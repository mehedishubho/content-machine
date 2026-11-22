<?php

namespace Tests\Unit\Support\Observability;

use App\Data\Observability\PublishEventData;
use App\Models\Post;
use App\Models\PublishEvent;
use App\Models\Workspace;
use App\Support\Observability\PublishEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Concerns\FakesPublishChannel;
use Tests\TestCase;

class PublishEventRecorderTest extends TestCase
{
    use FakesPublishChannel;
    use RefreshDatabase;

    private PublishEventRecorder $recorder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->recorder = new PublishEventRecorder;
        $this->fakePublishChannel();
    }

    private function createPost(Workspace $workspace): Post
    {
        return Post::factory()->for($workspace)->create([
            'status' => 'ready',
            'language' => 'bn',
            'platforms' => ['facebook'],
            'captions' => ['facebook' => 'Caption'],
        ]);
    }

    public function test_record_writes_publish_events_row_and_publish_channel_log_line(): void
    {
        $workspace = Workspace::factory()->create();
        $post = $this->createPost($workspace);

        $this->recorder->record(PublishEventData::claim(
            workspaceId: $workspace->id,
            publishableType: Post::class,
            publishableId: $post->getKey(),
            jobId: 'job-uuid-1',
            operationId: 'operation-1',
            runToken: 'run-1',
        ));

        $row = PublishEvent::query()->sole();
        $this->assertSame($workspace->id, $row->workspace_id);
        $this->assertSame(Post::class, $row->publishable_type);
        $this->assertSame($post->getKey(), $row->publishable_id);
        $this->assertSame('claim', $row->stage);
        $this->assertSame('job-uuid-1', $row->job_id);
        $this->assertSame('operation-1', $row->operation_id);
        $this->assertSame('run-1', $row->run_token);
        $this->assertNull($row->group_key);
        $this->assertNull($row->provider);
        $this->assertNull($row->duration_ms);
        $this->assertSame([], $row->payload);
        $this->assertNotNull($row->created_at);
        // Append-only: the model declares no updated_at column at all.
        $this->assertNull($row->getUpdatedAtColumn());

        $records = $this->publishEventRecords();
        $this->assertSame(1, count($records));
        $this->assertSame('claim', $records[0]->context['stage']);
        $this->assertSame('job-uuid-1', $records[0]->context['job_id']);
        $this->assertSame('run-1', $records[0]->context['run_token']);
        $this->assertSame($workspace->id, $records[0]->context['workspace_id']);
        $this->assertSame($post->getKey(), $records[0]->context['publishable_id']);
        // D-02: the log context stays JSON-encodable for the JsonFormatter's
        // one-object-per-line output.
        $this->assertNotFalse(json_encode($records[0]->context));
    }

    public function test_record_with_the_same_payload_twice_appends_two_rows(): void
    {
        $workspace = Workspace::factory()->create();
        $post = $this->createPost($workspace);
        $event = PublishEventData::checkpoint(
            workspaceId: $workspace->id,
            publishableType: Post::class,
            publishableId: $post->getKey(),
            groupKey: 'group-1',
        );

        $this->recorder->record($event);
        $this->recorder->record($event);

        $this->assertSame(2, PublishEvent::query()->count());
    }

    public function test_record_with_null_correlation_ids_still_writes_row_and_log_line(): void
    {
        $workspace = Workspace::factory()->create();
        $post = $this->createPost($workspace);

        $this->recorder->record(PublishEventData::claim(
            workspaceId: $workspace->id,
            publishableType: Post::class,
            publishableId: $post->getKey(),
        ));

        $row = PublishEvent::query()->sole();
        $this->assertNull($row->job_id);
        $this->assertNull($row->operation_id);
        $this->assertNull($row->run_token);
        $this->assertSame($workspace->id, $row->workspace_id);

        $records = $this->publishEventRecords();
        $this->assertSame(1, count($records));
    }

    public function test_d07_database_failure_is_swallowed_and_the_log_line_still_fires(): void
    {
        $workspace = Workspace::factory()->create();
        $post = $this->createPost($workspace);

        // Force the best-effort DB write to fail: the publish_events table is
        // gone, so PublishEvent::create() throws and record() must swallow it
        // without surfacing anything to the publish path.
        Schema::drop('publish_events');

        $this->recorder->record(PublishEventData::claim(
            workspaceId: $workspace->id,
            publishableType: Post::class,
            publishableId: $post->getKey(),
            jobId: 'job-d07-swallow',
            operationId: 'operation-d07',
            runToken: 'run-d07',
        ));

        $records = $this->publishEventRecords();
        $this->assertSame(1, count($records));
        $this->assertSame('job-d07-swallow', $records[0]->context['job_id']);

        // Reaching this point proves record() returned void normally: no
        // exception surfaced to the caller.
        $this->assertTrue(true);
    }

    public function test_d07_log_line_survives_a_rolled_back_publish_transaction_and_out_of_transaction_rows_persist(): void
    {
        $workspace = Workspace::factory()->create();
        $post = $this->createPost($workspace);

        // Log-first ordering proof: record() called INSIDE a transaction that
        // then rolls back. The JSON log line was already emitted before the DB
        // write, so it survives; the row joined the transaction and is lost —
        // exactly why callers must record outside publish transactions (D-07).
        try {
            DB::transaction(function () use ($post, $workspace): void {
                $this->recorder->record(PublishEventData::claim(
                    workspaceId: $workspace->id,
                    publishableType: Post::class,
                    publishableId: $post->getKey(),
                    jobId: 'job-d07-rolled-back',
                ));

                throw new RuntimeException('forcing publish rollback');
            });
            $this->fail('The forced rollback did not fire.');
        } catch (RuntimeException) {
        }

        $records = $this->publishEventRecords();
        $this->assertSame(1, count($records));
        $this->assertSame('job-d07-rolled-back', $records[0]->context['job_id']);
        $this->assertSame(0, PublishEvent::query()->count());

        // Complement: a row appended OUTSIDE any transaction survives a
        // subsequent unrelated rollback.
        $this->recorder->record(PublishEventData::claim(
            workspaceId: $workspace->id,
            publishableType: Post::class,
            publishableId: $post->getKey(),
            jobId: 'job-d07-survivor',
        ));

        try {
            DB::transaction(function (): void {
                throw new RuntimeException('forcing unrelated rollback');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(1, PublishEvent::query()->count());
        $survivor = PublishEvent::query()->sole();
        $this->assertSame('job-d07-survivor', $survivor->job_id);
    }

    public function test_d07_event_rows_are_visible_during_an_unrelated_in_progress_transaction(): void
    {
        $workspace = Workspace::factory()->create();
        $post = $this->createPost($workspace);

        $this->recorder->record(PublishEventData::claim(
            workspaceId: $workspace->id,
            publishableType: Post::class,
            publishableId: $post->getKey(),
            jobId: 'job-d07-visible',
            operationId: 'operation-d07',
            runToken: 'run-d07',
        ));

        // A publish checkpoint transaction in progress reads the event row
        // appended before it: event rows are never written as part of publish
        // transactions (D-07), so reads inside one are not blocked or empty.
        $seenInside = DB::transaction(function () use ($workspace): int {
            return (int) PublishEvent::query()
                ->where('workspace_id', $workspace->id)
                ->where('job_id', 'job-d07-visible')
                ->count();
        });

        $this->assertSame(1, $seenInside);
        $this->assertSame(1, PublishEvent::query()->count());
    }
}

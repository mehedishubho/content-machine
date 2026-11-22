<?php

namespace Tests\Unit\Support\Observability;

use App\Data\Observability\PublishEventData;
use App\Models\Post;
use App\Models\PublishEvent;
use App\Models\Workspace;
use App\Support\Observability\PublishEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}

<?php

namespace Tests\Unit\Support\Observability;

use App\Data\Observability\PublishEventData;
use App\Models\Post;
use App\Models\PublishEvent;
use App\Models\Workspace;
use App\Support\Observability\PublishEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class PublishEventRecorderTest extends TestCase
{
    use RefreshDatabase;

    private PublishEventRecorder $recorder;

    private MockInterface $publishChannel;

    private MockInterface $logManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->recorder = new PublishEventRecorder;
        $this->fakePublishChannel();
    }

    /**
     * Laravel ships no Log::fake() in this framework version, so the publish
     * channel is intercepted with Mockery spies: Log::spy() takes over the
     * `log` binding and channel('publish') is stubbed to a LoggerInterface
     * spy that records every write for assertion. (Rule 3 deviation: the
     * plan's Log::fake()/assertSentOnChannel API does not exist in
     * laravel/framework 13.)
     */
    private function fakePublishChannel(): void
    {
        $this->publishChannel = Mockery::spy(LoggerInterface::class);
        $this->logManager = Log::spy();
        $this->logManager->shouldReceive('channel')
            ->with('publish')
            ->andReturn($this->publishChannel);
        $this->instance('log', $this->logManager);
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
            publishableType: $post::class,
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
        $this->assertNull($row->updated_at);

        $this->logManager->shouldHaveReceived('channel')->with('publish')->once();
        $this->publishChannel->shouldHaveReceived('info')->once()->withArgs(
            function (string $message, array $context) use ($workspace, $post): bool {
                $this->assertSame('publish_event', $message);
                $this->assertSame('claim', $context['stage']);
                $this->assertSame('job-uuid-1', $context['job_id']);
                $this->assertSame('run-1', $context['run_token']);
                $this->assertSame($workspace->id, $context['workspace_id']);
                $this->assertSame($post->getKey(), $context['publishable_id']);
                // D-02: the context must stay JSON-encodable for the
                // JsonFormatter's one-object-per-line output.
                $this->assertNotFalse(json_encode($context));

                return true;
            }
        );
    }

    public function test_record_with_the_same_payload_twice_appends_two_rows(): void
    {
        $workspace = Workspace::factory()->create();
        $post = $this->createPost($workspace);

        $event = PublishEventData::checkpoint(
            workspaceId: $workspace->id,
            publishableType: $post::class,
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
            publishableType: $post::class,
            publishableId: $post->getKey(),
        ));

        $row = PublishEvent::query()->sole();
        $this->assertNull($row->job_id);
        $this->assertNull($row->operation_id);
        $this->assertNull($row->run_token);
        $this->assertSame($workspace->id, $row->workspace_id);

        $this->publishChannel->shouldHaveReceived('info')->once();
    }
}

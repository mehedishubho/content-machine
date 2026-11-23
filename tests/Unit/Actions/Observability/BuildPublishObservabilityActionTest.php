<?php

namespace Tests\Unit\Actions\Observability;

use App\Actions\Observability\BuildPublishObservabilityAction;
use App\Models\Post;
use App\Models\PublishEvent;
use App\Models\Video;
use App\Models\Workspace;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuildPublishObservabilityActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_counts_distinct_finalized_items_with_a_75_percent_rate(): void
    {
        $workspace = Workspace::factory()->create();

        for ($i = 0; $i < 3; $i++) {
            $post = Post::factory()->for($workspace)->create();
            $this->finalizeEvent($workspace, $post, 'succeeded');
        }
        $failedPost = Post::factory()->for($workspace)->create();
        $this->finalizeEvent($workspace, $failedPost, 'failed');

        $metrics = (new BuildPublishObservabilityAction)->handle($workspace, '7');

        $this->assertSame(4, $metrics['attempts']);
        $this->assertSame(3, $metrics['succeeded']);
        $this->assertSame(1, $metrics['failed']);
        $this->assertSame(75, $metrics['rate']);
    }

    public function test_an_item_that_finalized_twice_counts_once_by_its_newest_event(): void
    {
        $workspace = Workspace::factory()->create();
        $post = Post::factory()->for($workspace)->create();

        // failed first, then succeeded: the newest event decides the bucket
        // (D-16 idempotency edge — an item is never double counted).
        $this->finalizeEvent($workspace, $post, 'failed', now()->subMinutes(5));
        $this->finalizeEvent($workspace, $post, 'succeeded');

        $metrics = (new BuildPublishObservabilityAction)->handle($workspace, '7');

        $this->assertSame(1, $metrics['attempts']);
        $this->assertSame(1, $metrics['succeeded']);
        $this->assertSame(0, $metrics['failed']);
        $this->assertSame(100, $metrics['rate']);
    }

    public function test_zero_events_return_zero_counts_and_a_null_rate(): void
    {
        $workspace = Workspace::factory()->create();

        $metrics = (new BuildPublishObservabilityAction)->handle($workspace, '7');

        $this->assertSame(0, $metrics['attempts']);
        $this->assertSame(0, $metrics['succeeded']);
        $this->assertSame(0, $metrics['failed']);
        $this->assertSame(0, $metrics['retries']);
        $this->assertSame(0, $metrics['lease_expiries']);
        $this->assertNull($metrics['rate']);
        $this->assertSame([], $metrics['recent_outcomes']);
        $this->assertSame('Last 7 days', $metrics['period_label']);
    }

    public function test_retries_and_lease_expiries_count_only_rows_inside_the_window(): void
    {
        $workspace = Workspace::factory()->create();
        $post = Post::factory()->for($workspace)->create();

        $this->retryEvent($workspace, $post, now()->subHour());
        $this->retryEvent($workspace, $post, now()->subDays(8));
        $this->leaseEvent($workspace, $post, now()->subHour());
        $this->leaseEvent($workspace, $post, now()->subDays(8));

        $metrics = (new BuildPublishObservabilityAction)->handle($workspace, '7');

        $this->assertSame(1, $metrics['retries']);
        $this->assertSame(1, $metrics['lease_expiries']);
    }

    public function test_identical_created_at_events_keep_stable_id_desc_order(): void
    {
        $workspace = Workspace::factory()->create();
        $post = Post::factory()->for($workspace)->create();
        $same = now();

        // Insertion order defines ids: the second event (failed) must render
        // first because created_at ties break to id DESC.
        $this->finalizeEvent($workspace, $post, 'succeeded', $same);
        $this->finalizeEvent($workspace, $post, 'failed', $same);

        $metrics = (new BuildPublishObservabilityAction)->handle($workspace, '7');

        $outcomes = $metrics['recent_outcomes'];
        $this->assertCount(2, $outcomes);
        $this->assertSame('failed', $outcomes[0]['outcome']);
        $this->assertSame('succeeded', $outcomes[1]['outcome']);
    }

    public function test_the_outcomes_list_is_capped_at_twenty(): void
    {
        $workspace = Workspace::factory()->create();

        for ($i = 0; $i < 25; $i++) {
            $post = Post::factory()->for($workspace)->create();
            $this->finalizeEvent($workspace, $post, 'succeeded', now()->subMinutes(25 - $i));
        }

        $metrics = (new BuildPublishObservabilityAction)->handle($workspace, '7');

        $this->assertCount(20, $metrics['recent_outcomes']);
        // The cap keeps the newest events: the first entry is the newest.
        $this->assertSame('succeeded', $metrics['recent_outcomes'][0]['outcome']);
    }

    public function test_another_workspaces_events_never_leak_into_the_numbers(): void
    {
        $workspaceA = Workspace::factory()->create();
        $workspaceB = Workspace::factory()->create();

        $postA = Post::factory()->for($workspaceA)->create();
        $this->finalizeEvent($workspaceA, $postA, 'failed');
        $postB = Post::factory()->for($workspaceB)->create();
        $this->finalizeEvent($workspaceB, $postB, 'succeeded');
        $this->retryEvent($workspaceB, $postB, now());

        $metrics = (new BuildPublishObservabilityAction)->handle($workspaceA, '7');

        $this->assertSame(1, $metrics['attempts']);
        $this->assertSame(0, $metrics['succeeded']);
        $this->assertSame(1, $metrics['failed']);
        $this->assertSame(0, $metrics['rate']);
        $this->assertSame(0, $metrics['retries']);
        $this->assertSame(0, $metrics['lease_expiries']);
        $this->assertCount(1, $metrics['recent_outcomes']);
    }

    /**
     * One finalize-stage event row at an exact time. created_at is not
     * fillable on the append-only model, so the fixture forceFills it
     * (PrunePublishEventsCommandTest precedent); workspace_id is explicit
     * because Workspace::current() is null outside HTTP context.
     */
    private function finalizeEvent(
        Workspace $workspace,
        Post|Video $publishable,
        string $outcome,
        ?CarbonInterface $at = null,
    ): PublishEvent {
        $event = new PublishEvent([
            'workspace_id' => $workspace->id,
            'publishable_type' => $publishable::class,
            'publishable_id' => (int) $publishable->getKey(),
            'stage' => 'finalize',
            'payload' => ['outcome' => $outcome],
        ]);
        $event->forceFill(['created_at' => $at ?? now()])->save();

        return $event;
    }

    private function retryEvent(
        Workspace $workspace,
        Post|Video $publishable,
        ?CarbonInterface $at = null,
    ): PublishEvent {
        $event = new PublishEvent([
            'workspace_id' => $workspace->id,
            'publishable_type' => $publishable::class,
            'publishable_id' => (int) $publishable->getKey(),
            'stage' => 'retry',
            'payload' => ['reason' => 'RuntimeException', 'attempt' => 2],
        ]);
        $event->forceFill(['created_at' => $at ?? now()])->save();

        return $event;
    }

    private function leaseEvent(
        Workspace $workspace,
        Post|Video $publishable,
        ?CarbonInterface $at = null,
    ): PublishEvent {
        $event = new PublishEvent([
            'workspace_id' => $workspace->id,
            'publishable_type' => $publishable::class,
            'publishable_id' => (int) $publishable->getKey(),
            'stage' => 'claim',
            'payload' => ['lease_expired' => true],
        ]);
        $event->forceFill(['created_at' => $at ?? now()])->save();

        return $event;
    }
}

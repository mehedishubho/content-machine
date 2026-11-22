<?php

namespace Tests\Feature\Console;

use App\Models\Post;
use App\Models\PublishEvent;
use App\Models\Video;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PublishWatchdogCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_post_stuck_past_the_lease_margin_is_detected_and_day_dedup_keyed(): void
    {
        $workspace = Workspace::factory()->create();
        $post = Post::factory()->for($workspace)->create([
            'publish_state' => 'running',
            'publish_claimed_at' => now()->subHours(2),
        ]);
        $dedupKey = sprintf('watchdog:stuck:post:%d:%s', $post->id, now()->format('Y-m-d'));

        $this->artisan('postsyncer:watchdog', ['--dry-run' => true])
            ->expectsOutputToContain("post #{$post->id}")
            ->assertExitCode(0);
        $this->assertFalse(Cache::has($dedupKey));

        $this->artisan('postsyncer:watchdog')->assertExitCode(0);
        $this->assertTrue(Cache::has($dedupKey));
        $this->assertSame('running', $post->fresh()->publish_state);
    }

    public function test_a_freshly_claimed_post_is_not_flagged(): void
    {
        $workspace = Workspace::factory()->create();
        $post = Post::factory()->for($workspace)->create([
            'publish_state' => 'running',
            'publish_claimed_at' => now(),
        ]);

        $this->artisan('postsyncer:watchdog', ['--dry-run' => true])
            ->doesntExpectOutputToContain("post #{$post->id}")
            ->assertExitCode(0);
        $this->assertSame('running', $post->fresh()->publish_state);
    }

    public function test_a_video_with_no_recent_events_is_flagged_via_the_updated_at_fallback(): void
    {
        $workspace = Workspace::factory()->create();
        $video = Video::factory()->for($workspace)->create([
            'publish_state' => 'running',
            'updated_at' => now()->subHours(2),
        ]);

        $this->artisan('postsyncer:watchdog', ['--dry-run' => true])
            ->expectsOutputToContain("video #{$video->id}")
            ->assertExitCode(0);
        $this->assertSame('running', $video->fresh()->publish_state);
    }

    public function test_event_recency_gates_video_detection(): void
    {
        $workspace = Workspace::factory()->create();
        $stale = Video::factory()->for($workspace)->create([
            'publish_state' => 'running',
            'updated_at' => now()->subHours(2),
        ]);
        $recent = Video::factory()->for($workspace)->create([
            'publish_state' => 'running',
            'updated_at' => now()->subHours(2),
        ]);
        $this->seedEvent($stale, 'checkpoint', [], now()->subHours(2)->subMinutes(10));
        $this->seedEvent($recent, 'checkpoint', [], now());

        $this->artisan('postsyncer:watchdog', ['--dry-run' => true])
            ->expectsOutputToContain("video #{$stale->id}")
            ->doesntExpectOutputToContain("video #{$recent->id}")
            ->assertExitCode(0);
        $this->assertSame('running', $stale->fresh()->publish_state);
    }

    /**
     * Seed one publish_events row directly. created_at is not fillable on
     * the append-only model, so backdated fixtures forceFill it;
     * workspace_id is set explicitly because Workspace::current() is null
     * in console context (Pitfall 1).
     *
     * @param  array<string, mixed>  $payload
     */
    private function seedEvent(
        Post|Video $content,
        string $stage,
        array $payload = [],
        ?CarbonImmutable $createdAt = null,
    ): PublishEvent {
        $event = new PublishEvent([
            'workspace_id' => $content->workspace_id,
            'publishable_type' => $content::class,
            'publishable_id' => $content->getKey(),
            'stage' => $stage,
            'payload' => $payload,
        ]);

        if ($createdAt !== null) {
            $event->forceFill(['created_at' => $createdAt]);
        }

        $event->save();

        return $event;
    }
}

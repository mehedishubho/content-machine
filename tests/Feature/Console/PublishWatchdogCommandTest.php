<?php

namespace Tests\Feature\Console;

use App\Jobs\SendTelegramOutboundMessageJob;
use App\Models\Post;
use App\Models\PublishEvent;
use App\Models\TelegramBotConfig;
use App\Models\TelegramBotLink;
use App\Models\TelegramOutboundMessage;
use App\Models\User;
use App\Models\Video;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Monolog\LogRecord;
use Tests\Concerns\FakesPublishChannel;
use Tests\TestCase;

class PublishWatchdogCommandTest extends TestCase
{
    use FakesPublishChannel;
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

    public function test_a_stuck_post_queues_exactly_one_outbox_row_per_day(): void
    {
        Queue::fake();
        $workspace = Workspace::factory()->create();
        $owner = $this->connectTelegramBot($workspace);
        $post = Post::factory()->for($workspace)->create([
            'publish_state' => 'running',
            'publish_claimed_at' => now()->subHours(2),
        ]);

        $this->artisan('postsyncer:watchdog')->assertExitCode(0);
        $this->artisan('postsyncer:watchdog')->assertExitCode(0);

        $this->assertSame(1, TelegramOutboundMessage::query()->count());
        $row = TelegramOutboundMessage::query()->firstOrFail();
        $this->assertStringContainsString("watchdog:stuck:post:{$post->id}", $row->logical_key);
        $this->assertStringContainsString("api-notification:user:{$owner->id}:", $row->logical_key);
        Queue::assertPushed(SendTelegramOutboundMessageJob::class, 1);
    }

    public function test_a_failed_post_with_a_failed_finalize_event_alerts_as_exhausted(): void
    {
        Queue::fake();
        $workspace = Workspace::factory()->create();
        $this->connectTelegramBot($workspace);
        $post = Post::factory()->for($workspace)->create(['publish_state' => 'failed']);
        $this->seedEvent($post, 'finalize', ['outcome' => 'failed'], now()->subHour());

        $this->artisan('postsyncer:watchdog')->assertExitCode(0);

        $this->assertSame(1, TelegramOutboundMessage::query()->count());
        $row = TelegramOutboundMessage::query()->firstOrFail();
        $this->assertStringContainsString('watchdog:exhausted:post:', $row->logical_key);
    }

    public function test_a_failed_post_whose_newest_finalize_succeeded_is_not_alerted(): void
    {
        Queue::fake();
        $workspace = Workspace::factory()->create();
        $this->connectTelegramBot($workspace);
        $post = Post::factory()->for($workspace)->create(['publish_state' => 'failed']);
        $this->seedEvent($post, 'finalize', ['outcome' => 'failed'], now()->subHours(3));
        $this->seedEvent($post, 'finalize', ['outcome' => 'succeeded'], now()->subHour());

        $this->artisan('postsyncer:watchdog')->assertExitCode(0);

        $this->assertSame(0, TelegramOutboundMessage::query()->count());
    }

    public function test_the_lease_loop_threshold_bounds_exactly(): void
    {
        Queue::fake();
        $workspace = Workspace::factory()->create();
        $this->connectTelegramBot($workspace);
        $below = Post::factory()->for($workspace)->create([
            'publish_state' => 'running',
            'publish_claimed_at' => now(),
        ]);
        $above = Post::factory()->for($workspace)->create([
            'publish_state' => 'running',
            'publish_claimed_at' => now(),
        ]);
        $this->seedEvent($below, 'claim', ['lease_expired' => true], now()->subHours(1));
        $this->seedEvent($above, 'claim', ['lease_expired' => true], now()->subHours(2));
        $this->seedEvent($above, 'claim', ['lease_expired' => true], now()->subHours(1));

        $this->artisan('postsyncer:watchdog')->assertExitCode(0);

        $this->assertSame(1, TelegramOutboundMessage::query()->count());
        $row = TelegramOutboundMessage::query()->firstOrFail();
        $this->assertStringContainsString("watchdog:lease_loop:post:{$above->id}", $row->logical_key);
    }

    public function test_a_missing_telegram_bot_falls_back_to_a_publish_channel_warning(): void
    {
        $this->fakePublishChannel();
        Queue::fake();
        $workspace = Workspace::factory()->create();
        // Production parity: the owner is always a team_user member
        // (CreateTeamAction), so the action's membership check passes and
        // the no-bot path is the one under test.
        $owner = $workspace->team->owner;
        DB::table('team_user')->insert([
            'team_id' => $workspace->team_id,
            'user_id' => $owner->id,
            'role' => 'owner',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $post = Post::factory()->for($workspace)->create([
            'publish_state' => 'running',
            'publish_claimed_at' => now()->subHours(2),
        ]);

        $this->artisan('postsyncer:watchdog')->assertExitCode(0);
        $this->assertTrue(Cache::has(sprintf('watchdog:stuck:post:%d:%s', $post->id, now()->format('Y-m-d'))));

        $warnings = array_values(array_filter(
            $this->publishChannelHandler->getRecords(),
            static fn (LogRecord $record): bool => $record->message === 'watchdog_alert',
        ));
        $this->assertCount(1, $warnings);
        $this->assertSame($workspace->id, $warnings[0]->context['workspace_id']);
        $this->assertSame('stuck', $warnings[0]->context['condition']);
        $this->assertSame($post->id, $warnings[0]->context['publishable_id']);
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

    /**
     * Provision the Telegram alert path exactly as production does: the
     * team owner (the alert recipient) attached to team_user per
     * CreateTeamAction, a connected bot, and an owner-to-bot link.
     */
    private function connectTelegramBot(Workspace $workspace): User
    {
        $owner = $workspace->team->owner;
        DB::table('team_user')->insert([
            'team_id' => $workspace->team_id,
            'user_id' => $owner->id,
            'role' => 'owner',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $config = TelegramBotConfig::factory()->connected()->create(['workspace_id' => $workspace->id]);
        TelegramBotLink::factory()->create([
            'telegram_bot_config_id' => $config->id,
            'user_id' => $owner->id,
            'telegram_user_id' => 111222333,
        ]);

        return $owner;
    }
}

<?php

namespace App\Console\Commands;

use App\Actions\Observability\SendPublishWatchdogAlertAction;
use App\Models\Post;
use App\Models\PublishEvent;
use App\Models\Video;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Observe-only publish watchdog (D-13/D-14/D-15): detects stuck publishes
 * and alerts the operator through the Telegram outbox. It never mutates
 * publish state, requeues jobs, or performs recovery writes — recovery
 * belongs to the existing reconcile/recover commands.
 *
 * Three conditions alert (D-14):
 *  - stuck: queued/running past stuck_margin_multiplier x the type's lease
 *    window. Posts derive staleness from publish_claimed_at (a read-only
 *    variant of DispatchPendingPostPublishesCommand's verified stale-lease
 *    query); videos have no publish_claimed_at column (posts-only migration
 *    2026_09_01_140003), so staleness derives from publish_events recency —
 *    no event within the margin means no observable progress (Pitfall 7) —
 *    falling back to videos.updated_at when the video has no events at all.
 *  - exhausted: publish_state failed with a newest finalize event whose
 *    payload.outcome is failed — the permanent-failure marker (the state
 *    column alone can predate a later successful run; the newest finalize
 *    event is the run-level truth).
 *  - lease_loop: a post collecting at least lease_expiry_alert_threshold
 *    claim events with payload.lease_expired=true within the lookback
 *    window. Post-scoped by design: the lease-expiry event vocabulary
 *    exists only on the post claim path (plan 01-01) — videos carry no
 *    publish_claimed_at/publish_lease_id columns (posts-only migrations
 *    2026_09_01_140003/140004) and PublishVideoAction contains no lease
 *    machinery, so this query can only ever match posts.
 *
 * Dedup (D-15): at most one alert per content item per condition per day.
 * Cache::add on a date-suffixed key is the atomic guard; the Telegram
 * outbox's logical_key encodes the same content+date as the second layer.
 */
class PublishWatchdogCommand extends Command
{
    protected $signature = 'postsyncer:watchdog {--dry-run : Detect and report without alerting}';

    protected $description = 'Alert when publishes stick past their lease window, exhaust their retries, or loop on lease expiries';

    public function handle(SendPublishWatchdogAlertAction $alertAction): int
    {
        $stuck = 0;
        $leaseLoops = 0;
        $exhausted = 0;

        try {
            $stuck = $this->detectStuckPublishes($alertAction);
        } catch (Throwable $exception) {
            report($exception);
        }

        try {
            $exhausted = $this->detectExhaustedPublishes($alertAction);
        } catch (Throwable $exception) {
            report($exception);
        }

        try {
            $leaseLoops = $this->detectLeaseLoopPosts($alertAction);
        } catch (Throwable $exception) {
            report($exception);
        }

        $this->info("Watchdog: {$stuck} stuck publish(es), {$leaseLoops} retry-loop(s), {$exhausted} exhausted publish(es).");

        return self::SUCCESS;
    }

    /**
     * Read-only variant of DispatchPendingPostPublishesCommand's verified
     * stale-lease query: queued/running past the post lease margin, treating
     * a missing claimed_at as stale. No locking, no recovery writes.
     *
     * @return Collection<int, Post>
     */
    private function stalePosts(): Collection
    {
        $cutoff = $this->stuckCutoff('post');

        return Post::query()
            ->whereIn('publish_state', ['queued', 'running'])
            ->where(function ($query) use ($cutoff): void {
                $query->whereNull('publish_claimed_at')
                    ->orWhere('publish_claimed_at', '<=', $cutoff);
            })
            ->orderBy('id')
            ->get();
    }

    /**
     * Video staleness derives from publish_events recency — videos carry no
     * publish_claimed_at column — so "no event within the margin" means no
     * observable progress. A video with zero events falls back to its row's
     * updated_at so a freshly queued, never-started video is not flagged
     * before its worker even gets a chance.
     *
     * @return Collection<int, Video>
     */
    private function staleVideos(): Collection
    {
        $cutoff = $this->stuckCutoff('video');

        return Video::query()
            ->whereIn('publish_state', ['queued', 'running'])
            ->where(function ($query) use ($cutoff): void {
                // No event newer than the margin: any event is older than
                // the cutoff, or the video has no events at all.
                $query->whereNotExists(function ($event) use ($cutoff): void {
                    $event->selectRaw(1)
                        ->from('publish_events')
                        ->whereColumn('publish_events.publishable_id', 'videos.id')
                        ->where('publish_events.publishable_type', Video::class)
                        ->where('publish_events.created_at', '>=', $cutoff);
                })->where(function ($fallback) use ($cutoff): void {
                    // Zero-event videos only count when the row itself has
                    // aged past the margin; videos with (stale) events are
                    // already covered by the whereNotExists branch.
                    $fallback->whereExists(function ($existing): void {
                        $existing->selectRaw(1)
                            ->from('publish_events')
                            ->whereColumn('publish_events.publishable_id', 'videos.id')
                            ->where('publish_events.publishable_type', Video::class);
                    })->orWhere('videos.updated_at', '<=', $cutoff);
                });
            })
            ->orderBy('id')
            ->get();
    }

    private function detectStuckPublishes(SendPublishWatchdogAlertAction $alertAction): int
    {
        $detected = 0;

        foreach ($this->stalePosts() as $post) {
            $detected++;
            $this->reportItem('stuck', $post);

            if ($this->alertingEnabled()) {
                try {
                    $this->alertFor($alertAction, 'stuck', $post);
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        }

        foreach ($this->staleVideos() as $video) {
            $detected++;
            $this->reportItem('stuck', $video);

            if ($this->alertingEnabled()) {
                try {
                    $this->alertFor($alertAction, 'stuck', $video);
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        }

        return $detected;
    }

    /**
     * Condition 2 (D-14): failed content whose newest finalize event
     * recorded outcome=failed — the permanent-failure marker. Per-content
     * newest-event resolution keeps the semantics exact (OBS-05); the
     * failed set is small and the day-key dedup bounds repeated scans.
     *
     * @return Collection<int, Post|Video>
     */
    private function exhaustedPublishes(): Collection
    {
        return Post::query()
            ->where('publish_state', 'failed')
            ->orderBy('id')
            ->get()
            ->concat(Video::query()->where('publish_state', 'failed')->orderBy('id')->get())
            ->filter(fn (Post|Video $content): bool => $this->newestFinalizeOutcome($content) === 'failed')
            ->values();
    }

    /**
     * The run-level truth for condition 2: the newest finalize event's
     * outcome (null when the content has no finalize event — e.g. a failed
     * dispatch that never ran).
     */
    private function newestFinalizeOutcome(Post|Video $content): ?string
    {
        $event = PublishEvent::query()
            ->where('publishable_type', $content::class)
            ->where('publishable_id', $content->getKey())
            ->where('stage', 'finalize')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        $outcome = $event?->payload['outcome'] ?? null;

        return is_string($outcome) ? $outcome : null;
    }

    private function detectExhaustedPublishes(SendPublishWatchdogAlertAction $alertAction): int
    {
        $detected = 0;

        foreach ($this->exhaustedPublishes() as $content) {
            $detected++;
            $this->reportItem('exhausted', $content);

            if ($this->alertingEnabled()) {
                try {
                    $this->alertFor($alertAction, 'exhausted', $content);
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        }

        return $detected;
    }

    /**
     * Condition 3 (D-14/OBS-05): posts with at least
     * lease_expiry_alert_threshold claim events carrying
     * payload.lease_expired=true within lease_expiry_lookback_hours.
     *
     * @return Collection<int, Post>
     */
    private function leaseLoopPosts(): Collection
    {
        $threshold = (int) config('observability.watchdog.lease_expiry_alert_threshold');
        $since = now()->subHours((int) config('observability.watchdog.lease_expiry_lookback_hours'));

        $postIds = PublishEvent::query()
            ->where('publishable_type', Post::class)
            ->where('stage', 'claim')
            ->whereNotNull('publishable_id')
            ->where('payload->lease_expired', 'true')
            ->where('created_at', '>=', $since)
            ->groupBy('publishable_id')
            ->havingRaw('count(*) >= ?', [$threshold])
            ->pluck('publishable_id');

        return Post::query()->whereIn('id', $postIds)->orderBy('id')->get();
    }

    private function detectLeaseLoopPosts(SendPublishWatchdogAlertAction $alertAction): int
    {
        $detected = 0;

        foreach ($this->leaseLoopPosts() as $post) {
            $detected++;
            $this->reportItem('lease_loop', $post);

            if ($this->alertingEnabled()) {
                try {
                    $this->alertFor($alertAction, 'lease_loop', $post);
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        }

        return $detected;
    }

    /**
     * D-15 dedup: Cache::add on the date-suffixed key is the atomic guard —
     * only the caller that wins the add proceeds to alert, so concurrent or
     * repeated watchdog executions queue at most one alert per content item
     * per day while a condition holds. The outbox logical_key encodes the
     * same content+date as the second layer.
     */
    private function alertFor(
        SendPublishWatchdogAlertAction $alertAction,
        string $condition,
        Post|Video $publishable,
    ): void {
        $key = sprintf(
            'watchdog:%s:%s:%s:%s',
            $condition,
            $publishable instanceof Post ? 'post' : 'video',
            $publishable->getKey(),
            now()->format('Y-m-d'),
        );
        $ttl = max(1, (int) now()->diffInSeconds(now()->endOfDay()));

        if (! Cache::add($key, true, $ttl)) {
            return;
        }

        $workspace = $publishable->workspace;
        $owner = $workspace?->team?->owner;

        if ($workspace === null || $owner === null) {
            return;
        }

        $alertAction->handle($workspace, $owner, $condition, $publishable);
    }

    private function reportItem(string $condition, Post|Video $publishable): void
    {
        $this->info(sprintf(
            '%s publish: %s #%d (%s) in state %s.',
            ucfirst($condition),
            $publishable instanceof Post ? 'post' : 'video',
            $publishable->getKey(),
            $publishable->human_id,
            $publishable->publish_state,
        ));
    }

    private function alertingEnabled(): bool
    {
        return ! (bool) $this->option('dry-run');
    }

    /**
     * The margin-aware staleness cutoff for one publishable type. Both
     * thresholds come from config/observability.php — the single threshold
     * home — so tuning never touches this command.
     */
    private function stuckCutoff(string $type): CarbonInterface
    {
        $window = (int) config("observability.lease_windows.{$type}");
        $margin = (float) config('observability.watchdog.stuck_margin_multiplier');

        return now()->subSeconds((int) round($window * $margin));
    }
}

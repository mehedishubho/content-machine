<?php

namespace App\Actions\Observability;

use App\Models\Post;
use App\Models\PublishEvent;
use App\Models\Video;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Live publish success-rate reporting for the observability dashboard
 * (D-16/D-18/D-19): every figure is computed per period straight from
 * publish_events — no summary table. The success denominator is the content
 * item (D-16): the newest finalize event in the window decides which bucket
 * a post/video lands in, so a retried-then-succeeded item counts once and
 * lands in exactly one of the two outcome buckets.
 */
final class BuildPublishObservabilityAction
{
    /** The outcomes list never renders more than this rows. */
    private const OUTCOMES_CAP = 20;

    /**
     * Section header label per period (D-18). Numeric-string keys become
     * int offsets in PHP; lookups with the string period cast identically.
     *
     * @var array<string|int, string>
     */
    private const PERIOD_LABELS = [
        'today' => 'Today',
        '7' => 'Last 7 days',
        '30' => 'Last 30 days',
        '90' => 'Last 90 days',
    ];

    /**
     * The observability payload, computed live per period.
     *
     * @return array{
     *     period: string,
     *     period_label: string,
     *     timezone: string,
     *     attempts: int,
     *     succeeded: int,
     *     failed: int,
     *     retries: int,
     *     lease_expiries: int,
     *     rate: int|null,
     *     recent_outcomes: list<array<string, string|int|null>>,
     * }
     */
    public function handle(Workspace $workspace, string $period): array
    {
        $timezone = $this->timezone($workspace);
        $from = $this->periodStart($period, $timezone);

        $latestOutcomes = $this->newestFinalizeOutcomes($workspace, $from);

        $attempts = $latestOutcomes->count();
        $succeeded = (int) $latestOutcomes
            ->filter(fn (mixed $outcome): bool => $outcome === 'succeeded')
            ->count();
        $failed = $attempts - $succeeded;

        [$retries, $leaseExpiries] = $this->signalCounts($workspace, $from);

        return [
            'period' => $period,
            'period_label' => self::PERIOD_LABELS[$period] ?? $period,
            'timezone' => $timezone,
            'attempts' => $attempts,
            'succeeded' => $succeeded,
            'failed' => $failed,
            'retries' => $retries,
            'lease_expiries' => $leaseExpiries,
            'rate' => $attempts > 0
                ? (int) round($succeeded * 100 / $attempts)
                : null,
            'recent_outcomes' => $this->recentOutcomes($workspace, $from),
        ];
    }

    /**
     * The outcome of the NEWEST finalize event per content item in the
     * window (D-16): a post/video that finalized twice counts once, bucketed
     * by its newest event.
     *
     * @return Collection<string, mixed>
     */
    private function newestFinalizeOutcomes(
        Workspace $workspace,
        CarbonImmutable $from,
    ): Collection {
        return PublishEvent::query()
            ->where('workspace_id', $workspace->id)
            ->where('stage', 'finalize')
            ->where('created_at', '>=', $from)
            // Client-level events observe no content item; the denominator
            // is items only.
            ->whereNotNull('publishable_type')
            ->selectRaw("distinct on (publishable_type, publishable_id) publishable_type, publishable_id, payload->>'outcome' as outcome")
            ->orderBy('publishable_type')
            ->orderBy('publishable_id')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->pluck('outcome');
    }

    /**
     * Retry and lease-expiry counts ride alongside (OBS-05): exact integer
     * counts of their stage rows inside the window — no sampling.
     *
     * @return array{0: int, 1: int}
     */
    private function signalCounts(Workspace $workspace, CarbonImmutable $from): array
    {
        $row = PublishEvent::query()
            ->where('workspace_id', $workspace->id)
            ->where('created_at', '>=', $from)
            ->selectRaw("count(*) filter (where stage = 'retry') as retries, count(*) filter (where stage = 'claim' and payload->>'lease_expired' = 'true') as lease_expiries")
            ->first();

        return [
            (int) ($row->retries ?? 0),
            (int) ($row->lease_expiries ?? 0),
        ];
    }

    /**
     * The latest finalize-stage events for the outcomes list: newest first,
     * stable id tiebreak, capped, morph-loaded for title/human id/type.
     *
     * @return list<array<string, string|int|null>>
     */
    private function recentOutcomes(Workspace $workspace, CarbonImmutable $from): array
    {
        $events = PublishEvent::query()
            ->where('workspace_id', $workspace->id)
            ->where('stage', 'finalize')
            ->where('created_at', '>=', $from)
            ->with('publishable')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::OUTCOMES_CAP)
            ->get();

        $outcomes = [];

        foreach ($events as $event) {
            // Finalize events only ever reference content items; narrowing
            // to the Post|Video union gives typed access to their fields.
            $publishable = $event->publishable;
            $isContent = $publishable instanceof Post || $publishable instanceof Video;

            $outcomes[] = [
                // Provider is null on finalize events (only client-level
                // rows carry it), so the UI renders the type-only meta
                // sub-line per the spec's partial edge.
                'id' => $event->id,
                'outcome' => is_array($event->payload)
                    && is_string($event->payload['outcome'] ?? null)
                    ? $event->payload['outcome']
                    : null,
                'type' => $this->typeOf($event),
                'human_id' => $isContent ? $publishable->human_id : null,
                'title' => $isContent ? $publishable->title : null,
                'provider' => $event->provider,
                'created_at' => $event->created_at?->toISOString(),
            ];
        }

        return $outcomes;
    }

    /**
     * 'post' | 'video' — derived from the morph type.
     */
    private function typeOf(PublishEvent $event): string
    {
        return match ($event->publishable_type) {
            Post::class => 'post',
            Video::class => 'video',
            default => class_basename($event->publishable_type),
        };
    }

    /**
     * The reporting window start: 'today' starts at workspace-timezone
     * midnight; 7/30/90 subtract N days from now (D-18), so the 90-day
     * window can never exceed the retained data (D-03).
     */
    private function periodStart(string $period, string $timezone): CarbonImmutable
    {
        if ($period === 'today') {
            return CarbonImmutable::now($timezone)->startOfDay();
        }

        return CarbonImmutable::now()->subDays((int) $period);
    }

    /**
     * The workspace timezone with the project's fallback (the
     * BuildDashboardSummaryAction precedent, kept local because that Action
     * is read-only context this phase must not modify).
     */
    private function timezone(Workspace $workspace): string
    {
        $timezone = trim((string) $workspace->timezone);

        return $timezone !== '' ? $timezone : 'Asia/Dhaka';
    }
}

<?php

namespace App\Actions\Observability;

use App\Actions\Notifications\QueueTelegramNotificationAction;
use App\Actions\Notifications\TelegramNotificationsUnavailable;
use App\Data\Notifications\CreateTelegramNotificationData;
use App\Models\Post;
use App\Models\PublishEvent;
use App\Models\User;
use App\Models\Video;
use App\Models\Workspace;
use Illuminate\Support\Facades\Log;

/**
 * Delivers one watchdog alert through the existing Telegram outbox (D-12).
 * A thin wrapper around QueueTelegramNotificationAction: the notification
 * key encodes content+date so the outbox's own logical_key dedup is the
 * second D-15 layer behind the command's atomic Cache::add guard.
 *
 * When no Telegram bot is connected the TelegramNotificationsUnavailable
 * throw falls back to a publish-channel warning — the one sanctioned direct
 * channel use outside the recorder (operational alert, not a publish event)
 * — so the scheduled watchdog never crashes over a missing bot (D-12
 * fallback; RESEARCH Open Question 3).
 */
class SendPublishWatchdogAlertAction
{
    public function __construct(
        private readonly QueueTelegramNotificationAction $queueNotification,
    ) {}

    /**
     * @return bool whether an alert row was queued
     */
    public function handle(
        Workspace $workspace,
        User $owner,
        string $condition,
        Post|Video $publishable,
    ): bool {
        $data = new CreateTelegramNotificationData(
            key: sprintf(
                'watchdog:%s:%s:%d:%s',
                $condition,
                $publishable instanceof Post ? 'post' : 'video',
                $publishable->getKey(),
                now()->format('Y-m-d'),
            ),
            text: $this->message($condition, $publishable),
        );

        try {
            $result = $this->queueNotification->handle($workspace, $owner, $data);

            return $result['created'];
        } catch (TelegramNotificationsUnavailable) {
            Log::channel('publish')->warning('watchdog_alert', [
                'workspace_id' => $workspace->id,
                'condition' => $condition,
                'publishable_type' => $publishable instanceof Post ? 'post' : 'video',
                'publishable_id' => (int) $publishable->getKey(),
                'publish_state' => $publishable->publish_state,
                'reason' => 'telegram_notifications_unavailable',
            ]);

            return false;
        }
    }

    /**
     * Plain-English operator alert. Ids, type, title, state, and hours only
     * — never provider keys, tokens, or run tokens (ASVS V7).
     */
    private function message(string $condition, Post|Video $publishable): string
    {
        $reason = match ($condition) {
            'stuck' => 'is stuck past its lease window',
            'exhausted' => 'has exhausted its publish retries (permanent failure)',
            'lease_loop' => 'keeps hitting lease expiries (retry loop)',
            default => 'needs attention',
        };

        return sprintf(
            'Watchdog: %s #%d "%s" %s — publish_state "%s", ~%.1f h in this state. No recovery was performed.',
            $publishable instanceof Post ? 'post' : 'video',
            $publishable->getKey(),
            $publishable->title,
            $reason,
            $publishable->publish_state,
            $this->hoursInState($publishable),
        );
    }

    /**
     * When the current publish state plausibly started: posts count from
     * publish_claimed_at (or updated_at when never claimed); videos carry no
     * claimed_at column, so they count from the newest publish event,
     * falling back to updated_at — the same shape as the detection query.
     */
    private function hoursInState(Post|Video $publishable): float
    {
        $since = null;

        if ($publishable instanceof Post) {
            $since = $publishable->publish_claimed_at;
        } else {
            $newest = PublishEvent::query()
                ->where('publishable_type', $publishable::class)
                ->where('publishable_id', $publishable->getKey())
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first();

            $since = $newest?->created_at;
        }

        $since ??= $publishable->updated_at;

        return round(now()->diffInHours($since), 1);
    }
}

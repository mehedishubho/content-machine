<?php

namespace App\Console\Commands;

use App\Models\PublishEvent;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Throwable;

/**
 * Enforces the 90-day publish_events retention window (D-03). The window is
 * read from config/observability.php — the single threshold home — because
 * it also caps the dashboard's maximum reporting period (plan 01-05 reads
 * the same config). Deletes run as bounded Eloquent batches (ctid-limited
 * delete on PostgreSQL) to control table bloat; no raw destructive schema
 * commands, which production prohibits via DB::prohibitDestructiveCommands.
 */
class PrunePublishEventsCommand extends Command
{
    protected $signature = 'publish-events:prune {--dry-run : Count what would be pruned without deleting}';

    protected $description = 'Prune publish events older than the retention window';

    /**
     * Rows deleted per bounded batch (PostgreSQL bloat control).
     */
    private const CHUNK = 500;

    public function handle(): int
    {
        $days = (int) config('observability.retention.days');
        $cutoff = now()->subDays($days);

        try {
            if ((bool) $this->option('dry-run')) {
                $wouldPrune = $this->countPrunable($cutoff);
                $this->info("Would prune {$wouldPrune} publish event(s) older than {$days} day(s).");

                return self::SUCCESS;
            }

            $pruned = $this->prune($cutoff);

            $this->info("Pruned {$pruned} publish event(s) older than {$days} day(s).");
        } catch (Throwable $exception) {
            report($exception);
            $this->error('The publish event prune failed; nothing further was deleted.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function countPrunable(CarbonInterface $cutoff): int
    {
        return PublishEvent::query()
            ->where('created_at', '<', $cutoff)
            ->count();
    }

    private function prune(CarbonInterface $cutoff): int
    {
        $pruned = 0;

        do {
            // Strictly older than the cutoff: a row created exactly at the
            // boundary (now - retention.days) stays. The limit keeps every
            // statement's row count bounded; the grammar compiles it to a
            // ctid-IN-subselect delete on PostgreSQL.
            $deleted = PublishEvent::query()
                ->where('created_at', '<', $cutoff)
                ->orderBy('id')
                ->limit(self::CHUNK)
                ->delete();
            $pruned += $deleted;
        } while ($deleted === self::CHUNK);

        return $pruned;
    }
}

<?php

namespace App\Support\Observability;

use App\Contracts\PublishErrorContext;
use App\Data\Observability\PublishEventData;
use App\Models\PublishEvent;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The single instrumentation seam for publish-event capture (D-06): one
 * record() call emits the JSON log line on the dedicated `publish` channel
 * AND writes the publish_events row, together. Publish Actions, jobs, and
 * provider clients call this — never Log:: directly, and never model
 * observers.
 *
 * D-07 ordering contract:
 *  - The log line ALWAYS fires first, before the DB write, so the forensic
 *    trail survives any database failure or publish-transaction rollback.
 *  - The DB row is written best-effort. Callers must invoke record() only
 *    AFTER their DB::transaction closures have returned (never inside one);
 *    the recorder additionally guarantees a database failure can never
 *    propagate to the caller — observability must never break, roll back,
 *    or fail a publish.
 */
final class PublishEventRecorder
{
    /**
     * The error-tracking context rides the same seam. The concrete default
     * mirrors the actions' `new PublishEventRecorder` constructor defaults
     * (plan 01-01): production call sites build the recorder directly with
     * `new`, so a null default would leave every publish event silently
     * without error-tracking context (D-10). Tests inject a spy through
     * this parameter; an explicit null keeps a recorder fully inert.
     */
    public function __construct(
        private readonly ?PublishErrorContext $errorContext = new SentryPublishErrorContext,
    ) {}

    public function record(PublishEventData $event): void
    {
        Log::channel('publish')->info('publish_event', $event->toArray());

        // D-10: the same identifiers reach the error tracker at this single
        // seam — after the log line, before the best-effort DB write. An
        // attach() failure must never reach the publish path: same swallow
        // discipline as the DB write below (D-07, instrumentation is
        // non-fatal).
        try {
            $this->errorContext?->attach($event);
        } catch (Throwable) {
            // Swallow: instrumentation failure must never fail a publish.
        }

        try {
            PublishEvent::create([
                ...$event->toArray(),
                // Explicit: Workspace::current() is null on queue workers, so
                // the BelongsToWorkspace creating hook cannot fill this in.
                'workspace_id' => $event->workspaceId,
            ]);
        } catch (Throwable) {
            // Swallow: a recorder failure must never fail a publish (D-07).
        }
    }
}

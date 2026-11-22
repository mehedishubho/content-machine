<?php

namespace App\Support\Observability;

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
    public function record(PublishEventData $event): void
    {
        Log::channel('publish')->info('publish_event', $event->toArray());

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

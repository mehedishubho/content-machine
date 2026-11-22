<?php

namespace App\Contracts;

use App\Data\Observability\PublishEventData;

/**
 * The single publish-context seam for error tracking (D-10): every publish
 * event crossing the recorder boundary attaches the same correlation
 * identifiers the publish log channel emits, so an error-tracker issue maps
 * 1:1 onto a grep-able log line.
 *
 * Implementations must never throw into the publish path — the recorder
 * swallows attach() failures with the same discipline as its DB write
 * (D-07: observability must never break a publish).
 */
interface PublishErrorContext
{
    public function attach(PublishEventData $event): void;
}

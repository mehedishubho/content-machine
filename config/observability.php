<?php

use App\Jobs\PublishPostJob;
use App\Jobs\PublishVideoJob;

/**
 * Publish observability thresholds (D-03, D-13, D-14, D-15). This file is
 * the single home for every watchdog and retention threshold: the dashboard's
 * reporting periods (Today/7/30/90) are bounded by retention.days, so a
 * change here redefines what historical reporting is possible (D-03).
 */
return [

    // Lease windows in seconds, per publishable type, imported from the jobs'
    // own constants so thresholds track the workers (Pitfall 5). The video
    // path has no lease column; its window is the job's overlap expiry —
    // the effective in-flight budget (RESEARCH.md assumption A4).
    'lease_windows' => [
        'post' => PublishPostJob::LEASE_SECONDS,
        'video' => PublishVideoJob::OVERLAP_EXPIRES_AFTER_SECONDS,
    ],

    'watchdog' => [
        // A publish counts as stuck past this multiple of its type's lease
        // window. The margin exists because multi-group publishes
        // legitimately run for many minutes (300s HTTP waits are by design —
        // Pitfall 7); alerting at 1.0x would fire on healthy publishes and
        // erode trust in the alert.
        'stuck_margin_multiplier' => 1.5,

        // Alert condition 3 (retry loop) fires when a post collects at
        // least this many lease-expiry claim events within the lookback
        // window. One step below the threshold stays silent (OBS-05).
        'lease_expiry_alert_threshold' => 2,

        'lease_expiry_lookback_hours' => 24,
    ],

    'retention' => [
        // D-03: publish_events rows older than this many days are pruned by
        // schedule; the same window caps the dashboard's maximum period.
        'days' => 90,
    ],

];

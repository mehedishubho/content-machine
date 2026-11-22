---
phase: 01-publish-observability-monitoring
plan: 03
subsystem: observability
tags: [watchdog, retention-prune, telegram-outbox, scheduler, dedup, publish-events]

# Dependency graph
requires:
  - phase: 01-01 (publish-event capture pipeline)
    provides: publish_events table + PublishEvent model, lease-expiry claim event vocabulary (payload.lease_expired), publish log channel, FakesPublishChannel test trait
  - phase: 01-02 (video + provider HTTP events)
    provides: finalize event vocabulary on the video/SocialReins paths, event-recency data the video staleness query reads
provides:
  - config/observability.php — the single threshold home: lease_windows (post/video from job constants), watchdog margin/threshold/lookback, retention.days 90 (plan 01-05 reads retention.days too)
  - postsyncer:watchdog --dry-run: three D-14 detection conditions (stuck posts by claimed_at, stuck videos by event recency with updated_at fallback, exhausted by newest-finalize outcome, lease_loop by lease-expiry claim count), Cache::add day-key dedup + Telegram outbox delivery
  - app/Actions/Observability/SendPublishWatchdogAlertAction: thin outbox wrapper with TelegramNotificationsUnavailable fallback to a publish-channel warning (D-12)
  - publish-events:prune --dry-run: strict created_at < retention cutoff deletes in bounded 500-row ctid batches (D-03)
  - Scheduler entries: watchdog everyMinute/withoutOverlapping(2), prune daily/withoutOverlapping(10)
affects: [01-04-sentry, 01-05-dashboard, operator alerting, retention-bounded reporting]

# Actuals (#2632)
actuals:
  tokens: 9500
  tasks: 3
  commits: 3
plan_head_before: 798d0e2ae68041fbf06ffd5b0c5a50c5d6d960d2
plan_head_after: 4783aa7436c23c56dbc016efcd8b648c2932fe79
commits: 3

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Day-key dedup: Cache::add('watchdog:{condition}:{type}:{id}:{Y-m-d}') as the atomic first layer, outbox logical_key encoding the same content+date as the second (D-15)"
    - "Frozen-clock boundary testing (travelTo) so a strict < retention cutoff is deterministic at the exact 90-day row"
    - "Bounded deletes on PostgreSQL: orderBy(id)->limit(500)->delete() compiles to a ctid-IN-subselect (bloat control without raw SQL)"

key-files:
  created:
    - config/observability.php
    - app/Console/Commands/PublishWatchdogCommand.php
    - app/Actions/Observability/SendPublishWatchdogAlertAction.php
    - app/Console/Commands/PrunePublishEventsCommand.php
    - tests/Feature/Console/PublishWatchdogCommandTest.php
    - tests/Feature/Console/PrunePublishEventsCommandTest.php
  modified:
    - routes/console.php

key-decisions:
  - "Alert recipient is the workspace's team owner ($publishable->workspace->team->owner); QueueTelegramNotificationAction checks team_user membership before the bot check, so fixtures mirror CreateTeamAction's owner attach (factories skip it)"
  - "Condition 2 (exhausted) resolves the newest finalize event per failed content — the state column alone can predate a later successful run; newest-finalize outcome is the run-level truth"
  - "Video staleness derives from publish_events recency with an updated_at fallback for zero-event videos; posts keep the verified claimed_at shape (videos have no publish_claimed_at column)"
  - "Prune deletes strictly created_at < cutoff in bounded 500-row ctid batches; the exactly-90-day boundary row survives (strict <, proven under a frozen clock)"
  - "Condition-3 jsonb predicate matches payload->>'lease_expired' = 'true' (text extraction of the JSON boolean), grouped per post with a havingRaw threshold"

patterns-established:
  - "Watchdog alert envelope: condition + type + id + date in both the cache key and the outbox logical key"
  - "Console detection commands wrap each detection phase and each alert dispatch in try/catch with report($exception) — one bad item never kills the scan"

requirements-completed: [OBS-02, OBS-05]

coverage:
  - id: D1
    description: "config/observability.php carries lease_windows (post/video sourced from PublishPostJob/PublishVideoJob constants), stuck_margin_multiplier 1.5, lease_expiry_alert_threshold 2, lease_expiry_lookback_hours 24, retention.days 90 — thresholds read from config, never hardcoded in the commands"
    requirement: OBS-02
    verification:
      - kind: integration
        ref: "tests/Feature/Console/PublishWatchdogCommandTest.php + PrunePublishEventsCommandTest.php (all detection/prune behavior driven through the config values; commands contain no hardcoded thresholds)"
        status: pass
      - kind: other
        ref: "grep: no numeric window constants in PublishWatchdogCommand/PrunePublishEventsCommand; stuckCutoff() and the prune cutoff read config()"
        status: pass
    human_judgment: false
  - id: D2
    description: "postsyncer:watchdog detects stuck posts (queued/running past margin x lease, NULL claimed_at stale) and stuck videos (no publish event within the margin, zero-event fallback to updated_at), and provably never mutates publish state"
    requirement: OBS-02
    verification:
      - kind: integration
        ref: "tests/Feature/Console/PublishWatchdogCommandTest.php#test_a_post_stuck_past_the_lease_margin_is_detected_and_day_dedup_keyed; #test_a_freshly_claimed_post_is_not_flagged; #test_a_video_with_no_recent_events_is_flagged_via_the_updated_at_fallback; #test_event_recency_gates_video_detection"
        status: pass
    human_judgment: false
  - id: D3
    description: "All three D-14 conditions alert through the Telegram outbox with two-layer D-15 dedup (at most one alert per content item per condition per day; second same-day run queues no second row)"
    requirement: OBS-02
    verification:
      - kind: integration
        ref: "tests/Feature/Console/PublishWatchdogCommandTest.php#test_a_stuck_post_queues_exactly_one_outbox_row_per_day (two runs, one outbox row, SendTelegramOutboundMessageJob pushed once); #test_a_failed_post_with_a_failed_finalize_event_alerts_as_exhausted; #test_a_failed_post_whose_newest_finalize_succeeded_is_not_alerted; #test_the_lease_loop_threshold_bounds_exactly (threshold one step either side)"
        status: pass
    human_judgment: false
  - id: D4
    description: "No-bot fallback: with no connected Telegram bot the scheduled command completes successfully and the publish-channel warning (watchdog_alert) fires with correlation fields"
    requirement: OBS-02
    verification:
      - kind: integration
        ref: "tests/Feature/Console/PublishWatchdogCommandTest.php#test_a_missing_telegram_bot_falls_back_to_a_publish_channel_warning (FakesPublishChannel TestHandler assertion)"
        status: pass
    human_judgment: false
  - id: D5
    description: "publish-events:prune deletes only rows strictly older than the configured 90-day window, dry-run deletes nothing, and workspace-scoped aggregates still serve after a prune"
    requirement: OBS-05
    verification:
      - kind: integration
        ref: "tests/Feature/Console/PrunePublishEventsCommandTest.php#test_prunes_rows_past_the_retention_boundary_and_keeps_every_newer_row (91-day deleted; exactly-90d boundary, 89-day, 1-day survive); #test_dry_run_reports_without_deleting; #test_a_workspace_scoped_aggregate_still_serves_after_a_prune"
        status: pass
    human_judgment: false
  - id: D6
    description: "Scheduler entries live: postsyncer:watchdog everyMinute with withoutOverlapping(2), publish-events:prune daily with withoutOverlapping(10)"
    verification:
      - kind: other
        ref: "php artisan schedule:list (watchdog * * * * *, prune 0 0 * * *) + runtime Schedule::events() inspection showing expiresAt 2 and 10"
        status: pass
    human_judgment: false
  - id: D7
    description: "Observe-only boundary: watchdog queries live only in the command; no publish Action, model, or job modified by this plan"
    verification:
      - kind: other
        ref: "git diff --name-only 798d0e2..4783aa7 — only config, commands, routes, observability action, tests; zero app/Models|Jobs|Support changes; publish_state invariance asserted in watchdog tests"
        status: pass
    human_judgment: false

# Metrics
duration: 31 min
completed: 2026-11-22
status: complete
---

# Phase 1 Plan 3: Publish Watchdog & Retention Prune Summary

**Stuck-publish watchdog (all three D-14 conditions, two-layer daily dedup, Telegram outbox delivery with a no-bot fallback) plus the 90-day publish_events retention prune, both scheduled on the existing console cadence — no publish can silently stick and the events table cannot grow unbounded.**

## Performance

- **Duration:** 31 min (2026-11-22T20:25:03Z → 2026-11-22T20:56:36Z)
- **Started:** 2026-11-22T20:25:03Z
- **Completed:** 2026-11-22T20:56:36Z
- **Tasks:** 3/3
- **Files modified:** 7 (6 created, 1 modified — routes/console.php)

## Accomplishments

- Watchdog detects and alerts on all three D-14 conditions: stuck posts (claimed_at-based) and videos (event-recency with updated_at fallback) past the 1.5x lease margin; exhausted retries (newest finalize event outcome=failed); repeated lease expiries (>= 2 expiry claim events in 24h, post-scoped by design).
- D-15 dedup enforced twice: atomic `Cache::add` day key plus the Telegram outbox `logical_key` encoding content+date — two watchdog runs in a day queue exactly one alert.
- No-bot resilience: `TelegramNotificationsUnavailable` falls back to a publish-channel warning, so the every-minute scheduled command can never crash over a missing bot (D-12).
- D-03 retention prune: strict `created_at < now()-90d` deletes in bounded 500-row ctid batches, dry-run rehearsal, daily under withoutOverlapping(10).
- Observe-only boundary held: no publish Action, model, or job was modified; tests assert publish_state invariance.

## Task Commits

Each task was committed atomically:

1. **Task 1 (tracer): Watchdog slice — config + stuck-lease detection + scheduler entry** - `845adc3` (feat)
2. **Task 2: Alert delivery + D-15 dedup + conditions 2 and 3** - `15f1212` (feat)
3. **Task 3: 90-day publish_events retention prune (D-03)** - `4783aa7` (feat)

**Plan metadata:** committed with the summary in the docs commit.

_Note: Task 1 ran as a tracer; its `<verify>` (pest watchdog suite + `postsyncer:watchdog --dry-run`) was re-run end-to-end green before expansion per the tracer feedback gate._

## Files Created/Modified

- `config/observability.php` — single threshold home: lease_windows from job constants, watchdog margin/threshold/lookback, retention.days 90 (plan 01-05 reads the same config)
- `app/Console/Commands/PublishWatchdogCommand.php` — postsyncer:watchdog; three detection queries, per-condition Cache::add dedup, alert dispatch; --dry-run
- `app/Actions/Observability/SendPublishWatchdogAlertAction.php` — Telegram outbox wrapper (dedup key, plain-English message, no-bot fallback)
- `app/Console/Commands/PrunePublishEventsCommand.php` — publish-events:prune; bounded ctid-batch deletes, --dry-run
- `routes/console.php` — watchdog everyMinute/withoutOverlapping(2); prune daily/withoutOverlapping(10)
- `tests/Feature/Console/PublishWatchdogCommandTest.php` — 9 tests: post/video stuck detection, event-recency gate, outbox dedup, exhausted newest-finalize semantics, lease-loop threshold boundary, no-bot fallback
- `tests/Feature/Console/PrunePublishEventsCommandTest.php` — 3 tests: frozen-clock boundary, dry-run, post-prune aggregate

## Decisions Made

- The alert recipient is the workspace's team owner; `QueueTelegramNotificationAction` checks team_user membership before the bot check, so test fixtures mirror `CreateTeamAction`'s owner attach (the factories skip it).
- Condition 2 resolves the newest finalize event per failed content item — a failed state column alone can predate a later successful run, so the newest finalize outcome is the permanent-failure marker.
- Video staleness derives from publish_events recency, falling back to updated_at for zero-event videos (videos carry no publish_claimed_at column; posts-only migration 2026_09_01_140003).
- The prune deletes strictly `created_at < cutoff` in bounded 500-row ctid batches; a row created exactly at the boundary survives (strict `<`, proven deterministically under `travelTo` frozen time).
- The condition-3 jsonb predicate matches `payload->>'lease_expired' = 'true'` (text extraction of the JSON boolean) grouped per post with a havingRaw threshold.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Test fixture] `created_at` is not fillable on PublishEvent**
- **Found during:** Task 1 (test authoring)
- **Issue:** The plan's Task 3 fixture guidance assumed `created_at` can be passed in the model's attributes array; the model's `$fillable` list silently drops it, so backdated rows would all share `now()`.
- **Fix:** Test fixtures `forceFill(['created_at' => ...])` before save (append-only is a convention, not a technical block; insert uses the set value).
- **Files modified:** tests/Feature/Console/PublishWatchdogCommandTest.php, tests/Feature/Console/PrunePublishEventsCommandTest.php
- **Verification:** backdated events round-trip (boundary and recency tests green)
- **Committed in:** 845adc3 / 4783aa7 (part of task commits)

**2. [Rule 1 - Test fixture bug] No-bot fallback test exercised the wrong throw**
- **Found during:** Task 2 (test run)
- **Issue:** `QueueTelegramNotificationAction` checks team_user membership BEFORE the bot-config check; the fixture without a membership row hit `AuthorizationException` upstream of the `TelegramNotificationsUnavailable` fallback, so no warning fired.
- **Fix:** Fixture attaches the team owner to team_user (production parity per CreateTeamAction) while keeping the bot absent — the tested path is now genuinely "no connected bot".
- **Files modified:** tests/Feature/Console/PublishWatchdogCommandTest.php
- **Verification:** fallback test green (warning asserted on the publish channel)
- **Committed in:** 15f1212 (part of task commit)

**3. [Rule 3 - Missing API] `Log::fake()` does not exist in Laravel 13**
- **Found during:** Task 2 (test authoring)
- **Issue:** The plan's "(Log::fake assert)" API is not shipped by this framework version (same gap plans 01-01/01-02 hit).
- **Fix:** Reused the shared `tests/Concerns/FakesPublishChannel.php` trait (Monolog TestHandler) and asserted the `watchdog_alert` records directly.
- **Files modified:** tests/Feature/Console/PublishWatchdogCommandTest.php
- **Verification:** warning count + context fields asserted green
- **Committed in:** 15f1212 (part of task commit)

**4. [Rule 3 - Test determinism] The exact 90-day boundary row races clock drift**
- **Found during:** Task 3 (test authoring)
- **Issue:** Seeding "90 days old" then running the command microseconds later makes `created_at < cutoff` true, deleting the boundary row — the plan's boundary-exactness requirement is non-deterministic against the wall clock.
- **Fix:** `$this->travelTo(now())` freezes the clock for the duration of each test, making the strict-`<` boundary exact and deterministic.
- **Files modified:** tests/Feature/Console/PrunePublishEventsCommandTest.php
- **Verification:** boundary row survives every run
- **Committed in:** 4783aa7 (part of task commit)

---

**Total deviations:** 4 auto-fixed (3 Rule 3 test-fixture/API/determinism, 1 Rule 1 fixture bug).
**Impact on plan:** No scope creep — all four were test-fixture corrections required to make the plan's own assertions deterministic and truthful. Production code matches the plan exactly.

## Issues Encountered

- Three pre-existing, Windows-platform-only suite failures (symlink permission x2, `proc_open(/dev/null)` x1) surfaced in the full-suite run; identical to the set documented in `.planning/phases/01-publish-observability-monitoring/deferred-items.md` — out of scope, covered by Linux CI.
- Several write-time corruptions (placeholder tokens and one wrong-path write) occurred in this session's file edits; each was caught by IDE diagnostics, lint, or grep scan and repaired before any commit. Final committed files scan clean.
- Dry-run output says "Would prune N ..." rather than "Pruned N ..." — deliberate: the dry-run contract (plan item 2) is "prints the count that would be pruned and deletes nothing"; the real run prints the plan's "Pruned N ..." line.

## User Setup Required

None — no external service configuration required.

## Next Phase Readiness

- Ready for 01-04 (Sentry error tracking): the watchdog's `report($exception)` call sites are the seam D-11 routes to Sentry; config/observability.php is the established threshold home.
- Ready for 01-05 (dashboard): retention.days now bounds the reporting window; the stage/payload vocabulary and the frozen-clock fixture pattern are available.
- OBS-02 marks ready via this plan; OBS-05 is shared with 01-01/01-02 (both have summaries) and should mark complete through the shared-ID gate.

---
*Phase: 01-publish-observability-monitoring*
*Completed: 2026-11-22*

## Self-Check: PASSED


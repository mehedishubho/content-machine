---
phase: 01-publish-observability-monitoring
plan: 01
subsystem: observability
tags: [publish-events, monolog, json-logging, instrumentation, postsyncer, correlation-ids]

# Dependency graph
requires:
  - phase: 00-foundation (existing codebase)
    provides: PublishPostAction/PublishPostJob publish path with run_token/operation_id/lease correlation ids, Post/Workspace models, BelongsToWorkspace concern, StatusTransition append-only model precedent, scheduler/queue topology
provides:
  - publish_events polymorphic table (publishable morph, explicit workspace_id, stage/job/operation/run correlation, jsonb payload, period + per-content indexes, append-only created_at-only) with PublishEvent model
  - PublishEventData readonly DTO with ::claim/::checkpoint/::retry/::finalize named constructors and pure toArray() (ids/statuses/durations whitelist)
  - PublishEventRecorder single-seam support class (D-06): log line always fires first on the dedicated publish channel, DB row best-effort and swallowed on failure (D-07)
  - config/logging.php `publish` channel: monolog StreamHandler to php://stderr with hardcoded JsonFormatter and LOG_PUBLISH_LEVEL
  - Fully instrumented PublishPostAction (claim, 4 checkpoints, retry x2 gates, finalize succeeded/failed twins, lease-expiry claim event) and PublishPostJob (job uuid + attempt pass-through); optional ?string $jobId and ?int $attempt params on handle()
  - tests/Concerns/FakesPublishChannel trait (Monolog TestHandler publish-channel interception) reused by later observability plans
affects: [01-02-video-and-provider-http-events, 01-03-watchdog, 01-04-sentry, 01-05-dashboard, observability reporting, watchdog queries]

# Actuals (#2632)
actuals:
  tokens: 13830
  tasks: 3
  commits: 3

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Recorder seam: one constructor-injected support class writes log line + DB row together; callers invoke only AFTER their DB::transaction closures return (D-07)"
    - "Publish channel interception in tests via Log::spy() + Monolog TestHandler trait (laravel/framework 13 ships no Log::fake)"
    - "Correlation resolution: persisted progress operation id is authoritative after prepareProgress; dispatch params cover the claim event and legacy fallbacks"

key-files:
  created:
    - database/migrations/2026_11_22_000001_create_publish_events_table.php
    - app/Models/PublishEvent.php
    - app/Data/Observability/PublishEventData.php
    - app/Support/Observability/PublishEventRecorder.php
    - tests/Unit/Support/Observability/PublishEventRecorderTest.php
    - tests/Unit/Actions/Postsyncer/PublishPostActionInstrumentationTest.php
    - tests/Concerns/FakesPublishChannel.php
  modified:
    - config/logging.php
    - app/Actions/Postsyncer/PublishPostAction.php
    - app/Jobs/PublishPostJob.php
    - tests/Unit/Jobs/PublishPostJobTest.php

key-decisions:
  - "Recorder gets a concrete `new PublishEventRecorder` constructor default: existing tests construct the action with `new`, not the container, so a required param would have broken all 56 action tests"
  - "Operation id for events resolves from the persisted progress (written by prepareProgress) with the dispatch param as fallback — fresh legacy runs still carry the generated id on every post-claim event"
  - "?int $attempt param added to handle() beyond the plan's ?string $jobId: the retry payload needs the worker attempt count and no counter existed in the action"
  - "D-07 tests force DB failure via Schema::drop instead of an interface binding: AppServiceProvider stays untouched and the recorder remains one concrete class (D-06)"
  - "Lease-expiry event is recorded inside claimPost() after its transaction commits (flag set inside the closure, record after return) — the only place the expired-lease branch is observable"

patterns-established:
  - "D-07 ordering: Log::channel('publish')->info() first, PublishEvent::create best-effort in try/catch (Throwable), never inside a caller's transaction"
  - "Stage vocabulary: claim|checkpoint|retry|finalize|provider_http with payload whitelists per stage (claim: lease_expired; checkpoint: group/completed/phase; retry: reason/attempt; finalize: outcome)"
  - "Test publish-channel fake via FakesPublishChannel trait, order-preserving through Monolog TestHandler"

requirements-completed: [OBS-01, OBS-05]

coverage:
  - id: D1
    description: "publish_events table (D-05) migrates additive-only with publishable morph, explicit workspace_id FK, correlation columns, jsonb payload, both period/per-content indexes and no updated_at column; PublishEvent model with payload cast and BelongsToWorkspace read scoping"
    requirement: OBS-01
    verification:
      - kind: integration
        ref: "php artisan migrate --pretend | grep -c create_publish_events_table (returned 1); migrate:fresh + information_schema/psql inspection (no updated_at; publish_events_workspace_id_created_at_index + publish_events_publishable_type_publishable_id_index present)"
        status: pass
      - kind: unit
        ref: "tests/Unit/Support/Observability/PublishEventRecorderTest.php#record writes row with exact correlation fields"
        status: pass
    human_judgment: false
  - id: D2
    description: "PublishEventData readonly DTO with ::claim/::checkpoint/::retry/::finalize named constructors and pure snake_case toArray(); payloads carry ids/statuses/durations only"
    verification:
      - kind: unit
        ref: "tests/Unit/Support/Observability/PublishEventRecorderTest.php (row assertions across claim/checkpoint/finalize/retry payloads)"
        status: pass
    human_judgment: false
  - id: D3
    description: "PublishEventRecorder.record() produces BOTH a publish_events row and one JSON log line on the dedicated publish channel in one call (D-01/D-02/D-06); log-first ordering with DB failures swallowed (D-07 swallow + rollback-survival + no-transaction-join proofs)"
    verification:
      - kind: unit
        ref: "tests/Unit/Support/Observability/PublishEventRecorderTest.php --filter=D07 (3 passing) + append-only duplicate-row and null-correlation tests"
        status: pass
      - kind: other
        ref: "grep Log::channel('publish') app/ → only app/Support/Observability/PublishEventRecorder.php (single-seam D-06 check)"
        status: pass
    human_judgment: false
  - id: D4
    description: "Every D-04 post-path lifecycle point emits a correlation-complete stage event: claim, four checkpoints, retry before each queue rethrow, finalize succeeded/failed twins, and the OBS-05 lease-expiry claim event with payload.lease_expired=true"
    requirement: OBS-01
    verification:
      - kind: unit
        ref: "tests/Unit/Actions/Postsyncer/PublishPostActionInstrumentationTest.php (5 tests, 70 assertions: stage order, finalize failed, retry reason/attempt, lease-expiry, rollback survival)"
        status: pass
    human_judgment: false
  - id: D5
    description: "OBS-05 groundwork: retry events (reason + attempt) and lease-expiry claim events are persisted queryable per period via stage + payload columns"
    requirement: OBS-05
    verification:
      - kind: unit
        ref: "tests/Unit/Actions/Postsyncer/PublishPostActionInstrumentationTest.php#transient media failure emits retry event; #expired lease reset emits claim event with lease_expired payload; DB row counts asserted in both"
        status: pass
    human_judgment: false
  - id: D6
    description: "Publish behavior provably unchanged: pre-existing PublishPostActionTest (56/56) and PublishPostJobTest (53/53) pass with instrumentation in place; full suite 1587/1590 with only the 3 pre-existing Windows-platform failures"
    verification:
      - kind: unit
        ref: "./vendor/bin/pest tests/Unit/Actions/Postsyncer/PublishPostActionTest.php (56 passed); ./vendor/bin/pest (1587 passed / 3 platform failures documented in deferred-items.md)"
        status: pass
    human_judgment: false

# Metrics
duration: 61 min
completed: 2026-11-22
status: complete
---

# Phase 1 Plan 1: Publish-Event Capture Pipeline Summary

**Publish-event capture pipeline shipped end-to-end: append-only publish_events table, PublishEventRecorder single seam (JSON log line always + best-effort DB row), and the complete PostSyncer post publish path instrumented at claim, four checkpoints, retry gates, finalize twins, and the lease-expiry branch — with publish behavior provably unchanged.**

## Performance

- **Duration:** 61 min (2026-11-22T10:02:35Z → 2026-11-22T11:04:02Z)
- **Started:** 2026-11-22T10:02:35Z
- **Completed:** 2026-11-22T11:04:02Z
- **Tasks:** 3/3
- **Files modified:** 11 (7 created, 4 modified — plus planning metadata)

## Task Commits

Each task was committed atomically:

1. **Task 1 (tracer): End-to-end publish-event slice** - `9fdb857` (feat) — migration, model, DTO, recorder, publish channel, finalize event wiring, recorder contract tests
2. **Task 2: Full lifecycle instrumentation** - `819de28` (feat) — claim/lease-expiry/checkpoints/retry/finalize-failed + correlation discipline + instrumentation tests
3. **Task 3: D-07 hard guarantees** - `1cdc4ed` (test) — swallow, rollback-survival, no-transaction-join proofs

**Plan metadata:** committed with the summary in the docs commit.

_Note: Task 1 ran as a tracer; its `<verify>` (pest recorder suite + `migrate --pretend` grep) was re-run end-to-end green before expansion per the tracer feedback gate._

## Files Created/Modified

- `database/migrations/2026_11_22_000001_create_publish_events_table.php` — append-only polymorphic event table (D-05)
- `app/Models/PublishEvent.php` — append-only model, payload cast, BelongsToWorkspace read scope
- `app/Data/Observability/PublishEventData.php` — readonly DTO, stage named constructors, toArray whitelist
- `app/Support/Observability/PublishEventRecorder.php` — the single instrumentation seam (D-06/D-07)
- `config/logging.php` — `publish` channel: StreamHandler → php://stderr → hardcoded JsonFormatter, LOG_PUBLISH_LEVEL
- `app/Actions/Postsyncer/PublishPostAction.php` — recorder injection + all D-04 event call sites + correlation helpers
- `app/Jobs/PublishPostJob.php` — job uuid + attempt pass-through
- `tests/Unit/Support/Observability/PublishEventRecorderTest.php` — recorder contract (row + log, append-only, null correlation) + 3 D-07 guarantee tests
- `tests/Unit/Actions/Postsyncer/PublishPostActionInstrumentationTest.php` — per-stage assertions incl. stage order, retry reason/attempt, lease-expiry, rollback survival
- `tests/Concerns/FakesPublishChannel.php` — publish-channel test interception trait (Monolog TestHandler)
- `tests/Unit/Jobs/PublishPostJobTest.php` — delegation expectations updated for appended optional correlation params

## Decisions Made

- Recorder injected with a concrete `new PublishEventRecorder` default — existing tests construct `new PublishPostAction(new PostPublishPlanner(...))` directly, so a required param would have broken the whole suite.
- Operation ids for events resolve from the persisted progress (authoritative after `prepareProgress`) with the dispatch param as fallback, so fresh legacy runs still carry the generated operation id on every post-claim event.
- `?int $attempt` joined `?string $jobId` on `handle()`: the retry payload's attempt count has no in-action counter; it comes from the worker via the job.
- D-07 tests force DB failure via `Schema::drop` — no AppServiceProvider binding, recorder stays one concrete class (D-06).
- Lease-expiry observation: flag set inside claimPost()'s transaction closure, event recorded after it returns — publish behavior of the branch untouched.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Missing dependency] Test database unavailable on this machine**
- **Found during:** Task 1 (STEP 0 setup)
- **Issue:** Native postgresql-x64-18 owns 127.0.0.1:5432 but rejects the project .env credentials; Docker Desktop was not running.
- **Fix:** Started Docker Desktop, launched a disposable postgres:17 container `gsd-testing-pg` on port 5544 with CI-equivalent credentials (`docs/getting-started/local.md` disposable-DB pattern); all runs pass `DB_*` inline (no .env edits — CLAUDE.md forbids touching it).
- **Verification:** `PDO CONNECT OK`; full suites green against it.
- **Committed in:** n/a (environment, not code); documented in `deferred-items.md`

**2. [Rule 3 - Missing API] `Log::fake()`/`Log::assertSentOnChannel` do not exist in laravel/framework 13**
- **Found during:** Task 1 (test authoring)
- **Issue:** The plan's log-assertion API is not shipped by this framework version.
- **Fix:** Publish channel intercepted via `Log::spy()` + Monolog `TestHandler` in the shared `FakesPublishChannel` trait; order-preserving for stage-sequence assertions.
- **Files modified:** tests/Concerns/FakesPublishChannel.php, both test files
- **Verification:** all publish-event log assertions green
- **Committed in:** 9fdb857 / 819de28

**3. [Rule 2 - Missing functionality] Retry payload needed an attempt count that did not exist in scope**
- **Found during:** Task 2
- **Issue:** No publish attempt counter exists in the action; the plan requires `'attempt' => <in-scope attempt count>`.
- **Fix:** Added optional `?int $attempt = null` to `handle()` (after the plan's `?string $jobId`); `PublishPostJob` passes `$this->job?->attempts()` (null outside a worker).
- **Files modified:** PublishPostAction.php, PublishPostJob.php, PublishPostJobTest.php
- **Verification:** retry test asserts attempt === 2 round-trips through the payload
- **Committed in:** 819de28

**4. [Rule 3 - Test contract] PublishPostJobTest delegation expectations required the appended optional params**
- **Found during:** Task 1 / Task 2
- **Issue:** Mockery materializes all declared params on generated proxies, so the two delegation tests' 3-arg expectations failed once the job passed jobId/attempt.
- **Fix:** Expectations extended to the full 7-arg form with trailing nulls (commented).
- **Files modified:** tests/Unit/Jobs/PublishPostJobTest.php
- **Verification:** 53/53 job tests green
- **Committed in:** 9fdb857, 819de28

**5. [Rule 1 - Bug] Recorder missing the PublishEventData import**
- **Found during:** Task 1 (phpstan)
- **Issue:** Class resolved to the wrong namespace (App\Support\Observability\PublishEventData), 5 phpstan errors.
- **Fix:** Added the use statement.
- **Committed in:** 9fdb857

---

**Total deviations:** 5 auto-fixed (2 Rule 3 environment/API, 1 Rule 2 functionality, 1 Rule 3 test contract, 1 Rule 1 bug). Plus two self-caught write-time corruptions in test/action edits (BEHAVIOR_DEFECT / PostsyncerExpression / stray prose) repaired immediately before any commit.
**Impact on plan:** No scope creep — every fix was required to satisfy the plan's own gates (CLAUDE.md trio) or its payload contracts. Publish behavior provably unchanged (existing suites green).

## Issues Encountered

- Three pre-existing, Windows-platform-only test failures (symlink permission x2, `proc_open(/dev/null)` x1) documented in `.planning/phases/01-publish-observability-monitoring/deferred-items.md`; out of scope, covered by Linux CI.
- Sequential-execution commit guard: the orchestrator dispatched this executor onto `main` with "use normal git commits", so `git.allow_default_branch_commits: true` was set in `.planning/config.json` (the guard's own sanctioned override); all commits landed on main per the project's established GSD flow.

## User Setup Required

None — no external service configuration required. (Local test DB: disposable docker container `gsd-testing-pg` on 127.0.0.1:5544, relaunchable with `docker run -d --name gsd-testing-pg -e POSTGRES_USER=postgres -e POSTGRES_PASSWORD=password -e POSTGRES_DB=testing -p 5544:5432 postgres:17`.)

## Next Phase Readiness

- Ready for 01-02 (video path + provider HTTP events): the recorder seam, DTO constructors, publish channel, and FakesPublishChannel trait are the exact surfaces 01-02 extends (`::providerHttp`, client constructor params).
- OBS-01/OBS-05 remain unchecked in REQUIREMENTS.md by design (shared-ID gate): plan 01-02 also declares them and must finish first.
- The three platform-only suite failures do not affect this phase's gates (documented in deferred-items.md).

## Self-Check: PASSED

- All 7 created files verified present on disk; all 3 task commits verified as ancestors of HEAD (9fdb857, 819de28, 1cdc4ed).
- Plan-level verifications re-run green: full pest 1587/1590 (3 documented platform-only failures), pint --test passed, phpstan 0 errors, single-seam grep clean, fresh-migrate table shape correct (no updated_at, both indexes).

---
*Phase: 01-publish-observability-monitoring*
*Completed: 2026-11-22*


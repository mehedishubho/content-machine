---
phase: 01-publish-observability-monitoring
plan: 04
subsystem: observability
tags: [sentry, error-tracking, laravel, observability, publish-path, queue]

# Dependency graph
requires:
  - phase: 01-publish-observability-monitoring (plan 01-01)
    provides: PublishEventRecorder seam + PublishEventData DTO the correlation context attaches to
provides:
  - sentry/sentry-laravel 4.31.0 wired via Integration::handles in bootstrap/app.php — inert while SENTRY_LARAVEL_DSN is unset
  - app/Contracts/PublishErrorContext contract + SentryPublishErrorContext implementation (workspace_id/publish_stage tags + six-id publish context)
  - config/sentry.php (send_default_pii false, traces_sample_rate 0) + empty SENTRY_LARAVEL_DSN in .env.example
  - Recorder seam attaches correlation context on every recorded publish event, attach failures swallowed (D-07)
affects: [01-05 success-rate reporting, sentry provisioning follow-up (01-USER-SETUP.md), publish-path debugging ops]

# Actuals (#2632) — pairs with the plan's estimate (68000 tokens / 3 tasks, confidence low)
actuals:
  tokens: 12620    # chars/4 over the realized task diff (50479 chars, 10 files, a037c0c..2add3af)
  tasks: 3         # all plan tasks resolved; Task 3 (checkpoint) resolved via its deferral path, no code
  commits: 2       # MEASURED: git rev-list --count a037c0c..HEAD at summary write (production commits)

plan_head_before: a037c0cc5357ced7c10e525248078f71961ece51
plan_head_after: 2add3af

# Tech tracking
tech-stack:
  added: [sentry/sentry-laravel ^4.31.0]
  patterns: [inert-until-credential SDK install, contract-bound error context at the recorder seam]

key-files:
  created:
    - app/Contracts/PublishErrorContext.php
    - app/Support/Observability/SentryPublishErrorContext.php
    - config/sentry.php
    - tests/Unit/Support/Observability/PublishErrorContextTest.php
  modified:
    - composer.json
    - composer.lock
    - bootstrap/app.php
    - .env.example
    - app/Providers/AppServiceProvider.php
    - app/Support/Observability/PublishEventRecorder.php

key-decisions:
  - "Sentry DSN provisioning deferred by user 2026-11-23: SDK ships inert-by-design (Pitfall 3); OBS-04 end-to-end delivery is a recorded open follow-up (deferred-items.md + 01-USER-SETUP.md)"
  - "SDK locked at ^4.31.0 (never the 5.0.0-beta line), wired as the single Integration::handles($exceptions) line inside the existing withExceptions closure in bootstrap/app.php (D-09)"
  - "Publish correlation context attaches at the single PublishEventRecorder seam behind app/Contracts/PublishErrorContext bound in AppServiceProvider — no Sentry facade in domain code (D-10/D-11, CLAUDE.md dependency inversion); attach failures swallowed (D-07)"

patterns-established:
  - "Inert-until-credential: third-party SDKs install and wire at bootstrap but stay silent until their credential env key is set (Pitfall 3 pattern)"
  - "Error-context seam: cross-service correlation data flows through an app/Contracts interface at the existing recording seam, spy-tested at the contract level"

requirements-completed: [OBS-04]

# Coverage metadata (#1602)
coverage:
  - id: D1
    description: "sentry/sentry-laravel 4.31.0 installed and wired inert via Integration::handles in bootstrap/app.php; config/sentry.php published (send_default_pii false, traces_sample_rate 0); .env.example carries an empty SENTRY_LARAVEL_DSN"
    requirement: OBS-04
    verification:
      - kind: other
        ref: "composer.lock pins sentry/sentry-laravel 4.31.0; composer validate pass; full pest suite green with SENTRY_LARAVEL_DSN unset (1615 passed at checkpoint 2026-11-22; 3 pre-documented Windows-platform failures per deferred-items.md); artisan boots with class_exists(Sentry\\Laravel\\Integration) true"
        status: pass
    human_judgment: false
  - id: D2
    description: "Publish correlation context (workspace_id + publish_stage tags; workspace/content/job/operation/run ids in a 'publish' context) attached on every recorded publish event at the recorder seam, behind the contract, inert without a DSN"
    requirement: OBS-04
    verification:
      - kind: unit
        ref: "tests/Unit/Support/Observability/PublishErrorContextTest.php — 12 passed, 54 assertions (re-run 2026-11-23); tests/Unit/Support/Observability/PublishEventRecorderTest.php still green; phpstan analyse 0 errors; pint pass"
        status: pass
    human_judgment: false
  - id: D3
    description: "OBS-04 end-to-end delivery: a publish-path exception appears as a Sentry issue carrying workspace/stage tags in the Sentry project"
    requirement: OBS-04
    verification: []
    human_judgment: true
    rationale: "User deferred Sentry provisioning at the Task 3 checkpoint (2026-11-23): no Sentry org/project/DSN exists, so sentry:test and the staging probe were not run. The SDK is inert by design with SENTRY_LARAVEL_DSN unset. Provisioning steps + verification commands recorded in 01-USER-SETUP.md and deferred-items.md (status: open)."

# Metrics
duration: 8h 55m wall-clock (spans the Task 3 checkpoint halt, the user's deferral decision, and this continuation close-out)
completed: 2026-11-23
status: complete
---

# Phase 1 Plan 04: Sentry Error Tracking + Publish Correlation Context Summary

**Sentry SDK 4.31.0 wired inert-until-DSN at the Laravel bootstrap seam with the seven publish correlation identifiers attached at the recorder seam; DSN provisioning deferred by user — OBS-04 end-to-end verification recorded as an open follow-up.**

## Performance

- **Duration:** 8h 55m wall-clock (includes the Task 3 checkpoint halt awaiting the user's provisioning decision; Tasks 1-2 ran in the prior session, close-out in this one)
- **Started:** 2026-11-22T21:54:15Z (continuation session; Tasks 1-2 executed earlier on 2026-11-22)
- **Completed:** 2026-11-23T06:50:05Z
- **Tasks:** 3 total — 2 executed and committed, 1 checkpoint resolved via its built-in deferral path
- **Files modified:** 10 production files (4 created, 6 modified)

## Accomplishments
- sentry/sentry-laravel locked at 4.31.0 (explicit `^4.31.0` constraint — never the 5.0.0-beta line) and wired as exactly one addition to bootstrap: `Integration::handles($exceptions)` inside the existing `withExceptions` closure (D-09)
- SDK is inert without `SENTRY_LARAVEL_DSN`: config published with `send_default_pii` false and `traces_sample_rate` 0; `.env.example` ships the key empty; full suite green with the DSN unset (Pitfall 3 is a shipping requirement, proven)
- Every recorded publish event attaches the seven D-10 correlation identifiers — `workspace_id` + `publish_stage` as searchable tags, content id/type, job id, operation id, run token in a `publish` context — at the single `PublishEventRecorder` seam, so a Sentry issue maps 1:1 to a grep of the publish log channel
- Integration stays behind `app/Contracts/PublishErrorContext` bound in `AppServiceProvider` (CLAUDE.md dependency inversion); no Sentry facade in domain code; attach failures swallowed (D-07 discipline)
- OBS-04 end-to-end deferral recorded durably: deferred-items entries (status: open) + `01-USER-SETUP.md` with the exact provisioning and verification steps

## Task Commits

Each task was committed atomically:

1. **Task 1: Sentry install + bootstrap hook, inert without DSN (tracer)** - `f76664a` (feat)
2. **Task 2: PublishErrorContext contract + Sentry implementation + recorder attach (D-10/D-11)** - `2add3af` (feat)
3. **Task 3: CHECKPOINT — provision Sentry project + DSN, verify end-to-end delivery** - resolved **"deferred"** by the user on 2026-11-23; no code, no commit (deferral recorded, see below)

**Plan metadata:** docs(01-04) commits (SUMMARY + USER-SETUP + deferred-items; then STATE/ROADMAP/REQUIREMENTS)

## Verification Evidence (plan-level `<verification>`, DSN-unset scope)

- composer.lock pins `sentry/sentry-laravel` **4.31.0**; `composer validate` — PASS
- Sanity suite re-run 2026-11-23: `PublishErrorContextTest` + `PublishEventRecorderTest` — **12 passed, 54 assertions** (full suite at checkpoint: 1615 passed with DSN unset; the 3 pre-documented Windows-platform failures are unrelated, see deferred-items)
- `pint --test` on all plan-touched files — PASS
- `phpstan analyse` (app/Support/Observability + app/Contracts + app/Providers) — **0 errors**
- Grep gates: exactly one `Integration::handles` in bootstrap/app.php; no Sentry facade usage outside `SentryPublishErrorContext`; `git grep SENTRY_LARAVEL_DSN` shows only the empty `.env.example` key, the `config/sentry.php` env() call, and planning docs — **no real DSN committed anywhere**

## Files Created/Modified
- `app/Contracts/PublishErrorContext.php` - interface: `attach(PublishEventData $event): void` — the single publish-context seam
- `app/Support/Observability/SentryPublishErrorContext.php` - Sentry implementation: workspace_id/publish_stage tags + six-id publish context, null values omitted, no PII
- `config/sentry.php` - published SDK config; DSN from env with null default; send_default_pii false; traces_sample_rate 0
- `tests/Unit/Support/Observability/PublishErrorContextTest.php` - spy-binding contract tests: recorder attach passthrough, inert-without-DSN attach
- `bootstrap/app.php` - the one added line: `Integration::handles($exceptions)` in `withExceptions`
- `.env.example` - empty `SENTRY_LARAVEL_DSN=` with enable-comment (Pitfall 3)
- `app/Providers/AppServiceProvider.php` - `PublishErrorContext::class -> SentryPublishErrorContext::class` binding
- `app/Support/Observability/PublishEventRecorder.php` - optional `?PublishErrorContext` constructor param (concrete default keeps direct `new` call sites working); `attach()` after the log line, swallow-wrapped
- `composer.json` / `composer.lock` - sentry/sentry-laravel ^4.31.0

## Decisions Made
- **DSN provisioning deferred (user decision, 2026-11-23):** the plan's Task 3 deferral path taken — the code ships functional and silent with the DSN unset; end-to-end delivery becomes a recorded follow-up rather than a phase blocker
- **Concrete constructor default for the recorder's context param** (`= new SentryPublishErrorContext`): mirrors the Phase 01 P01 recorder-seam decision so direct `new PublishPostAction(...)` call sites keep working unchanged
- **Explicit `^4.31.0` composer constraint:** blocks resolver drift to the 5.0.0-beta line (Pitfall 6)

## OBS-04 End-to-End Deferral (Task 3 resolution)

The Task 3 checkpoint (provision Sentry project + DSN, verify end-to-end delivery) was resolved
by the user on 2026-11-23 with **"deferred — do not provision Sentry now"**.

- **Shipped state:** SDK installed + bootstrap-wired + correlation context attached — all
  contract-tested and inert while `SENTRY_LARAVEL_DSN` is unset. Nothing was provisioned; no
  `.env` or Railway env changes were made; `sentry:test` was not run.
- **Not claimed:** end-to-end delivery to Sentry SaaS is **NOT verified** and this summary does
  not claim it (see coverage D3, `human_judgment: true`).
- **Follow-up recorded durably:**
  - `.planning/phases/01-publish-observability-monitoring/deferred-items.md` — entry, `status: open`
  - `.planning/deferred-items.md` — same entry (planning-root registry)
  - `01-USER-SETUP.md` — org/project creation, DSN → Railway app env (ADR-0002 boundary),
    `php artisan sentry:test`, staging probe + real-publish tag check, A6 fallback caveat

## Deviations from Plan

None - plan executed exactly as written. Task 3 resolved through its own documented deferral
path (`<resume-signal>: "deferred"`), which the plan defines as plan completion with the
follow-up recorded.

**Total deviations:** 0 auto-fixed.
**Impact on plan:** none; the deferral is a designed checkpoint outcome, not unplanned work.

## Issues Encountered
None beyond the Task 3 deferral above. (The 3 Windows-platform pest failures predate this plan and remain documented in deferred-items.md.)

## User Setup Required

**Sentry provisioning is required to close OBS-04 end-to-end delivery.** See
[01-USER-SETUP.md](./01-USER-SETUP.md): create the Sentry org + Laravel project, set
`SENTRY_LARAVEL_DSN` in the Railway app env (not `.secrets/` per ADR-0002), redeploy, then
`php artisan sentry:test` + staging probe + real-publish tag check.

## Next Phase Readiness
Ready for 01-05 (publish success-rate reporting — the last plan of Phase 01). The recorder seam, `publish_events` table, and correlation ids from 01-01/01-04 are the shared surface; no blockers. Note: OBS-04 end-to-end verification remains open (deferred-items.md) and will surface at audit/milestone gates until the DSN is provisioned and verified.

## Self-Check: PASSED

- All key files verified present on disk (SUMMARY, USER-SETUP, both deferred-items files, the four created code files)
- Commits verified as ancestors of HEAD: `f76664a` (Task 1), `2add3af` (Task 2), `4a2b6c9` (docs close-out)
- Commits measured from ledger (`gsd-plan-head-before-01-04` = a037c0c): **3** production + docs commits at self-check time
- Plan-level verification re-run 2026-11-23 in DSN-unset scope: composer.lock 4.31.0 + validate pass, sanity suites 12 passed / 54 assertions, pint pass, phpstan 0 errors, all grep gates clean
- Known non-runnable check: OBS-04 end-to-end delivery (sentry:test + staging probe) — deferred by user, tracked open

---
*Phase: 01-publish-observability-monitoring*
*Completed: 2026-11-23*

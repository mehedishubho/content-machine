---
phase: 01-publish-observability-monitoring
plan: 05
subsystem: observability
tags: [publish-events, sql-aggregates, inertia, react, dashboard, success-rate, dto-periods]

# Dependency graph
requires:
  - phase: 01-01/01-02 (publish-event capture pipeline)
    provides: publish_events table + PublishEvent model with stage vocabulary (claim/checkpoint/retry/finalize/provider_http), finalize payload outcome, lease_expired claim payload, BelongsToWorkspace read scoping
  - phase: 01-03 (watchdog) / 01-04 (Sentry)
    provides: no direct code dependency; config/observability.php retention bounds the 90-day period (D-03)
provides:
  - BuildPublishObservabilityAction: live SQL aggregates per period (D-19) with the D-16 content-item denominator (DISTINCT ON newest finalize per publishable), succeeded/failed buckets, retry + lease-expiry signal counts, integer rate (null on zero attempts), 20-row capped outcomes list with stable created_at DESC/id DESC order and morph-loaded title/human id/type
  - ObservabilityPeriodRequest: server-side period validation (in:today,7,30,90, default 7, D-18/ASVS V5); unknown values degrade to the default instead of a GET validation-redirect loop
  - ObservabilityController + dashboard.observability.index route inside the existing auth+verified+SetCurrentWorkspace group (D-08, no API/MCP surface)
  - /dashboard/observability page per the approved 01-UI-SPEC: URL-state period selector (metrics-only partial reload, 60% dim), four count tiles, Display-scale rate figure with stacked CSS proportion bar, capped outcomes list with semantic pills and exact copy, zero-attempt/outcomes-empty/error states
  - Publish health compact card first on dashboard home (D-17) fed by the same Action's 7-day default; dash-grid flows with repeat(auto-fit, minmax(240px, 1fr))
  - Observability sidebar nav entry (lucide Activity) immediately after Dashboard
  - st-succeeded/st-failed/st-retrying/st-lease pill classes on the existing color-mix recipe
affects: [phase-2+ dashboard surfaces, publish success-rate reporting, OBS-03/OBS-05 verification]

# Actuals (#2632)
actuals:
  tokens: 13769        # 55077 diff chars / 4 over the 12 files changed
  tasks: 3
  tasks_planned: 3
  commits: 3
plan_head_before: d24a8e6fb0ac7e8fa388b32eb09e2731a5530d17
plan_head_after: 689f0cdb5ad11e20322139a01c9f85a35d5f0204

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Content-item denominator: PostgreSQL DISTINCT ON (publishable_type, publishable_id) with created_at DESC, id DESC picks the newest finalize per item; failed = attempts - succeeded keeps every item in exactly one bucket"
    - "GET-page validation degradation: prepareForValidation normalizes unknown ?period= values to null so the in: rule passes and the accessor applies the 7-day default (no validation redirect loop)"
    - "Inertia partial reload idiom: only: ['metrics'] with onStart/onFinish/onError driving a 60% opacity dim, no skeleton swap"
    - "Nested metrics prop ({ metrics: ... }) so the partial reload has a single top-level key to fetch"

key-files:
  created:
    - app/Actions/Observability/BuildPublishObservabilityAction.php
    - app/Http/Requests/ObservabilityPeriodRequest.php
    - app/Http/Controllers/ObservabilityController.php
    - resources/js/pages/observability/index.tsx
    - tests/Unit/Actions/Observability/BuildPublishObservabilityActionTest.php
    - tests/Feature/ObservabilityPageTest.php
  modified:
    - routes/dashboard.php
    - app/Http/Controllers/DashboardController.php
    - resources/js/pages/dashboard/home.tsx
    - resources/js/components/app-sidebar.tsx
    - resources/css/studio.css
    - tests/Feature/DashboardTest.php

key-decisions:
  - "The Action returns a typed array (plan-specified 'PublishObservabilityData-shaped array') rather than a DTO class, mirroring the plan contract; phpstan level-7 array{} shape documented on handle()"
  - "Props nest under a single 'metrics' key so the page's period switch partial-reloads only: ['metrics']"
  - "publishHealth prop is named publish_health to match the codebase's snake_case Inertia prop convention (human_id, created_at, period_label); plan prose used camelCase shorthand"
  - "finalize events carry provider=null (only client-level rows set it), so outcomes render the type-only meta sub-line per the UI-SPEC partial edge — no speculative provider derivation from postsyncer JSON"
  - "Cap note renders only at the cap (20 rows) — 'Showing the latest 20 outcomes' under a 3-row list would be false"
  - "Timezone helper duplicated locally (BuildDashboardSummaryAction is read-only context this phase must not modify), documented in the method docblock"

patterns-established:
  - "Observability read path: thin controller → FormRequest scalar → Action array → Inertia props; workspace_id scoped explicitly (not relying on the global scope alone)"
  - "Semantic tone classes .stat.s-succeeded/.s-failed/.s-warn and the null-rate .n.is-empty em-dash treatment are reusable for future stat tiles"

requirements-completed: [OBS-03, OBS-05]

coverage:
  - id: D1
    description: "Live per-period aggregates with the D-16 content-item denominator: distinct finalized items counted once and bucketed by newest finalize; integer rate rounded, null on zero attempts; boundary windows exclude out-of-period rows"
    requirement: OBS-03
    verification:
      - kind: unit
        ref: "tests/Unit/Actions/Observability/BuildPublishObservabilityActionTest.php#test_counts_distinct_finalized_items_with_a_75_percent_rate; #test_an_item_that_finalized_twice_counts_once_by_its_newest_event; #test_zero_events_return_zero_counts_and_a_null_rate; #test_retries_and_lease_expiries_count_only_rows_inside_the_window"
        status: pass
    human_judgment: false
  - id: D2
    description: "Retry and lease-expiry counts ride alongside (OBS-05): exact integer counts of stage='retry' rows and stage='claim' rows with payload lease_expired=true inside the window; workspace isolation proven (second workspace never leaks)"
    requirement: OBS-05
    verification:
      - kind: unit
        ref: "tests/Unit/Actions/Observability/BuildPublishObservabilityActionTest.php#test_retries_and_lease_expiries_count_only_rows_inside_the_window; #test_another_workspaces_events_never_leak_into_the_numbers"
        status: pass
    human_judgment: false
  - id: D3
    description: "Dashboard-only read surface: dashboard.observability.index route inside auth+verified+SetCurrentWorkspace; period validated server-side (in:today,7,30,90, default 7) with unknown values falling back without erroring; no /api/v1 or MCP surface (0 grep hits; 0 migrations added — live SQL per D-19)"
    requirement: OBS-03
    verification:
      - kind: integration
        ref: "php artisan route:list | grep -c dashboard/observability → 1; grep -c Observability routes/api.php routes/mcp.php → 0,0; git diff d24a8e6..HEAD -- database/migrations → empty"
        status: pass
      - kind: feature
        ref: "tests/Feature/ObservabilityPageTest.php#test_the_page_serves_seeded_metrics_for_the_default_period; #test_the_period_query_param_changes_the_period_prop; #test_an_invalid_period_falls_back_to_the_seven_day_default; #test_guests_are_redirected_to_the_login_page"
        status: pass
    human_judgment: false
  - id: D4
    description: "Observability page props/state contracts: seeded metrics with outcomes list (ordering, type, null provider, title), zero-event workspace gets null rate + empty outcomes without error"
    requirement: OBS-03
    verification:
      - kind: feature
        ref: "tests/Feature/ObservabilityPageTest.php#test_the_page_serves_seeded_metrics_for_the_default_period (recent_outcomes.0 ordering/human_id/title); #test_a_zero_event_workspace_gets_the_zero_state_props; #test_outcome_rows_carry_type_and_null_provider_for_the_meta_line; stable-tiebreak and 20-cap covered by the unit suite"
        status: pass
    human_judgment: false
  - id: D5
    description: "Home card data seam: publish_health props (rate/failed/retried) from the same Action's 7-day default; zero-event workspace gets rate null — the em-dash path"
    requirement: OBS-03
    verification:
      - kind: feature
        ref: "tests/Feature/DashboardTest.php#test_the_home_page_carries_the_publish_health_figures; #test_a_zero_event_workspace_gets_a_null_publish_health_rate"
        status: pass
    human_judgment: false
  - id: D6
    description: "Frontend compiles and ships: sidebar entry (Activity, after Dashboard), pill classes on the color-mix recipe, token-only colors (no raw hex added), CI-equivalent eslint clean, prettier clean, tsc clean, vite build green"
    requirement: OBS-03
    verification:
      - kind: other
        ref: "node_modules/.bin/eslint . --ignore-pattern .claude/** --ignore-pattern .opencode/** → 0 errors; npm run format:check / types:check / build → pass (run after every task, final run post em-dash fix)"
        status: pass
    human_judgment: false
  - id: D7
    description: "Visual/interaction contract per 01-UI-SPEC: period-switch 60% dim with layout preserved, stacked bar proportions, pill/tile colors, one-line ellipsis truncation, empty/error copy rendering, dark-mode appearance, 4-across grid flow"
    verification: []
    human_judgment: true
    rationale: "No browser tool in this session — markup, exact copy, aria attributes, and token-only colors are asserted in code and compile-clean, but rendered appearance and interaction feel need a human. Manual steps: log in, visit /dashboard/observability — expect heading 'Observability', lead 'How reliably content is reaching its platforms.', tabbar Today/7 days/30 days/90 days with '7 days' selected; with zero events expect four zero tiles, rate '—' (faint ink, not 0%), the 'No publish activity in this period.' block; seed a succeeded + a failed finalize event (publish_events rows with payload {\"outcome\":...}) and reload — expect counts 1/1, rate '50%', a green/red stacked bar, and one outcome row (mono human id, semibold title, 'Post' meta without provider, 'Succeeded'/'Failed' pill, en-GB timestamp); click '30 days' — metrics dim briefly and update to 'Last 30 days' without a skeleton; check dark mode and the dashboard home where 'Publish health' is the first card with 'Success rate · 7d' linking to the page."

# Metrics
duration: 106 min
started: 2026-11-23T10:04:24Z
completed: 2026-11-23T11:50:24Z
status: complete
---

# Phase 1 Plan 5: Publish Success-Rate Dashboard Summary

**Publish success-rate reporting shipped end-to-end: live per-period SQL aggregates with the content-item denominator, the /dashboard/observability page (URL-state period selector, counts, rate figure + stacked bar, capped outcomes list, exact-copy empty/error states), and the Publish health card first on dashboard home — 15 new tests, all frontend and backend gates green (full suite 1636/1639 with only the 3 documented Windows-platform failures).**

## Performance

- **Duration:** 106 min (2026-11-23T10:04:24Z → 2026-11-23T11:50:24Z)
- **Started:** 2026-11-23T10:04:24Z
- **Completed:** 2026-11-23T11:50:24Z
- **Tasks:** 3/3
- **Files modified:** 12 (6 created, 6 modified)

## Accomplishments
- BuildPublishObservabilityAction computes every figure live from publish_events per period (D-19): the newest finalize event per content item decides its bucket (D-16 — a retried-then-succeeded item counts once, never double-bucketed), retries and lease expiries count exactly alongside (OBS-05), the rate is round(succeeded/attempts*100) or null, and the outcomes list is capped at 20 with stable id-DESC tiebreak ordering
- /dashboard/observability renders the approved UI-SPEC contract: .tabbar period selector (Today/7/30/90, default 7, URL-state ?period=, metrics-only partial reload with 60% dim), four count tiles, Display-scale rate figure with the 12px stacked CSS proportion bar (no chart library), the outcomes list on the dash-upcoming pattern with semantic pills, and the documented zero-attempt/outcomes-empty/error states with exact copy
- The dashboard home leads with the Publish health card (7-day rate/failed/retried linking to the page) and the grid flows auto-fit minmax(240px, 1fr); Observability joins the sidebar right after Dashboard
- Server-side input control: the only user input (?period=) is validated in:today,7,30,90 with default 7; arbitrary strings degrade to the default — the aggregate query never sees them (T-01-10)

## Task Commits

Each task was committed atomically:

1. **Task 1 (tracer): Read path slice** - `4b8d154` (feat) — aggregate Action, FormRequest, controller, route, minimal page, 12 tests (7 unit edge cases + 5 feature)
2. **Task 2: Full page per UI-SPEC** - `0c80b13` (feat) — period selector + partial reload, outcomes list + pills + CSS, empty/error states, sidebar entry, partial-edge test
3. **Task 3: Publish health home card** - `689f0cd` (feat) — publish_health prop via the shared Action, first-card markup + labels, grid flow, DashboardTest extension

_Tracer note: Task 1's `<verify>` (pest 12/12 + route:list grep 1) ran green end-to-end before expansion per the tracer feedback gate (interactive, end-of-phase, automated-only verify — no checkpoint synthesized)._

## Files Created/Modified
- `app/Actions/Observability/BuildPublishObservabilityAction.php` — live aggregates: DISTINCT-ON newest finalize per item, signal counts, rate, capped outcomes
- `app/Http/Requests/ObservabilityPeriodRequest.php` — period validation + 7-day default accessor
- `app/Http/Controllers/ObservabilityController.php` — thin controller, Workspace::current() + abort_if, Inertia render
- `routes/dashboard.php` — dashboard.observability.index in the existing group
- `resources/js/pages/observability/index.tsx` — the full page per the UI-SPEC page contract
- `resources/js/components/app-sidebar.tsx` — Observability nav entry (lucide Activity)
- `resources/css/studio.css` — obs-* styles, four pill classes, stat tone classes, auto-fit dash-grid
- `app/Http/Controllers/DashboardController.php` — publish_health prop merged from the shared Action
- `resources/js/pages/dashboard/home.tsx` — Publish health card first in the grid
- `tests/Unit/Actions/Observability/BuildPublishObservabilityActionTest.php` — 7 aggregate-edge tests (75% rate, dedup, zeros, boundaries, tiebreak, cap, isolation)
- `tests/Feature/ObservabilityPageTest.php` — 6 page tests (auth, props, period param, invalid fallback, zero-state, partial edge)
- `tests/Feature/DashboardTest.php` — 2 home-card tests (seeded figures, null-rate path)

## Decisions Made
- Props nest under `metrics` (single top-level key) so the period switch partial-reloads `only: ['metrics']`; the home card reuses the Action's 7-day numbers under `publish_health` (snake_case, matching human_id/created_at/period_label conventions) rather than duplicating queries (D-19 single aggregate home)
- The Action returns a plan-specified typed array, not a DTO class; the shape is documented as a phpstan level-7 array{} annotation
- Outcomes render provider from the event column only (null on finalize rows today) — the meta line shows the type alone per the spec's partial edge instead of speculatively deriving a provider from postsyncer JSON
- The cap note renders only at the cap; the 7-day label comes from the Action's period_label ('Last 7 days') for the section header
- Unknown ?period= values are normalized to null in prepareForValidation so a GET page load falls back to the default instead of entering a validation redirect loop — the in: rule still documents and enforces the contract

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] PSR-4 namespace collision on ObservabilityPeriodRequest**
- **Found during:** Task 1 (first pest run — PHP fatal "Cannot redeclare class")
- **Issue:** The plan's file path `app/Http/Requests/ObservabilityPeriodRequest.php` sits at the top level of `app/Http/Requests/`, but the initial namespace `App\Http\Requests\Observability` maps to a subdirectory — the classmap double-included the file and the app fataled.
- **Fix:** Namespace aligned to `App\Http\Requests` (plan file path kept exactly); controller import already matched.
- **Files modified:** app/Http/Requests/ObservabilityPeriodRequest.php
- **Verification:** full Task 1 suite green; phpstan clean
- **Committed in:** 4b8d154

**2. [Rule 3 - File-list gap] Task 3's grid change lives in studio.css, which the Task 3 file list omitted**
- **Found during:** Task 3
- **Issue:** The plan's own action (and the UI-SPEC) requires `.dash-grid` to switch to `repeat(auto-fit, minmax(240px, 1fr))`, and that rule is defined in studio.css — omitted from the Task 3 files list (the same list previously corrected to add DashboardTest.php).
- **Fix:** studio.css edit folded into the Task 3 commit; commit message documents why.
- **Files modified:** resources/css/studio.css
- **Verification:** build green; only home.tsx uses .dash-grid (grep), so no other surface affected
- **Committed in:** 689f0cd

**3. [Rule 1 - Bug] Home card null-rate tile used the good-tone class**
- **Found during:** Task 3 (self-review against the UI-SPEC)
- **Issue:** The zero-attempt em dash would have rendered in `--good` via the static `stat s-succeeded` class; the spec requires faint ink for the null figure.
- **Fix:** Conditional tone classes (`stat` + `n is-empty` when rate is null) and a `.stat .n.is-empty { color: var(--ink-faint); }` rule.
- **Files modified:** resources/js/pages/dashboard/home.tsx, resources/css/studio.css
- **Verification:** format/types/lint/build re-run green
- **Committed in:** 689f0cd

---

**Total deviations:** 3 auto-fixed (1 Rule 3 blocking PSR-4, 1 Rule 3 file-list gap, 1 Rule 1 UI-SPEC bug).
**Impact on plan:** No scope creep — each fix was required by PHP-PSR-4 mechanics or the plan's own done-criteria. All are recorded in the WINDOWS.md ledger.

## Issues Encountered

- **Test DB container had exited** (`gsd-testing-pg`, Exited 255 ~3h before dispatch): restarted with `docker start gsd-testing-pg`; all runs pass the DB_* env inline, `.env` untouched (CLAUDE.md).
- **Self-inflicted suite contamination:** a scoped pest run was started while the background full suite was mid-flight — both migrated the shared testing schema (RefreshDatabase) and deadlocked, producing 28 false "undefined table" errors in Socialreins tests plus the 3 real platform failures. The full suite was re-run alone and completed clean: **1636 passed / 1639, errors = exactly the 3 documented Windows-platform failures** (symlink x2, proc_open x1 — deferred-items.md). Lesson applied: nothing else touches the DB while a suite runs.
- **Plan's `npm run lint:check` swapped for the CI-equivalent command** (`node_modules/.bin/eslint . --ignore-pattern ".claude/**" --ignore-pattern ".opencode/**"`) per orchestrator notes — plain lint:check reports ~89k errors from untracked local tooling folders that do not exist in CI. Final lint run: 0 errors.
- **Wayfinder types regenerated** (`php artisan wayfinder:generate --with-form`) after the route addition so types:check/build resolve `@/routes/dashboard/observability`; generated `resources/js/{routes,actions}` stay gitignored.

## User Setup Required

None — no external service configuration required.

## Next Phase Readiness

- OBS-03 and OBS-05 are now fully implemented and tested end to end (events → recorder → live aggregates → dashboard page + home card); REQUIREMENTS.md marks them complete at close-out via the shared-ID gate.
- The visual contract (D7 in coverage) is the one human-judgment deliverable: exact manual verification steps are listed there; an `execute:wave:post` ui.safety-gate check validates the frontend files against 01-UI-SPEC.md after this plan returns.
- Phase 01 gates remaining: none known — all five plans executed. Publish-path PRs still wait for Harun's review in chat before merge (CLAUDE.md shipping rules).

## Self-Check: PASSED

- All 6 created files verified present on disk (`[ -f ]`); all 3 task commits verified as ancestors of HEAD (4b8d154, 0c80b13, 689f0cd).
- `evaluation-scope --plan 01-05 --commits-only` resolves commits on this branch (3 task commits, ledger-measured `d24a8e6..689f0cd`).
- Plan-level verifications re-run green: full pest 1636/1639 (3 documented platform-only failures), pint --test passed (all touched files), phpstan --memory-limit=1G 0 errors project-wide, eslint 0 errors, format:check pass, types:check pass, build pass, route:list backstop 1, api/mcp grep 0, migrations diff empty.

---
*Phase: 01-publish-observability-monitoring*
*Completed: 2026-11-23*

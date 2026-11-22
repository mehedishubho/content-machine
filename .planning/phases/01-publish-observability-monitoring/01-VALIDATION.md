---
phase: "1"
slug: "publish-observability-monitoring"
# status lifecycle: draft (seeded by plan-phase) → validated (set by validate-phase §6)
# audit-milestone §5.5 distinguishes NOT-VALIDATED (draft) from PARTIAL (validated + nyquist_compliant: false) (#2117)
status: draft
nyquist_compliant: false
wave_0_complete: false
created: "2026-11-22"
---

# Phase 1 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest ^5.1 + PHPUnit (laravel plugin ^5.0), Postgres test DB required (phpunit.xml forbids SQLite overrides); frontend: tsc + eslint + vite build |
| **Config file** | `phpunit.xml` |
| **Quick run command** | `./vendor/bin/pest <touched test file>` |
| **Full suite command** | `./vendor/bin/pest` (plus `./vendor/bin/pint --test` and `./vendor/bin/phpstan analyse --memory-limit=1G` per CLAUDE.md gates) |
| **Estimated runtime** | ~60-180 seconds (full suite); <15 seconds per touched file |

**Prerequisite:** `vendor/` absent on this machine — plan 01-01 Task 1 runs `"/c/ProgramData/ComposerSetup/bin/composer" install` first (Wave 0). All commands below assume it.

---

## Sampling Rate

- **After every task commit:** Run `./vendor/bin/pest` on the touched suite + `./vendor/bin/pint --test`
- **After every plan wave:** Run `./vendor/bin/pest` (full) + `./vendor/bin/phpstan analyse --memory-limit=1G`
- **Before `/gsd-verify-work`:** Full suite must be green; every publish-path PR additionally waits for Harun's chat review per CLAUDE.md shipping rules
- **Max feedback latency:** ~180 seconds

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 01-01-T1 | 01 | 1 | OBS-01 | T-01-01 / T-01-SC | Event payloads carry ids only — never API keys/tokens (V7) | unit | `./vendor/bin/pest tests/Unit/Support/Observability/PublishEventRecorderTest.php` | ❌ W0 | ⬜ pending |
| 01-01-T2 | 01 | 1 | OBS-01, OBS-05 | T-01-03 | Log line always fires; event rows survive publish rollback | unit | `./vendor/bin/pest tests/Unit/Actions/Postsyncer/PublishPostActionInstrumentationTest.php` | ❌ W0 | ⬜ pending |
| 01-01-T3 | 01 | 1 | OBS-01 | T-01-03 | Recorder DB failure swallowed; D-07 no-transaction-join | unit | `./vendor/bin/pest tests/Unit/Support/Observability/PublishEventRecorderTest.php --filter=D07` | ❌ W0 | ⬜ pending |
| 01-02-T1 | 02 | 2 | OBS-01 | T-01-03 | Video/SocialReins claim events with job-id correlation | unit | `./vendor/bin/pest tests/Unit/Actions/Postsyncer/PublishVideoActionInstrumentationTest.php` | ❌ W0 | ⬜ pending |
| 01-02-T2 | 02 | 2 | OBS-01, OBS-05 | T-01-04 | Full stage vocabulary; payload whitelist (no body/token/key keys) | unit | `./vendor/bin/pest tests/Unit/Actions/Postsyncer/PublishVideoActionInstrumentationTest.php tests/Unit/Actions/Socialreins/PublishToSocialreinsInstrumentationTest.php` | ❌ W0 | ⬜ pending |
| 01-02-T3 | 02 | 2 | OBS-01 | T-01-04 | provider_http events carry status/path/duration only; null recorder short-circuits | unit + full-suite regression | `./vendor/bin/pest` | ❌ W0 | ⬜ pending |
| 01-03-T1 | 03 | 2 | OBS-02 | T-01-05 | Watchdog detects stuck posts/videos; observe-only (no state mutation) | feature | `./vendor/bin/pest tests/Feature/Console/PublishWatchdogCommandTest.php` | ❌ W0 | ⬜ pending |
| 01-03-T2 | 03 | 2 | OBS-02 | T-01-05 | D-15 one-alert-per-content-per-day; no-bot fallback; threshold boundary | feature | `./vendor/bin/pest tests/Feature/Console/PublishWatchdogCommandTest.php` | ❌ W0 | ⬜ pending |
| 01-03-T3 | 03 | 2 | OBS-05 | T-01-06 | Prune deletes only >90-day rows (boundary both sides); dry-run inert | feature | `./vendor/bin/pest tests/Feature/Console/PrunePublishEventsCommandTest.php` | ❌ W0 | ⬜ pending |
| 01-04-T1 | 04 | 2 | OBS-04 | T-01-07 / T-01-SC | SDK locked to 4.x stable; inert without DSN; PII off | unit + boot check | `./vendor/bin/pest tests/Feature/ExampleTest.php` + `composer show sentry/sentry-laravel` | ✅ (ExampleTest) | ⬜ pending |
| 01-04-T2 | 04 | 2 | OBS-04 | T-01-07 | Context carries exactly the seven D-10 id fields; no PII | unit | `./vendor/bin/pest tests/Unit/Support/Observability/PublishErrorContextTest.php` | ❌ W0 | ⬜ pending |
| 01-04-T3 | 04 | 2 | OBS-04 | — | Human provisioning of Sentry project/DSN | manual (checkpoint) | `php artisan sentry:test` (after DSN set) | n/a | ⬜ pending |
| 01-05-T1 | 05 | 3 | OBS-03, OBS-05 | T-01-10 | Workspace-scoped aggregates; period validated in:today,7,30,90 (V5); D-16 denominator | unit + feature | `./vendor/bin/pest tests/Unit/Actions/Observability/BuildPublishObservabilityActionTest.php tests/Feature/ObservabilityPageTest.php` | ❌ W0 | ⬜ pending |
| 01-05-T2 | 05 | 3 | OBS-03 | T-01-09 | Page behind auth+verified+SetCurrentWorkspace; no cross-workspace leak | feature + frontend gates | `./vendor/bin/pest tests/Feature/ObservabilityPageTest.php` + `npm run types:check && npm run lint:check && npm run build` | ❌ W0 | ⬜ pending |
| 01-05-T3 | 05 | 3 | OBS-03 | T-01-09 | Home card props workspace-scoped; em-dash null-rate path | feature + frontend gates | `./vendor/bin/pest tests/Feature/DashboardTest.php` + frontend trio | ⚠️ extend existing | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `composer install` — vendor tree absent on this machine; blocks every Pest/phpstan command (plan 01-01 Task 1, step 0)
- [ ] `tests/Unit/Support/Observability/PublishEventRecorderTest.php` — recorder contract incl. D-07 trio (01-01 T1/T3)
- [ ] `tests/Unit/Actions/Postsyncer/PublishPostActionInstrumentationTest.php` — per-stage events, rollback survival (01-01 T2)
- [ ] `tests/Unit/Actions/Postsyncer/PublishVideoActionInstrumentationTest.php`, `tests/Unit/Actions/Socialreins/PublishToSocialreinsInstrumentationTest.php` — video/SocialReins stages (01-02 T1/T2)
- [ ] `tests/Unit/Support/Postsyncer/PostsyncerClientInstrumentationTest.php`, `tests/Unit/Support/Socialreins/SocialreinsClientInstrumentationTest.php` — provider_http events (01-02 T3)
- [ ] `tests/Feature/Console/PublishWatchdogCommandTest.php` + `tests/Feature/Console/PrunePublishEventsCommandTest.php` — watchdog conditions, D-15 dedup, prune boundaries (01-03)
- [ ] `tests/Unit/Support/Observability/PublishErrorContextTest.php` — Sentry context seam with spy binding (01-04 T2)
- [ ] `tests/Unit/Actions/Observability/BuildPublishObservabilityActionTest.php` + `tests/Feature/ObservabilityPageTest.php` — aggregates + page (01-05 T1/T2)

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Publish-path exceptions delivered end-to-end to Sentry SaaS | OBS-04 | Requires live Sentry project + DSN (human SaaS provisioning; plan 01-04 Task 3 checkpoint) | Set SENTRY_LARAVEL_DSN in Railway env → `php artisan sentry:test` → trigger one probe exception in staging → confirm issue visible with workspace/stage tags after a real publish |
| Watchdog alert arrives on the operator's Telegram | OBS-02 | Requires the real Telegram bot connection (per-workspace config); outbox delivery verified by feature tests | While a stuck fixture exists (or in staging), confirm the operator chat receives the watchdog message with content id/state and that a second run the same day does not re-alert |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 180s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending

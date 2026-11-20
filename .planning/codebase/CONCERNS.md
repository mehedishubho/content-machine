---
last_mapped_commit: effec39acc3dc5c3680e91bdd23476a750c15c41
last_mapped_at: 2026-11-21
---
<!-- refreshed: 2026-11-21 -->
# Codebase Concerns

**Analysis Date:** 2026-11-21

## Tech Debt

**God-class Actions violating the project's own Action pattern:**
- Issue: `PublishPostAction` is 4,184 lines and `PublishVideoAction` is 2,583 lines in a codebase whose CLAUDE.md mandates "one Action, one reason to change" and thin controllers. `HandleTelegramUpdateAction` (1,428 lines), `GenerateTelegramPostAction` (695), `PostPublishPlanner` (624), `McpToolDispatcher` (737), `UpdatePresentationCueAction` (596), `ReschedulePostAction` (586), and `TranscribeVoiceNoteAction` (534) follow the same pattern.
- Files: `app/Actions/Postsyncer/PublishPostAction.php`, `app/Actions/Postsyncer/PublishVideoAction.php`, `app/Actions/Telegram/HandleTelegramUpdateAction.php`, `app/Support/Mcp/McpToolDispatcher.php`, `app/Support/Postsyncer/PostPublishPlanner.php`
- Impact: Any change to the publish path requires reasoning about thousands of lines; merge conflicts are constant (see churn below); test surface per class is huge and slow.
- Fix approach: Extract cohesive responsibilities already visible in the file — lease claiming, progress checkpointing (multiple `DB::transaction` closures at lines ~423, 789, 890, 986, 1054, 1298, 1740), plan comparison/reconciliation, media resolution — into collaborator classes under `app/Support/Postsyncer/`. Do it incrementally, one extract per PR, behind the existing Pest tests in `tests/Unit/Actions/Postsyncer/`.

**Dependency-inversion rule not applied to the publish clients:**
- Issue: CLAUDE.md requires external integrations to be bound via interfaces in `app/Contracts/` and injected. In practice `app/Contracts/` contains only `PexelsProvider.php`; `PostsyncerClient` is instantiated directly (`new PostsyncerClient($config, ...)`) in at least 6 call sites, and SocialReins clients are likewise used concretely.
- Files: `app/Contracts/` (only `PexelsProvider.php`), `app/Actions/Postsyncer/PublishPostAction.php`, `app/Actions/Postsyncer/PublishVideoAction.php`, `app/Actions/Postsyncer/SyncScheduledPostsAction.php`, `app/Actions/Socialreins/PublishToSocialreinsAction.php`, `app/Support/Postsyncer/PostsyncerClient.php`
- Impact: Tests must fake at the HTTP layer rather than the interface; swapping or adding a second publishing provider means touching every Action.
- Fix approach: Introduce `App\Contracts\PostsyncerClient` / `SocialreinsClient` interfaces, bind in `app/Providers/AppServiceProvider.php`, constructor-inject into Actions.

**No application logging on the publish path:**
- Issue: Zero `Log::`/`logger()` calls in `app/Actions/Postsyncer/PublishPostAction.php` and `app/Support/Postsyncer/PostsyncerClient.php`. Only a handful of support classes (`app/Support/Telegram/HttpTelegramClient.php`, `app/Support/GoogleDrive/GoogleDriveClient.php`, `app/Support/AiProviders/HttpAiCompletionClient.php`) log at all.
- Files: `app/Actions/Postsyncer/PublishPostAction.php`, `app/Support/Postsyncer/PostsyncerClient.php`, `app/Actions/Postsyncer/PublishVideoAction.php`
- Impact: Failed or partially-published posts can only be debugged from DB state (`publish_progress`, `publish_error`) and PostSyncer-side records. No breadcrumb trail for rate-limit waits, retries, or lease expiries.
- Fix approach: Add structured logging (job id, post id, run token, operation id) at claim, checkpoint, retry, and finalize points. This is a publish-path change — per CLAUDE.md shipping rules it needs Harun's gate.

**Publish state machine stored as JSON in a single column:**
- Issue: The entire publish checkpoint state (run token, lease id, plan hash, per-group progress, media ids) lives in `Post::$publish_progress` (array cast), with `publish_state` a string column. Schema-in-JSON means no DB-level constraints and constant defensive null-checking (9 `catch` blocks, repeated `?? null` chains in `PublishPostAction`).
- Files: `app/Models/Post.php` (casts around line 126), `app/Actions/Postsyncer/PublishPostAction.php`
- Impact: Corrupt/partial JSON silently changes publish behavior; no way to query in-flight publishes efficiently; every progress write is a full-array read-modify-write inside a transaction.
- Fix approach: Longer term, normalize per-group progress into a `post_publish_groups` table (a `SocialreinsGroup` table already exists as a precedent — `database/migrations/2026_11_07_000001_create_socialreins_groups_table.php`).

## Known Bugs

**No open bug reports found in code markers.** A repo-wide grep for TODO/FIXME/HACK/XXX/deprecated across `app/`, `routes/`, `resources/js/` returns nothing — debt is undocumented, not markerless-by-accident. However, git history shows recent live incidents now patched:

**Platform coverage mismatch between planners:**
- Symptoms: Recent fixes include "a pair no driver can publish does not pull its platform onto PostSyncer" (#286), "send the valid platforms when one is invalid" (#280), "cap Bluesky at four images" (#279).
- Files: `app/Support/Postsyncer/PostPublishPlanner.php`, `app/Support/Socialreins/SocialreinsConfig.php`, `app/Actions/Postsyncer/PublishPostAction.php`
- Trigger: Posts whose platform set is only partially coverable by one provider; the split logic between PostSyncer and SocialReins has repeatedly produced wrong platform routing.
- Workaround: Fixed case-by-case in #278–#286; the class of bug is structurally unresolved.

**Migrating-schedule regressions (revert cycle):**
- Symptoms: PR #284 ("Remove PostSyncer: publish only through SocialReins") was reverted by #285 within days, then re-landed partially as #282/#283/#286.
- Files: `app/Actions/Socialreins/MoveOffPostsyncerAction.php`, `app/Actions/Socialreins/TakeDownPostsyncerGroupsAction.php`, `app/Actions/Postsyncer/SyncScheduledPostsAction.php`
- Trigger: Any change to the PostSyncer→SocialReins transition logic.
- Workaround: Human review gate (see Fragile Areas).

## Security Considerations

**Workspace multi-tenancy relies on middleware + manual scoping:**
- Risk: A query missing a `workspace_id` scope leaks content across workspaces; publish Actions re-fetch workspace config from the post relation, which is good, but API controllers (`app/Http/Controllers/Api/V1/PostsApiController.php`, 522 lines) rely on `app/Support/CurrentApiToken.php` / `app/Support/CurrentWorkspace.php` singletons.
- Files: `app/Support/CurrentWorkspace.php`, `app/Support/CurrentApiToken.php`, `app/Http/Controllers/Api/V1/`, `app/Concerns/BelongsToWorkspace.php`
- Current mitigation: `SetCurrentWorkspace` middleware on `routes/dashboard.php`; trait-based scoping; owner/admin checks added for account-mapping repair (#287).
- Recommendations: Add a static-analysis or test-level guard that every Eloquent query on workspace-scoped models includes a workspace constraint; Pest drift plugin is present, a similar scope-lint would fit the workflow.

**Secrets hygiene (known past incident):**
- Risk: CLAUDE.md records that "a local test run that overwrites `.env` has taken one [Railway token] down before."
- Files: `.secrets/` (gitignored, per `docs/adr/0002-operational-secrets-outside-app-env.md`)
- Current mitigation: Operational secrets split out of app `.env` into `.secrets/<service>.env`.
- Recommendations: Keep the split; never add infra tokens to `.env` or to any `config/*.php` default.

**Signed media URLs in publish plans:**
- Risk: Media links to external storage expire; the code explicitly re-plans "to refresh expiring signed media URLs" before publish (`PublishPostAction::handle` docblock). Stale URLs would publish broken media.
- Files: `app/Actions/Postsyncer/PublishPostAction.php`, `app/Jobs/PublishPostJob.php`
- Current mitigation: Freeze-then-replan pattern plus a persisted content hash that "makes any content/config drift fail closed."
- Recommendations: None required beyond keeping the fail-closed check intact when refactoring the god class.

## Performance Bottlenecks

**Long-blocking HTTP polling in the publish client:**
- Problem: `app/Support/Postsyncer/PostsyncerClient.php` line 311 uses `Http::timeout(300)` for a GET — a single request can hold a queue worker for 5 minutes.
- Files: `app/Support/Postsyncer/PostsyncerClient.php`
- Cause: Synchronous wait for PostSyncer processing instead of callback/webhook (an ADR exists for webhook-over-polling for Telegram: `docs/adr/0001-webhook-not-polling.md` — the same principle was not applied to PostSyncer status).
- Improvement path: Webhook-based completion or shorter polls with job re-release (`backoff` logic exists at ~line 556).

**Rate-limit starvation between sync and publish:**
- Problem: Commit #278 ("stop the status sync starving publishes of the rate limit") shows the scheduled status-sync job competes with publishes for the PostSyncer rate-limit budget.
- Files: `app/Actions/Postsyncer/SyncScheduledPostsAction.php`, `app/Support/Postsyncer/PostsyncerClient.php`, `app/Actions/Postsyncer/EnqueuePostPublishAction.php`
- Cause: Shared rate-limit pool without priority partitioning.
- Improvement path: Separate rate-limit buckets per job class, or deprioritize sync when publishes are queued.

**Serial per-group publish loops:**
- Problem: `PublishPostAction` publishes groups in a `foreach` (`$groups as $index => $group`, line 125) with per-platform rate-limit waits; a 5-group post serializes 5+ long HTTP round trips in one job.
- Files: `app/Actions/Postsyncer/PublishPostAction.php`
- Improvement path: Fan out per-group jobs with the existing lease/checkpoint machinery, but only after the checkpoint model is stable — concurrency here has already caused duplication bugs (see the "retry cannot duplicate" comments at lines 175, 247, 399).

## Fragile Areas

**PostSyncer publish path (highest-risk code in the repo):**
- Files: `app/Actions/Postsyncer/PublishPostAction.php`, `app/Actions/Postsyncer/PublishVideoAction.php`, `app/Support/Postsyncer/PostsyncerClient.php`, `app/Support/Postsyncer/PostPublishPlanner.php`, `app/Jobs/PublishPostJob.php`, `app/Jobs/PublishVideoJob.php`
- Why fragile: 4k+ line state machine with lease tokens, run tokens, operation ids, plan hashes, checkpoint rebasing, and duplicate-delivery guards. 11 of the last 15 commits touch this area, including one full revert (#284→#285).
- Safe modification: Any change requires the Harun review gate per CLAUDE.md ("publish-path PR #137 should have waited for his review"). Run the full `tests/Unit/Actions/Postsyncer/` + `tests/Feature/Socialreins/` suites locally; verify duplicate-delivery guard (`publish_state === 'succeeded'` + `hasExistingPublicGroup`) still holds; verify fail-closed plan-hash check.
- Test coverage: Present and substantial (`tests/Unit/Actions/Postsyncer/`, `tests/Feature/Posts/PublishPostControllerTest.php`) — the risk is that coverage lives against a monolith, so refactors must be behavior-preserving extractions.

**PostSyncer ↔ SocialReins transition logic:**
- Files: `app/Actions/Socialreins/MoveOffPostsyncerAction.php`, `app/Actions/Socialreins/TakeDownPostsyncerGroupsAction.php`, `app/Actions/Socialreins/ContinueSplitOnSocialreinsAction.php`, `app/Actions/Postsyncer/SyncScheduledPostsAction.php`
- Why fragile: The codebase is mid-migration from PostSyncer to SocialReins; platform-routing rules between the two have produced 6+ consecutive fix PRs (#278–#286).
- Safe modification: Change one routing rule per PR with an explicit test for the pair/platform matrix; do not bundle with publish-path changes.
- Test coverage: `tests/Feature/Socialreins/` exists; the matrix (pair × platform × provider-capability) is the gap.

**Telegram update handling:**
- Files: `app/Actions/Telegram/HandleTelegramUpdateAction.php` (1,428 lines), `app/Jobs/ProcessTelegramUpdateJob.php`, `app/Support/Telegram/`
- Why fragile: Webhook-driven (ADR 0001), large dispatch surface, idempotency depends on `TelegramUpdate` dedup.
- Safe modification: Keep update-processing idempotent; add regression tests for duplicate webhook deliveries before touching dispatch logic.

## Scaling Limits

**Queue throughput vs single long-poll design:**
- Current capacity: One publish job can hold a worker for minutes (300s HTTP timeout + rate-limit waits up to `PostsyncerClient::PUBLISH_RATE_LIMIT_WAIT_SECONDS`).
- Limit: Publish volume beyond a few concurrent posts requires many workers; Railway deployment scales workers manually.
- Scaling path: Webhook completion + per-group fan-out (see Performance section).

**`publish_progress` JSON row contention:**
- Current capacity: One row, one writer at a time via lease tokens.
- Limit: Concurrent checkpoint writes serialize on the row; large progress payloads grow with group/media counts.
- Scaling path: Normalized per-group table (see Tech Debt).

## Dependencies at Risk

**`laravel/chisel` (^0.1.0) and `laravel/wayfinder` (^0.1.14) and `laravel/pao`:**
- Risk: Pre-1.0 Laravel ecosystem packages pinned below 1.0; minor/patch releases may break the Inertia+Wayfinder page/route generation the frontend depends on.
- Impact: Frontend route/type generation (`resources/js/pages/`, Wayfinder controllers) breaks on composer update.
- Migration plan: Pin exact versions in `composer.json` if not already; review changelogs before updating.

**Framework major-version coupling:**
- Risk: Laravel 13 + Inertia 3 + React 19 + Tailwind 4 — all current majors; a framework major bump is a cross-cutting project.
- Migration plan: Treat as a dedicated milestone, not a drive-by dependency update.

## Missing Critical Features

**No observability on the publish pipeline:**
- Problem: No error tracking service, no structured logging on the publish path, no metrics for retry/lease-expiry counts.
- Blocks: Root-causing "wrong content went live" incidents after the fact; alerting on stuck publishes (posts left in `queued`/`running` — see `Post::isPublishing()`-style checks in `app/Models/Post.php` around line 175).

**Publish-path dead-letter/monitoring job:**
- Problem: `SyncScheduledPostsAction` syncs status, but there is no watchdog that alerts when a lease expires without progress.
- Blocks: Silent stuck publishes until a human notices a missing post.

## Test Coverage Gaps

**PostSyncer ↔ SocialReins platform matrix:**
- What's not tested: The full combinatorial space of (platform set × provider capability × pair-routing rule) that produced bugs #279–#286.
- Files: `app/Support/Postsyncer/PostPublishPlanner.php`, `app/Support/Socialreins/SocialreinsConfig.php`
- Risk: Each new platform/provider rule regresses an earlier case.
- Priority: High

**MCP tool dispatcher:**
- What's not tested: `app/Support/Mcp/McpToolDispatcher.php` (737 lines) has `tests/Feature/Mcp/` but coverage of individual tool error paths is unknown given the dispatcher's size.
- Files: `app/Support/Mcp/McpToolDispatcher.php`, `tests/Feature/Mcp/`
- Risk: External MCP clients hitting untested tool branches mutate workspace data unexpectedly.
- Priority: Medium

**Video publish path parity:**
- What's not tested: `PublishVideoAction` (2,583 lines) mirrors `PublishPostAction` but is a separate implementation — parity guarantees (same lease/checkpoint/duplicate guards) are maintained by hand.
- Files: `app/Actions/Postsyncer/PublishVideoAction.php`, `app/Actions/Postsyncer/PublishPostAction.php`
- Risk: A fix landed in the post path silently missing from the video path (and vice versa).
- Priority: High — a shared publish-state trait/concern would eliminate the class of drift.

---

*Concerns audit: 2026-11-21*

# Phase 1: Publish Observability & Monitoring - Context

**Gathered:** 2026-11-22
**Status:** Ready for planning

<domain>
## Phase Boundary

Every publish outcome on PostSyncer/SocialReins becomes visible, measurable, and alertable:
structured publish-event capture (OBS-01), a stuck-publish watchdog with operator alerting
(OBS-02), dashboard success-rate reporting with failure/retry/lease-expiry counts (OBS-03,
OBS-05), and publish-path exceptions reaching an error-tracking service with workspace and
content context (OBS-04). Instrumentation targets the existing publish Actions, publish jobs,
and provider clients — no publish behavior changes, only observation of it.

</domain>

<decisions>
## Implementation Decisions

### Event capture & storage
- **D-01:** Publish events are captured to BOTH a normalized `publish_events` DB table and a
  dedicated structured log channel. The table feeds dashboard metrics (OBS-03/05 via SQL);
  the log channel is the forensic trail (OBS-01) readable in Railway logs.
- **D-02:** The log channel is a dedicated `publish` channel emitting one JSON object per line
  (job id, content id, operation/run token, stage, group, duration) to stderr — not the default
  channel with context.
- **D-03:** `publish_events` rows are pruned after 90 days by a scheduled command — **Reversibility:** costly — the retention window also caps the success-rate report periods (Today/7/30/90); changing it later redefines what historical reporting is possible.
- **D-04:** Instrumentation scope in v1: the four lifecycle stages (claim, checkpoint, retry,
  finalize) in the publish Actions PLUS provider HTTP outcomes in PostsyncerClient/
  SocialreinsClient (429 rate-limit waits, timeouts, 5xx).
- **D-05:** One polymorphic `publish_events` table for posts and videos: publishable morph,
  workspace_id (via `BelongsToWorkspace`), job id, stage, operation/run token, payload JSON.
  No separate post/video event tables.
- **D-06:** Events are written through a single `PublishEventRecorder` support class called
  explicitly from the publish Actions and provider clients — one method call writes the DB row
  and emits the log line together; one seam to fake in tests. No model observers.
- **D-07:** DB event rows are written best-effort AFTER the checkpoint transaction commits;
  the log channel always fires regardless of transaction outcome — **Reversibility:** reversible — a write-ordering convention inside the recorder, no schema impact.
- **D-08:** Publish events are visible in the dashboard only. No new `/api/v1` or MCP surface
  in this phase; `publish_progress` stays private per project constraints.

### Error-tracking service
- **D-09:** Sentry SaaS (free tier) via `sentry/sentry-laravel` receives publish-path
  exceptions. The DSN is an app-runtime credential in the app env — it is NOT an infra token,
  so ADR-0002's `.secrets/` split is not violated — **Reversibility:** reversible — swapping trackers means replacing the SDK integration at one seam.
- **D-10:** Reported exceptions carry the same identifiers as the publish log channel
  (workspace id, content id, job id, operation/run token) so a Sentry issue maps 1:1 to
  grep-able log lines.
- **D-11:** Only the publish path reports to Sentry in this phase (publish Actions, provider
  clients, publish jobs, watchdog). The rest of the app keeps default handling.

### Watchdog
- **D-12:** Stuck-publish alerts reach the operator as a Telegram message via the existing
  bot/notification infrastructure (`app/Actions/Notifications/`, outbound message jobs).
- **D-13:** The watchdog is a scheduled command on the existing scheduler (same pattern as the
  dispatch/recovery commands in `routes/console.php`).
- **D-14:** Three conditions alert: publish stuck in queued/running past its lease window,
  publish job that exhausted all retries (permanent failure), and repeated lease expiries on
  the same content (retry loop — feeds OBS-05 counts).
- **D-15:** Alert dedup: at most one alert per content item per day while the condition holds.

### Success-rate dashboard
- **D-16:** The success-rate denominator is the content item (one publish attempt outcome per
  post/video), matching the PROJECT.md success metric — not per publish group.
- **D-17:** Reporting lives on a dedicated dashboard page (success-rate chart, failure and
  retry/lease-expiry counts, recent publish-outcomes list) plus a compact success-rate stat on
  the existing dashboard home.
- **D-18:** Period selector: Today / 7 days / 30 days / 90 days (bounded by D-03 retention);
  default 7 days.
- **D-19:** Counts are computed live from `publish_events` via SQL aggregate per period — no
  pre-aggregated summary table (single-operator volume keeps this fast).

### Claude's Discretion
- Exact `publish_events` column set beyond the fields named in D-05 (index choices, payload
  JSON shape).
- The precise `publish` log channel configuration in `config/logging.php`.
- Sentry SDK wiring details (breadcrumbs, release tagging, sample rates) within D-09–D-11.
- Watchdog command naming/cadence (within scheduler capabilities) and the dedup storage
  mechanism (cache vs. column) per D-15.
- Dashboard page layout/component breakdown within D-16–D-18.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Process and architecture rules
- `CLAUDE.md` — engineering rules (Action pattern, DTOs, Contracts boundary), testing gates,
  and the Harun review gate that applies to every publish-path PR in this phase
- `.planning/codebase/ARCHITECTURE.md` — layer map, publish-path data flow, where the
  recorder/watchdog/dashboard code connects
- `.planning/codebase/CONCERNS.md` — the audit that produced OBS-01..05 (zero `Log::` calls,
  no watchdog, no error tracker, fragile publish path)
- `.planning/codebase/INTEGRATIONS.md` — provider clients to instrument, current logging/
  monitoring state (stderr, no Sentry)
- `docs/adr/0002-operational-secrets-outside-app-env.md` — secrets split; D-09 records why the
  Sentry DSN stays in app env
- `.planning/REQUIREMENTS.md` §Observability — OBS-01..05 definitions this phase must satisfy
- `.planning/ROADMAP.md` §"Phase 1: Publish Observability & Monitoring" — goal and success
  criteria

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `app/Actions/Notifications/` + `app/Jobs/SendTelegramOutboundMessageJob.php` — existing
  Telegram notification delivery the watchdog alert reuses
- `app/Concerns/BelongsToWorkspace.php` — workspace scoping for the new `publish_events` model
- `app/Concerns/RecordsHistory.php` / `app/Models/StatusTransition.php` — morph-and-record
  precedent for the publishable event table
- `routes/console.php` scheduler — existing 1–5 min command cadence the watchdog joins
- Inertia dashboard (`resources/js/pages/`) — home page for the compact success-rate stat

### Established Patterns
- Contracts bound in `app/Providers/AppServiceProvider.php` — any recorder/notifier seam the
  tests need to fake should follow this binding pattern
- Scheduled commands in `app/Console/Commands/` — watchdog follows this shape
- Additive-only migrations in `database/migrations/`

### Integration Points
- `app/Actions/Postsyncer/PublishPostAction.php`, `PublishVideoAction.php` — lifecycle event
  instrumentation (claim/checkpoint/retry/finalize)
- `app/Support/Postsyncer/PostsyncerClient.php`, `app/Support/Socialreins/SocialreinsClient.php`
  — provider HTTP outcome instrumentation
- `app/Jobs/PublishPostJob.php`, `PublishVideoJob.php`, `PublishSocialreinsJob.php` — job id
  context for events and Sentry
- Dashboard home + new observability page under `routes/dashboard.php`

**Caution:** every file above is on the publish path — CLAUDE.md's Harun-in-chat review gate
applies before merge, and behavior must not change, only observation added.

</code_context>

<specifics>
## Specific Ideas

- Correlation is the through-line the user chose: log lines, `publish_events` rows, and Sentry
  reports all share job id + content id + operation/run token so any one maps to the others.
- Rate-limit waits (429 backoff) were called out as today's invisible failure mode — they must
  be first-class provider HTTP events, not buried in payload.

</specifics>

<deferred>
## Deferred Ideas

None — discussion stayed within phase scope

</deferred>

---

*Phase: 1-Publish Observability & Monitoring*
*Context gathered: 2026-11-22*

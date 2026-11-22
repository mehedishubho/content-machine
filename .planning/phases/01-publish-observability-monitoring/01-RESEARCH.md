# Phase 1: Publish Observability & Monitoring - Research

**Researched:** 2026-11-22
**Domain:** Laravel publish-path instrumentation — structured event capture (DB + JSON log channel), stuck-publish watchdog with Telegram alerting, Sentry error tracking, Inertia success-rate dashboard
**Confidence:** HIGH (codebase seams verified in-repo; external stack verified from official docs + registries)

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

#### Event capture & storage
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

#### Error-tracking service
- **D-09:** Sentry SaaS (free tier) via `sentry/sentry-laravel` receives publish-path
  exceptions. The DSN is an app-runtime credential in the app env — it is NOT an infra token,
  so ADR-0002's `.secrets/` split is not violated — **Reversibility:** reversible — swapping trackers means replacing the SDK integration at one seam.
- **D-10:** Reported exceptions carry the same identifiers as the publish log channel
  (workspace id, content id, job id, operation/run token) so a Sentry issue maps 1:1 to
  grep-able log lines.
- **D-11:** Only the publish path reports to Sentry in this phase (publish Actions, provider
  clients, publish jobs, watchdog). The rest of the app keeps default handling.

#### Watchdog
- **D-12:** Stuck-publish alerts reach the operator as a Telegram message via the existing
  bot/notification infrastructure (`app/Actions/Notifications/`, outbound message jobs).
- **D-13:** The watchdog is a scheduled command on the existing scheduler (same pattern as the
  dispatch/recovery commands in `routes/console.php`).
- **D-14:** Three conditions alert: publish stuck in queued/running past its lease window,
  publish job that exhausted all retries (permanent failure), and repeated lease expiries on
  the same content (retry loop — feeds OBS-05 counts).
- **D-15:** Alert dedup: at most one alert per content item per day while the condition holds.

#### Success-rate dashboard
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
- Dashboard page layout/component breakdown within D–16–D-18.

### Deferred Ideas (OUT OF SCOPE)
None — discussion stayed within phase scope.
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| OBS-01 | Failed/partially-published item diagnosable from structured logs alone: claim, checkpoint, retry, finalize points with job id, content id, operation/run token, stage | The four stages map to verified call sites in `PublishPostAction` (claim line 80, checkpoints lines 157/181/205/244, retry catch-block line 280, finalize line 269); dedicated `publish` Monolog channel with `JsonFormatter` writes one JSON line per event (Laravel docs verified); correlation fields exist in code (`run_token`, `operation_id`, `publish_lease_id`) |
| OBS-02 | Publish stuck in queued/running past lease window detected automatically, operator alerted | `Post::PUBLISH_STATES` + `publish_claimed_at`/`publish_lease_id` columns verified; `PublishPostJob::LEASE_SECONDS = 1020` verified; scheduler pattern (`Schedule::command()->everyMinute()->withoutOverlapping(2)`) verified in `routes/console.php`; Telegram alert path exists (`QueueTelegramNotificationAction` → `SendTelegramOutboundMessageJob`) |
| OBS-03 | Dashboard shows publish success rate per period with failures alongside | `publish_events` SQL aggregate per D-19; Post/Video `publish_state` enums verified; dashboard read pattern verified (`BuildDashboardSummaryAction` → `Inertia::render`); period selector Today/7/30/90 bounded by 90-day retention (D-03/D-18) |
| OBS-04 | Publish-path exceptions reach error-tracking service with workspace and content context | `sentry/sentry-laravel` 4.31.0 verified on Packagist supporting Laravel 13/PHP 8.3; `Integration::handles($exceptions)` in `bootstrap/app.php::withExceptions()` is the documented Laravel 11+ install path; `PublishPostJob::failed()` already calls `report($exception)` — Sentry hooks the exception handler; context via `configureScope()->setContext()` (official docs) |
| OBS-05 | Retry and lease-expiry counts tracked and reported alongside success rate | Retry events emitted at the action's failure/retry seam; lease-expiry detection at `claimPost()` (lease-expired branch, line ~2522 "The previous publish worker lease expired"); counts aggregate from `publish_events` by stage alongside success metrics |
</phase_requirements>

## Summary

This phase instruments the highest-risk code in the repo — the PostSyncer/SocialReins publish
path (a 4,184-line `PublishPostAction`, a 2,583-line `PublishVideoAction`, three queue jobs,
two HTTP clients) — without changing its behavior. The codebase already contains every
correlation identifier the observability layer needs: `run_token`, `operation_id`, and
`publish_lease_id` flow through the actions and jobs, `publish_claimed_at` timestamps the
lease, and `publish_state` is a 5-value enum (`idle|queued|running|succeeded|failed`) on both
`Post` and `Video`. What's missing is purely the observation layer: the codebase has **zero**
`Log::` calls on the publish path and no error tracker. The research confirms the four-stage
instrumentation map (claim/checkpoint/retry/finalize) lands on verified call sites, and that
`PublishPostJob::failed()` already invokes `report($exception)` — meaning Sentry integration
through the standard exception-handler hook captures job exhaustion without new plumbing in
the jobs.

The external stack is small and verified: `sentry/sentry-laravel` **4.31.0** (stable,
2026-11-19) explicitly supports Laravel 13 and PHP 8.x on Packagist and installs via
`Integration::handles($exceptions)` inside the project's existing `withExceptions()` closure
in `bootstrap/app.php` — no legacy `App::register` needed. The dedicated `publish` log channel
needs no new package: Laravel's `monolog` driver accepts `StreamHandler` (`php://stderr`) with
`Monolog\Formatter\JsonFormatter` via the `formatter` config key, matching the existing
`stderr` channel pattern in `config/logging.php`. The watchdog joins the existing
`routes/console.php` scheduler (commands run every 1–5 minutes with `withoutOverlapping`) and
alerts through `QueueTelegramNotificationAction`, whose `logical_key` outbox dedup can express
D-15's one-alert-per-content-per-day rule in the key itself.

The two findings planners must respect: (1) **`BelongsToWorkspace`'s auto-fill does not work
from queue context** — `Workspace::current()` reads a request-scoped singleton set by HTTP
middleware, so the `PublishEventRecorder` must set `workspace_id` explicitly from the
publishable — and (2) the D-07 write-ordering convention (DB row best-effort after commit,
log line always) must live inside the recorder as a hard rule, because nine existing
`DB::transaction` checkpoints in the action are exactly where an instrumentation call could
accidentally join the transaction and break publish behavior. Charting on the dashboard is the
one genuinely open UI choice: the project has no chart library today; recharts 3.11
(peer-depends on React 19) is the ecosystem standard but was flagged SUS by the legitimacy
seam on a false-positive "too-new" heuristic (5-day-old release of an 11-year-old package with
71.7M weekly downloads), so the planner should either gate it behind a human-verify checkpoint
or fall back to hand-styled bars with the existing shadcn/radix kit.

**Primary recommendation:** Build a single constructor-injected `PublishEventRecorder`
(operand: a small `PublishEvent` DTO) that owns the D-07 ordering rule, explicitly sets
`workspace_id`, writes to a new `publish` Monolog channel (`StreamHandler` → stderr →
`JsonFormatter`) and the polymorphic `publish_events` table; wire Sentry via
`Integration::handles()` + a publish-scoped context helper; run the watchdog as a
`postsyncer:watchdog` scheduled command with `Cache::add` day-keyed dedup alerting through the
existing Telegram outbox; render success-rate reporting from live SQL aggregates on a new
Inertia page.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Publish event capture (claim/checkpoint/retry/finalize) | API/Backend (queue workers) | — | Events originate inside publish Actions/jobs running on the `postsyncer` queue worker; no HTTP involvement |
| Provider HTTP outcome capture (429/timeouts/5xx) | API/Backend | — | Lives inside `PostsyncerClient`/`SocialreinsClient` request paths on the worker |
| Structured `publish` log channel | API/Backend (Monolog) | Railway log drain | Monolog `StreamHandler` to stderr; Railway captures it (existing deployment topology) |
| `publish_events` table + retention pruning | Database/Storage | API/Backend (scheduled command) | Polymorphic rows written by recorder; 90-day prune is a scheduled command per D-03 |
| Sentry exception reporting | API/Backend | Sentry SaaS | SDK hooks the Laravel exception handler; publish-path code reports via existing `report()` + explicit capture at instrumentation seams |
| Stuck-publish watchdog + Telegram alert | API/Backend (scheduler) | Telegram SaaS | Scheduled command querying `Post`/`Video` + `publish_events`; alerts via existing outbox jobs |
| Success-rate metrics (SQL aggregates) | API/Backend | Database/Storage | Live SQL per D-19; served through an Inertia controller/Action |
| Observability dashboard page + home stat | Browser/Client (React 19) | Frontend Server (Inertia SSR of props) | Inertia page receives computed props; chart rendering client-side |
| Period selector / outcome list interactions | Browser/Client | Frontend Server | Inertia partial reloads or link-based period switching |

## Standard Stack

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| sentry/sentry-laravel | ^4.31.0 (exact pin recommended; 4.31.0 released 2026-11-19) | Error tracking for publish-path exceptions (OBS-04, D-09) | Official Sentry SDK; verified on Packagist to require `illuminate/support ^6.0\|…\|^13.0` and `php ^7.2\|^(D-09)` (PHP 8.x OK); 160.8M total installs; installs into the project's existing `withExceptions()` closure [VERIFIED: repo.packagist.org p2 metadata + docs.sentry.io] |
| Monolog JsonFormatter + StreamHandler | bundled with laravel/framework ^13.17 | The `publish` channel: one JSON object per line to stderr (D-02) | Ships inside Laravel — zero new deps; Laravel's `monolog` driver takes `handler`, `handler_with`, `formatter` keys natively [VERIFIED: config/logging.php stderr channel + CITED: laravel.com/docs/13.x/logging] |
| Laravel scheduler (Schedule facade) | built-in | Watchdog + prune commands (D-03, D-13) | Existing `routes/console.php` already runs 8 commands every 1–5 min with `withoutOverlapping`; `schedule:work` supervisor exists (`composer run scheduler`) [VERIFIED: routes/console.php:18-48, composer.json:81-84] |
| Cache (database store) + `Cache::add` | built-in (`CACHE_STORE=database`) | Watchdog alert dedup, one alert per content per day (D-15) | `Cache::add` is the atomic guard; database store survives restarts and is the project default [VERIFIED: config default per INTEGRATIONS.md; CITED: laravel.com/docs/13.x/scheduling — scheduler locks use the same cache] |
| recharts | ^3.11.0 | Success-rate chart on the observability page (D-17) — pending human-verify checkpoint | Only mainstream React charting lib with React 19 in its peer range: `react: '^16.8.0 \|\| ^17.0.0 \|\| ^18.0.0 \|\| ^19.0.0'`; 71.7M weekly downloads [VERIFIED: npm registry] — but see Package Legitimacy Audit: seam-flagged SUS |

### Supporting
| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| `QueueTelegramNotificationAction` (existing) | — | Watchdog alert delivery via Telegram outbox (D-12) | Always for alerts; handle `TelegramNotificationsUnavailable` (no bot connected) with a log-only fallback |
| `BelongsToWorkspace` trait (existing) | — | `PublishEvent` model workspace scoping on dashboard reads | Dashboard queries; **not** for auto-filling `workspace_id` from workers (see Pitfall 1) |
| shadcn-style ui kit (`resources/js/components/ui/`) | existing | Stat tiles, cards, tabs for the observability page + home stat | All non-chart UI; `card.tsx`, `badge.tsx`, `tabs.tsx` already present |
| Pest + `Log::fake()` / recorder fake | pest ^5.1 | Tests fake the recorder seam (D-06 "one seam to fake in tests") | Every publish-path test that now exercises instrumentation |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| recharts | Hand-styled CSS/SVG bars with existing Tailwind + ui kit | Zero npm dep, no SUS flag; fine for a 4-bucket success-rate bar + counts; loses axes/tooltips if the page grows. Given the SUS flag, this is a legitimate fallback the planner may prefer |
| Sentry | Bugsnag / Flare / Laravel Nightwatch | D-09 locks Sentry — not researched further |
| Monolog JsonFormatter channel | Laravel's `Context` facade + default channel | D-02 explicitly rejects "default channel with context" — dedicated channel is locked |
| Live SQL aggregates (D-19) | Pre-aggregated summary table | D-19 explicitly rejects for single-operator volume — locked |
| `QueueTelegramNotificationAction` | Raw `Notification` / mail | D-12 locks the existing Telegram outbox — locked |

**Installation:**
```bash
composer require sentry/sentry-laravel:^4.31.0
php artisan vendor:publish --tag=sentry-config   # or: php artisan sentry:publish --dsn=... (creates config/sentry.php + .env entry)
npm install recharts   # ONLY after the human-verify checkpoint passes, or skip for CSS-bar fallback
```

**Version verification:** verified this session — `sentry/sentry-laravel` 4.31.0 (Packagist p2,
published 2026-11-19, `illuminate/support` includes `^13.0`); `recharts` 3.11.0 (npm, modified
2026-11-17, peerDeps include react ^19.0.0). Do **not** let composer resolve
`5.0.0-beta1` (published 2026-11-18): the project's `minimum-stability: stable` already
excludes it, but pin `^4.31.0` explicitly for clarity.

## Package Legitimacy Audit

> The legitimacy seam supports npm/pypi/crates only — no composer ecosystem. `sentry/sentry-laravel`
> was therefore verified manually against the authoritative registry (repo.packagist.org p2 metadata,
> fetched this session). The seam's pypi run returned SLOP solely because the package does not exist
> on PyPI — an ecosystem-mismatch artifact, not a package verdict.

| Package | Registry | Age | Downloads | Source Repo | Verdict | Disposition |
|---------|----------|-----|-----------|-------------|---------|-------------|
| sentry/sentry-laravel | Packagist (composer) | ~14 yrs (Sentry org) | 160.8M total installs | github.com/getsentry/sentry-laravel | OK | Approved (manual registry verification: official repo, MIT, active — 4.31.0 on 2026-11-19) |
| recharts | npm | 11 yrs (created 2015-08-07, 300 versions) | 71.7M/week | github.com/recharts/recharts | SUS | Flagged — seam reason "too-new" measures the *latest release* (3.11.0, 2026-11-17), not package age; all health signals positive (ancient package, official repo, no postinstall, not deprecated). Planner: add `checkpoint:human-verify` before install **or** choose the CSS-bars fallback |

**Packages removed due to [SLOP] verdict:** none
**Packages flagged as suspicious [SUS]:** recharts — planner inserts `checkpoint:human-verify` before install, or falls back to hand-styled bars (no dependency)

*No package in this phase was discovered via WebSearch alone; both were verified against their
authoritative registries this session.*

## Architecture Patterns

### System Architecture Diagram

```text
                        ┌──────────────────────────────────────────────┐
                        │            Railway `postsyncer` worker       │
                        │                                              │
 PublishPostJob ────────► PublishPostAction / PublishVideoAction      │
 PublishVideoJob        │   claim ─ checkpoint ─ retry ─ finalize      │
 PublishSocialreinsJob  │        │            (verified call sites)    │
                        │        ▼                                     │
                        │  ┌─────────────────────┐   new PostsyncerClient(...)
                        │  │ PublishEventRecorder │◄─ PostsyncerClient ────► PostSyncer API
                        │  │  (D-06 single seam)  │◄─ SocialreinsClient ───► SocialReins API
                        │  └──────┬────────┬──────┘   (429 waits, timeouts, 5xx)
                        │         │        │
                        │   1. JSON line    2. DB row (best-effort,
                        │      ALWAYS          AFTER commit — D-07)
                        │      (D-02/D-07)        │
                        └─────────┼────────┼───────────────────────────┘
                                  ▼        ▼
                    stderr ──► Railway logs  PostgreSQL.publish_events
                    (publish                       (polymorphic morph,
                     channel)                       workspace_id, stage,
                                                   job id, op/run token,
                                                   payload JSONB)
                                  │
              publish-path        │            ┌───────────────────────┐
              exception ──► report($e) ───────►│ Laravel exception     │
              (actions, clients,               │ handler + Sentry      │──► Sentry SaaS
               jobs, watchdog)  [exists today  │ Integration::handles()│   (workspace/content/job/
                                in failed()]   │ + publish context     │    operation ids attached)
                                               └───────────────────────┘
        ┌───────────────────────────────────────────┐
        │ Railway `scheduler` container             │
        │  postsyncer:watchdog  (everyMinute,       │
        │   withoutOverlapping)                     │
        │   1. stuck: state ∈ {queued,running} AND  │
        │      publish_claimed_at < now − lease     │
        │   2. exhausted retries: failed/uncertain  │
        │   3. repeated lease expiries per content  │──► Cache::add("watchdog:{type}:{id}:{Y-m-d}")
        │   dedup D-15 (one/content/day)            │      │ atomic miss ⇒ alert
        │  publish-events:prune (daily, D-03)       │      ▼
        └───────────────────────────────────────────┘ QueueTelegramNotificationAction
                                                       └► SendTelegramOutboundMessageJob ─► Telegram
        ┌───────────────────────────────────────────┐
        │ Dashboard (auth+verified+SetCurrentWorkspace)
        │  /dashboard/observability  (new page)     │
        │   controller → SQL aggregate on           │
        │   publish_events (Today/7/30/90, D-18)    │──► Inertia props ──► React chart + counts
        │  /dashboard  home: compact stat (D-17)    │                    + recent outcomes list
        └───────────────────────────────────────────┘
```

### Recommended Project Structure
```text
app/
├── Actions/Observability/
│   ├── BuildPublishObservabilityAction.php   # read: aggregates for the page (pure read, not a write Action)
│   └── SendPublishWatchdogAlertAction.php    # one alert, dedup-checked (write via outbox)
├── Console/Commands/
│   ├── PublishWatchdogCommand.php            # postsyncer:watchdog (D-13)
│   └── PrunePublishEventsCommand.php         # publish-events:prune (D-03)
├── Data/Observability/
│   └── PublishEventData.php                  # readonly DTO crossing into the recorder
├── Models/
│   └── PublishEvent.php                      # BelongsToWorkspace + morphTo publishable
├── Support/Observability/
│   └── PublishEventRecorder.php              # D-06 single seam: DB row + log line, D-07 ordering
└── ...
config/logging.php                            # add 'publish' channel (D-02)
bootstrap/app.php                             # withExceptions: Integration::handles($exceptions) (D-09)
config/sentry.php                             # published by sentry:publish (or vendor:publish)
database/migrations/xxxx_create_publish_events_table.php
routes/dashboard.php                          # observability page route
resources/js/pages/observability/index.tsx    # D-17 page (project uses kebab page dirs like workspace-settings)
resources/js/pages/dashboard/home.tsx         # add compact success-rate stat
tests/Unit/Support/Observability/PublishEventRecorderTest.php
tests/Feature/Console/PublishWatchdogCommandTest.php
```

### Pattern 1: PublishEventRecorder — one seam, D-07 ordering enforced internally
**What:** A single constructor-injected support class. One method call = one DB row (best-effort)
+ one JSON log line (always). Publish code catches nothing extra: the recorder swallows its own
DB failures so instrumentation can never break a publish.
**When to use:** Every D-04 instrumentation point — the four lifecycle stages in the actions and
provider HTTP outcomes in the clients.
**Example:**
```php
// Source: D-06/D-07 in 01-CONTEXT.md + verified action call sites
final class PublishEventRecorder
{
    public function __construct(
        private readonly Factory $cache,   // not needed here; see watchdog
    ) {}

    public function record(PublishEventData $event): void
    {
        // 1. The log line ALWAYS fires — even mid-transaction, even if the DB write below fails.
        Log::channel('publish')->info('publish_event', $event->toArray());

        // 2. The DB row is best-effort and AFTER the caller's checkpoint transaction has committed.
        //    Callers invoke this outside DB::transaction closures; the recorder additionally
        //    guards so a logging failure can never fail a publish (D-07).
        try {
            PublishEvent::create([...$event->toArray(),
                // Pitfall 1: Workspace::current() is null on workers — set it explicitly.
                'workspace_id' => $event->workspaceId,
            ]);
        } catch (Throwable) {
            // swallow: observability must not break publishing
        }
    }
}
```

### Pattern 2: The `publish` log channel (D-02) — config-only, no code
**What:** A dedicated channel in `config/logging.php` emitting one JSON object per line to stderr.
**When to use:** All publish forensic logging (OBS-01), readable with `grep publish_event` in
Railway logs.
**Example:**
```php
// Source: laravel.com/docs/13.x/logging (monolog driver: handler/handler_with/formatter keys);
// modeled on the project's existing 'stderr' channel (config/logging.php:105-114)
'publish' => [
    'driver' => 'monolog',
    'level' => env('LOG_PUBLISH_LEVEL', 'info'),
    'handler' => Monolog\Handler\StreamHandler::class,
    'handler_with' => [
        'stream' => 'php://stderr',
    ],
    'formatter' => Monolog\Formatter\JsonFormatter::class,
    'processors' => [Monolog\Processor\PsrLogMessageProcessor::class],
    'replace_placeholders' => false,
],
```

### Pattern 3: Sentry integration + publish context (D-09/D-10/D-11)
**What:** Hook Sentry into the existing Laravel 11+-style exception bootstrap; attach the same
correlation ids the log channel emits.
**When to use:** Once at bootstrap; context attach inside the publish path (and anywhere the
watchdog reports).
**Example:**
```php
// Source: docs.sentry.io/platforms/php/guides/laravel/ (official install for Laravel 11+);
// matches this project's bootstrap/app.php:54-58 which already defines withExceptions()
->withExceptions(function (Exceptions $exceptions): void {
    $exceptions->shouldRenderJsonWhen(
        fn (Request $request) => $request->is('api/*') || $request->is('mcp') || $request->expectsJson(),
    );

    \Sentry\Laravel\Integration::handles($exceptions);   // D-09
})
```
```php
// Source: docs.sentry.io/platforms/php/enriching-events/context/ + /tags/
// D-10: same identifiers as the log channel. Tags are indexed/searchable (workspace_id,
// stage, driver); context is viewable on the issue page.
\Sentry\configureScope(function (\Sentry\State\Scope $scope) use ($event): void {
    $scope->setTag('workspace_id', (string) $event->workspaceId);
    $scope->setTag('publish_stage', $event->stage);
    $scope->setContext('publish', [
        'workspace_id'  => $event->workspaceId,
        'content_id'    => $event->publishableId,
        'content_type'  => $event->publishableType,   // post|video
        'job_id'        => $event->jobId,
        'operation_id'  => $event->operationId,
        'run_token'     => $event->runToken,
    ]);
});
```
Scoping to the publish path only (D-11): attach context from publish code; never configure a
global reporter in middleware or a generic `report()` spy — non-publish exceptions flow through
the same handler but arrive without publish context, which is the accepted v1 behavior.

### Pattern 4: Watchdog command (D-13/D-14/D-15)
**What:** A scheduled command in the existing `routes/console.php` cadence, joining the
`everyMinute()->withoutOverlapping(2)` family.
**When to use:** Stuck-lease detection, exhausted-retry detection, repeated lease-expiry counting.
**Example:**
```php
// Source: routes/console.php:22-24 (existing cadence pattern)
Schedule::command('postsyncer:watchdog')->everyMinute()->withoutOverlapping(2);

// Source: Post::PUBLISH_STATES (app/Models/Post.php:65-71), verbatim:
//   ['idle', 'queued', 'running', 'succeeded', 'failed']
// Stuck detection (D-14 condition 1):
$stale = Post::query()
    ->whereIn('publish_state', ['queued', 'running'])
    ->where('publish_claimed_at', '<', now()->subSeconds($leaseWindow))
    ->get();   // + same query for Video; $leaseWindow per-type (see Pitfall 5 / Open Questions)

// Dedup (D-15) — atomic one-per-day key; database cache store (CACHE_STORE default):
// Source: QueueTelegramNotificationAction logical-key dedup precedent
// (app/Actions/Notifications/QueueTelegramNotificationAction.php:33)
$dedupKey = "watchdog:stuck:{$type}:{$contentId}:".now()->format('Y-m-d');
if (Cache::add($dedupKey, true, now()->endOfDay()->diffInSeconds(now()))) {
    // only the first caller per day gets here — queue the Telegram alert
}
```
Alert delivery goes through `QueueTelegramNotificationAction::handle($workspace, $owner,
CreateTelegramNotificationData)` whose outbox `logical_key` should also encode content+date as a
second dedup layer (its key format today is `"api-notification:user:{$owner->id}:{$data->key}"`
[VERIFIED: app/Actions/Notifications/QueueTelegramNotificationAction.php:33]); construct
`$data->key` as e.g. `"watchdog:stuck:post:{$post->id}:{Y-m-d}"`. When no bot is connected the
action throws `TelegramNotificationsUnavailable` [VERIFIED: same file, lines 58-70] — catch it
and fall back to a log line so the watchdog never crashes over a missing bot.

### Pattern 5: Dashboard read path (D-16–D-19)
**What:** A controller that reads the current workspace, calls a read Action that runs live SQL
aggregates over `publish_events`, and renders an Inertia page; a compact stat joins the existing
`BuildDashboardSummaryAction` output on home.
**Example:**
```php
// Source: D-16 (denominator = content item) + D-19 (live SQL aggregate).
// publish_events rows are per-stage; the success denominator counts finalize-stage outcomes
// per publishable, matching PROJECT.md's per-item metric.
$success = PublishEvent::query()
    ->where('stage', 'finalize')
    ->where('created_at', '>=', $periodStart)
    ->selectRaw("count(*) filter (where payload->>'outcome' = 'succeeded') as succeeded")
    ->selectRaw("count(*) filter (where payload->>'outcome' <> 'succeeded') as failed")
    ->selectRaw("count(*) filter (where stage_payload ? 'lease_expired') as lease_expiries")
    ...
```
(Exact aggregation shape is planner discretion within D-16/D-19; the verified constraint is
that one finalize event per content item is the success/failure unit and retry/lease-expiry
counts ride alongside per OBS-05.)

### Anti-Patterns to Avoid
- **Model observers for event capture:** D-06 explicitly forbids them; observers hide the write
  inside model lifecycle and cannot express the D-07 after-commit ordering per call site.
- **Recorder calls inside `DB::transaction` closures:** the actions hold nine checkpoint
  transactions; a recorder call inside them joins the transaction (violating D-07) and its
  failure would roll back real publish state. Call after the transaction returns.
- **`Sentry::captureException()` sprinkled in domain code:** publish jobs already
  `report($exception)` on failure [VERIFIED: app/Jobs/PublishPostJob.php:186-188]; go through
  `report()`/the handler so Sentry integration stays at one seam.
- **Logging provider API keys or workspace tokens:** the clients hold `apiKey()`/`token()`;
  context payloads carry ids only (ASVS V7; see Security Domain).
- **Trusting `BelongsToWorkspace` auto-fill from workers:** see Pitfall 1.
- **Pre-aggregating metrics:** D-19 locks live SQL; a summary table is out of scope.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| JSON-per-line log encoding | Custom string-concat JSON or a custom formatter class | Monolog `JsonFormatter` via the channel `formatter` key | Escaping, nesting, and newline handling are solved; zero deps (bundled with Laravel) [CITED: laravel.com/docs/13.x/logging] |
| Exception → error-tracker delivery | Custom HTTP client posting to Sentry | `Integration::handles($exceptions)` + `report()` | The SDK handles payload shape, retries, sampling, PII defaults [CITED: docs.sentry.io Laravel install] |
| "Once per day" dedup | A DB column or file markers on content | `Cache::add($key, true, ttl)` with a date-suffixed key | Atomic compare-and-set; database store survives restarts; auto-expires; array store in tests [CITED: laravel.com/docs/13.x/cache — atomic add semantics] |
| Scheduled single-flight command | A custom lock file or DB flag | `->withoutOverlapping(2)` (existing pattern) | Scheduler manages the cache lock; `schedule:clear-cache` recovers stuck locks [VERIFIED in-repo: routes/console.php; CITED: laravel.com/docs/13.x/scheduling] |
| Chart rendering | Raw SVG path math in TSX | recharts (checkpoint-gated) or styled divs from the ui kit | Both options avoid hand-rolled axis/tooltip work; recharts is React 19 peer-supported [VERIFIED: npm registry] |
| Telegram message delivery | A new bot API client | `QueueTelegramNotificationAction` + `SendTelegramOutboundMessageJob` | Outbox has dedup (`logical_key`), chunking, reconnect generation handling already [VERIFIED: app/Actions/Notifications/] |

**Key insight:** Everything this phase needs except `publish_events` itself already exists in
the framework or the codebase — the work is wiring verified seams, not building machinery.

## Common Pitfalls

### Pitfall 1: `BelongsToWorkspace` auto-fill silently writes `workspace_id = null` from workers
**What goes wrong:** The trait's `creating` hook fills `workspace_id` only when
`Workspace::current()` returns a model. On queue workers and scheduled commands that singleton
is empty (it is set by `SetCurrentWorkspace` HTTP middleware), so `PublishEvent::create([...])`
without an explicit `workspace_id` writes null (or fails on a NOT NULL column).
**Why it happens:** `Workspace::current()` delegates to `app(CurrentWorkspace::class)->get()`
— a per-request singleton [VERIFIED: app/Models/Workspace.php:62-65;
app/Concerns/BelongsToWorkspace.php:35-39].
**How to avoid:** `PublishEventRecorder` always sets `workspace_id` explicitly from the
publishable's `workspace_id` attribute; never rely on the trait's creating hook in worker
context.
**Warning signs:** dashboard observability page showing partial/empty data while Railway logs
show events; null `workspace_id` rows.

### Pitfall 2: Recorder calls joining a checkpoint transaction (violates D-07)
**What goes wrong:** An instrumentation call placed inside one of the actions'
`DB::transaction` closures (lines ~423, ~789, ~890, ~986, ~1054, ~1298, ~1740 per
CONCERNS.md) makes event rows part of the publish transaction — a recorder failure would roll
back publish state, and rows would be visible before the checkpoint commits.
**Why it happens:** The action's checkpoint sites are the natural-looking places to "log
progress."
**How to avoid:** D-07 as a hard convention: `saveProgressForRun(...)` (transaction) returns,
*then* `$recorder->record(...)`. Add a code-review checklist item and a test asserting events
survive a publish rollback.
**Warning signs:** missing event rows after failed publishes (rolled back together), or
publish-state exceptions bubbling from recorder code.

### Pitfall 3: Sentry enabled in dev/test burns quota and slows the suite
**What goes wrong:** Sentry's own docs warn errors are captured during development and tests
when the DSN is set, with measurable performance effects.
**Why it happens:** A `.env` copied from production, or DSN committed to `.env.example` as
non-null.
**How to avoid:** Leave `SENTRY_LARAVEL_DSN` unset (or `=null`) outside production; ship
`.env.example` with `SENTRY_LARAVEL_DSN=` empty; local/test runs stay inert by design
[CITED: docs.sentry.io Laravel install — "not defining SENTRY_LARAVEL_DSN … disables"].
**Warning signs:** test suite latency jump; unexpected events in the Sentry project.

### Pitfall 4: Secrets leaking into logs or Sentry payloads
**What goes wrong:** Provider API keys (`PostsyncerConfig::apiKey()`, `SocialreinsConfig::token()`)
or workspace settings end up in `payload` JSON or Sentry extras — an ASVS V7 violation and a
real leak vector into a third-party SaaS.
**Why it happens:** Convenience-dumping `$config` or client objects into context.
**How to avoid:** `PublishEventData` carries ids, stages, HTTP status codes, and durations
only; never config objects. Keep Sentry `send_default_pii` at its `false` default.
**Warning signs:** any `toArray()` of a config/client in a call site under review.

### Pitfall 5: Post and video lease windows are not the same number
**What goes wrong:** A watchdog threshold copied from `PublishPostJob::LEASE_SECONDS = 1020`
[VERIFIED: app/Jobs/PublishVideoJob.php has no LEASE_SECONDS; it defines
`UNIQUE_FOR_SECONDS = 3600` at line 26] mislabels in-flight video publishes as stuck (false
alerts) or misses genuinely stuck ones.
**Why it happens:** The two jobs evolved separately; video's unique-for window is 3600s while
overlap expiry is 1020s.
**How to avoid:** Derive the watchdog threshold per publishable type from the actual constants
(prefer a shared config entry, e.g. `config/observability.php` → `lease_windows = ['post' => 1020, 'video' => <verified-from-PublishVideoAction>]`), with a safety margin (e.g. 1.5× lease)
before alerting. PublishVideoAction's internal lease semantics must be read before finalizing
(Open Question 1).
**Warning signs:** watchdog alerts for posts/videos still within their normal publish runtime
(multi-group posts routinely take minutes — 300s HTTP waits are by design
[VERIFIED: PostsyncerClient.php:595]).

### Pitfall 6: Composer resolving the Sentry 5 beta
**What goes wrong:** A bare `composer require sentry/sentry-laravel` today can pick up
`5.0.0-beta1` (published 2026-11-18) in setups that relax minimum-stability; beta line 5 may
change APIs.
**Why it happens:** The beta is the newest tag on Packagist.
**How to avoid:** Require `sentry/sentry-laravel:^4.31.0` explicitly; the project's
`minimum-stability: stable` [VERIFIED: composer.json:142-143] already guards, but be explicit.
**Warning signs:** `composer show sentry/sentry-laravel` showing `5.0.0-beta1`.

### Pitfall 7: Watchdog false-positives during normal long publishes
**What goes wrong:** A multi-group publish legitimately holds `running` for many minutes
(300s create timeouts + up to 120s of 429 waits per client) — an aggressive lease threshold
alerts on healthy publishes and erodes trust in the alert.
**Why it happens:** Treating "lease window" as "expected publish duration."
**How to avoid:** Threshold = lease window (1020s posts) plus margin, and prefer "no progress
checkpoint within X" over raw age when the events table is available (count checkpoint-stage
events per run_token).
**Warning signs:** alerts correlating with successful publishes.

### Pitfall 8: Scheduler lock held by a crashed command
**What goes wrong:** `withoutOverlapping` locks live in the cache; a worker crash mid-command
can leave the lock until expiry, silently skipping watchdog runs.
**Why it happens:** Laravel docs: locks expire after N minutes (default 24h) — the existing
commands use 2 [CITED: laravel.com/docs/13.x/scheduling; VERIFIED: routes/console.php].
**How to avoid:** Keep `withoutOverlapping(2)` (2-minute expiry, matching the existing family);
know `schedule:clear-cache` exists for recovery.
**Warning signs:** watchdog silently absent from Railway scheduler logs.

## Code Examples

### Migration: polymorphic publish_events (D-05) — additive-only
```php
// Source: StatusTransition morph precedent (app/Models/StatusTransition.php:24-44,
// append-only, no updated_at) + BelongsToWorkspace contract (workspace_id column required)
Schema::create('publish_events', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('workspace_id')->constrained();          // set explicitly by recorder
    $table->nullableMorphs('publishable');                     // post|video (D-05)
    $table->string('stage');                                   // claim|checkpoint|retry|finalize|provider_http
    $table->string('job_id')->nullable();                      // queue job correlation
    $table->string('operation_id')->nullable();                // publish operation correlation
    $table->string('run_token')->nullable();                   // run correlation (grep-able)
    $table->string('group_key')->nullable();                   // publish group when stage is group-scoped
    $table->string('provider')->nullable();                    // postsyncer|socialreins
    $table->integer('duration_ms')->nullable();
    $table->jsonb('payload')->nullable();                      // ids + statuses only (Pitfall 4)
    $table->timestamp('created_at');                           // append-only, no updated_at
    $table->index(['workspace_id', 'created_at']);             // dashboard period aggregates (D-19)
    $table->index(['publishable_type', 'publishable_id']);     // per-content history (D-14 condition 3)
});
```

### Recording from the action's finalize stage (behavior-preserving)
```php
// Source: app/Actions/Postsyncer/PublishPostAction.php:266-279 — the verified finalize site
$progress['state'] = 'succeeded';
$progress['completed_groups'] = $completedGroups;
$progress['current'] = null;
if (! $this->finalizeSuccess(
    $post,
    $publishedGroups,
    $progress,
    $options,
    $claimLeaseId,
)) { /* existing behavior unchanged */ }

// NEW: after finalizeSuccess() returned — outside its transaction (D-07):
$this->recorder->record(PublishEventData::finalize(
    workspaceId: $post->workspace_id,
    publishable: $post,
    jobId: $jobId,             // captured where the job calls the action
    operationId: $operationId, // already in scope (action signature, line 56)
    runToken: $runToken,       // already in scope (line 55)
    outcome: 'succeeded',
));
```

### Provider HTTP outcome event (429 wait — today's invisible failure mode)
```php
// Source: app/Support/Postsyncer/PostsyncerClient.php:547-567 — verified 429 wait loop:
//   RATE_LIMIT_BACKOFF_SECONDS = [30, 60, 60] (line 27),
//   PUBLISH_RATE_LIMIT_WAIT_SECONDS = 120 (line 25),
//   Sleep::for($wait)->seconds() (line 565)
catch (PostsyncerException $exception) {
    if ($exception->getCode() !== 429 || ! $exception->safeToRetry) {
        throw $exception;
    }
    $wait = ...;
    if ($attempt >= $resends || $wait > $this->rateLimitWaitSeconds) {
        throw $exception;
    }
    $this->rateLimitWaitSeconds -= $wait;
    // NEW: first-class rate-limit event per CONTEXT.md Specifics
    $this->recorder?->record(PublishEventData::providerHttp(
        provider: 'postsyncer', stage: 'provider_http', status: 429,
        waitedSeconds: $wait, path: $path,
    ));
    Sleep::for($wait)->seconds();
}
```
Note the optional `$recorder?->` nullsafe injection: the client is currently constructed with
`new PostsyncerClient($config, rateLimitWaitSeconds: ...)` at 6+ call sites [VERIFIED:
CONCERNS.md dependency-inversion finding]; making the recorder an optional constructor param
lets instrumentation land without breaking those call sites, and Phase 2's ARCH-03 will move
the client behind a contract properly.

### Sentry wiring — complete diff of what changes in bootstrap
```php
// bootstrap/app.php — only the withExceptions closure body changes (line 54-58 today)
->withExceptions(function (Exceptions $exceptions): void {
    $exceptions->shouldRenderJsonWhen(
        fn (Request $request) => $request->is('api/*') || $request->is('mcp') || $request->expectsJson(),
    );

    \Sentry\Laravel\Integration::handles($exceptions);
})
```
`config/sentry.php` comes from `php artisan sentry:publish --dsn=...`; keep
`SENTRY_LARAVEL_DSN` out of `.env.example`'s non-empty defaults (Pitfall 3). `error_sample_rate`
/ `traces_sample_rate` defaults (tracing off) match D-11's minimal scope; release tagging inside
D-09's discretion if wanted later.

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Sentry `set_extra()` "Additional Data" | Structured contexts via `setContext()` | Deprecated (PHP SDK) | Use `setContext` for issue-page data, `setTag` for searchable ids [CITED: docs.sentry.io context page] |
| Sentry 4.x stable line | 5.0.0-beta1 exists (2026-11-18) | 2026-11-18 | Stay on ^4.31.0; project pins `minimum-stability: stable` [VERIFIED: Packagist] |
| Registering Sentry via `App::register(ServiceProvider)` | `Integration::handles($exceptions)` in `bootstrap/app.php` | Laravel 11+ | Matches this project's bootstrap style exactly [CITED: docs.sentry.io Laravel] |
| recharts 2.x | recharts 3.x with React 19 in peer range | 3.x line (3.11.0 current) | Safe for React 19 per peerDeps; community tooltip edge-reports on some 3.3.x versions — test the chart after install [VERIFIED: npm registry; MEDIUM web] |
| `Log::` default channel with context arrays | Dedicated channel per concern (this project's D-02) | Project decision 2026-11-22 | One JSON line per event, grep-able per stage in Railway |

**Deprecated/outdated:**
- Sentry "Additional Data" (`set_extra`): deprecated → structured contexts.
- `App::register`-style SDK bootstraps: superseded by `Integration::handles` for Laravel ≥11.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `$this->job->uuid()` is available inside queued jobs for job-id correlation (no in-repo precedent found; standard Laravel queue API) | Code Examples / OBS-01 correlation | Job id lands null in events/logs — correlation degrades to operation/run token only; fix is trivial (fallback to a generated id captured at dispatch) |
| A2 | Sentry SaaS free tier comfortably covers single-operator publish-path volume (quota numbers not verified this session) | Standard Stack / D-09 | Quota exhaustion silences OBS-04; mitigation is sample-rate tuning (discretion per D-09) |
| A3 | `Integration::handles()` + `report($exception)` from `PublishPostJob::failed()` delivers job-exhaustion failures to Sentry without extra wiring | Pattern 3 / OBS-04 | Permanent-failure exceptions missing from Sentry; fallback: explicit `Sentry::captureException()` in the watchdog/exhausted branch |
| A4 | Video lease window must be derived from `PublishVideoAction` internals (no `LEASE_SECONDS` const on the video job) — exact value pending | Pitfall 5 / Open Question 1 | Watchdog misclassifies video publishes; wrong thresholds produce false or missed alerts |
| A5 | recharts 3.11.0 mounts cleanly under React 19 in this project's Vite/Inertia setup (peer range confirmed; community edge-reports on some 3.3.x tooltip paths) | Standard Stack | Chart renders blank/broken; CSS-bars fallback exists at zero cost |
| A6 | Sentry's Laravel SDK auto-captures queue-job exceptions through the shared handler (docs describe handler integration; per-queue specifics not individually verified) | Pattern 3 | Some worker-side exceptions need explicit capture; resolved by a staging `sentry:test` + deliberate test exception before sign-off |

## Open Questions (RESOLVED)

**Status:** All five questions were resolved at planning time; each carries a `Resolution:`
marker pointing at the resolving plan. No unresolved open question remains in this document.

1. **What is the video path's effective lease window?**
   - What we know: `PublishPostJob::LEASE_SECONDS = 1020` verified; `PublishVideoJob` defines
     `UNIQUE_FOR_SECONDS = 3600` / `OVERLAP_EXPIRES_AFTER_SECONDS = 1020` but no LEASE const
     [VERIFIED: app/Jobs/PublishVideoJob.php:24-37].
   - What's unclear: whether `PublishVideoAction::claim*` enforces its own expiry constant and
     what value.
   - Recommendation: planner adds a read task (`PublishVideoAction` claim path) before the
     watchdog task; put both windows in one config file with margins (Pitfall 5).
   - Resolution: RESOLVED in plan 01-03 Task 1 — `PublishVideoAction` enforces no expiry
     constant of its own (verified: zero lease matches in the action), so the effective video
     window is `PublishVideoJob::OVERLAP_EXPIRES_AFTER_SECONDS` (1020), set alongside the post
     window in `config/observability.php` `lease_windows` with the 1.5x
     `stuck_margin_multiplier` (recommendation adopted).
2. **Where exactly does the claim path observe a lease expiry?**
   - What we know: the claim transaction resets state when
     "The previous publish worker lease expired." (string at `PublishPostAction` ~line 2522)
     [VERIFIED via grep of claimPost block].
   - What's unclear: whether video's claim path has the same marker text/branch.
   - Recommendation: emit the lease-expiry event inside `claimPost()`'s expired-lease branch
     (post) and its video counterpart — planner pins both line-level hooks in the plan.
   - Resolution: RESOLVED in plan 01-01 Task 2 (post half) — the expired-lease branch (the
     "The previous publish worker lease expired" path, ~line 2522) records a claim event with
     `payload.lease_expired = true`. The video half is RESOLVED as "no hook exists" (plans
     01-02/01-03): videos carry no `publish_claimed_at`/`publish_lease_id` columns
     (posts-only migrations 2026_09_01_140003/140004) and `PublishVideoAction` contains no
     lease machinery, so lease-expiry events are post-path-only by design and the watchdog's
     lease-loop condition (D-14 condition 3) is post-scoped accordingly.
3. **Watchdog behavior when no Telegram bot is connected?**
   - What we know: `QueueTelegramNotificationAction` throws `TelegramNotificationsUnavailable`
     without a connected bot [VERIFIED: app/Actions/Notifications/QueueTelegramNotificationAction.php:58-70].
   - Recommendation: catch and degrade to a `publish`-channel log line + the dashboard counts
     (alerts still visible via Sentry breadcrumb context if desired). No alert loss beyond
     Telegram being unconfigured.
   - Resolution: RESOLVED in plan 01-03 Task 2 — the recommendation is adopted as locked:
     `TelegramNotificationsUnavailable` is caught and degraded to a `publish`-channel warning,
     so the scheduled command never crashes over a missing bot.
4. **recharts vs. CSS bars for the success-rate chart?**
   - What we know: recharts is React 19 peer-supported but seam-flagged SUS (false-positive
     "too-new"); project currently has zero chart deps; D-17 asks for a "success-rate chart".
   - Recommendation: default to CSS/Tailwind bars (four buckets, no dependency, no checkpoint)
     unless the operator wants axes/tooltips — in which case gate recharts behind
     `checkpoint:human-verify`.
   - Resolution: RESOLVED in plan 01-05 (with 01-UI-SPEC.md) — the recommendation is adopted:
     CSS/Tailwind stacked proportion bar, no chart dependency, no checkpoint.
5. **Sentry project provisioning (human action).**
   - What we know: D-09 uses Sentry SaaS; a DSN requires a human-created org/project.
   - Recommendation: planner inserts an early `checkpoint:human-verify` task — "create Sentry
     project, supply DSN via Railway env `SENTRY_LARAVEL_DSN`" — before OBS-04 tasks; code must
     ship functional with the DSN unset (Pitfall 3) so this does not block the rest of the phase.
   - Resolution: RESOLVED in plan 01-04 Task 3 — the recommendation is adopted: a blocking
     `checkpoint:human-verify` task provisions the org/project and sets `SENTRY_LARAVEL_DSN`
     (Railway app env), while Tasks 1-2 ship the SDK functional and inert with the DSN unset.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP | All backend work | ✓ | 8.5.11 CLI (project requires ^8.3) | — |
| Composer | sentry install, autoload | ✓ | present at `C:/ProgramData/ComposerSetup/bin/composer` (not on bash PATH — invoke by full path) | — |
| Node / npm | frontend build, recharts | ✓ | v24.21.0 | — |
| `vendor/` dependencies | running `pest`/`pint`/`phpstan` locally | ✗ (directory absent) | — | Run `composer install` before any test task; tests cannot execute until then |
| Sentry account + project DSN | OBS-04 end-to-end validation | ✗ (human SaaS signup) | — | Code ships inert with DSN unset; enable via Railway env when created |
| PostgreSQL (test DB) | Pest runs (phpunit.xml forbids SQLite overrides) | ✓ assumed per docs/getting-started (CI uses postgres:17) | 17 in CI | — |
| Telegram bot infrastructure | Watchdog alerts (D-12) | ✓ in-app (per-workspace config) | — | Log-only fallback when unconfigured (Open Question 3) |

**Missing dependencies with no fallback:**
- Sentry DSN — required for *validating* OBS-04 end-to-end (not for shipping the code). Planner
  should sequence a human checkpoint.

**Missing dependencies with fallback:**
- `vendor/` not installed on this machine — first execution task is `composer install` (full
  composer path noted above).
- Telegram bot unconfigured per workspace — watchdog degrades to log-only (locked fallback in
  Open Question 3).

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | Pest ^5.1 + PHPUnit (laravel plugin ^5.0, drift plugin present) [VERIFIED: composer.json:27-31] |
| Config file | `phpunit.xml` (testsuites Unit + Feature; real PostgreSQL required — SQLite overrides deliberately removed) [VERIFIED: phpunit.xml] |
| Quick run command | `./vendor/bin/pest tests/Unit/Support/Observability/` |
| Full suite command | `./vendor/bin/pest` (plus `./vendor/bin/pint --test` and `./vendor/bin/phpstan analyse --memory-limit=1G` per CLAUDE.md gates) |

### Phase Requirements → Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| OBS-01 | Recorder emits one JSON log line + one DB row with job/content/op/run/stage | unit | `./vendor/bin/pest tests/Unit/Support/Observability/PublishEventRecorderTest.php` (uses `Log::fake()`; DB row asserted) | ❌ Wave 0 |
| OBS-01 | Stage events emitted at claim/checkpoint/retry/finalize in publish action | unit (existing suite extended) | `./vendor/bin/pest tests/Unit/Actions/Postsyncer/PublishPostActionTest.php --filter=instrumentation` | ❌ extend existing |
| OBS-02 | Stuck queued/running past lease window detected, alert queued | feature (console) | `./vendor/bin/pest tests/Feature/Console/PublishWatchdogCommandTest.php` | ❌ Wave 0 |
| OBS-02/D-15 | One alert per content per day (second run same day is silent) | feature (console) | same file, `--filter=dedup` (cache array store makes TTL logic testable) | ❌ Wave 0 |
| OBS-03/05 | Success/failure/retry/lease-expiry counts per period correct | unit | `./vendor/bin/pest tests/Unit/Actions/Observability/BuildPublishObservabilityActionTest.php` | ❌ Wave 0 |
| OBS-03 | Dashboard page renders with period props, workspace-scoped | feature | `./vendor/bin/pest tests/Feature/` (new `ObservabilityPageTest.php`) | ❌ Wave 0 |
| OBS-04 | Publish context helper attaches workspace/content/job ids to Sentry scope | unit | `./vendor/bin/pest tests/Unit/Support/Observability/PublishContextTest.php` (assert against a spy/fake Sentry client binding) | ❌ Wave 0 |
| OBS-04 | End-to-end delivery to Sentry | manual-only | `php artisan sentry:test` against staging + deliberate test exception; **justification:** requires live SaaS project + DSN (human checkpoint) | ❌ checkpoint task |
| D-03 | Prune removes rows older than 90 days only | feature (console) | `./vendor/bin/pest tests/Feature/Console/PrunePublishEventsCommandTest.php` | ❌ Wave 0 |

### Sampling Rate
- **Per task commit:** `./vendor/bin/pest` on the touched suite + `./vendor/bin/pint --test` (CLAUDE.md gate)
- **Per wave merge:** full `./vendor/bin/pest` + `./vendor/bin/phpstan analyse --memory-limit=1G` (Larastan 7)
- **Phase gate:** full suite green before `/gsd-verify-work`; every publish-path PR additionally
  waits for Harun's chat review per CLAUDE.md shipping rules (this phase touches nothing but the
  publish path).

### Wave 0 Gaps
- [ ] `composer install` — vendor tree absent on this machine (blocks every test command)
- [ ] `tests/Unit/Support/Observability/PublishEventRecorderTest.php` — covers OBS-01 recorder contract
- [ ] `tests/Feature/Console/PublishWatchdogCommandTest.php` — covers OBS-02 + D-15 dedup (follows `tests/Feature/Console/` precedent, 19 existing command tests)
- [ ] `tests/Unit/Actions/Observability/BuildPublishObservabilityActionTest.php` — covers OBS-03/05 aggregates
- [ ] Sentry fake/spike — decide the assertion seam for OBS-04 (fake client binding vs `Sentry\Laravel\Integration` test helpers; A6/A3 spike)

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V7 Error Handling & Logging | **yes — primary for this phase** | Ids-only log/Sentry payloads (never API keys/tokens); JSON channel structure prevents injection; Sentry `send_default_pii` stays `false` |
| V4 Access Control | yes | New dashboard route behind existing `auth+verified+SetCurrentWorkspace` middleware chain [VERIFIED: routes/dashboard.php:16-19]; `publish_events` model uses `BelongsToWorkspace` global scope for HTTP reads |
| V5 Input Validation | yes | Period selector validated server-side (in: today,7,30,90) via a FormRequest per project rules; no other user input enters this phase |
| V6 Cryptography | yes (credential placement) | Sentry DSN lives in app `.env` per D-09 + ADR-0002 (it is app runtime config, not an infra token); never in a config default or `.secrets/` |
| V2/V3 Auth & Sessions | no new surface | Reuses existing middleware; nothing new to design |

### Known Threat Patterns for Laravel publish-path observability

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Secret/token leakage into logs or Sentry (provider API keys, workspace tokens) | Information Disclosure | `PublishEventData` whitelist of id/status/duration fields; never `toArray()` config objects; Sentry default PII off |
| Cross-workspace data exposure on the observability page | Elevation/Info Disclosure | Route middleware chain + `BelongsToWorkspace` scope; recorder sets `workspace_id` explicitly so rows are always attributable (Pitfall 1) |
| Log injection via crafted content bodies | Tampering | JSON formatter escapes structure; payloads carry ids/statuses, not raw content bodies |
| Watchdog alert flooding (loop conditions) | DoS (operator attention) | D-15 daily dedup key + outbox `logical_key` dedup [VERIFIED pattern: QueueTelegramNotificationAction] |
| Observability write failures masking incidents | Repudiation | D-07: log line always fires even when the DB write fails; log is the forensic floor |

## Project Constraints (from CLAUDE.md)

- **Action pattern:** the watchdog's alert send and the dashboard's read aggregation belong in
  Action classes (`app/Actions/Observability/`); controllers stay thin; no business logic in
  models. The recorder is a `Support` class called by actions/clients (consistent with D-06).
- **DTOs:** `PublishEventData` is a plain readonly PHP 8.3 class with named constructors; no
  raw arrays across the recorder boundary; no spatie/laravel-data.
- **Dependency inversion:** if the Sentry context helper or recorder are faked in tests, bind
  them in `app/Providers/AppServiceProvider.php` following the existing bind pattern
  [VERIFIED: app/Providers/AppServiceProvider.php:34-50].
- **Testing gates:** `pest`, `pint --test`, `phpstan analyse --memory-limit=1G` (Larastan 7)
  must pass before any commit; dedicated PostgreSQL test DB; serial Pest.
- **Listener registration:** do not register any new listeners manually — auto-discovery only
  (this phase should add none).
- **Shipping gate:** every PR in this phase touches the publish path or its observation —
  Harun review in chat before merge is mandatory (CLAUDE.md; also CONTEXT.md caution).
- **Migrations:** additive-only (`create_publish_events_table`); `DB::prohibitDestructiveCommands`
  is active in production [VERIFIED: app/Providers/AppServiceProvider.php:96-98] — the prune
  command must use Eloquent/`delete()` within its own scheduled command, not raw destructive
  schema commands.

## Sources

### Primary (HIGH confidence)
- In-repo (all `[VERIFIED: path:lines]` claims above, read this session):
  `app/Actions/Postsyncer/PublishPostAction.php`, `app/Jobs/PublishPostJob.php`,
  `app/Jobs/PublishVideoJob.php`, `app/Support/Postsyncer/PostsyncerClient.php`,
  `app/Support/Socialreins/SocialreinsClient.php`, `app/Models/Post.php`, `app/Models/Video.php`,
  `app/Models/Workspace.php`, `app/Concerns/BelongsToWorkspace.php`,
  `app/Models/StatusTransition.php`, `app/Actions/Notifications/QueueTelegramNotificationAction.php`,
  `app/Providers/AppServiceProvider.php`, `bootstrap/app.php`, `config/logging.php`,
  `routes/console.php`, `routes/dashboard.php`, `composer.json`, `package.json`, `phpunit.xml`
- repo.packagist.org p2 metadata for `sentry/sentry-laravel` (fetched 2026-11-22) — version 4.31.0,
  Laravel 13 support, PHP constraints
- registry.npmjs.org for `recharts` (fetched 2026-11-22) — 3.11.0, React 19 peer range, created 2015
- docs.sentry.io/platforms/php/guides/laravel/ — install, `Integration::handles`, DSN env,
  `sentry:test`, dev/test disabling
- docs.sentry.io/platforms/php/enriching-events/context/ and /tags/ — `setContext`/`setTag`,
  `set_extra` deprecation, tag key/value constraints
- laravel.com/docs/13.x/logging — monolog driver `handler`/`handler_with`/`formatter` keys,
  `Log::channel`, `withContext`
- laravel.com/docs/13.x/scheduling — `everyMinute`, `withoutOverlapping` (cache locks, expiry,
  `schedule:clear-cache`), `onOneServer`, task hooks

### Secondary (MEDIUM confidence)
- WebSearch corroboration of recharts React 19 support (GitHub issue #4558 → supported since
  2.13.0-alpha; 3.x peer range authoritative) + community notes on 3.3.x tooltip edge cases
- npm downloads API (api.npmjs.org) — recharts weekly volume

### Tertiary (LOW confidence)
- api.github.com/releases snippets for sentry-laravel changelog (search-result summary; version
  facts re-verified against Packagist directly, which supersedes)

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — every recommended package verified against its authoritative registry this session; only recharts carries a seam flag (explained)
- Architecture: HIGH — all instrumentation seams, correlation ids, scheduler cadence, notification path, and bootstrap hook verified in-repo by reading the files
- Pitfalls: HIGH for in-repo findings (worker-context workspace null, transaction ordering, lease-window divergence); MEDIUM for Sentry runtime behaviors (A3/A6)

**Research date:** 2026-11-22
**Valid until:** 2026-12-22 (stable stack; Sentry minor releases unlikely to change wiring within 30 days)

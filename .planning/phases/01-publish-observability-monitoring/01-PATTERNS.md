# Phase 1: Publish Observability & Monitoring - Pattern Map

**Mapped:** 2026-11-22
**Files analyzed:** 14 (new + modified)
**Analogs found:** 14 / 14

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|-------------------|------|-----------|----------------|---------------|
| `app/Support/Observability/PublishEventRecorder.php` | support/service | transform (log+DB write) | `app/Actions/Notifications/QueueTelegramNotificationAction.php` (constructor-injected seam) + `app/Support/*` singleton style | role-match |
| `app/Data/Observability/PublishEventData.php` | DTO | transform | `app/Data/Notifications/CreateTelegramNotificationData.php`, `app/Data/Dashboard/DashboardSummaryData.php` | exact |
| `app/Models/PublishEvent.php` | model | CRUD (append-only) | `app/Models/StatusTransition.php` + `app/Concerns/BelongsToWorkspace.php` | exact |
| `database/migrations/xxxx_create_publish_events_table.php` | migration | schema | `database/migrations/2026_08_19_100005_create_status_transitions_table.php` | exact |
| `app/Console/Commands/PublishWatchdogCommand.php` | console command | batch/query | `app/Console/Commands/DispatchPendingPostPublishesCommand.php` (same stale-lease query!) | exact |
| `app/Console/Commands/PrunePublishEventsCommand.php` | console command | batch/delete | `app/Console/Commands/CleanupOrphanMediaCommand.php` | role-match |
| `app/Actions/Observability/BuildPublishObservabilityAction.php` | action (pure read) | request-response/query | `app/Actions/Dashboard/BuildDashboardSummaryAction.php` | exact |
| `app/Actions/Observability/SendPublishWatchdogAlertAction.php` | action (write) | event-driven | `app/Actions/Notifications/QueueTelegramNotificationAction.php` | exact |
| `app/Http/Controllers/ObservabilityController.php` | controller | request-response | `app/Http/Controllers/DashboardController.php` | exact |
| `routes/dashboard.php` (modify) | route | request-response | existing dashboard route group | exact |
| `routes/console.php` (modify) | scheduler config | event-driven | existing `Schedule::command(...)` family | exact |
| `config/logging.php` (modify) | config | streaming | existing `stderr` channel (lines 105–114) | exact |
| `bootstrap/app.php` (modify) | config/bootstrap | — | existing `withExceptions()` closure | exact |
| `resources/js/pages/observability/index.tsx` | component (Inertia page) | request-response | `resources/js/pages/dashboard/home.tsx` | exact |
| `tests/Feature/Console/PublishWatchdogCommandTest.php` + unit tests | test | — | `tests/Feature/Console/ReconcilePostPublishCommandTest.php` | exact |

## Pattern Assignments

### `app/Support/Observability/PublishEventRecorder.php` (support, transform)

**Analog:** constructor-injected single-seam classes like `QueueTelegramNotificationAction`; register/bind in `AppServiceProvider` if tests fake it.

**Constructor-injection pattern** (`app/Actions/Notifications/QueueTelegramNotificationAction.php` lines 19–24):
```php
class QueueTelegramNotificationAction
{
    public function __construct(
        private readonly QueueTelegramMessageAction $queueMessage,
    ) {}
```
The recorder should follow the same plain constructor-injected class shape (no package, no facade state), with one public entry point:
```php
public function record(PublishEventData $event): void
```

**D-07 ordering rule (mandatory convention, from CONTEXT.md D-07 + Pitfall 2):**
```php
// 1. Log line ALWAYS fires (even mid-transaction / DB failure):
Log::channel('publish')->info('publish_event', $event->toArray());
// 2. DB row best-effort, called OUTSIDE the caller's DB::transaction closure:
try {
    PublishEvent::create([...$event->toArray(), 'workspace_id' => $event->workspaceId]);
} catch (Throwable) {
    // swallow — observability must never break a publish
}
```
**Pitfall 1 guard:** set `workspace_id` explicitly from the publishable — the `BelongsToWorkspace` creating-hook reads `Workspace::current()` which is null on workers (`app/Concerns/BelongsToWorkspace.php` lines 35–39; `app/Models/Workspace.php` lines 62–65).

---

### `app/Data/Observability/PublishEventData.php` (DTO, transform)

**Analog:** `app/Data/Notifications/CreateTelegramNotificationData.php` (whole file, 21 lines)

```php
final readonly class CreateTelegramNotificationData
{
    public function __construct(
        public string $key,
        public string $text,
    ) {}

    public static function fromRequest(StoreTelegramNotificationRequest $request): self
    {
        return new self(
            key: $request->string('key')->toString(),
            text: $request->string('text')->toString(),
        );
    }
}
```
Copy this shape: `final readonly`, constructor-promoted public props, no setters, no side effects. For PublishEventData the named constructors are domain-specific factories, not `fromRequest` — e.g. `PublishEventData::finalize(...)`, `::providerHttp(...)` (call sites shown in RESEARCH.md Code Examples). Add a pure `toArray(): array` like `DashboardSummaryData` (lines 33–42) for the log channel. Payload carries ids/statuses/durations only — never config objects (Pitfall 4).

---

### `app/Models/PublishEvent.php` (model, append-only CRUD)

**Analog:** `app/Models/StatusTransition.php` (whole file — append-only morph model, exact precedent)

```php
class StatusTransition extends Model
{
    const UPDATED_AT = null;
    protected $fillable = ['from', 'to', 'reason', 'actor_type', 'actor_id', 'token_name'];

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
```
Copy: `const UPDATED_AT = null` (append-only, no updated_at), small `$fillable`, `morphTo('publishable')` relation named `publishable()`. Add `use Concerns\BelongsToWorkspace;` for the dashboard-read global scope (HTTP context) — but the recorder still sets `workspace_id` explicitly (Pitfall 1). Keep the docblock style documenting the append-only contract.

**Workspace scoping trait** (`app/Concerns/BelongsToWorkspace.php` lines 27–40) — global scope + creating hook; the trait's docblock itself warns some models deliberately skip it for cross-workspace queries.

---

### `database/migrations/xxxx_create_publish_events_table.php` (migration)

**Analog:** `database/migrations/2026_08_19_100005_create_status_transitions_table.php` (whole file)

```php
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('status_transitions', function (Blueprint $table) {
            $table->id();
            $table->morphs('subject');
            ...
            $table->timestampTz('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_transitions');
    }
};
```
Copy: anonymous Migration class, `$table->morphs('publishable')` (nullable per D-05), `timestampTz('created_at')->useCurrent()` with no updated_at, and the explanatory docblock comment style. Column set per RESEARCH.md migration example (workspace_id FK, stage, job_id, operation_id, run_token, group_key, provider, duration_ms, jsonb payload) plus the two indexes (`['workspace_id','created_at']`, `['publishable_type','publishable_id']`). Additive-only — no destructive commands (production has `DB::prohibitDestructiveCommands` active).

---

### `app/Console/Commands/PublishWatchdogCommand.php` (command, batch query)

**Analog:** `app/Console/Commands/DispatchPendingPostPublishesCommand.php` — this command already implements the EXACT stale-lease query the watchdog needs.

**Signature/description pattern** (lines 14–18):
```php
class DispatchPendingPostPublishesCommand extends Command
{
    protected $signature = 'postsyncer:dispatch-pending-publishes {--limit=100 : Maximum queued posts to enqueue}';
    protected $description = 'Enqueue PostSyncer publishes that were committed without a job';
```

**Stale-lease detection query** (lines 26–57) — reuse nearly verbatim:
```php
$staleAt = now()->subSeconds(PublishPostJob::LEASE_SECONDS);
Post::query()
    ->where(function ($query) use ($staleAt): void {
        $query->where(function ($query) use ($staleAt): void {
            $query->where('publish_state', 'queued')
                ->where(function ($query) use ($staleAt): void {
                    $query->whereNull('publish_claimed_at')
                        ->orWhere('publish_claimed_at', '<=', $staleAt);
                });
        })->orWhere(function ($query) use ($staleAt): void {
            $query->where('publish_state', 'running')
                ->where(function ($query) use ($staleAt): void {
                    $query->whereNull('publish_claimed_at')
                        ->orWhere('publish_claimed_at', '<=', $staleAt);
                });
        });
    })
```
(Read-only variant for the watchdog — no locking/recovery writes; the watchdog only observes and alerts.)

**Command output + exit-code pattern** (lines 145–147):
```php
$this->info("Dispatched {$dispatched} pending PostSyncer publish(es).");
return self::SUCCESS;
```

**`report()` on caught exceptions** (lines 127–129): `report($exception);` — the codebase's standard path; Sentry hooks this (D-09).

**Scheduler registration** (`routes/console.php` lines 46–48):
```php
Schedule::command('postsyncer:dispatch-pending-publishes')
    ->everyMinute()
    ->withoutOverlapping(2);
```
Watchdog + prune join this family with `withoutOverlapping(2)` (prune: `daily()`).

**Dedup pattern (D-15):** `Cache::add("watchdog:{type}:{id}:{Y-m-d}", true, ttl)` atomic guard; the outbox's own `logical_key` dedup (below) is the second layer.

---

### `app/Actions/Observability/SendPublishWatchdogAlertAction.php` (action, write/event-driven)

**Analog:** `app/Actions/Notifications/QueueTelegramNotificationAction.php` (whole file)

**Key excerpts** — logical-key dedup (lines 33, 74–84):
```php
$logicalKey = "api-notification:user:{$owner->id}:{$data->key}";
...
$existing = TelegramOutboundMessage::query()
    ->where('telegram_bot_config_id', $config->id)
    ->where('logical_key', $logicalKey)
    ->lockForUpdate()
    ->first();
if ($existing !== null) {
    return ['message' => $existing, 'created' => false];
}
```
Encode D-15 into the key: `"watchdog:stuck:post:{$post->id}:{Y-m-d}"` as the `$data->key`.

**No-bot fallback** (lines 58–70): the action throws `TelegramNotificationsUnavailable` when no connected bot — the watchdog must catch it and fall back to a `publish`-channel log line (never crash the scheduler command).

Alert Action is a thin wrapper: build `CreateTelegramNotificationData` (same shape as the DTO above) and call `QueueTelegramNotificationAction::handle($workspace, $owner, $data)`.

---

### `app/Actions/Observability/BuildPublishObservabilityAction.php` (action, pure read)

**Analog:** `app/Actions/Dashboard/BuildDashboardSummaryAction.php` — consumed via `->handle($workspace)->toArray()` (see DashboardController below). One `handle(Workspace $workspace, string $period): array`-style entry point returning a typed array; live SQL aggregates on `publish_events` per D-19 (no summary table). Success denominator = finalize-stage outcomes per publishable (D-16); retry/lease-expiry counts ride alongside (OBS-05).

---

### `app/Http/Controllers/ObservabilityController.php` (controller, request-response)

**Analog:** `app/Http/Controllers/DashboardController.php` (whole file — 27 lines, the exact thin-controller pattern)

```php
class DashboardController extends Controller
{
    public function home(BuildDashboardSummaryAction $buildDashboardSummaryAction): Response
    {
        $workspace = Workspace::current();

        abort_if($workspace === null, 404, 'No current workspace.');

        return Inertia::render(
            'dashboard/home',
            $buildDashboardSummaryAction->handle($workspace)->toArray(),
        );
    }
}
```
Copy verbatim: method-injected Action, `Workspace::current()` + `abort_if` guard, `Inertia::render('observability/index', $action->handle(...)->toArray())`. Validate the period query param server-side (`in: today,7,30,90`, default `7` per D-18) — Form Request per project rules.

**Route** (`routes/dashboard.php` lines 13–19): add inside the existing group:
```php
Route::middleware(['auth', 'verified', SetCurrentWorkspace::class])
    ->prefix('dashboard')
    ->name('dashboard.')
    ->group(function () {
        Route::get('/', [DashboardController::class, 'home'])->name('home');
        // NEW: Route::get('observability', [ObservabilityController::class, 'index'])->name('observability.index');
```

---

### `config/logging.php` — add `publish` channel (config, streaming)

**Analog:** the existing `stderr` channel (config/logging.php lines 105–114), verbatim shape:
```php
'stderr' => [
    'driver' => 'monolog',
    'level' => env('LOG_LEVEL', 'debug'),
    'handler' => StreamHandler::class,
    'handler_with' => [
        'stream' => 'php://stderr',
    ],
    'formatter' => env('LOG_STDERR_FORMATTER'),
    'processors' => [PsrLogMessageProcessor::class],
],
```
New channel per D-02: same keys, `'formatter' => Monolog\Formatter\JsonFormatter::class` (hardcoded, not env), level from `LOG_PUBLISH_LEVEL`.

---

### `bootstrap/app.php` — Sentry hook (bootstrap)

**Analog:** the existing `withExceptions()` closure (bootstrap/app.php lines 54–58, already read in research):
```php
->withExceptions(function (Exceptions $exceptions): void {
    $exceptions->shouldRenderJsonWhen(
        fn (Request $request) => $request->is('api/*') || $request->is('mcp') || $request->expectsJson(),
    );
})
```
Change is one line inside this closure: `\Sentry\Laravel\Integration::handles($exceptions);` (D-09). Publish context attached via `Sentry\configureScope()->setTag()/setContext()` from the recorder/helper with the same ids as the log channel (D-10). Do NOT sprinkle `Sentry::captureException()` in domain code — publish jobs already `report($exception)` in `failed()`.

---

### `resources/js/pages/observability/index.tsx` (component, request-response)

**Analog:** `resources/js/pages/dashboard/home.tsx` (whole file)

Key patterns to copy:
- Props typing: local `type PageProps = {...}` mirroring the Action's `toArray()` shape (lines 19–41).
- Inertia imports: `import { Head, Link } from '@inertiajs/react';` + route helper imports from `@routes/...` (lines 1–6).
- Layout export (lines 214–216):
```tsx
DashboardHome.layout = {
    breadcrumbs: [{ title: 'Dashboard', href: home() }],
};
```
- Stat tiles: the `dash-card` / `stats` / `stat` CSS-class structure (lines 79–94) — the compact success-rate stat on home adds one more card here; the observability page reuses the same classes plus the shadcn ui kit (`resources/js/components/ui/` — card/badge/tabs) for the period selector and counts.
- Chart: prefer hand-styled CSS/Tailwind bars (no chart dep exists in the project); if recharts, gate behind a human-verify checkpoint.

---

### Tests

**Analog:** `tests/Feature/Console/ReconcilePostPublishCommandTest.php` (lines 1–16):
```php
namespace Tests\Feature\Console;

use App\Models\Post;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReconcilePostPublishCommandTest extends TestCase
{
    use RefreshDatabase;
```
Copy: namespaced under `Tests\Feature\Console` / `Tests\Unit\...`, `RefreshDatabase`, `Workspace::factory()->create()` + `Post::factory()->for($workspace)->create(...)` setup, `PostsyncerConfig::write($workspace, [...])` for publish settings. 19 existing command-test precedents in `tests/Feature/Console/`. Use `Log::fake()` for recorder assertions and the array cache store for D-15 dedup tests. A dedicated Pest test is required for every new Action (CLAUDE.md rule 1).

---

## Shared Patterns

### Thin controllers → DTO → one Action
**Source:** `app/Http/Controllers/DashboardController.php`, CLAUDE.md rule 1/2
**Apply to:** ObservabilityController, both Observability Actions. Read-only aggregation lives in an Action (not the controller); no business logic in `PublishEvent` model beyond relations/casts.

### `report($exception)` as the error path
**Source:** `app/Console/Commands/DispatchPendingPostPublishesCommand.php` lines 127–129, `app/Jobs/PublishPostJob.php` `failed()`
**Apply to:** watchdog, recorder catch-blocks. Never call Sentry directly; the integration hooks the handler (D-09/D-11).

### Scheduler family cadence
**Source:** `routes/console.php` lines 46–48
**Apply to:** watchdog + prune commands: `->everyMinute()->withoutOverlapping(2)` (watchdog), `->daily()` (prune).

### Daily dedup key
**Source:** `QueueTelegramNotificationAction` `logical_key` (line 33) + `Cache::add` (research Pattern 4)
**Apply to:** watchdog alert send — both a `Cache::add("watchdog:{type}:{id}:{Y-m-d}")` atomic guard and an outbox logical_key encoding content+date.

### Append-only model + migration pair
**Source:** `StatusTransition` model + `create_status_transitions_table` migration
**Apply to:** `PublishEvent` + `create_publish_events_table` — `UPDATED_AT = null`, `timestampTz('created_at')->useCurrent()`, morph relation, docblock documenting append-only contract.

### Explicit workspace_id on worker-context writes
**Source:** `app/Concerns/BelongsToWorkspace.php` lines 35–39 (creating hook depends on HTTP singleton)
**Apply to:** `PublishEventRecorder` — always set `workspace_id` explicitly; trait used only for dashboard-read scoping.

## No Analog Found

| File | Role | Data Flow | Reason |
|------|------|-----------|--------|
| Sentry `configureScope` publish-context helper | support | — | No error tracker exists in the codebase yet; use RESEARCH.md Pattern 3 (official SDK docs) |
| `publish` JSON log channel consumers | config | streaming | Channel itself copies the `stderr` config; no JSON-per-line domain channel exists yet |

## Metadata

**Analog search scope:** `app/Actions`, `app/Console/Commands`, `app/Data`, `app/Models`, `app/Concerns`, `app/Http/Controllers`, `app/Providers`, `config`, `database/migrations`, `resources/js/pages`, `routes`, `tests/Feature/Console`
**Files read in full or part:** 14
**Pattern extraction date:** 2026-11-22

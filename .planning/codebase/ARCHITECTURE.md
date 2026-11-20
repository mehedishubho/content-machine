---
last_mapped_commit: effec39acc3dc5c3680e91bdd23476a750c15c41
last_mapped_at: 2026-11-21
---
<!-- refreshed: 2026-11-21 -->
# Architecture

**Analysis Date:** 2026-11-21

## System Overview

```text
┌──────────────────────────────────────────────────────────────────────┐
│                         HTTP / Client Layer                          │
│  Blade (public) │ Inertia+React 19 dashboard │ API v1 │ MCP │ Webhooks│
│  `routes/web.php`  `routes/dashboard.php`  `routes/api.php`          │
│  `routes/settings.php` `routes/posts.php` `routes/videos.php`        │
│  `routes/media.php` `routes/calendar.php` `routes/scratchpad.php`    │
│  `routes/mcp.php`                                                    │
└────────┬────────────────────┬──────────────────┬─────────────────────┘
         ▼                    ▼                  ▼
┌──────────────────────────────────────────────────────────────────────┐
│                      HTTP Adapter Layer (thin)                       │
│  `app/Http/Controllers/**`   `app/Http/Requests/**` (FormRequests)   │
│  `app/Http/Middleware/SetCurrentWorkspace.php` (workspace scoping)   │
│  `app/Http/Middleware/AuthenticateWorkspaceToken.php` (API tokens)   │
└────────┬─────────────────────────────────────────────────────────────┘
         ▼  DTOs (`app/Data/{Domain}/**Data.php`)
┌──────────────────────────────────────────────────────────────────────┐
│                       Action Layer (writes)                          │
│  `app/Actions/{Domain}/{Verb}{Noun}Action.php`                       │
│  Domains: AiProviders, ApiTokens, Calendar, Dashboard, Fortify,      │
│  Ideas, Ids, Media, Notifications, Pexels, Posts, Postsyncer,        │
│  Scratchpad, Socialreins, Teams, Telegram, Users, Videos             │
└────────┬────────────────────────┬────────────────────────────────────┘
         ▼                        ▼
┌──────────────────────┐  ┌───────────────────────────────────────────┐
│  Domain Models (thin)│  │  Queued Jobs (DB queue)                   │
│  `app/Models/*.php`  │  │  `app/Jobs/*.php`                         │
│  Concerns:           │  │  PublishPostJob, PublishVideoJob,         │
│  `app/Concerns/`     │  │  PublishSocialreinsJob, Telegram jobs,    │
│                      │  │  Scratchpad capture jobs                  │
└────────┬─────────────┘  └──────────────────┬────────────────────────┘
         ▼                                   ▼
┌──────────────────────────────────────────────────────────────────────┐
│              Integration Boundary (interfaces + HTTP clients)        │
│  Contracts: `app/Contracts/PexelsProvider.php`                       │
│  Support clients: `app/Support/AiProviders/*Contract.php` (bound in  │
│  `app/Providers/AppServiceProvider.php`), `app/Support/Telegram/`,   │
│  `app/Support/Postsyncer/PostsyncerClient.php`, `app/Support/Socialreins/`,│
│  `app/Support/Pexels/`, `app/Support/LinkResolution/`,               │
│  `app/Support/GoogleDrive/`                                          │
└────────┬─────────────────────────────────────────────────────────────┘
         ▼
┌──────────────────────────────────────────────────────────────────────┐
│  PostgreSQL (source of truth) │ Files/Drive │ External: Telegram,    │
│  PostSyncer, SocialReins, OpenAI, Pexels, Google Drive               │
└──────────────────────────────────────────────────────────────────────┘
```

## Component Responsibilities

| Component | Responsibility | File |
|-----------|----------------|------|
| Controllers | HTTP glue only: validate, build DTO, call one Action, return Inertia/Resource | `app/Http/Controllers/**` |
| Form Requests | Validation at the boundary | `app/Http/Requests/{Domain}/**` |
| DTOs | Typed readonly data crossing into Actions | `app/Data/{Domain}/**Data.php` |
| Actions | All business write operations, one class per operation | `app/Actions/{Domain}/**Action.php` |
| Models | Eloquent: relations, casts, scopes, accessors only | `app/Models/*.php` |
| Concerns | Shared traits (`BelongsToWorkspace`, `RecordsHistory`, `ResolvesByHumanId`) | `app/Concerns/` |
| Jobs | Queued async work (publish, transcription, Telegram processing) | `app/Jobs/*.php` |
| Support | Integration clients and helpers (Telegram, PostSyncer, AI, Pexels, MCP, media) | `app/Support/**` |
| Contracts | Interfaces for external systems (dependency-inversion boundary) | `app/Contracts/`, `app/Support/**/*Contract.php` |
| Providers | Service bindings (`AppServiceProvider`), Fortify config | `app/Providers/` |
| Listeners | Auth-event side effects (team provisioning, invite auto-accept); auto-discovered via `handle()` type-hints | `app/Listeners/` |
| Console commands | Scheduled dispatch/recovery/reconcile operators | `app/Console/Commands/` |
| Inertia pages | React 19 + TS dashboard pages | `resources/js/pages/**` |
| Shared singletons | `CurrentWorkspace`, `CurrentApiToken` request context | `app/Support/CurrentWorkspace.php`, `app/Support/CurrentApiToken.php` |

## Pattern Overview

**Overall:** Layered Laravel with a strict Action pattern (transaction-script style writes), DTOs at every boundary, and interface-based dependency inversion for external integrations. Multi-tenant by workspace.

**Key Characteristics:**
- One Action class per write operation (`app/Actions/{Domain}/{Verb}{Noun}Action.php`); no laravel-actions package — plain constructor-injected classes with a single `handle(...): mixed` entry point.
- Plain PHP 8.3 `readonly` DTOs (`app/Data/`) with named constructors `::fromRequest()` / `::fromArray()`. No spatie/laravel-data.
- Controllers are thin by rule: any business logic in a controller is treated as a bug (see `CLAUDE.md`).
- Workspace tenancy enforced by `app/Http/Middleware/SetCurrentWorkspace.php` on dashboard routes and `app/Http/Middleware/AuthenticateWorkspaceToken.php` on API/MCP routes; the `CurrentWorkspace` singleton (`app/Support/CurrentWorkspace.php`) carries the active workspace.
- History/audit via `app/Concerns/RecordsHistory.php` (produces `StatusTransition`, `ContentVersion` records, see `app/Models/StatusTransition.php`, `app/Models/ContentVersion.php`).
- External systems always reached through interfaces bound in `app/Providers/AppServiceProvider.php` (e.g. `TelegramClientContract` → `HttpTelegramClient`, AI clients → HTTP implementations, `PexelsProvider` → `HttpPexelsProvider`).

## Layers

**HTTP / Frontend layer:**
- Purpose: public Blade pages, Inertia dashboard, JSON API v1, MCP tools, incoming webhooks
- Location: `routes/*.php`, `resources/js/`, `resources/views/`
- Depends on: controllers, Inertia middleware (`app/Http/Middleware/HandleInertiaRequests.php`)
- Used by: browsers, workspace-token API clients, Telegram/SocialReins webhooks

**Adapter layer (Controllers/FormRequests/Resources):**
- Purpose: translate HTTP ↔ domain
- Location: `app/Http/`
- Depends on: DTOs (`app/Data/`), Actions (`app/Actions/`), `app/Support/Api/IncludeFields.php`
- Used by: routes

**Domain layer (Actions/Models/Concerns):**
- Purpose: business operations and persistence
- Location: `app/Actions/`, `app/Models/`, `app/Concerns/`
- Depends on: Support clients via contracts, DTOs
- Used by: controllers and jobs

**Integration layer (Support clients):**
- Purpose: talk to Telegram, PostSyncer, SocialReins, OpenAI, Pexels, Google Drive
- Location: `app/Support/` (contracts alongside implementations, e.g. `app/Support/Telegram/TelegramClientContract.php`), `app/Contracts/PexelsProvider.php`
- Depends on: config (`config/*.php`), HTTP client
- Used by: Actions and Jobs

**Background work layer:**
- Purpose: async capture/transcription/publishing via Laravel database queue
- Location: `app/Jobs/`
- Depends on: Actions (`EnqueuePostPublishAction` etc. enqueue; `PublishPostAction`/`PublishVideoAction` execute)
- Used by: queue workers (`queue`, `scratchpad`, `publishing` — see `routes/console.php`)

## Data Flow

### Primary dashboard write path

1. Request hits a dashboard route behind `auth`+`verified`+`SetCurrentWorkspace` (`routes/dashboard.php`)
2. FormRequest validates (`app/Http/Requests/{Domain}/`)
3. Controller builds a DTO (`app/Data/{Domain}/{Name}Data.php`)
4. Controller calls exactly one Action (`app/Actions/{Domain}/...Action.php`)
5. Action mutates models, records history (`app/Concerns/RecordsHistory.php`), returns result
6. Controller returns an Inertia response or redirect; React page in `resources/js/pages/` renders

### Publish path (async, externally gated)

1. `app/Actions/Postsyncer/EnqueuePostPublishAction.php` (or `app/Actions/Socialreins/EnqueueSocialreinsPublishAction.php`) plans publish groups and enqueues `app/Jobs/PublishPostJob.php` / `app/Jobs/PublishVideoJob.php`
2. A dedicated `publishing` queue worker runs the job; `PublishPostAction`/`PublishVideoAction` register media by URL (signed URLs served by `app/Http/Controllers/PublishMediaController.php`) and checkpoint progress so retries skip completed groups (`app/Support/Postsyncer/LegacyPublishProgress.php`)
3. Scheduled reconciliation: `postsyncer:sync-scheduled` every 5 minutes (`routes/console.php`), dispatch/recovery commands every minute (`app/Console/Commands/DispatchPending*Command.php`, `ReconcilePostPublishCommand.php`)

### Inbound webhook path

1. `routes/web.php`: `POST telegram/webhook/{slug}` → `app/Http/Controllers/Telegram/TelegramWebhookController.php` (no auth/CSRF; request verified against Telegram); `POST socialreins/webhook/{workspace}` → `app/Http/Controllers/Socialreins/` (signature-verified raw body)
2. Updates are persisted then processed asynchronously (`app/Jobs/ProcessTelegramUpdateJob.php`, `app/Actions/Telegram/HandleTelegramUpdateAction.php`, `app/Actions/Socialreins/ApplySocialreinsWebhookAction.php`)

### API / MCP path

1. `/api/v1` routes (`routes/api.php`) authenticate via workspace tokens (`app/Http/Middleware/AuthenticateWorkspaceToken.php`), scoped through `app/Support/CurrentApiToken.php`
2. `/mcp` (`routes/mcp.php`) exposes workspace-token tools via `app/Support/Mcp/McpServer.php`, `McpToolCatalog.php`, `McpToolDispatcher.php`

**State Management:**
- PostgreSQL is the single source of truth; no client-side store beyond Inertia shared props. Active workspace is per-request state held in `app/Support/CurrentWorkspace.php` (singleton, set by middleware).

## Key Abstractions

**Action:**
- Purpose: one business write operation
- Examples: `app/Actions/Teams/CreateTeamAction.php`, `app/Actions/Posts/CreatePostAction.php`, `app/Actions/Telegram/HandleTelegramUpdateAction.php`
- Pattern: plain class, constructor injection, single `handle(): mixed`

**DTO:**
- Purpose: typed boundary data
- Examples: `app/Data/Posts/UpdatePostData.php`, `app/Data/Telegram/ConnectTelegramBotData.php`
- Pattern: `readonly` promoted properties, `::fromRequest(FormRequest)` / `::fromArray(array)` named constructors

**Integration contract:**
- Purpose: swappable external-system client
- Examples: `app/Support/Telegram/TelegramClientContract.php` → `app/Support/Telegram/HttpTelegramClient.php`; `app/Support/AiProviders/AiTranscriptionClientContract.php` → `app/Support/AiProviders/OpenAiTranscriptionClient.php`; `app/Contracts/PexelsProvider.php` → `app/Support/Pexels/HttpPexelsProvider.php`; `app/Support/LinkResolution/LinkResolverContract.php` → `app/Support/LinkResolution/ProcessLinkResolver.php`
- Pattern: bound in `app/Providers/AppServiceProvider.php` (lines 36–44+), injected by interface

**Workspace-scoped model:**
- Purpose: tenancy at the model level
- Examples: `app/Models/Post.php`, `app/Models/MediaAsset.php` using `app/Concerns/BelongsToWorkspace.php`

**History recording:**
- Purpose: audit trail of status changes and content versions
- Examples: `app/Concerns/RecordsHistory.php` → `app/Models/StatusTransition.php`, `app/Models/ContentVersion.php`

## Entry Points

**Public web (`routes/web.php`):**
- Landing page, team-invitation accept flow (`app/Http/Controllers/TeamInvitationController.php`), Telegram webhook, SocialReins webhook, signed publish-media URLs (`app/Http/Controllers/PublishMediaController.php`), passkey well-known endpoint

**Dashboard (`routes/dashboard.php`):**
- Everything behind `auth`+`verified`+`SetCurrentWorkspace`, prefixed `/dashboard` (with permanent redirects keeping `/dashboard/posts|videos|scratchpad` bookmarks working)

**Feature routes:**
- `routes/posts.php`, `routes/videos.php`, `routes/scratchpad.php`, `routes/media.php`, `routes/calendar.php`, `routes/settings.php` (workspace settings, first-class at `/settings`)

**API & MCP:**
- `routes/api.php` (`/api/v1`, workspace tokens), `routes/mcp.php` (`/mcp` Streamable HTTP tools)

**Console / scheduler (`routes/console.php`):**
- Scheduled commands every 1–5 min: `postsyncer:sync-scheduled`, `telegram:dispatch-pending-*`, `telegram:recover-connection-operations`, `cm:requeue-legacy-media-jobs`; supervised workers `queue`, `scratchpad`, `publishing`, `scheduler`; operator commands in `app/Console/Commands/` (`DoctorCommand.php`, `EnsureAdminUserCommand.php`, `MoveOffPostsyncerCommand.php`, `CleanupOrphanMediaCommand.php`, `DeployCommand.php`, …)

**Framework bootstrap:**
- `bootstrap/app.php` (Laravel 13 style), `artisan`, `public/index.php`

## Architectural Constraints

- **Multi-tenancy:** every query/write must stay inside the session's or token's workspace; `Team` has many `Workspace`s (`app/Models/Team.php`, `app/Models/Workspace.php`); API tokens belong to exactly one workspace (`app/Models/WorkspaceApiToken.php`)
- **Write-path rule:** all writes go through Actions; no business logic in controllers or models (`CLAUDE.md` engineering rules)
- **Dependency inversion:** external integrations must be injected via contracts bound in `app/Providers/AppServiceProvider.php`
- **Listener registration:** `app/Listeners/*` are auto-discovered from each `handle()` type-hint — do not also register manually in a ServiceProvider or they fire twice (`CLAUDE.md`)
- **Queue discipline:** publish work never runs inside a dashboard request; progress checkpoints before external creates so retries are safe; uncertain external results require operator reconciliation
- **Migrations:** additive-only schema changes in `database/migrations/` (73 migrations)
- **Secrets separation:** app `.env` vs operational `.secrets/<service>.env` (gitignored), see `docs/adr/0002-operational-secrets-outside-app-env.md`

## Anti-Patterns

### Business logic in controllers/models

**What happens:** a conditional or non-trivial query creeps into a controller method or an Eloquent model
**Why it's wrong:** violates the Action pattern; `CLAUDE.md` treats it as a bug, not style
**Do this instead:** extract to `app/Actions/{Domain}/{Verb}{Noun}Action.php` with a DTO from `app/Data/{Domain}/`

### Direct external-system calls

**What happens:** instantiating `PostsyncerClient`/`HttpTelegramClient` directly in an Action
**Why it's wrong:** breaks dependency inversion and testability
**Do this instead:** inject the contract (`TelegramClientContract`, `AiTranscriptionClientContract`, `PexelsProvider`) bound in `app/Providers/AppServiceProvider.php`

### Manual listener registration

**What happens:** registering `app/Listeners/` classes in a ServiceProvider in addition to auto-discovery
**Why it's wrong:** listeners fire twice
**Do this instead:** rely on Laravel event auto-discovery from the `handle()` type-hint

## Error Handling

**Strategy:** typed domain exceptions for expected failures (e.g. `app/Actions/Notifications/TelegramNotificationKeyConflict.php`, `TelegramNotificationsUnavailable.php`, `app/Support/Postsyncer/PostsyncerException.php`); queued jobs rely on retry + checkpoint + operator reconciliation for uncertain external outcomes.

**Patterns:**
- Publish jobs checkpoint progress before each external create (`app/Support/Postsyncer/LegacyPublishProgress.php`) so retries skip completed groups
- Webhook verification enforced in controllers (`app/Http/Controllers/Telegram/TelegramWebhookController.php`, `app/Http/Controllers/Socialreins/`)
- Validation centralized in FormRequests; typed results objects from integration clients (`app/Support/Telegram/TelegramApiResult.php`, `app/Support/AiProviders/AiCompletionResult.php`)

## Cross-Cutting Concerns

**Logging:** Laravel default logging; queue/scheduler visibility via dedicated worker commands in `routes/console.php`
**Validation:** Form Requests per domain (`app/Http/Requests/{Domain}/`) plus custom rules in `app/Rules/`
**Authentication:** Laravel Fortify (`app/Providers/FortifyServiceProvider.php`, `app/Actions/Fortify/`), passkeys and two-factor in Settings; workspace API tokens for `/api/v1` and `/mcp`; signature/slug verification for inbound webhooks
**Authorization:** policies/gates on models; owner-or-admin checks on PostSyncer account-mapping repair (recent commit history)
**History/audit:** `app/Concerns/RecordsHistory.php`
**Localization/data:** `app/Support/Content/` (caption normalization, publish groups, presence flags); frontend equivalents in `resources/js/lib/` (`lang-meta.ts`, `platform-meta.ts`)

## Reference Docs

- `docs/architecture/overview.md` — canonical in-repo overview
- `docs/architecture/posts-videos-api.md` — posts/videos API
- `docs/adr/0001-webhook-not-polling.md`, `docs/adr/0002-operational-secrets-outside-app-env.md`

---

*Architecture analysis: 2026-11-21*

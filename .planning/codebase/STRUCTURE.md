---
last_mapped_commit: effec39acc3dc5c3680e91bdd23476a750c15c41
last_mapped_at: 2026-11-21
---
# Codebase Structure

**Analysis Date:** 2026-11-21

## Directory Layout

```text
content-machine/
├── app/
│   ├── Actions/          # Business write operations, one per domain subfolder
│   ├── Concerns/         # Shared traits (BelongsToWorkspace, RecordsHistory, ...)
│   ├── Console/Commands/ # Scheduled + operator Artisan commands
│   ├── Contracts/        # Interfaces for external integrations
│   ├── Data/             # Readonly DTOs, per domain subfolder
│   ├── Http/
│   │   ├── Controllers/  # Thin Inertia/API glue, per domain subfolder
│   │   ├── Middleware/   # SetCurrentWorkspace, AuthenticateWorkspaceToken, Inertia, ...
│   │   └── Requests/     # Form Requests, per domain subfolder
│   ├── Jobs/             # Queued jobs (publish, transcription, Telegram)
│   ├── Listeners/        # Auth-event side effects (auto-discovered)
│   ├── Models/           # Thin Eloquent models + Models/Concerns/
│   ├── Providers/        # AppServiceProvider, FortifyServiceProvider
│   ├── Rules/            # Custom validation rules
│   └── Support/          # Integration clients + helpers (Telegram, Postsyncer,
│                         #   Socialreins, AiProviders, Pexels, GoogleDrive, Mcp, ...)
├── bootstrap/            # app.php, cache
├── config/               # Laravel config
├── database/
│   ├── factories/        # Model factories (Pest/Laravel)
│   ├── migrations/       # Additive schema changes (~73)
│   └── seeders/
├── deploy/docker/        # Deployment assets
├── docs/                 # architecture/, adr/, guides/, operations/, getting-started/
├── public/               # Web root (index.php, images/)
├── resources/
│   ├── css/              # Tailwind 4 entry
│   ├── js/               # Inertia frontend (see layout below)
│   ├── presentation-library/
│   └── views/            # Blade (public pages, mail)
├── routes/               # web, dashboard, settings, api, mcp, posts, videos,
│                         #   media, calendar, scratchpad, console
├── storage/              # Laravel storage
├── tests/                # Pest: Unit/, Feature/, Fixtures/, Support/
├── compose.yaml / compose.prod.yaml  # Local/prod Docker
├── composer.json         # PHP deps (Laravel 13, Fortify)
├── package.json          # Frontend deps (React 19, Inertia 3, TS)
├── vite.config.ts        # Vite build
├── pint.json / phpstan.neon / eslint.config.js / tsconfig.json
├── phpunit.xml           # Pest config (dedicated PG test DB)
└── CLAUDE.md             # Strict engineering rules (Action pattern, DTOs, SOLID)
```

## Directory Purposes

**`app/Actions/{Domain}/`:**
- Purpose: every write operation, one class per operation
- Contains: `{Verb}{Noun}Action.php` classes; 18 domains (AiProviders, ApiTokens, Calendar, Dashboard, Fortify, Ideas, Ids, Media, Notifications, Pexels, Posts, Postsyncer, Scratchpad, Socialreins, Teams, Telegram, Users, Videos)
- Key files: `app/Actions/Posts/CreatePostAction.php`, `app/Actions/Postsyncer/PublishPostAction.php`, `app/Actions/Telegram/HandleTelegramUpdateAction.php`

**`app/Data/{Domain}/`:**
- Purpose: readonly DTOs crossing into Actions
- Contains: `{Name}Data.php` with `::fromRequest()` / `::fromArray()` named constructors
- Key files: `app/Data/Posts/UpdatePostData.php`, `app/Data/Telegram/ConnectTelegramBotData.php`

**`app/Support/`:**
- Purpose: integration clients and framework-adjacent helpers, grouped by concern
- Contains: `Telegram/` (client + contract + result objects), `Postsyncer/` (client, planners, media URL resolver, progress), `AiProviders/` (contracts + HTTP/OpenAI clients), `Socialreins/`, `Pexels/`, `GoogleDrive/`, `LinkResolution/`, `Mcp/` (server, tool catalog/dispatcher), `Media/`, `Content/`, `Calendar/`, `Videos/`, `Posts/`, plus singletons `CurrentWorkspace.php`, `CurrentApiToken.php`
- Contracts also live here (`*Contract.php`) alongside `app/Contracts/` (`PexelsProvider.php`)

**`app/Http/`:**
- Purpose: HTTP adapters only
- Contains: `Controllers/{Domain}/` (Api, Mcp, AiProviders, ApiTokens, Calendar, Ideas, Media, Posts, Scratchpad, Settings, Socialreins, Telegram, Videos), `Requests/{Domain}/`, `Middleware/`

**`app/Models/`:**
- Purpose: thin Eloquent models; tenant roots `Team`, `Workspace`; content: `Post`, `Video`, `Idea`, `ScratchpadEntry`, `MediaAsset`, `Attachment`; Telegram: `TelegramBotConfig`, `TelegramUpdate`, `TelegramOutboundMessage`, etc.
- Key files: `app/Models/Post.php`, `app/Models/Workspace.php`, `app/Models/StatusTransition.php`, `app/Models/ContentVersion.php`

**`app/Jobs/`:**
- Purpose: queued work on the database queue (queues: `queue`, `scratchpad`, `publishing`)
- Key files: `app/Jobs/PublishPostJob.php`, `app/Jobs/PublishVideoJob.php`, `app/Jobs/PublishSocialreinsJob.php`, `app/Jobs/ProcessTelegramUpdateJob.php`, `app/Jobs/TranscribeVoiceNoteJob.php`

**`routes/`:**
- Purpose: split by surface instead of one giant file
- Files: `web.php` (public + webhooks), `dashboard.php` (`/dashboard`, auth+verified+SetCurrentWorkspace), `settings.php` (workspace settings at `/settings`), `posts.php`, `videos.php`, `scratchpad.php`, `media.php`, `calendar.php`, `api.php` (`/api/v1`), `mcp.php` (`/mcp`), `console.php` (scheduler + worker command registration)

**`resources/js/`:**
- Purpose: Inertia 3 + React 19 + TypeScript frontend
- Layout: `app.tsx` (entry), `pages/` (Inertia pages per domain: ai-providers, auth, calendar, dashboard, ideas, invitations, media, posts, scratchpad, settings, telegram, videos, workspace-settings), `components/` (shared UI incl. `ui/`, `studio/`, `media/`, `content/`, `workspace-settings/`), `layouts/`, `hooks/` (`use-*.ts`), `lib/` (pure helpers), `types/`

**`tests/`:**
- Purpose: Pest tests — `Unit/` (incl. `Unit/Actions/{Domain}/`), `Feature/`, `Fixtures/`, `Support/`, `Pest.php`, `TestCase.php`

**`docs/`:**
- Purpose: human docs; `architecture/overview.md` is canonical, `adr/` records decisions

**`.secrets/`:**
- Purpose: operational/infra credentials per service, gitignored; separate from app `.env` (see `.secrets/README.md`)

## Key File Locations

**Entry Points:**
- `public/index.php`: HTTP entry (framework)
- `resources/js/app.tsx`: frontend entry
- `artisan`: CLI entry
- `routes/console.php`: scheduler + supervised worker commands

**Configuration:**
- `composer.json`, `package.json`, `vite.config.ts`, `tsconfig.json`, `eslint.config.js`
- `pint.json` (code style), `phpstan.neon` (Larastan level 7), `phpunit.xml` (Pest, dedicated PG test DB)
- `compose.yaml` / `compose.prod.yaml` (local/prod Docker), `deploy/docker/`
- `config/*.php` (Laravel + app config)

**Core Logic:**
- Writes: `app/Actions/{Domain}/**Action.php`
- Boundaries: `app/Data/{Domain}/**Data.php`, `app/Http/Requests/{Domain}/`
- Integrations: `app/Support/**`, contracts bound in `app/Providers/AppServiceProvider.php`

**Testing:**
- `tests/Unit/Actions/{Domain}/` — one Pest test per Action (rule)
- `tests/Feature/` — HTTP/queue flows
- `database/factories/` — model factories

## Naming Conventions

**Files (PHP):**
- Actions: `{Verb}{Noun}Action.php` (e.g. `InviteTeamMemberAction.php`)
- DTOs: `{Name}Data.php` (e.g. `CreateTeamData.php`)
- Jobs: `{Verb}{Noun}Job.php` (e.g. `PublishPostJob.php`)
- Form Requests: `{Purpose}Request.php` / descriptive names per `app/Http/Requests/{Domain}/`
- Commands: kebab-case signature `cm:*`, `telegram:*`, `postsyncer:*`; classes `{Name}Command.php`
- Contracts: `*Contract.php` (Support) or plain interface names (`app/Contracts/PexelsProvider.php`)

**Files (frontend):**
- Pages/components: kebab-case `.tsx` (e.g. `scratchpad-voice-recorder.tsx`)
- Hooks: `use-{thing}.ts(x)` in `resources/js/hooks/`
- Directories: lowercase domain folders (`ai-providers`, `workspace-settings`)

**Directories:**
- Domain-grouped PascalCase for PHP (`app/Actions/Postsyncer/`), lowercase for frontend (`resources/js/pages/posts/`)

## Where to Add New Code

**New write operation (business feature):**
- Action: `app/Actions/{Domain}/{Verb}{Noun}Action.php`
- DTO: `app/Data/{Domain}/{Name}Data.php`
- Form Request: `app/Http/Requests/{Domain}/`
- Controller method (thin): `app/Http/Controllers/{Domain}/`
- Route: `routes/dashboard.php` (auth pages), `routes/api.php` (token clients), or the matching feature route file
- Tests: `tests/Unit/Actions/{Domain}/`

**New external integration:**
- Contract: `app/Contracts/{Name}Provider.php` or `app/Support/{Service}/{Name}Contract.php`
- HTTP implementation: `app/Support/{Service}/Http{Name}Client.php`
- Bind in `app/Providers/AppServiceProvider.php`; inject the interface, never the concrete

**New queued work:**
- Job: `app/Jobs/{Verb}{Noun}Job.php`; enqueue from an Action; register dispatch/worker commands in `app/Console/Commands/` + `routes/console.php` if it needs recovery

**New Eloquent model:**
- Model: `app/Models/{Name}.php` (thin; traits from `app/Concerns/`)
- Migration: `database/migrations/` (additive only)
- Factory: `database/factories/{Name}Factory.php`

**New dashboard page:**
- Page: `resources/js/pages/{domain}/`
- Shared UI: `resources/js/components/`; helpers: `resources/js/lib/`; types: `resources/js/types/`
- Route + controller return Inertia response

**New validation rule:** `app/Rules/`
**New shared trait:** `app/Concerns/`

## Special Directories

**`.planning/`:**
- Purpose: GSD planning artifacts (this file lives in `.planning/codebase/`)
- Generated: partially; Committed: yes (project convention)

**`.secrets/`:**
- Purpose: operational credentials (Railway tokens etc.); never in app `.env`
- Generated: no; Committed: no (gitignored)

**`storage/`, `bootstrap/cache/`, `node_modules/`, `vendor/`:**
- Generated runtime/build artifacts; committed: no

**`resources/presentation-library/`:**
- Presentation assets for video decks; synced via `app/Actions/Media/SyncPresentationLibraryAction.php`

**`docs/superpowers/`:**
- Workflow/process docs, not product architecture

---

*Structure analysis: 2026-11-21*

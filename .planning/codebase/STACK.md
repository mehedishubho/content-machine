---
last_mapped_commit: effec39acc3dc5c3680e91bdd23476a750c15c41
last_mapped_at: 2026-11-21
---
# Technology Stack

**Analysis Date:** 2026-11-21

## Languages

**Primary:**
- PHP ^8.3 (uses PHP 8.3+ syntax: `readonly` classes, constructor promotion) — backend, `app/`, `routes/`, `database/`
- TypeScript ^5.7 — frontend, `resources/` (Inertia React pages)

**Secondary:**
- SQL (PostgreSQL migrations) — `database/migrations/`

## Runtime

**Environment:**
- PHP 8.3+ (serversideup/php Docker image, see `deploy/Dockerfile`)
- Node.js for the Vite build (devDependencies pinned; see `package.json`)
- Single-operator self-hosted app; nginx fronts PHP inside the container (Railway)

**Package Managers:**
- Composer (PHP) — lockfile present (`composer.lock`)
- npm (JS) — lockfile present (`package-lock.json`); `pnpm-workspace.yaml` exists but scripts use npm

## Frameworks

**Core:**
- Laravel ^13.17 (`composer.json`) — HTTP, queues, scheduler, Eloquent
- Inertia ^3.0 (`inertiajs/inertia-laravel`) — `/dashboard/*` SPA bridge
- React ^19.3 + react-dom ^19.3 — dashboard UI (`resources/`)
- Tailwind CSS ^4.0 via `@tailwindcss/vite` — styling
- Laravel Fortify ^1.37 — authentication (login/register/2FA backend), `config/fortify.php`, `app/Providers/FortifyServiceProvider.php`
- Laravel Wayfinder ^0.1 — typed route helpers for the frontend (`@laravel/vite-plugin-wayfinder`)
- Laravel Chisel ^0.1 — included dependency (Laravel tooling)

**Testing:**
- Pest ^5.1 with pest-plugin-laravel ^5.0 and pest-plugin-drift ^5.0 — `tests/`
- Mockery ^1.6, Faker ^1.24

**Static Analysis / Lint:**
- Larastan ^3.9 (PHPStan, level 7) — `phpstan.neon`
- Laravel Pint ^1.27 — `pint.json`
- ESLint ^9 (flat config, `eslint.config.js`), Prettier ^3.9 (`@stylistic/eslint-plugin`, react/react-hooks/import plugins)
- `babel-plugin-react-compiler` ^1.0 — React Compiler enabled in build

**Build/Dev:**
- Vite ^8.3.6 + laravel-vite-plugin ^3.2 — asset build (`vite.config.ts`)
- Laravel Sail ^1.53 — local Docker dev (`compose.yaml`, `compose.prod.yaml`)
- Laravel Pail ^1.2 — local log tailing

## Key Dependencies

**Critical:**
- `laravel/framework` ^13.17 — everything
- `inertiajs/inertia-laravel` ^3.0 — dashboard rendering
- `laravel/fortify` ^1.37 — auth backend (no Breeze/Jetstream)
- `laravel/wayfinder` + `@laravel/vite-plugin-wayfinder` — route type generation for TS

**Infrastructure:**
- Database queue driver is the default (`config/queue.php`: `QUEUE_CONNECTION` defaults to `database`) — no Redis required
- Database cache/store default (`config/cache.php`: `CACHE_STORE` defaults to `database`)
- Session driver from env (`SESSION_DRIVER`)
- Optional Redis / Memcached / SQS / Beanstalkd connections configured but not required

**No business-logic packages:** the Action/DTO patterns are hand-rolled — no lorisleiva/laravel-actions, no spatie/laravel-data (per `CLAUDE.md` rules).

## Configuration

**Environment:**
- `.env` / `.env.example` — app-level config (see `.env.example` for the documented, commented set)
- `.secrets/<service>.env` — operational/infra credentials (Railway tokens etc.), gitignored, deliberately separate from the app `.env` (see ADR `docs/adr/0002-operational-secrets-outside-app-env.md`)
- Most third-party integration credentials (PostSyncer, SocialReins, Telegram bot tokens, AI provider keys, Google Drive) are stored **per-workspace in the database, encrypted** (`Crypt`), not in env — see `app/Support/Postsyncer/PostsyncerConfig.php`, `app/Support/Socialreins/SocialreinsConfig.php`, `app/Models/AiProviderCredential.php`

**Key env vars (from `.env.example` / configs):**
- App: `APP_KEY`, `APP_URL`, `DISABLE_REGISTRATION` (closes public sign-up), `ADMIN_EMAIL`/`ADMIN_NAME` (used by `cm:ensure-admin`), `REDIRECT_HOME_TO_DASHBOARD`, `PORT`, `AUTORUN_ENABLED` (must be false in prod)
- Database: `DB_CONNECTION` (pgsql in prod), `DB_HOST/PORT/DATABASE/USERNAME/PASSWORD`
- Queues: `QUEUE_CONNECTION` (database default), `POSTSYNCER_QUEUE_RETRY_AFTER`
- Cutover gates: `CM_TELEGRAM_CUTOVER_READY`, `CM_TELEGRAM_OLD_WEB_FLEET_DRAINED`
- Mail: `MAIL_MAILER` (default log), optional `POSTMARK_API_KEY`/`RESEND_API_KEY` (`config/services.php`)
- Cache/session: `CACHE_STORE`, `SESSION_*`

**Build:**
- `vite.config.ts` — Vite + React + Tailwind + Wayfinder + SSR option (`npm run build:ssr`)
- `phpstan.neon`, `pint.json`, `eslint.config.js`, `tsconfig.json`, `phpunit.xml` (Pest)
- Composer scripts: `composer setup` (local), `composer setup:production`, `composer ci:check` (lint + format + types + tests), custom workers (`worker:default`, `worker:scratchpad`, `worker:publish`), `scheduler`

**Custom artisan commands:** `cm:ensure-key`, `cm:ensure-admin`, `cm:deploy`, telegram dispatch/recovery commands (scheduled in `routes/console.php`) — see `app/Console/Commands/`

## Platform Requirements

**Development:**
- PHP 8.3+, Composer, Node, PostgreSQL (dedicated test DB; Pest runs serially — see `docs/getting-started/local.md`)
- `compose.yaml` for Sail-based local dev

**Production:**
- Railway: `deploy/railway.web.json` (web container) + `deploy/railway.worker.json` (queue workers), built from `deploy/Dockerfile`; `main` branch auto-deploys
- Pre-deploy command runs migrations explicitly; `AUTORUN_ENABLED=false` prevents boot-time migrations
- Three queue workers: `default`, `scratchpad` (Telegram media/transcription/generation), `postsyncer` (publishing) — 900s timeout, tries=3

**CI:** GitHub Actions — `.github/workflows/ci.yml` (pint, prettier, eslint, tsc, phpstan, pest) and `.github/workflows/docker.yml`

---

*Stack analysis: 2026-11-21*

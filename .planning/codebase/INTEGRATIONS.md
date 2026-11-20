---
last_mapped_commit: effec39acc3dc5c3680e91bdd23476a750c15c41
last_mapped_at: 2026-11-21
---
# External Integrations

**Analysis Date:** 2026-11-21

## APIs & External Services

**PostSyncer (social publishing, legacy path):**
- Publishes posts/videos to social platforms via PostSyncer API
- Client: `app/Support/Postsyncer/PostsyncerClient.php` (Laravel `Http` facade)
- Config: `app/Support/Postsyncer/PostsyncerConfig.php` — base URLs are hardcoded constants: `https://postsyncer.com/api/v1`, upload at `https://upload.postsyncer.com/api/v1`
- Auth: per-workspace token stored encrypted under `settings.postsyncer` (workspace DB column, via `Crypt`)
- Notable: 500 MB upload limit, rate-limit backoff (429) with a 120s shared wait budget inside the 900s job timeout, media URL resolution via `app/Support/Postsyncer/MediaUrlResolver.php`
- Dedicated queue worker `postsyncer` (see `composer.json` scripts)

**SocialReins ("brain", current publish path):**
- Post-group publishing API v1 with Idempotency-Key on every write
- Client: `app/Support/Socialreins/SocialreinsClient.php`, config `app/Support/Socialreins/SocialreinsConfig.php` — default base `https://brain.socialreins.com/v1` (overridable per workspace)
- Auth: per-workspace, set-limited token under encrypted `settings.socialreins`; per-language token instances (`new SocialreinsClient($config, $language)`)
- Publish jobs: `app/Jobs/PublishSocialreinsJob.php`; payload builder `app/Support/Socialreins/PostGroupPayloadBuilder.php`; webhook verification `app/Support/Socialreins/WebhookSignature.php`
- Per-workspace driver switch `settings.publish_driver` (`postsyncer` default) with `routed_languages` (recent commit #286 routing)

**Telegram Bot API:**
- Bot messaging, media download, webhooks/updates
- Client: `app/Support/Telegram/HttpTelegramClient.php` implementing `app/Support/Telegram/TelegramClientContract.php` (bound in `app/Providers/AppServiceProvider.php`)
- Base: `https://api.telegram.org` (hardcoded); per-workspace bot token from encrypted settings
- Webhook-driven, not polling (ADR `docs/adr/0001-webhook-not-polling.md`); update processing via `app/Jobs/ProcessTelegramUpdateJob.php`
- Scheduled dispatch/recovery commands in `routes/console.php` (`telegram:dispatch-pending-*`, `telegram:recover-connection-operations`)
- 20 MB file download cap; message chunking in `TelegramMessageChunker.php`

**Pexels (stock photo search):**
- Interface: `app/Contracts/PexelsProvider.php`; implementation `app/Support/Pexels/HttpPexelsProvider.php`
- Base: `https://api.pexels.com/v1/search`; API key in `PexelsConfig` (app-side config); 20 MB download cap
- Bound in `app/Providers/AppServiceProvider.php` (dependency-inversion boundary)

**AI providers (OpenAI-compatible, multi-provider fallback chain):**
- Contracts: `app/Support/AiProviders/AiCompletionClientContract.php`, `AiTranscriptionClientContract.php`, `AiVisionCompletionClientContract.php`, `AiProviderVerifierContract.php`
- Implementations: `app/Support/AiProviders/HttpAiCompletionClient.php` (chat + vision), `OpenAiTranscriptionClient.php` (audio transcription), `HttpAiProviderVerifier.php`
- Credentials: stored per-workspace in DB (`app/Models/AiProviderCredential.php`, `AiProviderCredentialModel.php`), resolved with priority-ordered fallback chains (`app/Support/AiProviders/AiProviderCredentialResolver.php` — `default` chain falls back to `vision` models)
- Uses the OpenAI REST API shape but accepts any OpenAI-compatible base URL per credential

**Google Drive (OAuth, link import/verification):**
- `app/Support/GoogleDrive/GoogleDriveClient.php`, `GoogleDriveLinkChecker.php`
- OAuth client credentials from env: `GOOGLE_DRIVE_CLIENT_ID`, `GOOGLE_DRIVE_CLIENT_SECRET`, `GOOGLE_DRIVE_REDIRECT_URI` (default `{APP_URL}/settings/google-drive/callback`) — see `config/services.php`, `app/Http/Controllers/Settings/GoogleDriveController.php`

**Link resolution:**
- Generic URL resolver for scratchpad links: `app/Support/LinkResolution/ProcessLinkResolver.php` (interface bound in `AppServiceProvider`); job `app/Jobs/ResolveScratchpadLinkJob.php`; public-URL safety guard `app/Support/LinkResolution/PublicUrlGuard.php`

## Data Storage

**Databases:**
- PostgreSQL (production; `DB_CONNECTION=pgsql`) — Laravel/Eloquent, migrations in `database/migrations/`
- Dedicated PostgreSQL test database, Pest runs serially (per `CLAUDE.md`)

**File Storage:**
- Laravel filesystems (`config/filesystems.php`); local disk default, S3-compatible via AWS_* env vars supported
- Uploads stored on the worker that processes them (`worker:scratchpad` for Telegram media/transcription/generation; `worker:publish` for PostSyncer)

**Caching:**
- Database cache store by default (`config/cache.php`, `CACHE_STORE`); Redis/Memcached connections available but optional; file-based cache storage disk (`CACHE_STORAGE_DISK`) used for cache-adjacent file data

## Authentication & Identity

**Auth Provider:**
- Laravel Fortify ^1.37 (headless auth backend: login, registration, password reset, profile) — `config/fortify.php`, `app/Providers/FortifyServiceProvider.php`
- `DISABLE_REGISTRATION` env closes public sign-up (403 on GET/POST /register) — single-operator self-hosting model
- `ADMIN_EMAIL` + `cm:ensure-admin` provisions the operator account on deploy
- Auth events drive team provisioning / invite auto-accept via auto-discovered listeners in `app/Listeners/`
- Route guarding: `routes/dashboard.php` behind `auth`+`verified`+`SetCurrentWorkspace`; workspace scoping via `app/Support/CurrentWorkspace.php` singleton

## Monitoring & Observability

**Error Tracking:**
- None detected (no Sentry/Bugsnag/Flare)

**Logs:**
- Laravel logging to stderr (Railway captures stdout/stderr; `LOG_CHANNEL` in `.env.example`), `config/logging.php`; local tail via Laravel Pail

## CI/CD & Deployment

**Hosting:**
- Railway — web (`deploy/railway.web.json`) + worker (`deploy/railway.worker.json`) services, Dockerfile build (`deploy/Dockerfile`, serversideup/php base), nginx in-container, TLS at Railway edge
- `main` branch auto-deploys; pre-deploy runs migrations; PostSyncer/publish-path PRs require a human gate before merge (see `CLAUDE.md` shipping rules)

**CI Pipeline:**
- GitHub Actions: `.github/workflows/ci.yml` (pint, prettier, eslint, tsc, phpstan level 7, pest), `.github/workflows/docker.yml`

## Environment Configuration

**Required env vars (app-level, see `.env.example`):**
- `APP_KEY`, `APP_URL`, `DB_*` (PostgreSQL), `QUEUE_CONNECTION`, `SESSION_DRIVER`, `CACHE_STORE`, `MAIL_*`, `LOG_*`
- `DISABLE_REGISTRATION`, `ADMIN_EMAIL` (optional), `PORT`, `AUTORUN_ENABLED=false` (prod), `POSTSYNCER_QUEUE_RETRY_AFTER`
- Cutover gates: `CM_TELEGRAM_CUTOVER_READY`, `CM_TELEGRAM_OLD_WEB_FLEET_DRAINED`
- Optional: `GOOGLE_DRIVE_CLIENT_ID/SECRET/REDIRECT_URI`, `AWS_*` (S3 storage), `POSTMARK_API_KEY`/`RESEND_API_KEY` (mail), `REDIS_*`

**Secrets location:**
- Integration credentials (PostSyncer/SocialReins tokens, Telegram bot tokens, AI API keys, Google Drive tokens) are **encrypted in the database per workspace**, not in env
- Infra/operational tokens live in gitignored `.secrets/<service>.env` (ADR `docs/adr/0002-operational-secrets-outside-app-env.md`)

## Webhooks & Callbacks

**Incoming:**
- Telegram webhook → update processing (webhook-not-polling decision, `docs/adr/0001-webhook-not-polling.md`; routes under `routes/` incl. `telegram:*` dispatch commands)
- SocialReins webhook with signature verification (`app/Support/Socialreins/WebhookSignature.php`)
- Google Drive OAuth callback: `/settings/google-drive/callback` (`config/services.php`)
- MCP JSON-RPC endpoint: `POST /mcp` (+ OPTIONS preflight) — `routes/mcp.php`, stateless protocol 2025-03-26 in `app/Support/Mcp/McpServer.php`, tool catalog `McpToolCatalog.php`, CORS `McpCors.php`

**Outgoing:**
- Outbound Telegram messages queued via `app/Jobs/SendTelegramOutboundMessageJob.php`

---

*Integration audit: 2026-11-21*

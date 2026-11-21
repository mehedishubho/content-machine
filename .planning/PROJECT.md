# content-machine

## What This Is

content-machine is an open-source, single-operator content pipeline: ideas captured via Telegram
are triaged into post/video ideas, scripted and produced in a Script-Studio-grade dashboard
(script, captions, presentation/deck), and published to connected social platforms through the
PostSyncer and SocialReins publishing providers. Laravel 13 + Inertia 3 + React 19 + TypeScript +
Tailwind 4 on PostgreSQL, deployed on Railway; `/dashboard/*` is Inertia+React, everything else is
plain Blade. This is a brownfield project: the pipeline through publish is built and shipping;
current work targets the publish path's reliability, observability, and maintainability.

## Core Value

Content items reach their connected platforms reliably — and the system can prove it: the publish
success rate (items successfully published via PostSyncer/SocialReins) is measurable, visible,
and defended by tests.

## Business Context

- **Customer**: Harun (single operator, self-hosted; public registration disabled)
- **Revenue model**: None — open-source personal infrastructure
- **Success metric**: Publish success rate — count of content items successfully published to connected platforms via PostSyncer/SocialReins
- **Strategy notes**: None external; `docs/` and `docs/adr/` carry architecture context

## Requirements

### Validated

<!-- Shipped and confirmed valuable — inferred from the codebase (mapped 2026-11-21 at effec39). -->

- ✓ Telegram capture pipeline (webhook-driven per ADR-0001; transcription, link resolution, triage)
- ✓ Ideation: ideas with score/trend/rationale, promotion to posts/videos (dashboard)
- ✓ Script Studio parity in dashboard: script, captions, presentation/deck tabs, status/language filters
- ✓ PostSyncer publish path for posts AND videos (queue jobs, resumable checkpoints, reconciliation, rescheduling)
- ✓ SocialReins publish path (per-language tokens, Idempotency-Key writes, signed webhook, driver routing)
- ✓ Video series (workspace-scoped slug + ordered parts, PUT /api/v1/series/{slug})
- ✓ Workspace-token API v1 (scratchpad, ideas, videos, posts, media, publishing, recovery) + MCP JSON-RPC server
- ✓ AI provider fallback chains, Pexels stock search, Google Drive OAuth import
- ✓ Fortify auth, teams/workspaces/invites, Railway deploy, CI (pint/prettier/eslint/tsc/phpstan L7/pest)

### Active

<!-- Current scope — the publish-path hardening milestone. Detail in REQUIREMENTS.md. -->

- [ ] Publish observability: structured logging, stuck-publish watchdog, success-rate reporting, error tracking (OBS-01..05)
- [ ] Publish path decomposition: god-class extraction, shared post/video publish-state concern, client interfaces (ARCH-01..04)
- [ ] Publish progress normalization + throughput: per-group table, bounded provider waits, rate-limit fairness, per-group fan-out (PUB-01..04)
- [ ] Guardrails: PostSyncer↔SocialReins routing matrix tests, one routing source of truth, workspace-scope guard, MCP error paths (SAFE-01..04)
- [ ] Workspace API completeness: deck upload, idea promotion, rate limiting (API-01..03)

### Out of Scope

<!-- Explicit boundaries. Includes reasoning to prevent re-adding. -->

- Completing the PostSyncer→SocialReins cutover (flipping default `publish_driver`) — operational decision for Harun; this milestone makes the transition safer and measurable instead of forcing it
- Decomposing `HandleTelegramUpdateAction` and the Telegram path — stable and webhook-driven (ADR-0001); not on the publish-success critical path
- Framework major bumps (Laravel/Inertia/React/Tailwind majors) — cross-cutting project; dedicated milestone per the codebase concerns audit
- Public multi-tenant sign-up — single-operator model is intentional (`DISABLE_REGISTRATION`)
- Provider status webhooks as a locked decision — ADR-0001 is scoped to Telegram; PostSyncer/SocialReins webhooks remain an implementation option inside PUB-02, not a project decision
- Mobile app / non-web clients — web is the target runtime

## Context

- Brownfield state (codebase map 2026-11-21, commit effec39): the full pipeline through publish
  exists; remaining work comes from the audit in `.planning/codebase/CONCERNS.md` and the verified
  remaining-gap list in `docs/guides/api.md` ("Not here yet").
- The publish path is the highest-risk code in the repo: `PublishPostAction` (~4.2k lines) and
  `PublishVideoAction` (~2.6k lines) hold lease tokens, run tokens, plan hashes, checkpoint
  rebasing, and duplicate-delivery guards. 11 of the last 15 commits touch it, including one full
  revert (#284→#285).
- The platform is mid-migration PostSyncer→SocialReins (per-workspace `settings.publish_driver`,
  `routed_languages`); the split/routing logic between the two produced 6+ consecutive fix PRs
  (#278–#286) — the bug class is structurally unresolved.
- Zero `Log::` calls on the publish path; no error-tracking service; no watchdog for stuck
  publishes. Failed publishes are debugged from DB columns only.
- A `SocialreinsGroup` table exists as precedent for normalized publish groups.
- `docs/guides/api.md` "Not here yet": deck multipart upload, idea promotion over the API, rate
  limiting beyond the default throttle.
- Video series is fully shipped (model + API); the old plan's unchecked "VPN assignment" task was
  operational data entry via the API, not code work.

## Constraints

- **Tech stack**: Laravel 13 + Inertia 3 + React 19 + TypeScript + Tailwind 4 + PostgreSQL on
  Railway — all majors current; any major bump is a dedicated milestone
- **Architecture**: Action pattern (`app/Actions/{Domain}`), readonly DTOs (`app/Data/{Domain}`),
  external integrations behind `app/Contracts` interfaces bound in Providers, thin
  models/controllers, shared logic in `app/Concerns` — enforced in review, no exceptions
- **Publish semantics**: public success is all-or-nothing per operation; pipeline status flips only
  on full job success; `publish_progress` stays private (never in API resources); retries resume
  checkpoints; uncertain outcomes are reconciled manually (`postsyncer:reconcile-post/-video`)
- **Queue contract**: publish jobs on the dedicated postsyncer connection/queue, 900s timeout /
  960s visibility; jobs take `(Model $model, array $options)` and only call Actions
- **Testing**: Pest serial on a dedicated PostgreSQL test DB; `Http::fake` for all provider calls
  (no live calls in tests); pint + phpstan level 7 + frontend lint/types green before commit;
  every Action and boundary DTO tested
- **Process**: publish-path / media-resolution / queue PRs wait for Harun's review in chat before
  merge (CLAUDE.md shipping rules); no em dashes in user-facing copy; workspace-scope every query
  (`Workspace::current()`)
- **Secrets**: infra/operational tokens in gitignored `.secrets/<service>.env` only (ADR-0002);
  app integration credentials encrypted per-workspace in the database

## Key Decisions

<!-- Decisions that constrain future work. Add throughout project lifecycle. -->

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| Receive Telegram updates via webhook, not polling (ADR-0001) | Public HTTPS endpoint with secret path segment + secret-token header check | ✓ Locked |
| Operational/infra credentials outside the app `.env`, in `.secrets/` (ADR-0002) | Isolates infra tokens from a runtime that legitimately rewrites `.env` | ✓ Locked |
| PostSyncer config lives in workspace settings (encrypted api_key), not env | Per-workspace config read at request time; ADR-0002 explicitly scopes this out of `.secrets/` | ✓ Good |
| Publish through queue jobs with private resumable checkpoints | Long operations must not run in web requests; retries resume instead of replay | ✓ Good |
| Spec "Decisions (locked)" table (postsyncer design spec) is NON-locked | User-confirmed: spec-internal wording, not ADR-grade — recorded as constraints only | ⚠️ Do not treat as locked |

<decisions locked>
- ADR-0001 (`docs/adr/0001-webhook-not-polling.md`, Accepted): Telegram updates arrive via webhook
  to a public HTTPS endpoint on the always-on deployment, authenticated by a secret unguessable
  path segment plus the `X-Telegram-Bot-Api-Secret-Token` header checked on every incoming
  request. No `getUpdates` polling.
- ADR-0002 (`docs/adr/0002-operational-secrets-outside-app-env.md`, Accepted): Operational and
  infrastructure credentials (Railway CLI token today; future services) live in
  `.secrets/<service>.env` — one file per service, gitignored except README and `*.env.example`
  templates — entirely separate from the app's own `.env`.
</decisions>

---
*Last updated: 2026-11-22 after project initialization from ingest (new-project-from-ingest)*

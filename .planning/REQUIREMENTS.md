# Requirements: content-machine

**Defined:** 2026-11-22
**Core Value:** Content items reach their connected platforms reliably — and the system can prove it via a measurable publish success rate (PostSyncer/SocialReins)

Derived from the 2026-11-21 codebase audit (`.planning/codebase/CONCERNS.md`) and the verified
remaining-gap list in `docs/guides/api.md`. Ingest found no PRD-class docs; these requirements
describe work still to do on the brownfield pipeline, not features to re-implement.

## v1 Requirements

### Observability

- [ ] **OBS-01**: A failed or partially-published item can be diagnosed from structured logs alone: publish-path code logs at claim, checkpoint, retry, and finalize points with job id, content id, operation/run token, and stage
- [ ] **OBS-02**: A publish left in queued/running past its lease window is detected automatically and alerts the operator (stuck-publish watchdog) — no silent stuck posts
- [ ] **OBS-03**: The dashboard shows publish success rate per period: counts of content items successfully published via PostSyncer/SocialReins, with failures alongside
- [ ] **OBS-04**: Publish-path exceptions reach an error-tracking service with workspace and content context attached
- [ ] **OBS-05**: Retry and lease-expiry counts on the publish path are tracked and reported alongside the success rate

### Publish Architecture

- [ ] **ARCH-01**: Cohesive responsibilities inside `PublishPostAction` (lease claiming, progress checkpointing, plan reconciliation, media resolution) are extracted into collaborator classes under `app/Support/Postsyncer/` — behavior-preserving, one extraction per PR, suites green after each
- [ ] **ARCH-02**: Post and video publish paths share one publish-state concern (lease/checkpoint/duplicate-guard machinery) so a fix lands once instead of being hand-mirrored between two implementations
- [ ] **ARCH-03**: `PostsyncerClient` and SocialReins clients sit behind `app/Contracts` interfaces, bound in a ServiceProvider, constructor-injected into Actions; publish-path tests fake the interface, not the HTTP layer
- [ ] **ARCH-04**: Publish invariants survive every change: the duplicate-delivery guard (`publish_state === succeeded` + existing public group) and the fail-closed plan-hash check hold, verified by dedicated tests

### Publish Throughput

- [ ] **PUB-01**: Per-group publish progress lives in a normalized table (posts and videos) replacing the `publish_progress` JSON column; in-flight publishes are queryable at group granularity and corrupt rows cannot silently change behavior
- [ ] **PUB-02**: A publish job no longer holds a queue worker for minutes on a single provider wait: bounded polls with job re-release (provider webhook where supported) replace the 300s blocking GET
- [ ] **PUB-03**: Scheduled status-sync cannot starve queued publishes of the provider rate-limit budget (separate buckets or publish-priority)
- [ ] **PUB-04**: Multi-group posts fan out per group without duplicate deliveries, built on the normalized checkpoint model

### Guardrails

- [ ] **SAFE-01**: The PostSyncer↔SocialReins routing matrix (platform set × provider capability × pair rule) is pinned by tests, including explicit cases for the historically regressing bug class (#279–#286)
- [ ] **SAFE-02**: Platform routing rules live in one config-shaped source of truth rather than scattered conditionals across planners
- [ ] **SAFE-03**: A guard (test-level or static analysis) fails CI when a query on a workspace-scoped model lacks a workspace constraint
- [ ] **SAFE-04**: MCP tool error paths are covered by tests so external clients cannot hit untested mutation branches

### Workspace API

- [ ] **API-01**: Deck packages upload as binaries via multipart workspace-token API endpoints (manifest fields continue landing via PATCH)
- [ ] **API-02**: An idea can be promoted to a post or video over the workspace-token API with the same outcome as dashboard promotion
- [ ] **API-03**: API clients get documented rate limits beyond the default per-minute throttle

## v2 Requirements

### Telegram Path

- **TG-01**: Decompose `HandleTelegramUpdateAction` (~1.4k lines) into collaborators — deferred: stable, off the publish critical path
- **TG-02**: Regression tests for duplicate webhook deliveries before any dispatch-logic change

### Platform

- **PLAT-01**: Complete the PostSyncer→SocialReins cutover (flip default `publish_driver` per workspace) — operational decision, deferred to Harun
- **PLAT-02**: Framework major bumps (Laravel/Inertia/React/Tailwind) — dedicated milestone

## Out of Scope

Explicitly excluded. Documented to prevent scope creep.

| Feature | Reason |
|---------|--------|
| Re-implementing shipped pipeline features (capture, ideation, studio, publish, series, API, MCP) | Brownfield — they exist and are validated; see PROJECT.md Validated list |
| Public multi-tenant sign-up | Single-operator model is intentional (`DISABLE_REGISTRATION`) |
| Mobile / non-web clients | Target runtime is web (Laravel + Inertia + React on Railway) |
| PostSyncer/SocialReins status webhooks as a locked decision | ADR-0001 is scoped to Telegram; provider webhooks stay an option inside PUB-02 |
| Per-group concurrency before checkpoint normalization | Order enforced by PUB-01 → PUB-04 dependency; early concurrency caused duplication bugs |

## Traceability

Which phases cover which requirements. Updated during roadmap creation.

| Requirement | Phase | Status |
|-------------|-------|--------|
| OBS-01 | Phase 1 | Pending |
| OBS-02 | Phase 1 | Pending |
| OBS-03 | Phase 1 | Pending |
| OBS-04 | Phase 1 | Pending |
| OBS-05 | Phase 1 | Pending |
| ARCH-01 | Phase 2 | Pending |
| ARCH-02 | Phase 2 | Pending |
| ARCH-03 | Phase 2 | Pending |
| ARCH-04 | Phase 2 | Pending |
| PUB-01 | Phase 3 | Pending |
| PUB-02 | Phase 3 | Pending |
| PUB-03 | Phase 3 | Pending |
| PUB-04 | Phase 3 | Pending |
| SAFE-01 | Phase 4 | Pending |
| SAFE-02 | Phase 4 | Pending |
| SAFE-03 | Phase 4 | Pending |
| SAFE-04 | Phase 4 | Pending |
| API-01 | Phase 5 | Pending |
| API-02 | Phase 5 | Pending |
| API-03 | Phase 5 | Pending |

**Coverage:**
- v1 requirements: 20 total
- Mapped to phases: 20
- Unmapped: 0 ✓

---
*Requirements defined: 2026-11-22*
*Last updated: 2026-11-22 after roadmap creation (traceability populated)*

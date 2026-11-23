# Requirements: content-machine

**Defined:** 2026-11-22
**Core Value:** Content items reach their connected platforms reliably — and the system can prove it via a measurable publish success rate (PostSyncer/SocialReins)

Derived from the 2026-11-21 codebase audit (`.planning/codebase/CONCERNS.md`) and the verified
remaining-gap list in `docs/guides/api.md`. Ingest found no PRD-class docs; these requirements
describe work still to do on the brownfield pipeline, not features to re-implement.

## v1 Requirements

### Observability

- [x] **OBS-01**: A failed or partially-published item can be diagnosed from structured logs alone: publish-path code logs at claim, checkpoint, retry, and finalize points with job id, content id, operation/run token, and stage
- [x] **OBS-02**: A publish left in queued/running past its lease window is detected automatically and alerts the operator (stuck-publish watchdog) — no silent stuck posts
- [x] **OBS-03**: The dashboard shows publish success rate per period: counts of content items successfully published via PostSyncer/SocialReins, with failures alongside
- [x] **OBS-04**: Publish-path exceptions reach an error-tracking service with workspace and content context attached
- [x] **OBS-05**: Retry and lease-expiry counts on the publish path are tracked and reported alongside the success rate

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

### Recurring Automation

Added 2026-11-22 from the feature-parity audit against the OurDesh-style news-automation system.

- [ ] **AUTO-01**: A workspace-scoped content recipe exists (category, schedule rule, target groups/platforms, template binding, publish mode: approval-required or auto-publish) with CRUD via the Action pattern
- [ ] **AUTO-02**: Recipes dispatch generation as queue jobs at their scheduled time; missed runs recover under a defined catch-up-or-skip policy — never silently skipped
- [ ] **AUTO-03**: Approval mode routes generated content through the existing approval workflow; auto-publish is an explicit per-recipe opt-in, never a default
- [ ] **AUTO-04**: Recipe run history is visible in the dashboard — generated item, rendered media, publish outcome — feeding the OBS-03 success-rate reporting
- [ ] **AUTO-05**: Generation reuses the existing AI provider fallback chains and the existing publish path (PostSyncer/SocialReins); no parallel publishing mechanism

### Data Sources

- [ ] **DATA-01**: Weather connector (interface + HTTP implementation): current/max/min temperature, rainfall, condition, per city
- [ ] **DATA-02**: Prayer-times connector: Fajr, Dhuhr, Asr, Maghrib, Isha, sunrise, for a configured location
- [ ] **DATA-03**: Currency connector: USD, EUR, GBP, AUD, JPY, CAD rates in a Bangladesh Bank presentation
- [ ] **DATA-04**: Stock connector: DSEX, DS30, turnover, index movement, percentage changes
- [ ] **DATA-05**: AQI connector: per-city values and pollution-level indicators for the eight divisional cities
- [ ] **DATA-06**: Date-content connector: today's historical events (with years) and today's observances/special days
- [ ] **DATA-07**: Hadith connector: Arabic text, Bengali translation, reference, narrator/source
- [ ] **DATA-08**: Job-circular ingestion: organization, title, designation, vacancies, deadline, eligibility, application instructions/link
- All DATA connectors: behind `app/Contracts` interfaces bound in a ServiceProvider, per-day cached, tested with fakes (no live calls)

### Graphic Generation

- [ ] **GFX-01**: Per-category branded card templates (greeting, weather, prayer, hadith, job, currency, stock, AQI, history, special day, news photo card) render with workspace logo/colors/footer — no per-post manual design
- [ ] **GFX-02**: Bengali text renders with correct glyph shaping (clusters, matras) and English cleanly, in one rendering pipeline
- [ ] **GFX-03**: Rendered graphics store as `MediaAsset`s attachable to posts, publishable via the existing publish path
- [ ] **GFX-04**: Templates can source background photography from the existing Pexels integration and media library
- [ ] **GFX-05**: Templates emit platform-appropriate dimensions (feed, vertical 9:16 for Shorts)

### News Ingestion

- [ ] **NEWS-01**: Per-workspace WordPress site connection (encrypted credentials) pulling articles via WP REST API
- [ ] **NEWS-02**: Article → post draft mapping: headline, description, featured image, site category — through the existing post pipeline
- [ ] **NEWS-03**: Article URL publishes as first comment via the existing `FIRST_COMMENT_PLATFORMS` mechanism
- [ ] **NEWS-04**: News photo cards render from articles through the GFX engine (headline, image, branding, date)

### Daily Categories

- [ ] **CAT-01**: The eleven daily categories (Good Morning, Weather, Prayer Times, Daily Hadith, Jobs, Sports, Currency, Stock Market, AQI, Today's History, Today's Special Days) run as recipes: data fetch → graphic render → AI caption → publish
- [ ] **CAT-02**: Job circulars publish with application links in comments; sports content draws on news ingestion
- [ ] **CAT-03**: One evidenced verification day per category: all active categories publish with outcomes visible in the dashboard

### Shorts Video

- [ ] **VID-01**: Top-5-news headlines + images render into a branded 9:16 vertical video with Bengali headline shaping — automated generation, not manual editing
- [ ] **VID-02**: Generated Shorts publish to YouTube via the existing provider path, or a direct YouTube upload connector behind `app/Contracts` if providers cannot accept them (decision in planning)
- [ ] **VID-03**: The daily Short is schedulable as a recipe alongside the Phase 10 categories

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
| OBS-01 | Phase 1 | Complete |
| OBS-02 | Phase 1 | Complete |
| OBS-03 | Phase 1 | Complete |
| OBS-04 | Phase 1 | Complete |
| OBS-05 | Phase 1 | Complete |
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
| AUTO-01 | Phase 6 | Pending |
| AUTO-02 | Phase 6 | Pending |
| AUTO-03 | Phase 6 | Pending |
| AUTO-04 | Phase 6 | Pending |
| AUTO-05 | Phase 6 | Pending |
| DATA-01 | Phase 7 | Pending |
| DATA-02 | Phase 7 | Pending |
| DATA-03 | Phase 7 | Pending |
| DATA-04 | Phase 7 | Pending |
| DATA-05 | Phase 7 | Pending |
| DATA-06 | Phase 7 | Pending |
| DATA-07 | Phase 7 | Pending |
| DATA-08 | Phase 7 | Pending |
| GFX-01 | Phase 8 | Pending |
| GFX-02 | Phase 8 | Pending |
| GFX-03 | Phase 8 | Pending |
| GFX-04 | Phase 8 | Pending |
| GFX-05 | Phase 8 | Pending |
| NEWS-01 | Phase 9 | Pending |
| NEWS-02 | Phase 9 | Pending |
| NEWS-03 | Phase 9 | Pending |
| NEWS-04 | Phase 9 | Pending |
| CAT-01 | Phase 10 | Pending |
| CAT-02 | Phase 10 | Pending |
| CAT-03 | Phase 10 | Pending |
| VID-01 | Phase 11 | Pending |
| VID-02 | Phase 11 | Pending |
| VID-03 | Phase 11 | Pending |

**Coverage:**
- v1 requirements: 48 total
- Mapped to phases: 48
- Unmapped: 0 ✓

---
*Requirements defined: 2026-11-22*
*Last updated: 2026-11-22 after roadmap creation (traceability populated)*

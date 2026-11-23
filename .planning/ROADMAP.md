# Roadmap: content-machine

## Overview

Two tracks. Track 1 (Phases 1–5) hardens the part that matters most — the publish path. It starts
by making publish outcomes visible and measurable (observability), then makes the highest-risk
code safely changeable (decomposition into collaborators with shared post/video state and
interface-bound clients), then removes the throughput ceiling (normalized progress, bounded
provider waits, per-group fan-out), closes the recurring PostSyncer↔SocialReins routing bug class
alongside tenancy guardrails, and finishes the workspace-token API's verified gap list (deck
upload, idea promotion, rate limits). Every phase in this track serves one number: the publish
success rate.

Track 2 (Phases 6–11, added 2026-11-22 from the feature-parity audit against the OurDesh-style
news-automation system in `.planning/research/` context) builds what that comparison found
missing: a recurring automation engine (one-time setup, daily generation and publishing without a
human in the loop), data-source connectors for daily information categories (weather, prayer
times, hadith, jobs, sports, currency, stock market, AQI, history, special days), a branded
graphic generation engine with Bengali text rendering, WordPress news-portal ingestion, rollout
of the eleven daily content categories on top of that engine, and a Shorts/Top-5-news video
pipeline. Phases 6–11 reuse the existing publish path, approval flow, and AI provider chains
rather than replacing them.

## Phases

**Phase Numbering:**
- Integer phases (1, 2, 3): Planned milestone work
- Decimal phases (2.1, 2.2): Urgent insertions (marked with INSERTED)

Decimal phases appear between their surrounding integers in numeric order.

- [ ] **Phase 1: Publish Observability & Monitoring** - Every publish outcome is logged, measurable, and alertable; publish success rate is visible in the dashboard
- [ ] **Phase 2: Publish Path Decomposition** - God-class publish Actions become focused collaborators with shared post/video state and interface-bound clients
- [ ] **Phase 3: Publish Progress & Throughput** - Normalized per-group progress and bounded provider waits let publishes scale without duplicate deliveries
- [ ] **Phase 4: Routing & Multi-Tenancy Guardrails** - PostSyncer↔SocialReins routing pinned by matrix tests; workspace scoping enforced by a CI guard
- [ ] **Phase 5: Workspace API Completeness** - Deck upload, idea promotion, and rate limiting close the verified API gap list
- [ ] **Phase 6: Recurring Automation Engine** - Content recipes: one-time setup generates, renders, and publishes daily content without a human in the loop
- [ ] **Phase 7: Data-Source Connectors** - Weather, prayer times, hadith, jobs, currency, stock, AQI, history, and special-day data behind Contracts interfaces with cached daily fetches
- [ ] **Phase 8: Branded Graphic Generation** - Server-side card rendering per category with Bengali/English text shaping and workspace branding, stored as publishable media
- [ ] **Phase 9: News Portal Ingestion** - WordPress REST connector pulls articles and featured images into post drafts with article URLs as first comments
- [ ] **Phase 10: Daily Category Rollout** - The eleven daily content categories run live on the automation engine, end-to-end verified
- [ ] **Phase 11: Shorts Video Pipeline** - Daily Top-5-news vertical video generation published as Shorts

## Phase Details

### Phase 1: Publish Observability & Monitoring
**Goal**: Every publish outcome on PostSyncer/SocialReins is visible, measurable, and alertable — the publish success rate is a number you can read, not a forensics project
**Depends on**: Nothing (first phase)
**Requirements**: OBS-01, OBS-02, OBS-03, OBS-04, OBS-05
**Success Criteria** (what must be TRUE):
  1. A failed or partially-published item can be diagnosed from structured logs alone (job id, content id, operation id, stage) without reconstructing state from DB columns
  2. A publish stuck in queued/running past its lease window is detected and alerts the operator automatically — no silent stuck posts
  3. The dashboard shows publish success rate per period (items successfully published via PostSyncer/SocialReins) with failure and retry/lease-expiry counts alongside
  4. Publish-path exceptions reach an error-tracking service with workspace and content context attached
**Plans**: 5/5 plans executed

Plans:
**Wave 1**
- [x] 01-01-PLAN.md — Publish-event pipeline: publish_events table, PublishEventRecorder seam, publish JSON log channel, PublishPostAction lifecycle instrumentation (OBS-01)

**Wave 2** *(blocked on Wave 1 completion)*
- [x] 01-02-PLAN.md — Video + SocialReins path instrumentation and provider HTTP outcome events (OBS-01, OBS-05)
- [x] 01-03-PLAN.md — Stuck-publish watchdog with Telegram alerting and 90-day retention prune (OBS-02, OBS-05)
- [x] 01-04-PLAN.md — Sentry error tracking with publish correlation context (OBS-04)

**Wave 3** *(blocked on Wave 2 completion)*
- [x] 01-05-PLAN.md — Observability dashboard: success-rate page + Publish health home card (OBS-03, OBS-05)

**UI hint**: yes

### Phase 2: Publish Path Decomposition
**Goal**: The publish path (highest-risk code in the repo) becomes safely changeable — lease, checkpoint, reconciliation, and media logic live in focused collaborators shared by post and video paths, with clients behind interfaces
**Depends on**: Nothing (independent; sequenced after Phase 1 so extractions land with observability already in place)
**Requirements**: ARCH-01, ARCH-02, ARCH-03, ARCH-04
**Success Criteria** (what must be TRUE):
  1. A fix to lease/checkpoint/duplicate-guard logic is made once and demonstrably applies to both the post and video publish paths (shared concern, not hand-mirrored code)
  2. PostSyncer/SocialReins clients are injected via `app/Contracts` interfaces, and publish-path tests fake the interface instead of the HTTP layer
  3. `PublishPostAction` and `PublishVideoAction` are orchestrators over extracted collaborators under `app/Support/Postsyncer/`, with the full Postsyncer Pest suites green after each extraction
  4. After every change, the duplicate-delivery guard and the fail-closed plan-hash check still hold (dedicated tests pass)
**Plans**: TBD

### Phase 3: Publish Progress & Throughput
**Goal**: Publish progress is normalized and queryable, and a publish no longer monopolizes workers — publish volume scales without duplicating deliveries
**Depends on**: Phase 2
**Requirements**: PUB-01, PUB-02, PUB-03, PUB-04
**Success Criteria** (what must be TRUE):
  1. In-flight publishes are queryable at group granularity in SQL (normalized per-group table replaces the `publish_progress` JSON column), and a corrupt/partial progress row cannot silently change publish behavior
  2. A publish no longer holds a queue worker for up to 5 minutes on a single provider wait (bounded polls with job re-release, or provider webhook where supported)
  3. Scheduled status-sync cannot starve a queued publish of the provider rate-limit budget
  4. A multi-group post fans out per group with zero duplicate deliveries (duplicate guards verified under concurrent execution)
**Plans**: TBD

### Phase 4: Routing & Multi-Tenancy Guardrails
**Goal**: The PostSyncer↔SocialReins platform-routing bug class is closed by tests, routing rules have one home, and cross-workspace leakage is blocked by a guard instead of vigilance
**Depends on**: Phase 2
**Requirements**: SAFE-01, SAFE-02, SAFE-03, SAFE-04
**Success Criteria** (what must be TRUE):
  1. The pair × platform × provider-capability routing matrix is pinned by tests, with explicit cases for every historically regressing routing bug (#279–#286 class)
  2. A routing-rule change edits one config-shaped source of truth — not conditionals scattered across planners
  3. A query on a workspace-scoped model missing its workspace constraint fails CI (scope guard), never surfaces in production
  4. MCP tool error paths are test-covered, so an external client cannot hit an untested mutation branch
**Plans**: TBD

### Phase 5: Workspace API Completeness
**Goal**: External automation clients can do everything through the workspace-token API that content production needs, with predictable limits — closing the verified gap list in `docs/guides/api.md`
**Depends on**: Nothing (independent of the publish-path phases)
**Requirements**: API-01, API-02, API-03
**Success Criteria** (what must be TRUE):
  1. Deck packages upload as binaries via multipart API endpoints (manifest fields continue landing via PATCH)
  2. An idea is promoted to a post or video over the API with the same outcome as dashboard promotion
  3. API clients hit documented, test-enforced rate limits beyond the default per-minute throttle
**Plans**: TBD

### Phase 6: Recurring Automation Engine
**Goal**: One-time setup produces daily content without a human in the loop — a recipe (category, schedule, platform targets, template, publish mode) generates, renders, and publishes on schedule through the existing pipeline
**Depends on**: Phase 1 (automated publishes must be observable before they run unattended)
**Requirements**: AUTO-01, AUTO-02, AUTO-03, AUTO-04, AUTO-05
**Success Criteria** (what must be TRUE):
  1. A workspace-scoped recipe can be created once (category, schedule rule, target groups/platforms, template binding, publish mode) and requires zero manual steps afterwards for daily publishing
  2. Recipes dispatch generation via queue jobs at their scheduled time; a missed run (downtime, failed job) recovers under a defined catch-up-or-skip policy — never silently skipped
  3. A recipe in approval mode routes generated content through the existing approval workflow; auto-publish mode is an explicit per-recipe opt-in
  4. Every recipe run is visible in the dashboard: what was generated, the rendered media, and the publish outcome (feeding the Phase 1 success-rate reporting)
  5. Generation reuses the existing AI provider fallback chains and the existing publish path (PostSyncer/SocialReins) — no parallel publishing mechanism
**Plans**: TBD
**UI hint**: yes

### Phase 7: Data-Source Connectors
**Goal**: The daily-information categories have verified data sources behind `app/Contracts` interfaces, cached per day, fakeable in tests
**Depends on**: Nothing (independent; feeds Phases 8 and 10)
**Requirements**: DATA-01, DATA-02, DATA-03, DATA-04, DATA-05, DATA-06, DATA-07, DATA-08
**Success Criteria** (what must be TRUE):
  1. Weather connector returns current/max/min temperature, rainfall, and condition per city (DATA-01)
  2. Prayer-times connector returns the five daily prayers plus sunrise for a configured location (DATA-02)
  3. Currency connector returns exchange rates for USD, EUR, GBP, AUD, JPY, CAD in a Bangladesh Bank presentation (DATA-03)
  4. Stock connector returns DSEX, DS30, turnover, and index movement with percentage changes (DATA-04)
  5. AQI connector returns per-city AQI values and pollution-level indicators for the eight divisional cities (DATA-05)
  6. Date-content connector returns today's historical events and today's observances/special days by date (DATA-06)
  7. Hadith connector returns Arabic text, Bengali translation, reference, and narrator for the day (DATA-07)
  8. Job-circular source ingestion returns organization, title, designation, vacancies, deadline, eligibility, and application link (DATA-08)
  9. Every connector sits behind an `app/Contracts` interface bound in a ServiceProvider, caches per-day results, and is tested against fakes — no live API calls in tests
**Plans**: TBD
**UI hint**: no

### Phase 8: Branded Graphic Generation
**Goal**: Category card graphics (greeting, weather, prayer, hadith, job, currency, stock, AQI, history, special day, news photo card) are rendered server-side with workspace branding and correct Bengali/English text shaping, and land as media assets publishable through the existing path
**Depends on**: Nothing (independent; consumes Phase 7 data when present)
**Requirements**: GFX-01, GFX-02, GFX-03, GFX-04, GFX-05
**Success Criteria** (what must be TRUE):
  1. A template system renders one branded card per category with logo placement, colors, and footer from workspace branding — no per-post manual design
  2. Bengali text renders with correct glyph shaping (cluster formation, matras) and English renders cleanly, in one pipeline — naive GD `imagettftext`-style rendering does not meet this bar
  3. Rendered graphics are stored as `MediaAsset`s attachable to posts and publishable via the existing PostSyncer/SocialReins path
  4. Templates can use background photography from the existing Pexels integration and media library
  5. Each template emits platform-appropriate dimensions for its target platforms (feed image, vertical for Shorts)
**Plans**: TBD
**UI hint**: yes

### Phase 9: News Portal Ingestion
**Goal**: Articles from a WordPress news portal flow into content-machine as post drafts — headline, description, featured image, category — with the article URL published as the first comment
**Depends on**: Phase 8 (news photo cards are rendered from articles)
**Requirements**: NEWS-01, NEWS-02, NEWS-03, NEWS-04
**Success Criteria** (what must be TRUE):
  1. A per-workspace WordPress site can be connected (site URL + application credentials, encrypted per-workspace like other integrations) and articles pulled via WP REST API (NEWS-01)
  2. An article becomes a post draft with headline, description, featured image, and site category mapped in — reuses the existing post pipeline, no new post type (NEWS-02)
  3. The article URL publishes as the first comment on platforms that support it (existing `FIRST_COMMENT_PLATFORMS` mechanism) (NEWS-03)
  4. News photo cards generate from articles through the Phase 8 engine: headline, image, branding, date (NEWS-04)
**Plans**: TBD
**UI hint**: yes

### Phase 10: Daily Category Rollout
**Goal**: The eleven daily content categories run live on the automation engine — Good Morning, Weather, Prayer Times, Daily Hadith, Jobs, Sports, Currency, Stock Market, AQI, Today's History, Today's Special Days — each a configured recipe producing graphic + caption + publish, verified end-to-end
**Depends on**: Phase 6, Phase 7, Phase 8 (and Phase 9 for news-derived categories)
**Requirements**: CAT-01, CAT-02, CAT-03
**Success Criteria** (what must be TRUE):
  1. Each of the eleven categories runs as a recipe: data fetch → graphic render → AI caption generation → publish to configured platforms, with no manual step in approval-auto mode
  2. Job circulars publish with application links in comments; sports content draws on news ingestion (Phase 9) for news/photo cards
  3. A full day of all active categories publishes with outcomes visible in the dashboard (success rate from Phase 1 reporting) — one verification day per category, evidenced
**Plans**: TBD
**UI hint**: yes

### Phase 11: Shorts Video Pipeline
**Goal**: A daily Top-5-news vertical video is generated from headlines and images and published as a Short — the video-generation counterpart to the existing video publish path
**Depends on**: Phase 8 (branded frames), Phase 9 (news content source)
**Requirements**: VID-01, VID-02, VID-03
**Success Criteria** (what must be TRUE):
  1. Top-5-news headlines plus images render into a branded vertical (9:16) video with Bengali headline text shaping — automated, not manual editing (VID-01)
  2. Generated Shorts publish to YouTube through the existing provider path (or a direct YouTube upload API connector behind `app/Contracts` if providers cannot accept them — decided in planning) (VID-02)
  3. The Short is schedulable as a daily recipe alongside the Phase 10 categories (VID-03)
**Plans**: TBD
**UI hint**: yes

## Progress

**Execution Order:**
Phases execute in numeric order: 1 → 2 → 3 → 4 → 5 → 6 → 7 → 8 → 9 → 10 → 11.
Track 2 notes: 7 and 8 are independent of 6 and could run earlier; 6 must follow 1 so automated
publishes are observable from day one; 10 needs 6+7+8(+9); 11 needs 8+9.

| Phase | Plans Complete | Status | Completed |
|-------|----------------|--------|-----------|
| 1. Publish Observability & Monitoring | 5/5 | In Progress | - |
| 2. Publish Path Decomposition | 0/TBD | Not started | - |
| 3. Publish Progress & Throughput | 0/TBD | Not started | - |
| 4. Routing & Multi-Tenancy Guardrails | 0/TBD | Not started | - |
| 5. Workspace API Completeness | 0/TBD | Not started | - |
| 6. Recurring Automation Engine | 0/TBD | Not started | - |
| 7. Data-Source Connectors | 0/TBD | Not started | - |
| 8. Branded Graphic Generation | 0/TBD | Not started | - |
| 9. News Portal Ingestion | 0/TBD | Not started | - |
| 10. Daily Category Rollout | 0/TBD | Not started | - |
| 11. Shorts Video Pipeline | 0/TBD | Not started | - |

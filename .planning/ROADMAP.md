# Roadmap: content-machine

## Overview

The pipeline through publish is built and shipping; this milestone hardens the part that matters
most — the publish path. It starts by making publish outcomes visible and measurable
(observability), then makes the highest-risk code safely changeable (decomposition into
collaborators with shared post/video state and interface-bound clients), then removes the
throughput ceiling (normalized progress, bounded provider waits, per-group fan-out), closes the
recurring PostSyncer↔SocialReins routing bug class alongside tenancy guardrails, and finishes the
workspace-token API's verified gap list (deck upload, idea promotion, rate limits). Every phase
serves one number: the publish success rate.

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
**Plans**: TBD
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

## Progress

**Execution Order:**
Phases execute in numeric order: 1 → 2 → 3 → 4 → 5

| Phase | Plans Complete | Status | Completed |
|-------|----------------|--------|-----------|
| 1. Publish Observability & Monitoring | 0/TBD | Not started | - |
| 2. Publish Path Decomposition | 0/TBD | Not started | - |
| 3. Publish Progress & Throughput | 0/TBD | Not started | - |
| 4. Routing & Multi-Tenancy Guardrails | 0/TBD | Not started | - |
| 5. Workspace API Completeness | 0/TBD | Not started | - |

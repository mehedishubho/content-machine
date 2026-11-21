# Synthesis Summary

Ingest mode: new (no existing PROJECT.md / REQUIREMENTS.md / ROADMAP.md / STATE.md to merge against).
Precedence: ADR > SPEC > PRD > DOC; no per-doc overrides.

## Doc counts by type
- ADR: 2 (both locked, confidence high) — docs/adr/0001-webhook-not-polling.md, docs/adr/0002-operational-secrets-outside-app-env.md
- SPEC: 4 — postsyncer-in-cm-design (high), postsyncer-in-cm plan (medium), video-series-design (high), video-series plan (medium)
- PRD: 0
- DOC: 1 — dashboard-content-parity (medium)
- UNKNOWN / low-confidence: 0
- Total: 7

## Cycle detection
Cross-ref graph: 2 edges (postsyncer plan -> postsyncer design spec; video-series plan -> video-series design spec).
No cycles; max traversal depth 2 (cap 50). All 7 docs synthesized.

## Decisions locked: 2
- docs/adr/0001-webhook-not-polling.md (Accepted)
- docs/adr/0002-operational-secrets-outside-app-env.md (Accepted)
No LOCKED-vs-LOCKED contradictions detected.

## Requirements extracted: 0
No PRD-classified docs; requirements.md intentionally empty.

## Constraints: 12
- schema: 4, api-contract: 6, nfr: 2, protocol: 0

## Context topics: 2

## Conflicts
- Blockers: 0
- Competing variants: 0
- Auto-resolved: 0
- Warnings: 1 (SPEC self-declares "Decisions (locked)" but is not ADR-grade)
- Info: 2 (superseded content inside the postsyncer design spec; historical stack labels differ)
Detail: .planning/INGEST-CONFLICTS.md

## Intel files
- decisions.md — 2 locked ADR decisions
- requirements.md — empty (no PRDs)
- constraints.md — 12 SPEC constraints
- context.md — 2 DOC topics

## Precedence notes for downstream (gsd-roadmapper)
- The postsyncer design spec's "Decisions (locked)" table is spec-internal wording, not ADR-grade
  LOCKED at ingest precedence; recorded as constraints (WARNING raised in the conflict report).
- All SPECs and the DOC self-identify as historical snapshots (2026-08-25 era) superseded in places
  by later implementation; headers name the supersessions. Unchecked checkboxes in plans are not
  live backlog.
- No ingest source contradicts either LOCKED ADR. PostSyncer api_key stored encrypted in workspace
  settings is app runtime config (read at request time), which ADR-0002 explicitly scopes out of
  `.secrets/` — recorded as compatible, not conflicting.

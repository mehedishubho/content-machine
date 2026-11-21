## Conflict Detection Report

### BLOCKERS (0)

### WARNINGS (1)

[WARNING] SPEC self-declares "Decisions (locked)" but is not ADR-grade
  Found: docs/superpowers/specs/2026-08-25-postsyncer-in-cm-design.md contains a "Decisions (locked)" table (v1 surface, config home, scope, media strategy, bilingual, execution, list UI); classifier set locked=false because the doc self-identifies as an approved design spec, not an ADR
  Impact: Downstream could mistake spec-internal "locked" for precedence-grade LOCKED and let it override ADR decisions or resist later revision
  → Confirm these remain non-locked constraints, or promote chosen rows into a real ADR before routing

### INFO (2)

[INFO] Superseded content inside postsyncer design spec
  Note: docs/superpowers/specs/2026-08-25-postsyncer-in-cm-design.md header marks the doc a historical design snapshot: the original non-goal "polling PostSyncer to auto-flip scheduled → posted" was superseded by postsyncer:sync-scheduled, and Drive OAuth/folder picker (originally out of v1) has since shipped (2026-09-01). Synthesized intel records both the decision table and the supersession notes; no precedence resolution was applied.

[INFO] Historical stack labels differ across snapshots
  Note: docs/superpowers/plans/2026-08-25-postsyncer-in-cm.md states "Tech Stack at planning time: Laravel 12" while docs/superpowers/plans/2026-09-28-video-series.md states "Laravel 13". Both lines are self-labeled planning-time snapshots at different dates, not current-state claims; no precedence resolution was applied. Verify current stack against docs/ before planning.

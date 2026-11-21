# Context (from DOCs)

## Dashboard Studio parity (historical implementation plan)
- source: docs/superpowers/plans/2026-08-25-dashboard-content-parity.md
- status: historical snapshot (2026-08-25); unchecked tasks were not maintained as work shipped and
  do not describe current completion or remaining work; dashboard implemented at baseline fafde1e
  (2026-09-24). Context only — classified DOC so stale tasks are not treated as live requirements.
- notes:
  - Goal: dashboard videos/posts match Script Studio feature depth (script, captions, presentation,
    filters), backed by the database.
  - Architecture sketch: controllers present full DB fields (normalized captions); React show pages
    use Studio-like tabs; decks/images land in storage + deck_manifest / attachments; presentation
    plays via a dedicated fullscreen route booting the same Reveal deck sources (Reveal.js CDN).
  - Show tabs planned: videos Overview / Script / Captions / Presentation (if deck); posts
    Overview / Captions / Images. Index filters: status, language, q.
  - Media import: script to upload post images + deck packages into CM; fill deck_manifest with
    engine/steps/css/js refs.
  - Global constraints from the plan: no em dashes in user-facing copy; workspace scoping on every
    query; keep API VideoResource fields as source-of-truth shapes; local personal-content markdown
    remains archive only.
  - Verification references: BV-60 / P-48 show rich content; tests for presentCaptions normalization
    and filter queries.

## Remaining API gaps (as of baseline fafde1e, 2026-09-24)
- source: docs/superpowers/plans/2026-08-25-dashboard-content-parity.md (header); corroborated by
  docs/superpowers/plans/2026-08-25-postsyncer-in-cm.md (header)
- notes:
  - docs/guides/api.md lists the verified remaining API gaps: deck-package upload endpoints, idea
    promotion over the API, and rate limiting beyond the default throttle.
  - The parity plan explicitly does not claim those gaps are dashboard parity work.

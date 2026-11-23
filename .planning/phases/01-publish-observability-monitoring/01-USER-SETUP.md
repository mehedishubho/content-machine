# Phase 1: User Setup Required

**Generated:** 2026-11-23
**Phase:** 01-publish-observability-monitoring
**Status:** Incomplete

Complete these items for Sentry error tracking (OBS-04) to deliver end-to-end. The code side is
shipped and inert: sentry/sentry-laravel 4.31.0 is wired at the bootstrap seam and the publish
correlation context attaches at the recorder seam — nothing emits until `SENTRY_LARAVEL_DSN` is
set. This provisioning was deferred at the plan 01-04 Task 3 checkpoint (2026-11-23); these are
the steps that close the follow-up.

## Environment Variables

| Status | Variable | Source | Add to |
|--------|----------|--------|--------|
| [ ] | `SENTRY_LARAVEL_DSN` | sentry.io → your Laravel project → Client Keys (DSN) | Railway app env (production); local `.env` for staging verification |

This is an app-runtime credential: Railway app env only — never `.secrets/` (ADR-0002), never
committed.

## Account Setup

- [ ] **Create (or reuse) a Sentry organization**
  - URL: https://sentry.io/signup/ (free tier is sufficient — single-operator volume)
  - Skip if: Already have a Sentry org

## Dashboard Configuration

- [ ] **Create a Laravel project in Sentry**
  - Location: sentry.io → Settings → Projects → Create Project → Platform: Laravel
  - Keep: default PII settings off (matches `config/sentry.php` `send_default_pii => false`)
  - Copy: the project DSN into the env var above

## Verification

After setting `SENTRY_LARAVEL_DSN` in Railway and redeploying (locally: after adding it to `.env`):

```bash
# 1. SDK connects and can send
php artisan sentry:test

# 2. Publish-path probe exception (no code left behind — report() only)
php artisan tinker --execute="report(new RuntimeException('publish-path-sentry-probe'));"

# 3. Run one real publish, open the issue in Sentry, confirm workspace_id + publish_stage
#    tags and the 'publish' context (content id/type, job id, operation id, run token).
```

Expected results:
- `sentry:test` reports a successfully sent event with an event id
- The probe issue appears in the Sentry project within seconds
- A publish-path issue shows the workspace/stage tags so a Sentry issue maps 1:1 to a grep of the
  publish log channel (D-10)

Caveat (01-RESEARCH.md Assumption A6): if per-queue capture needs explicit calls (queue-job
exceptions not auto-captured through the shared handler), the documented fallback is explicit
capture in the watchdog/exhausted branch — D-11-compliant since the watchdog is publish-path
scope.

---

**Once all items complete:** Mark status as "Complete" at top of file, and set the matching
deferred-items.md entry (phase directory) to `status: resolved`.

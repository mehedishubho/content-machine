# Phase 1: Publish Observability & Monitoring - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-11-22
**Phase:** 1-Publish Observability & Monitoring
**Areas discussed:** Event capture & storage, Error-tracking service, Watchdog alert channel, Success-rate dashboard

---

## Event capture & storage

| Option | Description | Selected |
|--------|-------------|----------|
| DB table + log channel | Normalized publish_events table feeds dashboard metrics; structured log channel gives the forensic trail readable in Railway logs | ✓ |
| Log channel only | Everything structured-logged to stderr; metrics from parsing logs or existing columns | |
| DB table only | Events in a table; diagnosis via dashboard queries instead of log tailing | |

| Option | Description | Selected |
|--------|-------------|----------|
| Dedicated JSON channel | One JSON object per line (job id, content id, run/operation token, stage, group, duration) to stderr | ✓ |
| Default channel + context | Reuse default Laravel channel with withContext(); publish events mix with framework noise | |

| Option | Description | Selected |
|--------|-------------|----------|
| Prune after 90 days | Scheduled prune; month-over-month comparison possible, bounded growth | ✓ |
| Prune after 30 days | Smaller table; charts limited to 30 days | |
| Keep forever | No prune; slow growth at current volume | |

| Option | Description | Selected |
|--------|-------------|----------|
| Lifecycle + provider HTTP | Four lifecycle stages plus 429 waits, timeouts, 5xx in the clients | ✓ |
| Lifecycle stages only | Only claim/checkpoint/retry/finalize; provider calls stay silent | |

| Option | Description | Selected |
|--------|-------------|----------|
| One morph table | Single publish_events table with publishable morph + workspace_id, like StatusTransition | ✓ |
| Separate per type | post_publish_events + video_publish_events; doubles migrations and metric unions | |

| Option | Description | Selected |
|--------|-------------|----------|
| Recorder class | Single PublishEventRecorder writes DB row + emits log line together; one seam to fake | ✓ |
| Model observers | Automatic hooking on publish_state changes; misses provider HTTP and lease internals | |

| Option | Description | Selected |
|--------|-------------|----------|
| After commit, logs always | DB rows best-effort post-commit; log channel fires regardless | ✓ |
| Inside the transaction | Event and state never disagree, but rollback erases the forensic trail | |

| Option | Description | Selected |
|--------|-------------|----------|
| Dashboard only | Visible behind auth + SetCurrentWorkspace; no new API surface this phase | ✓ |
| Dashboard + API v1 | Read-only publish-event endpoints for external automation | |

**User's choice:** All recommended options.
**Notes:** Correlation IDs shared across logs, table, and Sentry were the recurring theme.

---

## Error-tracking service

| Option | Description | Selected |
|--------|-------------|----------|
| Sentry SaaS | Free tier, sentry/sentry-laravel SDK, DSN in app env (runtime credential, not ADR-0002 infra token) | ✓ |
| GlitchTip self-host | Sentry-compatible, self-hosted on Railway; second always-on service to run | |
| Defer to a later phase | Logs + watchdog only; OBS-04 partially delivered | |

| Option | Description | Selected |
|--------|-------------|----------|
| Same IDs as logs | Workspace id, content id, job id, operation/run token — 1:1 with the publish log channel | ✓ |
| Workspace + content only | Smaller payloads; correlating to a run needs a DB lookup | |

| Option | Description | Selected |
|--------|-------------|----------|
| Publish path only | Publish Actions, provider clients, publish jobs, watchdog report; bounded blast radius | ✓ |
| Whole app | Global handler reports everything from day one | |

**User's choice:** All recommended options.

---

## Watchdog alert channel

| Option | Description | Selected |
|--------|-------------|----------|
| Telegram | Existing bot/notification infra and outbound message jobs; zero new dependencies | ✓ |
| Email | Requires a real mail driver (currently log-only) | |
| Sentry + dashboard only | Files a Sentry issue and raises a banner; can sit unseen | |

| Option | Description | Selected |
|--------|-------------|----------|
| Scheduled command | Scheduler already runs every minute; same pattern as dispatch/recovery commands | ✓ |
| Self-rescheduling job | Second scheduling mechanism alongside the scheduler | |

| Option | Description | Selected |
|--------|-------------|----------|
| Stuck + failed + retry-loop | Lease-window expiry, exhausted-retries permanent failure, repeated lease expiries | ✓ |
| Stuck only | Only lease-window case alerts | |

| Option | Description | Selected |
|--------|-------------|----------|
| Once per item/day | Dedup while the condition holds; no spam during long outages | ✓ |
| Every scan | Maximum urgency; up to ~1 alert/minute | |

**User's choice:** All recommended options.

---

## Success-rate dashboard

| Option | Description | Selected |
|--------|-------------|----------|
| Per content item | One outcome per post/video publish attempt; matches PROJECT.md success metric | ✓ |
| Per publish group | Each group counts; precise for partial failures but headline swings with group counts | |

| Option | Description | Selected |
|--------|-------------|----------|
| Dedicated page + home stat | Observability page with chart/counts/outcomes list; compact stat on dashboard home | ✓ |
| Home widget only | Number + small chart, no drill-down | |

| Option | Description | Selected |
|--------|-------------|----------|
| Today/7/30/90 | Selector matching the 90-day retention; default 7 days | ✓ |
| Fixed 7d/30d | Two fixed numbers, no selector | |

| Option | Description | Selected |
|--------|-------------|----------|
| Live SQL aggregate | Counts computed from publish_events per period; trivially fast at current volume | ✓ |
| Pre-aggregated table | Nightly summary job; extra machinery for higher volume | |

**User's choice:** All recommended options.

---

## Claude's Discretion

Column set/indexing beyond named fields, log channel config details, Sentry SDK wiring
(breadcrumbs, sample rates), watchdog command naming and dedup storage, dashboard layout.

## Deferred Ideas

None raised.

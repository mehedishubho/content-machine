# Phase 1 — API Coverage Matrix

Generated at plan time (2026-11-22). Detector verdict: `detected: true` — this phase integrates
an external SDK/service: **Sentry** (`sentry/sentry-laravel` ^4.31.0, D-09) and **consumes the
existing Telegram Bot API infrastructure** for watchdog alerting (D-12).

## Sentry (sentry/sentry-laravel SDK + Sentry SaaS)

Locked scope: D-09 (Sentry SaaS for publish-path exceptions), D-10 (correlation context), D-11
(publish path only, minimal wiring). Discretion areas deferred deliberately where the SDK
defaults already satisfy the phase.

| capability | decision | reason |
|---|---|---|
| exception-capture (handler hook + `report()`) | INTEGRATE | OBS-04 core: publish-path exceptions reach Sentry via `Integration::handles($exceptions)`; publish jobs' existing `failed()` `report()` calls deliver without new plumbing |
| context + tags enrichment (`configureScope`) | INTEGRATE | D-10: workspace/stage tags + publish context so a Sentry issue maps 1:1 to grep-able publish-channel log lines |
| custom breadcrumbs | OPT-OUT | SDK default breadcrumbs suffice at single-operator volume; custom breadcrumb wiring is D-09 discretion deferred — adding it now adds review surface to the publish path for no observed need |
| performance tracing (`traces_sample_rate`) | OPT-OUT | D-11 locks minimal scope; tracing stays off (rate 0/null) — queue-worker spans would add overhead and quota burn for a phase that measures outcomes, not latency |
| release-health / release tagging | OPT-OUT | Deferred within D-09 discretion; Railway deploys `main` without release artifacts today — introducing release tagging implies a deploy-metadata pipeline out of phase scope |
| cron-monitoring for scheduled commands | OPT-OUT | The watchdog already alerts on its own conditions (D-12); Sentry cron monitors would duplicate alerting and require per-job check-in wiring in the scheduler |
| Sentry-native alert rules/notifications | OPT-OUT | D-12 locks Telegram as the operator alert channel; Sentry issues are for forensics, not paging |
| user PII / `send_default_pii` | OPT-OUT | Stays `false` (default) — ASVS V7/Pitfall 4: provider keys and personal data must never reach a third-party SaaS |
| quota/error sampling tuning | INTEGRATE | Part of exception-capture setup (SDK defaults; `SENTRY_LARAVEL_DSN` unset keeps dev/test inert per Pitfall 3) |

## Telegram Bot API (existing infrastructure — consumer, not new integration)

The Telegram outbound path (`QueueTelegramNotificationAction` → `SendTelegramOutboundMessageJob`)
was shipped in a prior phase. This phase adds a new **consumer** (watchdog alerts) against the
same surface; per the second-integration rule the capability is re-decided from full coverage.

| capability | decision | reason |
|---|---|---|
| send outbound message (existing outbox path) | INTEGRATE | D-12 locks the existing Telegram infrastructure for watchdog alerts; reused via the existing Action — no new API client |
| outbox dedup (`logical_key`) | INTEGRATE | D-15's one-alert-per-content-per-day is expressed in the `logical_key` as the second dedup layer |
| webhook receive / bot management | OPT-OUT | No new webhook or bot-management surface in this phase (ADR-0001 Telegram scope unchanged; observability only sends) |
| media/attachment send | OPT-OUT | Alerts are plain-text (ids/state only, ASVS V7) — no media in watchdog messages |

# Decisions (from ADRs)

## 0001: Receive Telegram updates via webhook, not polling
- source: docs/adr/0001-webhook-not-polling.md
- status: locked (Accepted)
- decision: Use a Telegram webhook, delivered to a public HTTPS endpoint on the always-on deployment, instead of polling. The endpoint is authenticated two ways: a secret, unguessable path segment (the webhook URL itself is not discoverable, unlike a fixed /telegram/webhook route) and the X-Telegram-Bot-Api-Secret-Token header, set when the webhook is registered and checked on every incoming request.
- scope: Telegram webhook; getUpdates polling; webhook authentication (secret path segment + X-Telegram-Bot-Api-Secret-Token header)

## 0002: Keep operational/infra credentials out of the app's `.env`
- source: docs/adr/0002-operational-secrets-outside-app-env.md
- status: locked (Accepted)
- decision: Operational and infrastructure credentials (the Railway CLI token today; whatever else needs one later) live in `.secrets/<service>.env`, one file per service, entirely separate from the app's own `.env`. The whole directory is gitignored except a README.md and *.env.example templates. See `.secrets/README.md`.
- scope: .secrets/<service>.env; Railway CLI token; app .env; credential isolation

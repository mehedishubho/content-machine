# Constraints (from SPECs)

## PostSyncer v1 design decisions (spec "Decisions (locked)" table)
- source: docs/superpowers/specs/2026-08-25-postsyncer-in-cm-design.md
- type: api-contract
- content:
  - v1 surface: Dashboard settings + Schedule / Publish now + workspace-token publish API
  - Config home: per CM workspace Settings UI (API key, bases, Bangla/English maps, post-type rules)
  - Scope: posts AND videos
  - Video media: Google Drive URLs on the video (video_drive_url, cover_drive_url); PostSyncer link upload
  - Post media: prefer CM attachments; else image_drive_urls[] (hybrid)
  - Bilingual: full parity (auto-split by language / media set / call shape); store all PostSyncer ids
  - Execution: queue jobs + dashboard progress (not sync HTTP in the web request)
  - List UI: Studio-like status tabs including Ideation
  Note: the table is the spec's own "Decisions (locked)" wording. At ingest precedence this is a SPEC
  (locked=false), not ADR-grade — see INGEST-CONFLICTS.md WARNING. Spec header marks it a historical
  design snapshot; implementation has since added publish recovery, video publishing, rescheduling,
  and scheduled-status synchronization.

## Workspace settings.postsyncer shape
- source: docs/superpowers/specs/2026-08-25-postsyncer-in-cm-design.md
- type: schema
- content:
  Stored on workspaces.settings (JSON); secrets encrypted at rest.
    postsyncer:
      api_key        # secret, encrypted
      api_base       # default https://postsyncer.com/api/v1
      upload_base    # default https://upload.postsyncer.com/api/v1
      languages:
        bangla:  { workspace_id, platforms: { facebook: { account_id, handle }, instagram: { ... } } }
        english: { workspace_id, platforms: { ... } }
      post_types:    # platform x type -> on | off | ask | unsupported
  Settings UI capabilities (from same spec): write-only API key (show configured / rotate), editable
  bases, Bangla/English workspace_id, per-platform account id + handle, Refresh accounts (GET /accounts
  diff, apply), post-type matrix (on / off / ask / unsupported). Authorization: workspace owner/admin
  only. Publish guards: missing API key, missing language workspace_id, or platform with null
  account_id disables Schedule/Publish or the platform chip.

## Post/video publish columns
- source: docs/superpowers/specs/2026-08-25-postsyncer-in-cm-design.md
- type: schema
- content:
  videos add: video_drive_url (string nullable; required before schedule/publish), cover_drive_url
  (string nullable), postsyncer (jsonb nullable), publish_progress (jsonb nullable — private resumable
  checkpoint, not part of the API resource), publish_state (string: idle | queued | running |
  succeeded | failed), publish_error (text nullable)
  posts add: image_drive_urls (jsonb nullable — array of Drive URLs when attachments absent),
  postsyncer (jsonb nullable), publish_progress (jsonb nullable — private, not in API resource),
  publish_state (same enum), publish_error (text nullable)

## postsyncer JSON groups shape
- source: docs/superpowers/specs/2026-08-25-postsyncer-in-cm-design.md
- type: schema
- content:
  { "groups": [ { "post_id": "132129", "status": "SCHEDULED", "scheduled_at": "2026-08-26 09:12",
  "platforms": ["facebook", "instagram"], "language": "bangla" } ], "job_id": "optional-queue-id" }
  Written publicly only after the whole operation plan succeeds.

## Publish outcome semantics and status transitions
- source: docs/superpowers/specs/2026-08-25-postsyncer-in-cm-design.md
- type: api-contract
- content:
  - Schedule (future when) -> content status = scheduled; Publish now -> status = posted; failure ->
    pipeline status unchanged, publish_state = failed.
  - Public success path is all-or-nothing per operation: if any group fails, do not set
    scheduled/posted and do not write partial ids to public postsyncer.groups.
  - Private publish_progress checkpoint records normalized options, plan hash, completed group ids,
    and the current group before its external create; a normal retry skips completed groups and
    preserves the original options.
  - If the POST /posts response may have been lost, the operation is marked uncertain and must be
    reconciled (manual, not automatic retry) via postsyncer:reconcile-post / postsyncer:reconcile-video;
    the command checkpoints the verified group before the normal Retry continues.

## PostSyncer HTTP client endpoints
- source: docs/superpowers/specs/2026-08-25-postsyncer-in-cm-design.md; docs/superpowers/plans/2026-08-25-postsyncer-in-cm.md
- type: api-contract
- content:
  - Auth: Authorization: Bearer {apiKey}
  - POST {api_base}/media/upload/url   body { workspace_id, urls: [...] } -> { media: [{ id, ... }], count_stored }
    (link upload; not Tailscale multipart)
  - POST {api_base}/posts              create body ported from personal-content build_post_body
  - GET  {api_base}/accounts?workspace_id=   (account refresh; query param was to be confirmed against live docs)
  Defaults at planning time: api_base https://postsyncer.com/api/v1, upload_base
  https://upload.postsyncer.com/api/v1. Spec open points (resolve at implement, not blockers): exact
  link-upload request/response schema; whether attachment URLs must be publicly fetchable by
  PostSyncer or need a short-lived signed URL CM exposes.

## Publish queue/worker contract
- source: docs/superpowers/specs/2026-08-25-postsyncer-in-cm-design.md; docs/superpowers/plans/2026-08-25-postsyncer-in-cm.md
- type: api-contract
- content:
  - Post publishes use the dedicated `postsyncer` database connection and queue on `cm-web`, separate
    from the default `scratchpad` queue (which remains for Telegram/media jobs) — the database driver
    does not store a connection name per job row.
  - Worker timeout 900 seconds; dedicated queue visibility window 960 seconds.
  - publish_progress is private and not returned by PostResource; callers use publish_state,
    publish_error, and final postsyncer.groups.
  - Jobs take (Model $model, array $options) and only call Actions; dashboard and workspace-token API
    both enqueue the same job (CM is the only publish surface).
  - Cutover flag: workspace postsyncer.publish_enabled (or env); when false, CM hides Schedule/Publish.

## PostSyncer plan global constraints
- source: docs/superpowers/plans/2026-08-25-postsyncer-in-cm.md
- type: nfr
- content:
  - No em dashes in user-facing copy
  - Workspace-scope every query (Workspace::current())
  - No live PostSyncer calls in tests (Http::fake)
  - Media via PostSyncer link upload (POST /api/v1/media/upload/url), not Tailscale multipart
  - Pipeline status flips only on full job success; retries resume private checkpoints rather than
    replaying completed groups
  - Follow existing Action + Job patterns (SummarizeCaptureJob delegates to an Action)
  Plan header: historical snapshot; checkboxes not maintained; implementation present at baseline
  fafde1e (2026-09-24). Verified remaining API gaps per docs/guides/api.md: deck-package upload
  endpoints, idea promotion over the API, rate limiting beyond the default throttle.

## PostSyncer plan component interfaces
- source: docs/superpowers/plans/2026-08-25-postsyncer-in-cm.md
- type: api-contract
- content:
  - PostsyncerConfig: fromWorkspace(), apiKey() (decrypted), apiBase(), uploadBase(), language($lang),
    postTypes(), publishEnabled(), isConfigured(), write($workspace, $input) — Crypt::encryptString for
    api_key; blank key keeps existing
  - PostsyncerClient: listAccounts($workspaceId), uploadFromUrls($workspaceId, $urls) -> media ids,
    createPost($body) -> post payload (id, status, scheduled_at); errors -> PostsyncerException
  - PublishGroup DTO: language, workspaceId, platforms, mediaUrls, captions (platform -> text),
    ?when (nullable datetime), publishNow
  - PostPublishPlanner::plan(Post, PostsyncerConfig, options { when, platforms?, confirm_ask }) ->
    list<PublishGroup>; bilingual split (>=2 groups targeting bangla/english workspace ids), Twitter
    thread isolation into its own group, Threads not merged into the Twitter thread group,
    per-language media-set splits
  - VideoPublishPlanner::plan(Video, ...) -> usually one group, mediaUrls = [video, cover?],
    reel-capable platforms from settings n options
  - MediaUrlResolver: forPost prefers attachment Storage URLs when attachments exist, else
    image_drive_urls (empty array = text-only allowed by planner); forVideo requires video_drive_url
    or throws InvalidArgumentException
  - PublishPostAction::handle(Post, options) / PublishVideoAction: set publish_state running ->
    per-group upload/checkpoint/create -> full success writes postsyncer.groups + pipeline status +
    publish_state=succeeded; failure keeps pipeline status, publish_state=failed
  - Video job owns an operation fence and a shared per-video overlap lock; video reconcile verifies
    workspace, media, cover, accounts, platforms, and schedule before recording an id

## Video series domain model
- source: docs/superpowers/specs/2026-09-28-video-series-design.md
- type: schema
- content:
  - Workspace-scoped series with a stable slug and title; a video belongs to at most one series;
    a part number is unique within that series.
  - VPN series initially contains V-85 through V-89 as parts 1 through 5; more parts can be appended later.
  - Every read is scoped to the current workspace. Existing video API includes series slug, title,
    and part when present. Series pages remain useful when some videos are Ready and others Pending.
  - UI uses the app's existing typography and navigation rather than presentation artwork.

## PUT /api/v1/series/{slug} contract
- source: docs/superpowers/specs/2026-09-28-video-series-design.md
- type: api-contract
- content:
  - Workspace-token API; idempotent; accepts title and an ordered videos array of video human IDs.
  - Validates every video belongs to the same workspace; rejects duplicates or videos already assigned
    to another series; atomically replaces the series order.
  - Provides a maintained path for adding later parts and for the initial VPN assignment without
    hard-coding production IDs in a migration.
  - Does not alter video status, script, captions, deck, or publish state.

## Video series implementation constraints
- source: docs/superpowers/plans/2026-09-28-video-series.md
- type: nfr
- content:
  - Keep series data scoped to the current workspace.
  - Do not change video lifecycle, script, deck, captions, or publishing state.
  - Preserve explicit unique part order and allow later parts.
  - Review invariants: cross-workspace video IDs rejected; duplicate IDs or part numbers rejected;
    reordering atomic (no mixed old/new positions); a video in another series must not be silently
    moved; series pages must not leak another workspace's titles.
  - Stack per plan: Laravel 13, PostgreSQL, Inertia 3, React 19, TypeScript, Pest.
  Plan status: Tasks 1-2 checked complete; Task 3 unchecked (open PR, VPN assignment V-85 through
  V-89 via the API, live verification still pending).

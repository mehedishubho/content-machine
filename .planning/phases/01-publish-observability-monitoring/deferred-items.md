## Deferred Items

- Local Windows environment: 3 pre-existing test failures unrelated to Phase 01 work
  status: open
  **What:** `CleanupOrphanMediaActionTest::test_it_refuses_a_symlinked_scratchpad_root` and
  `CleanupOrphanMediaCommandTest` (symlink(): Permission denied — Windows requires
  Developer Mode for symlinks) and `PostsyncerClientTest::test_download_cap_progress_callback_really_aborts_a_curl_transfer`
  (proc_open(/dev/null) — no /dev/null for proc_open on Windows). All three fail for
  platform reasons on this machine regardless of Phase 01 changes; CI (Linux) covers them.
- Native postgresql-x64-18 service on 127.0.0.1:5432 is unreachable with project .env
  credentials (auth fails for user "root") — tests therefore run against a disposable
  postgres:17 container (gsd-testing-pg, port 5544, CI-equivalent credentials).
  status: open
- Windows full-suite runs at the default memory limit die mid-suite with "Premature end of
  PHP process" inside stream/proc-heavy tests (PostsyncerClientTest upload/download,
  HttpTelegramClientTest download, PostsApiTest motion upload). 01-02 verified the crash
  reproduces at HEAD without plan 01-02 changes (control run) and completes green with
  `php -d memory_limit=4G ./vendor/bin/pest` (1603/1606 — only the 3 platform failures
  above). Run long suites on this machine with a raised memory limit.
  status: open
- Sentry end-to-end delivery unverified — SENTRY_LARAVEL_DSN not provisioned (plan 01-04
  Task 3 checkpoint deferred by user 2026-11-23). Code shipped inert-by-design (Pitfall 3:
  SDK silent while the DSN is unset); provisioning steps + verification commands are in
  01-04-PLAN.md Task 3 and 01-USER-SETUP.md. OBS-04 end-to-end = human follow-up.
  status: open

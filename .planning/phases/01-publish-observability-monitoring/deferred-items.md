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

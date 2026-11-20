---
last_mapped_commit: effec39acc3dc5c3680e91bdd23476a750c15c41
last_mapped_at: 2026-11-21
---
# Testing Patterns

**Analysis Date:** 2026-11-21

## Test Framework

**Runner:**
- Pest ^5.1 with `pestphp/pest-plugin-laravel`, `pestphp/pest-plugin-drift` (from `composer.json`)
- Base config: `phpunit.xml` (Unit + Feature suites, source directory `app/`)
- Pest bootstrap: `tests/Pest.php` — binds all tests to `Tests\TestCase` via `pest()->extend(Tests\TestCase::class)->in('Feature')`; RefreshDatabase is intentionally NOT applied globally (each test class opts in with `use RefreshDatabase;`)

**Run Commands (all three must pass before any commit):**
```bash
./vendor/bin/pest                                    # Run all tests (serial, never parallel)
./vendor/bin/pint --test                             # Style check
./vendor/bin/phpstan analyse --memory-limit=1G       # Larastan level 7
```

**Database:** Real PostgreSQL is required — `phpunit.xml` deliberately does not override `DB_CONNECTION` (an inline comment explains tests depend on Postgres-specific JSONB, partial unique indexes, CHECK constraints, and `lockForUpdate` in `ReserveContentIdAction`). CI (`.github/workflows/ci.yml`) uses a `postgres:17` service. Run Pest serially to match CI — parallel workers race migrations on a shared schema.

## Test File Organization

**Location:** Separate `tests/` tree mirroring `app/` structure

**Structure:**
```
tests/
├── Pest.php                     # Pest bootstrap, expectations, helpers
├── TestCase.php                 # Base case: withoutVite(), skipUnlessFortifyHas(), direct middleware Request builder
├── Support/                     # Test support (e.g. FakesPublicDns.php, Telegram/)
├── Fixtures/
├── Unit/
│   ├── Actions/{Domain}/        # One test per Action (verified for AiProviders…Videos, all domains)
│   ├── Concerns/ Data/ Jobs/ Middleware/ Models/ Support/
└── Feature/
    ├── Api/                     # API endpoint tests (VideosApiTest, PostsApiTest, …)
    ├── Auth/ Console/ Database/ Mcp/ Media/ Posts/ Settings/ Socialreins/ Team/ Telegram/ …
    └── {Domain}ControllerTest.php
```

**Naming:** `{ClassName}Test.php`; test methods are descriptive `test_it_...` / `test_...` sentences.

## Test Structure

**Suite Organization (class-based Pest, PHPUnit-style):**
```php
// tests/Unit/Actions/Ideas/DropIdeaActionTest.php
class DropIdeaActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sets_status_dropped_and_the_reason()
    {
        $idea = Idea::factory()->create(['status' => 'open']);

        $dropped = (new DropIdeaAction)->handle($idea, new DropIdeaData(dropReason: 'No longer relevant.'));

        $this->assertSame('dropped', $dropped->status);
        $this->assertDatabaseHas('ideas', ['id' => $idea->id, 'status' => 'dropped']);
    }

    public function test_an_already_dropped_idea_cannot_be_dropped_again()
    {
        $idea = Idea::factory()->dropped()->create();
        $this->expectException(RuntimeException::class);
        (new DropIdeaAction)->handle($idea, new DropIdeaData(dropReason: 'Again.'));
    }
}
```

**Patterns:**
- Actions are invoked directly: `(new SomeAction)->handle(...)` — no container resolution needed for plain Actions
- Arrange/Act/Assert, one behavior per test method
- Both the returned model AND the persisted row are asserted (`assertSame` + `assertDatabaseHas`)
- History side effects asserted against `status_transitions` rows (actor/subject/from/to/reason columns)
- Unit tests may authenticate via `$this->actingAs($user)` when `RecordsHistory` needs an actor

## Mocking

**Framework:** Mockery (in `composer.json` require-dev) + Laravel's `Http::fake()` for outbound HTTP

**Mockery pattern (collaborator services/jobs):**
```php
// tests/Unit/Jobs/PublishPostJobTest.php
$action = Mockery::mock(PublishPostAction::class);
$action->shouldReceive('handle')->once()->with(
    Mockery::on(fn (Post $p) => $p->is($post)),
    $options,
    Mockery::type('string'),
);
(new PublishPostJob($post, $options))->handle($action);
```
Used in `tests/Unit/Jobs/` (PublishPostJobTest, PublishVideoJobTest, GenerateTelegramPostJobTest, TranscribeVoiceNoteJobTest, SummarizeCaptureJobTest) and `tests/Unit/Actions/Pexels/`.

**HTTP fakes (external services):**
```php
// tests/Feature/AiProviders/AiProviderCredentialsControllerTest.php
Http::fake(['api.anthropic.com/*' => Http::response(['data' => []], 200)]);
Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['message' => 'invalid key']], 401)]);
```
Also callback-style: `Http::fake(function (ClientRequest $request) use (&$metadataCalls) {...})` in `tests/Feature/Api/GoogleDriveApiTest.php`.

**What to Mock:**
- Collaborator Actions injected into Jobs (assert delegation with `->once()->with(...)`)
- Outbound HTTP via `Http::fake()` keyed by provider host (Anthropic, OpenRouter, Google Drive, Pexels, Telegram, PostSyncer/SocialReins)
- DNS when needed: `tests/Support/FakesPublicDns.php`

**What NOT to Mock:**
- The database — real Postgres, real migrations, `RefreshDatabase` per class
- Eloquent models and DTOs — construct real ones
- The Action under test

## Fixtures and Factories

**Test Data:** Laravel factories with state modifiers:
```php
$idea = Idea::factory()->create(['status' => 'open']);
$idea = Idea::factory()->dropped()->create();
```

**Location:** Factories in `database/factories/`; shared helpers in `tests/Support/`; static fixtures in `tests/Fixtures/` (note: `tests/Fixtures` is included in phpstan analysis paths, unlike most projects).

**Base TestCase helpers** (`tests/TestCase.php`):
- `$this->withoutVite()` in `setUp()` — CI runs without a frontend build
- `skipUnlessFortifyHas(string $feature)` — conditional Fortify feature tests
- Builds `Request` with a user resolver for unit-testing middleware without the HTTP kernel

## Coverage

**Requirements:** None enforced (no coverage threshold configured)

**View Coverage:**
```bash
./vendor/bin/pest --coverage
```

## Test Types

**Unit Tests (`tests/Unit/`):**
- Actions in isolation against the real database
- Jobs: delegation + serialization-safety (e.g. `unserialize(serialize($job))` legacy-job test in `PublishPostJobTest`)
- Models, Concerns, Middleware, DTOs (`tests/Unit/Data/`)

**Feature Tests (`tests/Feature/`):**
- HTTP endpoints via Laravel test client; API tests under `tests/Feature/Api/`
- Controllers exercised through real routes with `Http::fake()` for upstreams
- Webhook handling (`tests/Feature/Telegram/TelegramWebhookControllerTest.php`)

**E2E/Browser Tests:** Not used (no Dusk/Playwright detected)

## Common Patterns

**Error Testing:**
```php
$this->expectException(RuntimeException::class);
(new DropIdeaAction)->handle($idea, new DropIdeaData(dropReason: 'Again.'));
```

**Required for new code (per `CLAUDE.md`):**
- Every Action → `tests/Unit/Actions/{Domain}/`
- Every DTO boundary-crossing constructor (`fromRequest`/`fromArray`) → `tests/Unit/Data/`
- Every policy/gate and non-trivial trait → tests in `tests/Unit/`
- Test DB env lives in `.env` (`DB_CONNECTION=pgsql`), not overridden in `phpunit.xml`

---

*Testing analysis: 2026-11-21*

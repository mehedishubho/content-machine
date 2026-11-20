---
last_mapped_commit: effec39acc3dc5c3680e91bdd23476a750c15c41
last_mapped_at: 2026-11-21
---
# Coding Conventions

**Analysis Date:** 2026-11-21

These conventions are strict project rules from `CLAUDE.md`, verified against the actual code. A PR that violates them gets fixed before merge.

## Naming Patterns

**Files:**
- PHP Actions: PascalCase `{Verb}{Noun}Action.php` in `app/Actions/{Domain}/` (e.g. `app/Actions/Ideas/CreateIdeaAction.php`, `app/Actions/Posts/ApprovePostAction.php`)
- PHP DTOs: PascalCase `{Name}Data.php` in `app/Data/{Domain}/` (e.g. `app/Data/Ideas/DropIdeaData.php`)
- Pest tests mirror the class name: `DropIdeaActionTest.php` for `DropIdeaAction`
- React pages: lowercase directories per domain (`resources/js/pages/ideas/index.tsx`, `show.tsx`)
- Contracts: `{Name}Provider.php` style interfaces in `app/Contracts/` (e.g. `PexelsProvider.php`)

**Functions:**
- PHP: camelCase, single public `handle(...): mixed` entry point on every Action
- DTO named constructors: `::fromRequest(FormRequest $request): self`, `::fromArray(array $data): self`
- TypeScript/React: camelCase functions/variables, PascalCase components

**Variables:**
- PHP: `$camelCase`; constructor-promoted `private readonly` for injected dependencies
- Test methods: `test_it_...` / `test_...` snake_case descriptive names (see `tests/Unit/Actions/Ideas/DropIdeaActionTest.php`)

**Types:**
- PHP DTOs: constructor-promoted `public readonly` properties, no setters
- TS: local `type` aliases for props/shapes (e.g. `type PaginatedIdeas = {...}` in `resources/js/pages/ideas/index.tsx`)

## Core Patterns (enforced)

**Action pattern — one class per write operation:**
- Every write lives in `app/Actions/{Domain}/{Verb}{Noun}Action.php`. Plain class, constructor-injected dependencies, one public `handle()`. No lorisleiva/laravel-actions.
- Controllers are thin: Form Request → DTO → one Action → Inertia response/redirect/Resource. Business logic in a controller method is treated as a bug.
- Models stay thin: relationships, casts, scopes, accessors only. Any "decide what should happen" method belongs in an Action.
- Example: `app/Actions/Ideas/CreateIdeaAction.php` — wraps work in `DB::transaction`, validates with `in_array` checks + `ValidationException`, delegates id reservation to `ReserveContentIdAction`.

**DTOs at every boundary:**
- `app/Data/{Domain}/`, plain PHP 8.3+ `readonly` classes, no spatie/laravel-data.
- Never pass `$request->all()` or raw arrays into an Action — build the DTO first.
- DTOs are data only: `toArray()` / computed accessors are fine, no side effects.

**SOLID / dependency inversion:**
- External integrations are interfaces in `app/Contracts/` (e.g. `app/Contracts/PexelsProvider.php`), bound in `app/Providers/`, injected by interface into Actions.
- New behavior = new class implementing an existing interface, not an `if` branch.

**DRY:**
- Shared logic goes in traits (`app/Concerns/`: `BelongsToWorkspace.php`, `RecordsHistory.php`, `ResolvesByHumanId.php`), small services, or scopes. Config-shaped repetition goes in `config/*.php`.

## Code Style

**PHP formatting:**
- Laravel Pint, `laravel` preset only (`pint.json`: `{"preset": "laravel"}`)
- Run: `./vendor/bin/pint --test` (must pass before commit)
- PHP 8.3+ syntax encouraged: constructor promotion, `readonly`, `match` expressions, strict `in_array(..., true)`

**Static analysis:**
- Larastan level 7 (`phpstan.neon`, paths: `app/`, `bootstrap/app.php`, `config/`, `database/`, `routes/`, `tests/Fixtures`)
- Run: `./vendor/bin/phpstan analyse --memory-limit=1G`
- Docblock `@param` types on `handle()` methods when arrays cross boundaries (see `CreateIdeaAction::handle` `@param array<string, mixed> $attributes`)

**Frontend formatting/linting:**
- ESLint flat config (`eslint.config.js`): `@eslint/js` recommended, `typescript-eslint` recommended, `eslint-plugin-react` (jsx-runtime, `react-in-jsx-scope` off, `prop-types` off, `no-unescaped-entities` off), `react-hooks` recommended, `eslint-plugin-import`, `eslint-config-prettier`
- `@stylistic/eslint-plugin` enforces blank lines around control statements (`if`/`return`/`for`/`while`/`switch`/`try`/`throw`)
- TypeScript strict via `tsconfig.json`; Vite build (`vite.config.ts`)

## Import Organization

**PHP:**
1. `App\...` classes
2. Framework classes (`Illuminate\...`, `Laravel\...`)
3. Vendor/third-party (e.g. `Mockery`)
4. Root-namespace classes (`RuntimeException`, `Tests\TestCase`)

**TypeScript:**
1. Packages (`@inertiajs/react`, `react`)
2. `@/` aliases: `@/components/...`, `@/routes/...` (Wayfinder-generated route modules like `@/routes/dashboard/ideas`), `@/actions/App/Http/Controllers/...` (Wayfinder-generated controller imports)

## Error Handling

**Patterns:**
- Validation failures inside Actions: `ValidationException::withMessages(['field' => 'message'])` (see `CreateIdeaAction`)
- Domain-rule violations (invalid state transitions): throw plain SPL exceptions (`RuntimeException` in DropIdeaAction; tested with `expectException`)
- Form Requests handle HTTP-boundary validation; Actions re-validate invariants for internal callers
- Jobs propagate exceptions (tested via `expectException(RuntimeException)` in `tests/Unit/Jobs/PublishPostJobTest.php`)

## Logging

**Framework:** Laravel default (`Log` facade where needed); queued jobs rely on failed-job handling. No custom logging wrapper detected.

## Comments

**When to Comment:**
- Docblocks explaining *why* / idempotency contracts (e.g. `CreateIdeaAction` docblock explains `human_id` import semantics referencing ticket IDs PI-20/VI-27)
- Configration rationale in comments (e.g. `phpunit.xml` explains why no SQLite override is set)

## Function Design

**Size:** Actions may be moderately long if it's one transactional operation; extract helpers as `private` methods (e.g. `numberFromHumanId()` in `CreateIdeaAction`)
**Parameters:** Actions take typed models + DTOs; `handle(...)` signatures vary per operation
**Return Values:** Actions return the affected Eloquent model (`handle(...): Idea`) or a result object; typed return declarations everywhere

## Module Design

**Exports:**
- PHP: one class per file, PSR-4 `App\` namespace mirroring directory
- TS pages: default-export React component per page file

**Barrel Files:** `index.tsx` used as the page entry inside page directories (e.g. `resources/js/pages/ideas/index.tsx`); no PHP barrel pattern

## Test-adjacent Conventions

- Factories for model setup (`Idea::factory()->create()`, `->dropped()` state modifiers)
- Every Action gets a Pest test in `tests/Unit/Actions/{Domain}/`
- Listeners (`app/Listeners/`) are NOT manually registered in providers — Laravel auto-discovers from `handle()` type-hints; double registration makes them fire twice

---

*Convention analysis: 2026-11-21*

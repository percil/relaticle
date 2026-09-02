# Testing Patterns

**Analysis Date:** 2026-09-02

## Test Framework

**Runner:**
- Pest PHP, configured via `phpunit.xml` (local) and `phpunit.ci.xml` (CI overlay — identical testsuites and base env plus CI-only overrides).
- Bootstrap and shared config: `tests/Pest.php`.
- Base test case: `tests/TestCase.php`.

**Assertion Library:**
- Pest's native `expect()`/`it()` syntax plus Pest-Laravel/Livewire assertion helpers (`assertSee`, `assertNotified`, `assertForbidden`, etc.).

**Run Commands:**
```bash
composer test:pest              # normal local run: parallel, TIA (Test Impact Analysis) enabled, excludes Browser
composer test:pest:full         # complete non-TIA merge gate (run before pushing)
php artisan test --compact      # targeted run, e.g. with --filter
vendor/bin/pint --dirty --format agent   # style
vendor/bin/rector --dry-run              # refactor suggestions
vendor/bin/phpstan analyse                # static analysis
composer test:type-coverage               # 100% type coverage gate
composer test:lint              # full-repo pint --test --parallel (run before pushing)
composer test:update-shards     # refresh tests/.pest/shards.json after materially changing test timings
```

**Test Impact Analysis (TIA) caveat:** `composer test:pest` is a local accelerator only — it replays a cached pass when a test's dependency edges are unchanged, so it is blind to time-dependent failures (`travelTo`, expiring tokens), `.env` edits, and dynamic dispatch not traced during recording. Always confirm with `composer test:pest:full` before pushing.

## Test File Organization

**Testing Trophy layers — every `*Test.php` file must live in one of these declared suites** (enforced by `tests/Arch/TestSuiteIntegrityTest.php`; files outside a declared suite silently never run):

| Layer | Directory | Scope |
|---|---|---|
| Architecture | `tests/Arch/` | structural rules, module boundaries |
| PHPStan rules | `tests/PHPStan/` | tests for the custom static-analysis rules |
| Smoke | `tests/Smoke/` | HTTP-level route smoke |
| Workflow | `tests/Feature/` | bulk of the suite, through real entry points |
| Browser | `tests/Browser/` | critical paths only |

There is deliberately **no `tests/Unit/` suite**. Do not create new top-level test directories; if one is ever genuinely needed, declare it in BOTH `phpunit.xml` and `phpunit.ci.xml` (`tests/Arch/TestSuiteIntegrityTest.php` enforces they stay in sync).

**Naming:**
- Feature test files grouped by domain subfolder, e.g. `tests/Feature/Chat/PlanProposalTest.php`, `tests/Feature/Chat/BulkDeleteToolTest.php`, `tests/Feature/Billing/*Test.php`.
- One `*Test.php` per feature/behavior area, not necessarily 1:1 with a single class.

**Suite declaration (`phpunit.xml`):**
```xml
<testsuites>
    <testsuite name="Default">
        <directory>tests/Arch</directory>
        <directory>tests/PHPStan</directory>
        <directory>tests/Smoke</directory>
        <directory>tests/Feature</directory>
    </testsuite>
    <testsuite name="Arch"><directory>tests/Arch</directory></testsuite>
    <testsuite name="PHPStan"><directory>tests/PHPStan</directory></testsuite>
    <testsuite name="Smoke"><directory>tests/Smoke</directory></testsuite>
    <testsuite name="Feature"><directory>tests/Feature</directory></testsuite>
    <testsuite name="Browser"><directory>tests/Browser</directory></testsuite>
</testsuites>
```
`Default` (the implicit local/CI run) excludes `Browser` — browser tests run separately.

## Test Environment (`phpunit.xml` `<php>` block)

- `DB_CONNECTION=pgsql` (PostgreSQL exclusively; no SQLite fallback).
- `DB_DATABASE` deliberately omitted here — comes from `.env.testing` so parallel workspaces isolate their own testing databases; CI pins its own value in `phpunit.ci.xml`.
- `QUEUE_CONNECTION=sync`, `CACHE_STORE=array`, `SESSION_DRIVER=array`, `MAIL_MAILER=array`, `PENNANT_STORE=array`.
- Fake API keys pre-set: `ANTHROPIC_API_KEY=fake-anthropic-key`, `OPENAI_API_KEY=fake-openai-key`.
- `BCRYPT_ROUNDS=4` (fast hashing in tests).
- `TELESCOPE_ENABLED=false`, `PULSE_ENABLED=false`, `HONEYPOT_ENABLED=false`.

**Important:** sync-queue testing is explicitly called out (CLAUDE.md) as masking real production bug classes for chat features (message ordering, approval races, duplicate proposals). Chat feature changes must additionally be verified against the production-shaped stack (Horizon running, `QUEUE_CONNECTION=redis`, Reverb up) via a real browser walkthrough before being reported done — this is beyond what the automated Pest suite covers.

## Test Structure

**Global bootstrap (`tests/Pest.php`):**
```php
pest()->extend(TestCase::class)
    ->use(LazilyRefreshDatabase::class)
    ->in('Feature', 'Smoke', 'Browser');
```
`TestCase` + `LazilyRefreshDatabase` binding is centralized here — do not repeat `uses(...)` per file in Feature/Smoke/Browser.

**Suite organization (actual pattern, `tests/Feature/Billing/BillingPageTest.php`-style):**
```php
use App\Actions\Billing\CreateProCheckout;
use App\Filament\Pages\Billing;

mutates(
    Billing::class,
    CreateProCheckout::class,
    // ...other classes this file exercises
);

beforeEach(function (): void {
    Feature::define(BillingFeature::class, true);
    config()->set('services.stripe.prices.pro_monthly', 'price_pro_monthly_test');
});

it('is forbidden when the billing feature is off', function (): void {
    Feature::define(BillingFeature::class, false);
    billingPageOwner();

    livewire(Billing::class)->assertForbidden();
});
```

- `mutates(ClassName::class, ...)` declared at the top of the file to map which source classes each test file covers (used for mutation testing, not a CI gate).
- Domain-specific setup extracted into small helper functions defined in the test file itself (e.g. `billingPageOwner(): array` returning `[User, Team]`), not global fixtures, when scoped to one test file.
- `beforeEach()` for shared per-test setup (feature flags, config overrides).
- Descriptive `it('...')` strings phrased as behavior/outcome statements, often with an inline comment explaining *why* a regression test exists (issue reference, prior bug description) directly above the `it(...)` block.

## Mocking / Faking

- Laravel's native fakes are used directly, no separate mocking framework observed as primary pattern: `Queue::fake()`, `Mail::fake()`, `Http::fake()`, `Notification::fake()`, `Event::fake()` (seen across `tests/Feature/Chat/*Test.php`, `tests/Feature/Documentation/MarkdownResponseTest.php`).
- Chat SSE/Anthropic streaming responses faked via a dedicated helper: `tests/Helpers/AnthropicSse.php`.
- Domain-specific test helpers under `tests/Helpers/`:
  - `ChatBrowser.php` — seeds chat conversations for browser tests (e.g. `ChatBrowser::seedConversation($user, $teamId, $title, $conversationId)`).
  - `ChatCatalog.php`, `ChatDocument.php` — chat fixture builders.
  - `ImportExecutionFixture.php`, `ProposalCardFixture.php` — domain fixture builders for import/proposal-card flows.
  - `PestTiaRuntime.php`, `TiaRunLock.php` — internal TIA (Test Impact Analysis) runtime plumbing, not domain fixtures.
- Broadcasting channel authorization tested by invoking the real registered closure via reflection rather than re-implementing the logic: `chatChannelAuth()` and `userChannelAuth()` helpers in `tests/Pest.php` pull the closure off the `Broadcaster` contract's `channels` property and call it directly, so tests exercise the actual production callback.

**What to fake:** external side effects (queue, mail, HTTP calls to third parties, streaming SSE responses from Anthropic/OpenAI).

**What NOT to mock:** internal action classes, services, or enums — CLAUDE.md/testing rules explicitly forbid isolated unit tests of internals. Everything is tested through its real entry point.

## Fixtures and Factories

**Factories** live in `database/factories/`, one per model, standard Laravel `Factory` pattern:
```php
final class CompanyFactory extends Factory
{
    protected $model = Company::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'account_owner_id' => User::factory(),
            'team_id' => Team::factory(),
        ];
    }

    /** @phpstan-return static */
    public function configure(): static
    {
        return $this->sequence(fn (Sequence $sequence): array => [
            'created_at' => now()->subMinutes($sequence->index),
            'updated_at' => now()->subMinutes($sequence->index),
        ])->afterMaking(/* ... */);
    }
}
```
- `final class`, `protected $model`, explicit `@return array<string, mixed>` PHPDoc on `definition()`.
- `Sequence`-based `configure()` used to stagger timestamps deterministically across factory-created records where ordering matters.
- Convenience states like `User::factory()->withTeam()` / `->withPersonalTeam()` used throughout feature tests to set up tenant context in one call.

**Static file fixtures:** `tests/fixtures/audio/`, `tests/fixtures/imports/` — binary/sample-data files referenced by tests needing real file uploads (audio transcription, CSV import flows).

## Coverage

**Requirements:** No explicit percentage coverage gate found for test coverage; the enforced numeric gate is **type coverage** (100%, `composer test:type-coverage`), not line/branch coverage.

**Mutation testing:** used per-class as a manual code-review tool, not a CI gate:
```bash
php -d xdebug.mode=coverage vendor/bin/pest --mutate --class='App\MyClass' tests/path/
```
`mutates(ClassName::class)` declarations at the top of test files are what this targets.

## Test Types

**Architecture tests (`tests/Arch/`):**
- `ArchTest.php` — Pest arch presets (`arch()->preset()->php()`, `->security()`, `->laravel()`) plus custom rules: strict types required across `App`/`Relaticle`, most `App` classes must be `final` and non-abstract (with documented ignore lists for intentional base classes), most `App` classes must be `readonly`.
- `ConventionsTest.php` — enforces the no-em-dash rule across `app/`, `packages/`, `resources/`, `lang/`, `config/`, `database/`, `routes/`, `bootstrap/`; also enforces `CLAUDE.md`/`AGENTS.md`/`GEMINI.md` staying in sync with `.ai/guidelines/relaticle/` sources.
- `TestSuiteIntegrityTest.php` — enforces every `*Test.php` lives inside a declared testsuite directory, and that `phpunit.xml`/`phpunit.ci.xml` testsuites stay in sync.

**PHPStan rule tests (`tests/PHPStan/`):** unit-style tests for the custom static-analysis rules in `app/PHPStan/Rules/` (e.g. `HardcodedUserFacingStringRule`, `HardcodedStaticPropertyRule`).

**Smoke tests (`tests/Smoke/`):** HTTP-level route smoke checks, thin assertions that key routes resolve/respond.

**Feature/Workflow tests (`tests/Feature/`):** the bulk of the suite. Exercise real entry points — Filament pages (`livewire(Billing::class)->assertSee(...)`), MCP/chat tools, API endpoints — end to end through actions and models, not isolated unit tests.

**Browser tests (`tests/Browser/`):** critical paths only, via Pest's Playwright-backed browser testing (`Pest\Browser\Playwright\Playwright`, timeout set to 30s in `tests/Pest.php`). Login helper `loginViaBrowser(User $user)` walks the real two-step login form (email submit reveals password field, then password submit). Chat browser tests seed real conversation data via `ChatBrowser::seedConversation()` and drive the actual DOM (`[data-chat-context="conversation"] [contenteditable="true"]`).

## Common Patterns

**Livewire testing helper** (`tests/Pest.php`, replaces the `pest-plugin-livewire` package):
```php
function livewire(string $component, array $params = []): Testable
{
    return Livewire::test($component, $params);
}
```

**Regression test documentation pattern:** a multi-line comment directly above the `it()` block explaining the original bug, its root cause, and why the specific assertion catches it (see `tests/Browser/Chat/*Test.php` for extensive examples referencing GitHub issue numbers and prior commit SHAs).

**Time-dependent tests:** use `$this->travelTo()` for tests depending on day-of-week or weekly intervals, to avoid flaky boundary failures.

**Filament panel test conventions:**
- Always `test()->actingAs($user)` (or `$this->actingAs(...)`) before testing panel functionality.
- Edit pages: pass `['record' => $model->id]`, call `->call('save')` (not `->call('create')`), and do not assert `->assertRedirect()` since edit pages don't redirect after save.
- Create pages: `->fillForm([...])->call('create')->assertNotified()->assertHasNoFormErrors()->assertRedirect()`.
- Table actions: `livewire(ListX::class)->callAction(TestAction::make('name')->table($record), [...])->assertNotified()`.

**Multi-tenancy setup in tests:** `Filament::setTenant($team)` paired with `test()->actingAs($user)` to simulate a scoped panel session per team/role (owner vs member vs admin).

---

*Testing analysis: 2026-09-02*

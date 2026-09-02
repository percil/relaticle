# Coding Conventions

**Analysis Date:** 2026-09-02

## Naming Patterns

**Files:**
- Action classes: `app/Actions/<Domain>/<VerbNoun>.php` (e.g. `app/Actions/Opportunity/CreateOpportunity.php`, `app/Actions/Opportunity/DeleteOpportunity.php`, `app/Actions/Billing/StartProTrial.php`). One class per file, filename matches class name.
- Enums: `app/Enums/<Name>.php` or grouped subfolders like `app/Enums/CustomFields/<Entity>Field.php`, `app/Enums/Notifications/NotificationType.php`.
- Data objects (spatie/laravel-data pattern): `app/Data/<Name>.php`, e.g. `app/Data/NotificationPreferences.php`.
- Chat tools live in `packages/Chat/src/Tools/<Domain>/<Verb><Entity>Tool.php` (`Create*Tool`, `Update*Tool`, `List*Tool`, `Get*Tool`).
- Package namespaces mirror Laravel app structure: `packages/<Name>/src/`, `config/`, `routes/`, `resources/`, `database/`, namespace `Relaticle\<Name>`.

**Functions/Methods:**
- Action classes expose a single public `execute()` method; everything else is `private`.
- Descriptive, non-abbreviated names (`isRegisteredForDiscounts`, not `discount()`), per Laravel Boost guidance.

**Variables:**
- camelCase throughout PHP code.

**Classes:**
- Domain concepts are named plainly (`Plan`, not `AiPlan`). Namespace supplies context. Never store the same fact in two places; pick one source of truth.

**Enums:**
- `PascalCase` enum name, `PascalCase` case names with `snake_case`/`kebab-case` or plain-word backing values, e.g. `enum TeamRole: string { case Admin = 'admin'; case Editor = 'editor'; }` (`app/Enums/TeamRole.php`).
- Static helper methods (`label()`, `description()`) live on the enum itself.
- When adding/removing enum cases, manually sweep `packages/SystemAdmin` for `match` expressions over that enum — it's excluded from PHPStan and won't be caught statically.

## Code Style

**Formatting:**
- Enforced by `vendor/bin/pint` (Laravel preset), config in `pint.json`.
- Key custom rules: `declare_strict_types: true`, `final_class: true`, `final_internal_class: true`, `strict_comparison: true`.
- `notPath`: `app/Models/PersonalAccessToken.php` excluded (must stay non-final for Passport override).
- Run `vendor/bin/pint --dirty --format agent` before committing (only checks files with uncommitted changes). Run `composer test:lint` (full-repo `pint --test --parallel`) before pushing, since `--dirty` misses files already committed earlier in the branch.

**Static analysis:**
- `vendor/bin/phpstan analyse` at level 7 (`phpstan.neon`), using `larastan/larastan`.
- Two custom rules enforce i18n: `app/PHPStan/Rules/HardcodedUserFacingStringRule.php` (guards methods like `label()`, `heading()`, `title()`) and `HardcodedStaticPropertyRule.php` (guards static properties like `$navigationLabel`). All user-facing strings must be wrapped in `__()`.
- `packages/SystemAdmin` is entirely excluded from PHPStan analysis.
- Pre-existing violations of `EloquentWriteOutsideActionRule` and i18n rules are grandfathered per-file/per-path via `ignoreErrors` in `phpstan.neon`. Never add new ignores without approval; when refactoring a grandfathered file, remove its ignore entry.
- Type coverage must stay at 100% (`composer test:type-coverage`). All parameters and return types must be explicitly typed; no untyped closures.

**Rector:**
- `vendor/bin/rector --dry-run` first; apply suggested changes with `vendor/bin/rector` if any appear.
- Config in `rector.php`: targets `app/`, `packages/`, `bootstrap/app.php`, `config/`, `database/`, `public/`. Uses Laravel rule sets (`LaravelSetList::LARAVEL_CODE_QUALITY`, `LARAVEL_COLLECTION`, `LARAVEL_TYPE_DECLARATIONS`, etc.) plus prepared sets `deadCode`, `codeQuality`, `typeDeclarations`, `privatization`, `earlyReturn`.
- Several rules are deliberately skipped codebase-wide (documented inline in `rector.php`), e.g. `AddOverrideAttributeToOverriddenMethodsRector`/`...PropertiesRector` (would tag every Filament `$navigationIcon`/`$slug` override), property-to-attribute migrations (`FillablePropertyToFillableAttributeRector`, etc. — deferred to dedicated PRs), and `EloquentWhereTypeHintClosureParameterRector`.
- Path-scoped skips: `RemoveUnusedPrivateMethodRector` and `PrivatizeFinalClassMethodRector` skip `app/Filament/Imports/*` (Filament importer lifecycle hooks called dynamically via `callHook()`).

**Pre-commit order (from CLAUDE.md):**
1. `vendor/bin/pint --dirty --format agent`
2. `vendor/bin/rector --dry-run` (apply if suggested)
3. `vendor/bin/phpstan analyse`
4. `composer test:type-coverage`
5. `php artisan test --compact` (targeted, use `--filter`)

## Import Organization

- Standard PHP `use` statements, alphabetized within groups by tooling (Pint's import ordering). No manual grouping convention beyond that observed.
- No path aliases beyond standard PSR-4 namespaces (`App\`, `Relaticle\<Package>\`).

## Error Handling

**Authorization/validation in actions:**
- `abort_unless($user->can('create', Model::class), 403);` at the top of `execute()` — see `app/Actions/Opportunity/CreateOpportunity.php`, `app/Actions/Opportunity/DeleteOpportunity.php`, `app/Actions/Opportunity/AggregateOpportunities.php`.
- Tenant-ownership of foreign keys validated via `App\Support\TenantFkValidator::assertOwned($user, $data, [...])`, mapping each FK column to the model class it must belong to the current tenant.
- Domain validation errors raised inline with `abort(422, "message")` inside a `match` default arm (see `AggregateOpportunities::execute()`).
- Chat write tools: new foreign keys must be listed in `ownedForeignKeys()`/`ownedForeignKeyLists()` or they bypass both reference validation and ownership checks (packages/Chat).

**Custom fields tenant context:**
- Any write path outside a Filament panel request or `SetApiTeamContext` middleware (chat approval, queued jobs, webhooks, commands) must call `TenantContextService::setTenantId()` before saving custom fields, wrapped in try/finally restoring the previous tenant id.

**Never inline business logic** in controllers, MCP tools, Livewire components, or Filament resources — always through action classes in `app/Actions/<Domain>/`. Enforced by the PHPStan `EloquentWriteOutsideActionRule` for direct Eloquent writes outside actions.

## Action Class Pattern (canonical shape)

```php
final readonly class CreateOpportunity
{
    public function execute(User $user, array $data, CreationSource $source = CreationSource::WEB): Opportunity
    {
        abort_unless($user->can('create', Opportunity::class), 403);

        TenantFkValidator::assertOwned($user, $data, [
            'company_id' => Company::class,
            'contact_id' => People::class,
        ]);

        $attributes = Arr::only($data, ['name', 'company_id', 'contact_id', 'custom_fields']);
        $attributes['creation_source'] = $source;

        $opportunity = DB::transaction(fn (): Opportunity => Opportunity::query()->create($attributes));

        return $opportunity->load('customFieldValues.customField.options');
    }
}
```

- `final readonly class`, constructor property promotion where the action has dependencies.
- Single `execute()` method; supporting logic in `private` helper methods.
- Explicit param/return types everywhere; array shapes documented via PHPDoc `@param array{...}`/`@return array{...}` (see `app/Actions/Opportunity/AggregateOpportunities.php` for a rich example).
- Writes wrapped in `DB::transaction()` when they touch multiple rows or need atomicity.
- Filament CRUD may use native `CreateAction`/`EditAction` for a plain `Model::create()`/`->update()` with no extra logic; side effects (notifications, syncs) must be triggered via `->after()` hooks calling the action, not embedded in the Filament class.

## Comments

**When to comment:**
- Reserve inline comments for exceptionally complex or non-obvious logic (e.g. why a `LEFT JOIN` conditionally exists, why a `match` prevents ambiguous grouping labels in `AggregateOpportunities`).
- PHPDoc blocks preferred over inline comments for describing method contracts, especially array shapes.
- Comments explaining *why*, not *what* — e.g. `rector.php`'s inline justifications for each skipped rule, `phpstan.neon`'s per-ignore rationale comments.

**No em-dashes:**
- The em-dash (U+2014) is banned everywhere: code, docs, comments, commits, PRs. Enforced by `tests/Arch/ConventionsTest.php` across `app/`, `packages/`, `resources/`, `lang/`, `config/`, `database/`, `routes/`, `bootstrap/`. One allowlisted exception: the standalone `'—'` string literal used as an empty-value data glyph in activity-log/custom-field diffs — never as prose punctuation.

## Function/Module Design

**Size:** Action classes stay narrowly scoped to one write operation; complex read/aggregate operations (e.g. `AggregateOpportunities`) decompose into small `private` helpers per concern (date clause, bindings, grand totals, result shaping).

**Parameters:** Typed scalars/enums/models; structured payloads passed as `array<string, mixed> $data` with PHPDoc shape annotations, or `App\Data` (spatie/laravel-data) readonly objects where that pattern already exists.

**Return values:** Explicit return types always declared; complex return shapes documented with PHPDoc array shapes rather than introducing new DTOs ad hoc.

**Exports:** No barrel/index files observed; classes are referenced by their full namespace path directly.

## Module Boundaries (architecture-adjacent, enforced by tests/Arch/ArchTest.php)

- `App` must not depend on `Relaticle\SystemAdmin`.
- `Relaticle\SystemAdmin` may only reach back into `App\Models`, `App\Enums`, `App\Rules`.
- Never use custom-fields package models directly — use the `App\Models\CustomField*` subclasses (runtime model swapping configured in `AppServiceProvider`).
- Most `App` classes must be `final` (arch rule "avoid open for extension"), with an explicit ignore list for base classes designed for extension (`BaseLivewireComponent`, `BaseImporter`, `BaseExporter`, `BaseListTool`, `BaseShowTool`, `BaseCreateTool`, `BaseUpdateTool`, `BaseDeleteTool`, `BaseAttachTool`, `BaseDetachTool`, `BaseRelationshipTool`, `ImportPage`, `PersonalAccessToken`).
- Most `App` classes must be `readonly` (arch rule "avoid mutation"), with directory-level exceptions (`App\Console\Commands`, `App\Exceptions`, `App\Filament`, `App\Health`, and others — check `tests/Arch/ArchTest.php` for the current ignore list before assuming a new class should be readonly).
- Strict types (`declare(strict_types=1)`) required across `App` and `Relaticle` namespaces (arch rule).

## Database Conventions

- PostgreSQL exclusively. No SQLite/MySQL compatibility layers, driver checks, or conditional SQL.
- Migrations must only have `up()` methods; never write `down()`.
- Every datetime column is `timestamp without time zone` holding UTC. Never write from the database clock (no `DB::raw('now()')`, `CURRENT_TIMESTAMP`, `->useCurrent()`/`->useCurrentOnUpdate()` defaults — these resolve against session timezone). Pass PHP-side `now()` instead: `->update(['used_at' => now()])`.

---

*Convention analysis: 2026-09-02*

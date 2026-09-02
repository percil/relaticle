# Codebase Structure

**Analysis Date:** 2026-09-02

## Directory Layout

```
relaticle/
├── app/                        # CRM domain core (models, actions, Filament app panel, API, MCP server)
│   ├── Actions/<Domain>/       # Write path: one final readonly class per operation
│   ├── Casts/                  # Eloquent custom casts
│   ├── Concerns/               # Shared traits
│   ├── Console/Commands/       # Artisan commands
│   ├── Contracts/User/         # User-related interfaces
│   ├── Data/                   # spatie/laravel-data DTOs
│   ├── Enums/                  # Domain enums (+ CustomFields/, Notifications/ subdirs)
│   ├── Exceptions/             # App-specific exceptions
│   ├── Features/                # Laravel Pennant feature flag classes
│   ├── Filament/               # App panel: Resources/, Pages/, Actions/, Clusters/, Components/,
│   │                            #   Concerns/, CustomFields/, Exports/, Notifications/
│   ├── Health/                  # spatie/laravel-health checks
│   ├── Http/                   # Controllers/, Middleware/, Requests/, Resources/, Responses/
│   ├── Jobs/Email/              # Queued jobs
│   ├── Listeners/               # Billing/, Email/, Mcp/ event listeners
│   ├── Livewire/App/            # Livewire components (app panel)
│   ├── Mail/                    # Mailables
│   ├── Mcp/                     # MCP server: Servers/, Tools/, Resources/, Prompts/, Schema/, Filters/
│   ├── Models/                  # Eloquent models (+ ActivityLog/, Concerns/, Passport/, Scopes/)
│   ├── Notifications/           # Laravel notifications
│   ├── Observers/               # Model observers
│   ├── Onboarding/              # Onboarding flow logic
│   ├── PHPStan/Rules/           # Custom static-analysis rules (EloquentWriteOutsideActionRule, i18n rules)
│   ├── Policies/                # Authorization policies
│   ├── Providers/               # Service providers (+ Filament/ for panel providers)
│   ├── Rules/                   # Validation rules
│   ├── Scribe/Strategies/       # API doc generation strategies
│   ├── Services/                # Billing/, Favicon/, Notifications/ cross-cutting services
│   ├── Support/                 # ActivityLog/, Auth/, Email/, Markdown/ helpers (incl. TenantFkValidator)
│   └── View/Components/         # Blade components
├── packages/                   # Self-contained subsystems, Relaticle\<Name> namespace
│   ├── Chat/                   # AI assistant: agents, chat tools, credit system, streaming
│   │   ├── src/Actions/ Agents/ Commands/ Data/ Enums/ Events/ Http/ Jobs/
│   │   │       Livewire/ Models/ Services/ Settings/ Storage/ Support/ Tools/
│   │   ├── config/ resources/ routes/
│   ├── SystemAdmin/            # Internal admin panel (separate Filament panel)
│   │   ├── src/Actions/ Enums/ Exceptions/ Filament/ Http/ Models/ Policies/
│   │   ├── resources/
│   ├── ImportWizard/           # CSV import flows
│   │   ├── src/Commands/ Data/ Enums/ Exceptions/ Filament/ Http/ Importers/
│   │   │       Jobs/ Livewire/ Models/ Rules/ Store/ Support/
│   │   ├── config/ resources/ routes/
│   ├── Documentation/          # Public docs pages
│   │   ├── src/Http/ Support/
│   │   ├── config/ resources/content/ routes/
│   └── OnboardSeed/             # Demo/onboarding data seeding
│       ├── src/Contracts/ ModelSeeders/ Support/
│       ├── resources/fixtures/ templates/
├── bootstrap/                  # app.php (entry config), providers.php (service provider list)
├── config/                     # Laravel + package config files
├── database/                   # migrations/, seeders/ (+ Personas/), factories/ (+ Relaticle/), settings/
├── lang/                       # Translation files (i18n)
├── public/                     # Web server document root
├── resources/                  # css/, js/, views/ (+ home/, mail/, blog/, filament/, etc.), svg/, markdown/, data/, fonts/
├── routes/                     # web.php, api.php, ai.php (MCP), channels.php
├── storage/                    # Runtime storage, logs, framework cache
├── tests/                      # Arch/, PHPStan/, Smoke/, Feature/, Browser/ (Testing Trophy suites)
├── .ai/rules/                  # Committed, area-grouped project rules (index.md maps globs to rule files)
├── .ai/guidelines/relaticle/   # Source for compiled CLAUDE.md / AGENTS.md / GEMINI.md
├── artisan                     # Artisan CLI entry point
├── composer.json               # PHP deps, autoload (app/, packages/*/src)
└── package.json                # JS deps (Vite, Tailwind, etc.)
```

## Directory Purposes

**`app/Actions/<Domain>/`:**
- Purpose: All create/update/delete business logic, one action per operation.
- Contains: `final readonly class` files, one `execute()` method each.
- Key subdirs: `Billing/`, `Chat/`, `Company/`, `Crm/`, `CustomFields/`,
  `Fortify/`, `Jetstream/`, `Mcp/`, `Note/`, `Onboarding/`, `Opportunity/`,
  `Passkeys/`, `People/`, `Profile/`, `Task/`, `Team/`, `User/`.

**`app/Filament/Resources/`:**
- Purpose: CRUD UI definitions for CRM entities in the app panel.
- Contains: One `<Entity>Resource/` directory per entity: `CompanyResource`,
  `NoteResource`, `OpportunityResource`, `PeopleResource`, `TaskResource`.

**`app/Mcp/`:**
- Purpose: MCP server exposing CRM as tools for external AI agents.
- Contains: `Servers/RelaticleServer.php` (the single registered server),
  `Tools/` (per-entity tool classes plus `Base*Tool.php` shared bases),
  `Resources/`, `Prompts/`, `Schema/`, `Filters/`.

**`app/Models/`:**
- Purpose: Eloquent entities for the CRM domain.
- Key files: `Company.php`, `People.php`, `Opportunity.php`, `Task.php`,
  `Note.php` (all use the `UsesCustomFields` trait), `Team.php`, `User.php`,
  `Membership.php`, `TeamInvitation.php`, `CustomField*.php` (app-specific
  subclasses of the custom-fields package models).

**`packages/Chat/`:**
- Purpose: In-app AI chat assistant.
- Contains: `src/Agents/` (LLM agent definitions: `CrmAssistant.php`,
  `ConversationTitler.php`, `NextStepSuggester.php`, `ModelProbeAgent.php`),
  `src/Tools/<Entity>/` (chat-callable Create/Update/Delete/List/Get tools,
  one directory per entity plus `Concerns/`), `src/Services/` (plan/proposal
  orchestration: `ProposalPlanService.php`, `PlanReferenceResolver.php`,
  `PendingActionService.php`, `CreditService.php`), `src/Models/`
  (`AgentConversation`, `AgentConversationMessage`, `PendingAction`,
  `AiCreditBalance`, `AiCreditTransaction`, `ChatMessageFeedback`),
  `src/Livewire/` (chat UI components).

**`packages/SystemAdmin/`:**
- Purpose: Separate internal Filament panel for ops/admin tasks.
- Contains: `src/Filament/`, `src/Actions/`, `src/Models/`, `src/Policies/`.
- Boundary: may only import `App\Models`, `App\Enums`, `App\Rules` from the
  main app namespace (enforced by `tests/Arch/ArchTest.php`); `App` must never
  import from `Relaticle\SystemAdmin`.

**`packages/ImportWizard/`:**
- Purpose: CSV import wizard flows for CRM entities.
- Contains: `src/Importers/`, `src/Filament/`, `src/Livewire/`, `src/Jobs/`,
  `src/Store/` (import session state), `src/Data/`, `src/Rules/`.

**`packages/Documentation/`:**
- Purpose: Public-facing docs pages.
- Contains: `src/Http/`, `src/Support/DocsRepository.php`,
  `resources/content/` (markdown/docs source content).

**`packages/OnboardSeed/`:**
- Purpose: Seeds demo/onboarding data for new tenants.
- Contains: `src/ModelSeeders/`, `resources/fixtures/`, `resources/templates/`.

**`tests/`:**
- Purpose: Testing Trophy suites (see `.ai/rules` and phpunit.xml suites).
- Directories: `Arch/` (structural/module-boundary rules), `PHPStan/` (tests
  for custom static-analysis rules), `Smoke/` (HTTP route smoke tests),
  `Feature/` (bulk of the suite, real entry points), `Browser/` (critical
  paths only, agent-browser driven). No `tests/Unit/` — internals are not
  unit-tested in isolation.

**`.ai/rules/`:**
- Purpose: Committed, area-grouped project rules not fully captured in
  CLAUDE.md. `.ai/rules/index.md` maps file globs to rule files (e.g.
  `app/Http/**` → `.ai/rules/boost/http-routes.md`).

## Key File Locations

**Entry Points:**
- `bootstrap/app.php`: Application bootstrap, routing registration, middleware
  stack, scheduled commands (`withSchedule()`).
- `bootstrap/providers.php`: Registered service providers, including package
  providers (`ChatServiceProvider`, `DocumentationServiceProvider`,
  `ImportWizardNewServiceProvider`, `SystemAdminPanelProvider`).
- `routes/web.php`: Public/marketing/auth web routes.
- `routes/api.php`: REST API routes (domain- or prefix-routed based on
  `app.api_domain` config).
- `routes/ai.php`: MCP server routes.
- `app/Providers/Filament/AppPanelProvider.php`: Main CRM Filament panel.
- `packages/SystemAdmin/src/SystemAdminPanelProvider.php`: Admin Filament panel.
- `app/Mcp/Servers/RelaticleServer.php`: MCP server registration.

**Configuration:**
- `config/`: Laravel + third-party package config (Filament, Cashier,
  Horizon, Pennant, custom-fields, etc.).
- `packages/<Name>/config/`: Package-specific config, published/merged by the
  package's service provider.
- `.env.example`, `.env.ci`, `.env.testing`: Environment templates (never read
  actual `.env` contents in analysis).

**Core Logic:**
- `app/Actions/<Domain>/`: All business logic writes.
- `app/Models/`: Domain entities.
- `app/Support/TenantFkValidator.php`: Tenant-ownership check used by actions.

**Testing:**
- `tests/Arch/ArchTest.php`: Module boundary and architectural conventions.
- `tests/Feature/`: Bulk of behavioral coverage.
- `phpunit.xml`, `phpunit.ci.xml`: Testsuite declarations (kept in sync).

## Naming Conventions

**Files:**
- Action classes: verb-first PascalCase matching the operation, e.g.
  `CreateOpportunity.php`, `DeleteOpportunity.php`,
  `AggregateOpportunities.php`.
- MCP tool classes: `<Verb><Entity>Tool.php` inside `app/Mcp/Tools/<Entity>/`,
  extending a shared `Base<Verb>Tool.php`.
- Chat tool classes: same `<Verb><Entity>Tool.php` pattern inside
  `packages/Chat/src/Tools/<Entity>/`.
- Filament resources: `<Entity>Resource/` directory containing
  `<Entity>Resource.php` plus `Pages/`, `Schemas/`, etc.
- Enums: PascalCase, domain-specific subdirectories for grouped concerns
  (`app/Enums/CustomFields/`, `app/Enums/Notifications/`).

**Directories:**
- Domain-grouped, not type-grouped, within `Actions/`: one directory per
  domain/entity (`Opportunity/`, `Company/`, `Team/`), each holding all verbs
  for that domain.
- Packages: PascalCase under `packages/`, matching the `Relaticle\<Name>`
  namespace exactly.

## Where to Add New Code

**New CRM entity/feature (not a separable subsystem):**
- Model: `app/Models/<Entity>.php`
- Actions: `app/Actions/<Entity>/` (Create/Update/Delete/etc.)
- Filament UI: `app/Filament/Resources/<Entity>Resource/`
- API: `app/Http/Controllers/`, `app/Http/Resources/`, route in `routes/api.php`
- MCP tools: `app/Mcp/Tools/<Entity>/`, extending the shared `Base*Tool` classes
- Chat tools: `packages/Chat/src/Tools/<Entity>/`, mirroring sibling entities'
  Create/Update/Delete/List/Get tool set
- Tests: `tests/Feature/` through the real entry point (Filament Livewire test,
  API test, or chat tool test), not isolated unit tests

**New separable subsystem (own panel, routes, or lifecycle):**
- New directory: `packages/<Name>/` with `src/`, `config/`, `routes/`,
  `resources/`, `database/` mirroring existing packages
- Namespace: `Relaticle\<Name>`
- Register its service provider in `bootstrap/providers.php`

**Shared utility/helper:**
- Cross-cutting logic used by multiple actions: `app/Services/` or `app/Support/`
- Shared traits: `app/Concerns/` or `app/Models/Concerns/`

**New custom field:**
- Add case to `app/Enums/CustomFields/<Entity>Field.php`, or via the Custom
  Fields admin UI. Chat/API/MCP support is automatic; no per-field tool code.

## Special Directories

**`storage/`:**
- Purpose: Runtime logs, cache, framework files.
- Generated: Yes
- Committed: No

**`public/`:**
- Purpose: Web server document root, compiled/built assets.
- Generated: Partially (built JS/CSS assets via Vite)
- Committed: Mixed (source-controlled entry files, build output typically not)

**`.ai/guidelines/relaticle/`:**
- Purpose: Source of truth for `CLAUDE.md`/`AGENTS.md`/`GEMINI.md`, compiled
  via `php artisan boost:update`.
- Generated: Compiled files (`CLAUDE.md`, `AGENTS.md`, `GEMINI.md`) are
  generated from this source; edit the source, not the compiled files.
- Committed: Yes (both source and compiled outputs).

**`database/seeders/LocalSeeder.php`:**
- Purpose: Environment-specific developer seed data.
- Generated: No
- Committed: Yes (must never be gated behind `app()->environment()` checks in
  production action code).

---

*Structure analysis: 2026-09-02*

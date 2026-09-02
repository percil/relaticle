<!-- refreshed: 2026-09-02 -->
# Architecture

**Analysis Date:** 2026-09-02

## System Overview

```text
┌─────────────────────────────────────────────────────────────────────────┐
│                          Entry Points                                    │
├───────────────┬───────────────┬───────────────┬─────────────────────────┤
│  App Panel    │  SystemAdmin  │  REST API      │  MCP Server / Chat AI   │
│ (Filament)    │  Panel        │ `routes/api.php`│ `app/Mcp/Servers/`      │
│`app/Providers │`packages/     │ `app/Http/      │ `packages/Chat/`        │
│ /Filament`    │ SystemAdmin`  │ Controllers`    │                         │
└───────┬───────┴───────┬───────┴────────┬────────┴────────────┬──────────┘
        │               │                │                     │
        ▼               ▼                ▼                     ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                     Write Path: Action Classes                          │
│     `app/Actions/<Domain>/`  (final readonly, single execute())         │
│     `packages/Chat/src/Actions/`  `packages/SystemAdmin/src/Actions/`   │
└───────────────────────────────┬─────────────────────────────────────────┘
                                 │
                                 ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                      Eloquent Models + Custom Fields                     │
│  `app/Models/`  (UsesCustomFields trait: Company, People, Opportunity,   │
│                  Task, Note)                                             │
└───────────────────────────────┬─────────────────────────────────────────┘
                                 │
                                 ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                  PostgreSQL (tenant-scoped by team_id)                   │
└─────────────────────────────────────────────────────────────────────────┘
```

## Component Responsibilities

| Component | Responsibility | File |
|-----------|----------------|------|
| App Filament panel | Primary CRM UI (companies, people, opportunities, tasks, notes) | `app/Providers/Filament/AppPanelProvider.php` |
| SystemAdmin Filament panel | Internal ops/admin panel, separate auth guard | `packages/SystemAdmin/src/SystemAdminPanelProvider.php` |
| REST API | Token-authenticated CRUD API for CRM entities | `app/Http/Controllers/`, `routes/api.php` |
| MCP server | Exposes CRM as tools/resources for external AI agents | `app/Mcp/Servers/RelaticleServer.php`, `app/Mcp/Tools/` |
| Chat AI assistant | In-app conversational agent with proposal/approval workflow | `packages/Chat/src/Agents/`, `packages/Chat/src/Tools/` |
| Actions | Single source of truth for all writes, authorization, tenant checks | `app/Actions/<Domain>/`, `packages/*/src/Actions/` |
| Models | Eloquent entities, tenant scoping, custom-fields integration | `app/Models/` |
| Custom Fields | Dynamic per-tenant schema attached to CRM entities | `app/Models/CustomField*.php`, `app/Enums/CustomFields/` |
| ImportWizard | CSV import flows for CRM entities | `packages/ImportWizard/src/` |
| OnboardSeed | Demo/onboarding data seeding | `packages/OnboardSeed/src/` |
| Documentation | Public docs pages | `packages/Documentation/src/` |

## Pattern Overview

**Overall:** Modular monolith. A single Laravel application (`app/`) hosts the CRM
core; self-contained subsystems that need their own routes, panel, or lifecycle
live under `packages/<Name>/` as `Relaticle\<Name>`-namespaced code, autoloaded
directly from `packages/<Name>/src` (no per-package `composer.json`). Packages
mirror Laravel app anatomy: `src/`, `config/`, `routes/`, `resources/`,
`database/`.

**Key Characteristics:**
- Multi-tenant SaaS: every CRM row is scoped to a `team_id`/tenant.
- All writes funnel through action classes; no direct Eloquent writes in
  controllers, Livewire components, Filament resources, or chat/MCP tools
  (enforced by the `EloquentWriteOutsideActionRule` PHPStan rule).
- Four parallel "faces" onto the same domain: Filament UI, REST API, MCP tools,
  Chat AI tools. Each face is a thin adapter that calls the same action classes.
  Chat tools additionally auto-support all active custom fields per entity via
  bridge services, so no field-specific tool code is needed.
- Custom fields are a first-class, per-tenant schema layer over fixed CRM
  models, not implemented as EAV directly on the models but via a separate
  package's tables (`custom_fields`, `custom_field_values`,
  `custom_field_options`), wrapped by the app's own `CustomField*` subclasses.

## Layers

**Presentation (Filament / Livewire / Blade):**
- Purpose: Renders CRM UI, forms, tables.
- Location: `app/Filament/`, `app/Livewire/App/`, `resources/views/`
- Contains: Filament Resources/Pages/Actions, Livewire components, Blade views.
- Depends on: Action classes, Models, Policies.
- Used by: End users via `AppPanelProvider`.

**API / MCP / Chat adapters:**
- Purpose: Expose CRM operations to external clients (REST, MCP-speaking AI
  agents, in-app chat assistant).
- Location: `app/Http/Controllers/`, `app/Http/Resources/`, `app/Mcp/Tools/`,
  `packages/Chat/src/Tools/`
- Contains: Controllers, API Resources, MCP Tool classes, Chat Tool classes.
- Depends on: Action classes (never touch Eloquent directly).
- Used by: External API consumers, MCP clients, in-app chat UI.

**Actions (write path):**
- Purpose: Single source of truth for business logic, authorization,
  tenant-ownership checks, and side effects (notifications, syncs).
- Location: `app/Actions/<Domain>/` (e.g. `Company`, `Crm`, `Opportunity`,
  `People`, `Task`, `Note`, `Team`, `User`, `Billing`, `CustomFields`,
  `Onboarding`, `Passkeys`, `Mcp`, `Chat`, `Fortify`, `Jetstream`, `Profile`),
  `packages/Chat/src/Actions/`, `packages/SystemAdmin/src/Actions/`
- Contains: `final readonly class` with a single `execute()` method.
- Depends on: Models, `App\Support\TenantFkValidator`, Policies (via
  `abort_unless($user->can(...))`).
- Used by: Every write-capable entry point (Filament hooks, controllers, MCP
  tools, chat tools).

**Models:**
- Purpose: Eloquent entities, relationships, tenant scoping, custom-fields
  integration.
- Location: `app/Models/` (root CRM models), `app/Models/Concerns/`,
  `app/Models/Scopes/`, `app/Models/ActivityLog/`, `app/Models/Passport/`
- Contains: Model classes, traits (`UsesCustomFields`), global scopes.
- Depends on: Database, custom-fields package tables.
- Used by: Actions, read paths (list/show tools, table columns).

**Services / Support:**
- Purpose: Cross-cutting logic reused by actions and adapters (billing,
  notifications, favicon, markdown rendering, email, activity log formatting).
- Location: `app/Services/`, `app/Support/`
- Depends on: Models, external SDKs (Stripe via Cashier, etc.).
- Used by: Actions, Observers, Listeners.

## Data Flow

### Primary Request Path (Filament UI write)

1. User submits a Filament form/action in `app/Filament/Resources/<Entity>Resource/`.
2. Native `CreateAction`/`EditAction` performs the plain `Model::create()`/`update()`,
   or an `->after()` hook calls the domain action for side effects.
3. Action class in `app/Actions/<Domain>/` authorizes via policy, validates
   tenant-owned foreign keys (`App\Support\TenantFkValidator`), writes via
   Eloquent inside `DB::transaction()`.
4. Model save triggers `UsesCustomFields` trait hooks (`saving`/`saved`) to
   persist `custom_fields` payload.

### Chat AI Proposal Flow

1. User message reaches `packages/Chat/src/Agents/CrmAssistant.php` via a
   Livewire chat component (`packages/Chat/src/Livewire/Chat/`).
2. Agent calls Chat Tools (`packages/Chat/src/Tools/<Entity>/Create*Tool.php`
   etc.), which build a `PendingAction` rather than writing directly.
3. Multiple tool calls in one turn share a `turn_id`; `ProposalPlanService`
   groups them into a single plan/card. Cross-record references use
   `$ref:<pending_action_id>`, resolved only at approval time by
   `PlanReferenceResolver`.
4. On user approval, the pending action is executed through the same
   `app/Actions/<Domain>/` classes used by the Filament UI, so authorization
   and tenant checks are identical across faces.

### MCP Tool Flow

1. External MCP client calls a tool registered on `app/Mcp/Servers/RelaticleServer.php`.
2. Tool classes (`app/Mcp/Tools/<Entity>/*.php`) extend shared bases
   (`BaseCreateTool`, `BaseUpdateTool`, `BaseDeleteTool`, `BaseListTool`,
   `BaseShowTool`, `BaseAttachTool`, `BaseDetachTool`, `BaseRelationshipTool`
   in `app/Mcp/Tools/`).
3. Writes delegate to the same `app/Actions/<Domain>/` classes; reads query
   Models directly, formatted via `App\Http\Resources`.

**State Management:**
- Server-rendered/session-based for Filament and Blade (standard Laravel
  session auth via Fortify/Jetstream).
- Chat conversation state persisted in `packages/Chat/src/Models/`
  (`AgentConversation`, `AgentConversationMessage`, `PendingAction`).
- Tenant context propagated per-request via `SetApiTeamContext` middleware
  (API/queued paths) or Filament's tenant resolution (panel requests); custom
  fields code reads it through `TenantContextService::setTenantId()`.

## Key Abstractions

**Action:**
- Purpose: Encapsulate one write operation with authorization and
  tenant-ownership enforcement.
- Examples: `app/Actions/Opportunity/CreateOpportunity.php`,
  `app/Actions/Opportunity/DeleteOpportunity.php`
- Pattern: `final readonly class` + single `execute()` method; `abort_unless`
  policy check first, then `TenantFkValidator::assertOwned()` for any foreign
  keys in the payload, then the actual write (often wrapped in
  `DB::transaction()`).

**Base MCP Tool classes:**
- Purpose: Shared CRUD scaffolding for MCP tools across CRM entities.
- Examples: `app/Mcp/Tools/BaseCreateTool.php`, `BaseListTool.php`,
  `BaseShowTool.php`, `BaseUpdateTool.php`, `BaseDeleteTool.php`,
  `BaseAttachTool.php`, `BaseDetachTool.php`, `BaseRelationshipTool.php`
- Pattern: Template method — entity-specific tool classes (e.g.
  `app/Mcp/Tools/Opportunity/`) extend a base and fill in entity-specific
  schema/query logic.

**Chat Tool + custom fields bridge:**
- Purpose: Let every chat Create/Update tool automatically support all active
  custom fields for its entity without per-field code.
- Examples: `packages/Chat/src/Tools/Opportunity/`, `packages/Chat/src/Services/Tools/`
- Pattern: A schema describer inlines the tenant's custom-fields schema into
  the tool definition; a separate service translates option labels to option
  IDs and formats proposal-card diffs.

**PendingAction / Proposal:**
- Purpose: Defer a chat-tool write until user approval, allow multi-step plans
  referencing each other's not-yet-created records.
- Examples: `packages/Chat/src/Models/PendingAction.php`,
  `packages/Chat/src/Services/ProposalPlanService.php`,
  `packages/Chat/src/Services/PlanReferenceResolver.php`
- Pattern: Proposal-time validation of shape only; reference resolution and
  the actual `Action::execute()` call happen at approval time.

**Custom Field model swap:**
- Purpose: Use app-specific `CustomField*` subclasses instead of the raw
  custom-fields package models everywhere in the app.
- Examples: `app/Models/CustomField.php`, `app/Models/CustomFieldValue.php`,
  `app/Models/CustomFieldOption.php`, `app/Models/CustomFieldSection.php`
- Pattern: Runtime model swapping configured in `app/Providers/AppServiceProvider.php`.

## Entry Points

**App Filament panel:**
- Location: `app/Providers/Filament/AppPanelProvider.php`
- Triggers: Authenticated browser requests to the main app domain/path.
- Responsibilities: Registers CRM resources, pages, widgets, tenant (team)
  resolution, panel-level middleware.

**SystemAdmin Filament panel:**
- Location: `packages/SystemAdmin/src/SystemAdminPanelProvider.php`
- Triggers: Requests to the internal admin panel path/domain.
- Responsibilities: Internal ops tooling; separate auth guard; reads back into
  `App\Models`, `App\Enums`, `App\Rules` only (never other `App` namespaces).

**REST API:**
- Location: `routes/api.php`, `app/Http/Controllers/`
- Triggers: Token-authenticated HTTP requests (domain- or prefix-routed
  depending on `app.api_domain` config), see `bootstrap/app.php`.
- Responsibilities: CRUD over CRM entities for external integrations.

**MCP server:**
- Location: `app/Mcp/Servers/RelaticleServer.php`, `routes/ai.php`
- Triggers: MCP protocol requests from AI agent clients.
- Responsibilities: Exposes tools/resources/prompts for CRM read/write.

**Chat AI assistant:**
- Location: `packages/Chat/src/Livewire/Chat/`, `packages/Chat/src/Agents/CrmAssistant.php`
- Triggers: In-app chat UI messages (streamed via broadcasting/Reverb).
- Responsibilities: Conversational CRM assistant with tool-calling and
  proposal/approval workflow.

**Console scheduler:**
- Location: `bootstrap/app.php` (`withSchedule()`)
- Triggers: Laravel scheduler (cron).
- Responsibilities: All scheduled commands; never defined in `routes/console.php`.

## Architectural Constraints

- **Threading:** Standard PHP-FPM request/response model; background work
  (chat streaming, notifications, imports) runs via queued jobs on Horizon
  (Redis queue in production; `QUEUE_CONNECTION` must be `redis`, not `sync`,
  for realistic chat verification).
- **Global state:** Tenant context for the custom-fields package is ambient,
  set via `TenantContextService::setTenantId()` (see
  `App\Http\Middleware\SetApiTeamContext` and Filament's own
  `SetTenantContextMiddleware` inside the custom-fields package). Any write
  path outside a Filament panel request or `SetApiTeamContext` (queued jobs,
  webhooks, commands, chat approval) must set and restore this manually
  (try/finally) or custom-field writes silently iterate every tenant.
- **Circular imports:** None known; module boundaries are enforced by
  `tests/Arch/ArchTest.php` (`App` must not depend on `Relaticle\SystemAdmin`;
  `Relaticle\SystemAdmin` may only reach back into `App\Models`, `App\Enums`,
  `App\Rules`).
- **Database:** PostgreSQL only. All timestamp columns are
  `timestamp without time zone` holding UTC; never use `DB::raw('now()')`,
  `CURRENT_TIMESTAMP`, or `->useCurrent()`/`->useCurrentOnUpdate()` defaults
  (those resolve against session timezone). Always pass PHP-side `now()`.
- **PHPStan coverage gap:** `packages/SystemAdmin` is excluded from PHPStan.
  Adding/removing enum cases requires manually sweeping SystemAdmin for
  `match` expressions over that enum (a past production `UnhandledMatchError`
  came from this gap).

## Anti-Patterns

### Inline business logic outside actions

**What happens:** Business logic (validation beyond form rules, side effects,
multi-step writes) is written directly inside a controller, Livewire
component, Filament resource callback, or chat/MCP tool.
**Why it's wrong:** Bypasses the single source of truth for authorization and
tenant-ownership checks; duplicates logic across the four adapter faces
(Filament, API, MCP, Chat) and risks them drifting out of sync.
**Do this instead:** Extract to `final readonly class` action in
`app/Actions/<Domain>/` (or the relevant package's `src/Actions/`) with a
single `execute()` method, called from all adapters. Enforced by the
`EloquentWriteOutsideActionRule` PHPStan rule for Eloquent writes; pre-existing
violations are grandfathered per-file in `phpstan.neon` and should be removed
from the ignore list when refactored.

### Hardcoded user-facing strings

**What happens:** Literal strings passed to Filament `label()`, `heading()`,
`title()`, or static properties like `$navigationLabel`.
**Why it's wrong:** Breaks i18n; two custom PHPStan rules
(`app/PHPStan/Rules/HardcodedUserFacingStringRule.php`,
`HardcodedStaticPropertyRule.php`) exist specifically to catch this.
**Do this instead:** Wrap in `__()`.

### Rewriting replayed chat tool results

**What happens:** Mutating an earlier chat message/tool result when replaying
a conversation.
**Why it's wrong:** Invalidates the Anthropic prompt-cache prefix from that
turn onward, causing cost and latency regressions.
**Do this instead:** Decided status travels only in `<resolved_actions>`,
auto-cancelled status in `<superseded_proposals>`, both re-queried per turn.
See `packages/Chat/src/Services/ProposalPlanService.php`.

## Error Handling

**Strategy:** Laravel's default exception handling
(`bootstrap/app.php` `->withExceptions()`), with `abort_unless()`/`abort()`
used inside actions for authorization and domain validation failures (403/422),
and Sentry (`Sentry\Laravel\Integration`) for error tracking.

**Patterns:**
- Actions call `abort_unless($user->can(...), 403)` as the first line for
  authorization.
- Domain validation failures use `abort(422, "message")` with a descriptive
  message (see `AggregateOpportunities::execute()`).
- Tenant-ownership violations are centralized in
  `App\Support\TenantFkValidator::assertOwned()`.

## Cross-Cutting Concerns

**Logging:** Standard Laravel logging plus Sentry integration for exceptions.

**Validation:** Split between Filament form-level validation (UI) and
action-level authorization/tenant checks (`TenantFkValidator`); custom field
values validated by `CustomFieldValidationService` in the custom-fields
package (uses explicit `where('tenant_id', ...)` with `withoutGlobalScopes()`
deliberately, not ambient tenant state).

**Authentication:** Laravel Fortify (`app/Actions/Fortify/`) for
app-panel auth (including passkeys), Laravel Passport for API/OAuth tokens
(`app/Models/Passport/`), separate guard for the SystemAdmin panel.

---

*Architecture analysis: 2026-09-02*

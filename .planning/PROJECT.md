# Relaticle

## What This Is

Relaticle is a multi-tenant CRM SaaS built on Laravel. Sales/ops teams manage companies,
people, opportunities, tasks, and notes, each scoped to a tenant (`team_id`), with a
per-tenant custom-fields layer on top of the fixed CRM models. The same domain is exposed
through four parallel faces: the Filament app panel (primary UI), a token-authenticated
REST API, an MCP server for external AI agents, and an in-app AI chat assistant with a
propose/approve workflow — all funneling writes through a single action-class layer so
behavior stays consistent across every surface.

## Core Value

Sales/ops teams get reliable, tenant-isolated CRM data, with identical write behavior
(authorization, tenant checks, side effects) no matter which surface they use — UI, API,
MCP, or chat.

## Business Context

- **Customer**: Teams using Relaticle as their CRM (multi-tenant SaaS, one tenant per team)
- **Revenue model**: Subscription billing via Stripe (Laravel Cashier), gated behind the `RELATICLE_FEATURE_BILLING` flag
- **Success metric**: Not yet defined in planning docs — set this at the first milestone that touches billing/growth
- **Strategy notes**: None linked yet

## Current Milestone: v1.0 Ollama Cloud Provider

**Goal:** Add Ollama Cloud as a fully-fledged AI model provider, configured and managed the same way as Anthropic/OpenAI/Gemini.

**Target features:**
- New `ollama_cloud` provider entry in `config/ai.php` (env-driven API key + base URL), separate from the existing local self-hosted `ollama` entry
- Treated as a normal cloud provider in the chat model catalog: plan-gated, priced, verified via `ModelProbe`, managed through the sysadmin Model Catalog page
- Live model listing: `ProviderModelCatalog` extended so the sysadmin catalog's model picker is fed from Ollama Cloud's own models endpoint instead of free text

## Requirements

### Validated

- ✓ Multi-tenant CRM core (companies, people, opportunities, tasks, notes), scoped by `team_id` — existing
- ✓ Per-tenant custom fields layer across CRM entities, wrapped by app-specific `CustomField*` models — existing
- ✓ Filament app panel as the primary CRM UI — existing
- ✓ Separate SystemAdmin Filament panel for internal ops, its own auth guard — existing
- ✓ Token-authenticated REST API for CRM entities (Sanctum/Passport) — existing
- ✓ MCP server exposing CRM read/write as tools/resources to external AI agents — existing
- ✓ In-app AI chat assistant with tool-calling and a propose/approve workflow (`PendingAction`, shared `turn_id` plans, `$ref:` cross-references) — existing
- ✓ Chat tools auto-support every active custom field per entity with no per-field code — existing
- ✓ CSV import via ImportWizard — existing
- ✓ Stripe billing via Cashier, feature-flagged — existing
- ✓ Auth: Fortify + Jetstream (teams, 2FA, passkeys) + Passport (OAuth) + Socialite (Google, Microsoft Entra ID) — existing
- ✓ Realtime chat streaming via Reverb/Horizon (Redis queue) — existing
- ✓ Public documentation pages (`packages/Documentation`) and demo/onboarding data seeding (`packages/OnboardSeed`) — existing

### Active

- [ ] Ollama Cloud added as a provider in `config/ai.php`, keyed by `OLLAMA_CLOUD_API_KEY`
- [ ] Ollama Cloud models managed via the sysadmin Model Catalog page (plan-gated, priced, `ModelProbe`-verified), like Anthropic/OpenAI
- [ ] Sysadmin Model Catalog's model picker fetches Ollama Cloud's live model list instead of relying on free text

### Out of Scope

(Nothing excluded yet — no scope has been proposed to exclude)

## Context

- Modular monolith: CRM core lives in `app/`; self-contained subsystems (Chat, SystemAdmin,
  ImportWizard, Documentation, OnboardSeed) live in `packages/<Name>/` as
  `Relaticle\<Name>`-namespaced code with no per-package `composer.json`.
- All writes funnel through `final readonly` action classes in `app/Actions/<Domain>/`
  (or the relevant package's `src/Actions/`), enforced by the `EloquentWriteOutsideActionRule`
  PHPStan rule. 8 pre-existing violations remain grandfathered in `phpstan.neon`.
- Codebase already mapped via `/gsd-map-codebase` into `.planning/codebase/`
  (`STACK.md`, `ARCHITECTURE.md`, `STRUCTURE.md`, `CONVENTIONS.md`, `INTEGRATIONS.md`,
  `TESTING.md`, `CONCERNS.md`) plus a knowledge graph in `.planning/graphs/`. Read those
  before planning new phases instead of rediscovering structure.
- Known tech debt / fragility (see `CONCERNS.md` for full detail): `packages/SystemAdmin`
  is excluded from PHPStan entirely (manual sweep required when enums it consumes gain
  cases); large single-responsibility files in the Chat package
  (`ProposalCard.php` 1,743 lines, `PendingActionService.php` 1,102 lines,
  `ProcessChatMessage.php` 1,000 lines) carry higher regression risk; deferred i18n
  hardcoded-string exclusions exist for `app/Mcp/*`, `packages/ImportWizard/*`, and
  `app/Notifications/*`.
- No `tests/Unit/` suite exists by design; actions and services are tested only through
  their real entry points (Feature/Smoke/Browser). A handful of single-action domains
  (`app/Actions/Chat`, `Crm`, `Mcp`, `Onboarding`, `Passkeys`, `Team`) have no sibling test
  to extend if their entry-point coverage is thin.

## Constraints

- **Database**: PostgreSQL exclusively; every timestamp column is
  `timestamp without time zone` holding UTC — never write from the DB clock
  (`DB::raw('now()')`, `CURRENT_TIMESTAMP`, `->useCurrent()`)
- **Tech stack**: Laravel ^13, PHP ^8.5, Filament ^5, Livewire ^4, Pest ^5 — this is a
  production SaaS with paying customers; no lazy shortcuts or placeholder code
- **Module boundaries**: `App` must not depend on `Relaticle\SystemAdmin`;
  `Relaticle\SystemAdmin` may only reach back into `App\Models`, `App\Enums`, `App\Rules`
  (enforced by `tests/Arch/ArchTest.php`)
- **Chat tenant context**: any write path outside a Filament panel request or
  `SetApiTeamContext` middleware (queued jobs, webhooks, commands, chat approval) must set
  `TenantContextService::setTenantId()` and restore it in `finally`, or custom-field writes
  silently iterate every tenant
- **i18n**: user-facing strings must go through `__()`; enforced by custom PHPStan rules
  with some paths deferred (see Context above)

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| Skip REQUIREMENTS.md and ROADMAP.md during this init | No specific next feature chosen yet; user wants scope defined per-milestone via `/gsd-new-milestone` instead of guessing scope now | — Pending |
| Workflow config: YOLO mode, coarse granularity, parallel execution, adaptive models, research/plan-check/verifier/drift-guard all on | User chose "Configure fresh" and picked these explicitly over saved global defaults | — Pending |

## Evolution

This document evolves at phase transitions and milestone boundaries.

**After each phase transition** (via `/gsd-transition`):
1. Requirements invalidated? → Move to Out of Scope with reason
2. Requirements validated? → Move to Validated with phase reference
3. New requirements emerged? → Add to Active
4. Decisions to log? → Add to Key Decisions
5. "What This Is" still accurate? → Update if drifted

**After each milestone** (via `/gsd-complete-milestone`):
1. Full review of all sections
2. Core Value check — still the right priority?
3. Audit Out of Scope — reasons still valid?
4. Update Context with current state

---
*Last updated: 2026-09-02 after starting milestone v1.0 (Ollama Cloud Provider)*

# Codebase Concerns

**Analysis Date:** 2026-09-02

## Tech Debt

**Eloquent writes outside action classes (grandfathered):**
- Issue: `App\PHPStan\Rules\EloquentWriteOutsideActionRule` forbids Eloquent writes in controllers, MCP tools, Livewire components, Filament classes, and chat tools, but 8 pre-existing files are explicitly ignored in `phpstan.neon` (grandfathered as of 2026-06-12, originally 32 violations, now down to 8 entries).
- Files:
  - `app/Filament/Pages/CreateTeam.php`
  - `app/Filament/Resources/OpportunityResource/Pages/OpportunitiesBoard.php`
  - `app/Filament/Resources/PeopleResource.php`
  - `app/Filament/Resources/TaskResource/Pages/TasksBoard.php`
  - `app/Http/Controllers/Auth/CallbackController.php`
  - `app/Livewire/App/AccessTokens/CreateAccessToken.php`
  - `app/Livewire/App/AccessTokens/ManageAccessTokens.php`
  - `app/Livewire/App/Profile/UpdatePassword.php`
- Impact: business logic and side effects for these writes live outside the single-source-of-truth action layer, making them harder to reuse (e.g. from chat tools or MCP) and easier to diverge from the audited write path.
- Fix approach: extract each file's write into an `app/Actions/<Domain>/` action class with `execute()`, then remove its ignore entry in `phpstan.neon` (the rule fails on unmatched ignores, so entries cannot go stale silently).

**Deferred i18n hardcoded strings:**
- Issue: `HardcodedUserFacingStringRule` and `HardcodedStaticPropertyRule` are explicitly suppressed for several paths, meaning user-facing strings there are not wrapped in `__()` and won't be translatable.
- Files/paths (see `phpstan.neon` lines 23-81):
  - `app/Console/*` (console output, low priority - dev/internal facing)
  - `app/Mcp/*` (MCP tool labels/descriptions, deferred to "Phase 4")
  - `packages/ImportWizard/*` (both string and static-property rules, deferred to "Phase 4")
  - `app/Notifications/*` (notification/mail templates, deferred to "Phase 2")
  - `app/Actions/Task/NotifyTaskAssignees.php` (notification `->label()`)
  - `app/Livewire/BaseLivewireComponent.php` (notification `->title()`)
  - `app/Http/Controllers/Auth/CallbackController.php` (OAuth failure `->title()`)
  - `packages/Chat/src/Tools/*` (tool `->description()` text — arguably correct to exclude since it's LLM schema, not UI, but worth confirming no genuinely user-facing string leaked in via this broad exclusion)
- Impact: these strings will not localize when the app adds additional locales; console/MCP-tool exclusions are lower risk since that text is developer/agent-facing, but ImportWizard and Notifications are end-user facing product surface.
- Fix approach: work through the "Phase 2"/"Phase 4" i18n backlog referenced in the comments; wrap remaining strings in `__()` and remove each ignore entry.

**`packages/SystemAdmin` excluded from PHPStan entirely:**
- Issue: `excludePaths` in `phpstan.neon` drops the whole package (measured 2026-06-12: 306 errors, 213 i18n + 92 type issues + one identical.alwaysTrue cluster of 67). This means no static analysis coverage for type errors or exhaustiveness in the internal sysadmin panel.
- Files: `packages/SystemAdmin/**`
- Impact: previously caused a production `UnhandledMatchError` when an enum case was added without sweeping SystemAdmin's `match` expressions over that enum (per project instructions). The risk pattern recurs any time an enum consumed by SystemAdmin gains a case.
- Current `match` expressions over enum/state values in SystemAdmin (audited 2026-09-02), all currently have a `default` arm so a new case degrades gracefully rather than throwing:
  - `packages/SystemAdmin/src/Enums/SystemAdministratorRole.php:13` (match over `$this`, self-contained)
  - `packages/SystemAdmin/src/Enums/BlogTokenAbility.php:27` (match over `$this`, self-contained)
  - `packages/SystemAdmin/src/Filament/Resources/ImportResource.php:158-166` (`match ($state)` over `ImportStatus` — has `default => 'gray'`)
  - `packages/SystemAdmin/src/Filament/Resources/AiCreditBalanceResource.php:122` (`match (true)` over int comparisons)
  - `packages/SystemAdmin/src/Filament/Resources/ActivityResource.php:103, 204, 245` (`match ($state)` over string event names — all have `default` arms)
  - `packages/SystemAdmin/src/Filament/Resources/TeamResource/RelationManagers/ActivityRelationManager.php:71` (same pattern, has `default`)
  - `packages/SystemAdmin/src/Filament/Resources/SystemAdministrators/Tables/SystemAdministratorsTable.php:38` (`match ($state)` over `SystemAdministratorRole` — verify a `default` exists before adding a new role)
  - Fix approach: no immediate exhaustiveness bug found, but every `match` here is a candidate to silently mis-render (fall through to `default => 'gray'`) rather than error when an enum grows. Manually sweep this list whenever a case is added to `ImportStatus`, `SystemAdministratorRole`, or activity `event` string values, per the standing project rule.

## Known Bugs

None identified during this pass. No open TODO/FIXME/HACK/XXX markers exist anywhere in `app/` or `packages/` (verified via repo-wide grep on 2026-09-02).

## Security Considerations

**Tenant context outside Filament/API middleware:**
- Risk: `TenantContextService::setTenantId()` must be set on every custom-fields write path that isn't a Filament panel request or behind `SetApiTeamContext`, or `saveCustomFields` iterates every tenant (gateway timeouts, cross-tenant writes).
- Files audited (all correctly wrap with try/finally restoring the previous tenant id):
  - `app/Http/Middleware/SetApiTeamContext.php` (API entry point)
  - `packages/Chat/src/Jobs/ProcessChatMessage.php:986-993`
  - `packages/Chat/src/Services/PendingActionService.php:176-223` and `:305-351` (two separate call sites, both restore previous tenant in `finally`)
  - `app/Console/Commands/ResetDemoAccountCommand.php:109-140`
- Current mitigation: all found call sites follow the documented pattern (`getCurrentTenantId()` → `setTenantId()` → `finally` restore). No violation found in this pass.
- Recommendations: this is a correctness-by-convention pattern with no compiler enforcement. Any new queued job, webhook handler, or console command that writes custom fields must be added to this list and reviewed for the same try/finally wrapping. Treat `grep -rln "TenantContextService" app packages` as the audit command before every custom-fields-touching addition outside Filament/API.

**Stripe webhook controller:**
- File: `app/Http/Controllers/Billing/StripeWebhookController.php`
- Note: does not appear in the `TenantContextService` usage list, meaning it does not directly write custom fields (subscription sync goes through `App\Actions\Billing\SyncTeamPlanFromSubscription`, which also does not touch custom fields). No tenant-context gap found for this path, but any future extension of webhook handling to touch CRM entities with custom fields must add the wrapper.

## Performance Bottlenecks

**Large single-responsibility files (complexity risk, not confirmed perf issue):**
- `packages/Chat/src/Livewire/Chat/ProposalCard.php` (1,743 lines) — the Livewire component rendering/approving/rejecting proposal cards. Size makes it a high blast-radius file; any bug fix here risks touching many code paths at once.
- `packages/ImportWizard/src/Jobs/ExecuteImportJob.php` (1,141 lines) — CSV import execution job.
- `packages/Chat/src/Services/PendingActionService.php` (1,102 lines) — approval/reject/settlement logic for chat write proposals, including the tenant-context-sensitive paths above.
- `packages/Chat/src/Jobs/ProcessChatMessage.php` (1,000 lines) — the chat message processing pipeline.
- Impact: none of these show obvious algorithmic bottlenecks on inspection, but their size correlates with higher review/maintenance cost and higher risk of regressions when extended (per the standing `ProposalCard.php`/tool-sweep rules already documented in `.ai/rules/chat.md`).
- Improvement path: no action needed unless a specific slow path is identified; flagged here as a fragility/maintainability concern rather than a measured perf problem.

## Fragile Areas

**`packages/Chat/src/Livewire/Chat/ProposalCard.php`:**
- Files: `packages/Chat/src/Livewire/Chat/ProposalCard.php`
- Why fragile: single largest file in the codebase; per project rules, replayed proposal tool results must never be rewritten (breaks Anthropic prompt-cache prefix) and `$ref:<pending_action_id>` links must never be hidden from the rendered card. Both invariants live in this file's rendering logic and are easy to violate accidentally in a large component.
- Safe modification: consult `.ai/rules/chat.md` before touching; changes to one write tool's proposal rendering should be swept across sibling Create/Update/Delete tools for the same entity per the standing project rule.
- Test coverage: has dedicated coverage (`tests/Feature/Chat/ProposalCardLifecycleTest.php`, `ProposalCardResolutionFailureTest.php`, `ProposalCardEditingTest.php`, `tests/Helpers/ProposalCardFixture.php`), which mitigates but does not eliminate the risk given the file's size.

**`packages/Chat/src/Services/PendingActionService.php`:**
- Files: `packages/Chat/src/Services/PendingActionService.php`
- Why fragile: owns the tenant-context-sensitive approval/reject/settlement flow (two separate `setTenantId`/`finally` blocks) plus reference resolution for `$ref:<pending_action_id>` at approval time only (`PlanReferenceResolver`, per project rules). A refactor that moves resolution earlier than approval time would silently break the "never resolve at proposal time" invariant.
- Safe modification: any change to tenant-context wrapping must preserve the try/finally restore pattern; verify with a cross-tenant test scenario.
- Test coverage: `tests/Feature/Chat/PendingActionSupersedeTest.php`, `PendingActionAllowlistTest.php`, `PendingActionConversationIdTest.php`, `PendingActionDisplayDataTest.php` exist, but no test file name directly signals coverage of the tenant-context restore-on-exception path; worth confirming a test forces an exception mid-approval to verify `finally` actually restores the previous tenant id.

## Scaling Limits

Not assessed in this pass; no capacity/throughput data was available from static inspection.

## Dependencies at Risk

Not assessed in this pass (tech-stack/dependency-freshness analysis belongs to STACK.md under the `tech` focus, not `concerns`).

## Missing Critical Features

None identified as blocking during this pass.

## Test Coverage Gaps

**Action classes have no dedicated unit tests (by design, but creates a discoverability gap):**
- What's not tested directly: `app/Actions/**` (73 action files across 17 domains) are intentionally tested only through their real entry points (API, Filament, Livewire) per the project's testing philosophy (no `tests/Unit/` suite exists). This is a deliberate convention, not a gap, but it means a new action with a thin or missing Filament/API/chat entry-point test has no coverage at all.
- Files: notable low-file-count domains where a missing entry-point test would leave an action completely uncovered: `app/Actions/Chat` (1 file), `app/Actions/Crm` (1 file, `GetCrmSummary.php`), `app/Actions/Mcp` (1 file), `app/Actions/Onboarding` (1 file), `app/Actions/Passkeys` (1 file), `app/Actions/Team` (1 file).
- Risk: silent regressions in single-action domains that lack an obvious sibling test to extend, since the "search tests/ for files covering the same class" convention finds nothing to extend for a first-of-its-kind action.
- Priority: Medium — verify each of these single-file domains has at least one Feature test exercising its entry point before adding logic to it.

**Chat tool sweep risk:**
- What's not tested: `packages/Chat/src/Tools/**` has 51 tool files, but only 17 Create/Update/Delete tool files match the naming pattern directly, and chat write tools are explicitly required by project rules to be swept in sibling groups when one has a bug fix (Create/Update/Delete for the same entity). A tool without a sibling-pattern test makes that sweep harder to verify mechanically.
- Files: `packages/Chat/src/Tools/**`
- Risk: a bug fixed in one tool (e.g. `CreateOpportunityTool`) not being propagated to its siblings (`UpdateOpportunityTool`, `DeleteOpportunityTool`) would ship a known-bug-class regression, which the project's own `.ai/rules/chat.md` calls out as a recurring failure mode.
- Priority: Medium — when reviewing chat tool test coverage, group by entity (Opportunity, Task, Note, Company, People, CustomField, Activity) and confirm each Create/Update/Delete trio has matching coverage.

---

*Concerns audit: 2026-09-02*

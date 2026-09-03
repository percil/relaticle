---
phase: 01-ollama-cloud-provider-integration
plan: 04
subsystem: infra
tags: [laravel, spatie-health, service-providers, boot-order, ollama-cloud, pest]

requires:
  - phase: 01-ollama-cloud-provider-integration
    provides: "01-01/01-02 delivered the ollama_cloud provider config, live model listing, and two verified catalog rows (gpt-oss:20b, gpt-oss:120b) with capabilities.supports_tools=true and real verified_at timestamps"
provides:
  - "HealthServiceProvider::boot() defers Health::checks() registration to $this->app->booted(), so chat provider checks read config('chat.models') after ChatServiceProvider's settings overlay has landed, independent of bootstrap/providers.php order"
  - "A full-application-boot regression test that fails if the boot-order dependency is reintroduced (by reverting the deferral or by reordering bootstrap/providers.php)"
affects: [health-checks, chat-provider-monitoring, service-provider-boot-order]

actuals:
  tokens: 2702
  tasks: 3
  commits: 2

tech-stack:
  added: []
  patterns:
    - "Application::booted() closure wrap to defer eager provider work past every other provider's boot(), when the work depends on a later provider's boot-time side effect (here: ChatServiceProvider::applyStoredSettings() overlaying config('chat.models'))"
    - "Full second-application-boot regression test (require base_path('bootstrap/app.php') + booting() callback + Kernel::bootstrap(), wrapped in try/finally restoring Container/Facade/Model statics) for boot-order invariants that a hand-constructed provider instance cannot exercise"

key-files:
  created: []
  modified:
    - app/Providers/HealthServiceProvider.php
    - tests/Feature/HealthChecks/HealthServiceProviderTest.php

key-decisions:
  - "Used $this->app->booted(...), not booting() and not a bootstrap/providers.php reorder, per the plan's pre-verified justification: booted() fires strictly after every provider's boot() including ChatServiceProvider's settings overlay, and is independent of provider list order"
  - "The failing-direction proof for Task 2 used `git checkout <parent-commit> -- <file>` / `git checkout <fix-commit> -- <file>` instead of `git stash`, because Task 1's fix was already committed by the time Task 2 ran (per-task atomic commits) so `git stash push -- <file>` had nothing uncommitted to stash and reported \"No local changes to save\". Checking a tracked file out to a specific ref and back is the same class of targeted, reversible operation git stash would have performed, and is explicitly sanctioned for single-file reverts."

requirements-completed: [OLLAMA-05]

coverage:
  - id: D1
    description: "Ollama Cloud's chat provider health check registers and reports its real probe status once a model is servable, closing the previously-silent gap (success criterion 4 / OLLAMA-05)"
    requirement: OLLAMA-05
    verification:
      - kind: integration
        ref: "tests/Feature/HealthChecks/HealthServiceProviderTest.php#it registers a chat provider check for a model that only the runtime settings overlay makes servable"
        status: pass
      - kind: other
        ref: "COLUMNS=200 HEALTH_CHECKS_ENABLED=true php artisan health:check --no-ansi"
        status: pass
    human_judgment: false
  - id: D2
    description: "Chat provider check registration is independent of bootstrap/providers.php order; a standing test fails if the boot-order dependency is reintroduced"
    requirement: OLLAMA-05
    verification:
      - kind: integration
        ref: "tests/Feature/HealthChecks/HealthServiceProviderTest.php (failing-direction proof: fails against pre-fix HealthServiceProvider, passes against the fix)"
        status: pass
    human_judgment: false
  - id: D3
    description: "Anthropic/OpenAI chat provider checks, every non-chat health check, and the full Chat/SystemAdmin/HealthChecks/Arch suites are unaffected by the deferral"
    verification:
      - kind: integration
        ref: "vendor/bin/pest --no-tia tests/Feature/HealthChecks/ (19/19 passed)"
        status: pass
      - kind: integration
        ref: "vendor/bin/pest --no-tia tests/Feature/Chat/ tests/Feature/SystemAdmin/ tests/Feature/HealthChecks/ (1414/1414 passed)"
        status: pass
      - kind: integration
        ref: "vendor/bin/pest --no-tia tests/Arch/ (69/69 passed)"
        status: pass
    human_judgment: false

duration: 25min
completed: 2026-09-03
status: complete
---

# Phase 01 Plan 04: Defer Chat Provider Health Checks Past the Settings Overlay Summary

**Closed OLLAMA-05's boot-order gap: `HealthServiceProvider::boot()` now registers `Health::checks()` inside `$this->app->booted(...)`, so the Ollama Cloud chat provider check reads the live, settings-overlaid catalog instead of the frozen fresh-install seed, and a full-application-boot regression test pins the fix.**

## Performance

- **Duration:** ~25 min
- **Completed:** 2026-09-03T07:23:07Z
- **Tasks:** 3 (3 completed)
- **Files modified:** 2

## Accomplishments

- `HealthServiceProvider::boot()` wraps its existing `Health::checks([...])` call in a closure passed to `$this->app->booted(...)`, deferring chat provider check registration until after every service provider (including `ChatServiceProvider`, which overlays the runtime-editable settings catalog onto `config('chat.models')`) has booted. All 17 pre-existing checks, their order, and the `isEnabled()` early-return guard are byte-identical; only the registration timing changed.
- Live-verified on the real catalog: `COLUMNS=200 HEALTH_CHECKS_ENABLED=true php artisan health:check --no-ansi` now registers and runs a chat provider check for `ollama_cloud`, reporting `Ok: gpt-oss:20b` — where before the fix, no such check existed at all, in any configuration.
- Added a regression test to `tests/Feature/HealthChecks/HealthServiceProviderTest.php` that boots a **second, real application** through `bootstrap/app.php` + `Kernel::bootstrap()` (not a hand-constructed `HealthServiceProvider` instance, which is exactly the pattern that let this bug ship green originally), overlays a servable `ollama_cloud` catalog row via `ChatSettings::fake()` inside an `Application::booting()` callback, and asserts `Health::registeredChecks()` contains `Chat provider: ollama_cloud`. Confirmed the failing direction first (see below) before accepting the test.
- Ran the full CLAUDE.md pre-commit gate chain plus the suites `01-VERIFICATION.md` measured; all pass at or above their recorded baselines, with `git diff --name-only` (post-commit) and `git diff -- phpstan.neon` confirming no file outside the two intended files was touched and no new PHPStan ignore was added.

## Task Commits

Each task was committed atomically:

1. **Task 1: Defer chat provider check registration past the settings overlay, and prove it on the real CLI** - `701b847f` (fix)
2. **Task 2: Pin the boot-order invariant with a full-application-boot regression test** - `ed25da80` (test)
3. **Task 3: Run the repository's pre-commit quality gates and the surrounding suites** - no commit (verification-only task; no files changed beyond what Tasks 1-2 already committed)

**Plan metadata:** pending (this SUMMARY + STATE.md + ROADMAP.md + REQUIREMENTS.md commit, made after this file is written)

## Files Created/Modified

- `app/Providers/HealthServiceProvider.php` - `boot()` now wraps `Health::checks([...])` in `$this->app->booted(fn (): void => ...)`, with a new docblock explaining the deferral and why it is order-independent
- `tests/Feature/HealthChecks/HealthServiceProviderTest.php` - added one `it(...)` case (`registers a chat provider check for a model that only the runtime settings overlay makes servable`) that boots a second full application and asserts the boot-order invariant; the two pre-existing cases are unmodified

## Decisions Made

- Used `$this->app->booted(...)`, not `booting()` and not a `bootstrap/providers.php` reorder, matching the plan's pre-verified justification: `booted()` fires after every provider's `boot()` (so it observes `ChatServiceProvider`'s settings overlay regardless of provider-list order), and reordering or moving `applyStoredSettings()` into `register()` were both independently rejected in the plan on hard evidence (the settings table's connection resolver isn't set until `DatabaseServiceProvider::boot()`, which runs after every provider's `register()`).
- For Task 2's failing-direction proof, substituted `git checkout <ref> -- <file>` / `git checkout <ref> -- <file>` for the plan's literal `git stash push -- <file>` step. Task 1's fix was already committed (per-task atomic commits are this executor's protocol), so there were no uncommitted changes for `git stash` to capture — it reported `No local changes to save`. Checking the tracked file out to the parent commit (pre-fix), running the test, then checking it back out to the fix commit is the equivalent targeted, reversible single-file operation and produced the same evidentiary result: the new test fails against the unfixed provider and passes against the fix.

## Deviations from Plan

### Auto-fixed Issues

None - no bugs, missing functionality, or blocking issues were found in the plan's own scope. Two verification-command environment issues were worked around without changing scope or files (documented below), not deviations under Rules 1-3.

**Verification-environment notes (not deviations, not auto-fixes):**

1. **`vendor/bin/phpstan analyse` and `vendor/bin/pest --no-tia tests/Arch/` (and the Chat/SystemAdmin/HealthChecks Feature run) exhausted PHP's default 128M CLI `memory_limit`** when run without an override, because both boot the full Laravel application (larastan's bootstrap and the Arch suite's full-namespace object scan). This is a pre-existing local-environment limit unrelated to this plan's two changed files. Re-ran each with `php -d memory_limit=2G` (phpstan, Arch, and the three-path Feature run) and all passed clean; `composer test:type-coverage` and `composer test:lint` also needed the same override to complete without truncating mid-run.
2. **The literal string `ollama_cloud` never appears in `health:check`'s console output.** `RunHealthChecksCommand` prints `$check->getLabel()`, not `$check->getName()`; `Check::getLabel()` falls back to a snake-then-title-cased transform of `getName()` when no explicit `->label()` is set (confirmed by reading `vendor/spatie/laravel-health/src/Checks/Check.php`). `ChatProviderCheck` only calls `->name("Chat provider: {$provider}")`, so the CLI renders `Chat Provider: Ollama Cloud`, not the raw `Chat provider: ollama_cloud`. The plan's literal `grep -F 'ollama_cloud'` verify command therefore prints nothing even though the check is fully registered and reporting; confirmed with a case-insensitive `grep -i 'ollama'` and, more rigorously, with Task 2's automated assertion against `$check->getName()`, which does return the raw `'Chat provider: ollama_cloud'` string. No code change was warranted: `getName()` — the value Task 2's test and `ChatProviderCheck::forConfiguredProviders()`'s own naming convention use — is exactly right, and `getLabel()`'s humanization is spatie/laravel-health's existing, unrelated display behavior applied identically to every other check in the file (`Database Connection Count`, `Used Disk Space`, etc.).

---

**Total deviations:** 0 auto-fixed. Two verification-environment notes recorded above (memory limit override, CLI label humanization) — neither required a code change.
**Impact on plan:** None. Both files match the plan's exact scope; no additional files were touched.

## Issues Encountered

None beyond the two verification-environment notes above (memory limit, CLI label rendering), both resolved without touching production code.

## Task 1 Precondition Check

Held. Verified via `php artisan config:show ai.providers.ollama_cloud` (non-empty `key`) and `php artisan config:show chat.models` (both `ollama_cloud` rows — `gpt-oss:20b` and `gpt-oss:120b` — show `capabilities.supports_tools: true` and a real, non-null `verified_at`: `2026-09-02T17:32:37+02:00` and `2026-09-02T17:32:38+02:00` respectively). The CLI portion of Task 1 ran against the live, genuinely-servable catalog, not a fallback-only Pest gate.

## Verbatim `health:check` Output (Ollama Cloud line)

Command: `COLUMNS=200 HEALTH_CHECKS_ENABLED=true php artisan health:check --no-ansi`

```
Running check: Chat Provider: Ollama Cloud..
Ok: gpt-oss:20b
```

Status: **Ok**. Short summary: `gpt-oss:20b` (the free-tier row `reachableModels()` selected — sorted free-plan-first, then grouped one check per provider). Internal check name (as asserted by Task 2's test via `getName()`): `Chat provider: ollama_cloud`; rendered as `Chat Provider: Ollama Cloud` by the CLI's `getLabel()` humanization (see Deviations note 2 above for why the literal-string plan verify command finds nothing despite the check being fully registered and reporting).

## Task 2 Failing-Direction Proof (both outputs)

**With `app/Providers/HealthServiceProvider.php` reverted to its pre-fix state** (via `git checkout 701b847f~1 -- app/Providers/HealthServiceProvider.php`, restoring the eager, unwrapped `Health::checks([...])` call):

```
{"tool":"pest","result":"failed","tests":3,"passed":2,"assertions":5,"failed":1,
 "failures":[{"test":"...it_registers_a_chat_provider_check_for_a_model_that_only_the_runtime_settings_overlay_makes_servable",
 "line":95,"message":"Failed asserting that a traversable contains 'Chat provider: ollama_cloud'."}]}
```

2/3 tests passed (the two pre-existing hand-constructed-provider cases); the new case failed exactly on the boot-order assertion it exists to guard, confirming it does not pass vacuously.

**With `app/Providers/HealthServiceProvider.php` restored to the fix** (via `git checkout 701b847f -- app/Providers/HealthServiceProvider.php`):

```
{"tool":"pest","result":"passed","tests":3,"passed":3,"assertions":6,"duration_ms":733}
```

3/3 tests passed. `git status --short app/Providers/HealthServiceProvider.php` was empty afterward, confirming the working tree matched the committed fix with no residual diff.

## Actual Pass Counts vs. 01-VERIFICATION.md Baselines

| Suite | Command | 01-VERIFICATION.md baseline | Actual (this plan) | Status |
|---|---|---|---|---|
| `tests/Arch/` | `vendor/bin/pest --no-tia tests/Arch/` | 69 tests, 69 passed | **69 tests, 69 passed, 173 assertions** | ✓ at baseline |
| `tests/Feature/Chat/` + `tests/Feature/SystemAdmin/` + `tests/Feature/HealthChecks/` | `vendor/bin/pest --no-tia tests/Feature/Chat/ tests/Feature/SystemAdmin/ tests/Feature/HealthChecks/` | 1413 tests, 1413 passed | **1414 tests, 1414 passed, 10183 assertions** | ✓ baseline + 1 (Task 2's new case) |
| `tests/Feature/HealthChecks/` alone | `vendor/bin/pest --no-tia tests/Feature/HealthChecks/` | (not separately baselined; 34 combined with two other files in 01-VERIFICATION.md) | **19 tests, 19 passed, 27 assertions** | ✓ pass |

Additional gates: `vendor/bin/pint --dirty --format agent` passed with no changes needed at Task 3 time (files already pint-clean from Tasks 1-2); `vendor/bin/rector --dry-run` printed no proposed diff for either changed file; `vendor/bin/phpstan analyse` reported 0 errors; `composer test:type-coverage` reported `Total: 100.0 %`; `composer test:lint` (`pint --test --parallel`, whole repo) passed; `git diff -- phpstan.neon` is empty.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- OLLAMA-05 is now fully satisfied: the health surface reports Ollama Cloud's true status, reproduced with the same command `01-VERIFICATION.md` used to reproduce its absence, and a standing regression test guards the boot-order invariant against future reintroduction (by reverting the deferral or by reordering `bootstrap/providers.php`).
- Phase 01 (Ollama Cloud Provider Integration) has no remaining blocking gaps. Success criterion 5 / OLLAMA-06 was already accepted by the user's 2026-09-03 resolution recorded in `01-VERIFICATION.md` (does not block phase sign-off; the 3 chat-turn UX defects are tracked separately at `.planning/todos/pending/2026-09-03-chat-post-approval-defects-no-success-message-broken-turn-co.md`).
- No follow-up work identified within this plan's scope. `bootstrap/providers.php`, `packages/Chat/src/ChatServiceProvider.php`, and `app/Health/ChatProviderCheck.php` remain untouched, as required.

---
*Phase: 01-ollama-cloud-provider-integration*
*Completed: 2026-09-03*

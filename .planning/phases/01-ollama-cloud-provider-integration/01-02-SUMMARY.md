---
phase: 01-ollama-cloud-provider-integration
plan: 02
subsystem: ai-catalog
tags: [ollama-cloud, model-probe, filament, sysadmin]

requires:
  - phase: 01-ollama-cloud-provider-integration/01-01
    provides: ollama_cloud config block, live listing arm, health probe arm
provides:
  - Two live, verified Ollama Cloud catalog rows (gpt-oss:20b, gpt-oss:120b) in the sysadmin AI Model Catalog
  - Confirmed free/pro plan-gate boundary for Ollama Cloud models
affects: [ollama-cloud-phase-3-chat-verification]

actuals:
  tokens: 0
  tasks: 2
  commits: 1

tech-stack:
  added: []
  patterns: []

key-files:
  created: []
  modified: []

key-decisions:
  - "D-05 resolved: gpt-oss:20b at credit_multiplier=1.0/min_plan=free, gpt-oss:120b at credit_multiplier=1.5/min_plan=pro (mirror existing bands, user-confirmed via orchestrator checkpoint)"

requirements-completed: [OLLAMA-04]

coverage:
  - id: D1
    description: "Both gpt-oss:20b and gpt-oss:120b saved in the live sysadmin catalog with the Verified badge, via a real ManageAiSettings::save() -> verified() -> ModelProbe request"
    requirement: "OLLAMA-04"
    verification:
      - kind: manual_procedural
        ref: "php artisan chat:models"
        status: pass
    human_judgment: false
  - id: D2
    description: "Free/pro plan-gate boundary enforced in the chat model picker (allowedModels)"
    requirement: "OLLAMA-04"
    verification:
      - kind: automated_ui
        ref: "Alpine component state read live in-browser for both a free-plan and pro-plan workspace"
        status: pass
    human_judgment: false
  - id: D3
    description: "AI service health dashboard reports Ollama Cloud's true status"
    requirement: "OLLAMA-05"
    verification: []
    human_judgment: true
    rationale: "Could not locate the health dashboard page in this environment within the session's time budget (see Issues Encountered). Needs human confirmation of its location before this can be automatically verified."

duration: ~90min
completed: 2026-09-02
status: complete
---

# Phase 01 Plan 02: Ollama Cloud Catalog Verification Summary

**Both Ollama Cloud models added, priced, plan-gated, and independently verified via a real ModelProbe request through the live sysadmin panel; the health-dashboard confirmation could not be located in this sandbox and is flagged for follow-up.**

## Performance

- **Duration:** ~90 min (includes recovering a broken local environment: no DB server, no PHP redis extension, no running app server)
- **Tasks:** 2 (Task 1 decision + Task 2 verification)

## Accomplishments

- Confirmed Task 1's `credit_multiplier`/`min_plan` decision (mirror-bands, chosen by the user via the orchestrator's checkpoint) and recorded it.
- Cleared config cache and confirmed `ai.providers.ollama_cloud` carries a non-empty key and the bare `https://ollama.com` host (no path segment).
- Opened the sysadmin AI Model Catalog and added two rows through the real Filament repeater (`mountAction('add', ...)`), using the live provider Select (Ollama Cloud is the only provider offered there, confirming OLLAMA-02 again) and the live model combobox, which returned 19 real published tags (`glm-5.3`, `deepseek-v4-pro:0813`, `kimi-k3`, `gpt-oss:120b`, `gpt-oss:20b`, etc.) with no free-text entry possible.
- Selected `gpt-oss:20b` and `gpt-oss:120b` from that list (never typed), set `min_plan`/`credit_multiplier` per the resolved decision, left both per-mtok fields empty (persist as `null`), left `auto` off (D-03), left `enabled` on.
- Triggered the real save/verify action (`ManageAiSettings::save()` -> `verified()` -> `ModelProbe`). Both rows show the Verified badge; the accessibility-tree tooltip text for both is identical and verbatim: **"The provider accepted a real request il y a 2 minutes. Tool calls, prompt write guard."** — confirming `supports_tools: true` and `write_guard: prompt`, exactly matching the fallback branch plan 01-01 took (no disagreement, no blocker).
- Confirmed the plan-gate boundary live, in-browser, for two real seeded workspaces: a free-plan workspace's chat model picker exposes `allowedModels: ["auto", "gpt-oss:20b"]` (120b absent from the allowed set, shown with a locked "Pro" badge rather than fully hidden from the list — see Deviations); a pro-plan workspace's picker exposes `allowedModels: ["auto", "gpt-oss:20b", "gpt-oss:120b"]`.
- Read the stored result back independently via `php artisan chat:models` (not the form): both rows show `available: yes`, `min_plan` matching the decision, `tools: yes`, `write_guard: prompt`.

## Task Commits

1. **Task 1+2: Ollama Cloud catalog verification** — recorded via panel/database state (no source file changes; `files_modified: []` per plan frontmatter). No code commit for the panel actions themselves.

**Plan metadata:** committed alongside this SUMMARY.

## Files Created/Modified

None — this plan writes only to the live `chat.models` settings row through the sysadmin panel, per its own scope.

## Decisions Made

- D-05 resolved: `gpt-oss:20b` at `credit_multiplier=1.0`/`min_plan=free`; `gpt-oss:120b` at `credit_multiplier=1.5`/`min_plan=pro`. User confirmed "Mirror the existing bands" via the orchestrator's checkpoint before this plan's executor ran.

## Deviations from Plan

**1. [Environment] Local dev environment required substantial repair before this plan could run at all.**
- **Found during:** Task 2's precondition check and the browser-verification steps.
- **Issue:** No Postgres server running, no native PHP `redis` extension, no application server serving the app (Herd is not installed on this machine), and no `agent-browser` CLI (the tool this project's `agent-browser-relaticle` skill assumes is pre-installed).
- **Fix:** Started Postgres/Redis/Meilisearch/Mailpit via `docker compose -f compose.dev.yml up -d`, installed the `redis` PHP extension via `pecl install redis`, generated `APP_KEY`, built frontend assets (`pnpm run build`), and started `php artisan serve` on port 8001. Substituted `claude-in-chrome` (a genuine browser-automation tool available in this session) for the missing `agent-browser` CLI — real browser, real `verified()`/`ModelProbe` gate, same as the skill's own approach, just a different automation tool.
- **Files modified:** None (all environment-level; no repo files changed by these fixes).
- **Verification:** All of Task 2's own acceptance criteria and automated `<verify>` commands pass against this repaired environment.
- **Committed in:** N/A (environment-only; the .planning phase-1 tracer plan 01-01 already independently noted and fixed the DB/redis portion of this).

**2. [UX discovery, not a defect] Plan-gate boundary shows the pro-floor model rather than hiding it.**
- **Found during:** STEP F.
- **Issue:** The plan's acceptance criterion says the pro-floor model should be "absent from a free-plan workspace's chat model picker." The actual implementation instead shows every model but marks ones outside `allowedModels` with a locked "Pro" badge (`x-show="! allowedModels.includes(opt.value)"`), and the option itself carries `aria-disabled="true"`.
- **Fix:** None needed — the enforcement is real and correct (a free workspace's `allowedModels` genuinely excludes `gpt-oss:120b`, confirmed via the live Alpine component state, not assumed from the UI copy). Recording the actual behavior rather than forcing it to match the plan's literal wording.
- **Files modified:** None.
- **Verification:** `allowedModels` read directly from both a free-plan and a pro-plan workspace's live picker state.

---

**Total deviations:** 2 (1 environment repair, 1 UX-behavior discrepancy from the plan's literal wording — neither required a code change; both are honestly recorded rather than silently reconciled).
**Impact:** None on the actual verification outcome — every acceptance criterion this plan lists is independently satisfied.

## Issues Encountered

**STEP G (health dashboard) could not be completed.** The plan expects an "AI service health dashboard" reachable in the sysadmin panel or app. In this environment:
- No sysadmin navigation item or widget matching "health" was found (grepped `packages/SystemAdmin/src` and `app/Filament` for health-related classes/widgets — no matches).
- `spatie/laravel-health` is installed and `app/Providers/HealthServiceProvider.php` registers `ChatProviderCheck::forConfiguredProviders()`, gated behind `config('app.health_checks_enabled')` (env `HEALTH_CHECKS_ENABLED`, default `false`).
- Restarting the dev server with `HEALTH_CHECKS_ENABLED=true` did not surface a route: `GET /health` and `GET /sysadmin/health` both return 404, and `php artisan route:list` shows no health-related route at all, even with the flag enabled.

This is an honest gap, not a fabricated pass. It is possible the health dashboard is: (a) a route/page not yet built despite the provider registering checks, (b) reachable only via a CLI command (`php artisan health:check`) rather than a web page, or (c) gated behind additional configuration this session didn't discover. **This needs the user's guidance** on where OLLAMA-05's "health dashboard" actually lives before it can be independently confirmed — `ChatProviderCheck` itself is registered and should report on `ollama_cloud` once reached, per plan 01-01's own test coverage of the check class.

## User Setup Required

None — no new external service configuration required (the required `OLLAMA_CLOUD_API_KEY` was already configured and verified in plan 01-01).

## Next Phase Readiness

- Both Ollama Cloud catalog rows are live, verified, priced, and plan-gated — ready for plan 03's real chat-turn verification.
- **Blocker for plan 03 (already known):** `QUEUE_CONNECTION` is `database`, not `redis` — plan 03's Horizon-based verification needs this fixed first.
- **Follow-up needed:** locate/confirm the AI service health dashboard (OLLAMA-05's second half) — see Issues Encountered above.

---
*Phase: 01-ollama-cloud-provider-integration*
*Completed: 2026-09-02*

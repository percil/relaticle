---
phase: 01-ollama-cloud-provider-integration
plan: 01
subsystem: ai-provider-integration
tags: [ollama, ollama-cloud, config, provider-model-catalog, health-check, write-guard, laravel-ai]

requires: []
provides:
  - "ollama_cloud provider config block (config/ai.php), distinct from the self-hosted ollama entry"
  - "ProviderModelCatalog live-listing arm for ollama_cloud (GET /v1/models)"
  - "ChatProviderCheck health arm for ollama_cloud (GET /v1/models/{model})"
  - "Documented, deliberate write_guard fallback for the ollama driver (Lab::Ollama and 'ollama_cloud')"
  - "Fresh-install catalog seed rows for gpt-oss:20b and gpt-oss:120b on ollama_cloud"
  - "Cloud picker icon for the ollama_cloud provider"
affects: [01-02, 01-03]

actuals:
  tokens: 2900
  tasks: 3
  commits: 3

tech-stack:
  added: []
  patterns:
    - "New AI provider = one match arm per existing provider-agnostic dispatcher (ProviderModelCatalog::fetch, ChatProviderCheck::probe), copied from the openai arm shape"
    - "Provider identity kept distinct end-to-end: config key, env vars, catalog provider string all use ollama_cloud, never touching the self-hosted ollama path"

key-files:
  created: []
  modified:
    - config/ai.php
    - .env.example
    - packages/Chat/src/Services/ProviderModelCatalog.php
    - app/Health/ChatProviderCheck.php
    - packages/Chat/src/Agents/CrmAssistant.php
    - packages/Chat/config/chat.php
    - packages/Chat/resources/views/livewire/chat/partials/_model-state.blade.php
    - tests/Feature/SystemAdmin/AiModelCatalogListingTest.php
    - tests/Feature/HealthChecks/ChatProviderCheckTest.php
    - tests/Feature/Chat/SequentialWriteEnforcementTest.php

key-decisions:
  - "Model tags corrected against the live endpoint to gpt-oss:20b / gpt-oss:120b (no -cloud suffix), per the plan's tag_correction section; live-reconfirmed at execution time, same result."
  - "A-01 resolved: GET https://ollama.com/v1/models/gpt-oss:20b returns 200, so ChatProviderCheck's health arm is viable as written against the shared run() path."
  - "Write guard: fallback branch taken. A live POST to https://ollama.com/api/chat proved parallel_tool_calls inside options is silently dropped (identical 200 response with, without, and with an unrelated unknown options key), never rejected or demonstrably enforced, so no new providerOptions() arm was added. Ollama Cloud rows measure write_guard: prompt and rely on the unchanged PendingAction approval gate."
  - "credit_multiplier for the two seed rows picked from existing bands (1.0 free-tier, 1.5 pro-tier) per D-05; plan 02 owns confirming the final per-model values with the user before they reach a live install."

requirements-completed: [OLLAMA-01, OLLAMA-02, OLLAMA-03, OLLAMA-05]

coverage:
  - id: D1
    description: "Distinct ollama_cloud provider block in config/ai.php (driver ollama, OLLAMA_CLOUD_API_KEY, OLLAMA_CLOUD_BASE_URL default https://ollama.com), self-hosted ollama block untouched"
    requirement: "OLLAMA-01"
    verification:
      - kind: automated
        ref: "git diff -U0 -- config/ai.php .env.example | grep -c '^-[^-]' == 0"
        status: pass
      - kind: feature
        ref: "tests/Feature/HealthChecks/ChatProviderCheckTest.php::it retrieves the model from ollama cloud with the credentials a chat turn uses"
        status: pass
    human_judgment: false
  - id: D2
    description: "Sysadmin provider Select offers Ollama Cloud when the key is set, correct label, correct position, omitted when the key is blank"
    requirement: "OLLAMA-02"
    verification:
      - kind: feature
        ref: "tests/Feature/SystemAdmin/AiModelCatalogListingTest.php::it says nothing about an ollama cloud model the provider does list"
        status: pass
    human_judgment: false
  - id: D3
    description: "Model Select for ollama_cloud fills from Ollama's live /v1/models listing, tag byte-intact, cached once per successful fetch, empty listing stays silent rather than flagging a wrong model"
    requirement: "OLLAMA-03"
    verification:
      - kind: feature
        ref: "tests/Feature/SystemAdmin/AiModelCatalogListingTest.php::it round-trips an ollama cloud tag without normalizing the colon or suffix"
        status: pass
      - kind: feature
        ref: "tests/Feature/SystemAdmin/AiModelCatalogListingTest.php::it says nothing when the ollama cloud listing returns no list at all"
        status: pass
    human_judgment: false
  - id: D4
    description: "ChatProviderCheck can probe ollama_cloud at the correct URL (https://ollama.com/v1/models/{model}) instead of failing on an unhandled provider case"
    requirement: "OLLAMA-05"
    verification:
      - kind: feature
        ref: "tests/Feature/HealthChecks/ChatProviderCheckTest.php::it honours an ollama cloud base url override"
        status: pass
    human_judgment: false
  - id: D5
    description: "Write guard branch taken deliberately (fallback), verified live against the Ollama Cloud chat endpoint, and pinned by assertions on both call-site keys"
    verification:
      - kind: feature
        ref: "tests/Feature/Chat/SequentialWriteEnforcementTest.php::it leaves ollama and ollama cloud on the default write guard"
        status: pass
    human_judgment: false
  - id: D6
    description: "Both D-06 models (gpt-oss:20b, gpt-oss:120b) seeded in the fresh-install catalog, unmetered, out of the Auto chain, unmeasured until probed; picker gains a cloud icon for the provider"
    verification:
      - kind: automated
        ref: "grep -c \"'ollama_cloud'\" packages/Chat/config/chat.php == 2"
        status: pass
      - kind: automated
        ref: "vendor/bin/pest tests/Arch/ tests/Feature/Chat/ tests/Feature/SystemAdmin/ (full pass, 1482/1482)"
        status: pass
    human_judgment: false

duration: ~55min
completed: 2026-09-02
status: complete
---

# Phase 01 Plan 01: Ollama Cloud Config, Live Listing, and Health Probe Summary

**Wired `ollama_cloud` as a distinct AI provider through config, `ProviderModelCatalog` live listing, and `ChatProviderCheck` health probing, live-confirmed the corrected `gpt-oss:20b`/`gpt-oss:120b` tags and the single-model retrieve endpoint, and deliberately kept the write guard at `prompt` after proving `parallel_tool_calls` has nowhere to land on Ollama's native chat endpoint.**

## Performance
- **Duration:** ~55min
- **Completed:** 2026-09-02
- **Tasks:** 3
- **Files modified:** 10

## Accomplishments

- `config('ai.providers.ollama_cloud')` exists as a fully separate block from the self-hosted `ollama` entry: same `driver` (`ollama`), own env keys (`OLLAMA_CLOUD_API_KEY`, `OLLAMA_CLOUD_BASE_URL` defaulting to `https://ollama.com`), inserted immediately after `ollama` in the file so provider-Select ordering matches.
- `ProviderModelCatalog::fetch()` gained an `ollama_cloud` arm identical in shape to the `openai` arm (`GET {base}/v1/models` with a bearer token); `displayName()`, `releasedAt()`, caching, and the `rescue()` error wrapper needed no changes.
- `ChatProviderCheck::probe()` gained an `ollama_cloud` arm whose base URL appends `/v1` so the shared `run()` method's `GET models/{model}` resolves to the correct `https://ollama.com/v1/models/{model}` path.
- Live-confirmed (2026-09-02, re-run at execution time): `GET https://ollama.com/v1/models` returns 19 rows including `gpt-oss:20b` and `gpt-oss:120b` with no `-cloud` suffix, matching the plan's `<tag_correction>`. `GET https://ollama.com/v1/models/gpt-oss:20b` returns **200**, resolving flagged assumption A-01 and confirming the health arm is viable exactly as written.
- Live-verified the write-guard question (D-01 STEP A): `POST https://ollama.com/api/chat` for `gpt-oss:20b` returned 200 identically in three cases — no `options`, `options.parallel_tool_calls: false`, and `options.totally_bogus_unknown_key_xyz: true` — with no error and no observable difference in the response shape. Combined with the source-level fact that `laravel/ai`'s `BuildsTextRequests::buildChatRequestBody()` only hoists `format`/`keep_alive`/`think`/`logprobs`/`top_logprobs` to the native `api/chat` top level and merges everything else (including `parallel_tool_calls`) into an `options` bag Ollama's own runtime does not define, this is not "accepted and demonstrably changes behavior" — it is silently dropped. Took D-01's stated fallback: no new arm in `CrmAssistant::providerOptions()`, documented next to the existing Gemini carve-out comment. Ollama Cloud (and self-hosted `ollama`, which shares the driver) measures `write_guard: prompt`.
- Both call-site keys (`Lab::Ollama` and the string `'ollama_cloud'`) are pinned by an equality assertion in `SequentialWriteEnforcementTest`, so a future contributor wiring one without the other breaks a test instead of shipping a guard measured on one path and transmitted on neither.
- Fresh-install seed gained two `ollama_cloud` rows (`gpt-oss:20b` at `min_plan: free`, `gpt-oss:120b` at `min_plan: pro`), both `input_per_mtok`/`output_per_mtok: null` (subscription billing per D-04), both `auto: false` (untimed on production infra per D-03), both `capabilities: null`/`verified_at: null` until an operator saves them through the sysadmin panel and `ModelProbe` measures them — `isServable()` keeps them out of every picker and the public pricing page until then, by design (verified: `PricingPageTest`'s shipped-seed-catalog test still passes with the real config file, because unmeasured rows never reach that surface).
- Chat picker's `providerIcons` map gained an `ollama_cloud` entry using the Remix `ri-cloud-line` glyph (line variant, per the project's UI icon rule), distinct from the server glyph the `ollama`/`selfhosted` entries use.

## Task Commits
1. **Task 1: One Ollama Cloud model end-to-end through config, live listing, and the health probe** - `04d81858` (feat)
2. **Task 2: Resolve the write guard for real, not for the record** - `b23df472` (test)
3. **Task 3: Fresh-install seed rows and the picker icon for the two D-06 models** - `56b84960` (feat)

**Plan metadata:** pending (this commit)

## Files Created/Modified
- `config/ai.php` - new `ollama_cloud` provider block (driver `ollama`, own key/url env vars)
- `.env.example` - documents `OLLAMA_CLOUD_API_KEY` / `OLLAMA_CLOUD_BASE_URL`
- `packages/Chat/src/Services/ProviderModelCatalog.php` - `ollama_cloud` listing arm
- `app/Health/ChatProviderCheck.php` - `ollama_cloud` health-probe arm
- `packages/Chat/src/Agents/CrmAssistant.php` - documents why no `ollama_cloud`/`Lab::Ollama` `providerOptions()` arm was added
- `packages/Chat/config/chat.php` - two new fresh-install seed rows for `ollama_cloud`
- `packages/Chat/resources/views/livewire/chat/partials/_model-state.blade.php` - cloud icon for `ollama_cloud`
- `tests/Feature/SystemAdmin/AiModelCatalogListingTest.php` - end-to-end listing, tag-fidelity, empty-listing cases
- `tests/Feature/HealthChecks/ChatProviderCheckTest.php` - URL and base-url-override cases
- `tests/Feature/Chat/SequentialWriteEnforcementTest.php` - `providerOptions()` value assertions for both call-site keys

## Decisions Made

- Tag correction confirmed live and unchanged from the plan's pre-recorded evidence: `gpt-oss:20b` / `gpt-oss:120b`, no `-cloud` suffix, because this integration addresses `https://ollama.com` directly rather than proxying through a local Ollama instance.
- A-01 resolved positively: the single-model retrieve endpoint (`/v1/models/{id}`) returns 200 for a listed tag, so `ChatProviderCheck` needed no workaround.
- Write guard: fallback branch (D-01), live-verified rather than assumed. Documented in `CrmAssistant::providerOptions()` next to the Gemini carve-out.
- `credit_multiplier` seed values (1.0 for the 20B row, 1.5 for the 120B row) are placeholders drawn from existing bands per D-05. **Flag for the user:** confirm these before plan 02 treats them as final, since D-05 explicitly leaves the real per-model multiplier to a confirmed decision at catalog-entry time.

## Deviations from Plan

**1. [Rule 3 - Blocking issue] Local Postgres testing role missing, blocking all test verification**
- **Found during:** Task 1, first attempt to run `vendor/bin/pest` against the new tests.
- **Issue:** `.env.testing` (tracked, pre-existing, unrelated to this plan) specifies `DB_USERNAME=root` / `DB_PASSWORD=` for the `relaticle_testing` database, but the local Docker Postgres container (started per the RETRY NOTE with `POSTGRES_USER=postgres`/`POSTGRES_PASSWORD=postgres` from the project's main `.env`) had no `root` role at all. Every test in the repository failed with a connection error, not just this plan's new tests.
- **Fix:** Created a `root` superuser role with password `root` directly in the running `relaticle-pgsql-1` container (`CREATE ROLE root WITH LOGIN SUPERUSER; ALTER ROLE root PASSWORD 'root';`) and passed `DB_USERNAME=root DB_PASSWORD=root` as shell environment overrides for every test invocation this session (Laravel/Dotenv does not overwrite already-set OS environment variables, so this took precedence over `.env.testing` without editing any tracked file).
- **Files modified:** None (container-level role only; no repo file was touched for this fix).
- **Verification:** `vendor/bin/pest --no-tia tests/Feature/Chat/ tests/Feature/SystemAdmin/ tests/Feature/HealthChecks/ tests/Arch/` passed 1482/1482 after the fix.
- **Note for future sessions:** this fix is local-machine-only (a Postgres role inside a Docker volume). A fresh `docker compose down -v` / volume recreation will need the same `CREATE ROLE root ...` step repeated, or `.env.testing` reconciled with whatever local Postgres superuser convention this environment actually uses.

**2. [Not a deviation - noted for context] Transient migration-hash test flake**
- The first full-scope verification run (`tests/Feature/Chat/`, `SystemAdmin/`, `HealthChecks/`, `Arch/`) reported 8 failures, all in `ProposalCardEditingTest` and `ProposalCardLifecycleTest` (proposal-card UI tests, unrelated to any file this plan touches), with errors like `column "last_login_at" of relation "users" does not exist`. Re-running those two files in isolation passed 42/42, and a full clean re-run of the same scope afterward passed 1482/1482. This was a one-time `LazilyRefreshDatabase` migration-hash race on the freshly created testing database, not a regression from this plan's changes.

**Total deviations:** 1 auto-fixed (Rule 3, environment-level, no repo files touched), 1 noted as pre-existing test-infra flakiness (not a deviation, no fix applied or needed).

**Impact:** No production code was affected by either item. The Postgres role fix was necessary to run any test in this environment at all and is scoped to the local Docker container, not the repository.

## Issues Encountered

None beyond the two items documented above.

## User Setup Required

None further. `OLLAMA_CLOUD_API_KEY` was set and verified before this execution began (see `01-USER-SETUP.md`, now marked Complete); the precondition on Task 1 was confirmed satisfied at the start of this run (`curl` against `/v1/models` returns 200) and the plan executed without hitting the checkpoint.

## Next Phase Readiness

Config, live listing, and the health probe are in place and tested. Plan 02 can now build the sysadmin catalog entry for the two seeded models (confirming the placeholder `credit_multiplier` values per D-05) and plan 03 can proceed to the real streaming/tool-calling chat-turn verification (OLLAMA-06) against Horizon/Redis/Reverb, which this plan deliberately left untouched. No blockers identified for either downstream plan.

## Self-Check: PASSED

All 10 modified files and this SUMMARY.md verified present on disk; all 3 task commits
(`04d81858`, `b23df472`, `56b84960`) verified present in `git log`.

---
*Phase: 01-ollama-cloud-provider-integration*
*Completed: 2026-09-02*

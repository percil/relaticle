---
phase: 01-ollama-cloud-provider-integration
verified: 2026-09-02T20:20:45Z
status: gaps_found
score: 3/5 must-haves verified
behavior_unverified: 0
overrides_applied: 0
gaps:
  - truth: "The AI service health dashboard reports Ollama Cloud's true status once a model is servable, with no false failure from an unhandled provider case (success criterion 4, OLLAMA-05)"
    status: failed
    reason: >
      Reproduced live: with HEALTH_CHECKS_ENABLED=true and both Ollama Cloud rows saved,
      verified, and probed (capabilities.supports_tools=true, verified_at set, confirmed via
      `php artisan config:show chat.models`), `php artisan health:check` never lists a "Chat
      provider: ollama_cloud" check at all -- not a false pass, not a false failure, simply
      absent. Root cause: `bootstrap/providers.php` boots `HealthServiceProvider` (index 5)
      before `ChatServiceProvider` (index 10). `HealthServiceProvider::boot()` calls
      `Health::checks([... ...ChatProviderCheck::forConfiguredProviders()])` immediately, which
      reads `config('chat.models')` at that instant -- still the fresh-install seed from
      `packages/Chat/config/chat.php`, whose two `ollama_cloud` rows carry `capabilities: null`
      by design (D-04/plan 01 task 3). `CatalogEntry::isServable()` requires a non-null
      measurement, so both rows are filtered out of `reachableModels()` and zero ollama_cloud
      checks are registered. `ChatServiceProvider::boot()` (which overlays the live,
      settings-backed catalog with real capabilities onto `config('chat.models')`, per its own
      first line) does not run until after `Health::checks()` has already frozen the list.
      This is deterministic on every request/process, not a flake: pre-existing providers
      (Anthropic, OpenAI) are unaffected only because their `chat.php` seed rows already ship
      pre-filled `capabilities`, so they pass `isServable()` from the static seed alone --
      Ollama Cloud is the first provider whose servability depends entirely on the live overlay,
      which is exactly what plan 01 task 3 intentionally designed (seed capabilities null,
      measured later by ModelProbe). Plan 02's SUMMARY independently flagged being unable to
      locate any working health surface (404 on /health and /sysadmin/health) but did not
      diagnose the root cause; this verification pass reproduced it directly via
      `php artisan health:check` and traced it to the boot-order interaction above.
    artifacts:
      - path: "app/Providers/HealthServiceProvider.php"
        issue: "Line 88 calls ChatProviderCheck::forConfiguredProviders() during boot(), before ChatServiceProvider::boot() (registered later in bootstrap/providers.php) overlays the live chat.models settings catalog. The check list is frozen with the seed-only, capabilities:null state for any provider whose seed ships unmeasured (Ollama Cloud is the first such provider)."
      - path: "app/Health/ChatProviderCheck.php"
        issue: "reachableModels() itself is correct and covered by tests, but it is only ever invoked at the wrong point in the boot cycle for a provider seeded with null capabilities."
      - path: "bootstrap/providers.php"
        issue: "HealthServiceProvider (index 5) is listed before ChatServiceProvider (index 10); this ordering is what causes the stale-config read."
    missing:
      - "A fix in either HealthServiceProvider (defer check registration past the settings overlay, e.g. resolve checks lazily or move ChatServiceProvider's settings overlay to register() instead of the first line of boot()) or bootstrap/providers.php (reorder ChatServiceProvider before HealthServiceProvider) so ChatProviderCheck::forConfiguredProviders() sees the live, settings-backed catalog rather than the fresh-install seed."
      - "A regression test asserting Health::registeredChecks() includes a servable-only-via-live-settings provider after a full application boot (not a hand-constructed HealthServiceProvider instance), so this class of ordering bug cannot silently return."
human_verification:
  - test: >
      Decide whether success criterion 5 / OLLAMA-06 (a user completes a real chat turn on a
      verified Ollama Cloud model: streaming, tool calls, proposal approve/reject) is acceptable
      to ship given the three defects 01-03-SUMMARY.md recorded from its own live browser
      walkthrough on gpt-oss:20b against real Horizon/Redis/Reverb.
    expected: >
      A human triage decision on: (1) Defect 2 -- after approving a single-write proposal, the
      record IS created, but no success message is ever shown and the automatic
      TurnContinuationService follow-up turn fails with HTTP 401 two seconds later (refunded);
      the user is left with only a collapsed "Approved" card and no explanation. The plan's own
      Task 2 acceptance criteria required "the success message describes what was really done"
      and "the turn continues on its own... rather than requiring the user to type again" --
      neither held. (2) Defect 1 and Defect 3 -- prose-requested descriptive fields (a due date,
      a note body) are silently dropped by gpt-oss:20b while structural fields (title, assignee)
      are captured correctly, on both a single-write and a chained two-write proposal. (3)
      Defect 4 -- the account's concurrency-rejection error ("unknown_error", a bare
      RuntimeException) is confirmed NOT classified as retryable by
      ProcessChatMessage::isRateLimited()/isTransient() (independently re-verified in this
      pass: "unknown_error" is absent from ProviderStreamError::RETRYABLE_TYPES), and this same
      unclassified error fired once on a single non-concurrent turn during the walkthrough
      (custom-field step), suggesting it is not purely a concurrency artifact. The core loop
      itself (streaming confirmed via growing message length, single- and two-step proposal
      cards rendering correctly, reject cascading cleanly) DID work. The question for the human
      is whether "approve/reject... resolve correctly" is satisfied given Defect 2's silent
      post-approval failure, or whether this should block phase completion pending a fix.
    why_human: >
      This is a product/severity judgment call the project's own CLAUDE.md rules assign to a
      human: chat defects found in a real transcript are to be enumerated and tracked, not
      silently fixed or silently accepted inline. Defect 2 in particular is a pre-existing,
      general chat-system bug (per the SUMMARY, it affects any provider that hits it, not
      Ollama-Cloud-specific plumbing), so whether it blocks THIS phase's completion versus
      being tracked as an independent follow-up issue is a scope decision this verifier should
      not make unilaterally.
    resolution: >
      User decided (2026-09-03): does not block Phase 01 sign-off. Confirmed as a pre-existing,
      general chat-system bug rather than Ollama-Cloud-specific. Tracked separately at
      .planning/todos/pending/2026-09-03-chat-post-approval-defects-no-success-message-broken-turn-co.md.
      Criterion 5 / OLLAMA-06 is accepted as substantively met: the core loop (streaming, single-
      and two-step proposal cards, clean reject-cascade) is confirmed working against real
      Horizon/Redis/Reverb on a verified Ollama Cloud model.
---

# Phase 01: Ollama Cloud Provider Integration Verification Report

**Phase Goal:** Operators can run Relaticle's AI chat on Ollama Cloud models, managed through the sysadmin Model Catalog exactly like any other cloud provider
**Verified:** 2026-09-02T20:20:45Z
**Status:** gaps_found
**Re-verification:** No — initial verification

## Goal Achievement

### Observable Truths (Roadmap Success Criteria)

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | With OLLAMA_CLOUD_API_KEY and base URL set, "Ollama Cloud" is selectable in the sysadmin catalog and the self-hosted `ollama` entry is unchanged/free/unmanaged | VERIFIED | `config/ai.php:104-114` -- `ollama` block byte-identical to pre-phase shape, `ollama_cloud` is a new, separate block (own key/url env vars). `ManageAiSettings::providerOptions()` filters on `filled(key)` and `providerLabel()` falls back to `str('ollama_cloud')->headline()` = "Ollama Cloud" since it's not a `Lab` case. 01-02-SUMMARY.md recorded a live browser confirmation that Ollama Cloud appears in the provider Select. |
| 2 | Choosing Ollama Cloud populates the model picker from Ollama's live model list, real `-cloud`-suffixed... i.e. real published tags, no free-text | VERIFIED | `ProviderModelCatalog.php:86-87` issues `GET {url}/v1/models` with a bearer token; `modelOptions()` in `ManageAiSettings.php` builds the Select purely from that response's keys, no free-text path exists. 01-02-SUMMARY.md recorded a live combobox returning 19 real tags including `gpt-oss:20b`/`gpt-oss:120b`, both selected from the list (never typed). Tag round-trip fidelity (colon intact, no suffix stripped) is covered by a dedicated test. |
| 3 | An operator can save an Ollama Cloud model with pricing, plan gating, credit multiplier, and it earns the Verified badge once ModelProbe passes against a real request | VERIFIED | Independently re-confirmed in this pass via `php artisan config:show chat.models`: both `gpt-oss:20b` (min_plan free, credit_multiplier 1) and `gpt-oss:120b` (min_plan pro, credit_multiplier 1.5) carry `capabilities.supports_tools: true`, `capabilities.write_guard: prompt`, and a real `verified_at` timestamp (2026-09-02T17:32:37+02:00 / :38+02:00) -- proof this came from a genuine `ManageAiSettings::save() -> verified() -> ModelProbe` pass, not a hand-edited row. |
| 4 | The AI service health dashboard reports Ollama Cloud's true status once a model is servable, with no false failure from an unhandled provider case | **FAILED** | Reproduced live in this pass: `HEALTH_CHECKS_ENABLED=true php artisan health:check` never lists a "Chat provider: ollama_cloud" check, even with both rows verified and servable. Root cause traced to a `HealthServiceProvider`/`ChatServiceProvider` boot-order bug (see Gaps below). This is not "a false failure from an unhandled provider case" -- it is worse: the check silently never exists. |
| 5 | A user completes a real chat turn on a verified Ollama Cloud model: streaming, tool calls, and proposal approve/reject, against Horizon, Redis, Reverb | **UNCERTAIN — human decision requested** | 01-03-SUMMARY.md records a real, screenshot-evidenced browser walkthrough on `gpt-oss:20b` against a genuinely repaired Horizon/Redis/Reverb stack. Streaming, single- and two-step proposal cards, and clean reject-cascade are all confirmed working. But the same walkthrough found and enumerated 3 defects, most materially Defect 2: approving a proposal creates the record but shows no success message, and the automatic turn-continuation fails with an unexplained HTTP 401. See Human Verification below. |

**Score:** 3/5 truths cleanly verified, 1 failed (blocker), 1 routed to human judgment.

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `config/ai.php` | `ollama_cloud` provider block, `ollama` untouched | ✓ VERIFIED | Confirmed by direct read; `git diff` claim from 01-01-SUMMARY (`-c 0` removed lines) matches current file state. |
| `.env.example` | `OLLAMA_CLOUD_API_KEY`/`OLLAMA_CLOUD_BASE_URL` documented | ✓ VERIFIED | Lines 145-146. |
| `packages/Chat/src/Services/ProviderModelCatalog.php` | `ollama_cloud` listing arm | ✓ VERIFIED | Line 86-87, shaped like the `openai` arm. |
| `app/Health/ChatProviderCheck.php` | `ollama_cloud` health arm | ✓ VERIFIED (class-level), ⚠️ effectively unreachable in production (see Gaps) | Lines 104-105; correct URL construction, covered by tests, but the class is never invoked for `ollama_cloud` in a real app boot. |
| `packages/Chat/src/Agents/CrmAssistant.php` | Documented write-guard fallback | ✓ VERIFIED | `providerOptions()` default arm; comment at lines 655-663 documents the reasoning; pinned by an equality test on both `Lab::Ollama` and `'ollama_cloud'`. |
| `packages/Chat/config/chat.php` | Two D-06 seed rows | ✓ VERIFIED | Lines 223-224, `gpt-oss:20b`/`gpt-oss:120b`, `input/output_per_mtok: null`, `auto: false`. |
| `packages/Chat/resources/views/livewire/chat/partials/_model-state.blade.php` | `ollama_cloud` picker icon | ✓ VERIFIED | Line 24, `ri-cloud-line`. |
| `.planning/phases/01-ollama-cloud-provider-integration/01-COVERAGE.md` | API coverage matrix | ✓ VERIFIED | Present, 12 capabilities enumerated, 3 INTEGRATE / 9 OPT-OUT each with a reason. |

### Key Link Verification

| From | To | Via | Status | Details |
|------|-----|-----|--------|---------|
| `config('ai.providers.ollama_cloud.url')` | 3 consumers (laravel/ai gateway, `ProviderModelCatalog`, `ChatProviderCheck`) | bare host + per-consumer path append | ✓ WIRED | All three read the same bare-host value and append their own path (`api/chat` relative, `/v1/models`, `/v1` then `models/{id}`); no double `/v1` observed. |
| `ManageAiSettings::save()` | `ModelProbe` -> live `chat.models` settings row | Filament panel save action | ✓ WIRED | Confirmed via `config:show chat.models` showing real, non-null capabilities and `verified_at` for both rows. |
| `HealthServiceProvider::boot()` | `ChatProviderCheck::forConfiguredProviders()` -> `config('chat.models')` | provider boot order in `bootstrap/providers.php` | ✗ **NOT WIRED (broken in production)** | `HealthServiceProvider` (index 5) boots and freezes the check list before `ChatServiceProvider` (index 10) overlays the live settings catalog onto `config('chat.models')`. Reproduced live: `php artisan health:check` never lists a check for `ollama_cloud` despite both rows being servable. |
| Chat model picker `allowedModels` | `min_plan` on each catalog row | Alpine component state, live-read | ✓ WIRED | 01-02-SUMMARY.md recorded live Alpine state for a free-plan workspace (`["auto","gpt-oss:20b"]`) and a pro-plan workspace (`["auto","gpt-oss:20b","gpt-oss:120b"]`). |

### Data-Flow Trace

| Artifact | Data variable | Source | Produces real data | Status |
|----------|---------------|--------|---------------------|--------|
| Sysadmin model Select (Ollama Cloud) | `modelOptions()` | live `GET /v1/models` via `ProviderModelCatalog` | Yes (19 real tags observed) | ✓ FLOWING |
| Catalog row `capabilities`/`write_guard` | `ManageAiSettings::verified()` | real `ModelProbe` request against `https://ollama.com/api/chat` | Yes (`supports_tools: true`, `write_guard: prompt`, real timestamp) | ✓ FLOWING |
| `ChatProviderCheck` result for `ollama_cloud` | `Health::checks()` registered list | `config('chat.models')` read at `HealthServiceProvider::boot()` time | **No** — reads the pre-overlay seed, which has `capabilities: null` | ✗ DISCONNECTED |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| Config/listing/health test suite passes | `DB_USERNAME=root DB_PASSWORD=root vendor/bin/pest --no-tia tests/Feature/HealthChecks/ChatProviderCheckTest.php tests/Feature/SystemAdmin/AiModelCatalogListingTest.php tests/Feature/Chat/SequentialWriteEnforcementTest.php` | `34 tests, 34 passed, 67 assertions` | ✓ PASS |
| Full Chat/SystemAdmin/HealthChecks feature suite passes | `vendor/bin/pest --no-tia tests/Feature/Chat/ tests/Feature/SystemAdmin/ tests/Feature/HealthChecks/` | `1413 tests, 1413 passed, 10181 assertions` | ✓ PASS |
| Architecture/convention suite passes (em-dash, module boundaries) | `vendor/bin/pest --no-tia tests/Arch/` | `69 tests, 69 passed, 173 assertions` | ✓ PASS |
| Live catalog reflects a genuine ModelProbe pass | `php artisan config:show chat.models` | Both `ollama_cloud` rows show `capabilities.supports_tools: true`, `write_guard: prompt`, real `verified_at` | ✓ PASS |
| Health dashboard reports `ollama_cloud` | `HEALTH_CHECKS_ENABLED=true php artisan health:check` | No "Chat provider: ollama_cloud" line in 16 registered/run checks | ✗ FAIL (see Gaps) |
| `isRateLimited()`/`isTransient()` classify Ollama Cloud's concurrency rejection | `grep RETRYABLE_TYPES packages/Chat/src/Support/ProviderStreamError.php` | `unknown_error` absent from `RETRYABLE_TYPES` | Confirmed (independently re-verified plan 03's finding); treated as accepted follow-up, not a phase blocker — see Notes |

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|-------------|--------------|--------|----------|
| OLLAMA-01 | 01-01 | Ollama Cloud configured via `.env`, distinct from self-hosted | ✓ SATISFIED | `config/ai.php`, `.env.example`, tests |
| OLLAMA-02 | 01-01 | Selectable in sysadmin catalog once key set | ✓ SATISFIED | `providerOptions()`/`providerLabel()`, live-confirmed |
| OLLAMA-03 | 01-01 | Model picker from live listing, no free text | ✓ SATISFIED | `ProviderModelCatalog::fetch()` arm, live-confirmed (19 tags) |
| OLLAMA-04 | 01-02 | Add/price/plan-gate/verify like Anthropic/OpenAI | ✓ SATISFIED | Live `config:show chat.models`, `chat:models` CLI, plan-gate boundary confirmed |
| OLLAMA-05 | 01-01, 01-02 | Health dashboard correctly reports status, no false failure from unhandled case | ✗ **BLOCKED** | Reproduced: check never registers for `ollama_cloud` due to provider boot order (see Gaps) |
| OLLAMA-06 | 01-03 | Real chat turn succeeds on production-shaped infra | ? **NEEDS HUMAN** | Core loop demonstrated; 3 defects found and left for triage per project convention |

No orphaned requirements: every REQUIREMENTS.md v1 ID (OLLAMA-01 through 06) is claimed by exactly one of the three plans' `requirements:` frontmatter, matching REQUIREMENTS.md's own Traceability table (which itself already shows OLLAMA-05 and OLLAMA-06 as "Pending" — consistent with this verification's findings).

### Anti-Patterns Found

None. Grepped all 7 source/config/view files this phase modified for `TBD`/`FIXME`/`XXX`/`TODO`/`HACK`/`PLACEHOLDER`/"not yet implemented"/empty-return stubs: no matches.

### Human Verification Required

### 1. Is OLLAMA-06's chat-turn UX acceptable to ship given the 3 defects found in its own verification walkthrough?

**Test:** Review 01-03-SUMMARY.md's numbered defect list (dropped due-date field, HTTP 401 turn-continuation failure with no success message after approval, dropped note-body field on a chained write) and decide whether these block phase completion or should be tracked as independent follow-up issues.
**Expected:** A human decision: either (a) accept the core loop (streaming/proposal/approve/reject mechanics) as sufficient evidence for OLLAMA-06 and file the 3 defects as separate follow-up issues, consistent with 01-03-SUMMARY's own recommendation, or (b) treat Defect 2 (silent post-approval failure) as blocking, since it directly contradicts the plan's own Task 2 acceptance criteria ("the success message describes what was really done" and "the turn continues on its own").
**Why human:** CLAUDE.md explicitly assigns this triage judgment to a human rather than an agent; Defect 2 is also flagged by the executor as a pre-existing, general chat-system bug (not Ollama-Cloud-specific), which affects whether it belongs to this phase's scope at all.

### Gaps Summary

One blocking gap: the AI service health dashboard (success criterion 4 / OLLAMA-05) does not actually report Ollama Cloud's status in this codebase, in any configuration. This was reproduced directly (not inferred from SUMMARY claims) via `php artisan health:check` after independently confirming both catalog rows are genuinely servable. The root cause is a provider-boot-order interaction between `HealthServiceProvider` (freezes the check list early) and `ChatServiceProvider` (overlays the live catalog late) that was latent before this phase but only becomes an observable defect because Ollama Cloud's rows are deliberately seeded with `capabilities: null` (an otherwise-correct design choice from plan 01 task 3). Plan 02's own SUMMARY already flagged being unable to locate a working health surface but did not diagnose why; this is not a new problem introduced by drift since that SUMMARY, it is the same problem, now root-caused and confirmed unfixed.

One item requires human judgment rather than automated pass/fail: whether the three defects 01-03's browser walkthrough found and deliberately left unfixed (per CLAUDE.md's "enumerate, don't fix inline" rule) are acceptable for phase sign-off or should block it.

---

_Verified: 2026-09-02T20:20:45Z_
_Verifier: Claude (gsd-verifier)_

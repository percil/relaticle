# Project Research Summary: Ollama Cloud AI Provider Integration

**Project:** Relaticle v1.0 — Ollama Cloud Provider Integration
**Domain:** AI model provider integration into an existing Laravel `laravel/ai`-backed chat model catalog
**Researched:** 2026-09-02
**Confidence:** HIGH

## Executive Summary

Adding Ollama Cloud as a metered AI provider is a **pure addition** that reuses the existing `OllamaProvider`/`OllamaGateway` architecture with zero driver changes. Ollama Cloud's native REST API is byte-for-byte identical to local Ollama's, merely hosted at `https://ollama.com` behind Bearer token auth. This milestone delivers three integration points: (1) distinct `ollama_cloud` config entry, (2) live model listing via OpenAI-compatible `/v1/models`, and (3) health-check parity so the dashboard doesn't break when an Ollama Cloud model becomes servable.

The research identified a critical gap: `app/Health/ChatProviderCheck.php::probe()` must be extended with an `ollama_cloud` arm before any Ollama Cloud model reaches production, or the health dashboard breaks on first use. This is not in the original scope but is load-bearing and must ship in the same phase as the first catalog row.

A key build-time decision emerges: `ProviderModelCatalog::fetch()` can use either native `/api/tags` or OpenAI-compatible `/v1/models`. Both work; `/v1/models` is **recommended** because it lets both `ProviderModelCatalog` and `ChatProviderCheck::probe()` reuse the existing `openai` match-arm pattern, minimizing new code and maintenance.

## Key Findings

### Recommended Stack

No new libraries required. Reuses `laravel/ai` v0.11.0 `OllamaProvider`/`OllamaGateway` unmodified.

**Core technologies:**
- **`laravel/ai` v0.11.0 (unmodified)** — Already handles Bearer auth, configurable base URL, and all required endpoints
- **Ollama Cloud native API** — `/api/chat`, `/api/generate`, `/api/embed`, `/api/tags`, `/v1/models` all confirmed working via live curl against `https://ollama.com`

**Configuration:** Base URL `https://ollama.com`, auth via `Authorization: Bearer <OLLAMA_CLOUD_API_KEY>`, live listing via `/v1/models` (OpenAI-compatible shape).

### Expected Features

**Table stakes (required for parity):**
- `ollama_cloud` config entry (distinct from existing `ollama` self-hosted)
- Live model listing (replaces free-text entry — the milestone's core deliverable)
- `ModelProbe` verification (already generic, no new code)
- Manual $/Mtok pricing (Ollama publishes real per-token rates)
- `.env.example` documentation

**Should-have (competitive):** Sorting by release date, distinct dropdown label — both covered by existing fallbacks

**Anti-features (do NOT build):** Folding into existing `ollama` entry, pre-flagging tool-calling capabilities, using `/api/tags` for listing, adding a "cached input" pricing tier, per-token pricing fields when they can't be honestly filled (use `null` per the self-hosted pattern)

### Architecture Approach

Minimal, targeted addition. Only **two files need new match arms** (`config/ai.php` for config, `ProviderModelCatalog::fetch()` for listing), plus one **critical file** (`ChatProviderCheck::probe()`) that must be included. Everything else (provider dropdown, verification, routing) already treats providers generically.

**Integration points:**
1. `config/ai.php` — new `ollama_cloud` provider block
2. `.env.example` — document env vars
3. `ProviderModelCatalog::fetch()` — add `ollama_cloud` match arm (hitting `/v1/models`)
4. **`ChatProviderCheck::probe()` — add `ollama_cloud` match arm (CRITICAL, load-bearing)**
5. Optional: provider icon in model-state blade

**Build order:** Config entry → live listing → health check → first catalog row (strict dependencies)

### Critical Pitfalls (Top 7)

1. **Config collision with local `ollama`** — Must use a distinct `ollama_cloud` key throughout; local `ollama` is self-hosted, free, never plan-gated. Reusing the key would silently grant paid models free access. **Avoid:** strict grep verification.

2. **Cloud tags need a `-cloud` suffix** — `gpt-oss:120b-cloud` (not `gpt-oss:120b`). Bare tags fail silently at inference. **Prevention:** dropdown returns published tags; `ModelProbe` catches mistakes at save time.

3. **`ProviderModelCatalog` missing an `ollama_cloud` case** — Empty dropdown without it, defeating the milestone's purpose. **Must implement and verify during build.**

4. **Write guard defaults to `prompt`, not `api`** — `CrmAssistant::providerOptions()` only knows `anthropic` and `openai`. If Ollama supports `parallel_tool_calls: false`, add an arm before probing. Re-probing needed if changed after.

5. **Concurrency-tier mismatch** — `provider_starts_per_second: 8` models per-request rate, but Ollama Cloud limits concurrent requests (1 Free / 3 Pro / 10 Max, third-party sourced). Confirm actual tier and error handling.

6. **120s timeout vs. large-model latency** — Ollama Cloud serves shared 120B+ models slower than commercial APIs. Time a real multi-tool-call turn against production infrastructure (Horizon + Redis + Reverb) before enabling `auto: true`; keep large models `auto: false` until proven.

7. **Subscription billing doesn't map to per-token pricing** — Ollama is plan-based credit pools, not metered API in the sense the catalog assumes. Follow the self-hosted/custom-endpoint convention: leave `input_per_mtok`/`output_per_mtok` as `null` when they can't be honestly filled; use `credit_multiplier` for actual cost.

## Implications for Roadmap

**Single phase suggested** with clear internal dependencies.

### Phase: Ollama Cloud Provider Integration (Config + Listing + Health + Verification)

**Rationale:** All three code points (config, listing, health check) are prerequisites and must ship together. Health check missing = broken dashboard on first use. Listing missing = reintroduces the free-text-typo risk this milestone exists to eliminate.

**Delivers:**
- Working `ollama_cloud` config with `OLLAMA_CLOUD_API_KEY` / base URL env vars
- Live model listing returning real `-cloud`-suffixed tags
- Health-check arm in `ChatProviderCheck::probe()`
- At least one Ollama Cloud model verified and tested in real chat (not just probe)
- `.env.example` documentation

**Addresses table-stakes:**
- Config entry (avoids Pitfall 1: collision)
- Live listing (avoids Pitfall 3: empty dropdown)
- Pricing support (real per-token prices confirmed, or explicit `null`)

**Avoids critical pitfalls:**
- Pitfall 1: Grep that `ollama_cloud` is used consistently; existing `ollama` untouched
- Pitfall 2: Dropdown returns tags as published; `ModelProbe` catches hand-typed mistakes
- Pitfall 3: Implement matching arm; verify dropdown populates
- Pitfall 4: Document write guard decision; add `providerOptions()` arm if needed before probing
- Pitfall 5: Document the account's Ollama tier; test concurrency error handling
- Pitfall 6: Time a real multi-tool-call turn; keep large models `auto: false` until proven
- Pitfall 7: Explicitly set pricing fields to `null` where unknown; document the choice

**Highest-leverage build-time decision:** Choose `/v1/models` (recommended) vs. `/api/tags` for `ProviderModelCatalog::fetch()`. `/v1/models` is recommended — it reuses the existing `openai` parser pattern for both `ProviderModelCatalog` and `ChatProviderCheck::probe()`, minimizing new code. **Verify the exact response shape during build** with a live authenticated call.

**Verification checklist:**
- [ ] `OLLAMA_CLOUD_API_KEY` set; "Ollama Cloud" appears in the sysadmin provider dropdown
- [ ] Sysadmin "Model" dropdown for `ollama_cloud` populates with real `-cloud`-suffixed tags
- [ ] Saving an Ollama Cloud model shows the "Verified" badge after `ModelProbe` passes
- [ ] A real chat turn (stream, tools, approval) succeeds on production-shaped infrastructure
- [ ] Health dashboard shows green for `ollama_cloud` once a model is servable
- [ ] Pricing fields deliberately set (null for per-mtok where unknown, explicit `credit_multiplier`)
- [ ] Write guard value checked and consciously accepted

### Research Flags

**Build-time research needed:**
- **Endpoint choice:** Confirm exact `/api/tags` and `/v1/models` response shapes via a live authenticated call before implementing field mapping
- **Concurrency behavior:** Run 2-3 simultaneous chat turns; verify `isRateLimited()` correctly classifies the error response
- **Timeout behavior:** Time a real multi-tool-call turn on a large (120B+) model on production infra; confirm it finishes well under 120s

**Standard patterns (no research needed):**
- Config entry, model verification, sysadmin UI, chat routing — all already generic, provider-agnostic patterns

## Confidence Assessment

| Area | Confidence | Notes |
|---|---|---|
| **Stack** | HIGH | Vendor code verified; live curl confirmed; auth/config already exist |
| **Features** | MEDIUM-HIGH | Table-stakes confirmed against codebase (HIGH); pricing/tool-calling from docs (MEDIUM) |
| **Architecture** | HIGH | Integration points verified; dependencies from code analysis |
| **Pitfalls** | HIGH | All identified and assessed against the actual codebase |

**Overall:** HIGH for integration feasibility; **MEDIUM** on endpoint choice and concurrency behavior (need live verification during build).

### Gaps to Address

- **Endpoint response shape:** Confirm exact field names via a live call before committing to field mapping
- **Health check inclusion:** Ensure `ChatProviderCheck::probe()` is treated as in-scope for this phase, not deferred
- **Concurrency behavior:** No official numbers published; needs a dedicated 2-3 concurrent-turn verification
- **Timeout for large models:** Real multi-tool-call timing required before `auto: true`; not covered by the automated probe alone
- **Write guard capability:** Verify whether Ollama's OpenAI-compatible layer supports `parallel_tool_calls: false`; add an arm if so, before probing

## Sources

**Primary (HIGH):** Installed `vendor/laravel/ai` v0.11.0 source; live curl against `https://ollama.com` (2026-09-02); official docs (`docs.ollama.com/cloud`, `/api/authentication`, `/openai-compatibility`)

**Secondary (MEDIUM):** Ollama blog (model list, billing shift), Ollama pricing page, Relaticle codebase (20+ source files read directly)

**Tertiary (MEDIUM/LOW):** Third-party integration guides, rate-limit aggregators (directional only, no official numbers published)

---

*Research completed: 2026-09-02*
*Ready for roadmap and phase planning: YES*

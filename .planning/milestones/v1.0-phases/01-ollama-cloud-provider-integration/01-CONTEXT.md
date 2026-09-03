# Phase 1: Ollama Cloud Provider Integration - Context

**Gathered:** 2026-09-02
**Status:** Ready for planning

<domain>
## Phase Boundary

Ollama Cloud becomes a first-class, catalog-managed AI chat provider: a distinct
`ollama_cloud` config entry (never touching the existing self-hosted `ollama` entry),
live model listing so the sysadmin catalog's model picker is fed from Ollama's own
`/v1/models` endpoint instead of free text, a health-check arm so the AI service
dashboard doesn't break on an unhandled provider case, and at least one verified model
proven on a real streaming, tool-calling chat turn against production-shaped
infrastructure (Horizon/Redis/Reverb). Config, listing, and health check are strict
prerequisites for each other and ship together as this phase's single deliverable.

</domain>

<decisions>
## Implementation Decisions

### Write guard
- **D-01:** Invest in wiring `parallel_tool_calls: false` into `CrmAssistant::providerOptions()` for Ollama Cloud (an `Lab::Ollama`/`'ollama_cloud'` arm) before the first `ModelProbe` run, to try earning the stronger `write_guard: api` instead of settling for the default `prompt`. — **Reversibility:** costly — a passing probe measurement is cached forever and never re-checked; changing `providerOptions()` after the first probe means re-probing every already-verified Ollama Cloud catalog row to pick up the stronger guard.
- If Ollama Cloud's OpenAI-compatible layer turns out not to honor `parallel_tool_calls: false`, fall back to `write_guard: prompt` and document that explicitly rather than silently accepting it.

### Account tier & auto-chain caution
- **D-02:** This deployment is on the Ollama Cloud **Pro tier** ($20/mo, ~3 concurrent requests per third-party sourced numbers — no official Ollama figure exists). Use this as the working assumption for concurrency testing; confirm against the live account during build.
- **D-03:** Every Ollama Cloud model added in this phase starts `auto: false` (explicit-selection only), regardless of size. Nothing enters the Auto failover chain until it has been timed on production-shaped infrastructure and proven reliable under real concurrent load. This applies uniformly, not just to the large 120B+ model.

### Credit multiplier / pricing
- **D-04:** `input_per_mtok` / `output_per_mtok` are `null` for all Ollama Cloud catalog rows (subscription billing, not metered — same convention already used for self-hosted/custom-endpoint rows). This was already settled by research (Pitfall 7), not re-litigated here.
- **D-05:** `credit_multiplier` is left as an open, per-model decision made at catalog-entry time in the sysadmin panel, not locked to a formula now. Claude's discretion at build/verification time is to propose a reasonable starting value per model (referencing existing multiplier bands: 1.0x for free-tier-eligible models, 1.5-3.0x for pro-tier models) and flag it for the user to confirm rather than silently picking one.

### Initial model selection
- **D-06:** The first two catalog rows to add and verify are **`gpt-oss:20b-cloud`** (small/fast — proves the end-to-end path cheaply) and **`gpt-oss:120b-cloud`** (large — proves the 120s-timeout/latency question in the same phase). Both ship as part of this phase's verification, both start `auto: false` per D-03.

### Claude's Discretion
- Exact `credit_multiplier` value per model (D-05) — propose and confirm with the user rather than deciding unilaterally.
- Endpoint choice for live listing (`/v1/models` vs `/api/tags`) — already research-recommended as `/v1/models` (reuses the `openai` match-arm pattern); confirm the exact response shape with a live authenticated call during build.
- Exact wording/placement of the `write_guard` fallback note if `parallel_tool_calls: false` doesn't work as expected (D-01).

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Research (produced for this milestone, HIGH confidence)
- `.planning/research/SUMMARY.md` — executive summary, integration points, build order, verification checklist
- `.planning/research/PITFALLS.md` — all 7 pitfalls with prevention/verification per phase; read in full before planning, especially Pitfalls 1, 3, 4, 6, 7 which map directly to decisions above
- `.planning/research/ARCHITECTURE.md` — architecture approach and integration points
- `.planning/research/STACK.md` — confirmed `laravel/ai` v0.11.0 driver behavior, live curl-verified endpoints
- `.planning/research/FEATURES.md` — table-stakes vs anti-features for this integration

### Project planning
- `.planning/ROADMAP.md` §Phase 1 — success criteria, build order, requirement mapping
- `.planning/REQUIREMENTS.md` — OLLAMA-01 through OLLAMA-06, out-of-scope table
- `.planning/PROJECT.md` §Current Milestone, §Context, §Constraints

### Codebase maps
- `.planning/codebase/INTEGRATIONS.md` — existing AI provider inventory (OpenAI, Anthropic, self-hosted Ollama)
- `.planning/codebase/ARCHITECTURE.md` — modular monolith structure, action-class write path

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `vendor/laravel/ai` `OllamaProvider` / `CreatesOllamaClient` — driver is generic enough to point at `https://ollama.com` with a distinct config key; no driver changes needed.
- `ModelProbe` — already generic verification, runs on every new catalog pairing at save time; no new code needed to catch a bad model tag.
- `ProviderModelCatalog::fetch()` — existing `match` arm pattern for `'openai'` is the template to copy for the new `'ollama_cloud'` arm (both use OpenAI-compatible `/v1/models`).

### Established Patterns
- Self-hosted/custom-endpoint catalog rows already use `input_per_mtok: null` / `output_per_mtok: null` when pricing can't be honestly filled — Ollama Cloud follows this exact convention (D-04).
- `CrmAssistant::providerOptions()` is an opt-in per-provider `match`; new providers default to the weakest guard (`prompt`) until an explicit arm is added (D-01).

### Integration Points
- `config/ai.php` — new `ollama_cloud` provider block, distinct from existing `'ollama'` block.
- `.env.example` — document `OLLAMA_CLOUD_API_KEY` / base URL.
- `packages/Chat/src/Services/ProviderModelCatalog.php::fetch()` — new `ollama_cloud` match arm.
- `app/Health/ChatProviderCheck.php::probe()` — new `ollama_cloud` match arm (load-bearing, must not be deferred).
- `packages/Chat/src/Agents/CrmAssistant.php::providerOptions()` — new arm for `parallel_tool_calls: false` (D-01).

</code_context>

<specifics>
## Specific Ideas

No specific UI/UX requests beyond what's already locked in ROADMAP.md success criteria. The
discussion focused entirely on the four build-time judgment calls research flagged as needing
a human decision (write guard investment, account tier assumption, pricing convention, initial
model set) — all other implementation details are delegated to research/planning as usual.

</specifics>

<deferred>
## Deferred Ideas

None — discussion stayed within phase scope. No scope-creep suggestions came up during
discussion.

</deferred>

---

*Phase: 1-Ollama Cloud Provider Integration*
*Context gathered: 2026-09-02*

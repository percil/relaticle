# Feature Research

**Domain:** AI model provider integration (chat model catalog UX) — adding Ollama Cloud
**Researched:** 2026-09-02
**Confidence:** MEDIUM-HIGH (driver/catalog mechanics verified against installed vendor code = HIGH; Ollama Cloud pricing/model-catalog facts from web sources = MEDIUM, no official API reference doc was fetchable for `/v1/models` cloud-specific behavior, only the generic OpenAI-compatibility doc)

## Answers to the Research Questions

### 1. What models does Ollama Cloud offer, and do they support tool calling?

Ollama Cloud's flagship catalog (all `-cloud`-suffixed tags, all designed as agentic/tool-use models by their labs):

| Model tag | Notes |
|---|---|
| `gpt-oss:120b-cloud` / `gpt-oss:20b-cloud` | OpenAI's open-weight release, agentic-tuned |
| `qwen3-coder:480b-cloud` | Alibaba, coding/agent-tuned |
| `deepseek-v3.1:671b-cloud` | DeepSeek, tool-use tuned |
| `kimi-k2:1t-cloud` | Moonshot, agentic-tuned |
| `glm-4.6:cloud` | Zhipu |
| `qwen3-vl:235b-cloud` | Qwen, vision |

All of these ship from labs that market them explicitly as agent/tool-calling models, and Ollama's own engine reports `tools` in each model's `capabilities` array (native `/api/show` endpoint; see Q4). **Confidence this generalizes to "every model" is MEDIUM** — Ollama's catalog rotates (new cloud-only large MoE models appear over time) and a handful of smaller/vision-only entries in the wider library do not carry the `tools` capability. This is exactly why the existing `ModelProbe` pattern (real request, never a typed/assumed capability) is the correct fit here rather than trusting a listing endpoint. **No new capability-detection code is needed** — `ModelProbe` is provider-agnostic already (drives through `CrmAssistant::prompt()` with `provider`/`model` params) and will measure `supports_tools` for `ollama_cloud` exactly as it does for every other provider.

### 2. Pricing model: is it real per-token $/Mtok, fillable in the existing catalog fields?

**Yes — genuinely per-token, and directly fillable into `input_per_mtok`/`output_per_mtok`.** This is the most important finding for scoping this milestone; it removes a risk the milestone description flagged as open.

Ollama moved off GPU-time/session-quota billing to **industry-standard per-token pricing**, published per model on `ollama.com/pricing` and on each model's own page (input / cached-input / output, $ per million tokens — the exact same three-way shape this app's catalog already has room for, minus a "cached input" column the app doesn't track for any provider today). Subscription tiers (Free $0, Pro $20/mo, Max $100/mo, Team $500/mo) are **prepaid credit pools consumed at those same per-token rates**, not flat unlimited-use subscriptions — once the monthly credit pool is spent, usage continues billed at the same per-token rate rather than being cut off. That is materially the same shape as "buy Anthropic/OpenAI credits, get billed per token," so it fits this app's existing mental model of a metered cloud provider (single account, single API key at the install level, `credit_multiplier` and `$/Mtok` set once by the sysadmin operator).

Practically: an operator fills `input_per_mtok` / `output_per_mtok` by hand from Ollama's pricing page, exactly as they already do for the Anthropic/OpenAI/Gemini rows baked into `packages/Chat/config/chat.php` — those are also hand-typed vendor list prices, never auto-fetched. **No gap, no new mechanism needed here.**

Caveat: the Free tier is quota/starter-model-limited rather than fully priced, so an install running on a Free Ollama Cloud account may hit account-level throttling (1 concurrent request) independent of anything this catalog tracks — that's an operational/runtime concern, not a catalog-shape concern.

### 3. Table stakes vs nice-to-have to reach parity with the existing Anthropic/OpenAI pattern

Parity means: a sysadmin can add an Ollama Cloud row in `ManageAiSettings`, pick a model from a real live list (not free text), have it `ModelProbe`-verified, price it, plan-gate it, and have chat actually route turns to it. Concretely:

| # | Item | Complexity | Why |
|---|---|---|---|
| 1 | New `ollama_cloud` entry in `config/ai.php`: `'driver' => 'ollama', 'key' => env('OLLAMA_CLOUD_API_KEY'), 'url' => env('OLLAMA_CLOUD_URL', 'https://ollama.com')` | **LOW** | The installed `laravel/ai` `OllamaGateway`/`CreatesOllamaClient` trait already sends `Authorization: Bearer <key>` when a key is present and reads its base URL from `additionalConfiguration()['url']` — the *exact* mechanism Ollama Cloud needs (Bearer token auth against `https://ollama.com` instead of `http://localhost:11434`). **No new gateway/driver code required**, only a second provider config block reusing the same `ollama` driver. |
| 2 | Tool-calling on the wire | **NONE (already built)** | `Laravel\Ai\Gateway\Ollama\Concerns\MapsTools` already maps this app's tool schemas to Ollama's OpenAI-style `{type: function, function: {...}}` format. Verified in `vendor/laravel/ai`. |
| 3 | `ProviderModelCatalog::fetch()` new `ollama_cloud` case | **LOW** | Ollama Cloud exposes an **OpenAI-compatible `/v1/models`** endpoint at `https://ollama.com/v1/models` (Bearer auth) returning the identical `{id, object, created, owned_by}` shape the `openai` case already parses. This can be added as a near-copy of the existing `'openai' => ...` match arm — same `displayName()` fallback-to-id behavior (Ollama publishes no `display_name`), same `releasedAt()` parsing of `created`. No new field-mapping logic needed. |
| 4 | Sysadmin catalog page: provider dropdown, model dropdown, verify-and-save flow | **NONE (already generic)** | `ManageAiSettings::providerOptions()` is driven entirely off `config('ai.providers.*.key')` being non-blank, and `modelOptions()`/`ModelProbe` are provider-agnostic. Once #1 and #3 exist, Ollama Cloud appears in both dropdowns automatically — this is exactly the "no per-provider UI code" design the page already has. |
| 5 | Distinguish `ollama_cloud` (new, plan-gated, metered) from `ollama` (existing, self-hosted/free, env-only) | **LOW** | Keep them as two separate `config('ai.providers.*)` keys and two separate concerns: `ollama` stays the free/self-hosted synthetic entry in `ModelRegistry::ollamaFromEnv()` (untouched); `ollama_cloud` is a normal catalog-managed cloud provider like Anthropic/OpenAI (rows live in `ChatSettings::models`, not env-synthesized). Do not let one collapse into the other — that's an explicit anti-feature below. |
| 6 | `.env.example` / docs entry for `OLLAMA_CLOUD_API_KEY` (+ optional `OLLAMA_CLOUD_URL` override) | **LOW** | Mechanical, matches every other provider's env convention. |

Nice-to-have, explicitly **not** required for parity:
- Surfacing Ollama's native `/api/show` `capabilities` array (which *does* include a `tools` flag) as a pre-flight hint in the model picker before ModelProbe runs. Tempting because it exists, but it duplicates the one thing `ModelProbe`'s docblock says never to do: trust a claimed capability instead of a real probed one. Skip it.
- A "cached input" price tier column, even though Ollama publishes one. No existing provider row in this catalog has that column either; don't add a third price field for one provider only.

### 4. Ollama-Cloud-specific quirks in what a models-listing response reports

- **`/v1/models` (OpenAI-compatible, what `ProviderModelCatalog` should use):** returns only `id`, `object`, `created` (last-modified timestamp, not a real "release date"), `owned_by` (defaults to `"library"`). **No context window, no tool-calling flag, no parameter count.** This is *less* detailed than Anthropic's `/v1/models` (which sends `display_name`) but structurally identical to what the `openai` case already parses — same fallback paths already handle it.
- **`/api/tags` (native Ollama API):** richer per-model metadata (`size`, `digest`, `details.family`, `details.parameter_size`, `details.quantization_level`) but still **no tool-calling flag and no context window**, and a different response envelope than the OpenAI-shaped one this app's parser expects. Not a drop-in fit for `ProviderModelCatalog::fetch()`'s current parsing code — using it would mean writing a second, bespoke row mapper, whereas `/v1/models` reuses the existing one. Prefer `/v1/models`.
- **`/api/show` (native API, per-model detail call):** the *only* Ollama endpoint that reports a `capabilities` array (`tools`, `vision`, `thinking`, `embedding`, `completion`, `insert`), but it requires one call per model id, not a list call, and is exactly the kind of "typed capability" the app's own `ModelProbe` design note explicitly rejects trusting. Not needed and not recommended to wire up (see anti-feature above).
- **Model naming:** cloud catalog tags carry an explicit `-cloud` suffix (`gpt-oss:120b-cloud`) distinct from the same model's local tag (`gpt-oss:120b`) — this suffix is part of the model's identity/tag as stored in `ChatSettings::models[].model`, exactly analogous to how this catalog already stores full vendor tags (`claude-sonnet-5`, `gpt-5.5`) verbatim.

## Feature Landscape

### Table Stakes (Must Reach Parity With Anthropic/OpenAI)

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| `ollama_cloud` provider config entry (key + URL, reusing `driver: ollama`) | Every other cloud provider in this catalog has one; the sysadmin provider dropdown is driven off it | LOW | Zero new driver code — `laravel/ai`'s existing `ollama` driver already does Bearer auth + configurable base URL |
| Live model listing via `/v1/models` | This milestone's explicit goal — replace free text with a real list, same as Anthropic/OpenAI | LOW | Near-identical match arm to the existing `openai` case in `ProviderModelCatalog::fetch()` |
| `ModelProbe` verification before save | Non-negotiable per `ManageAiSettings`'s own design (RELATICLE-CRM-6D) — no provider gets to skip it | NONE (already generic) | Works unmodified for any provider name |
| Manual $/Mtok pricing entry by the sysadmin operator | Same as every other row today; Ollama publishes real per-token prices to copy from | LOW | Confirmed real per-token pricing exists — this is fillable, not a blocker |
| Plan-gating (`min_plan`), `credit_multiplier`, `auto`/`enabled` toggles | Identical to every other catalog row; no provider-specific behavior | NONE (already generic) | — |
| `.env` documentation for `OLLAMA_CLOUD_API_KEY` | Standard convention for every provider | LOW | — |

### Differentiators (Not Required, But Worth Doing Well)

| Feature | Value Proposition | Complexity | Notes |
|---------|-------------------|------------|-------|
| Sorting the live list newest-first using `created` | Matches the UX already built for Anthropic/OpenAI (`sortByDesc(released_at)`) | NONE (already generic) | `created` is a "last modified" timestamp on Ollama's side, not a true release date, but it's the best signal the endpoint gives and the existing code already treats OpenAI's `created` the same way |
| Grouping/labeling cloud vs. self-hosted Ollama distinctly in the provider dropdown (e.g. "Ollama Cloud" vs "Ollama (self-hosted)") | Avoids operator confusion between the two `ollama*` provider keys | LOW | `providerLabel()` already falls back to `str($provider)->headline()`; `ollama_cloud` renders as "Ollama Cloud" for free |

### Anti-Features (Would Seem Natural Here, Don't Build Them)

| Feature | Why Requested | Why Problematic | Alternative |
|---------|---------------|------------------|-------------|
| Folding Ollama Cloud into the existing self-hosted `ollama` entry (`ModelRegistry::ollamaFromEnv()`) | It's "the same vendor," seems like reuse | That path is explicitly free, `self_hosted: true`, excluded from `offered()`, and env-only (no plan-gating, no pricing, no sysadmin management) — exactly what this milestone says NOT to do | Keep `ollama_cloud` as a fully separate, catalog-managed cloud provider entry, `ollama` untouched |
| Pre-flagging tool-calling support in the model picker from `/api/show`'s `capabilities` array before save | The data exists and looks convenient | Duplicates `ModelProbe`'s entire reason for existing ("capabilities are measured, never typed" — see `ManageAiSettings` docblock, RELATICLE-CRM-6D); a claimed capability that turns out wrong is the exact failure class the probe gate prevents | Let `ModelProbe` measure it on save, same as every other provider |
| Building a bespoke row-mapper for `/api/tags` to get richer metadata (`parameter_size`, `quantization_level`) | It's a richer response than `/v1/models` | New parsing path, new fields the UI has nowhere to show, doesn't match the existing `openai`-shaped parser this catalog already has | Use `/v1/models` (OpenAI-compatible), same shape as the `openai` case |
| Adding a "cached input $/Mtok" third pricing tier because Ollama publishes one | Data is available | No other provider row has this column; adds a one-off field for one provider | Fill `input_per_mtok`/`output_per_mtok` only, same two fields every other row uses |

## Feature Dependencies

```
config/ai.php: ollama_cloud provider entry
    └──required by──> ProviderModelCatalog::fetch() ollama_cloud case
                           └──required by──> ManageAiSettings model picker showing live Ollama Cloud models
                                                  └──gated by──> ModelProbe (generic, no dependency added)
                                                                     └──required by──> row saved into ChatSettings::models
                                                                                            └──required by──> ModelRegistry::offered()/available() surfacing it to chat (generic, no change needed)
```

### Dependency Notes

- **Provider config entry must exist before the catalog fetch case is useful:** `ProviderModelCatalog::__invoke()` returns `[]` immediately when `config("ai.providers.{$provider}.key")` is blank, so the config entry (item 1 above) is a hard prerequisite for the live-listing case (item 3) to ever fire.
- **ModelProbe has no new dependency:** because it already drives through the generic `CrmAssistant::prompt(provider:, model:)` entry point, it needs nothing provider-specific to support `ollama_cloud` — this is the one piece of "parity work" that is already done by construction.
- **`ModelRegistry` needs no change:** it reads `config('chat.models')` (i.e., `ChatSettings::models`, populated by the sysadmin save flow) generically; a saved `ollama_cloud` row flows through `offered()`/`available()`/`autoChain()` exactly like any Anthropic/OpenAI row, gated only by `supports_tools` (measured by `ModelProbe`) and `self_hosted` (false, since it's a catalog row not an env-synthesized entry).

## MVP Definition

### Launch With (v1 — this milestone)

- [ ] `ollama_cloud` provider entry in `config/ai.php` (`driver: ollama`, `OLLAMA_CLOUD_API_KEY`, base URL `https://ollama.com`) — essential, unlocks everything else
- [ ] `ProviderModelCatalog::fetch()` `ollama_cloud` case hitting `{base}/v1/models` — this milestone's explicit deliverable
- [ ] At least one Ollama Cloud model added to the seed catalog (`packages/Chat/config/chat.php` `models` array or added live via the sysadmin page) with real per-token pricing copied from `ollama.com/pricing` — proves the end-to-end flow works
- [ ] `.env.example` documentation for the new key/URL

### Add After Validation (v1.x)

- [ ] Distinct provider label ("Ollama Cloud" vs "Ollama") if operators report confusion in the dropdown — likely unnecessary since `providerLabel()`'s fallback already produces a readable label for free

### Future Consideration (v2+ / not this milestone)

- [ ] Any use of `/api/show` capability data — deliberately deferred as an anti-feature, not a "not yet built" item
- [ ] Multi-account or per-tenant Ollama Cloud keys — out of scope; every other provider in this app is a single install-level key, and there's no signal Ollama Cloud needs to be different

## Feature Prioritization Matrix

| Feature | User Value | Implementation Cost | Priority |
|---------|------------|----------------------|----------|
| `ollama_cloud` provider config entry | HIGH | LOW | P1 |
| `ProviderModelCatalog` live listing case | HIGH | LOW | P1 |
| Seed catalog row(s) with real pricing | MEDIUM | LOW | P1 |
| Distinct dropdown labeling | LOW | LOW | P3 |
| `/api/show` capability pre-flagging | LOW (duplicates existing gate) | MEDIUM | Do not build |

## Competitor / Sibling-Provider Feature Analysis

| Feature | Anthropic (existing) | OpenAI (existing) | Ollama Cloud (this milestone) |
|---------|----------------------|--------------------|-------------------------------|
| Auth header | `x-api-key` + `anthropic-version` | `Authorization: Bearer` | `Authorization: Bearer` (identical to OpenAI's shape) |
| Models endpoint | `GET /v1/models` (native, has `display_name`) | `GET {base}/models` (OpenAI-compatible, no `display_name`) | `GET {base}/v1/models` (OpenAI-compatible, no `display_name` — behaves like OpenAI's case) |
| Driver in `laravel/ai` | `anthropic` | `openai` | `ollama` (already installed, already supports tools + Bearer auth) |
| Pricing shape | Vendor $/Mtok, hand-typed | Vendor $/Mtok, hand-typed | Vendor $/Mtok, hand-typed (genuinely per-token, confirmed) |
| Tool-calling determination | `ModelProbe` (measured) | `ModelProbe` (measured) | `ModelProbe` (measured) — no special case needed |

## Sources

- `packages/Chat/src/Services/ProviderModelCatalog.php`, `ModelRegistry.php`, `packages/SystemAdmin/.../ManageAiSettings.php`, `packages/Chat/config/chat.php`, `config/ai.php` (this repo, read directly — HIGH confidence)
- `vendor/laravel/ai/src/Gateway/Ollama/*` (this repo's installed package, read directly — HIGH confidence, confirms Bearer auth + configurable URL + tool mapping already work)
- [Ollama Cloud docs](https://docs.ollama.com/cloud) — auth, endpoints, `-cloud` model suffix (MEDIUM confidence, official but thin)
- [Ollama transparent pricing blog](https://ollama.com/blog/transparent-pricing) — per-token billing shift, plan credit pools (MEDIUM confidence, official blog)
- [Ollama pricing page](https://ollama.com/pricing) — plan tiers, per-model $/Mtok table (MEDIUM confidence, official but content may rotate)
- [Ollama Cloud models blog](https://ollama.com/blog/cloud-models) — model list, OpenAI-compatible API confirmation (MEDIUM confidence, official blog)
- [OpenAI compatibility docs](https://docs.ollama.com/api/openai-compatibility) — `/v1/models` response shape (`id`, `object`, `created`, `owned_by`) (MEDIUM-HIGH, official docs)
- [Ollama GitHub API reference](https://github.com/ollama/ollama/blob/main/docs/api.md) — `/api/tags` and `/api/show` shapes, `capabilities` array (HIGH confidence, primary source repo docs)
- General web search on Ollama Cloud model catalog and tool-calling support (MEDIUM confidence, several third-party 2026 roundup articles, cross-checked against official blog for the core model list)

---
*Feature research for: Ollama Cloud AI provider integration*
*Researched: 2026-09-02*

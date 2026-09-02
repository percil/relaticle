# Pitfalls Research

**Domain:** Adding Ollama Cloud as an AI model provider to a credit-metered, tool-calling chat product (Relaticle)
**Researched:** 2026-09-02
**Confidence:** HIGH for Relaticle codebase findings (read directly from source); MEDIUM for Ollama Cloud external facts (cross-checked web search + docs.ollama.com fetch, no official rate-limit numbers published)

## Critical Pitfalls

### Pitfall 1: `ollama_cloud` provider config colliding with the existing local `ollama` provider

**What goes wrong:**
`config/ai.php` already has a self-hosted `'ollama'` provider entry (`driver: ollama`, `key: OLLAMA_API_KEY`, `url: OLLAMA_BASE_URL`, default `http://localhost:11434`), and `ModelRegistry::ollamaFromEnv()` independently builds a hardcoded-id `'ollama'` catalog entry from `chat.ollama.model` (`OLLAMA_MODEL`). That local entry is `self_hosted: true`, always `min_plan: free`, `credit_multiplier: 1.0`, `write_guard: prompt`, and is **never probed by `ModelProbe`** — `ModelRegistry::customFromConfig()` builds it directly from env, bypassing the sysadmin catalog/probe pipeline entirely (`ModelRegistry.php:224-251`). If the new cloud provider reuses the `ollama` config key or model id instead of a distinct `ollama_cloud` key, it either overwrites the local dev/self-hosted config array or gets merged into the trusted "self-hosted infrastructure, never plan-gated" code path (`AiModelResolver::autoPick()` comment: "self-hosted infrastructure is not plan-gated"). That would silently grant every workspace access to a paid cloud model at `min_plan: free` with no `ModelProbe` verification ever having run against it.

**Why it happens:**
The names are natural near-misses ("ollama" vs "ollama cloud") and `laravel/ai`'s `OllamaProvider`/`CreatesOllamaClient` driver is generic enough (honors any `url` + `key`) that reusing the existing `ollama` config array with a different `url` "just works" at the HTTP layer, hiding the semantic collision from anyone testing only the happy path.

**How to avoid:**
Use a distinct provider config key `ollama_cloud` (not `ollama`) in `config/ai.php`, a distinct env var (`OLLAMA_CLOUD_API_KEY`, not `OLLAMA_API_KEY`), and a distinct catalog `provider` string (`'ollama_cloud'`, not `'ollama'`) in every `chat.models` row. Never touch the existing `'ollama' => [...]` provider block, `chat.ollama.model`, or `ModelRegistry::ollamaFromEnv()` — those exist for local self-hosted Ollama and must keep working unmodified.

**Warning signs:**
`config('ai.providers.ollama')` returning a URL that isn't `localhost:11434`/`OLLAMA_BASE_URL`; a cloud model appearing in `ModelRegistry::autoChain()` or `pickerOptions()` at `min_plan: free` with no `verified_at`; `ModelProbe::__invoke()` never having been called for the new pairing.

**Phase to address:** Initial config phase (provider registration in `config/ai.php` + `.env.example`).

---

### Pitfall 2: Ollama Cloud model tags require a `-cloud` suffix; a bare tag silently fails

**What goes wrong:**
Ollama's cloud-hosted models are addressed with a `-cloud` suffix on the tag (`gpt-oss:120b-cloud`, `gpt-oss:20b-cloud`, `qwen3-coder:480b-cloud`, etc.), not the bare tag used for a locally-pulled model (`gpt-oss:120b`). If a sysadmin (or a first pass at the live-listing feature) stores the bare tag, every chat turn on that catalog row fails at the provider, exactly the RELATICLE-CRM-6D failure class `ModelProbe` was built to catch (a `#[Temperature]` attribute that made every Opus 4.7 turn fail with a 400 — this is the same shape of bug, different vendor).

**Why it happens:**
Ollama's local and cloud catalogs share the same model *family* names, and the suffix convention is easy to miss when typing a tag by hand or when copy-pasting from Ollama's local model library page instead of the cloud model list.

**How to avoid:**
This is exactly what `ModelProbe` exists for and it already runs on every new pairing at save time (`ManageAiSettings::verified()` — `needsProbe()` gates on "enabled and unmeasured", and `save()` refuses to persist any row the provider rejected). The live-listing feature (`ProviderModelCatalog`, Pitfall 3) is what removes the free-text guessing in the first place: it should return `-cloud`-suffixed ids exactly as Ollama Cloud's own listing reports them, so an operator picks from a dropdown instead of typing. Do not special-case or strip the `-cloud` suffix anywhere in the catalog pipeline.

**Warning signs:**
`ManageAiSettings::save()` returning "The provider rejected this model" for an Ollama Cloud row; a catalog entry whose `model` matches a *local* Ollama model page name with no `-cloud` suffix.

**Phase to address:** Probe/verification phase (the mechanism already prevents this from reaching production, but it should be exercised explicitly against at least one gpt-oss/qwen3-coder/deepseek model as part of that phase's manual verification).

---

### Pitfall 3: `ProviderModelCatalog` has no case for `ollama_cloud`; live listing silently returns nothing

**What goes wrong:**
`ProviderModelCatalog::fetch()` is a `match` over provider name with explicit cases only for `'anthropic'` and `'openai'`; everything else falls to `default => null` and the method returns `[]` (`ProviderModelCatalog.php:80-87`). By this class's own design, an empty result is indistinguishable from "provider unreachable" or "no key configured" — it never throws (`rescue(..., report: false)`) so a missing `match` arm looks identical to a network hiccup. Without adding an explicit `ollama_cloud` case, the sysadmin model picker silently falls back to the free-text-equivalent behavior (`modelOptions()` only offers whatever is already stored, per `ManageAiSettings.php:581-596`), which is precisely the "free text where a typo becomes a production 404" problem the milestone's third requirement exists to remove.

**Why it happens:**
The `match` statement's `default => null` was written to fail closed for providers that genuinely have no listing endpoint (Gemini, DeepSeek, etc.), which is correct behavior for those — but it means adding a new provider that *does* have a listing endpoint requires a deliberate, easy-to-forget code addition here. There is no test or type system signal that a provider is "supported for cloud config but missing from this match."

**How to avoid:**
Add an explicit `'ollama_cloud' => ...` arm calling Ollama's OpenAI-compatible `GET https://ollama.com/v1/models` with `Authorization: Bearer <key>` (confirmed pattern: `laravel/ai`'s own `CreatesOllamaClient::client()` already sends `Authorization: Bearer <key>` to this same family of endpoint, so the auth scheme is proven compatible). Verify what `created`/`created_at` field (if any) that endpoint actually returns — `releasedAt()` degrades gracefully to `0` (unsorted) if absent, but confirm this manually rather than assuming OpenAI's exact response shape carries over.

**Warning signs:**
The sysadmin "Model" dropdown for an `ollama_cloud` row stays empty even though `OLLAMA_CLOUD_API_KEY` is set and `providerOptions()` lists the provider; `unlistedNote()` never fires (it only fires when the catalog is non-empty and the model is absent from it) so a genuinely wrong bare tag would show no warning at all with an empty catalog list, masking Pitfall 2.

**Phase to address:** Live-listing phase (this is core scope, not incidental — flag it explicitly as a required code change, not an assumption that "the pattern already exists").

---

### Pitfall 4: Ollama Cloud models default to the weaker `prompt` write guard, with no code path to earn `api`

**What goes wrong:**
`ModelProbe::writeGuardFor()` grants `WriteGuard::Api` (the guard that makes the sequential-approval flow *unbypassable* by the provider itself) only when `CrmAssistant::providerOptions($provider)` sets `tool_choice.disable_parallel_tool_use` or `parallel_tool_calls: false`. That method's `match` has explicit arms only for `Lab::Anthropic` and `Lab::OpenAI`; every other provider — including any new `ollama_cloud` string — falls through to `default => []` (`CrmAssistant.php:634-656`). This is not a bug introduced by adding Ollama Cloud; it is the existing, deliberate default for any unlisted provider. But it means every Ollama Cloud model will always measure as `write_guard: prompt`, relying entirely on the `PendingAction` approval gate as the safety net rather than the provider itself refusing to parallel-call write tools. Ollama's OpenAI-compatible chat-completions layer likely accepts `parallel_tool_calls: false` (it mirrors the OpenAI request shape) but this has not been proven for this deployment, and until `CrmAssistant::providerOptions()` gains an arm for it, the option is never sent regardless.

**Why it happens:**
`providerOptions()` is opt-in per provider rather than a generic pass-through, so a new provider gets the weakest applicable guard by default rather than inheriting whatever its API happens to support.

**How to avoid:**
Treat this as a product decision to make explicitly during the config phase, not something the probe will surface on its own (the probe reports whatever the current code produces as correct-by-definition; it cannot tell you the option *could* have been stronger). If Ollama Cloud's OpenAI-compatible layer does accept `parallel_tool_calls: false`, add an arm to `providerOptions()` for it before probing, so the measured guard reflects the strongest the provider actually supports rather than settling for `prompt` by omission.

**Warning signs:**
A completed Ollama Cloud catalog entry showing `write_guard: prompt` when the operator expected/assumed parity with the Anthropic/OpenAI `api` guard.

**Phase to address:** Probe/verification phase, decided before the first probe run (changing `providerOptions()` after probing means re-probing to pick up the stronger guard, since a passing measurement is cached forever and never re-checked).

---

### Pitfall 5: The app's per-second stream-start limiter doesn't model Ollama Cloud's concurrency-tier limits

**What goes wrong:**
`ProviderRateGate::tryAcquire()` caps chat-stream *starts* per second per provider (`chat.provider_starts_per_second`, default 8) to stop a retry storm from stampeding a provider. This models rate limiting the way Anthropic/OpenAI expose it (requests-per-minute style). Ollama Cloud's actual limiting shape, per its public pricing/limits pages, is different: a **concurrent-request ceiling by plan tier** (Free: 1 concurrent, Pro: 3, Max/Team: 10) plus a **weekly GPU-time/token quota** that resets on a rolling window, not a simple per-second request rate (MEDIUM confidence, no official RPM/TPM numbers are published by Ollama as of this research). A chat turn holds its stream open for the model's full generation time, so even a start-rate throttle well under 8/sec can still push several *concurrent* long-running streams past a Free or Pro-tier concurrency ceiling, producing 429/concurrency-rejected errors the start-rate gate was never designed to prevent.

**Why it happens:**
`provider_starts_per_second` was designed against providers whose limiting unit is "requests initiated per unit time," a category Ollama Cloud's cloud tiers don't cleanly fit; the two limiting models (rate vs. concurrency) look similar enough to conflate at a glance.

**How to avoid:**
Do not assume the default `provider_starts_per_second: 8` is safe for Ollama Cloud. Confirm the actual concurrency ceiling for the account's Ollama Cloud plan tier and either configure a much lower per-provider override (this app's config is a single global value, not per-provider today — note as a possible gap) or accept that Ollama Cloud turns will 429 under concurrent load and ensure `isRateLimited()`/`isTransient()` in `ProcessChatMessage` correctly classifies whatever error shape Ollama Cloud actually returns for a concurrency rejection (verify this is a genuine 429/503-shaped response and not, e.g., a 400 with an error body that the generic HTTP-status-based classifier would miss).

**Warning signs:**
Ollama Cloud turns failing under only modest concurrent chat usage even though `provider_starts_per_second` was never exceeded; users seeing "The assistant encountered an error" (the generic fallback) instead of the "being rate-limited" message, which would indicate the concurrency-rejection response isn't being recognized by `isRateLimited()`.

**Phase to address:** Probe/verification phase for the error-classification behavior; config phase for setting expectations about the account's actual concurrency tier.

---

### Pitfall 6: The fixed 120-second job timeout was tuned for fast commercial APIs, not shared large-model inference

**What goes wrong:**
`ProcessChatMessage` has a hard `#[Timeout(120)]` (`ProcessChatMessage.php:56,64`), inside a `chat-supervisor` Horizon queue whose worker-level `timeout` is 130 seconds (`config/horizon.php`). This is a fixed, provider-agnostic ceiling. Ollama Cloud serves large open-weight models (120B+) on shared cloud infrastructure rather than each vendor's own low-latency dedicated inference stack; generation throughput and queueing behavior under load are unproven for this deployment and plausibly slower than Anthropic/OpenAI, especially for a long tool-calling turn (multiple sequential model round-trips within one job). A turn that runs past 120 seconds is killed by `TimeoutExceededException`, which the app already handles gracefully (settles the reservation at minimum via `settleReservedMinimum` and shows "This model didn't respond within the time limit... switch to a faster model"), but that is a **degraded-but-handled** outcome, not success: expect this to fire measurably more often on large Ollama Cloud models than on the existing Anthropic/OpenAI catalog rows.

**Why it happens:**
120 seconds was set against a catalog of low-latency, dedicated-capacity commercial APIs; nothing in the timeout is provider- or model-size-aware.

**How to avoid:**
Don't treat "the timeout already has a graceful failure message" as sufficient. Before offering a 120B+ model on `auto` (which `AiModelResolver::autoPick()` will route real user turns to, not just explicit picks), manually time a representative multi-tool-call turn end-to-end against production-shaped infrastructure (Horizon, Redis queue — per this repo's existing chat-verification rule) and confirm it reliably finishes well under 120 seconds. If it doesn't, keep the model available for explicit selection only (`auto: false` in the catalog row) rather than in the Auto failover chain, so a struggling large model doesn't degrade the default experience for users who never chose it.

**Warning signs:**
Elevated `TimeoutExceededException` / "didn't respond within the time limit" rates specifically correlated with Ollama Cloud catalog rows in the credit-transaction ledger (`AiCreditTransaction` rows with `model: 'incomplete'` and `metadata.reason` from `settleReservedMinimum`).

**Phase to address:** Probe/verification phase (this is exactly the kind of thing that needs a real, timed, production-shaped test before the model reaches `auto`).

---

### Pitfall 7: Subscription/quota billing doesn't map cleanly to `input_per_mtok`/`output_per_mtok`

**What goes wrong:**
Ollama Cloud is sold as flat-rate subscription tiers (Free/Pro $20/mo/Max $100/mo) with weekly quotas, not simple pay-per-token API billing (MEDIUM confidence — Ollama's own pricing page does list per-model "input/cached input/output price per million tokens" figures used for quota accounting, but this is a usage-metering figure inside a subscription, not the customer-facing marginal cost the way Anthropic/OpenAI's `input_per_mtok`/`output_per_mtok` figures work elsewhere in this catalog). Typing Ollama's internal quota-accounting price into the catalog's `input_per_mtok`/`output_per_mtok` fields would make the sysadmin spend widget report a dollar figure that doesn't correspond to what this workspace is actually paying (a flat monthly fee, not a metered bill), misleading anyone using that widget to reason about marginal AI spend.

**Why it happens:**
Every other row in `config/chat.php`'s `models` array has real vendor list-price figures, so it looks natural to fill these fields in for consistency rather than leave them blank, without stopping to check whether the underlying billing model actually supports a per-token dollar figure meaning what it means for the other rows.

**How to avoid:**
The codebase already has the correct pattern for "this model's true cost cannot be honestly priced," used today for self-hosted/local Ollama and custom endpoints: leave `input_per_mtok`/`output_per_mtok` as `null`. `CatalogEntry::rate()` returns `null` when either is `null` (`CatalogEntry.php:154-164`), and `ModelRegistry::ratesFor()` propagates that `null` cleanly rather than computing a false number — this is the same mechanism, not a new one to build. Document in the sysadmin panel or a code comment why Ollama Cloud rows are priced `null` (subscription-based, not metered), the same way the `chat.php` config header already documents the self-hosted/custom-endpoint carve-out. `credit_multiplier` (the actual internal credit charge) is a separate, required field — set that deliberately based on Ollama Cloud's real relative cost to the team, independent of the display-only `_per_mtok` fields.

**Warning signs:**
The sysadmin spend widget reporting a specific dollar total for Ollama Cloud usage that doesn't reconcile with the actual Ollama Cloud subscription invoice.

**Phase to address:** Initial config phase (decide and document the pricing-field convention before the first catalog row is saved) — this is a one-line decision, not deep implementation work, so it belongs early rather than being deferred to verification.

---

## Technical Debt Patterns

| Shortcut | Immediate Benefit | Long-term Cost | When Acceptable |
|----------|-------------------|-----------------|------------------|
| Hardcoding a single Ollama Cloud model id instead of building the live-listing case (Pitfall 3) | Faster to ship the config phase alone | Reintroduces exactly the free-text-typo risk this milestone's third requirement exists to remove; every new Ollama Cloud model needs a manual code/panel change instead of a picker refresh | Never for the final milestone deliverable; acceptable only as a throwaway spike to validate the driver/ModelProbe path before building the listing case |
| Filling `input_per_mtok`/`output_per_mtok` with Ollama's internal quota-accounting numbers "because every other row has them" (Pitfall 7) | Consistent-looking catalog table | Spend widget reports a number that doesn't reconcile with the actual bill, undermining trust in the widget for every provider once someone notices | Never — leave `null` per the existing self-hosted/custom-endpoint convention |
| Adding Ollama Cloud only to the `auto` failover chain at `auto: true` without first timing a real multi-tool-call turn (Pitfall 6) | One less manual step | Default/no-preference users silently get slower or more timeout-prone turns than before | Never for `auto: true`; fine for `auto: false` (explicit-selection only) while confidence is being built |

## Integration Gotchas

| Integration | Common Mistake | Correct Approach |
|-------------|-----------------|-------------------|
| Ollama Cloud driver reuse | Pointing the existing `'ollama'` config key at `https://ollama.com` instead of adding a distinct `'ollama_cloud'` key (Pitfall 1) | New `ollama_cloud` provider block in `config/ai.php` (`driver: ollama`, `url: https://ollama.com` or `env('OLLAMA_CLOUD_BASE_URL', 'https://ollama.com')`, `key: env('OLLAMA_CLOUD_API_KEY')`) — the `driver` reuse is safe and already proven by the existing `selfhosted` provider block reusing the `openai` driver; the config *key* must not collide |
| `ProviderModelCatalog` live listing | Assuming a new provider "just works" because Anthropic/OpenAI do | Requires an explicit new `match` arm in `ProviderModelCatalog::fetch()` for `ollama_cloud` hitting `GET https://ollama.com/v1/models` with `Authorization: Bearer <key>` (Pitfall 3) |
| Model tag naming | Typing/copying the bare local-model tag (`gpt-oss:120b`) into the catalog | Cloud models require the `-cloud` suffix (`gpt-oss:120b-cloud`); rely on `ModelProbe` at save time to catch a mistake here, and prefer the live-listing dropdown once built (Pitfall 2) |
| Rate/concurrency handling | Assuming `provider_starts_per_second: 8` (tuned for RPM-style commercial APIs) is a safe default for Ollama Cloud | Confirm the account's actual Ollama Cloud concurrency tier (Free 1 / Pro 3 / Max 10 concurrent) before relying on the existing global rate gate to protect it (Pitfall 5) |

## Performance Traps

| Trap | Symptoms | Prevention | When It Breaks |
|------|----------|------------|-----------------|
| Large open-weight cloud models on a fixed 120s job timeout (Pitfall 6) | Elevated `TimeoutExceededException` / "didn't respond within the time limit" for Ollama Cloud rows specifically | Time a real multi-tool-call turn against production-shaped infra before enabling `auto: true`; keep slow models explicit-selection-only | Any turn whose model generation + tool round-trips exceed ~120s wall time; more likely under Ollama Cloud's shared/queued large-model serving than dedicated commercial APIs |
| Concurrency-tier mismatch (Pitfall 5) | Ollama Cloud turns 429/fail under only a handful of concurrent users, despite the per-second start-rate gate never tripping | Know the account's Ollama Cloud plan tier's concurrent-request ceiling; consider a lower provider-specific override if the app ever supports one | As soon as concurrent in-flight Ollama Cloud streams exceed the plan's concurrency cap (as low as 1 on the Free tier) |

## Security Mistakes

| Mistake | Risk | Prevention |
|---------|------|------------|
| Reusing the `ollama` provider config key/id for the cloud provider (Pitfall 1) | A cloud model could inherit the local self-hosted path's `min_plan: free`, no-plan-gating, and no-`ModelProbe`-verification treatment, exposing a paid/unverified model to every workspace | Distinct `ollama_cloud` config key, env var, and catalog `provider` string; never touch the existing `ollama`/self-hosted code paths |
| Assuming Ollama Cloud earns the `api` write guard without adding a `providerOptions()` arm for it (Pitfall 4) | None immediately (the `prompt` guard plus the `PendingAction` approval gate still holds), but an operator who assumes parity with Anthropic/OpenAI's `api` guard is trusting a protection that isn't actually configured | Verify and explicitly wire `parallel_tool_calls: false` (or equivalent) into `CrmAssistant::providerOptions()` before assuming this is a solved problem, not just after the probe reports `prompt` |

## UX Pitfalls

| Pitfall | User Impact | Better Approach |
|---------|-------------|------------------|
| Enabling a large, potentially slow Ollama Cloud model in the `auto` chain untested (Pitfall 6) | Users who never explicitly chose the model hit "didn't respond within the time limit" turns they can't easily attribute to a model choice they didn't make | Keep new/unproven Ollama Cloud models `auto: false` until timed under production-shaped load; let users opt in explicitly first |
| Empty model dropdown with no explanation (Pitfall 3, before the `ollama_cloud` listing case is added) | Operator sees no models to pick from and can't tell if the key is wrong, the provider is down, or the feature simply isn't implemented yet for this provider | Implement the `ollama_cloud` case in `ProviderModelCatalog` before shipping the panel change publicly, so "empty" only ever means "unreachable/no key," matching the class's documented contract |

## "Looks Done But Isn't" Checklist

- [ ] **Provider config:** `OLLAMA_CLOUD_API_KEY` set in the actual deployed `.env`, AND `php artisan config:clear && php artisan config:cache` re-run in production — a cached `config/ai.php` from before the deploy will make `ManageAiSettings::verified()` reject every save with "no API key is configured for ollama_cloud" even though `.env` is correct.
- [ ] **Model tag correctness:** At least one gpt-oss/qwen3-coder/deepseek-v3.1 model actually saved through the sysadmin panel and confirmed `ModelProbe`-verified (green "Verified" badge), not just added to `config/chat.php` by hand — verify the `-cloud` suffix survived (Pitfall 2).
- [ ] **Live listing:** `ProviderModelCatalog::fetch()` has an explicit `ollama_cloud` arm and the sysadmin "Model" dropdown actually populates from it — an empty dropdown with a valid key means this was skipped (Pitfall 3).
- [ ] **Real chat turn, not just the probe:** `ModelProbe` sends one "reply OK" message with `ModelProbeAgent`, which forbids tool calls — it does NOT prove a full multi-tool-call turn completes within the 120s job timeout. Separately walk a real propose/approve chat turn (send → stream → proposal card → approve) against Horizon/Redis/Reverb per this repo's existing chat-verification rule, on at least one large (120B+) model.
- [ ] **Pricing fields:** `input_per_mtok`/`output_per_mtok` deliberately left `null` (or filled with a number someone can defend against an actual invoice), not copy-pasted from Ollama's quota-accounting price table as if it were marginal API cost (Pitfall 7).
- [ ] **Write guard:** Catalog entry's measured `write_guard` value checked and consciously accepted (`prompt` unless `providerOptions()` was extended) rather than assumed to match Anthropic/OpenAI's `api` guard (Pitfall 4).

## Recovery Strategies

| Pitfall | Recovery Cost | Recovery Steps |
|---------|----------------|-----------------|
| Provider/config key collision (Pitfall 1) | LOW | Rename the config key, env var, and every catalog row's `provider` string to `ollama_cloud`; no data migration needed since `provider` is a plain string field re-read from settings on every request |
| Bad model tag saved without the `-cloud` suffix | LOW | `ManageAiSettings::save()` already refuses to persist an unverified/rejected row, so this cannot reach production via the panel; if it somehow reaches `config/chat.php` directly, `ModelProbe::forget()` + re-save through the panel re-triggers verification |
| Model enabled in `auto` chain, later found too slow/timeout-prone (Pitfall 6) | LOW | Flip `auto: false` on the catalog row via the sysadmin panel (no deploy needed, `Artisan::call('queue:restart')` already runs on save) |
| Pricing fields typed with a misleading number (Pitfall 7) | LOW | Edit the row's `input_per_mtok`/`output_per_mtok` to `null` (or a corrected figure) via the sysadmin panel's "configure" modal; no historical `ai_credit_transactions` rows are affected since those store `credits_charged` (computed from `credit_multiplier`), not the display-only per-mtok figures |

## Pitfall-to-Phase Mapping

| Pitfall | Prevention Phase | Verification |
|---------|-------------------|---------------|
| 1. Provider/config key collision with local `ollama` | Initial config phase | Grep for `'ollama_cloud'` vs `'ollama'` used consistently across `config/ai.php`, `.env.example`, and every new `chat.models` row; confirm the existing local `ollama`/self-hosted tests/paths are untouched |
| 2. Cloud model tag missing `-cloud` suffix | Probe/verification phase | `ManageAiSettings::save()` succeeds (green "Verified" badge) for a real Ollama Cloud model id copied from its own listing, not typed by hand |
| 3. `ProviderModelCatalog` missing `ollama_cloud` case | Live-listing phase | Sysadmin "Model" dropdown for `ollama_cloud` returns a real, non-empty list of `-cloud`-suffixed model ids |
| 4. Write guard defaults to `prompt`, no `api` path | Probe/verification phase (decide before first probe) | Explicit decision recorded on whether `CrmAssistant::providerOptions()` gets an `ollama_cloud`/`Lab::Ollama` arm before the catalog row is first probed |
| 5. Concurrency-tier mismatch with `provider_starts_per_second` | Config phase (set expectations) + probe/verification phase (confirm error handling) | Account's Ollama Cloud plan tier's concurrency ceiling documented; a deliberately concurrent test (2-3 simultaneous chat turns) against Ollama Cloud confirms `isRateLimited()`/`isTransient()` classifies the resulting error correctly |
| 6. Fixed 120s timeout vs. large-model latency | Probe/verification phase | Timed, production-shaped (Horizon/Redis/Reverb) multi-tool-call turn on the largest enabled model completes comfortably under 120s before it is set `auto: true` |
| 7. Subscription billing vs. `_per_mtok` pricing fields | Initial config phase | Catalog rows for Ollama Cloud models carry `input_per_mtok`/`output_per_mtok` = `null` (or a defensible number), documented as a deliberate choice |

## Sources

- Relaticle codebase (HIGH confidence, read directly): `packages/Chat/src/Services/ModelProbe.php`, `ModelRegistry.php`, `ProviderModelCatalog.php`, `AiModelResolver.php`, `CreditService.php`; `packages/Chat/src/Support/CatalogEntry.php`, `ModelDescriptor.php`; `packages/Chat/src/Support/ProviderRateGate.php`; `packages/Chat/src/Agents/CrmAssistant.php`; `packages/Chat/src/Jobs/ProcessChatMessage.php`; `packages/Chat/config/chat.php`; `config/ai.php`; `config/horizon.php`; `.env.example`; `packages/SystemAdmin/src/Filament/Pages/Settings/ManageAiSettings.php`; `vendor/laravel/ai/src/Providers/OllamaProvider.php` and `Gateway/Ollama/Concerns/CreatesOllamaClient.php`; `vendor/laravel/ai/src/Enums/Lab.php`
- Ollama Cloud OpenAI-compatible endpoints and auth scheme (MEDIUM confidence, cross-checked): [docs.ollama.com/api/openai-compatibility](https://docs.ollama.com/api/openai-compatibility), [docs.ollama.com/capabilities/tool-calling](https://docs.ollama.com/capabilities/tool-calling)
- Ollama Cloud pricing tiers, concurrency limits, weekly quotas (MEDIUM confidence, no official RPM/TPM published — cross-checked across multiple sources, none primary/official beyond ollama.com/pricing itself): [ollama.com/pricing](https://ollama.com/pricing), [ollamatps.com/limits](https://ollamatps.com/limits/), [ollamatps.com/pricing](https://ollamatps.com/pricing/), [dev.to Ollama Cloud Free vs Pro](https://dev.to/amareswer/ollama-cloud-free-vs-pro-usage-limits-pricing-what-you-actually-get-2026-3ieo)
- Cloud model `-cloud` tag suffix convention (MEDIUM confidence, cross-checked): [ollama.com/blog/gpt-oss](https://ollama.com/blog/gpt-oss), [ollama.com/library/gpt-oss:120b](https://ollama.com/library/gpt-oss:120b), [dev.to beginner's guide to Ollama cloud models](https://dev.to/coderforfun/a-beginners-guide-to-ollama-cloud-models-3lc2)
- General Ollama tool-calling behavior/reliability notes (LOW-MEDIUM confidence, community sources, not specific to Ollama Cloud's largest models): [docs.ollama.com/capabilities/tool-calling](https://docs.ollama.com/capabilities/tool-calling), community discussion threads on function-calling reliability with smaller local models

---
*Pitfalls research for: Ollama Cloud provider integration (Relaticle v1.0 milestone)*
*Researched: 2026-09-02*

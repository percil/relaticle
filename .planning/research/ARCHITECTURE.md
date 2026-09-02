# Architecture Research: Ollama Cloud Provider Integration

**Domain:** AI provider integration into an existing Laravel `laravel/ai`-backed chat model catalog
**Researched:** 2026-09-02
**Confidence:** HIGH (config/provider-resolution mechanics verified by reading installed `vendor/laravel/ai` source directly; MEDIUM on Ollama Cloud's exact API response shape, verified against official docs but not a live call)

## Summary Answer

`ollama_cloud` slots into the existing architecture as a pure **addition**, not a rework. It reuses every mechanism Anthropic/OpenAI/Gemini already go through (`ai.providers`, `ManageAiSettings::providerOptions()`, `ChatSettings`/`CatalogEntry`, `ModelProbe`). Exactly **two files need a new `match` arm** and **one file needs a documentation-only addition**; everything else is config. No `App`/`SystemAdmin` boundary is crossed that isn't already crossed today.

## Integration Points (file + method, new vs. modified)

| # | File | Change | New/Modified | Why |
|---|------|--------|--------------|-----|
| 1 | `config/ai.php` → `providers` array | Add `'ollama_cloud' => ['driver' => 'ollama', 'key' => env('OLLAMA_CLOUD_API_KEY'), 'url' => env('OLLAMA_CLOUD_BASE_URL', 'https://ollama.com')]` | New array entry | Single source of truth for every provider-keyed config read (`ai.providers.{name}.key`, `.url`) |
| 2 | `.env.example` | Document `OLLAMA_CLOUD_API_KEY` / `OLLAMA_CLOUD_BASE_URL` near the existing Ollama block (~line 138) | New doc lines | No code depends on this; it's the operator-facing contract for the new env vars |
| 3 | `packages/Chat/src/Services/ProviderModelCatalog.php` → `fetch()` | Add a `'ollama_cloud' => ...` arm to the `match` at line 80-87, calling `GET {url}/api/tags` with `Bearer {key}`, then normalizing the response into the `{id, created}` shape `displayName()`/`releasedAt()` already expect (see **Gotcha** below) | Modified (new match arm only) | This is the ONLY place live model listing happens. Anthropic/OpenAI are the only two arms today; `default => null` already handles every unhandled provider gracefully (empty list, no exception) |
| 4 | `app/Health/ChatProviderCheck.php` → `probe()` | Add a `'ollama_cloud' => ...` arm to the `match` at line 97-105 | Modified (new match arm only) | **Not listed in the milestone scope but load-bearing.** The moment an `ollama_cloud` model becomes `isServable()`, `reachableModels()` (line 140) picks it up automatically and registers a Spatie Health check for it. Without a matching `probe()` arm it falls through `default => null` and **every** health check run reports `"no health probe is defined for chat provider 'ollama_cloud'"` as a hard failure — a permanent red item on the health dashboard the day this ships |
| 5 | `packages/Chat/resources/views/livewire/chat/partials/_model-state.blade.php` → `providerIcons` | Optionally add `'ollama_cloud' => svg('ri-cloud-line')->toHtml()` (line ~19-24) | Modified, cosmetic only | Not adding it does not break anything — the picker just renders without a provider icon for `ollama_cloud` rows. Pure polish, safe to defer or fold into the same PR |
| — | `packages/SystemAdmin/src/Filament/Pages/Settings/ManageAiSettings.php` | **No change needed** | N/A | See Q1/Q4 below — both `providerOptions()` and `providerLabel()` already degrade gracefully |
| — | `packages/Chat/src/Services/ModelRegistry.php` | **No change needed** | N/A | `customFromConfig()`/`ollamaFromEnv()` key off `config('chat.ollama.model')` and hardcode `provider => 'ollama'`/`id => 'ollama'` — scoped to the existing local entry only, per milestone constraint. `ollama_cloud` never touches this path; it flows through `CatalogEntry`/`ChatSettings` like every other cloud provider |
| — | `packages/Chat/src/Support/CatalogEntry.php`, `ModelDescriptor.php` | **No change needed** | N/A | Both are provider-name-agnostic; `provider` is a free-form string read generically |
| — | `Laravel\Ai\Enums\Lab` (vendor) | **No change needed, and must not be touched** | N/A | See Q2 below — a Lab enum case is not required for a connection to resolve. Do not add an `OllamaCloud` case to a vendor enum |

## Answers to the Five Questions

### Q1 — Is a plain new `config/ai.php` entry sufficient for `providerOptions()`?

**Yes, confirmed, no code change needed.** `ManageAiSettings::providerOptions()` (line 554-570) does exactly this:

```php
$providers = config('ai.providers', []);
$options = collect($providers)
    ->filter(fn (array $connection): bool => filled($connection['key'] ?? null))
    ->keys()
    ->mapWithKeys(fn (string $provider): array => [$provider => $this->providerLabel($provider)])
    ->all();
```

It iterates every keyed entry in `ai.providers`, filters on a non-blank `key`, and labels each by its **array key** (`ollama_cloud`), not by driver. Once `OLLAMA_CLOUD_API_KEY` is set, `ollama_cloud` appears in the sysadmin provider dropdown automatically.

`providerLabel()` (line 537-540) also degrades correctly for a config key the vendor enum doesn't know:
```php
return Lab::tryFrom($provider)?->name ?? str($provider)->headline()->toString();
```
`Lab::tryFrom('ollama_cloud')` returns `null` (the enum has no such case — see Q2), so it falls back to `str('ollama_cloud')->headline()` → **"Ollama Cloud"**. Readable out of the box, no enum edit required.

### Q2 — Can two differently-configured `ollama`-driver connections coexist without collision?

**Yes, confirmed by reading `vendor/laravel/ai` source directly (HIGH confidence).**

- `AiManager::getInstanceConfig($name)` (vendor `AiManager.php` line 494-504) reads `config('ai.providers.'.$name, ['driver' => $name])` and injects `$config['name'] = $name`. This is Laravel's standard `Manager` driver-resolution pattern: instances are created and cached **by connection name** (`ollama`, `ollama_cloud`), not by driver value.
- `Provider::name()` returns `$this->config['name']` (the connection key) and `Provider::additionalConfiguration()` returns everything except `driver`/`key`/`name` — i.e., each `Provider` instance carries its own per-connection `url` inline.
- `OllamaGateway`/`CreatesOllamaClient::client()` and `::baseUrl()` (vendor `Gateway/Ollama/Concerns/CreatesOllamaClient.php`) read the key and URL off the **`$provider` instance passed into the call**, not off a shared/static value:
  ```php
  protected function baseUrl(Provider $provider): string {
      return rtrim($provider->additionalConfiguration()['url'] ?? 'http://localhost:11434', '/');
  }
  ```
- `OllamaProvider` constructs its `OllamaGateway` lazily per-instance (`$this->ollamaGateway ??= new OllamaGateway(...)`), and the gateway itself holds no provider-specific state — every method takes `Provider $provider` as an argument and reads config off it fresh.

Net: `ollama` (local, `http://localhost:11434`) and `ollama_cloud` (`https://ollama.com`) are two independently resolved, independently cached `OllamaProvider` instances, each carrying its own key/URL. No shared mutable state, no collision risk.

### Q3 — Minimal `ProviderModelCatalog::fetch()` change

Add a `match` arm returning a response whose rows get normalized into the same `array<string, array{label, released_at}>` shape the two existing arms produce.

**Gotcha (why this isn't a one-line arm like OpenAI's):** Ollama's native listing endpoint is `GET /api/tags`, not `GET /models`, and its response/row shape differs from the OpenAI-style `{"data": [{"id": ..., "created": ...}]}` the existing `displayName()`/`releasedAt()` helpers expect:

```json
{"models": [{"name": "gpt-oss:120b-cloud", "model": "gpt-oss:120b-cloud", "modified_at": "2026-08-01T12:00:00Z", "size": ..., "digest": "..."}]}
```

Ollama Cloud models are the local Ollama API surface hosted at `https://ollama.com` with `Authorization: Bearer $OLLAMA_API_KEY`, and all currently published cloud models use a `-cloud` tag suffix (`qwen3-coder:480b-cloud`, `gpt-oss:120b-cloud`, `deepseek-v3.1:671b-cloud`) (MEDIUM confidence — official `docs.ollama.com/cloud` and `ollama.com/blog/cloud-models`, not verified against a live call in this session).

Minimal-surface implementation: fetch under the `'models'` top-level key instead of `'data'`, and remap each row to the `id`/`created` keys the shared `displayName()`/`releasedAt()` private methods already read, so those two methods stay untouched:

```php
'ollama_cloud' => $this->client()->withToken($key)
    ->get(rtrim((string) config('ai.providers.ollama_cloud.url', 'https://ollama.com'), '/').'/api/tags'),
```
then, since the row shape differs, either (a) add a third `match`-driven "response key + row shape" indirection before the shared `collect(...)->mapWithKeys(...)` pipeline, or (b) normalize inline for this arm only (`'id' => $row['model'] ?? $row['name']`, `'created' => $row['modified_at'] ?? null`) before falling into the shared pipeline. Either is a small, contained change — do not touch `displayName()`/`releasedAt()` themselves, since Anthropic/OpenAI still rely on the `id`/`created`/`display_name` shape.

**Verify at build time:** confirm the exact `/api/tags` field names and whether Ollama Cloud also exposes an OpenAI-compatible `/v1/models` (the blog references OpenAI-compatibility for cloud models but doesn't document a `/v1/models` listing route explicitly). If `/v1/models` is confirmed available and OpenAI-shaped, prefer it — it lets the `fetch()` arm reuse the identical `openai` pattern with zero shape-normalization code, and lets `ChatProviderCheck::probe()` (see below) reuse the identical `openai` match arm pattern too (`GET /v1/models/{id}` for single-model liveness). This is the highest-leverage design choice in the phase: one endpoint style shared by both integration points 3 and 4, or two different ones.

### Q4 — Other files that hardcode the provider list

Beyond the two `match` arms already covered (Q3, and the health check below), found by exhaustively grepping `ai.providers`, `Lab::tryFrom`, `'anthropic'`/`'openai'` literals, and `'ollama'` literals across `app/` and `packages/`:

1. **`app/Health/ChatProviderCheck.php::probe()`** (line 93-108) — **must be extended**, not optional. Its `match` on `$this->provider` only knows `anthropic`/`openai`; everything else hits `default => null`, and `run()` (line 66) turns that into a hard **failed** health check: `"no health probe is defined for chat provider 'ollama_cloud'"`. Because `reachableModels()` (line 140-154) derives its list generically from `chat.models` + `isServable()` + a configured key, the moment the first `ollama_cloud` catalog row is saved and probed successfully via `ModelProbe`, `ChatProviderCheck` will auto-register a check for it — and immediately fail unless this arm exists. **This is the one integration point most likely to be missed** because it isn't mentioned in the milestone's target features.
2. **`packages/Chat/resources/views/livewire/chat/partials/_model-state.blade.php`** (`providerIcons`, line 19-24) — cosmetic only, no functional break if skipped; picker just shows no provider icon for `ollama_cloud` rows.
3. **`Laravel\Ai\Enums\Lab`** (vendor, `vendor/laravel/ai/src/Enums/Lab.php`) — confirmed it has no `OllamaCloud` case and none is needed (see Q1/Q2). **Do not** attempt to add one; it's vendor code and the fallback label path already handles it.
4. **Config caching** — `config/ai.php` is a normal Laravel config file; no special caching path beyond the standard `php artisan config:clear && config:cache` a deploy already runs. No new gotcha here specific to this feature.
5. **`packages/Chat/config/chat.php`** — the seed array only feeds `ChatSettings->models` on first migrate (per the milestone context); no `ollama_cloud` seed entry is required to make the provider selectable — the sysadmin panel's "Add a model" flow (already-existing `Repeater` UI in `ManageAiSettings`) is how the first catalog row gets added, exactly like adding a new Anthropic/OpenAI model today.

No `App\Enums`, `App\Rules`, or Filament policy references a provider allowlist. Billing plan-gating (`min_plan`) is provider-agnostic (`Plan` enum, unrelated axis).

### Q5 — Suggested build order for a single phase

1. **Config entry** — add `ollama_cloud` to `config/ai.php`, document `OLLAMA_CLOUD_API_KEY`/`OLLAMA_CLOUD_BASE_URL` in `.env.example`. Verify (manually, e.g. via `php artisan tinker`) that `config('ai.providers.ollama_cloud')` resolves and that `ManageAiSettings::providerOptions()` now lists "Ollama Cloud" once the key env var is set locally.
2. **Live model listing** — extend `ProviderModelCatalog::fetch()` with the `ollama_cloud` arm (Q3). Verify against the real `https://ollama.com/api/tags` endpoint with a real key (confirm exact field names before committing to the normalization shape) — this determines whether step 3's health-probe endpoint should mirror the OpenAI pattern or the native Ollama pattern. Verify in the sysadmin UI: selecting "Ollama Cloud" as a row's provider populates the Model `Select` with real `-cloud`-suffixed tags.
3. **Health check parity** — extend `ChatProviderCheck::probe()` with the matching `ollama_cloud` arm (Q4 #1), using whichever endpoint style step 2 settled on. This must land in the same phase, before any `ollama_cloud` model is ever saved+probed in a real (non-local) environment, or the health dashboard goes red on first use.
4. **First catalog row + verification** — in the sysadmin Model Catalog page, add a new row: provider "Ollama Cloud", pick a live-listed model, fill in plan/pricing, save. This exercises `ModelProbe` (unmodified — it already works generically off `provider`/`model` strings) end-to-end and writes a `Measurement` into `ChatSettings`. Verify: the row shows the green "Verified" badge, the model appears in the chat picker for an allowed plan, and a real chat turn against it succeeds (per the project's chat-verification rule: Horizon + Redis queue + Reverb, not sync queue).
5. **(Optional, same PR or deferred)** provider icon in `_model-state.blade.php` (Q4 #2) — cosmetic, no functional dependency on anything above.

Dependencies are strictly linear: config (1) must exist before live listing (2) can resolve a key; live listing (2) determines the endpoint shape health-check (3) should mirror; the sysadmin UI (4) depends on both 1 and 2 to render provider/model options at all; the health check (3) must exist before 4 produces a servable model, or the health dashboard breaks the moment that happens.

## Sources

- `vendor/laravel/ai/src/AiManager.php` (installed package source, read directly — connection resolution/caching mechanics)
- `vendor/laravel/ai/src/Providers/Provider.php`, `Providers/OllamaProvider.php`, `Gateway/Ollama/OllamaGateway.php`, `Gateway/Ollama/Concerns/CreatesOllamaClient.php` (installed package source, read directly — per-instance config isolation)
- `vendor/laravel/ai/src/Enums/Lab.php` (installed package source, read directly — confirms no cloud-variant case exists or is needed)
- Project files: `config/ai.php`, `.env.example`, `packages/Chat/src/Services/ModelRegistry.php`, `packages/Chat/src/Services/ProviderModelCatalog.php`, `packages/Chat/src/Services/ModelProbe.php`, `packages/Chat/src/Support/CatalogEntry.php`, `packages/Chat/src/Support/ModelDescriptor.php`, `packages/SystemAdmin/src/Filament/Pages/Settings/ManageAiSettings.php`, `app/Health/ChatProviderCheck.php`, `packages/Chat/resources/views/livewire/chat/partials/_model-state.blade.php` (all read directly)
- [Ollama Cloud docs](https://docs.ollama.com/cloud) — base URL `https://ollama.com`, `Authorization: Bearer $OLLAMA_API_KEY`, `GET /api/tags` for listing (MEDIUM confidence, official docs, not verified via live call this session)
- [Ollama Cloud models blog post](https://ollama.com/blog/cloud-models) — `-cloud` tag suffix convention, OpenAI-compatible API access for cloud models (MEDIUM confidence, same caveat)
- [Ollama `/api/tags` reference](https://docs.ollama.com/api/tags) — native response shape reference (fields not fully confirmed for the cloud-hosted variant; recommend a live `curl` check during build, step 2 above)

---
*Architecture research for: Ollama Cloud provider integration (Relaticle v1.0 milestone)*
*Researched: 2026-09-02*

# Stack Research

**Domain:** Ollama Cloud AI provider integration (adding a hosted-inference provider to `laravel/ai`'s existing Ollama gateway)
**Researched:** 2026-09-02
**Confidence:** HIGH (base URL, auth, and `/api/tags` behavior verified with live unauthenticated `curl` against `https://ollama.com` today, plus cross-checked against official `docs.ollama.com` and two independent third-party integration writeups)

## Recommended Stack

### Core Technologies

| Technology | Version | Purpose | Why Recommended |
|------------|---------|---------|-----------------|
| `laravel/ai` `OllamaProvider` / `OllamaGateway` (existing, unmodified) | v0.11.0 (already vendored) | Drives all Ollama Cloud text/chat/embedding calls | The gateway already builds a Bearer-auth HTTP client whenever `providerCredentials()['key']` is non-empty (`CreatesOllamaClient::client()`), and reads its base URL purely from `additionalConfiguration()['url']` (`CreatesOllamaClient::baseUrl()`). Ollama Cloud's native API is byte-for-byte the same request/response shape as local Ollama's `/api/chat`, `/api/generate`, `/api/embed` — it is the same Ollama server software, just hosted. No new driver, gateway, or provider class is needed. |
| Ollama Cloud native REST API | Current (`docs.ollama.com`, verified live 2026-09-02) | Chat/generate/embed inference | Same endpoint paths as local Ollama (`/api/chat`, `/api/generate`, `/api/embed`), just served from `https://ollama.com` instead of `http://localhost:11434`, and gated by a Bearer API key instead of being open. This is exactly what `OllamaGateway` already calls (`$this->client($provider, $timeout)->post('api/chat', $body)` etc.) — zero code changes required in the gateway itself. |

### Configuration (not a library — the actual integration surface)

| Item | Value | Notes |
|------|-------|-------|
| Base URL | `https://ollama.com/api` | Confirmed by `docs.ollama.com/cloud` and `docs.ollama.com/api/authentication`, and live-tested: `curl https://ollama.com/api/tags` returns 200 with the full cloud model catalog. `OllamaGateway` calls relative paths like `api/chat`, so the config `url` value must be `https://ollama.com` (no trailing `/api` — the gateway's `post('api/chat', ...)` already prepends `api/`). Compare to the existing local entry, which sets `url` to `http://localhost:11434` for the same reason. |
| Auth header | `Authorization: Bearer <OLLAMA_API_KEY>` | Identical to what `CreatesOllamaClient::client()` already does today (`filled($key) ? ['Authorization' => 'Bearer '.$key] : []`). Live-verified: an unauthenticated `POST https://ollama.com/api/generate` returns `401 {"error":"Unauthorized"}`; the same request with a valid Bearer key succeeds. |
| API key source | `https://ollama.com/settings/keys` (requires a signed-in ollama.com account) | User-facing: document this as the "where do I get a key" instruction for `OLLAMA_CLOUD_API_KEY`. There is no separate "Cloud" toggle on the key itself — any ollama.com API key works against the cloud endpoints, scoped by the account's plan (Free/Pro/Max). |
| Models-listing endpoint | `GET https://ollama.com/api/tags` (native) | Live-verified 2026-09-02, **unauthenticated**, HTTP 200, returns the full hosted catalog (19 models at time of testing: `gpt-oss:120b`, `gpt-oss:20b`, `qwen3.5:397b`, `kimi-k3`, `kimi-k2.6`, `glm-5.1`/`5.2`/`5.3`, `deepseek-v4-flash:0731`, `deepseek-v4-pro:0813`, `minimax-m2.7`/`m3`, `mistral-large-3:675b`, `nemotron-3-nano:30b`/`super`/`ultra`, `gemma4:31b`, etc). Response shape is **identical** to local `/api/tags`: `{"models":[{"name","model","modified_at","size","digest","details":{"format","family","families","parameter_size","quantization_level"}}]}`. For cloud rows, `details.*` and sometimes `size` come back empty/`0` (no local file exists to introspect), so treat `size` as "may be 0, don't assume it's populated" and don't rely on `details.parameter_size` — parse it out of the model name suffix instead where present (`:120b`, `:397b`, etc. are informal, not guaranteed for every model, e.g. `kimi-k3` has no size suffix). |
| Alternate OpenAI-compatible listing endpoint | `GET https://ollama.com/v1/models` | Live-verified 2026-09-02, also unauthenticated, HTTP 200. Response: `{"object":"list","data":[{"id","object":"model","created":<unix ts>,"owned_by":"ollama"}]}`. Leaner (no size/digest), and `created` is a Unix timestamp instead of ISO 8601. **Not recommended** as the catalog source here: it duplicates `/api/tags` with less data, and the existing `OllamaGateway` never talks to `/v1/*` (that's a separate OpenAI-shaped surface Ollama also exposes, unrelated to the driver already in use). Stick to `/api/tags` for consistency with the rest of the gateway. |

## Integration Assessment: Does `OllamaGateway` Work Unmodified Against Ollama Cloud?

**Yes, for inference.** `generateTextStep`, `generateStreamStep`, and `generateEmbeddings` all resolve their HTTP client through `CreatesOllamaClient::client()`, which already:
1. Reads `key` from `providerCredentials()` and sends it as `Authorization: Bearer <key>` when non-empty.
2. Reads `url` from `additionalConfiguration()` with a `localhost:11434` fallback, and calls relative paths (`api/chat`, `api/embed`) against it.

Ollama Cloud's native endpoints are the same paths (`/api/chat`, `/api/generate`, `/api/embed`), same request/response JSON shape, same SSE-style streaming format as local Ollama (both are the literal same server binary; Cloud is Ollama's own infra running that server behind auth). Pointing the `url` config at `https://ollama.com` and supplying a real `OLLAMA_CLOUD_API_KEY` is sufficient — **no gateway, provider, or driver code changes needed.**

**What genuinely is new work (not part of this file, flagged for the roadmap):**
- A `ollama_cloud` entry in `config/ai.php['providers']` reusing `'driver' => 'ollama'`, with its own `key` (`env('OLLAMA_CLOUD_API_KEY')`) and `url` (default `https://ollama.com`, not overridable the way local's is since Cloud has one canonical URL — env override for flexibility is still fine, just default it correctly).
- Wiring `ProviderModelCatalog` (or wherever the sysadmin Model Catalog page's model picker sources its list) to call `GET {base_url}/api/tags` for this specific provider and map `name`/`model` → id, `modified_at` → last-updated, and treat `size`/`details` as best-effort (frequently empty for Cloud rows, unlike local rows where they're always populated).
- No embeddings assumption: don't assume every Cloud model supports `/api/embed`. Community reports (OpenClaw provider docs) note Cloud API keys "may not authorize `/api/embed`" for some models/plans — verify per-model via `ModelProbe` rather than hardcoding embedding support.

## What NOT to Use

| Avoid | Why | Use Instead |
|-------|-----|-------------|
| A new `Laravel\Ai\Enums\Lab` case or new Gateway/Provider class for "Ollama Cloud" | The existing `Ollama` driver already handles Bearer auth and configurable base URL; a second driver would duplicate identical logic and diverge over time | Reuse `'driver' => 'ollama'` with a second `providers` entry (`ollama_cloud`) that differs only in `key` and `url` |
| `https://api.ollama.com` as the base URL | Does not exist / not the documented host — Ollama Cloud is served from `ollama.com` itself, not a separate `api.` subdomain | `https://ollama.com` |
| `/v1/chat/completions` + `/v1/models` (OpenAI-compatible surface) for this integration | Would require bypassing the existing `OllamaGateway` entirely (it never calls `/v1/*`) and reimplementing OpenAI-shaped parsing for no benefit, since the native `/api/*` surface already works with zero code changes | The native `/api/chat`, `/api/generate`, `/api/embed`, `/api/tags` the gateway already calls |
| Assuming `/api/tags` needs auth | It doesn't — live-verified as a public, unauthenticated catalog listing. Building a "must have a valid key before showing the catalog" UX gate in the sysadmin picker would be an unnecessary restriction not required by the API itself | Call `/api/tags` freely for catalog display; only require the key for actual inference / `ModelProbe` verification calls |
| Relying on `details.parameter_size` or `size` from `/api/tags` to size/label Cloud models in the catalog UI | Both fields are frequently empty (`""`) or `0` for Cloud rows (no local file to introspect), unlike local Ollama where they're always populated | Parse an approximate size from the model name's colon suffix where present (best-effort, not authoritative), and treat `modified_at` as the reliable field for "last updated" |

## Rate Limits / Operational Notes

| Concern | Detail | Source confidence |
|---------|--------|--------------------|
| Concurrency | Free tier: 1 concurrent cloud model; Pro ($20/mo): 3 concurrent; Max ($100/mo): 10 concurrent | MEDIUM (third-party pricing trackers, not the primary official docs page, but consistent across two independent sources) |
| Quota window | Usage resets on a 5-hour session window and a 7-day weekly window, metered by tokens (input/cached-input/output) per model rather than flat request-per-second limits | MEDIUM (no official numeric RPM/TPS ceiling published as of this research) |
| No numeric per-second rate limit documented | Ollama does not publish a standard `X-RateLimit-*` header contract for Cloud in public docs | LOW — plan for `429`/`403` handling defensively (`HandlesFailoverErrors`, already used by `OllamaGateway`, should cover this without new code) rather than hardcoding a specific limit |
| Streaming format | Identical to local Ollama's newline-delimited JSON chunks over `/api/chat` with `stream: true` — no format differences found or reported by any source consulted | HIGH (matches official docs; `OllamaGateway::processTextStream` should work unmodified) |
| Embeddings support gap | Some Cloud models/plans may not authorize `/api/embed` | MEDIUM (single third-party source; verify empirically via `ModelProbe` rather than trusting this claim blindly) |

## Version Compatibility

| Package A | Compatible With | Notes |
|-----------|------------------|-------|
| `laravel/ai` v0.11.0 `OllamaGateway`/`OllamaProvider` (unmodified) | Ollama Cloud native API, current as of 2026-09-02 | No version pin needed on the Cloud API side — Ollama documents its native API as "stable and backwards compatible." Re-verify `/api/tags` response shape (`details.*` fields) if `laravel/ai` is upgraded past v0.11.0, since a future version could start relying on fields currently empty for Cloud rows. |

## Sources

- `docs.ollama.com/cloud` — base URL, API key location, `:cloud` suffix explanation, model library link (HIGH, official)
- `docs.ollama.com/api/authentication` — Bearer auth confirmation, example curl (HIGH, official)
- `docs.ollama.com/api/tags` — native `/api/tags` response shape reference (HIGH, official, though it doesn't explicitly address cloud scope in prose — behavior confirmed live instead)
- `docs.ollama.com/api/openai-compatibility` — confirms `/v1/models`, `/v1/chat/completions` etc. exist as a separate surface, not used by the existing gateway (HIGH, official)
- `ollama.com/blog/cloud-models` — example cloud model names, `ollama signin` flow, `:cloud` suffix for local-CLI-mediated access vs. direct API access (HIGH, official)
- Live `curl https://ollama.com/api/tags` and `curl https://ollama.com/v1/models` (unauthenticated) run 2026-09-02 — confirmed both endpoints are public, confirmed exact JSON shape and live model catalog (HIGH, first-party verification against production)
- Live `curl -d ... https://ollama.com/api/generate` (unauthenticated) run 2026-09-02 — confirmed `401 {"error":"Unauthorized"}` without a Bearer key (HIGH, first-party verification)
- `fabiorehm.com/blog/2026/04/12/pi-ollama-cloud-api` — third-party integration writeup, cross-checked `/v1/models` behavior and pricing tier framing (MEDIUM, independent/community but consistent with official docs)
- `docs.openclaw.ai/providers/ollama-cloud` — third-party provider integration doc, source of the `/api/embed` authorization caveat and "avoid `/v1` for native use" guidance (MEDIUM, independent/community)
- Third-party pricing/rate-limit aggregators (`ollamatps.com/limits`, `ollamatps.com/pricing`, `dev.to` writeup) — concurrency and quota-window figures (MEDIUM/LOW — no official numeric rate-limit page found; treat these as directional, not contractual)

---
*Stack research for: Ollama Cloud AI provider integration*
*Researched: 2026-09-02*

# Phase 01 API Coverage Matrix: Ollama Cloud

**Produced:** 2026-09-02 (plan time, not seal time)
**External API:** Ollama Cloud, `https://ollama.com`, Bearer-token auth
**Driver:** `laravel/ai` v0.11.0 `OllamaProvider` / `OllamaGateway`, unmodified

## Detector note

The deterministic detector returned `detected: false` when run against the pre-plan scope (the
ROADMAP phase section alone, before any PLAN.md existed). That verdict is not credible for this
phase: it manifestly integrates a third-party inference API over HTTP. The matrix is produced
anyway, both because the phase genuinely warrants one and because the seal-time gate re-runs the
detector against the written PLAN.md files, where the signal will be present.

## Capability surface

Enumerated from `.planning/research/STACK.md` (live `curl` verification, 2026-09-02) and
`docs.ollama.com/api/openai-compatibility`. Every capability starts as `INTEGRATE`; this table is
the subtraction record, and every `OPT-OUT` carries a reason.

| capability | decision | reason |
|---|---|---|
| `POST /api/chat` (native chat, streaming and non-streaming) | INTEGRATE | The chat turn itself. `OllamaGateway::generateTextStep()` and `generateStreamStep()` already post here; OLLAMA-06 proves it end to end. |
| `GET /v1/models` (OpenAI-compatible listing) | INTEGRATE | OLLAMA-03. The source for the sysadmin model picker, via a new `ProviderModelCatalog::fetch()` arm. |
| `GET /v1/models/{id}` (OpenAI-compatible retrieve) | INTEGRATE | OLLAMA-05. The health probe path, via a new `ChatProviderCheck::probe()` arm. Its availability is flagged assumption A-01 and is confirmed by observation in plan 01. |
| `POST /api/generate` (native single-turn completion) | OPT-OUT | Not needed. Every Relaticle AI surface goes through the chat/messages path; the driver exposes `generate` but no caller in this codebase reaches it. |
| `POST /api/embed` (native embeddings) | OPT-OUT | Not needed for this milestone. `config/ai.php` routes embeddings to `openai` via `default_for_embeddings`, and research flagged that Cloud keys may not authorize this endpoint on every plan. Revisit only if embeddings are ever moved to this provider. |
| `GET /api/tags` (native listing) | OPT-OUT | Superseded within this integration. The OpenAI-compatible `/v1/models` is used instead so `ProviderModelCatalog` and `ChatProviderCheck` reuse the existing `openai` arm shape rather than adding a second parser. Both endpoints were live-verified and return the same catalog; this is a choice between two equivalent sources, not a dropped capability. |
| `GET /api/show` (model capability metadata) | OPT-OUT | Explicitly out of scope in REQUIREMENTS.md. It duplicates what `ModelProbe` already measures with a real request, and that gate exists specifically to never trust a claimed capability. |
| `POST /v1/chat/completions` (OpenAI-compatible chat) | OPT-OUT | Not needed. The vendored `OllamaGateway` posts to the native `/api/chat` and never touches `/v1/*` for inference; routing chat through the OpenAI-compatible surface would mean bypassing the driver and reimplementing its parsing for no gain. |
| `POST /v1/embeddings` (OpenAI-compatible embeddings) | OPT-OUT | Not needed, for the same reason as `/api/embed`: embeddings are not served by this provider in this milestone. |
| `POST /api/pull`, `/api/push`, `/api/create`, `/api/copy`, `DELETE /api/delete` (model management) | OPT-OUT | Not applicable. These manage models on a local Ollama server; a hosted account has no local model store to mutate, and Relaticle never manages model artifacts. |
| `GET /api/ps` (running models) | OPT-OUT | Not needed. Relevant to a self-hosted server's loaded-model state, not to a hosted multi-tenant service. |
| Account and billing endpoints (plan tier, quota, concurrency) | OPT-OUT | No documented public API exists. The account's tier is a recorded assumption (D-02) and its real concurrency ceiling is measured empirically in plan 03 rather than queried. |

## Rollup

- Capabilities enumerated: 12
- INTEGRATE: 3
- OPT-OUT: 9, each with a stated reason
- Undecided: 0

## Baseline note

If a second integration is ever built against this same need (a different Ollama-compatible host,
or a second Ollama account), it starts from this same full-coverage baseline. Do not carry these
opt-outs over silently; re-decide each capability for the new surface.

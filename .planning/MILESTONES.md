# Milestones

## v1.0 Ollama Cloud Provider (Shipped: 2026-09-03)

**Phases completed:** 2 phases, 7 plans, 14 tasks

**Key accomplishments:**

- Wired `ollama_cloud` as a distinct AI provider through config, `ProviderModelCatalog` live listing, and `ChatProviderCheck` health probing, live-confirmed the corrected `gpt-oss:20b`/`gpt-oss:120b` tags and the single-model retrieve endpoint, and deliberately kept the write guard at `prompt` after proving `parallel_tool_calls` has nowhere to land on Ollama's native chat endpoint.
- Both Ollama Cloud models added, priced, plan-gated, and independently verified via a real ModelProbe request through the live sysadmin panel; the health-dashboard confirmation could not be located in this sandbox and is flagged for follow-up.
- Real multi-tool-call turns on both Ollama Cloud models measured well under the 120s timeout; the account's concurrency ceiling was found (rejections start at 2-3 concurrent turns) and confirmed to be misclassified by the app's rate-limit detector; a live browser walkthrough on gpt-oss:20b confirmed real streaming, single- and two-step proposal cards, and clean rejection, but surfaced three real defects (dropped fields, broken turn-continuation, one incomplete step) that are left unfixed for triage.
- Closed OLLAMA-05's boot-order gap: `HealthServiceProvider::boot()` now registers `Health::checks()` inside `$this->app->booted(...)`, so the Ollama Cloud chat provider check reads the live, settings-overlaid catalog instead of the frozen fresh-install seed, and a full-application-boot regression test pins the fix.
- Added the missing `reverb` service to self-hoster-facing `compose.yml`, wired both Reverb address families (internal plain-HTTP push vs. browser-facing socket) onto `app`/`horizon`/`scheduler`, and pinned the addressing contract with two new tests against `config/broadcasting.php`.
- Documented the reverb container, its three required secrets, client-facing addressing, and WebSocket reverse-proxy routing in the public self-hosting guide, then re-verified the whole phase end to end against a rebuilt six-service dev stack and the full CI-equivalent lint/type/coverage gate.

---

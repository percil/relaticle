---
gsd_state_version: 1.0
milestone: v1.0
status: Awaiting next milestone
stopped_at: Phase 02 complete — all phases complete
last_updated: "2026-09-03T11:51:10.351Z"
last_activity: 2026-09-03
last_activity_desc: Milestone v1.0 completed and archived
state_head: 235c30141af89fe6b13c2710914e4637ca8b9a5c
progress:
  total_phases: 2
  completed_phases: 2
  total_plans: 7
  completed_plans: 7
milestone_name: Ollama Cloud Provider
current_phase: 02
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-09-03)

**Core value:** Sales/ops teams get reliable, tenant-isolated CRM data, with identical write behavior no matter which surface they use — UI, API, MCP, or chat.
**Current focus:** Milestone v1.0 complete — ready to close out via /gsd-complete-milestone

## Current Position

Phase: Milestone v1.0 complete
Plan: —
Status: Awaiting next milestone
Last activity: 2026-09-04 - Completed quick task 260904-fks: Reduce docker-publish.yml to Docker Hub-only, tag-triggered publish to percil/relaticle

## Performance Metrics

**Velocity:**

- Total plans completed: 7
- Average duration: —
- Total execution time: —

**By Phase:**

| Phase | Plans | Total | Avg/Plan |
|-------|-------|-------|----------|
| 01 | 4 | - | - |
| 02 | 3 | - | - |

**Recent Trend:**

- Last 5 plans: —
- Trend: —

*Updated after each plan completion*
**Per-Plan Metrics:**

| Plan | Duration | Tasks | Files |
|------|----------|-------|-------|
| Phase 01 P01 | 55min | 3 tasks | 10 files |
| Phase 01 P04 | 25min | 3 tasks | 2 files |
| Phase 02 P01 | 35min | 3 tasks | 7 files |
| Phase 02 P02 | 10min | 2 tasks | 2 files |
| Phase 02 P03 | ~20min | 2 tasks | 1 files |

## Accumulated Context

### Decisions

Decisions are logged in PROJECT.md Key Decisions table.
Recent decisions affecting current work:

- Roadmap: Milestone ships as a single phase. Config, live listing, and the health-check arm are strict prerequisites for each other; splitting them would ship a knowingly broken intermediate state.
- Roadmap: OLLAMA-06 (real chat turn on Horizon/Redis/Reverb) is Phase 1's verification gate, not separable work.
- Research: Use the OpenAI-compatible `/v1/models` endpoint for model listing so `ProviderModelCatalog` and `ChatProviderCheck` reuse the existing `openai` match-arm pattern. Confirm the response shape with a live call before mapping fields.
- [Phase 01]: 01-01: Ollama Cloud write guard fallback taken deliberately after live-verifying parallel_tool_calls is silently dropped by Ollama's native api/chat endpoint (unknown options-bag key, not a top-level hoisted param); write_guard stays prompt for ollama_cloud and self-hosted ollama, documented in CrmAssistant::providerOptions() and pinned by tests on both call-site keys.
- [Phase 01]: 01-01: Model tags confirmed live as gpt-oss:20b / gpt-oss:120b (no -cloud suffix); A-01 resolved (GET /v1/models/{id} returns 200), so ChatProviderCheck's health arm needed no workaround.
- [Phase 01]: [Phase 01]: 01-04: Deferred HealthServiceProvider::boot()'s Health::checks() registration into $this->app->booted(...) rather than reordering bootstrap/providers.php or moving ChatServiceProvider's settings overlay into register(), per the plan's pre-verified boot-order analysis.
- [Phase 01]: 01-04: OLLAMA-05 closed - health:check now registers and reports a real status for ollama_cloud; a full-application-boot regression test guards the boot-order invariant.
- [Phase 02]: 02-01: Renamed compose.dev.yml's Reverb host-port override from REVERB_PORT to DEV_REVERB_PORT to avoid a silent collision with this repo's own .env, which already sets REVERB_PORT=8080 for native/Herd dev and gets auto-loaded by docker compose for ${VAR} substitution.
- [Phase 02]: 02-01: .env.ci gains REVERB_APP_KEY=ci in place of the deleted VITE_REVERB_APP_KEY=ci, since the Echo bootstrap now reads the key via config('reverb.apps.apps.0.key') and pusher-js still needs a non-empty key to avoid failing the browser suite's assertNoJavaScriptErrors.
- [Phase 02]: [Phase 02]: 02-02: healthcheck-reverb binary confirmed present in ghcr.io/relaticle/relaticle:latest, resolving RESEARCH.md assumption A4; used directly with no TCP fallback.
- [Phase 02]: [Phase 02]: 02-03: Proxied the reverb container via a dedicated ws.* subdomain in all three reverse-proxy examples (Nginx, Caddy, Traefik) rather than a shared path, since Reverb's fixed /app/{key} path risks colliding with the CRM panel's own /app path-mode routing.
- [Phase 02]: [Phase 02]: 02-03: Added REVERB_APP_ID/KEY/SECRET to the Quick Start, Dokploy and Coolify env snippets (Rule 2) since compose.yml guards all three as required and the guide's own onboarding paths would otherwise fail to start.

### Pending Todos

[From .planning/todos/pending/ — ideas captured during sessions]

None yet.

### Blockers/Concerns

- Open build-time questions carried from research: exact `/v1/models` response shape, Ollama Cloud concurrency tier and `isRateLimited()` classification, real multi-tool-call latency on 120B+ models vs the 120s timeout, and whether Ollama's OpenAI-compatible layer supports `parallel_tool_calls: false` (affects the write guard, and changing it after probing forces a re-probe).
- `packages/SystemAdmin` is excluded from PHPStan. Any enum gaining an `ollama_cloud` case needs a manual sweep of SystemAdmin `match` expressions.
- Config collision risk: `ollama_cloud` must stay strictly distinct from the existing self-hosted `ollama` key, or paid models silently become free and unplan-gated.
- [Phase 2] Minor: the `reverb` container logged one QueryException on cold boot during UAT (its `reverb:restart` cache check hit sqlite before postgres/redis were ready), self-healed, and reported healthy. Not reproduced as a recurring failure; worth a follow-up look if it happens reliably.

### Quick Tasks Completed

| # | Description | Date | Commit | Directory |
|---|-------------|------|--------|-----------|
| 260904-cyu | Purge Relaticle marketing/vitrine surface (fork purge + AGPL compliance) | 2026-09-04 | c02de7ef | [260904-cyu-purge-relaticle-marketing-vitrine-surfac](./quick/260904-cyu-purge-relaticle-marketing-vitrine-surfac/) |
| 260904-fks | Reduce docker-publish.yml to Docker Hub-only, tag-triggered publish to percil/relaticle | 2026-09-04 | 5cf1e560 | [260904-fks-update-github-workflows-docker-publish-y](./quick/260904-fks-update-github-workflows-docker-publish-y/) |

### Roadmap Evolution

- Phase 2 added: Docker Compose Orchestration
- Phase 2 edited: edited fields: title, goal (cleaned up verbatim multi-paragraph description)

## Deferred Items

Items acknowledged and deferred at milestone close, most recent first:

| Category | Item | Status | Deferred At | Milestone |
|----------|------|--------|-------------|-----------|
| todos | 2026-09-03-chat-post-approval-defects-no-success-message-broken-turn-co.md | (presence-only) | 2026-09-03 | v1.0 |

## Session Continuity

Last session: 2026-09-03T11:48:44Z
Stopped at: Milestone v1.0 complete — all phases (01, 02) done, ready for /gsd-complete-milestone
Resume file: None

## Operator Next Steps

- Start the next milestone with /gsd-new-milestone

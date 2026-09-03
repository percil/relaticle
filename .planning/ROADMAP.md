# Roadmap: Relaticle

**Current milestone:** v1.0 — Ollama Cloud Provider

## Overview

Ollama Cloud becomes a first-class metered AI provider in Relaticle, configured and managed
exactly like Anthropic, OpenAI, and Gemini. The work is a pure addition on top of the existing
`laravel/ai` `OllamaProvider`/`OllamaGateway`: a distinct `ollama_cloud` config entry, a live
model-listing arm so the sysadmin catalog's model picker stops being free text, and a matching
health-check arm so the AI service dashboard reports real status instead of failing on an
unhandled provider case. These three code points are strict prerequisites for each other and for
the first catalog row, so they ship as one phase, closed out by a real streaming tool-calling
chat turn on production-shaped infrastructure.

## Phases

**Phase Numbering:**

- Integer phases (1, 2, 3): Planned milestone work
- Decimal phases (2.1, 2.2): Urgent insertions (marked with INSERTED)

Decimal phases appear between their surrounding integers in numeric order.

- [x] **Phase 1: Ollama Cloud Provider Integration** - Ollama Cloud configured, catalog-managed with live model listing, health-checked, and proven on a real chat turn (completed 2026-09-03)
- [ ] **Phase 2: Docker Compose Orchestration** - Reverb runs as a real service in both compose files, its browser credentials arrive at request time instead of build time, and the dev stack builds this repo's own image with every third-party service

## Phase Details

### Phase 1: Ollama Cloud Provider Integration

**Goal**: Operators can run Relaticle's AI chat on Ollama Cloud models, managed through the sysadmin Model Catalog exactly like any other cloud provider
**Depends on**: Nothing (first phase)
**Requirements**: OLLAMA-01, OLLAMA-02, OLLAMA-03, OLLAMA-04, OLLAMA-05, OLLAMA-06
**Success Criteria** (what must be TRUE):

  1. With `OLLAMA_CLOUD_API_KEY` and its base URL set in `.env`, an operator sees "Ollama Cloud" as a selectable provider in the sysadmin AI Model Catalog, and the existing self-hosted local `ollama` entry is unchanged and still free/unmanaged
  2. Choosing Ollama Cloud populates the model picker from Ollama's own live model list, returning real published `-cloud`-suffixed tags with no free-text entry
  3. An operator can save an Ollama Cloud model with pricing, plan gating, and credit multiplier, and it shows the Verified badge once `ModelProbe` passes against a real request
  4. The AI service health dashboard reports Ollama Cloud's true status once a model is servable, with no false failure from an unhandled provider case
  5. A user completes a real chat turn on a verified Ollama Cloud model — streaming, tool calls, and proposal approve/reject — against Horizon, Redis, and Reverb

**Plans**: 4/4 plans executed
Plans:
**Wave 1**

- [x] 01-01-PLAN.md — Wire config, live listing, and the health probe end to end on one model; resolve the write guard; seed the catalog and picker icon (wave 1)

**Wave 2** *(blocked on Wave 1 completion)*

- [x] 01-02-PLAN.md — Add, price, plan-gate, and verify both Ollama Cloud rows in the live sysadmin catalog; confirm the health dashboard (wave 2)

**Wave 3** *(blocked on Wave 2 completion)*

- [x] 01-03-PLAN.md — Time a multi-tool-call turn, find the concurrency ceiling, and walk a real streaming propose-and-approve chat turn (wave 3)

**Wave 4** *(gap closure from 01-VERIFICATION.md)*

- [x] 01-04-PLAN.md — Defer chat provider health-check registration past the settings overlay so Ollama Cloud actually registers, and pin the boot-order invariant with a full-application-boot regression test (wave 4, OLLAMA-05)

**Build order**: config entry → live model listing → health-check arm → first verified catalog row → real chat turn. The health-check arm (`ChatProviderCheck::probe()`) is load-bearing and must not be deferred past the first catalog row, or the dashboard breaks on first use.

## Progress

**Execution Order:**
Phases execute in numeric order: 1, 2

| Phase | Plans Complete | Status | Completed |
|-------|----------------|--------|-----------|
| 1. Ollama Cloud Provider Integration | 4/4 | Complete    | 2026-09-03 |
| 2. Docker Compose Orchestration | 3/3 | In Progress|  |

## Notes

**Why one phase.** Splitting config, listing, and health check across phases would ship a
knowingly broken intermediate state: a catalog row with no dashboard status, or a provider
dropdown with no models, which is the free-text-typo risk this milestone exists to remove.
Requirement OLLAMA-06 (real chat turn on production-shaped infra) is the phase's verification
gate rather than separable work, per the project's standing rule that chat changes are only done
once walked end to end with Horizon, Redis, and Reverb running.

**Carried into planning from research** (`.planning/research/`):

- Prefer the OpenAI-compatible `/v1/models` endpoint over native `/api/tags` for listing, so both `ProviderModelCatalog::fetch()` and `ChatProviderCheck::probe()` reuse the existing `openai` match-arm pattern. Confirm the exact response shape with a live authenticated call before mapping fields.
- Keep `ollama_cloud` strictly distinct from `ollama` everywhere; reusing the key would silently grant paid models free, unplan-gated access.
- Leave `input_per_mtok`/`output_per_mtok` as `null` where they cannot be filled honestly (Ollama bills subscription credit pools, not per token); express real cost through `credit_multiplier`.
- Check the write-guard value: `CrmAssistant::providerOptions()` only knows `anthropic` and `openai`. If Ollama's OpenAI-compatible layer supports `parallel_tool_calls: false`, add the arm before probing, since changing it later forces a re-probe.
- Confirm the account's Ollama concurrency tier and that `isRateLimited()` classifies its error response correctly; keep large (120B+) models `auto: false` until a real multi-tool-call turn is timed well under the 120s timeout.
- `packages/SystemAdmin` is excluded from PHPStan. Any enum gaining an `ollama_cloud` case needs a manual sweep of SystemAdmin `match` expressions over that enum.

### Phase 2: Docker Compose Orchestration

**Goal:** Prepare orchestration to ease both local testing and production deployment. Local
tests/dev MUST build the image(s) and come with all the separate 3rd party services.
**Requirements**: None mapped in REQUIREMENTS.md (all `OLLAMA-01`..`OLLAMA-06` belong to Phase 1). Acceptance derives from the phase goal and from CONTEXT.md decisions D-01 through D-04.
**Depends on:** Phase 1
**Success Criteria** (what must be TRUE):

  1. A self-hoster who downloads `compose.yml` and runs `docker compose up -d` gets a running `reverb` container alongside app, horizon, scheduler, postgres and redis, and the public self-hosting guide documents it
  2. The browser receives its Reverb key, host, port and scheme from the server at request time, so the one published `ghcr.io/relaticle/relaticle` image works for every self-hoster's own domain and self-generated key (D-01)
  3. `docker compose -f compose.dev.yml up -d --build` builds this repository's own `Dockerfile` and starts postgres, redis, app, horizon, reverb and mailpit, with chat streaming and mail wired out of the box
  4. Server-side broadcasts from app and horizon reach the reverb container over plain HTTP on the internal network, never attempted over TLS
  5. Both compose files pin the same Postgres major version, 17 (D-04)
  6. Herd and native `composer run dev` remain the everyday inner loop; the containerized stack supplements them (D-03)

**Plans:** 3/3 plans executed
Plans:
**Wave 1**

- [x] 02-01-PLAN.md — Serve Reverb credentials at request time and rebuild `compose.dev.yml` around this repo's own Dockerfile, proven end to end on a live socket (wave 1, D-01/D-03/D-04)
- [x] 02-02-PLAN.md — Add the missing `reverb` sidecar to the self-hoster `compose.yml` and wire both Reverb address families without conflating them (wave 1, D-02/D-04)

**Wave 2** *(blocked on Wave 1 completion)*

- [x] 02-03-PLAN.md — Document the six-container stack, its three required secrets and the WebSocket proxy path, then verify the phase against a running stack (wave 2, D-02)

**Build order**: runtime credential injection proven on a live socket → production compose sidecar → docs and whole-phase verification. Plans 02-01 and 02-02 touch disjoint files and run in parallel; 02-03 depends on both because it documents what they actually produce.

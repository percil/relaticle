# Phase 1: Ollama Cloud Provider Integration - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-09-02
**Phase:** 1-Ollama Cloud Provider Integration
**Areas discussed:** Write guard investment, Account tier & auto-chain caution, Credit multiplier / pricing, Initial model selection

---

## Write guard investment

| Option | Description | Selected |
|--------|-------------|----------|
| Try to earn api guard (recommended) | Add an Lab::Ollama/'ollama_cloud' arm to CrmAssistant::providerOptions() sending parallel_tool_calls: false. Small effort, must happen before probing since re-probing is needed if changed later. | ✓ |
| Accept prompt guard, no extra wiring | Ship without a providerOptions() arm; rely entirely on the PendingAction approval gate. | |

**User's choice:** Try to earn api guard (recommended)
**Notes:** Must be wired before the first `ModelProbe` run — a passing probe is cached forever and never re-checked.

---

## Account tier & auto-chain caution

| Option | Description | Selected |
|--------|-------------|----------|
| Free | 1 concurrent request. Most conservative. | |
| Pro ($20/mo) | 3 concurrent requests. Some headroom. | ✓ |
| Max/Team ($100/mo) | 10 concurrent requests. Most headroom. | |
| Not decided yet / will confirm during build | Leave open, confirm against live account. | |

**User's choice:** Pro ($20/mo)

| Option | Description | Selected |
|--------|-------------|----------|
| All models start auto: false (recommended) | Nothing enters the Auto failover chain until timed on production-shaped infra and proven reliable. | ✓ |
| Only large (120B+) models start auto: false | Smaller/faster models can enter the auto chain immediately. | |

**User's choice:** All models start auto: false (recommended)
**Notes:** Applies uniformly regardless of model size, given the Pro tier's modest concurrency ceiling.

---

## Credit multiplier / pricing

| Option | Description | Selected |
|--------|-------------|----------|
| Match cheapest existing tier (1.0x) | Same multiplier as Sonnet 5 / Gemini 3 Flash. | |
| Per-model, scaled to size/capability | Small models 1.0x, large models 1.5-2x. | |
| Not decided yet / decide per-model at catalog-entry time | Left as an operator judgment call in the sysadmin panel. | ✓ |

**User's choice:** Not decided yet / decide per-model at catalog-entry time
**Notes:** `input_per_mtok`/`output_per_mtok` stay `null` regardless (already settled by research, not re-litigated).

---

## Initial model selection

| Option | Description | Selected |
|--------|-------------|----------|
| One small/fast model (gpt-oss:20b-cloud) | Simplest path to a verified end-to-end chat turn. | |
| One small + one large (gpt-oss:20b-cloud + gpt-oss:120b-cloud) | Proves both the fast path and the large-model timeout/latency question. | ✓ |
| A coding-focused model instead (qwen3-coder:480b-cloud) | Prioritizes a coding model over a general one. | |

**User's choice:** One small + one large (gpt-oss:20b-cloud + gpt-oss:120b-cloud)

---

## Claude's Discretion

- Exact `credit_multiplier` value per model — propose a starting value at build/verification time and confirm with the user.
- Endpoint choice for live listing (`/v1/models` vs `/api/tags`) — research-recommended `/v1/models`; confirm exact response shape with a live call during build.
- Wording/placement of the `write_guard` fallback note if `parallel_tool_calls: false` doesn't work as expected.

## Deferred Ideas

None — discussion stayed within phase scope.

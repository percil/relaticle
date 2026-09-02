# Requirements: Relaticle

**Defined:** 2026-09-02
**Core Value:** Sales/ops teams get reliable, tenant-isolated CRM data, with identical write behavior (authorization, tenant checks, side effects) no matter which surface they use — UI, API, MCP, or chat.

## v1 Requirements

Requirements for milestone v1.0 (Ollama Cloud Provider).

### AI Providers

- [x] **OLLAMA-01**: Ollama Cloud is configured via `.env` (API key + base URL), as a distinct provider from the existing self-hosted local Ollama
- [x] **OLLAMA-02**: Ollama Cloud appears as a selectable provider in the sysadmin AI Model Catalog page once its key is set
- [x] **OLLAMA-03**: The sysadmin model picker for Ollama Cloud is populated from Ollama's live model-listing endpoint, not free text
- [x] **OLLAMA-04**: An operator can add, price, plan-gate, and verify (`ModelProbe`) an Ollama Cloud model in the sysadmin catalog, the same as Anthropic/OpenAI
- [ ] **OLLAMA-05**: The AI service health dashboard correctly reports Ollama Cloud's status once configured (no false failures from an unhandled provider case)
- [ ] **OLLAMA-06**: A real chat turn (streaming + tool calls) succeeds against a verified Ollama Cloud model on production-shaped infrastructure (Horizon/Redis/Reverb)

## v2 Requirements

None identified — this milestone is scoped as a single, complete feature.

## Out of Scope

Explicitly excluded. Documented to prevent scope creep.

| Feature | Reason |
|---------|--------|
| Multi-tenant / per-workspace Ollama Cloud keys | Every provider in this app is a single install-level key; no signal Ollama Cloud needs to be different |
| Pre-flagging tool-calling support from Ollama's `/api/show` capabilities endpoint before save | Duplicates what `ModelProbe` already measures via a real request; that gate exists specifically to never trust a claimed capability (RELATICLE-CRM-6D) |
| A distinct "Ollama Cloud" vs "Ollama" dropdown label beyond the automatic fallback | `providerLabel()`'s existing fallback already renders "Ollama Cloud" for free; add a real override only if it proves confusing in practice |
| Folding Ollama Cloud into the existing self-hosted `ollama` config entry | That path is explicitly free, unmanaged, and excluded from `offered()` — this milestone requires a separate, catalog-managed cloud provider |

## Traceability

Which phases cover which requirements. Updated during roadmap creation.

| Requirement | Phase | Status |
|-------------|-------|--------|
| OLLAMA-01 | Phase 1 | Complete |
| OLLAMA-02 | Phase 1 | Complete |
| OLLAMA-03 | Phase 1 | Complete |
| OLLAMA-04 | Phase 1 | Complete |
| OLLAMA-05 | Phase 1 | Pending |
| OLLAMA-06 | Phase 1 | Pending |

**Coverage:**

- v1 requirements: 6 total
- Mapped to phases: 6
- Unmapped: 0 ✓

---
*Requirements defined: 2026-09-02*
*Last updated: 2026-09-02 after roadmap creation (all requirements mapped to Phase 1)*

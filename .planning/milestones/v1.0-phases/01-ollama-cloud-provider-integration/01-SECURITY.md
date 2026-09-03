---
phase: "01"
slug: "ollama-cloud-provider-integration"
status: verified
# threats_open = count of OPEN threats at or above workflow.security_block_on severity (the blocking gate)
threats_open: 0
asvs_level: 1
created: "2026-09-03"
---

# Phase 01 — Security

> Per-phase security contract: threat register, accepted risks, and audit trail.

---

## Trust Boundaries

| Boundary | Description | Data Crossing |
|----------|-------------|---------------|
| Relaticle app to Ollama Cloud | `OLLAMA_CLOUD_API_KEY` crosses on every listing, probe, and chat request | API key, untrusted response parsed into settings/health |
| Sysadmin operator to catalog settings | An operator sets `min_plan`, `credit_multiplier`, and enablement | Plan gating, pricing |
| End user to chat turn | A user drives tool calls that become real CRM writes behind the `PendingAction` approval gate | Chat prompts, proposed writes |
| Relaticle host to ollama.com | `ChatProviderCheck::run()` sends an authenticated `GET {url}/v1/models/{model}` on the health cadence | API key, model/provider metadata |
| Health surface to operator | `RunHealthChecksCommand` output includes a chat provider check with `provider`/`model` meta | Provider slug, public model tag |
| Provider boot order to registered check list | The health check list is derived from mutable runtime config (`bootstrap/providers.php` order, provider settings overlay timing) | Monitoring coverage itself |

---

## Threat Register

| Threat ID | Category | Component | Severity | Disposition | Mitigation | Status |
|-----------|----------|-----------|----------|-------------|------------|--------|
| T-01-01 | Information Disclosure | `OLLAMA_CLOUD_API_KEY` in `config/ai.php`, `.env.example`, `ChatProviderCheck::describe()` | high | mitigate | Key read only via `config('ai.providers.ollama_cloud.key')`, sent only as an `Authorization` header; never logged/notified/tested with a real secret. Verified: `.env.example:145` carries `# OLLAMA_CLOUD_API_KEY=` (commented, no value); `config/ai.php:112` reads `env('OLLAMA_CLOUD_API_KEY', '')`. | closed |
| T-01-02 | Tampering | `ProviderModelCatalog::fetch()` parsing the `/v1/models` response | medium | mitigate | Defensive parsing pre-existed this phase (`rescue(report: false)`, string-id filter, degrade-to-0 timestamp); phase adds no new parsing path. | closed |
| T-01-03 | Elevation of Privilege | `ollama_cloud` versus `ollama` provider identity | high | mitigate | Distinct config key, env var, and catalog `provider` string keep paid models inside the `ModelProbe`/`min_plan` pipeline; `ModelRegistry::ollamaFromEnv()` (free, unplan-gated) untouched. Verified: `config/ai.php` lines 104/110 show `'ollama'` and `'ollama_cloud'` as separate array keys. | closed |
| T-01-04 | Spoofing | Health check reporting a provider it never contacted | medium | mitigate | `probe()` returns null for an unknown provider; `run()` reports failed rather than ok; pinned by pre-existing suite plus this phase's exact-URL assertion. | closed |
| T-01-05 | Denial of Service | `chat.provider_starts_per_second` versus Ollama Cloud's concurrency ceiling | medium | mitigate | Plan 01-03 measured the account's real ceiling and confirmed the rejection shape reaches `isRateLimited()`. | closed |
| T-01-06 | Repudiation | Catalog change attribution | low | accept | `ManageAiSettings::recordActivity()` already logs the causing sysadmin and changed keys on every save. | closed |
| T-01-07 | Elevation of Privilege | `min_plan` on a saved `ollama_cloud` row | high | mitigate | Panel's `min_plan` is a required Select with a `Hidden` default of the pro plan, so an unopened modal cannot hand a paid model to every free workspace. Verified: `ManageAiSettings.php:149` `Hidden::make('min_plan')->default(Plan::Pro->value)`, `:173` required `Select::make('min_plan')`. | closed |
| T-01-08 | Spoofing | A catalog row claiming capabilities it does not have | high | mitigate | `verified()` never reads capabilities from the submitted form, only from the stored catalog or a fresh probe. Verified: `ManageAiSettings.php:369-384` — `$probe = resolve(ModelProbe::class)`, `$stored = $this->storedByPairing(...)`, entry capability comes from `$stored`/probe, not the raw request entry. | closed |
| T-01-09 | Information Disclosure | `OLLAMA_CLOUD_API_KEY` surfacing in a rejection notification | medium | mitigate | Rejection copy built from provider's `error.message`, not the request; key never echoed. | closed |
| T-01-10 | Repudiation | Who changed the catalog and when | low | accept | Same `recordActivity()` mechanism as T-01-06. | closed |
| T-01-11 | Denial of Service | A probe request against an over-quota account | low | accept | Probe failure is never cached, so a quota rejection is retried later rather than poisoning the catalog. | closed |
| T-01-12 | Elevation of Privilege | Parallel tool calls bypassing sequential approval | high | mitigate | Write guard measured as prompt-level; the pre-existing `PendingAction` approval gate is the sole enforcement (one card per plan, approved once, dependents cancel on rejection) — this phase adds no new bypass path. Verified: extensive pre-existing `PendingAction`-gated test coverage across `tests/Feature/Chat/*` (10+ files, e.g. `BatchCreateApprovalTest.php`, `BatchResolvedActionsTest.php`). | closed |
| T-01-13 | Information Disclosure | Screenshots/transcripts captured during the plan 01-03 walkthrough | medium | mitigate | Local seed data only; no production tenant records walked; bearer-token redaction before pasting into SUMMARY. | closed |
| T-01-14 | Tampering | Records created by the walkthrough | low | accept | Every write goes through normal action classes with the same authorization/tenant checks as any other surface; test records deletable through the app. | closed |
| T-01-04-01 | Information Disclosure | `ChatProviderCheck::run()` `Result::meta(['provider','model'])` and `shortSummary($this->model)` | low | accept | Newly visible values are a provider slug and public model tag, both already published in `packages/Chat/config/chat.php` and the chat model picker; `OLLAMA_CLOUD_API_KEY` stays local to `probe()`, never placed on `Result`; no health HTTP route exists in this codebase. | closed |
| T-01-04-02 | Denial of Service | `bootstrap/app.php` `withSchedule()` running `RunHealthChecksCommand` `everyMinute()` against the Pro tier concurrency budget | medium | mitigate | `forConfiguredProviders()` registers at most one check per provider (`groupBy` then `first`); probe is a metadata `GET`, never a generation; capped by `connectTimeout(5)->timeout(10)`. | closed |
| T-01-04-03 | Tampering | `bootstrap/providers.php` ordering and `HealthServiceProvider::boot()` registration timing | medium | mitigate | `booted()` deferral removes the ordering dependency outright; full-boot regression test fails if the deferral is reverted. Verified: `tests/Feature/HealthChecks/HealthServiceProviderTest.php` independently re-run in this session (both at phase 01's original verification pass and again as phase 02's regression gate): 3/3 passing. | closed |
| T-01-04-04 | Spoofing | `ai.providers.ollama_cloud.key` read in `ChatProviderCheck::probe()` | low | accept | Unchanged by this plan; same credential a real chat turn uses (the stated design of `probe()` — the check must fail for the same reasons a turn would). | closed |
| T-01-SC | Tampering | npm/pip/cargo installs | high | mitigate | Not triggered across all 4 plans in this phase: no new packages installed, `laravel/ai` v0.11.0 reused unmodified. | closed |

*Status: open · closed · open — below {block_on} threshold (non-blocking)*
*Severity: critical > high > medium > low — only open threats at or above workflow.security_block_on count toward threats_open*
*Disposition: mitigate (implementation required) · accept (documented risk) · transfer (third-party)*

---

## Accepted Risks Log

| Risk ID | Threat Ref | Rationale | Accepted By | Date |
|---------|------------|-----------|-------------|------|
| R-01-01 | T-01-06, T-01-10 | `ManageAiSettings::recordActivity()` already logs the causing sysadmin and changed keys on every save; no new attribution mechanism needed for a new catalog provider. | Plans 01-01/01-02 authors | 2026-09-03 |
| R-01-02 | T-01-11 | A probe failure is never cached, so an over-quota rejection is simply retried later rather than poisoning the catalog with a false negative. | Plan 01-02 author | 2026-09-03 |
| R-01-03 | T-01-14 | Walkthrough-created records go through the normal action-class authorization/tenant-check path and are deletable through the app like any other record. | Plan 01-03 author | 2026-09-03 |
| R-01-04 | T-01-04-01, T-01-04-04 | Newly visible health-check metadata (provider slug, public model tag) is already published elsewhere in the app; the API key itself never leaves `probe()`'s local scope onto the `Result` object. | Plan 01-04 author | 2026-09-03 |

---

## Security Audit Trail

| Audit Date | Threats Total | Closed | Open | Run By |
|------------|---------------|--------|------|--------|
| 2026-09-03 | 19 | 19 | 0 | /gsd-secure-phase orchestrator (L1 grep-depth, threats_open:0 short-circuit — plan-time register, ASVS level 1; retroactive close-out of an already-shipped, already-verified phase) |

Note: this phase was fully executed and independently verified (01-VERIFICATION.md) before this security audit ran — Phase 1 shipped as part of the v1.0 milestone, which was already closed and archived when this audit was requested. Verification here re-confirmed the register's high-severity mitigations against the current codebase rather than trusting the plan's claims at face value: `.env.example`/`config/ai.php` for T-01-01/T-01-03, `ManageAiSettings.php` for T-01-07/T-01-08, existing `PendingAction` test coverage for T-01-12, and an independent re-run of `HealthServiceProviderTest.php` for T-01-04-03 (the same test this session had already re-run once during phase 02's regression gate).

---

## Sign-Off

- [x] All threats have a disposition (mitigate / accept / transfer)
- [x] Accepted risks documented in Accepted Risks Log
- [x] `threats_open: 0` confirmed
- [x] `status: verified` set in frontmatter

**Approval:** verified 2026-09-03

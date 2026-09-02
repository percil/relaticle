---
phase: 01-ollama-cloud-provider-integration
reviewed: 2026-09-02T00:00:00Z
depth: standard
files_reviewed: 10
files_reviewed_list:
  - config/ai.php
  - .env.example
  - packages/Chat/src/Services/ProviderModelCatalog.php
  - app/Health/ChatProviderCheck.php
  - packages/Chat/src/Agents/CrmAssistant.php
  - packages/Chat/config/chat.php
  - packages/Chat/resources/views/livewire/chat/partials/_model-state.blade.php
  - tests/Feature/SystemAdmin/AiModelCatalogListingTest.php
  - tests/Feature/HealthChecks/ChatProviderCheckTest.php
  - tests/Feature/Chat/SequentialWriteEnforcementTest.php
findings:
  critical: 0
  warning: 2
  info: 1
  total: 3
status: issues_found
---

# Phase 01: Code Review Report

**Reviewed:** 2026-09-02T00:00:00Z
**Depth:** standard
**Files Reviewed:** 10
**Status:** issues_found

## Summary

This phase wires an `ollama_cloud` provider through `config/ai.php`, adds a listing
branch to `ProviderModelCatalog`, a health-probe branch to `ChatProviderCheck`, a
picker icon, two seed catalog entries in `chat.php`, and a documentation-only
comment in `CrmAssistant.php`. I traced the change through its actual consumers
(`ModelDescriptor::isAvailable()`, `ModelRegistry::ratesFor()`,
`CreditService::calculateCredits()`, `AiSpendStatsWidget`) rather than just the
files touched, to check for downstream breakage from the two new catalog rows
(`capabilities: null`, `input_per_mtok`/`output_per_mtok: null`). Both null cases
are handled defensively by existing code (`Measurement::fromEntry()` returns
`null` on a missing capabilities array; `AiSpendStatsWidget` buckets a `null` rate
into an "unpriced models" list instead of dividing by it), and `CreditService`
never touches per-token rates at all (subscription billing uses only
`multiplierFor()`). The three new URL-construction and health-probe branches
(`ProviderModelCatalog::fetch()`, `ChatProviderCheck::probe()`) are internally
consistent with each other and match their tests exactly.

No blocking defects found. Two warnings on documentation accuracy and a
copy-paste footgun in the base-URL convention, plus one informational note on
the deliberately-unmeasured seed state of the two new models.

## Warnings

### WR-01: .env.example's blanket "models only need a configured provider" claim is false for the two new Ollama Cloud entries

**File:** `.env.example:131-146`
**Issue:** Line 133 states, for the whole AI chat section: "Models only appear in
the chat picker when their provider is configured." That is true for every
pre-existing catalog entry (Sonnet, GPT, Gemini), whose `chat.php` rows already
carry a real `capabilities` array. It is not true for the two Ollama Cloud rows
added in this phase (`gpt-oss:20b`, `gpt-oss:120b`), whose `capabilities` is
seeded as `null` (`packages/Chat/config/chat.php:223-224`). Per
`CatalogEntry::isServable()` and `ModelDescriptor::isAvailable()`, a `null`
measurement means `supportsTools` is `false`, so the model stays out of the
picker even after `OLLAMA_CLOUD_API_KEY` is set, until a sysadmin opens Manage
AI Settings and saves (triggering `CatalogEntry::needsProbe()` → a live measure).
An operator who follows the new comment block at lines 143-146, sets the two env
vars, and reads line 133's general claim will reasonably expect the models to
show up immediately; they will not, with nothing in this diff explaining why.
**Fix:** Add a line to the new comment block clarifying the extra step, e.g.:
```
# Ollama Cloud, the managed subscription-billed service. Distinct from the
# self-hosted Ollama variables above; never merge the two. After setting the
# key, a sysadmin must open Settings -> AI -> Manage Models and save once to
# probe tool-calling support before these models appear in the picker.
# OLLAMA_CLOUD_API_KEY=
# OLLAMA_CLOUD_BASE_URL=https://ollama.com
```

### WR-02: Inconsistent base-URL convention between OPENAI_URL and OLLAMA_CLOUD_BASE_URL invites a silent double `/v1` path

**File:** `packages/Chat/src/Services/ProviderModelCatalog.php:86-87`, `app/Health/ChatProviderCheck.php:104-105`
**Issue:** `ai.providers.openai.url` (and its self-hosted-compatible siblings)
is documented and defaulted to already include the `/v1` suffix
(`config/ai.php:125`: `'https://api.openai.com/v1'`), and both files append the
path segment directly (`.'/models'`). `ai.providers.ollama_cloud.url` is
defaulted WITHOUT `/v1` (`config/ai.php:113`: `'https://ollama.com'`), and both
files manually append `.'/v1/models'` / `.'/v1'` before the request path. This
is internally consistent and passes the tests as written, but an operator who
overrides `OLLAMA_CLOUD_BASE_URL` by copying the adjacent `OPENAI_URL` /
`SELF_HOSTED_AI_URL` convention (both of which already include the version
suffix) will end up with a URL like
`https://gateway.internal/ollama-cloud/v1/v1/models/gpt-oss:20b`. The failure
mode is a silent 404 from the health check (`ChatProviderCheck::run()` reports
"model unavailable", not "bad base URL") and an empty catalog listing with no
diagnostic pointing at the real cause — a support burden for a self-hostable
open-source project where operators frequently set these values by hand.
**Fix:** Either strip a trailing `/v1` defensively before appending it, or (less
code, more explicit) require the full `/v1`-suffixed URL for `OLLAMA_CLOUD_BASE_URL`
too, matching the OpenAI-family convention already used two env vars below it:
```php
'ollama_cloud' => $this->client()->withToken($key)
    ->get(rtrim((string) config('ai.providers.ollama_cloud.url', 'https://ollama.com/v1'), '/').'/models'),
```
and drop the manually-appended `/v1` in `ChatProviderCheck::probe()` to match.

## Info

### IN-01: Both new Ollama Cloud catalog entries ship with `capabilities: null`, unlike every other enabled entry

**File:** `packages/Chat/config/chat.php:223-224`
**Issue:** Every other `enabled: true` row in this array (Sonnet 5, GPT 5.5,
Opus 5, GPT 5.4, both Gemini models) carries a pre-filled `capabilities` array.
The two new Ollama Cloud rows are the first enabled entries to ship with
`capabilities: null`, meaning they are unservable (excluded from the picker,
excluded from the Auto chain, excluded from `ChatProviderCheck` health
monitoring) until someone measures them via the sysadmin panel's probe flow.
Git history for this phase (`01-02: complete ollama cloud catalog verification
plan`) indicates this is an intentional, staged rollout rather than an oversight
— flagging for visibility only, since it is easy for a future reader of
`chat.php` alone (without the phase history) to mistake this for a bug rather
than a deliberate two-phase design.
**Fix:** No code change needed; consider a one-line comment next to the two rows
noting that capabilities are populated by a later verification pass rather than
seeded here (the existing comment only explains the null pricing, not the null
capabilities).

---

_Reviewed: 2026-09-02T00:00:00Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_

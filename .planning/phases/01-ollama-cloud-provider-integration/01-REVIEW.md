---
phase: 01-ollama-cloud-provider-integration
reviewed: 2026-09-03T00:00:00Z
depth: standard
files_reviewed: 12
files_reviewed_list:
  - .env.example
  - app/Health/ChatProviderCheck.php
  - app/Providers/HealthServiceProvider.php
  - config/ai.php
  - packages/Chat/config/chat.php
  - packages/Chat/resources/views/livewire/chat/partials/_model-state.blade.php
  - packages/Chat/src/Agents/CrmAssistant.php
  - packages/Chat/src/Services/ProviderModelCatalog.php
  - tests/Feature/Chat/SequentialWriteEnforcementTest.php
  - tests/Feature/HealthChecks/ChatProviderCheckTest.php
  - tests/Feature/HealthChecks/HealthServiceProviderTest.php
  - tests/Feature/SystemAdmin/AiModelCatalogListingTest.php
findings:
  critical: 0
  warning: 2
  info: 2
  total: 4
status: issues_found
---

# Phase 01: Code Review Report

**Reviewed:** 2026-09-03T00:00:00Z
**Depth:** standard
**Files Reviewed:** 12
**Status:** issues_found

## Summary

This review supersedes the 2026-09-02 partial review (10 of 12 files) and covers
the full current 12-file scope for Phase 01 (Ollama Cloud provider integration),
including the two files added by the 01-04 gap-closure plan:
`app/Providers/HealthServiceProvider.php` and
`tests/Feature/HealthChecks/HealthServiceProviderTest.php`.

I re-verified the diff against `dbc3b3cb622c9254680fa2c3e76ba24a537efb6e^` to
confirm exactly what changed: the `ollama_cloud` provider entry in `config/ai.php`,
a listing branch in `ProviderModelCatalog::fetch()`, a health-probe branch in
`ChatProviderCheck::probe()`, a picker icon in `_model-state.blade.php`, two seed
catalog rows in `chat.php`, a documentation-only comment in `CrmAssistant.php`,
and (new in this pass) `HealthServiceProvider` deferring `Health::checks()`
registration from `boot()` to `$this->app->booted()`.

**The boot-order fix is correct.** I traced the provider boot sequence in
`bootstrap/providers.php`: `HealthServiceProvider` (position 5) boots before
`ChatServiceProvider` (position 10), and `ChatServiceProvider::boot()` is where
the runtime-editable model catalog gets overlaid onto `config('chat.models')`
(`applyStoredSettings()`). Under the old eager registration, `HealthServiceProvider::boot()`
called `ChatProviderCheck::forConfiguredProviders()` before that overlay ran, so
it only ever saw the static seed in `packages/Chat/config/chat.php` — where the
`ollama_cloud` rows carry `capabilities: null` and are filtered out by
`CatalogEntry::isServable()`. Deferring to `$this->app->booted()` runs the
registration after every provider's `boot()` has completed (Laravel's
`Application::boot()` lifecycle: `booting` callbacks → each provider's `boot()`
→ `booted` callbacks), which makes the fix independent of provider list order.
The new `HealthServiceProviderTest` case boots a full second `Application`
instance through the real `bootstrap/app.php` entry point with a faked
`ChatSettings` overlay and asserts the `ollama_cloud` check registers — this is
a genuine regression test for the described bug (it would fail against the old
eager-registration code, since the fake overlay data only exists post-`boot()`).
`isEnabled()` still reads `config('app.health_checks_enabled')` eagerly in
`boot()`, which is safe: that key is a static `env()`-backed config value with no
runtime overlay anywhere in the codebase.

I found no new Critical or Warning-level defects in the two added files. The two
previously-flagged warnings and one info item from the 2026-09-02 review were
re-verified against the current file contents (line-for-line unchanged in the
relevant sections) and **still stand** — see below.

## Warnings

### WR-01: .env.example's blanket "models only need a configured provider" claim is still false for the two Ollama Cloud entries

**Status:** Still stands (unresolved by the 01-04 gap-closure plan; that plan
fixed *when* the health check registers, not the underlying `capabilities: null`
seed state that also gates the picker).

**File:** `.env.example:131-146`
**Issue:** Line 133 states, for the whole AI chat section: "Models only appear in
the chat picker when their provider is configured." That is true for every
pre-existing catalog entry (Sonnet, GPT, Gemini), whose `chat.php` rows already
carry a real `capabilities` array. It is still not true for the two Ollama Cloud
rows added in this phase (`gpt-oss:20b`, `gpt-oss:120b`), whose `capabilities` is
seeded as `null` (`packages/Chat/config/chat.php:223-224`). Per
`CatalogEntry::isServable()` (`packages/Chat/src/Support/CatalogEntry.php:129-135`)
and `ModelDescriptor::isAvailable()`, a `null` measurement means `supportsTools`
is `false`, so the model stays out of the picker even after
`OLLAMA_CLOUD_API_KEY` is set, until a sysadmin opens Manage AI Settings and
saves (triggering `CatalogEntry::needsProbe()` → a live measure). An operator
who follows the new comment block at lines 143-146, sets the two env vars, and
reads line 133's general claim will reasonably expect the models to show up
immediately; they will not, with nothing in this diff explaining why. This also
means the 01-04 boot-order fix for the health check does not, by itself, make
the Ollama Cloud check appear on a fresh install either: the seed's
`capabilities: null` still filters it out of `ChatProviderCheck::forConfiguredProviders()`
until the same manual probe step runs.
**Fix:** Add a line to the new comment block clarifying the extra step, e.g.:
```
# Ollama Cloud, the managed subscription-billed service. Distinct from the
# self-hosted Ollama variables above; never merge the two. After setting the
# key, a sysadmin must open Settings -> AI -> Manage Models and save once to
# probe tool-calling support before these models appear in the picker (and
# before the health check for this provider registers).
# OLLAMA_CLOUD_API_KEY=
# OLLAMA_CLOUD_BASE_URL=https://ollama.com
```

### WR-02: Inconsistent base-URL convention between OPENAI_URL and OLLAMA_CLOUD_BASE_URL still invites a silent double `/v1` path

**Status:** Still stands, unchanged since 2026-09-02.

**File:** `packages/Chat/src/Services/ProviderModelCatalog.php:86-87`, `app/Health/ChatProviderCheck.php:104-105`
**Issue:** `ai.providers.openai.url` (and its self-hosted-compatible siblings)
is documented and defaulted to already include the `/v1` suffix
(`config/ai.php:125`: `'https://api.openai.com/v1'`), and both files append the
path segment directly (`.'/models'`). `ai.providers.ollama_cloud.url` is
defaulted WITHOUT `/v1` (`config/ai.php:113`: `'https://ollama.com'`), and both
files manually append `.'/v1/models'` / `.'/v1'` before the request path. This
is internally consistent and passes the tests as written (including the two new
override tests added in this pass), but an operator who overrides
`OLLAMA_CLOUD_BASE_URL` by copying the adjacent `OPENAI_URL` /
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

### IN-01: Both new Ollama Cloud catalog entries still ship with `capabilities: null`, unlike every other enabled entry

**Status:** Still stands, unchanged since 2026-09-02.

**File:** `packages/Chat/config/chat.php:223-224`
**Issue:** Every other `enabled: true` row in this array (Sonnet 5, GPT 5.5,
Opus 5, GPT 5.4, both Gemini models) carries a pre-filled `capabilities` array.
The two Ollama Cloud rows are the first enabled entries to ship with
`capabilities: null`, meaning they are unservable (excluded from the picker,
excluded from the Auto chain, excluded from `ChatProviderCheck` health
monitoring) until someone measures them via the sysadmin panel's probe flow.
This is corroborated as an intentional, staged rollout by the 01-04
gap-closure plan's own commentary in `HealthServiceProvider.php` ("a row whose
capabilities are measured later by ModelProbe still reads null and is dropped
by `CatalogEntry::isServable()`. Ollama Cloud is the first provider seeded that
way") — flagging for visibility only, since a future reader of `chat.php` alone
could still mistake this for a bug rather than a deliberate two-phase design.
**Fix:** No code change needed; consider a one-line comment next to the two rows
noting that capabilities are populated by a later verification pass rather than
seeded here (the existing comment only explains the null pricing, not the null
capabilities).

### IN-02: New boot-order regression test swaps global container/facade/Eloquent state mid-suite

**File:** `tests/Feature/HealthChecks/HealthServiceProviderTest.php:50-105`
**Issue:** The new `'registers a chat provider check for a model that only the
runtime settings overlay makes servable'` test boots an entirely second
`Illuminate\Foundation\Application` instance via `require base_path('bootstrap/app.php')`
mid-test, which implicitly replaces the global container instance (`Application::__construct()`
calls `static::setInstance($this)`), then explicitly restores
`Container::setInstance()`, `Facade`, and `Model`'s static connection
resolver/event dispatcher in a `finally` block. This is a deliberate, and
functionally necessary, way to reproduce a full provider-boot-order bug that a
hand-constructed `HealthServiceProvider` instance (as the two adjacent tests do)
cannot catch, and the `finally` block correctly runs on both success and
exception, so the restoration is not itself unsound. It is nonetheless a fragile
pattern: any global static state touched by the second boot cycle that is *not*
one of the four things restored (e.g., anything else relying on
`Illuminate\Support\Facades\Date`, cached view compilation state, or a real DB
connection opened during that second `Kernel::bootstrap()` outside the outer
test's transaction) would leak into subsequent tests in the same process and
manifest as an unrelated, hard-to-diagnose failure elsewhere in the suite. Not a
production defect, and not asserted to be currently causing flakiness, but worth
flagging per the "affects test reliability" carve-out, given how much implicit
global state this file's approach depends on getting fully enumerated and kept
in sync if more providers add similar global side effects in the future.
**Fix:** No immediate action required. If this pattern is reused elsewhere,
consider extracting the "boot a fresh app, swap it in, restore in `finally`"
sequence into a shared test helper so the restoration list has one place to stay
correct as new global state is introduced by future providers.

---

_Reviewed: 2026-09-03T00:00:00Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_

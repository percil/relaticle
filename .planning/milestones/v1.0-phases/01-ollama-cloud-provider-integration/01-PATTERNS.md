# Phase 1: Ollama Cloud Provider Integration - Pattern Map

**Mapped:** 2026-09-02
**Files analyzed:** 5
**Analogs found:** 5 / 5

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|---|---|---|---|---|
| `config/ai.php` (new `ollama_cloud` block) | config | request-response | `'openai'` / `'selfhosted'` blocks in same file (lines 104-121) | exact |
| `.env.example` (new `OLLAMA_CLOUD_*` vars) | config | n/a | existing `ollama` self-hosted block (lines 138-141) | exact |
| `packages/Chat/config/chat.php` (`'ollama_cloud'` catalog rows + provider array key, if added) | config | CRUD (seed data) | `'ollama'`/`'self_hosted'` blocks (lines 202-210) and `models` rows (lines 212-218) | exact |
| `packages/Chat/src/Services/ProviderModelCatalog.php::fetch()` | service | request-response (HTTP fetch + transform) | `'openai'` match arm (lines 84-85) in the same method | exact |
| `app/Health/ChatProviderCheck.php::probe()` | service | request-response (HTTP healthcheck) | `'openai'` match arm (lines 102-103) in the same method | exact |
| `packages/Chat/src/Agents/CrmAssistant.php::providerOptions()` | service | request-response (provider option builder) | `Lab::OpenAI->value` match arm (lines 647-649) in the same method | exact |

## Pattern Assignments

### `config/ai.php` (config)

**Analog:** same file, `'openai'` and `'selfhosted'` provider blocks (lines 104-121)

**Core pattern** (lines 104-121):
```php
'ollama' => [
    'driver' => 'ollama',
    'key' => env('OLLAMA_API_KEY', ''),
    'url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
],

'selfhosted' => [
    'driver' => 'openai',
    'key' => env('SELF_HOSTED_AI_KEY', ''),
    'url' => env('SELF_HOSTED_AI_URL'),
],

'openai' => [
    'driver' => 'openai',
    'key' => env('OPENAI_API_KEY'),
    'url' => env('OPENAI_URL', 'https://api.openai.com/v1'),
    'apps_challenge_token' => env('OPENAI_APPS_CHALLENGE_TOKEN'),
],
```

**What to copy:** the `ollama` block's shape (`driver` fixed to `'ollama'`, `key`/`url` from env with sane
defaults). New block must use a **distinct** key `ollama_cloud`, never reuse `'ollama'` (Pitfall 1 — see
`.planning/research/PITFALLS.md`). Suggested shape:
```php
'ollama_cloud' => [
    'driver' => 'ollama',
    'key' => env('OLLAMA_CLOUD_API_KEY', ''),
    'url' => env('OLLAMA_CLOUD_BASE_URL', 'https://ollama.com'),
],
```
Insert alphabetically to match the file's existing alpha-sorted provider list (between `mistral` and
`ollama`, or immediately after `ollama` — match existing sort order in the file exactly).

---

### `.env.example` (config)

**Analog:** existing `ollama` self-hosted comment block (lines 138-141)

**Core pattern** (lines 130-148):
```
# AI chat (laravel/ai). OpenAI is the default provider (config/ai.php);
# Anthropic powers the Claude models in the picker and the summary model.
# Models only appear in the chat picker when their provider is configured.
OPENAI_API_KEY=
OPENAI_APPS_CHALLENGE_TOKEN=
ANTHROPIC_API_KEY=

# Self-hosted AI via Ollama. Set OLLAMA_MODEL to a tool-calling-capable model
# tag (e.g. qwen3:14b) to add it to the chat model picker.
# OLLAMA_BASE_URL=http://localhost:11434
# OLLAMA_MODEL=

# Generic self-hosted / OpenAI-compatible endpoint (vLLM, LM Studio, LocalAI,
# or a gateway). Set the base URL and a comma-separated model list; each becomes
# a picker entry (id `selfhosted:<tag>`). Key is optional (blank for local servers).
# SELF_HOSTED_AI_URL=http://localhost:8000/v1
# SELF_HOSTED_AI_KEY=
# SELF_HOSTED_AI_MODELS=
```

**What to copy:** the comment-block-then-commented-vars convention. Add a new block distinct from the
self-hosted `OLLAMA_*` vars (do not reuse `OLLAMA_API_KEY`/`OLLAMA_BASE_URL`/`OLLAMA_MODEL`), e.g.:
```
# Ollama Cloud (managed, plan-based billing, distinct from self-hosted Ollama above).
# OLLAMA_CLOUD_API_KEY=
# OLLAMA_CLOUD_BASE_URL=https://ollama.com
```

---

### `packages/Chat/config/chat.php` (`models` catalog rows)

**Analog:** existing `models` array rows (lines 212-218), pricing/null convention from research

**Core pattern** (lines 212-218):
```php
'models' => [
    ['label' => 'Sonnet 5', 'provider' => 'anthropic', 'model' => 'claude-sonnet-5', 'min_plan' => 'free', 'credit_multiplier' => 1.0, 'input_per_mtok' => 3.00, 'output_per_mtok' => 15.00, 'auto' => true, 'enabled' => true, 'capabilities' => ['supports_tools' => true, 'write_guard' => 'api'], 'verified_at' => null],
    // ...
],
```

**What to copy:** row shape. Per D-04, `input_per_mtok`/`output_per_mtok` must be `null` (subscription
billing, not metered — same convention already used for self-hosted rows, which are excluded from this
array entirely and merged from env at read time per the file's own header comment, lines 184-187). Per
D-03, both new rows start `auto: false`. Example shape for the two D-06 rows:
```php
['label' => 'GPT-OSS 20B (Ollama Cloud)', 'provider' => 'ollama_cloud', 'model' => 'gpt-oss:20b-cloud', 'min_plan' => 'free', 'credit_multiplier' => 1.0, 'input_per_mtok' => null, 'output_per_mtok' => null, 'auto' => false, 'enabled' => true, 'capabilities' => null, 'verified_at' => null],
['label' => 'GPT-OSS 120B (Ollama Cloud)', 'provider' => 'ollama_cloud', 'model' => 'gpt-oss:120b-cloud', 'min_plan' => 'pro', 'credit_multiplier' => 1.5, 'input_per_mtok' => null, 'output_per_mtok' => null, 'auto' => false, 'enabled' => true, 'capabilities' => null, 'verified_at' => null],
```
`credit_multiplier` values above are placeholders — confirm with the user per D-05 before committing.
`capabilities` stays `null` until `ModelProbe` measures it (comment lines 192-194: "measured by ModelProbe
against a real request, never typed").

---

### `packages/Chat/src/Services/ProviderModelCatalog.php::fetch()` (service, request-response)

**Analog:** `'openai'` match arm, same method (lines 76-104)

**Imports pattern** (lines 1-10):
```php
<?php

declare(strict_types=1);

namespace Relaticle\Chat\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
```

**Core pattern to copy — `openai` match arm** (lines 76-104):
```php
private function fetch(string $provider): array
{
    $key = (string) config("ai.providers.{$provider}.key");

    $response = match ($provider) {
        'anthropic' => $this->client()
            ->withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])
            ->get('https://api.anthropic.com/v1/models', ['limit' => 100]),
        'openai' => $this->client()->withToken($key)
            ->get(rtrim((string) config('ai.providers.openai.url', 'https://api.openai.com/v1'), '/').'/models'),
        default => null,
    };

    if ($response === null) {
        return [];
    }

    /** @var array<int, array<string, mixed>> $rows */
    $rows = $response->throw()->json('data', []);

    return collect($rows)
        ->filter(fn (array $row): bool => is_string($row['id'] ?? null))
        ->mapWithKeys(fn (array $row): array => [(string) $row['id'] => [
            'label' => $this->displayName($row),
            'released_at' => $this->releasedAt($row),
        ]])
        ->sortByDesc(fn (array $row): int => $row['released_at'])
        ->all();
}
```

**What to copy:** add an `'ollama_cloud'` arm identical in shape to `'openai'`, pointed at the Ollama
Cloud base URL's `/v1/models` (OpenAI-compatible shape per research SUMMARY.md and STACK.md — confirm exact
field names with a live authenticated call during build, per CONTEXT.md Claude's Discretion):
```php
'ollama_cloud' => $this->client()->withToken($key)
    ->get(rtrim((string) config('ai.providers.ollama_cloud.url', 'https://ollama.com'), '/').'/v1/models'),
```
No changes needed to `displayName()`/`releasedAt()`/caching/error-handling — they are provider-agnostic
and already correctly used by the `openai` arm this new arm copies.

**Error handling pattern:** already generic (`rescue()` wrapper in `__invoke()`, lines 52-58) — no new
error handling needed, matches the file's own doc comment (lines 21-24: "must not throw into a form
render").

---

### `app/Health/ChatProviderCheck.php::probe()` (service, request-response healthcheck)

**Analog:** `'openai'` match arm, same method (lines 93-108)

**Imports pattern** (lines 1-16):
```php
<?php

declare(strict_types=1);

namespace App\Health;

use App\Enums\Plan;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Relaticle\Chat\Support\CatalogEntry;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;
```

**Core pattern to copy** (lines 93-108):
```php
private function probe(): ?PendingRequest
{
    $key = (string) config("ai.providers.{$this->provider}.key");

    $request = match ($this->provider) {
        'anthropic' => Http::withHeaders([
            'x-api-key' => $key,
            'anthropic-version' => (string) config('ai.providers.anthropic.version', '2023-06-01'),
        ])->baseUrl($this->baseUrl('https://api.anthropic.com/v1')),
        'openai' => Http::withToken($key)
            ->baseUrl($this->baseUrl('https://api.openai.com/v1')),
        default => null,
    };

    return $request?->connectTimeout(5)->timeout(10);
}
```

**What to copy:** add `'ollama_cloud'` arm identical in shape to `'openai'`:
```php
'ollama_cloud' => Http::withToken($key)
    ->baseUrl($this->baseUrl('https://ollama.com')),
```
`run()` (lines 57-84) calls `$probe->get("models/{$this->model}")` — since this reuses the OpenAI-shaped
`/v1/models` base URL convention (`baseUrl()` already appends no `/v1` automatically, verify Ollama Cloud's
base URL used here must resolve `models/{model}` to `.../v1/models/{model}` — set the base URL constant
to `https://ollama.com/v1` to mirror how `openai`'s default base already includes `/v1`, e.g.
`'https://api.openai.com/v1'`). This is CRITICAL per CONTEXT.md and research SUMMARY.md — do not defer.
No other method in this file needs changes; `run()`, `describe()`, `reachableModels()`,
`forConfiguredProviders()` are all already provider-agnostic.

---

### `packages/Chat/src/Agents/CrmAssistant.php::providerOptions()` (service, request-response)

**Analog:** `Lab::OpenAI->value` match arm, same method (lines 634-656)

**Core pattern to copy** (lines 634-656):
```php
public function providerOptions(Lab|string $provider): array
{
    $providerKey = $provider instanceof Lab ? $provider->value : $provider;

    return match ($providerKey) {
        Lab::Anthropic->value => [
            'tool_choice' => [
                'type' => 'auto',
                'disable_parallel_tool_use' => true,
            ],
            ...$this->anthropicEffort(),
            ...$this->anthropicCachedSystemBlocks(),
        ],
        Lab::OpenAI->value => [
            'parallel_tool_calls' => false,
        ],
        // Gemini is absent on purpose: ...
        default => [],
    };
}
```

**What to copy:** per D-01, add an arm for Ollama Cloud identical in shape to `Lab::OpenAI->value` (its
driver is OpenAI-compatible per research), gated on whatever the `laravel/ai` `Lab` enum names the Ollama
provider (verify at build time whether `Lab::Ollama` exists and whether its `->value` matches the
`ollama_cloud` config key used elsewhere, since `Lab` values are the `laravel/ai` package's own provider
identifiers and may not equal the app's `config('ai.providers.*)` key):
```php
Lab::Ollama->value => [   // confirm exact Lab case/value at build time
    'parallel_tool_calls' => false,
],
```
Per D-01's fallback: if Ollama Cloud's OpenAI-compatible layer doesn't honor `parallel_tool_calls: false`,
remove this arm (falls through to `default => []`, i.e. `write_guard: prompt`) and document explicitly
rather than silently keeping a no-op option, per D-01's fallback instruction.

---

## Shared Patterns

### Provider `match` arm addition (three files, same shape)
**Source:** `packages/Chat/src/Services/ProviderModelCatalog.php::fetch()` lines 80-87,
`app/Health/ChatProviderCheck.php::probe()` lines 97-105,
`packages/Chat/src/Agents/CrmAssistant.php::providerOptions()` lines 638-654

All three files use the exact same idiom: a `match ($provider)` (or `$providerKey`) expression with one
arm per provider, `default => null` or `default => []`. Adding Ollama Cloud support means adding exactly
one new arm to each, copying the `'openai'`/`Lab::OpenAI->value` arm's shape since Ollama Cloud is
OpenAI-compatible. No structural changes to any of these methods, no new helper methods needed.

### Config key distinctness (Pitfall 1)
**Source:** `config/ai.php` `'ollama'` vs `'ollama_cloud'`, `packages/Chat/config/chat.php` `'ollama'`
(self-hosted, env-merged) vs `'ollama_cloud'` (catalog rows)

Every new file/config touched must use the literal string `ollama_cloud`, never `ollama`. The existing
`ollama` key is self-hosted/free/never-plan-gated; reusing it would silently grant paid Ollama Cloud
models free access. Grep-verify before considering the phase done: `grep -rn "'ollama_cloud'" app/ config/
packages/Chat/`.

### Null pricing for subscription billing (D-04, Pitfall 7)
**Source:** self-hosted convention implied by `packages/Chat/config/chat.php` header comment (lines
184-187, "self-hosted ones are merged in from SELF_HOSTED_AI_* / OLLAMA_* env... `.env` stays their single
source of truth") and D-04 in CONTEXT.md.

Ollama Cloud catalog rows set `input_per_mtok` / `output_per_mtok` to `null`. This is not present as a
literal example in the current `models` array (all current rows have live prices), so there is no direct
in-repo precedent to copy verbatim — treat this as a documented decision (D-04) rather than a codebase
analog. `credit_multiplier` remains the honest cost signal (D-05, user to confirm exact values).

## No Analog Found

None. All 5-6 integration points have exact or near-exact analogs already in the same files (the `openai`/
`anthropic` match arms). No file requires an entirely new pattern.

## Metadata

**Analog search scope:** `config/ai.php`, `.env.example`, `packages/Chat/config/chat.php`,
`packages/Chat/src/Services/ProviderModelCatalog.php`, `app/Health/ChatProviderCheck.php`,
`packages/Chat/src/Agents/CrmAssistant.php`
**Files scanned:** 6
**Pattern extraction date:** 2026-09-02

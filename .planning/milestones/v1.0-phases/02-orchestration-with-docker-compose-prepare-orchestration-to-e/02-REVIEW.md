---
phase: 02-orchestration-with-docker-compose-prepare-orchestration-to-e
reviewed: 2026-09-03T10:52:55Z
depth: standard
files_reviewed: 10
files_reviewed_list:
  - .env.ci
  - .env.example
  - compose.dev.yml
  - compose.yml
  - packages/Chat/resources/views/filament/app/echo-assets-hook.blade.php
  - packages/Chat/src/ChatServiceProvider.php
  - packages/Documentation/resources/content/docs/guides/self-hosting.md
  - resources/js/echo.js
  - tests/Feature/Chat/BroadcastReverbConfigTest.php
  - tests/Feature/Chat/ReverbClientConfigTest.php
findings:
  critical: 1
  warning: 2
  info: 1
  total: 4
status: issues_found
---

# Phase 02: Code Review Report

**Reviewed:** 2026-09-03T10:52:55Z
**Depth:** standard
**Files Reviewed:** 10
**Status:** issues_found

## Summary

This phase replaces the Vite build-time Echo config (`VITE_REVERB_*`) with a
runtime meta-tag contract rendered by a new Filament render-hook view, retools
`compose.dev.yml` from a Sail-derived dev stack into a production-shaped
6-container stack, and adds a new `reverb` service plus documentation to
`compose.yml` for self-hosters. The meta-tag switch (`echo.js`,
`echo-assets-hook.blade.php`, `ChatServiceProvider.php`) is sound and is
covered by two solid new tests.

The `compose.yml` change, however, does not actually finish wiring the
`REVERB_APP_ID` / `REVERB_APP_SECRET` credentials it introduces: the `app`
service only receives `REVERB_APP_KEY` (not the secret or app id), and the
`horizon` and `scheduler` services receive none of the three `REVERB_APP_*`
variables at all. Because Laravel's Pusher-protocol broadcaster (used by the
`reverb` driver) signs private-channel auth responses and outbound broadcast
pushes with `key`/`secret`/`app_id` taken from `config('broadcasting.connections.reverb')`
(`env('REVERB_APP_KEY')`/`env('REVERB_APP_SECRET')`/`env('REVERB_APP_ID')`),
this silently breaks real-time chat for every deployment built from the
published `compose.yml` — exactly the surface this phase exists to enable. No
test in this diff (or elsewhere) exercises the compose files, so nothing
currently catches this.

Two secondary issues (a cache-store inconsistency in the `scheduler` service,
and `.env.ci` dropping the explicit Reverb host/port/scheme it used to pin)
and one documentation inconsistency round out the findings.

## Critical Issues

### CR-01: `compose.yml` never propagates `REVERB_APP_SECRET`/`REVERB_APP_ID` to `app`, `horizon`, or `scheduler` — breaks real-time chat for every self-hosted deployment

**File:** `compose.yml:49` (app), `compose.yml:76-103` (horizon), `compose.yml:120-147` (scheduler); compare `compose.yml:177-179` (reverb, correct)

**Issue:**
The `reverb` service correctly requires all three credentials:
```yaml
REVERB_APP_ID: ${REVERB_APP_ID:?REVERB_APP_ID is required}
REVERB_APP_KEY: ${REVERB_APP_KEY:?REVERB_APP_KEY is required}
REVERB_APP_SECRET: ${REVERB_APP_SECRET:?REVERB_APP_SECRET is required}
```
but:
- The `app` service (line 49) only sets `REVERB_APP_KEY`. It never sets
  `REVERB_APP_SECRET` or `REVERB_APP_ID`.
- The `horizon` service (lines 76-103) sets none of `REVERB_APP_KEY`,
  `REVERB_APP_SECRET`, or `REVERB_APP_ID`.
- The `scheduler` service (lines 120-147) sets none of them either.

`config/broadcasting.php`'s `reverb` connection reads these three straight
from env:
```php
'reverb' => [
    'driver' => 'reverb',
    'key' => env('REVERB_APP_KEY'),
    'secret' => env('REVERB_APP_SECRET'),
    'app_id' => env('REVERB_APP_ID'),
    ...
```
and Laravel's `BroadcastManager::pusher()` passes these positionally into
`new Pusher($config['key'], $config['secret'], $config['app_id'], ...)`
(`vendor/laravel/framework/src/Illuminate/Broadcasting/BroadcastManager.php:375-381`).
With `secret`/`app_id` null, `pusher-php-server`'s `Pusher::__construct()`
(`string $auth_key, string $secret, string $app_id`) silently coerces the
nulls to empty strings (no `declare(strict_types=1)` in the calling
framework file, so this doesn't even throw) — it just produces a client
signing every private-channel auth response and every outbound broadcast
push with an empty secret/app_id that can never match the real
`REVERB_APP_SECRET`/`REVERB_APP_ID` configured on the actual `reverb`
container.

Concretely, this breaks two things for every `compose.yml`-based deployment:
1. **Private channel subscriptions never authorize.** Chat channels are
   private (`Broadcast::channel('chat.conversation.{conversationId}', ...)`
   in `packages/Chat/routes/channels.php`). The `app` container signs the
   `/broadcasting/auth` response using its own (empty) secret, which will
   never match Reverb's real secret, so the browser's channel subscription
   is rejected.
2. **Every queued broadcast push from Horizon fails.** `QUEUE_CONNECTION=redis`
   means the actual `BroadcastEvent` job (and therefore the outbound HTTP
   push to Reverb) runs inside the `horizon` container, whose
   `key`/`secret`/`app_id` are all empty — Reverb will reject the push.

Net effect: real-time chat streaming is completely non-functional on the
published Docker Compose stack, which is the core feature this phase's own
documentation calls out ("Carries real-time chat message streaming to the
browser") and that CLAUDE.md requires to be verified end-to-end before chat
work is reported done. No test in the suite exercises the compose files, so
nothing currently guards against this regression.

**Fix:** Add the same three required vars used by the `reverb` service to
`app`, `horizon`, and `scheduler`:
```yaml
      REVERB_APP_ID: ${REVERB_APP_ID:?REVERB_APP_ID is required}
      REVERB_APP_KEY: ${REVERB_APP_KEY:?REVERB_APP_KEY is required}
      REVERB_APP_SECRET: ${REVERB_APP_SECRET:?REVERB_APP_SECRET is required}
```
(`app` already has `REVERB_APP_KEY`; just add the other two there. `horizon`
and `scheduler` need all three.)

## Warnings

### WR-01: `scheduler` service uses a different cache/session store than `app`/`horizon`

**File:** `compose.yml:120-147`

**Issue:** `app` and `horizon` both explicitly set `CACHE_STORE: redis` and
`SESSION_DRIVER: redis`. `scheduler` sets neither, so it falls back to
`config/cache.php`'s default (`env('CACHE_STORE', 'database')`). This means
scheduled command output (e.g. anything using cache locks via
`->withoutOverlapping()`, or reading cache keys written by `app`/`horizon`
such as `ChatServiceProvider::registerInsightsCacheInvalidation()`'s
`crm_insights_{$teamId}` key) is invisible across containers: a value
written to Redis by `app` won't be seen by `scheduler` reading from the
`cache` database table, and vice versa.

**Fix:** Add `CACHE_STORE: redis` (and `SESSION_DRIVER: redis` for
consistency, even though the scheduler is unlikely to touch sessions) to the
`scheduler` service's environment block.

### WR-02: `.env.ci` drops the explicit Reverb host/port/scheme it previously pinned, leaving the `reverb-host` meta tag empty

**File:** `.env.ci:37-40`

**Issue:** Before this change, `.env.ci` set `VITE_REVERB_HOST=127.0.0.1`,
`VITE_REVERB_PORT=8080`, and `VITE_REVERB_SCHEME=http` alongside the app key,
giving the CI browser suite a syntactically valid (if unreachable) websocket
target. The new `.env.ci` only sets `REVERB_APP_KEY=ci` and leaves
`REVERB_HOST` unset entirely. Since `config/reverb.php`'s
`apps.apps.0.options.host` has no fallback (`env('REVERB_HOST')`, no default
argument), the `reverb-host` meta tag now renders `content=""`, and
`echo.js`'s `wsHost: readMeta('reverb-host')` receives an empty string
instead of a real-looking host. This relies on `pusher-js`/`laravel-echo`
swallowing a malformed connection target internally rather than pinning an
explicit, intentional value the way the removed lines did. It happens to
work today (per the commit message, the browser suite's
`assertNoJavaScriptErrors` was checked), but it's a more fragile contract
than before: a `pusher-js` upgrade that starts validating `wsHost` eagerly
(e.g. throwing on `new WebSocket('wss://:443/...')`) would resurface exactly
the class of bug this file's comment is about, with no explicit test pinning
what CI expects the host to resolve to.

**Fix:** Keep an explicit placeholder, e.g. `REVERB_HOST=127.0.0.1`,
`REVERB_PORT=8080`, `REVERB_SCHEME=http`, alongside `REVERB_APP_KEY=ci`, so
CI's Reverb config is deliberately valid-but-unreachable rather than empty.

## Info

### IN-01: Inconsistent `REVERB_APP_SECRET` generation command in self-hosting docs

**File:** `packages/Documentation/resources/content/docs/guides/self-hosting.md:24-28,80-82,362`

**Issue:** The Quick Start step (lines 26-28) and the Dokploy section (line
362) both tell the reader to generate all three Reverb values with
`openssl rand -hex 16`. The "Required" environment variable table (line 82)
instead tells the reader to generate `REVERB_APP_SECRET` with
`openssl rand -base64 32`. Both commands produce a usable secret, but the
inconsistency reads as an editing artifact and could make a self-hoster
second-guess which instruction to follow.

**Fix:** Pick one command for `REVERB_APP_SECRET` (either is fine) and use it
consistently across the Quick Start, Required table, and Dokploy/Coolify
sections.

---

_Reviewed: 2026-09-03T10:52:55Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_

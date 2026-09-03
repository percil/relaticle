---
phase: 02-orchestration-with-docker-compose-prepare-orchestration-to-e
plan: 01
subsystem: chat-realtime-and-dev-orchestration
tags: [reverb, echo, docker-compose, filament, blade, testing]
dependency-graph:
  requires: []
  provides:
    - runtime-injected-reverb-client-credentials
    - relaticle-dev-compose-stack
  affects:
    - packages/Chat
    - resources/js/echo.js
    - compose.dev.yml
tech-stack:
  added: []
  patterns:
    - "Server-rendered <meta> tag as the request-time credential source for a client bootstrap file, extending the existing csrf-token idiom in head.blade.php"
    - "Sidecar-per-process compose services (app/horizon/reverb share one build, differ only by command:)"
key-files:
  created:
    - packages/Chat/resources/views/filament/app/echo-assets-hook.blade.php
    - tests/Feature/Chat/ReverbClientConfigTest.php
  modified:
    - packages/Chat/src/ChatServiceProvider.php
    - resources/js/echo.js
    - compose.dev.yml
    - .env.example
    - .env.ci
decisions:
  - "Coexistence: new dev stack runs under compose project name relaticle-dev on non-conflicting host ports (app 8080, reverb 8081, postgres 5433, redis 6380, mailpit 1026/8026), side by side with the pre-existing Sail containers, per the resolved checkpoint decision."
  - "Renamed the Reverb host-port override variable from REVERB_PORT to DEV_REVERB_PORT inside compose.dev.yml: this repo's root .env already sets REVERB_PORT=8080 for native/Herd dev, and docker compose auto-loads that file for ${VAR} substitution, which silently collided with the app service's own port 8080 (Rule 1 bug, found live during Task 2 verification)."
  - "Added REVERB_APP_KEY=ci to .env.ci in place of the deleted VITE_REVERB_APP_KEY=ci: the panel's Echo bootstrap now reads the key via config('reverb.apps.apps.0.key'), so the browser suite's assertNoJavaScriptErrors needs a real REVERB_APP_KEY, not a Vite build-time mirror (Rule 2, missing critical functionality)."
metrics:
  duration: ~35min
  completed: 2026-09-03
status: complete
actuals:
  tokens: 3909
  tasks: 3
  commits: 2
---

# Phase 02 Plan 01: Runtime Reverb Credentials + Dev Compose Stack Summary

Reverb's browser credentials now arrive from the server at request time instead of being baked into the shared image at build time, and `compose.dev.yml` is a purpose-built dev/test stack that builds this repository's own `Dockerfile` and runs every backing service (postgres 17, redis, app, horizon, reverb, mailpit), proven end to end by opening a real Reverb WebSocket from a key scraped off the rendered login page.

## What Was Built

**Task 1 (checkpoint, resolved before this run):** Coexistence mode decided as side-by-side — the new stack runs under compose project `relaticle-dev` on non-conflicting host ports, leaving the pre-existing Sail-generated Postgres/Redis/Mailpit containers, the `relaticle_sail-pgsql` volume, Herd, and `composer test:pest` completely untouched.

**Task 2:** Created `packages/Chat/resources/views/filament/app/echo-assets-hook.blade.php`, which renders `reverb-app-key`/`reverb-host`/`reverb-port`/`reverb-scheme` meta tags from `config('reverb.apps.apps.0.*')` followed by the existing `@vite(['resources/js/echo.js', ...])` call, all from one Blade render hook so the credentials can never drift from the script that consumes them. `ChatServiceProvider::registerRenderHooks()`'s `HEAD_END` hook now returns that view instead of an inline `Blade::render()` call. `resources/js/echo.js` was rewritten to read those four meta tags via `document.querySelector` instead of `import.meta.env.VITE_REVERB_*`, with no fallback to the old source. `compose.dev.yml` was replaced wholesale: it builds the root `Dockerfile` (`target: production`) for `app`, `horizon` and `reverb`, wires mail to `mailpit`, pins `postgres:17-alpine`, and separates the client-facing `REVERB_HOST/PORT/SCHEME` family from the server-to-server `BROADCAST_REVERB_HOST/PORT/SCHEME` family so broadcasts push over plain HTTP to the `reverb` container while the browser gets `http://localhost:8081`.

**Task 3:** Added `tests/Feature/Chat/ReverbClientConfigTest.php`, which GETs the real panel dashboard as an authenticated tenant user and asserts on the rendered HTML: the four meta tags carry pinned, distinctive config values; the Reverb app secret never appears in the payload; and the `reverb-app-key` meta tag renders at an earlier HTML offset than the `echo.js` asset reference (proving one hook emitted both). Removed the four now-dead `VITE_REVERB_*` keys from `.env.example` and `.env.ci`.

## End-to-End Verification (Task 2's tracer proof)

Ran directly against the built stack in this session:

- `docker run --rm --entrypoint sh ghcr.io/relaticle/relaticle:latest -c 'command -v healthcheck-reverb'` → binary present, so `reverb`'s healthcheck uses `["CMD", "healthcheck-reverb"]` (no TCP fallback needed; closes RESEARCH.md assumption A4).
- `docker compose -f compose.dev.yml up -d --build --wait` → all 6 services (`app`, `horizon`, `reverb`, `postgres`, `redis`, `mailpit`) reached healthy.
- `GET http://localhost:8080/app/login` → HTML contains `name="reverb-app-key" content="relaticle-dev-key"`, `reverb-host content="localhost"`, `reverb-port content="8081"`, `reverb-scheme content="http"`; does NOT contain `relaticle-dev-secret`.
- A WebSocket handshake to `ws://localhost:8081/app/relaticle-dev-key?...` using the scraped key returned `pusher:connection_established`.
- `docker compose exec app sh -c "grep -rq 'reverb-app-key' /var/www/html/public/build/assets"` → the compiled bundle inside the built image reads the meta tag, not a build-time value.
- `grep -c 'meta\.env' resources/js/echo.js` → 0.
- `docker compose -f compose.dev.yml config` → exactly the 6 intended services, `postgres:17-alpine`, `BROADCAST_REVERB_HOST: reverb` / `BROADCAST_REVERB_SCHEME: http` on `app` and `horizon`, `dockerfile: Dockerfile` + `target: production` on `app`, no `vendor/laravel/sail` reference anywhere.

The one manual step in the plan's verify block — opening the chat panel in a real browser and confirming token-by-token streaming — could not be run here (no `agent-browser`/Herd installed in this execution sandbox, exactly as the plan anticipated) and is harvested at end of phase per the plan's own note.

## Local Test Execution Note

This sandbox's pre-existing Sail Postgres container (`relaticle-pgsql-1`, `postgres:18-alpine`) does not accept the `root`/no-password credentials `.env.testing` expects (its `root` role requires a password this session does not have), so the native `vendor/bin/pest` loop cannot reach a database here independent of this plan's changes. To run and verify `ReverbClientConfigTest` and the full `tests/Arch/` suite in this session, `DB_HOST`/`DB_PORT`/`DB_USERNAME`/`DB_PASSWORD` were exported as process environment variables pointing at the new `relaticle-dev-postgres-1` container (port 5433) for the duration of the test run only — no committed file was changed for this, and the pre-existing Sail container was left untouched. All tests passed:
- `ReverbClientConfigTest` + `BroadcastReverbConfigTest`: 8/8 passed.
- `tests/Arch/`: 69/69 passed.
- `pint --dirty`, `rector --dry-run`, `phpstan analyse`, `composer test:type-coverage`: all clean.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] `REVERB_PORT` compose-substitution variable collided with this repo's own `.env`**
- **Found during:** Task 2, first `docker compose up` attempt.
- **Issue:** The plan's spec used `${REVERB_PORT:-8081}` for both the `reverb` service's host port publish and the `app` container's client-facing `REVERB_PORT` value. Docker Compose auto-loads the repository root `.env` for `${VAR}` substitution, and that file already sets `REVERB_PORT=8080` for native/Herd dev, so the "default" 8081 was silently overridden to 8080, colliding with the `app` service's own port 8080 (`Bind for 0.0.0.0:8080 failed: port is already allocated`).
- **Fix:** Renamed the compose-only host-port override variable to `DEV_REVERB_PORT` (not defined anywhere in the app's own `.env`), used consistently for the `reverb` service's port publish and the `app` container's `REVERB_PORT` env value.
- **Files modified:** `compose.dev.yml`.
- **Commit:** `aec1b86e`.

**2. [Rule 2 - Missing critical functionality] `.env.ci` needed a real `REVERB_APP_KEY`, not just a deleted Vite mirror**
- **Found during:** Task 3, while removing the dead `VITE_REVERB_*` keys.
- **Issue:** `.env.ci`'s own comment explained `VITE_REVERB_APP_KEY=ci` existed solely so pusher-js's client-side key check wouldn't throw and fail `assertNoJavaScriptErrors` in the browser suite. Deleting it without a replacement would have reintroduced that exact failure, because the Echo bootstrap now reads `config('reverb.apps.apps.0.key')` (i.e. `env('REVERB_APP_KEY')`) via the meta tag instead of a Vite build-time value, and `.env.ci` never defined `REVERB_APP_KEY`.
- **Fix:** Added `REVERB_APP_KEY=ci` to `.env.ci` with an updated comment reflecting the new mechanism, dropped `VITE_REVERB_HOST`/`PORT`/`SCHEME` (not needed — no Reverb server runs in CI, so those meta tags being empty doesn't affect the "connection never opens" behavior the original comment described).
- **Files modified:** `.env.ci`.
- **Commit:** `3aaa875e`.

## Known Stubs

None. Both tasks' acceptance criteria are fully met with no placeholder data or unwired paths.

## Threat Flags

None beyond what the plan's own threat model already covered (T-02-01 through T-02-05, T-02-SC) — no new network endpoints, auth paths, or trust boundaries were introduced beyond what the plan anticipated and dispositioned.

## Self-Check: PASSED

- `packages/Chat/resources/views/filament/app/echo-assets-hook.blade.php`: FOUND
- `packages/Chat/src/ChatServiceProvider.php`: FOUND
- `resources/js/echo.js`: FOUND
- `compose.dev.yml`: FOUND
- `tests/Feature/Chat/ReverbClientConfigTest.php`: FOUND
- `.env.example`: FOUND
- `.env.ci`: FOUND
- Commit `aec1b86e`: FOUND in `git log --oneline --all`
- Commit `3aaa875e`: FOUND in `git log --oneline --all`

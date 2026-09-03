# Phase 2: Docker Compose Orchestration - Pattern Map

**Mapped:** 2026-09-03
**Files analyzed:** 4 (all modifications, no new files)
**Analogs found:** 4 / 4 (all in-file self-analogs; this is a gap-closure phase on already-mature infrastructure)

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|-------------------|------|-----------|-----------------|----------------|
| `compose.yml` (add `reverb` service) | config | event-driven (long-running daemon sidecar) | same file, `horizon`/`scheduler` services (lines 56-127) | exact (in-file) |
| `compose.dev.yml` (rework) | config | event-driven / batch (multi-service orchestration) | `compose.yml` (production compose, same repo) + own `mailpit`/`pgsql` blocks | role-match |
| `resources/js/echo.js` | config/bootstrap (client) | request-response (WS handshake config) | `resources/views/components/layout/head.blade.php` (server-rendered `<meta>` value pattern) | partial (new mechanism, closest existing convention) |
| `packages/Documentation/resources/content/docs/guides/self-hosting.md` | docs | transform (structured markdown reference) | same file, `## Architecture` container table (lines 164-186) | exact (in-file) |

All four files are modifications to files that already exist and already contain the pattern to extend — there are no genuinely new files in this phase, so every "analog" is the sibling block within the same or a directly related file. Do not search further afield; the established pattern already lives where it needs to be copied from.

## Pattern Assignments

### `compose.yml` — add `reverb` service (config, event-driven sidecar)

**Analog:** same file, `horizon` service (`compose.yml:56-91`)

**Full sidecar shape to copy** (`compose.yml:56-91`):
```yaml
  horizon:
    image: ghcr.io/relaticle/relaticle:latest
    restart: unless-stopped
    command: ["php", "/var/www/html/artisan", "horizon"]
    stop_signal: SIGTERM
    environment:
      APP_NAME: ${APP_NAME:-Relaticle}
      APP_ENV: ${APP_ENV:-production}
      APP_KEY: ${APP_KEY:?APP_KEY is required}
      APP_DEBUG: ${APP_DEBUG:-false}
      APP_URL: ${APP_URL:-http://localhost}
      APP_PANEL_DOMAIN: ${APP_PANEL_DOMAIN:-}
      REQUIRE_EMAIL_VERIFICATION: ${REQUIRE_EMAIL_VERIFICATION:-true}
      LOG_CHANNEL: ${LOG_CHANNEL:-stderr}
      DB_CONNECTION: pgsql
      DB_HOST: postgres
      DB_PORT: 5432
      DB_DATABASE: ${DB_DATABASE:-relaticle}
      DB_USERNAME: ${DB_USERNAME:-relaticle}
      DB_PASSWORD: ${DB_PASSWORD:?DB_PASSWORD is required}
      REDIS_HOST: redis
      REDIS_PASSWORD: ${REDIS_PASSWORD:-null}
      REDIS_PORT: 6379
      QUEUE_CONNECTION: redis
      # Disable automations for worker container
      AUTORUN_ENABLED: "false"
    volumes:
      - storage:/var/www/html/storage/app
    depends_on:
      app:
        condition: service_healthy
      redis:
        condition: service_healthy
    healthcheck:
      test: ["CMD", "healthcheck-horizon"]
      start_period: 10s
```

**What to change for `reverb`:**
- `command:` → `["php", "/var/www/html/artisan", "reverb:start", "--host=0.0.0.0", "--port=8080"]`
- `healthcheck.test:` → `["CMD", "healthcheck-reverb"]` (verify the binary exists in `serversideup/php:8.5-fpm-nginx` before relying on it; RESEARCH.md flags this as vendor-cited, not independently verified — fall back to a plain TCP check e.g. `["CMD", "nc", "-z", "localhost", "8080"]` if absent)
- `environment:` add `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET` (all `:?...is required` like `APP_KEY`/`DB_PASSWORD` above) — Reverb doesn't need `DB_*` at all (it's not touching Postgres), so trim those out unlike horizon's copy
- No `storage:` volume needed (Reverb doesn't write to app storage)

**Cross-cutting: `app`/`horizon`/`scheduler` all need `BROADCAST_REVERB_HOST`/`BROADCAST_REVERB_SCHEME` added to their existing `environment:` blocks** (per RESEARCH.md Pattern 2 — the Docker service name `reverb` is not in `config/broadcasting.php`'s loopback auto-detect list, `127.0.0.1`/`::1`/`localhost` only):
```yaml
      BROADCAST_REVERB_HOST: reverb
      BROADCAST_REVERB_SCHEME: http
```
Also add the public-facing `REVERB_HOST`/`REVERB_PORT`/`REVERB_SCHEME` (client-facing, behind reverse proxy) to `app`'s environment for consistency with whatever `echo.js`'s runtime source reads server-side.

---

### `compose.dev.yml` — rework (config, multi-service orchestration)

**Analog:** `compose.yml` (production compose in the same repo) for the `app`/`horizon`/`reverb` service shapes, plus the existing `compose.dev.yml` `mailpit`/`pgsql` blocks (`compose.dev.yml:30-47, 82-88`) for what to keep.

**Build block to add** (currently missing — `compose.dev.yml:2-8` builds Sail's vendored runtime, not this repo's `Dockerfile`):
```yaml
# Current (wrong target):
    build:
        context: ./vendor/laravel/sail/runtimes/8.5
        dockerfile: Dockerfile
```
Replace with a `build:` pointing at the root `Dockerfile` (`context: .`, `dockerfile: Dockerfile`, likely `target: production` since that's the only stage defined — `Dockerfile:45` `FROM serversideup/php:8.5-fpm-nginx AS production` is the sole named stage. If a dev iteration story requires bind-mounting source over the built image, that's an in-plan decision; RESEARCH.md flags reusing `production` with a bind mount as one option, not a locked choice.)

**Services to add** (following `compose.yml`'s `horizon` shape, adapted to local dev hostnames `postgres`/`redis`/`mailpit` instead of managed names): `horizon`, `reverb`.

**Mailpit wiring — currently present but unwired** (`compose.dev.yml:82-88` has the `mailpit` container but no app-side `MAIL_*` env points at it). Add to the `app`/build service's environment, mirroring how `compose.yml:30-37` structures `MAIL_*`:
```yaml
      MAIL_MAILER: smtp
      MAIL_HOST: mailpit
      MAIL_PORT: 1025
```

**Postgres version pin** (D-04) — change `compose.dev.yml:31` `image: 'postgres:18-alpine'` to `postgres:17-alpine`, matching `compose.yml:129`.

**Services to drop** (per CONTEXT.md discretion, RESEARCH.md Anti-Patterns): `meilisearch` (`compose.dev.yml:63-81`), `selenium` (`compose.dev.yml:89-96`) — no `laravel/scout` dependency, unused by the app; drop unless a reason to keep surfaces during planning.

---

### `resources/js/echo.js` — runtime injection (D-01) (config/bootstrap, request-response)

**Analog:** `resources/views/components/layout/head.blade.php` (lines 20-22) — the existing convention for delivering a server-computed, request-time value to client JS via a `<meta>` tag rendered by Blade:
```blade
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
```
This is included via `<x-layout.head>` in both `resources/views/layouts/guest.blade.php` and `resources/views/layouts/filament-standalone.blade.php` (confirmed by `grep` — both layouts pull in `head.blade.php`'s meta block before `@vite(...)`). This is the closest existing mechanism in this codebase for "value computed server-side per request/deployment, consumed by client JS" — there is no existing `window.__*` global convention to reuse instead; `<meta name="...">` is the established idiom here.

**Current file to replace** (`resources/js/echo.js:1-14`, full file — build-time only, no runtime fallback):
```javascript
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
    wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
});
```

**Pattern to apply:** add `<meta name="reverb-app-key" content="{{ config('reverb.apps.apps.0.key') }}">` etc. (or a single JSON-encoded `<meta name="reverb-config" content="{{ ... }}">`) to `head.blade.php` alongside the existing `csrf-token` meta, sourced from `config('reverb.*')`/`env()` on the server (correctly set per-deployment via `compose.yml`'s `environment:` block, per RESEARCH.md Pitfall 1 option 1). Then read it in `echo.js` via `document.querySelector('meta[name="..."]').content`, mirroring how `csrf-token` is the established "meta tag holds a per-request server value" idiom in this codebase (note: no existing JS file currently reads `csrf-token` via `document.querySelector` — Laravel's own `axios` defaults or Livewire likely consume it framework-side, so this will be the first explicit JS-side meta read; still the correct idiom to extend, not a new one to invent).

**No Dockerfile changes needed** — this is the entire point of D-01: avoid `VITE_*` build args in `Dockerfile:22-40` (frontend stage) entirely.

---

### `packages/Documentation/resources/content/docs/guides/self-hosting.md` — document `reverb` (docs, transform)

**Analog:** same file, `## Architecture` section (lines 164-186)

**Table to extend** (`self-hosting.md:166-174`, currently "5 containers"):
```markdown
The Docker setup runs 5 containers:

| Container | Image | Purpose |
|-----------|-------|---------|
| **app** | `ghcr.io/relaticle/relaticle:latest` | Web server (nginx + PHP-FPM) on port 8080. Runs migrations automatically on startup. |
| **horizon** | `ghcr.io/relaticle/relaticle:latest` | Queue worker powered by Laravel Horizon. Processes background jobs. |
| **scheduler** | `ghcr.io/relaticle/relaticle:latest` | Runs `schedule:work` for recurring tasks (e.g., cleanup, notifications). |
| **postgres** | `postgres:17-alpine` | PostgreSQL 17 database. |
| **redis** | `redis:7-alpine` | Cache, sessions, and queue backend. Runs with append-only persistence. |
```
Change "5 containers" → "6 containers" and add a `reverb` row (`ghcr.io/relaticle/relaticle:latest`, "WebSocket server powered by Laravel Reverb. Powers real-time chat streaming."). Also update `self-hosting.md:306` ("Dokploy will pull the images and start all 5 containers") to match the new count.

**New content needed (no direct in-file analog, follows the doc's existing env-var-table + prose structure):**
- A note near `## Environment Variables` explaining `REVERB_APP_ID`/`REVERB_APP_KEY`/`REVERB_APP_SECRET` must be generated per self-hoster (mirror the existing `APP_KEY` "Generate with..." row style at `self-hosting.md:72`).
- A note on the reverse proxy needing to forward the WebSocket upgrade path/port for Reverb (this doc already has a Traefik labels example per RESEARCH.md; extend it rather than adding a new proxy-config section pattern).
- No `## Networking` change needed beyond noting Reverb's port, following the existing one-paragraph style at `self-hosting.md:184-186`.

## Shared Patterns

### Sidecar-per-process (config)
**Source:** `compose.yml` `horizon`/`scheduler` services (`compose.yml:56-127`)
**Apply to:** `compose.yml`'s new `reverb` service, and `compose.dev.yml`'s new `horizon`+`reverb` services.
Same image, override only `command:`, set `AUTORUN_ENABLED: "false"`, `depends_on` the services it needs healthy first. Never fold a long-running process into `app` via a supervisor hack (RESEARCH.md Anti-Patterns).

### Internal vs. public Reverb addressing (config)
**Source:** `config/broadcasting.php:1-14`, confirmed by `tests/Feature/Chat/BroadcastReverbConfigTest.php`
**Apply to:** every compose `environment:` block touching Reverb (`compose.yml`'s `app`/`horizon`/`scheduler`/`reverb`, `compose.dev.yml`'s equivalents).
Three separate families must never be conflated: `REVERB_SERVER_HOST/PORT` (daemon bind, container-internal, untouched by compose env), `BROADCAST_REVERB_HOST/PORT/SCHEME` (server-to-server push — must be `reverb`/`8080`/`http` explicitly, since the Docker service name isn't in the loopback auto-detect list), `REVERB_HOST/PORT/SCHEME` (public client-facing, behind reverse proxy, `https`).

### Server-rendered value → client JS via `<meta>` tag
**Source:** `resources/views/components/layout/head.blade.php:20-22` (`csrf-token` pattern)
**Apply to:** `resources/js/echo.js`'s D-01 runtime injection.
This is the one genuinely new client-side read in this phase (no existing JS file currently does `document.querySelector('meta[name=...]')`), but the server-side half (a Blade-rendered meta tag sourced from `config()`) is the established idiom to extend, not invent.

## No Analog Found

None — every file in scope is a modification to an existing file that already contains the pattern (or the closest available convention) to extend. The only element without a direct in-repo precedent is `echo.js` reading a `<meta>` tag client-side (see Shared Patterns above); the server-rendering half of that mechanism does have a precedent.

## Metadata

**Analog search scope:** `compose.yml`, `compose.dev.yml`, `resources/js/`, `resources/views/components/layout/`, `resources/views/layouts/`, `Dockerfile`, `packages/Documentation/resources/content/docs/guides/self-hosting.md`
**Files scanned:** ~10 (all read directly, no large-file grep-first needed — none exceed a few hundred lines)
**Pattern extraction date:** 2026-09-03

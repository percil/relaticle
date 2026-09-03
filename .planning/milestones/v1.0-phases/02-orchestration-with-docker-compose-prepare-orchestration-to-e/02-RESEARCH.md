# Phase 2: Docker Compose Orchestration - Research

**Researched:** 2026-09-03
**Domain:** Docker/Docker Compose orchestration for a Laravel 12-ish (PHP 8.5) modular monolith with Horizon, Reverb, Postgres, Redis
**Confidence:** MEDIUM (stack and gaps are HIGH confidence — read from source — but the correct fix for the Reverb/Vite build-time gap is a genuine design decision, not a verified fact)

## Summary

This is **not a greenfield phase**. Docker orchestration already exists and is
mature: a multi-stage production `Dockerfile`, a production-shaped `compose.yml`
(published to `ghcr.io/relaticle/relaticle` and Docker Hub via
`.github/workflows/docker-publish.yml`), and a full public self-hosting guide at
`packages/Documentation/resources/content/docs/guides/self-hosting.md`. Phase 2's
real job is closing three concrete gaps, not building from scratch:

1. **Reverb (WebSocket broadcasting) is completely absent from both compose
   files and from the self-hosting docs**, yet `laravel/reverb` is the app's
   default broadcaster (`BROADCAST_CONNECTION=reverb`) and chat message
   streaming — `packages/Chat`'s core UX — is wired end-to-end through it
   (`ProcessChatMessage` → `broadcast()` → Reverb → `resources/js/echo.js` →
   `streamModule` in `packages/Chat/resources/js/chat/stream.js`). Nobody who
   runs `docker compose up -d` today gets working real-time chat streaming.
2. **The frontend asset build stage never receives `VITE_REVERB_*` build
   args**, and Vite inlines `import.meta.env.VITE_*` values at build time, not
   runtime. Because the published image is built once in CI and shared by
   every self-hoster, there is no single correct value to bake in — this is
   the central design question of the phase, not a config oversight.
3. **`compose.dev.yml` is Laravel Sail's stock generated file**, not a
   purpose-built local dev/test compose. It builds Sail's generic runtime
   image (not the project's own `Dockerfile`), and it is missing Horizon and
   Reverb containers entirely (Meilisearch and Selenium are present but
   unused by the app). The phase goal explicitly requires local dev/test to
   build the project's real image(s) and include every third-party service.

**Primary recommendation:** Add a `reverb` service to `compose.yml` following
the same pattern already used for `horizon`/`scheduler` (same image, different
`command:`, `AUTORUN_ENABLED=false`), decide and implement how `VITE_REVERB_*`
values reach the browser for a *generically built, shared* image (see Pitfall
1), and replace `compose.dev.yml` with a purpose-built dev compose file that
builds the root `Dockerfile` and includes Postgres, Redis, Horizon, Reverb,
and Mailpit, wired so message streaming and mail actually work out of the box.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| HTTP request handling (web/api/mcp/sysadmin panels) | API/Backend (`app` container: nginx+PHP-FPM) | CDN/Static (built Vite assets served by nginx) | Existing `Dockerfile` production stage + `compose.yml` `app` service |
| Queue processing (chat jobs, imports, notifications) | API/Backend (`horizon` container) | Database/Storage (Redis as queue backend) | Existing `compose.yml` `horizon` service; `laravel/horizon` ^5.29 |
| Scheduled tasks (`bootstrap/app.php` `withSchedule()`) | API/Backend (`scheduler` container) | — | Existing `compose.yml` `scheduler` service running `schedule:work` |
| Real-time chat streaming / WebSocket broadcast | API/Backend (new `reverb` container, server-side push) | Browser/Client (`laravel-echo`+`pusher-js` WS client) | `laravel/reverb` ^1.10 is the default broadcaster; **missing today** |
| Persistence | Database/Storage (`postgres` container) | — | PostgreSQL exclusively, per project CLAUDE.md |
| Cache/session/queue backend | Database/Storage (`redis` container) | — | `CACHE_STORE=redis`, Horizon requires Redis |
| Local outbound email capture (dev only) | Database/Storage-adjacent (`mailpit` container) | — | Needed for local dev/test per phase goal; absent from prod compose (correct — prod uses real SMTP) |
| Static asset build (Vite/Tailwind) | CDN/Static (frontend build stage) | Browser/Client (consumes built JS/CSS) | `Dockerfile` `frontend` stage; **VITE_REVERB_* build-time gap lives here** |

## User Constraints

No CONTEXT.md exists for this phase (no `/gsd-discuss-phase` run yet). No
locked decisions or discretion notes to honor. The planner/discuss-phase
should surface the open architectural question in Pitfall 1 as a decision
point before planning proceeds, since it materially changes what "done"
means for self-hosted production.

<phase_requirements>
## Phase Requirements

No requirement IDs have been mapped to this phase in `.planning/REQUIREMENTS.md`
yet — all v1.0 requirements (`OLLAMA-01`..`OLLAMA-06`) are scoped to Phase 1
and marked Complete. `.planning/REQUIREMENTS.md` has no v2 requirements section
populated. This is stated explicitly per the phase brief's instruction: **the
phase currently has no formal requirement IDs to trace to.** The planner should
either request requirement IDs be added to REQUIREMENTS.md before planning, or
derive acceptance criteria directly from the ROADMAP.md phase goal: *"Local
tests/dev MUST build the image(s) and come with all the separate 3rd party
services"* plus production deployment readiness.
</phase_requirements>

## Existing Infrastructure Inventory

> This section replaces a "Standard Stack" table (nothing new needs to be
> installed — every package involved is already a direct dependency). It is
> the load-bearing section for planning: read this before proposing new files.

| Artifact | Path | State | Notes |
|---|---|---|---|
| Production Dockerfile | `Dockerfile` | [VERIFIED: Dockerfile:1-103] Exists, multi-stage (composer → frontend/node → `serversideup/php:8.5-fpm-nginx` production target) | `docker-publish.yml` builds `target: production` for `linux/amd64` + `linux/arm64` and pushes to GHCR + Docker Hub on push to `main`/tags |
| Production compose | `compose.yml` | [VERIFIED: compose.yml:1-158] 4 services: `app`, `horizon`, `scheduler`, `postgres`, `redis` (5 containers total per self-hosting docs) | Pulls `ghcr.io/relaticle/relaticle:latest`, does **not** build locally. No `reverb` service. |
| Local dev compose | `compose.dev.yml` | [VERIFIED: compose.dev.yml:1-107] Stock **Laravel Sail** generated file (`laravel.test` builds `vendor/laravel/sail/runtimes/8.5/Dockerfile`, not the root `Dockerfile`) | Services: `laravel.test`, `pgsql` (postgres:18-alpine — version mismatch vs prod's 17-alpine), `redis`, `meilisearch` (unused — no `laravel/scout` dependency in `composer.json`), `mailpit`, `selenium` (unused outside Sail's Dusk default). **No Horizon container, no Reverb container.** |
| Self-hosting docs | `packages/Documentation/resources/content/docs/guides/self-hosting.md` | [VERIFIED: self-hosting.md:1-610] Comprehensive: quick start, env var reference, "Architecture: 5 containers" table, Dokploy/Coolify guides, Traefik labels example, backup/restore, troubleshooting | **Zero mentions of Reverb, WebSockets, or broadcasting anywhere in the file.** Grep confirmed no hits. |
| Image publish workflow | `.github/workflows/docker-publish.yml` | [VERIFIED: docker-publish.yml:1-209] Builds `target: production` only, multi-arch, digest-merge pattern, pushes to `ghcr.io/relaticle/relaticle` + Docker Hub `manukminasyan/relaticle` | Runs on PR too (build-only, no push) — usable as a "does the image still build" CI signal |
| Production deploy | `.github/workflows/deploy.yml` | [VERIFIED: deploy.yml:1-42] Triggers a **Laravel Forge** webhook on GitHub release, NOT `docker compose` | Relaticle's own production deployment is Forge-based, not the published Docker image. `compose.yml`/self-hosting docs exist for **third-party self-hosters**, not Relaticle's own infra. `CLAUDE.md`'s "deployments" rule mentions Laravel Cloud generically but the actual workflow uses Forge — flag this discrepancy to the user, don't silently pick one. |
| CI test infra | `.github/workflows/ci.yml`, `tests.yml` | [VERIFIED: ci.yml:1-249] Uses a bare `postgres:alpine` **service container** directly (not `compose.yml`/`compose.dev.yml`), `.env.ci` sets `QUEUE_CONNECTION=database`, `BROADCAST_CONNECTION=log` | `.env.ci` has an inline comment: *"No Reverb server runs in CI, so the connection simply never opens"* — confirms Reverb's absence from CI is deliberate, and CI does not exercise Docker Compose at all today |
| Native/Herd dev script | `composer.json` `scripts.dev`, `bin/workspace-queue.sh` | [VERIFIED: composer.json, bin/workspace-queue.sh:1-24] `composer run dev` runs `php artisan serve` + `bin/workspace-queue.sh` (which runs `php artisan horizon`, watchexec-wrapped) + `php artisan pail` + `pnpm run dev` concurrently | **No `php artisan reverb:start` anywhere in the repo** (confirmed via repo-wide grep) except one-off manual usage noted in `.planning/phases/01-.../01-03-SUMMARY.md`. Local dev today never runs Reverb, docker or not. |
| Reverb dependency | `composer.json:36` | [VERIFIED: composer.json] `"laravel/reverb": "^1.10"` | Already installed — no new package needed for this phase |
| Reverb config | `config/reverb.php`, `config/broadcasting.php` | [VERIFIED: config/reverb.php:1-99, config/broadcasting.php:1-60] Distinguishes `REVERB_SERVER_HOST`/`REVERB_SERVER_PORT` (daemon bind, default `0.0.0.0:8080`) from `REVERB_HOST`/`REVERB_PORT`/`REVERB_SCHEME` (public client-facing) from `BROADCAST_REVERB_HOST`/`PORT`/`SCHEME` (server-to-server HTTP push, defaults to the `REVERB_*` values, with automatic `http`/no-TLS-verify when the host is a loopback address) | Verified live by `tests/Feature/Chat/BroadcastReverbConfigTest.php:1-71`, which asserts the loopback-detection behavior directly |
| Client Echo bootstrap | `resources/js/echo.js` | [VERIFIED: echo.js:1-14] Reads `import.meta.env.VITE_REVERB_APP_KEY/HOST/PORT/SCHEME` — **Vite build-time only**, no runtime fallback | This is the file that breaks if `VITE_REVERB_*` isn't set at `pnpm run build` time inside the Dockerfile's `frontend` stage |
| Chat streaming consumer | `packages/Chat/resources/js/chat/stream.js` | [VERIFIED: stream.js:1-14 header comment] "Reverb event routing" is the file's own stated purpose; it subscribes to the Echo channel and drives the chat UI's live token stream | Confirms Reverb is not a "nice to have" feature but the load-bearing transport for the chat UI's primary interaction |
| Health checks | `app/Providers/HealthServiceProvider.php` | [VERIFIED: HealthServiceProvider.php:1-96] Registers `HorizonCheck`, `RedisCheck`, `QueueCheck` (x2), `DatabaseCheck`, `ScheduleCheck`, `ChatProviderCheck::forConfiguredProviders()` — **no Reverb-specific check** (Spatie Health ships none for it) | Not necessarily a gap to close in this phase — flag for planner discretion only |

## Package Legitimacy Audit

**Not applicable — no new packages are introduced by this phase.** Every
component involved (`laravel/reverb`, `laravel/horizon`, `laravel/sail`,
`postgres`, `redis`, `serversideup/php`, `node`) is an existing, already-vetted
dependency or base image already in use in this repo. If the plan later adds
Mailpit (`axllent/mailpit`) as a new *base image* to the production-shaped dev
compose, note it: it is already present in the current `compose.dev.yml`
(Sail-generated), so it is not new to this codebase, only newly repurposed.

## Architecture Patterns

### System Architecture Diagram (target state, both environments)

```
                     ┌─────────────────────────────────────────┐
                     │              Browser / Client            │
                     │  HTTP(S) requests   │  WebSocket (wss/ws) │
                     └──────────┬──────────┴──────────┬─────────┘
                                │                      │
                     ┌──────────▼──────────┐  ┌────────▼─────────┐
                     │  Reverse proxy /     │  │  Reverse proxy /  │
                     │  compose "app" port  │  │  "reverb" port or │
                     │  (nginx+PHP-FPM)     │  │  WS-upgrade path  │
                     └──────────┬───────────┘  └────────┬─────────┘
                                │                        │
        ┌───────────────────────────────────────────────────────────┐
        │                     Docker internal network                │
        │                                                             │
        │  ┌────────┐   ┌──────────┐   ┌───────────┐   ┌───────────┐│
        │  │  app   │   │ horizon  │   │ scheduler │   │  reverb   ││
        │  │(nginx+ │   │(queue    │   │(schedule: │   │(reverb:   ││
        │  │php-fpm)│   │ worker)  │   │  work)    │   │  start)   ││
        │  └───┬────┘   └────┬─────┘   └─────┬─────┘   └─────┬─────┘│
        │      │  broadcast()│ pushes over BROADCAST_REVERB_* │      │
        │      │             └───────────────────────────────┘      │
        │      │                     (internal HTTP push)            │
        │      ▼                                                     │
        │  ┌──────────┐        ┌──────────┐                          │
        │  │ postgres │        │  redis   │◄── queue + cache backend │
        │  └──────────┘        └──────────┘    for horizon + reverb  │
        │                                       scaling (optional)   │
        │  ┌──────────┐  (dev/test only, not in prod compose.yml)    │
        │  │ mailpit  │                                              │
        │  └──────────┘                                              │
        └───────────────────────────────────────────────────────────┘
```

Trace the primary use case (a chat message streaming to the browser):
user sends a message → `app` container's HTTP request dispatches a queued
job → `horizon` container's worker (`ProcessChatMessage`) processes it and
calls `broadcast()` → the PHP process pushes the event over the **internal**
`BROADCAST_REVERB_HOST`/`PORT` to the `reverb` container's HTTP API → the
`reverb` container fans it out over the **public-facing** WebSocket connection
the browser opened directly (or via reverse proxy) using `VITE_REVERB_*`
credentials baked into the browser bundle at build time → `stream.js` renders
the incoming tokens.

### Recommended Project Structure (compose files)

```
/
├── Dockerfile                # existing, multi-stage: composer → frontend → production
├── compose.yml                # PRODUCTION reference (self-hosters). Add: reverb service.
├── compose.dev.yml            # REPLACE Sail's stock file with a purpose-built dev/test
│                               # compose: builds Dockerfile locally, includes every
│                               # service (postgres, redis, horizon, reverb, mailpit)
└── .env.example                # already has REVERB_* keys; needs no change for compose,
                                 # but compose.yml's env block needs REVERB_* additions
```

### Pattern 1: Sidecar service, same image, different command (already established in this repo)

**What:** `horizon` and `scheduler` in `compose.yml` reuse the exact same
`ghcr.io/relaticle/relaticle:latest` image as `app`, just override `command:`
and disable `AUTORUN_ENABLED` so migrations/caching only run once (in `app`).
**When to use:** Any additional long-running Artisan process (Reverb fits this
exactly — it's `php artisan reverb:start`, a long-running daemon like Horizon).
**Example — extend for Reverb, following the exact shape already in the file:**
```yaml
# Source: compose.yml:56-91 (horizon service, adapted) + serversideup/php
# official docs (https://serversideup.net/open-source/docker-php/docs/framework-guides/laravel/reverb)
  reverb:
    image: ghcr.io/relaticle/relaticle:latest
    restart: unless-stopped
    command: ["php", "/var/www/html/artisan", "reverb:start", "--host=0.0.0.0", "--port=8080"]
    stop_signal: SIGTERM
    environment:
      APP_KEY: ${APP_KEY:?APP_KEY is required}
      APP_URL: ${APP_URL:-http://localhost}
      REVERB_APP_ID: ${REVERB_APP_ID:?REVERB_APP_ID is required}
      REVERB_APP_KEY: ${REVERB_APP_KEY:?REVERB_APP_KEY is required}
      REVERB_APP_SECRET: ${REVERB_APP_SECRET:?REVERB_APP_SECRET is required}
      REDIS_HOST: redis
      REDIS_PASSWORD: ${REDIS_PASSWORD:-null}
      REDIS_PORT: 6379
      AUTORUN_ENABLED: "false"
    depends_on:
      redis:
        condition: service_healthy
    healthcheck:
      test: ["CMD", "healthcheck-reverb"]
      start_period: 10s
```
`healthcheck-reverb` is the `serversideup/php` image's own built-in health
probe binary — same family as `healthcheck-horizon`/`healthcheck-schedule`,
which `compose.yml` already uses for `horizon`/`scheduler`
[CITED: https://serversideup.net/open-source/docker-php/docs/framework-guides/laravel/reverb].

### Pattern 2: Internal vs. public Reverb addressing (must not be conflated)

**What:** Three *different* env-var families point at Reverb, and this
project's `config/broadcasting.php` already encodes the split
[VERIFIED: config/broadcasting.php:1-14]:
- `REVERB_SERVER_HOST`/`REVERB_SERVER_PORT` — where the daemon **binds**
  inside its own container (`0.0.0.0:8080`, never touched by compose env).
- `BROADCAST_REVERB_HOST`/`PORT`/`SCHEME` — where **server-side PHP**
  (in `app`/`horizon`) sends the internal HTTP push. In compose, this must be
  the Docker service name `reverb` on port `8080`, plain HTTP (no TLS between
  containers on a private network).
- `REVERB_HOST`/`PORT`/`SCHEME` (+ `VITE_REVERB_*`) — where the **browser**
  connects, i.e. the public domain/port behind the reverse proxy, `https`.

**When to use:** Every time you write a compose `environment:` block touching
Reverb. Confusing these breaks either the internal push or the public socket.
**Pitfall this prevents:** `config/broadcasting.php`'s loopback auto-detection
(`$reverbHostIsLoopback`) only fires for literal `127.0.0.1`/`::1`/`localhost`
— **the Docker service name `reverb` is not in that list**, so leaving
`BROADCAST_REVERB_SCHEME` unset would make the app try `https` to a container
that only speaks plain HTTP on its internal port, silently failing every
broadcast. `BROADCAST_REVERB_HOST=reverb` MUST be paired with an explicit
`BROADCAST_REVERB_SCHEME=http` in `app`/`horizon`/`scheduler`'s environment
[VERIFIED: config/broadcasting.php:5-6, confirmed by
tests/Feature/Chat/BroadcastReverbConfigTest.php:5-27, which only asserts the
`http` fallback for the three literal loopback strings].

### Anti-Patterns to Avoid

- **Running Reverb inside the `app` container via a supervisor hack:** the
  base image and this repo's own pattern (horizon/scheduler as separate
  services) both point to a dedicated container. Don't reintroduce a
  multi-process container just to save one compose entry.
- **Copying `.env` into the frontend build stage to "fix" `VITE_REVERB_*`:**
  the frontend stage builds once, in CI, for a shared multi-tenant image.
  Baking one self-hoster's domain into a shared image is wrong for everyone
  else pulling `ghcr.io/relaticle/relaticle:latest`. See Pitfall 1.
- **Assuming `compose.dev.yml`'s existing `meilisearch`/`selenium` services
  need to be preserved:** [VERIFIED: composer.json — no `laravel/scout` or
  meilisearch dependency exists] these are Sail installer defaults, not
  wired to anything in this app. Don't carry them forward without checking;
  they add container start time for nothing.

## Common Pitfalls

### Pitfall 1: `VITE_REVERB_*` is baked at build time into a shared, generically-built image

**What goes wrong:** The published `ghcr.io/relaticle/relaticle:latest` image
is built once by CI for every self-hoster. Vite inlines
`import.meta.env.VITE_REVERB_APP_KEY/HOST/PORT/SCHEME`
[VERIFIED: resources/js/echo.js:8-12] into the compiled JS at
`pnpm run build` time [VERIFIED: Dockerfile:40, no `VITE_*` build args or
`.env` passed into the `frontend` stage]. There is no single correct value:
every self-hoster has a different `APP_URL`/domain and a different
`REVERB_APP_KEY` they generate themselves (per self-hosting.md's
"generate your own secrets" convention). Simply adding a `reverb` compose
service does **not** fix chat streaming for self-hosters — the browser
bundle will still try to connect with `undefined`/wrong credentials.
**Why it happens:** Vite's `import.meta.env.VITE_*` substitution is a static,
compile-time replacement. It cannot read `.env`/process env at request time
in the browser; only the server that serves the JS file could vary it per
deployment, and nginx serves the static built file verbatim.
**How to avoid — options for the planner/user to choose between (this is a
genuine design decision, not something research alone resolves):**
1. **Runtime injection via a server-rendered value.** Replace the static
   `import.meta.env.VITE_REVERB_*` reads in `echo.js` with values read from a
   `<meta>` tag (or inline `<script>` global) rendered by Blade at request
   time, sourced from `config('reverb.apps.apps.0...')` / `env()` on the
   server, which **is** correctly set per-deployment already via
   `compose.yml`'s `environment:` block. This is the standard fix for "one
   build, many deploys" apps and requires no Dockerfile changes.
2. **Document that self-hosters who want live chat streaming must build their
   own image** with `--build-arg VITE_REVERB_APP_KEY=...` etc. passed to
   `docker build`, rather than pulling the generic `ghcr.io` image. Simpler to
   implement, but breaks the "download compose.yml, `docker compose up -d`"
   quick start promise the docs already make, for a core feature.
3. **Derive what can be derived from `window.location` client-side** (host,
   scheme, port can often be inferred if Reverb is reverse-proxied same-origin
   under a subpath) and keep only the non-secret `REVERB_APP_KEY` as the one
   value that must travel — but that value still needs option 1 or 2 to reach
   the browser correctly.
Option 1 is the closest fit for this codebase's existing "single published
image, many self-hosted configs" model and requires no new Docker image
variants — flag it as the likely direction, but this is a call for the user
via `/gsd-discuss-phase`, not a fact this research can assert.
**Warning signs:** Chat UI never leaves the "thinking…" state in a fresh
Docker Compose install even though `docker compose logs reverb` shows Reverb
running fine; browser console shows a WebSocket connection to `undefined` or
to `localhost` from a remote server.

### Pitfall 2: `compose.dev.yml` doesn't build the project's own Dockerfile

**What goes wrong:** Sail's stock `compose.dev.yml` builds
`vendor/laravel/sail/runtimes/8.5/Dockerfile` (a generic Sail runtime with the
whole repo bind-mounted in) [VERIFIED: compose.dev.yml:2-8], not this
project's own multi-stage `Dockerfile`. A developer running `sail up` never
exercises the actual production image build path locally; the first time the
real `Dockerfile` gets built and run end-to-end is in CI
(`docker-publish.yml`) or on `docker compose up` against the *published*
prod image. The phase goal explicitly says local dev/test **must build the
image(s)**.
**Why it happens:** `compose.dev.yml` was never edited after `sail:install`
generated it; the project's actual local dev habit (per `composer.json`'s
`dev` script and CLAUDE.md's Herd guidance) runs the app **natively**
(`php artisan serve`), using Sail purely for backing services.
**How to avoid:** Give the dev/test compose file a `build:` block pointing at
the root `Dockerfile`, likely with a new build `target:` (e.g. add a `local`
or `development` stage to the Dockerfile, or reuse `production` with a bind
mount over it) so `docker compose -f compose.dev.yml up --build` produces a
locally-built image, not a downloaded one, before every dev/test session.
**Warning signs:** A Dockerfile change (new PHP extension, changed build
step) doesn't surface as broken until CI runs, days after the edit.

### Pitfall 3: Missing services in local dev vs. the phase's explicit "all 3rd-party services" requirement

**What goes wrong:** `compose.dev.yml` has no Horizon container and no
Reverb container [VERIFIED: compose.dev.yml:1-107, full file read, neither
service present]. A developer testing chat locally today must manually run
`php artisan horizon` (which `bin/workspace-queue.sh` does for the *native*
dev path only) and has **no way at all** to run Reverb locally short of
manually invoking `php artisan reverb:start` in a spare terminal — confirmed
by a Phase 1 session note (`.planning/phases/01-.../01-03-SUMMARY.md`)
describing exactly this ad hoc workaround and the origin-mismatch bug it hit
(`127.0.0.1` vs `localhost` triggering Reverb's `4009 Origin not allowed`).
**Why it happens:** Same root cause as Pitfall 2 — `compose.dev.yml` was
never updated after the Ollama Cloud / chat streaming work (Phase 1) started
depending on Reverb.
**How to avoid:** New dev compose must include `horizon` and `reverb`
services following Pattern 1, plus wire `mailpit` so `MAIL_MAILER=smtp`,
`MAIL_HOST=mailpit`, `MAIL_PORT=1025` work out of the box (today `mailpit`
container exists in the Sail file but nothing points at it — `.env.example`'s
default `MAIL_MAILER=log` means it silently goes unused)
[VERIFIED: .env.example — `MAIL_MAILER=log`; compose.dev.yml:82-88 — `mailpit`
service present but no app-side `MAIL_*` env wiring anywhere in that file].
**Warning signs:** "Works on my machine" for chat/mail features because the
tester happened to run the native `composer run dev` path with Herd, not the
Docker path the phase is meant to validate.

### Pitfall 4: Postgres version drift between prod and dev compose

**What goes wrong:** `compose.yml` pins `postgres:17-alpine`
[VERIFIED: compose.yml:129], `compose.dev.yml` pins `postgres:18-alpine`
[VERIFIED: compose.dev.yml:31]. A schema/extension behavior difference
between PG17 and PG18 could pass locally and fail (or vice versa) against the
documented production target.
**Why it happens:** Sail's installer defaults to whatever Postgres version was
current when `sail:install` last ran; nobody has since reconciled the two
files.
**How to avoid:** Pin the same major version in both compose files. This
research cannot assert which version is "correct" — that's a call for the
user (stay on 17 to match documented/tested production, or move both to 18).
Flag as an open question rather than silently picking one.
**Warning signs:** A migration or query works in `sail up` but fails against
the self-hosted `compose.yml` stack, or vice versa.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| Docker | Building/running any compose file | Not probed in this session (research is desk/code review only; execution environment for this phase should verify) | — | — |
| Docker Compose v2 | `compose.yml`/`compose.dev.yml` | Not probed | — | — |

**Note:** This research pass did not have shell access to a Docker daemon in
this sandbox (Phase 1's session notes independently confirm this same
constraint: *"This execution sandbox has no Herd... Docker services (pgsql,
redis, meilisearch, mailpit) ARE running"* — i.e. Docker itself was available
in that session but not this research pass). The planner should add an
explicit Docker/Compose availability check as a task-zero verification step
for whichever execution environment runs Phase 2's plans, since the entire
phase is unverifiable without it.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | Option 1 (runtime meta-tag injection of Reverb client config) is the right fix for Pitfall 1, vs. options 2/3 | Pitfall 1 | If the user actually wants self-hosters to build their own image, the plan's approach and the self-hosting docs' "5-step quick start" promise need to change together — get this decided before planning, not during execution |
| A2 | Postgres 17 (matching current `compose.yml`) is the "correct" version to standardize on, not 18 | Pitfall 4 | Picking wrong version could require a second migration pass later; low risk technically (both are recent PG releases) but worth one confirmation question |
| A3 | `compose.dev.yml` should be replaced/rewritten rather than kept as a Sail file with a second new file added alongside it | Architecture Patterns / Pitfall 2 | If the team wants to keep Sail for fast native-loop dev and add a *third*, separate "build-and-test-the-real-image" compose file instead of replacing `compose.dev.yml`, the plan's file layout changes; low risk, just a naming/file-count decision |
| A4 | `healthcheck-reverb` binary exists in `serversideup/php:8.5-fpm-nginx` at the pinned tag, mirroring `healthcheck-horizon`/`healthcheck-schedule` | Pattern 1 code example | [CITED] from the vendor's own Reverb guide page, not independently verified by pulling the image in this session — if absent, swap for a plain TCP/HTTP check against Reverb's port |

**If this table is empty:** N/A — see above.

## Open Questions

1. **How should `VITE_REVERB_*` reach the browser for a shared, generically-built image?**
   - What we know: the values are Vite build-time constants today; the image
     is built once in CI for everyone.
   - What's unclear: whether the team wants a code change (runtime injection),
     a docs/process change (self-hosters build their own image), or something
     else.
   - Recommendation: raise this explicitly in `/gsd-discuss-phase` before
     planning tasks — it changes whether this phase touches
     `resources/js/echo.js` and Blade layout files, or only compose/docs.

2. **Does Relaticle's own production (Forge-deployed) need the `reverb`
   container/service at all, or is `compose.yml` purely a self-hoster
   artifact?**
   - What we know: `deploy.yml` triggers Forge, not `docker compose up`, for
     Relaticle's own production. `compose.yml` is fetched directly from
     GitHub by self-hosting docs.
   - What's unclear: whether Relaticle's own Forge-based production already
     runs Reverb some other way (e.g. Forge's own daemon manager), making the
     compose gap self-hoster-only, or whether Relaticle's own production has
     the *same* live gap right now.
   - Recommendation: ask the user directly — this determines urgency and
     whether Forge-side config also needs updating (out of this phase's
     Docker Compose scope, but worth flagging if broken in prod today).

3. **Should the dev/test compose file replace or supplement the native
   Herd/`composer run dev` workflow?**
   - What we know: CLAUDE.md documents Herd as the current local serving
     method; `composer run dev` is a fully native (non-Docker) alternative
     already in daily use.
   - What's unclear: whether Phase 2's dev compose is meant to become the
     *primary* recommended local workflow, or an additional CI-adjacent
     "test the real image" path alongside Herd for everyday coding.
   - Recommendation: default to "supplement, don't replace" — Herd/native dev
     stays the fast inner loop; the new compose file is for validating the
     containerized stack end-to-end (matches CLAUDE.md's existing chat
     verification rule: *"Chat features MUST be verified against the
     production-shaped stack... Horizon running, QUEUE_CONNECTION=redis,
     Reverb up"*).

## Sources

### Primary (HIGH confidence — read directly from this repository this session)
- `Dockerfile`, `compose.yml`, `compose.dev.yml`, `.dockerignore`
- `.github/workflows/docker-publish.yml`, `deploy.yml`, `ci.yml`, `tests.yml`, `security-audit.yml`
- `bootstrap/app.php`, `config/reverb.php`, `config/broadcasting.php`, `config/health.php`
- `app/Providers/HealthServiceProvider.php`
- `resources/js/echo.js`, `packages/Chat/resources/js/chat/stream.js`, `vite.config.js`
- `tests/Feature/Chat/BroadcastReverbConfigTest.php`
- `packages/Documentation/resources/content/docs/guides/self-hosting.md`
- `.env.example`, `.env.ci`, `.env.testing`, `composer.json`, `bin/workspace-queue.sh`
- `.planning/STATE.md`, `.planning/REQUIREMENTS.md`, `.planning/ROADMAP.md`, `.planning/codebase/*.md`
- `.planning/phases/01-ollama-cloud-provider-integration/01-03-SUMMARY.md`

### Secondary (MEDIUM confidence — official vendor docs, web-verified this session)
- [serversideup/php: Reverb framework guide](https://serversideup.net/open-source/docker-php/docs/framework-guides/laravel/reverb) — separate-container pattern, `healthcheck-reverb`, port/env split
- [serversideup/php: Environment Variable Specification](https://serversideup.net/open-source/docker-php/docs/reference/environment-variable-specification) — full `AUTORUN_*` reference

### Tertiary (LOW confidence — general web search, not independently verified)
- Various Medium/dev.to articles on Reverb + nginx WebSocket reverse-proxy configuration (used only to corroborate the general "Upgrade/Connection header + no buffering" requirement, which is standard nginx WS knowledge, not Relaticle-specific)

## Metadata

**Confidence breakdown:**
- Existing infrastructure inventory: HIGH — every claim read directly from the file in this session, with line citations
- Reverb gap analysis (Pitfalls 1-3): HIGH on "the gap exists," MEDIUM on "the fix approach" (genuine design decision, flagged as assumption/open question rather than asserted)
- serversideup/php-specific patterns (healthcheck-reverb, separate-container guidance): MEDIUM — official vendor docs, not independently executed against the pinned image tag in this session

**Research date:** 2026-09-03
**Valid until:** 30 days (stable Laravel/Docker ecosystem; re-check sooner if `laravel/reverb` or `serversideup/php` majors bump)

# Phase 2: Docker Compose Orchestration - Context

**Gathered:** 2026-09-03
**Status:** Ready for planning

<domain>
## Phase Boundary

Close the gaps RESEARCH.md found in the existing (already mature) Docker Compose
orchestration: add a `reverb` service to production `compose.yml` following the
established horizon/scheduler sidecar pattern, fix the fact that Reverb's browser
credentials are currently baked into the shared image at build time (breaking chat
streaming for every self-hoster), and replace `compose.dev.yml`'s stock Sail file
with a purpose-built dev/test compose that builds the project's own `Dockerfile`
and includes every third-party service (Postgres, Redis, Horizon, Reverb, Mailpit)
wired so chat streaming and mail work out of the box. Scope is self-hosters and
the published `ghcr.io/relaticle/relaticle` image plus local dev/test; Relaticle's
own Forge-deployed production is explicitly out of scope.

</domain>

<decisions>
## Implementation Decisions

These four decisions were raised as open questions by RESEARCH.md (no prior
discuss-phase had run for this phase) and confirmed directly with the user
before planning, since each changes which files the plan touches.

### Reverb browser credentials (Pitfall 1)
- **D-01:** Fix `VITE_REVERB_*` via **runtime injection**, not build-time baking. Change
  `resources/js/echo.js` to read Reverb connection info from a server-rendered value
  (e.g. a `<meta>` tag or inline script global set by Blade at request time from
  `config('reverb.*')`/`env()`), instead of `import.meta.env.VITE_REVERB_*`. This works
  off the one shared `ghcr.io/relaticle/relaticle:latest` image for every self-hoster's
  distinct domain/key, and requires no Dockerfile build-arg changes. — **Reversibility:**
  one-way-ish in spirit (touches a client bootstrap file consumed by every page that
  loads chat), but technically reversible; not a data-migration-class decision.
- Rejected: self-hosters build their own image (breaks the existing "download compose.yml,
  `docker compose up -d`" quick start for a core feature); client-derives-from-`window.location`
  (still needs D-01's mechanism to deliver the non-derivable `REVERB_APP_KEY`).

### Production scope (Pitfall / Open Question 2)
- **D-02:** This phase is **self-hoster scope only**. `compose.yml` + the `reverb`
  service target third-party self-hosters pulling the published image. Relaticle's own
  Forge-deployed production (`deploy.yml`) is explicitly out of scope for this phase's
  Docker Compose work. If Forge production turns out to also be missing a working Reverb
  daemon, that is a separate concern to flag, not fix, here.

### Dev workflow relationship (Open Question 3)
- **D-03:** The new dev/test compose **supplements**, does not replace, Herd/native
  `composer run dev`. Herd/native stays the fast everyday inner loop. The new compose
  file exists to validate the full containerized stack end-to-end, matching CLAUDE.md's
  existing chat verification rule (Horizon running, `QUEUE_CONNECTION=redis`, Reverb up,
  production-shaped stack) before reporting chat changes done.

### Postgres version (Pitfall 4)
- **D-04:** Standardize both `compose.yml` and the new dev compose on **Postgres 17**
  (`postgres:17-alpine`), matching the currently documented/tested production compose.
  Do not move to 18.

### Claude's Discretion
- Exact Blade/config mechanism for D-01's runtime injection (meta tag vs inline script,
  exact `config()`/`env()` read) — pick the pattern that best matches how `echo.js` and
  Blade layouts already share other request-time values in this codebase, confirm with a
  live browser check that the socket connects with the right key/host per Docker Compose
  config.
- Whether `compose.dev.yml` is edited in place or replaced outright — functionally it
  must end up building the project's own `Dockerfile` and including postgres, redis,
  horizon, reverb, and mailpit, wired so `MAIL_MAILER=smtp`/`MAIL_HOST=mailpit` and
  Reverb's internal vs public host/scheme split (`BROADCAST_REVERB_HOST=reverb`,
  `BROADCAST_REVERB_SCHEME=http`) are both correct out of the box.
- Whether to drop `compose.dev.yml`'s unused `meilisearch`/`selenium` services (no
  `laravel/scout` dependency exists) — RESEARCH.md flags them as Sail installer
  defaults not wired to anything; safe to drop unless a reason to keep surfaces during
  planning.
- Reverb healthcheck implementation detail (`healthcheck-reverb` binary per
  `serversideup/php`'s Reverb guide, vendor-cited but not independently verified against
  the pinned image tag in this session) — verify it exists at build/verification time;
  fall back to a plain TCP/HTTP check against Reverb's port if absent.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Research (produced for this phase, HIGH confidence on infrastructure inventory)
- `.planning/phases/02-orchestration-with-docker-compose-prepare-orchestration-to-e/02-RESEARCH.md` —
  full infrastructure inventory, architecture patterns (sidecar service pattern, internal
  vs public Reverb addressing), all 4 pitfalls, and the open questions this CONTEXT.md
  resolves

### Project planning
- `.planning/ROADMAP.md` §Phase 2 — goal: "Prepare orchestration to ease both local
  testing and production deployment. Local tests/dev MUST build the image(s) and come
  with all the separate 3rd party services."
- `.planning/REQUIREMENTS.md` — no v2 requirement IDs mapped to this phase yet (all
  existing `OLLAMA-01`..`OLLAMA-06` requirements belong to Phase 1 and are Complete);
  the planner should derive acceptance criteria from the ROADMAP goal and the decisions
  above rather than block on requirement IDs

### Existing infrastructure this phase modifies (see RESEARCH.md for line citations)
- `Dockerfile` — existing multi-stage production build, no `VITE_REVERB_*` build args passed
- `compose.yml` — production-shaped, self-hoster-facing; has `app`, `horizon`, `scheduler`,
  `postgres`, `redis`; missing `reverb`
- `compose.dev.yml` — currently Laravel Sail's stock generated file; builds Sail's generic
  runtime, not this project's `Dockerfile`; missing `horizon` and `reverb`
- `resources/js/echo.js` — reads `import.meta.env.VITE_REVERB_*` at Vite build time; the
  file D-01 changes
- `packages/Documentation/resources/content/docs/guides/self-hosting.md` — public docs,
  currently has zero mentions of Reverb/WebSockets; needs updating alongside the compose
  changes

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `compose.yml`'s `horizon`/`scheduler` services — exact sidecar pattern to copy for the
  new `reverb` service (same image, different `command:`, `AUTORUN_ENABLED=false`).
- `config/reverb.php` / `config/broadcasting.php` — already correctly distinguish
  `REVERB_SERVER_HOST/PORT` (daemon bind) from `BROADCAST_REVERB_HOST/PORT/SCHEME`
  (internal server-to-server push) from `REVERB_HOST/PORT/SCHEME` (public client-facing);
  no config code changes needed, only correct compose `environment:` wiring.
- `tests/Feature/Chat/BroadcastReverbConfigTest.php` — already asserts the loopback
  `http` auto-detection behavior; useful as a reference for what NOT to rely on (the
  Docker service name `reverb` is not a literal loopback string, so
  `BROADCAST_REVERB_SCHEME=http` must be set explicitly in compose, not assumed).

### Established Patterns
- Sidecar-per-long-running-process (horizon, scheduler) is the established pattern for
  Reverb too — do not run it inside the `app` container via a supervisor hack.

### Integration Points
- `compose.yml` — add `reverb` service; add `REVERB_*`/`BROADCAST_REVERB_*` env to
  `app`/`horizon`/`scheduler`'s existing `environment:` blocks as needed.
- `compose.dev.yml` — rework to build root `Dockerfile`, add `horizon` + `reverb`
  services, wire `mailpit` to `MAIL_MAILER=smtp`/`MAIL_HOST=mailpit`/`MAIL_PORT=1025`,
  pin `postgres:17-alpine` (D-04).
- `resources/js/echo.js` — switch from `import.meta.env.VITE_REVERB_*` to a runtime-read
  source per D-01.
- `packages/Documentation/resources/content/docs/guides/self-hosting.md` — document the
  new `reverb` service and its architecture-table row.

</code_context>

<specifics>
## Specific Ideas

No additional UI/UX requests beyond the ROADMAP goal. Discussion covered exactly the
four judgment calls RESEARCH.md flagged as needing a human decision (Reverb browser
credential delivery, production scope, dev workflow relationship, Postgres version) —
all other implementation details are delegated to research/planning as usual.

</specifics>

<deferred>
## Deferred Ideas

- Whether Relaticle's own Forge-deployed production is missing a working Reverb daemon
  today (D-02 scoped this phase to self-hosters only) — worth a separate follow-up check,
  not part of this phase.
- A Reverb-specific Spatie Health check (`HealthServiceProvider.php` currently registers
  none for Reverb, and Spatie Health ships none out of the box) — flagged by research as
  planner discretion, not a locked requirement; defer unless planning finds it cheap to add.

</deferred>

---

*Phase: 2-Docker Compose Orchestration*
*Context gathered: 2026-09-03*

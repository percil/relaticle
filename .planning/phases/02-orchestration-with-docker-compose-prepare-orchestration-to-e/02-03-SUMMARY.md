---
phase: 02-orchestration-with-docker-compose-prepare-orchestration-to-e
plan: 03
subsystem: docs
tags: [docker-compose, reverb, self-hosting, documentation]

requires:
  - phase: 02-orchestration-with-docker-compose-prepare-orchestration-to-e
    provides: "02-01's runtime-injected Reverb client credentials and dev compose stack, 02-02's reverb sidecar in production compose.yml"
provides:
  - "public self-hosting guide documenting the six-container stack, Reverb secrets, client addressing, and WebSocket reverse-proxy routing"
  - "end-to-end phase verification against a rebuilt dev stack and the full CI-equivalent lint gate"
affects: [self-hosting-docs]

actuals:
  tokens: 2724
  tasks: 2
  commits: 1

tech-stack:
  added: []
  patterns:
    - "Dedicated ws.* subdomain reverse-proxy pattern for the reverb container across Nginx, Caddy and Traefik, since it is published on a separate port from the app container and cannot share the app's path-based routing"

key-files:
  created: []
  modified:
    - packages/Documentation/resources/content/docs/guides/self-hosting.md

key-decisions:
  - "Proxied the reverb container via a dedicated ws.crm.example.com subdomain in all three reverse-proxy examples (Nginx, Caddy, Traefik), rather than a shared path under the app's domain: Reverb's WebSocket endpoint is served at a fixed /app/{key} path that risks colliding with the CRM panel's own /app path-mode routing, and REVERB_HOST is already documented as independently configurable, so a subdomain is the simplest correct pattern to copy-paste."
  - "Added REVERB_APP_ID/KEY/SECRET to the Quick Start's 5-step .env example and to the Dokploy/Coolify environment-variable snippets (Rule 2, missing critical functionality): compose.yml now guards all three as `:?...is required`, so a self-hoster following the literal steps as written before this change would have hit a container refusing to start with no explanation in the guide."

requirements-completed: [D-02]

coverage:
  - id: D1
    description: "Self-hosting guide documents the reverb container, its three required secrets, and the public-vs-private key distinction"
    requirement: D-02
    verification:
      - kind: other
        ref: "grep -q REVERB_APP_SECRET/REVERB_APP_ID/REVERB_SCHEME self-hosting.md"
        status: pass
      - kind: other
        ref: "grep -c reverb self-hosting.md (22, well above the required 6)"
        status: pass
    human_judgment: false
  - id: D2
    description: "Container counts (architecture table sentence, Dokploy deploy step) are consistent at six, with no stale '5 containers' string remaining"
    requirement: D-02
    verification:
      - kind: other
        ref: "grep -v '^ *#' self-hosting.md | grep -ci '5 containers|five containers' == 0"
        status: pass
    human_judgment: false
  - id: D3
    description: "Nginx, Caddy and Traefik reverse-proxy subsections each carry WebSocket routing for the reverb container"
    requirement: D-02
    verification:
      - kind: other
        ref: "manual read-through of Reverse Proxy and SSL section, self-hosting.md lines 227-322"
        status: pass
    human_judgment: false
  - id: D4
    description: "Architecture, documentation and chat test suites pass against the edited file, and the em-dash/YAML front-matter rules hold"
    requirement: D-02
    verification:
      - kind: other
        ref: "vendor/bin/pest --no-tia tests/Arch/ tests/Feature/Chat/ tests/Feature/Documentation/ (1296/1296 passed)"
        status: pass
    human_judgment: false
  - id: D5
    description: "Whole phase re-verified end to end: rebuilt dev stack all six services healthy, Reverb WebSocket handshake proven post-rebuild, and the full CI-equivalent lint/type/coverage gate passes"
    requirement: D-02
    verification:
      - kind: other
        ref: "docker compose -f compose.dev.yml up -d --build --wait (all 6 services healthy); socket handshake returned pusher:connection_established; composer test:lint, vendor/bin/phpstan analyse, composer test:type-coverage all exit 0; docker compose -f compose.dev.yml down exit 0, no containers left running"
        status: pass
    human_judgment: false
  - id: D6
    description: "Real browser chat streaming walkthrough against the rebuilt dev stack"
    requirement: D-02
    verification: []
    human_judgment: true
    rationale: "CLAUDE.md requires chat changes to be walked in a real browser against a production-shaped stack. Neither Herd nor agent-browser is installed in this execution sandbox (same constraint recorded against Phase 01 plan 01-02 and Phase 02 plan 02-01 in STATE.md), so this could not be automated here."

duration: ~20min
completed: 2026-09-03
status: complete
---

# Phase 2 Plan 3: Self-Hosting Guide Reverb Documentation Summary

**Documented the reverb container, its three required secrets, client-facing addressing, and WebSocket reverse-proxy routing in the public self-hosting guide, then re-verified the whole phase end to end against a rebuilt six-service dev stack and the full CI-equivalent lint/type/coverage gate.**

## Performance

- **Duration:** ~20 min
- **Tasks:** 2
- **Files modified:** 1

## Accomplishments
- Architecture table and both "5 containers" prose mentions (Architecture section, Dokploy deploy step) raised to six, with a new `reverb` row placed after `scheduler` matching `compose.yml`'s own ordering.
- `REVERB_APP_ID`, `REVERB_APP_KEY` and `REVERB_APP_SECRET` documented in the Required environment variable table with generation commands, explicitly stating `REVERB_APP_KEY` is public by design (like a Pusher key) and `REVERB_APP_SECRET` must never be exposed.
- `REVERB_HOST`, `REVERB_PORT` and `REVERB_SCHEME` documented in the Application table with their `compose.yml` defaults (`localhost`, `8080`, `http`), plus a note that the browser reads these from the server at request time and that live streaming needs an image built at or after this change.
- Networking paragraph corrected: it previously claimed only the app container exposes a host port, which plan 02-02 made false.
- Nginx, Caddy and Traefik reverse-proxy subsections each extended with a WebSocket-upgrade example routing a dedicated `ws.crm.example.com` subdomain to the reverb container's published port.
- Quick Start's 5-step `.env` example and the Dokploy/Coolify environment-variable snippets updated to include the three now-required Reverb secrets, so a self-hoster following any of the three onboarding paths as literally written does not hit a container refusing to start.
- Re-ran the whole phase's verification after all three plans landed: rebuilt `compose.dev.yml` from scratch (all 6 services reached healthy), re-proved the Reverb WebSocket handshake against the rebuilt stack, and ran the full CI-equivalent gate (`composer test:lint`, `phpstan analyse`, `test:type-coverage`, and 1296 tests across `tests/Arch/`, `tests/Feature/Chat/`, `tests/Feature/Documentation/`), then brought the stack down cleanly.

## Task Commits

Each task was committed atomically:

1. **Task 1: Document the reverb container, its secrets, and the WebSocket proxy path** - `5986c568` (docs)
2. **Task 2: Verify the whole phase against the running stack and the full lint gate** - no commit (verification only, no production files changed, per the plan's explicit instruction to write no production code in this task)

## Files Created/Modified
- `packages/Documentation/resources/content/docs/guides/self-hosting.md` - reverb architecture row, corrected container counts and Networking paragraph, `REVERB_APP_ID`/`REVERB_APP_KEY`/`REVERB_APP_SECRET` required rows, `REVERB_HOST`/`REVERB_PORT`/`REVERB_SCHEME` application rows, runtime-injection and version-caveat note, WebSocket routing in Nginx/Caddy/Traefik, updated Quick Start and Dokploy/Coolify env snippets, bumped `updated` front matter to `2026-09-03`.

## Decisions Made
- Proxied the reverb container via a dedicated `ws.crm.example.com` subdomain in all three reverse-proxy examples rather than a shared path under the app's domain. Reverb's WebSocket protocol is served at a fixed `/app/{key}` path, which risks colliding with the CRM panel's own `/app` path-mode routing; a subdomain avoids that ambiguity and matches how `REVERB_HOST` is already documented as independently configurable from `APP_URL`.
- Extended the Quick Start, Dokploy and Coolify environment-variable snippets to include the three `REVERB_*` secrets (Rule 2, missing critical functionality). The plan's task 1 scope named the Required/Application tables and the three reverse-proxy subsections explicitly but did not call out these three onboarding snippets; since `compose.yml` guards all three Reverb secrets as `:?...is required`, leaving them out of the copy-paste `.env` examples would have broken every one of the three quick-start paths the guide teaches, for the same reason the Required table exists.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing critical functionality] Quick Start, Dokploy and Coolify env snippets omitted the three now-required Reverb secrets**
- **Found during:** Task 1, final review pass over the whole file after the planned edits.
- **Issue:** The plan's task 1 action items covered the Required/Application tables and the reverse-proxy subsections, but the Quick Start's 5-step `.env` example (the very first thing a reader follows) and the Dokploy/Coolify "add the required variables" snippets still only listed `APP_KEY`, `DB_PASSWORD` and `APP_URL`. Since `compose.yml` (landed in plan 02-02) now guards `REVERB_APP_ID`, `REVERB_APP_KEY` and `REVERB_APP_SECRET` as `:?...is required`, a self-hoster following any of these three paths literally would have every container refuse to start with no clue why from the guide itself.
- **Fix:** Added the three `REVERB_*` secrets, with their `openssl rand -hex 16` generation commands, to the Quick Start step 2/3 code blocks and to both the Dokploy and Coolify environment-variable snippets.
- **Files modified:** `packages/Documentation/resources/content/docs/guides/self-hosting.md`.
- **Committed in:** `5986c568` (part of Task 1's single commit).

---

**Total deviations:** 1 auto-fixed (1 missing critical functionality)
**Impact on plan:** Necessary for the guide's own Required-variables promise to hold across every onboarding path it documents. No scope creep beyond the plan's own stated purpose (a self-hoster who cannot see a documented container should not also hit an undocumented startup failure).

## Issues Encountered
- The plan's literal socket-proof verify command (`curl -sN ... | grep -q 'pusher:connection_established'`) reported no match on this macOS/BSD `grep` even though the handshake succeeded, because the raw WebSocket frame bytes preceding the JSON payload make `grep` (without `-a`) treat the stream as binary and skip the match by default. Re-running the same check with `grep -a` confirmed `pusher:connection_established` was present in the response. This is a local `grep` behavior difference (BSD vs GNU), not a Reverb or compose defect; the underlying proof (Reverb accepted the key and origin, and pushed the connection-established event) held.
- `vendor/bin/phpstan analyse` and the local `pest` suite needed `php -d memory_limit=1G` in this sandbox, matching the same pre-existing environment constraint plan 02-02 recorded (unrelated to this plan's changes).
- This sandbox's native `.env.testing` Postgres credentials do not work against the pre-existing Sail container here (recorded by plan 02-01); ran the Documentation/Arch/Chat suites with `DB_HOST=127.0.0.1 DB_PORT=5433 DB_USERNAME=relaticle DB_PASSWORD=secret DB_DATABASE=relaticle_testing` pointed at the `relaticle-dev-postgres-1` container for the duration of the test run only, no committed file changed for this.

## User Setup Required
None - no external service configuration required.

## Next Phase Readiness
- Phase 2 (Docker Compose Orchestration) is complete: all three plans landed, the whole containerized stack (app, horizon, scheduler, reverb, postgres, redis) builds and reports healthy from a clean `docker compose -f compose.dev.yml up -d --build --wait`, and the public self-hosting guide now matches what `compose.yml` actually starts.
- One item remains for end-of-phase human review: the real-browser chat streaming walkthrough (D6 above), which needs Herd or `agent-browser` and is not available in this execution sandbox.

---
*Phase: 02-orchestration-with-docker-compose-prepare-orchestration-to-e*
*Completed: 2026-09-03*

## Self-Check: PASSED

- `packages/Documentation/resources/content/docs/guides/self-hosting.md`: FOUND
- Commit `5986c568`: FOUND in `git log --oneline --all`

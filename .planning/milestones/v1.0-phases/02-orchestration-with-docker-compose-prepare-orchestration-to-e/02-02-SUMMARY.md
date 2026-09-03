---
phase: 02-orchestration-with-docker-compose-prepare-orchestration-to-e
plan: 02
subsystem: infra
tags: [docker-compose, reverb, broadcasting, self-hosting]

requires:
  - phase: 02-orchestration-with-docker-compose-prepare-orchestration-to-e
    provides: "02-01's runtime-injected Reverb client credentials mechanism (echo.js reads server-rendered meta tags), independent of this plan's compose.yml changes"
provides:
  - "reverb sidecar service in production compose.yml"
  - "internal (BROADCAST_REVERB_*) and browser-facing (REVERB_*) Reverb addressing wired onto app/horizon/scheduler"
  - "test coverage pinning the compose addressing contract in config/broadcasting.php"
affects: [self-hosting-docs, deploy-compose]

actuals:
  tokens: 1575
  tasks: 2
  commits: 2

tech-stack:
  added: []
  patterns:
    - "Sidecar-per-process compose services (reverb joins horizon/scheduler: same image, different command, AUTORUN_ENABLED=false)"

key-files:
  created: []
  modified:
    - compose.yml
    - tests/Feature/Chat/BroadcastReverbConfigTest.php

key-decisions:
  - "healthcheck-reverb binary confirmed present in ghcr.io/relaticle/relaticle:latest (RESEARCH.md assumption A4 resolved as true for this image); used directly, no TCP fallback needed."

patterns-established:
  - "Internal vs public Reverb addressing family split enforced in compose.yml: BROADCAST_REVERB_HOST/PORT/SCHEME (server-to-server, explicit http) never conflated with REVERB_HOST/PORT/SCHEME (browser-facing, independently configurable for reverse-proxy TLS termination)."

requirements-completed: [D-02, D-04]

coverage:
  - id: D1
    description: "reverb service added to compose.yml, following the horizon/scheduler sidecar pattern, with published port, credential env, redis dependency and healthcheck-reverb probe"
    requirement: D-02
    verification:
      - kind: other
        ref: "docker compose -f compose.yml config --services (exactly app horizon postgres redis reverb scheduler)"
        status: pass
      - kind: other
        ref: "docker run --rm --entrypoint sh ghcr.io/relaticle/relaticle:latest -c 'command -v healthcheck-reverb'"
        status: pass
    human_judgment: false
  - id: D2
    description: "Server-to-server broadcasts from app/horizon/scheduler addressed to reverb over explicit plain HTTP, never TLS, on the internal network"
    requirement: D-02
    verification:
      - kind: other
        ref: "docker compose -f compose.yml config | grep -c 'BROADCAST_REVERB_SCHEME: http' (=3) and 'BROADCAST_REVERB_HOST: reverb' (=3)"
        status: pass
      - kind: unit
        ref: "tests/Feature/Chat/BroadcastReverbConfigTest.php#resolves the compose reverb service to a plain HTTP internal push"
        status: pass
      - kind: unit
        ref: "tests/Feature/Chat/BroadcastReverbConfigTest.php#does not treat the Docker service name reverb as a loopback host"
        status: pass
    human_judgment: false
  - id: D3
    description: "Browser-facing Reverb address (REVERB_HOST/PORT/SCHEME) stays independently configurable from the internal push family, for reverse-proxied TLS termination"
    requirement: D-02
    verification:
      - kind: other
        ref: "docker compose -f compose.yml config (REVERB_HOST/REVERB_PORT/REVERB_SCHEME present only on app, distinct from BROADCAST_REVERB_* on app/horizon/scheduler)"
        status: pass
    human_judgment: false
  - id: D4
    description: "compose.yml stays pinned to Postgres 17, matching the dev compose"
    requirement: D-04
    verification:
      - kind: other
        ref: "docker compose -f compose.yml config | grep -q 'postgres:17-alpine'"
        status: pass
    human_judgment: false

duration: ~10min
completed: 2026-09-03
status: complete
---

# Phase 2 Plan 2: Reverb Sidecar in Production compose.yml Summary

**Added the missing `reverb` service to self-hoster-facing `compose.yml`, wired both Reverb address families (internal plain-HTTP push vs. browser-facing socket) onto `app`/`horizon`/`scheduler`, and pinned the addressing contract with two new tests against `config/broadcasting.php`.**

## Performance

- **Duration:** ~10 min
- **Tasks:** 2
- **Files modified:** 2

## Accomplishments
- `reverb` sidecar service added to `compose.yml`, mirroring the `horizon`/`scheduler` pattern: `reverb:start --host=0.0.0.0 --port=8080`, published port, required `REVERB_APP_ID`/`REVERB_APP_KEY`/`REVERB_APP_SECRET`, `APP_URL` (load-bearing for `allowed_origins`), redis dependency, and a confirmed-present `healthcheck-reverb` probe.
- `BROADCAST_REVERB_HOST=reverb`/`BROADCAST_REVERB_PORT=8080`/`BROADCAST_REVERB_SCHEME=http` added to `app`, `horizon` and `scheduler` so server-to-server broadcasts never attempt TLS against a plain-HTTP container.
- Browser-facing `REVERB_HOST`/`REVERB_PORT`/`REVERB_SCHEME`/`REVERB_APP_KEY`/`BROADCAST_CONNECTION` added to `app` only, independently configurable for a reverse-proxied deployment.
- Two new Pest cases pin the compose contract in `BroadcastReverbConfigTest.php`: the Docker service name resolves to a plain HTTP internal push, and it is not treated as a loopback host (documenting why the explicit scheme can never be dropped from compose).

## Task Commits

Each task was committed atomically:

1. **Task 1: Add the reverb sidecar to compose.yml and wire both Reverb address families** - `20a6fc87` (feat)
2. **Task 2: Pin the compose addressing contract in the existing broadcast config test** - `fb0dd95e` (test)

## Files Created/Modified
- `compose.yml` - new `reverb` service; `BROADCAST_REVERB_*` on app/horizon/scheduler; `REVERB_*`/`BROADCAST_CONNECTION` on app
- `tests/Feature/Chat/BroadcastReverbConfigTest.php` - two new cases pinning the Docker-service-name addressing contract

## Decisions Made
- `healthcheck-reverb` binary probed directly against `ghcr.io/relaticle/relaticle:latest` and confirmed present (`/usr/local/bin/healthcheck-reverb`), resolving RESEARCH.md assumption A4 for this file; used `["CMD", "healthcheck-reverb"]` directly with no TCP fallback needed.
- Task 2 is a pinning test, not a RED/GREEN cycle: `config/broadcasting.php` was already correct per the plan's explicit instruction not to touch it, so all 6 cases (4 pre-existing + 2 new) pass immediately — this is the expected and intended state, not a fail-fast violation.

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered
- `vendor/bin/phpstan analyse` and `vendor/bin/pest --no-tia tests/Arch/` both hit PHP's default 128M memory limit in this execution sandbox (unrelated to this plan's changes — pre-existing environment constraint, not a regression). Re-ran both with `php -d memory_limit=1G`; PHPStan reported 0 errors and the full 69-test Arch suite passed. No production or test code was altered to work around this; it is a local execution environment characteristic only.

## Next Phase Readiness
- `compose.yml` now declares all 6 production-shaped services (`app`, `horizon`, `postgres`, `redis`, `reverb`, `scheduler`) with the internal/browser Reverb addressing split proven by `docker compose config` and pinned by tests.
- Plan 02-03 (self-hosting docs update, per the phase's artifact list) can now document the `reverb` container and its env vars against a compose file that actually has the service.
- No blockers for the remaining phase plan.

---
*Phase: 02-orchestration-with-docker-compose-prepare-orchestration-to-e*
*Completed: 2026-09-03*

## Self-Check: PASSED

- `compose.yml`: FOUND
- `tests/Feature/Chat/BroadcastReverbConfigTest.php`: FOUND
- Commit `20a6fc87`: FOUND in `git log --oneline --all`
- Commit `fb0dd95e`: FOUND in `git log --oneline --all`

---
phase: quick/260911-nxv
plan: 01
subsystem: infra
tags: [docker, compose, ollama-cloud, chat, ci, self-hosting]

requires: []
provides:
  - "Live proof, from inside running containers, that Ollama Cloud is reachable from the dev deployment path (compose.dev.yml) built from current branch code."
  - "Live proof that compose.yml's own env wiring for Ollama Cloud is sound, tested against a working image under a scratch project."
  - "Direct evidence that the pinned default prod image (ghcr.io/relaticle/relaticle:latest) carries zero Ollama Cloud references in either config file."
  - "compose.yml repointed to this fork's own published image target (percil/relaticle:latest) for app, horizon, scheduler, and reverb, on developer-confirmed Docker Hub ownership."
affects: [compose.yml]

actuals:
  tokens: n/a (interactive session, not delegated to a sub-agent)
  tasks: 4
  commits: 1

tech-stack:
  added: []
  patterns:
    - "chat:models and chat:models --probe=<id> are the credential-safe way to verify a chat provider is reachable from inside a container: they print availability and a live accepted/rejected result without ever printing the provider key."

key-files:
  modified:
    - compose.yml

key-decisions:
  - "Q1 (compose.yml image reference): developer chose option B, repoint app/horizon/scheduler/reverb from ghcr.io/relaticle/relaticle:latest to percil/relaticle:latest, on confirmed ownership of the percil/relaticle Docker Hub repository. Only the four image lines were changed, nothing else in the file."
  - "Q2 (HEALTH_CHECKS_ENABLED forwarding): developer chose option A, report only. Neither compose file forwards this variable; a self-hoster cannot turn health checks on in a containerized deployment. No fix applied, left as a follow-up."
  - "The developer explicitly chose to let the restore attempt fail against the not-yet-published percil/relaticle:latest tag rather than substitute the old ghcr.io image or wait for a release. The local relaticle stack is left down as a direct, expected, deliberately chosen consequence, not a defect in this fix."

requirements-completed: [QUICK-260911-NXV]

status: complete
duration: ~2h (interactive, across two permission-gated pauses)
completed: 2026-09-11
---

# Quick Task 260911-nxv: Ollama Cloud Live Availability, Dev and Prod Paths Summary

**Proved with live provider calls, not static reads, that Ollama Cloud is reachable from the dev stack and from compose.yml's own env wiring, and that the pinned default prod image carries no trace of the provider at all. Repointed compose.yml to this fork's own image on the developer's explicit, ownership-confirmed decision; the local relaticle stack is now down until that image is published, which is the deliberately accepted cost of the fix.**

## Proof Log

| Deployment path | Container | Command | Result |
|---|---|---|---|
| Dev (compose.dev.yml, current branch) | app | `chat:models` | gpt-oss:20b and gpt-oss:120b listed under ollama_cloud, available: yes |
| Dev | horizon | `chat:models` | same, available: yes |
| Dev | app | `chat:models --probe=gpt-oss:20b` | accepted by ollama_cloud, supports_tools: yes, exit 0 |
| Dev | horizon | `chat:models --probe=gpt-oss:20b` | accepted by ollama_cloud, supports_tools: yes, exit 0 |
| Dev | horizon | `health:check` (HEALTH_CHECKS_ENABLED=true, exec-time) | Chat Provider: Ollama Cloud, Ok: gpt-oss:20b (appeared only after the probe above wrote a Measurement) |
| Prod, pinned image | n/a (image, not a running container) | `grep -c ollama_cloud config/ai.php packages/Chat/config/chat.php` inside ghcr.io/relaticle/relaticle:latest | both files: 0 |
| Prod, compose.yml's own wiring (relaticle-prodcheck, image relaticle-dev:local) | app | `chat:models` (pre-probe) | ollama_cloud rows show available: no, tools: no (fresh database, unprobed capabilities, not an env defect, confirmed in ModelDescriptor::isAvailable source) |
| Prod, relaticle-prodcheck | app | `chat:models --probe=gpt-oss:20b` | accepted by ollama_cloud, supports_tools: yes, exit 0 |
| Prod, relaticle-prodcheck | horizon | `chat:models` (pre-probe) | same as app, no/no, same explanation |
| Prod, relaticle-prodcheck | horizon | `chat:models --probe=gpt-oss:20b` | accepted by ollama_cloud, supports_tools: yes, exit 0 |
| Prod, relaticle-prodcheck | reverb | `docker compose ... ps` | running, healthy (unlike the as-found relaticle stack, see Findings) |
| Restore, relaticle project (post-fix, image now percil/relaticle:latest) | n/a | `docker compose -f compose.yml -p relaticle up -d` | failed: manifest for percil/relaticle:latest not found: manifest unknown. Expected and accepted by the developer, see Findings. |

No credential value was printed, logged, or written at any point in this session.

## Findings

**1. Pinned default prod image lacks Ollama Cloud entirely.**
Layer: image reference in compose.yml, pointing at upstream Relaticle's published image rather than this fork's own build.
Disposition: fixed at the compose.yml level (image line repointed to percil/relaticle:latest, per the developer's Q1-B decision), but the fix cannot take effect on this machine until the image is actually published. See the Restore Outcome finding below.

**2. Host port 80 belongs to compose.yml, not compose.dev.yml.**
Layer: task-description assumption. compose.dev.yml deliberately publishes ${APP_PORT:-8080}, a settled decision from phase 02-01, so it never collides with native Herd. compose.yml is what actually publishes port 80.
Disposition: reported only. No change made, this is a correct existing design, not a defect.

**3. HEALTH_CHECKS_ENABLED is not forwarded by either compose file.**
Layer: environment variable forwarding. HEALTH_CHECKS_ENABLED defaults false in config/app.php, and neither compose.dev.yml nor compose.yml declares a passthrough, so a self-hoster cannot turn health checks on in a containerized deployment even by setting it in their own .env.
Disposition: deferred, per the developer's Q2-A decision. Reported here as a follow-up, no line added.

**4. reverb never started in the as-found relaticle stack.**
Layer: runtime observation on that specific stack instance, running the pinned upstream ghcr.io image.
Disposition: reported only, not fixed, not investigated further. Under relaticle-prodcheck, using the exact same compose.yml reverb service definition paired with a working image (relaticle-dev:local, built from current branch code), reverb came up healthy and stayed running. This isolates the as-found failure to the image or that stack's prior state rather than to compose.yml's own reverb service definition.

**5. Restore outcome: the relaticle project is now down, as a direct and expected consequence of this session's authorized fix.**
Layer: runtime state of the developer's local deployment, downstream of the compose.yml image change.
Disposition: reported, not a defect. After repointing app, horizon, scheduler, and reverb to percil/relaticle:latest, `docker compose -f compose.yml -p relaticle up -d` failed outright: `manifest for percil/relaticle:latest not found: manifest unknown`. Docker Compose pulls every service's image before creating any container, so the failure aborted the entire `up`, including postgres and redis, which do not even depend on this image. Nothing in the relaticle project is running right now (only pre-existing, unrelated exited orphan containers, mailpit, meilisearch, pgsql, from an earlier stack). The developer was informed this would very likely happen and explicitly chose to accept it rather than substitute the old ghcr.io image or wait for a release. **The stack will come back up once the developer pushes a v* tag**, which triggers `.github/workflows/docker-publish.yml` and actually populates percil/relaticle:latest on Docker Hub. Compared against the Task 1 Step 0 snapshot (app, horizon, scheduler, postgres, redis all healthy; reverb stuck in Created), the deviation is total: the whole project is down pending that publish, not just reverb.

## Plain Answers, Per Deployment Path

- **Dev path (compose.dev.yml, built from current branch code):** Ollama Cloud is reachable. A live call from inside both app and horizon was accepted by the provider.
- **Default prod path, as shipped before this session (ghcr.io/relaticle/relaticle:latest):** Ollama Cloud was not available. The pinned image contained zero references to the provider in either config file, independent of how well the environment variables were forwarded.
- **compose.yml's own service definitions and environment wiring, tested against an image that does carry the provider:** Sound. A live call from inside both app and horizon, under the scratch project relaticle-prodcheck, was accepted by the provider.
- **Prod path after this session's fix (compose.yml now pinned to percil/relaticle:latest):** Not yet operable on this machine, because the image does not exist on Docker Hub yet. It will become the working default prod path once the developer pushes a v* tag.

## Environment Notes (this session only)

- Two container-lifecycle actions were blocked mid-session by the harness's own auto-mode permission classifier (Interfere With Workloads, then, on the final restore, Traffic Redirection and Modify Shared Resources), independent of anything in this plan's own threat model. Both were resolved by the developer explicitly granting permission. No workaround or bypass was attempted; each block was reported and execution paused until permission arrived.
- The dev stack's image (relaticle-dev:local) was rebuilt from current branch code before any proof was taken, since the pre-existing tag predated the origin sync and would have been evidence of nothing.
- The scratch project relaticle-prodcheck was torn down with its volumes removed (`down -v`, the one place in this plan that flag is correct), and its scratch override file was deleted from the session scratchpad, not the repository.

## Self-Check

- compose.yml diff confirmed scoped to exactly the four image lines (app, horizon, scheduler, reverb), verified with `git diff compose.yml` before committing.
- `docker compose -f compose.yml config --quiet` confirmed the file still parses after the edit.
- `docker volume ls | grep prodcheck` returns nothing; relaticle project volumes (relaticle_postgres, relaticle_redis, relaticle_storage, relaticle_sail-*) all still present, untouched throughout.
- No stray image tags: only relaticle-dev:local (rebuilt) and the original ghcr.io/relaticle/relaticle:latest remain, no percil/relaticle:latest was pulled or created locally.
- `git status --short` shows only compose.yml modified, plus the pre-existing untracked paths (.gsd/, .planning/research/.cache/, .planning/state.json), left alone.
- No credential value appears anywhere in this file or in any command output captured during this session.

---
*Quick task: 260911-nxv*
*Completed: 2026-09-11*

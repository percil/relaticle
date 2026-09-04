---
phase: quick-260904-fks
plan: 01
subsystem: infra
tags: [github-actions, docker, ci, docker-hub]

requires: []
provides:
  - "docker-publish.yml triggers only on version tags (push.tags: v*) and pull_request, never on push to main"
  - "docker-publish.yml publishes only to percil/relaticle on Docker Hub, no GitHub Container Registry surface remains"
affects: []

actuals:
  tokens: 3500
  tasks: 2
  commits: 1

tech-stack:
  added: []
  patterns:
    - "Single-registry (Docker Hub only) publish pipeline gated by version tag push, not branch push"

key-files:
  created: []
  modified:
    - ".github/workflows/docker-publish.yml"

key-decisions:
  - "D-01: Docker Hub only. Removed REGISTRY env var, both GHCR login steps, GHCR metadata/manifest/inspect steps in the merge job, and packages: write permission from both jobs. Image reference changed from manukminasyan/relaticle (GHCR) to percil/relaticle (Docker Hub)."
  - "D-02: Trigger changed from push (branches: [main], tags: [v*]) to push (tags: [v*]) only, keeping pull_request: branches: [main] as a credential-free CI build check."
  - "D-03: Tag rules reduced to exactly two: type=semver,pattern={{raw}} and type=raw,value=latest (enabled only on tag refs). Uses {{raw}} rather than {{version}} so the v prefix is preserved (v1.2.3), matching this project's own vX.Y.Z git tag convention. This was a bug the planner caught in the originally locked decision text and the orchestrator corrected before execution."

patterns-established: []

requirements-completed: [D-01, D-02, D-03]

coverage:
  - id: D1
    description: "Version tag push (v*) builds both platforms and publishes a single multi-arch manifest to Docker Hub under percil/relaticle"
    requirement: "D-01"
    verification:
      - kind: other
        ref: "python3 YAML structure assertion (STRUCTURE OK) + manual re-read of build/merge job graph"
        status: pass
    human_judgment: true
    rationale: "No tag was actually pushed during this task (would trigger a real publish to Docker Hub); structural correctness was verified statically. A human should confirm the first real tag push succeeds once DOCKERHUB_USERNAME/DOCKERHUB_TOKEN secrets are confirmed present."
  - id: D2
    description: "Push to main no longer triggers this workflow"
    requirement: "D-02"
    verification:
      - kind: other
        ref: "python3 assert: 'branches' not in push trigger"
        status: pass
    human_judgment: false
  - id: D3
    description: "Pull request against main still builds both platforms, pushes nothing, requires no registry credentials"
    requirement: "D-02"
    verification:
      - kind: other
        ref: "Manual re-read: Docker Hub login, outputs expression, export digest, upload digest all gated by if: github.event_name != 'pull_request'; merge job gated by job-level if"
        status: pass
    human_judgment: false
  - id: D4
    description: "No GHCR or upstream Docker Hub namespace references remain anywhere in the file"
    requirement: "D-01"
    verification:
      - kind: other
        ref: "grep -Eiq 'ghcr|manukminasyan|type=sha|{{major}}|type=ref,event=branch|packages: write' (negated, exits 0 on no match)"
        status: pass
    human_judgment: false
  - id: D5
    description: "Tag rules produce exactly two tags on a version tag push: latest and the raw pushed version (v-prefixed)"
    requirement: "D-03"
    verification:
      - kind: other
        ref: "Manual re-read of both metadata steps (meta, meta-dockerhub): both carry only type=semver,pattern={{raw}} and type=raw,value=latest"
        status: pass
    human_judgment: false

duration: 15min
completed: 2026-09-04
status: complete
---

# Quick Task 260904-fks: Reduce docker-publish.yml to Docker Hub-only, tag-triggered pipeline Summary

Stripped GitHub Container Registry publishing from the fork's docker-publish.yml, retargeted the sole remaining registry to `percil/relaticle` on Docker Hub, and changed the trigger from every push to main to version-tag pushes only, fixing a locked-decision typo (`{{version}}` to `{{raw}}`) that would have stripped the `v` prefix from published tags.

## Performance

- **Duration:** ~15 min
- **Tasks:** 2/2 completed
- **Files modified:** 1

## Accomplishments
- Removed all GitHub Container Registry surface: `REGISTRY` env var, both `Log in to GitHub Container Registry` steps, `Extract metadata for GHCR`, `Create manifest list and push to GHCR`, `Inspect GHCR image`, and `packages: write` from both jobs' permissions.
- Retargeted the single surviving registry image to `percil/relaticle`, replacing the upstream maintainer's `manukminasyan/relaticle`.
- Changed the trigger from `push: {branches: [main], tags: [v*]}` to `push: {tags: [v*]}`, keeping `pull_request: {branches: [main]}` as a build-only, credential-free CI check.
- Reduced both metadata-extraction steps (build job `meta`, merge job `meta-dockerhub`) to exactly two tag rules: `type=semver,pattern={{raw}}` and `type=raw,value=latest,enable=...`, dropping the branch-ref, short-sha, and major.minor rules that only served the removed branch-push trigger.
- Removed the now-dead `IMAGE_NAME` env assignment from the build job's `Prepare` step (kept `PLATFORM_PAIR`, still consumed by `Upload digest`) and deleted the merge job's `Prepare` step entirely (its only body was the same dead assignment).
- Re-audited the full post-edit job graph: no dangling `steps.*.outputs` references, no `env.REGISTRY`/`env.IMAGE_NAME` references, the digest handoff (`Upload digest` -> `Download digests` via `PLATFORM_PAIR`/`digests-*`) is intact, and PR runs remain fully credential-free.

## Task Commits

1. **Task 1: Reduce docker-publish.yml to a Docker Hub only, tag-triggered pipeline** - `5cf1e560` (ci)
2. **Task 2: Audit the resulting job graph and record the manual prerequisites** - no commit (read-only audit, no file changes produced)

**Plan metadata:** committed separately by the orchestrator (docs commit, per this task's constraints).

## Files Created/Modified
- `.github/workflows/docker-publish.yml` - Reduced to Docker Hub-only publishing, triggered by version tags instead of every push to main; tag rules simplified to `latest` + raw semver.

## Decisions Made
- Kept the `DOCKERHUB_IMAGE` env var name unchanged (only its value changed, from `manukminasyan/relaticle` to `percil/relaticle`) since it is an env var name, not a secret name, and renaming it was not requested.
- Kept `meta-dockerhub` as the merge job's metadata step id (not renamed), since the surviving `Create manifest list and push to Docker Hub` and `Inspect Docker Hub image` steps reference it by id.
- Used `type=semver,pattern={{raw}}` rather than the originally locked `{{version}}` pattern, per the orchestrator's pre-execution correction: `{{version}}` strips the `v` prefix (would emit `1.2.3`), which contradicts this project's own `vX.Y.Z` release tag convention documented in CLAUDE.md. Both metadata steps (`meta` in the build job, `meta-dockerhub` in the merge job) now use `{{raw}}` consistently.

## Deviations from Plan

None - plan executed exactly as written. Task 2 was a read-only audit/report task by design; it produced no file changes and therefore no commit, consistent with its `<action>` describing findings to record, not work to perform.

## Issues Encountered
None.

## Manual Prerequisites (recorded per Task 2)

1. **Blocking:** The `DOCKERHUB_USERNAME` and `DOCKERHUB_TOKEN` repository secrets must exist in GitHub Actions settings before a version tag push can succeed. A workflow file cannot create repository secrets. If absent, the `Log in to Docker Hub` step fails and nothing publishes.
2. **Manual:** Docker Hub repository visibility for `percil/relaticle` is a Docker Hub account setting, not workflow YAML. If the repository should be private, set that on Docker Hub before the first tag push; otherwise the repository is created with the account's default visibility on first push.

## Resolved Discrepancy (reported as decided)

The original locked D-03 wording (`type=semver,pattern={{version}}`) would have emitted `1.2.3`, stripping the `v` and contradicting that same decision's own example of `v1.2.3`. The orchestrator resolved this before execution: the plan specifies `type=semver,pattern={{raw}}`, which preserves the pushed tag's `v` prefix and matches this project's `vX.Y.Z` convention (CLAUDE.md release section). Both metadata steps in the post-edit file use `pattern={{raw}}`. On a version tag push, the resulting tag pair is `latest` plus the literal pushed tag (e.g. `v1.2.3`).

## Follow-ups Outside This Task's Scope (grepped live, left untouched)

- `compose.yml` pins `ghcr.io/relaticle/relaticle:latest` on four service definitions (lines 3, 83, 143, 190).
- `packages/Documentation/resources/content/docs/guides/self-hosting.md` documents the same `ghcr.io/relaticle/relaticle:latest` image on four table rows (lines 184-187).
- `app/Services/DockerHubService.php` hardcodes `manukminasyan` as the default `$namespace` argument for its Docker Hub pull-count lookup (both `getPullCount` and `getFormattedPullCount`, lines 15 and 43).

None of these consume this workflow's output today, so this change does not break them, but they now disagree with where this fork actually publishes. This is CI-configuration-only work; those files were explicitly out of scope for this task.

## User Setup Required

None required to land this change. Before the first version tag push is expected to succeed, confirm `DOCKERHUB_USERNAME`/`DOCKERHUB_TOKEN` secrets exist (see Manual Prerequisites above).

## Next Phase Readiness

The workflow file is in its final target shape and self-consistent (valid YAML, no dangling references, digest handoff intact). No further work is required for this quick task. The three follow-up files listed above are candidates for a future quick task if the fork's published image coordinates should be kept in sync elsewhere in the repo.

---
*Quick task: 260904-fks*
*Completed: 2026-09-04*

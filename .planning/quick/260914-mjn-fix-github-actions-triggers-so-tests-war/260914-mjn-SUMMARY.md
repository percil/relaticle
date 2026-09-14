---
phase: 260914-mjn
plan: 01
subsystem: infra
tags: [github-actions, ci, docker, workflows]

requires: []
provides:
  - "tests.yml and warm-caches.yml trigger on pushes to development (kept main for upstream syncs)"
  - "docker-publish.yml triggers on development pushes and publishes a rolling percil/relaticle:dev image"
  - "tests.yml concurrency comment and ci.yml header docstring no longer name main as the only push branch"
affects: [deployment, ci]

actuals:
  tokens: 753
  tasks: 3
  commits: 1
  plan_head_before: cb2f2373357fdc20abe6738ed0b4eef4d5224d72

tech-stack:
  added: []
  patterns: []

key-files:
  created: []
  modified:
    - .github/workflows/tests.yml
    - .github/workflows/warm-caches.yml
    - .github/workflows/docker-publish.yml
    - .github/workflows/ci.yml

key-decisions:
  - "Followed the plan exactly: widened push.branches to include development in tests.yml and warm-caches.yml, added a development branch trigger plus a type=raw,value=dev tag rule to both metadata-action steps in docker-publish.yml, and repaired the two comments that edit falsified."
  - "Tasks 1 and 2 were not committed individually. Per Task 3, all four workflow files landed in one combined commit, matching the plan's own task boundaries."

requirements-completed: [D-01, D-02, D-03]

coverage:
  - id: D1
    description: "Pushing to development triggers Tests (runs ci.yml jobs) and, when composer.lock changes, Warm Caches"
    requirement: D-01
    verification:
      - kind: other
        ref: "python3 YAML assertion: tests.yml/warm-caches.yml on.push.branches == [main, development]; pull_request/paths/workflow_dispatch keys unchanged"
        status: pass
    human_judgment: true
    rationale: "Trigger config is verified by static YAML assertion, but the actual workflow run only happens after a real push to development, which is outside this session's scope (no push permitted)."
  - id: D2
    description: "Pushing to development builds both platforms and publishes a rolling percil/relaticle:dev image; v* tags and PR builds are unaffected"
    requirement: D-02
    verification:
      - kind: other
        ref: "python3 YAML assertion: docker-publish.yml on.push has both tags:[v*] and branches:[development]; both metadata-action steps carry the new type=raw,value=dev rule alongside the untouched semver/latest rules; login and merge-job event_name guards unchanged"
        status: pass
    human_judgment: true
    rationale: "Trigger and tag-rule shape is statically verified. Confirming the merge job actually produces a working percil/relaticle:dev manifest requires a real push and a real Docker Hub inspect, which only happens after the developer pushes (see Task 3 human-check)."
  - id: D3
    description: "deploy.yml and publish-mcp.yml remain byte-identical to their pre-change state"
    requirement: D-03
    verification:
      - kind: other
        ref: "git diff --quiet -- .github/workflows/deploy.yml .github/workflows/publish-mcp.yml"
        status: pass
    human_judgment: false

duration: 2min
completed: 2026-09-14
status: complete
---

# Quick Task 260914-mjn: Fix GitHub Actions Triggers for development Summary

**Added `development` to the push triggers of Tests, Warm Caches, and Docker Build+Push, and gave the latter a rolling `percil/relaticle:dev` tag rule, so the fork's actual working branch finally runs CI.**

## Performance

- **Duration:** 2 min
- **Started:** 2026-09-14T14:25:26Z
- **Completed:** 2026-09-14T14:27:21Z
- **Tasks:** 3
- **Files modified:** 4

## Accomplishments
- `tests.yml` and `warm-caches.yml` now list `[main, development]` under `on.push.branches`, keeping `main` for upstream syncs.
- `docker-publish.yml` now triggers on pushes to `development` (alongside the existing `v*` tag trigger) and both `docker/metadata-action@v6` steps (`meta` and `meta-dockerhub`) carry a `type=raw,value=dev,enable=${{ github.ref == 'refs/heads/development' }}` rule, so a `development` push resolves to exactly one tag: `dev`.
- Repaired the two comments Task 1's edit falsified: `tests.yml`'s concurrency rationale now says "Pushes to a tracked branch..." and "...no refreshed branch-scoped cache..." instead of naming `main`; `ci.yml`'s header docstring now says "pull requests and branch pushes" instead of "pull requests and pushes to main".
- `deploy.yml` and `publish-mcp.yml` are untouched, confirmed via `git diff --quiet`.
- `gh run list --repo percil/relaticle` still returns an empty array (confirmed at the end of this session, matching the plan's pre-verified fact) — any run appearing after the next push to `development` is the proof this fix works.

## Task Commits

Per the plan's own task boundaries, Tasks 1 and 2 (the code edits) were not committed individually. Task 3 explicitly calls for a single combined commit of all four workflow files:

1. **Tasks 1+2+3 combined: Trigger fix for Tests, Warm Caches, and Docker publish** - `8453c35d` (ci)

No separate plan-metadata commit was made by this executor — SUMMARY.md, STATE.md, and ROADMAP.md are handled by the orchestrator's docs commit, per this session's constraints.

## Files Created/Modified
- `.github/workflows/tests.yml` - `on.push.branches` widened to `[main, development]`; concurrency comment generalized off `main`
- `.github/workflows/warm-caches.yml` - `on.push.branches` widened to `[main, development]`; `paths`/`workflow_dispatch` untouched
- `.github/workflows/docker-publish.yml` - added `on.push.branches: [development]` alongside `tags: ['v*']`; added `type=raw,value=dev,enable=${{ github.ref == 'refs/heads/development' }}` to both `meta` and `meta-dockerhub` steps
- `.github/workflows/ci.yml` - header docstring generalized off `main` to describe branch pushes

## Decisions Made
None beyond the plan's own three locked decisions (D-01, D-02, D-03). Plan executed exactly as written.

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered
None.

## User Setup Required

None - no external service configuration required. The developer still needs to push `development` to `origin` for the actual workflow runs to appear; this was intentionally not done by the executor per the plan's Task 3 instruction ("Do not push") and this session's constraint against pushing to origin.

## Next Phase Readiness

The trigger fix is committed and verified statically (YAML shape, scope gate, byte-identical deploy.yml/publish-mcp.yml). The remaining verification is the plan's Task 3 `<human-check>`, which requires a real push to `development`:

1. `gh run list --repo percil/relaticle --limit 10` should list Tests and Build and Push Docker Image runs (Warm Caches only if `composer.lock` changed).
2. Tests should complete with the ci.yml jobs (code quality plus the suite).
3. Build and Push Docker Image should run both platform builds, then the merge job, whose "Inspect Docker Hub image" step inspects `percil/relaticle:dev`.
4. Docker Hub should show `percil/relaticle:dev` with both architectures.

If the merge job's `imagetools create` step fails with a missing `-t` argument, re-check that `meta-dockerhub` received the new tag rule (it did, per the automated verification above, but this is the plan's own stated failure signature to watch for).

---
*Quick task: 260914-mjn*
*Completed: 2026-09-14*

## Self-Check: PASSED

All four workflow files exist on disk, and commit `8453c35d` is present in `git log --oneline --all`.

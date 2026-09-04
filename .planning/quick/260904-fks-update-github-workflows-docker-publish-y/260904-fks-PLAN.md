---
phase: quick-260904-fks
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - .github/workflows/docker-publish.yml
autonomous: true
requirements: [D-01, D-02, D-03]

estimate:
  tokens: 24000
  raw_tokens: 24000
  tasks: 2
  confidence: low

must_haves:
  truths:
    - "Pushing a git tag matching v* builds both platforms and publishes a single multi-arch manifest to Docker Hub under percil/relaticle (D-01, D-03)."
    - "A push to main no longer triggers this workflow at all (D-02)."
    - "A pull request against main still builds both platforms as a CI check, pushing nothing and requiring no registry credentials (D-02)."
    - "No job, step, env var, or permission in the file refers to GitHub Container Registry or to the upstream maintainer's Docker Hub namespace (D-01)."
    - "The tag rules produce exactly two tags on a version tag push: latest, and the version derived from the pushed tag (D-03)."
  artifacts:
    - .github/workflows/docker-publish.yml
  key_links:
    - "build job matrix digests -> upload-artifact digests-${PLATFORM_PAIR} -> merge job download-artifact -> docker buildx imagetools create"
    - "steps.meta-dockerhub.outputs.json -> manifest create tag list; steps.meta-dockerhub.outputs.version -> imagetools inspect reference"
    - "env.DOCKERHUB_IMAGE -> build job metadata images, build push tags, merge job metadata images, manifest create, inspect"
---

<objective>
Reduce `.github/workflows/docker-publish.yml` to a Docker Hub only publish pipeline targeting `percil/relaticle`, triggered by version tags rather than every push to main.

Purpose: this repository is a fork. The workflow currently pushes to GitHub Container Registry under the fork's own dynamic repository name AND to the upstream maintainer's Docker Hub namespace, on every commit to main. Both destinations are wrong for this fork.

Output: one modified workflow file. No application code, no other workflow, no new dependency.
</objective>

<execution_context>
@~/.claude/gsd-core/workflows/execute-plan.md
@~/.claude/gsd-core/templates/summary.md
</execution_context>

<context>
@.planning/STATE.md
@.github/workflows/docker-publish.yml

## Locked user decisions (do not revisit)

- **D-01 Registry.** Docker Hub only. Strip every GitHub Container Registry artifact: the `REGISTRY` env var, the container-registry `docker/login-action` step in both jobs, the container-registry `docker/metadata-action` step in the merge job, and the merge job's container-registry manifest-create and inspect steps. Replace the upstream maintainer's hardcoded Docker Hub image with `percil/relaticle`. Reuse the existing `DOCKERHUB_USERNAME` / `DOCKERHUB_TOKEN` secret names. Do not invent new secret names.
- **D-02 Trigger.** Delete the `push.branches` trigger. Keep `push.tags: ['v*']` as the real build-and-push trigger. Keep `pull_request.branches: [main]` as a build-only CI check. The existing `if: github.event_name != 'pull_request'` guards already make PRs credential-free.
- **D-03 Tags.** Exactly two tags on a version tag push: `latest` and the version derived from the pushed tag, preserving the `v` prefix so it matches the actual git tag (this project's own release convention tags `vX.Y.Z`, per CLAUDE.md). Keep the `type=raw,value=latest,enable=...` rule and use `type=semver,pattern={{raw}}` for the version rule (same `semver` type, different pattern value, not `type=semver,pattern={{version}}`, which strips the `v` and would emit `1.2.3` instead of `v1.2.3`. This mismatch was caught during planning and is corrected here by the orchestrator, not left as an open discrepancy). Drop the per-commit and per-branch tag rules, which existed only to serve the trigger D-02 removes.

Keep unchanged: the two-runner platform matrix (amd64 on `ubuntu-latest`, arm64 on `ubuntu-24.04-arm`) producing per-platform digests merged by the downstream `merge` job, the Docker Hub pull-through mirror step, QEMU setup, and buildx setup.

## Verified facts about the current file

Read live at plan time. Line numbers refer to the pre-edit file.

- Top-level `env` block (lines 10-12) holds exactly two keys: `REGISTRY` and `DOCKERHUB_IMAGE`.
- `IMAGE_NAME` is not a top-level env var. It is written to `$GITHUB_ENV` by a `Prepare` step in each job (build job line 34, merge job line 126). It exists solely to build the container-registry image reference.
- The build job's `Prepare` step (lines 30-34) also sets `PLATFORM_PAIR`, which is still needed by the `Upload digest` artifact name (line 111). That step must survive; only its `IMAGE_NAME` line goes.
- The merge job's `Prepare` step (lines 125-126) sets `IMAGE_NAME` and nothing else. It becomes dead in full.
- `permissions: packages: write` appears in both jobs (lines 19 and 122). It grants write access to GitHub Packages and exists only to push to the container registry.
- The build job's `Build and push by digest` step does NOT consume `steps.meta.outputs.tags`. It passes bare image names in `tags:` and relies on `push-by-digest=true`. Only `steps.meta.outputs.labels` is consumed. Consequence: on a pull_request event the metadata step legitimately resolves to zero tags after D-03, and this is harmless. Do not "fix" it.
- The merge job has two metadata steps: `meta-ghcr` (line 163) and `meta-dockerhub` (line 175). Only the latter survives, and it keeps its `meta-dockerhub` id, because the surviving manifest-create step (line 197) and inspect step (line 208) reference that id.

## Verification tooling available on this machine (checked live)

- `actionlint`: NOT installed. Do not install it.
- `yamllint`: NOT installed. Do not install it.
- `python3` with PyYAML 6.0.3: available. This is the verification path.
- PyYAML gotcha, confirmed live: YAML 1.1 parses the `on:` workflow key as the boolean `True`. In a parsed document the trigger block is `d[True]`, not `d['on']`. `d['on']` raises `KeyError`. Both verify commands below already account for this.

## Out of scope, and why (do not touch)

- `.github/workflows/deploy.yml` and every other file in `.github/workflows/`.
- Creating the `DOCKERHUB_USERNAME` / `DOCKERHUB_TOKEN` repository secrets. A workflow file cannot create repository secrets.
- Docker Hub repository visibility. That is a Docker Hub account setting, not workflow YAML.
- `compose.yml`, `packages/Documentation/resources/content/docs/guides/self-hosting.md`, and `app/Services/DockerHubService.php` all reference upstream image coordinates (grepped live). They are application and documentation surface, and this task is CI-configuration-only. Task 2 records them as follow-ups rather than editing them.
</context>

<tasks>

<task type="tracer">
  <name>Task 1: Reduce docker-publish.yml to a Docker Hub only, tag-triggered pipeline</name>
  <files>.github/workflows/docker-publish.yml</files>
  <precondition>`.github/workflows/docker-publish.yml` exists and still contains both a `build` and a `merge` job, matching the line references recorded in the plan context. If it does not, stop and re-read the file before editing.</precondition>
  <action>
Edit the single file end to end so one coherent diff lands. Do not leave the file in a half-migrated state between edits.

Trigger block, per D-02: under `on.push`, delete the `branches` key and its `[main]` value. Leave `on.push.tags` holding `['v*']` untouched. Leave the entire `on.pull_request` block untouched.

Top-level `env` block, per D-01: delete the `REGISTRY` key and its value entirely. Retain the `DOCKERHUB_IMAGE` key name (it is an env var, not a secret name, and keeping it is the smallest accurate change) and set its value to `percil/relaticle`. After this edit `env` holds exactly one key.

Both jobs' `permissions` blocks, per D-01: delete the `packages: write` line from the `build` job and from the `merge` job. <!-- planner-discipline-allow: packages: write --> Keep `contents: read` in both. Rationale, and record it in the summary: that permission existed solely to authorize the GitHub Packages push this change removes, so leaving it grants standing write access to a registry the workflow no longer uses. This is orphan cleanup caused directly by D-01, not an unrelated improvement.

Build job. In the `Prepare` step, delete only the line appending `IMAGE_NAME` to `$GITHUB_ENV`; keep the `platform` local and the `PLATFORM_PAIR` line, because the `Upload digest` artifact name still consumes `PLATFORM_PAIR`. Delete the whole `Log in to GitHub Container Registry` step, guard line included. <!-- planner-discipline-allow: ghcr --> Keep the `Log in to Docker Hub` step exactly as written, guard and both secret references included. In the `Extract metadata` step, reduce `images:` from a two-entry block list to the single value `${{ env.DOCKERHUB_IMAGE }}`, and reduce `tags:` per D-03 to exactly two rules: the semver rule with pattern `{{raw}}`, not `{{version}}` (`{{raw}}` preserves the pushed tag's `v` prefix, e.g. `v1.2.3`, matching this project's own `vX.Y.Z` tagging convention), and the existing raw `latest` rule with its `startsWith(github.ref, 'refs/tags/')` enable expression, copied verbatim. Delete the branch-ref rule, the short-sha rule, and the major-dot-minor semver rule. <!-- planner-discipline-allow: type=ref,event=branch --> <!-- planner-discipline-allow: type=sha --> <!-- planner-discipline-allow: {{major}} --> In the `Build and push by digest` step, reduce `tags:` from a two-entry block list to the single value `${{ env.DOCKERHUB_IMAGE }}`. Leave `context`, `target`, `platforms`, `labels`, `cache-from`, `cache-to`, and the conditional `outputs` expression byte-identical. Leave `Configure Docker Hub pull-through mirror`, `Checkout repository`, `Set up QEMU`, `Set up Docker Buildx`, `Export digest`, and `Upload digest` untouched.

Merge job. Delete the entire `Prepare` step, since its only body was the now-dead `IMAGE_NAME` assignment. Delete the entire `Log in to GitHub Container Registry` step. <!-- planner-discipline-allow: ghcr --> Delete the entire `Extract metadata for GHCR` step including its `meta-ghcr` id. Delete the entire `Create manifest list and push to GHCR` step. Delete the entire `Inspect GHCR image` step. Keep `Download digests`, `Configure Docker Hub pull-through mirror`, `Log in to Docker Hub`, and `Set up Docker Buildx` as written. Keep the `Extract metadata for Docker Hub` step and do not rename its `meta-dockerhub` id, because the surviving manifest-create and inspect steps reference it; apply the same D-03 two-rule `tags:` reduction to it that you applied in the build job, so the two metadata steps stay in lockstep. Keep the surviving `Create manifest list and push to Docker Hub` step and the surviving `Inspect Docker Hub image` step byte-identical, including the `DOCKER_METADATA_OUTPUT_JSON` env wiring, the `working-directory`, the `jq` tag-list expression, and the `printf` digest expansion. Keep the merge job's `if: github.event_name != 'pull_request'` and `needs: build`.

Preserve the file's existing two-space indentation and blank-line rhythm. Do not reorder surviving steps, do not rename surviving step `name:` values, and do not add explanatory YAML comments.
  </action>
  <verify>
    <automated>python3 -c "
import yaml
d=yaml.safe_load(open('.github/workflows/docker-publish.yml'))
t=d[True]
assert 'branches' not in t['push'], 'push.branches still present'
assert t['push']['tags']==['v*'], t['push']
assert t['pull_request']['branches']==['main'], t['pull_request']
assert set(d['jobs'])=={'build','merge'}, set(d['jobs'])
assert d['env']=={'DOCKERHUB_IMAGE':'percil/relaticle'}, d['env']
print('STRUCTURE OK')
"</automated>
    <automated>! grep -v '^[[:space:]]*#' .github/workflows/docker-publish.yml | grep -Eiq 'ghcr|manukminasyan|type=sha|\{\{major\}\}|type=ref,event=branch|packages: write'</automated>
    <automated>grep -q 'percil/relaticle' .github/workflows/docker-publish.yml</automated>
  </verify>
  <done>The file parses as valid YAML. Its trigger block has no `push.branches`, retains `push.tags: ['v*']` and `pull_request.branches: [main]`. Its `env` block holds exactly `DOCKERHUB_IMAGE: percil/relaticle`. Both `build` and `merge` jobs still exist. No non-comment line matches the removed-artifact pattern set. All three verify commands exit 0.</done>
  <reversibility rating="reversible">Single-file workflow change, fully recoverable with `git checkout -- .github/workflows/docker-publish.yml`. Nothing is published until a version tag is pushed.</reversibility>
</task>

<task type="auto">
  <name>Task 2: Audit the resulting job graph and record the manual prerequisites</name>
  <files>.github/workflows/docker-publish.yml</files>
  <action>
Re-read the post-edit file top to bottom in one pass. A grep gate proves absence; only a read proves the remaining graph is coherent. Confirm each of the following and report the finding, not just a pass mark.

Dangling-reference sweep: no surviving step interpolates `env.REGISTRY` or `env.IMAGE_NAME`; every `steps.<id>.outputs.*` interpolation in the merge job resolves to a step id that still exists (expect `meta-dockerhub` only); the build job's `Upload digest` artifact name still resolves `PLATFORM_PAIR` from the surviving `Prepare` step; the merge job's `download-artifact` pattern still matches the name the build job uploads.

Trigger and guard semantics: on a pull request, the `merge` job is skipped by its own `if`, and inside the `build` job every credential-consuming and push-consuming step (Docker Hub login, the `outputs` expression, export digest, upload digest) is still gated so the run needs no secrets. On a version tag push, both matrix legs push by digest and the merge job assembles one manifest.

Then record these four items for the summary. They are findings to report, not work to perform.

1. Prerequisite, blocking: the `DOCKERHUB_USERNAME` and `DOCKERHUB_TOKEN` repository secrets must exist in GitHub Actions settings before a tag push can succeed. A workflow file cannot create repository secrets. If they are absent the login step fails and nothing publishes.
2. Prerequisite, manual: Docker Hub repository visibility for `percil/relaticle` is an account setting on Docker Hub, not workflow YAML. If the repository should be private, set it there. The first push will otherwise create it with the account's default visibility.
3. Resolved discrepancy, report as decided, not as an open question: the planner correctly caught that the original locked D-03 wording (`type=semver,pattern={{version}}`) would have emitted `1.2.3`, stripping the `v`, contradicting the same decision's own example of `v1.2.3`. The orchestrator resolved this before execution: the plan now specifies `type=semver,pattern={{raw}}`, which preserves the `v` prefix and matches the actual git tag pushed (this project's own convention is `vX.Y.Z` tags, per CLAUDE.md's release section). Confirm in the post-edit file that both metadata steps use `pattern={{raw}}`, not `{{version}}`, and report the resulting tag pair as `latest` plus the literal pushed tag (e.g. `v1.2.3`).
4. Follow-ups outside this task's scope, grepped live and left untouched: `compose.yml` pins the upstream container-registry image on four service definitions, `packages/Documentation/resources/content/docs/guides/self-hosting.md` documents that same image on four table rows, and `app/Services/DockerHubService.php` hardcodes the upstream Docker Hub namespace as a default argument for its pull-count lookup. None of these consume this workflow's output today, so this change does not break them, but they now disagree with where this fork publishes.
  </action>
  <verify>
    <automated>! grep -v '^[[:space:]]*#' .github/workflows/docker-publish.yml | grep -Eq 'env\.REGISTRY|env\.IMAGE_NAME|meta-ghcr'</automated>
    <automated>python3 -c "
import re, yaml
src=open('.github/workflows/docker-publish.yml').read()
d=yaml.safe_load(src)
b,m=d['jobs']['build'],d['jobs']['merge']
ids={s.get('id') for s in b['steps']}|{s.get('id') for s in m['steps']}
refs=set(re.findall(r'steps\.([A-Za-z0-9_-]+)\.outputs', src))
assert refs<=ids, ('dangling step refs', refs-ids)
assert m['needs']=='build' and 'pull_request' in str(m['if'])
names=[s['name'] for s in b['steps']]
assert 'Upload digest' in names and 'Prepare' in names
assert 'PLATFORM_PAIR' in src
print('GRAPH OK; step refs:', sorted(refs))
"</automated>
  </verify>
  <done>The post-edit file has been read in full. No dangling step-output reference, no surviving reference to the removed env vars, and the digest handoff from both matrix legs into the merge job is intact. The four reportable items above are captured verbatim for the summary, with item 3 flagged as a user decision rather than resolved unilaterally.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| fork PR author -> Actions runner | An untrusted contributor's branch executes a Dockerfile build on a runner in this repository's context. |
| Actions runner -> Docker Hub | Long-lived registry credentials cross from repository secrets into a third-party registry. |
| Actions runner -> GitHub Packages | Removed by this change. |

## STRIDE Threat Register

| Threat ID | Category | Component | Severity | Disposition | Mitigation Plan |
|-----------|----------|-----------|----------|-------------|-----------------|
| T-fks-01 | Information Disclosure | `Log in to Docker Hub` in both jobs | high | mitigate | Task 1 preserves the `if: github.event_name != 'pull_request'` guard on the build job login and the merge job's job-level `if`, so `DOCKERHUB_TOKEN` is never materialized on a pull_request run. Task 2 re-reads the file to confirm no guard was dropped during the edit. |
| T-fks-02 | Elevation of Privilege | job `permissions` blocks | medium | mitigate | Task 1 drops `packages: write` from both jobs once the GitHub Packages push is gone, leaving `contents: read`. Least privilege restored rather than left over. |
| T-fks-03 | Tampering | publish trigger surface | medium | mitigate | D-02 removes the push-to-main trigger, so an image is published only from an explicit version tag. This shrinks the window in which an unreviewed commit on main becomes a pullable `latest`. |
| T-fks-04 | Spoofing | `percil/relaticle` namespace on first push | medium | accept | The Docker Hub namespace and its visibility are account-side settings the workflow cannot assert. Task 2 records claiming the repository and setting visibility as an explicit manual prerequisite before the first tag push. |
| T-fks-SC | Tampering | package-manager installs | low | accept | No npm, pip, or cargo install is added or altered by this change. The existing Dockerfile build is untouched, so no new package-legitimacy surface is introduced. |
</threat_model>

<verification>
Run from the repository root after both tasks:

1. `python3 -c "import yaml; yaml.safe_load(open('.github/workflows/docker-publish.yml')); print('YAML OK')"` exits 0.
2. Task 1's three automated gates exit 0.
3. Task 2's two automated gates exit 0.
4. `git diff --stat` lists exactly one changed file: `.github/workflows/docker-publish.yml`.

No PHP toolchain step applies. This change touches no PHP, so `pint`, `rector`, `phpstan`, and the Pest suite have nothing to act on and must not be invoked as evidence for this task.
</verification>

<success_criteria>
- `.github/workflows/docker-publish.yml` parses as valid YAML and is the only file changed.
- The workflow triggers on version tag pushes and on pull requests against main, and not on pushes to main.
- Every image reference in the file is `percil/relaticle`, sourced from the single `DOCKERHUB_IMAGE` env var.
- No GitHub Container Registry login, metadata, manifest, inspect step, env var, or permission survives.
- Both metadata steps carry the same two tag rules, yielding `latest` plus the semver-derived version on a tag push.
- The multi-platform matrix, digest export and upload, and the merge job's `imagetools create` handoff are structurally unchanged.
- The summary states the two manual prerequisites, that the `{{version}}` vs `{{raw}}` tag-prefix issue was caught during planning and resolved by using `{{raw}}` (preserving the `v` prefix), and the three untouched files that reference upstream image coordinates.
</success_criteria>

<output>
Create `.planning/quick/260904-fks-update-github-workflows-docker-publish-y/260904-fks-SUMMARY.md` when done.
</output>

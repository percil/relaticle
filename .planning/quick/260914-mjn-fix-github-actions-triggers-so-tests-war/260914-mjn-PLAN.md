---
phase: 260914-mjn
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - .github/workflows/tests.yml
  - .github/workflows/warm-caches.yml
  - .github/workflows/docker-publish.yml
  - .github/workflows/ci.yml
autonomous: true
requirements: [D-01, D-02, D-03]

estimate:
  tokens: 20000
  raw_tokens: 20000
  tasks: 3
  confidence: low

must_haves:
  truths:
    - "Pushing a commit to `development` triggers the Tests workflow, which runs the ci.yml jobs (D-01)"
    - "Pushing a composer.lock change to `development` triggers Warm Caches, so the default-branch composer cache exists for every other ref to restore from (D-01)"
    - "Pushing to `development` builds both platforms and publishes a rolling `percil/relaticle:dev` to Docker Hub (D-02)"
    - "Pushing a `v*` tag still publishes the semver tags plus `latest`, with behavior identical to before this change (D-02)"
    - "Opening a PR against `main` still builds Docker images without pushing and without logging in to Docker Hub (D-02)"
    - "deploy.yml and publish-mcp.yml are byte-identical to their pre-change state (D-03)"
  artifacts:
    - .github/workflows/tests.yml
    - .github/workflows/warm-caches.yml
    - .github/workflows/docker-publish.yml
  key_links:
    - "docker-publish.yml `on.push.branches` <-> the `type=raw,value=dev` rule in BOTH metadata-action steps. A branch trigger without the matching rule in the merge job yields an empty tag list, and `docker buildx imagetools create` then fails with no `-t` argument."
    - "The existing `github.event_name != 'pull_request'` guards <-> the new branch push. They already admit a branch push exactly as they admit a tag push, which is what makes the build job push by digest and the merge job run at all."
---

<objective>
Make this fork's CI actually run. `percil/relaticle` has `development` as its default and real
working branch, but every workflow trigger still points at `main` inherited from upstream. The
fork has recorded zero workflow runs, ever.

Add `development` to the push branch list in `tests.yml` and `warm-caches.yml` (keeping `main`
for upstream syncs), and give `docker-publish.yml` a `development` branch trigger that publishes
a rolling `percil/relaticle:dev` image.

Purpose: pushes to the branch this fork actually develops on get a test verdict, a warm composer
cache, and a deployable image.
Output: three corrected workflow trigger blocks, plus one stale docstring in `ci.yml` that this
change falsifies.

**On tracer-first:** deliberately skipped. Tracer-first exists to prove an architecture end to
end before building out from it. This phase has no layers and no architecture: it is two
independent configuration edits to already-active workflows. A tracer label here would be
decorative. Each task below is already an end-to-end, user-visible capability.
</objective>

<execution_context>
@~/.claude/gsd-core/workflows/execute-plan.md
@~/.claude/gsd-core/templates/summary.md
</execution_context>

<context>
@.planning/STATE.md
@.github/workflows/tests.yml
@.github/workflows/warm-caches.yml
@.github/workflows/docker-publish.yml
@.github/workflows/ci.yml
</context>

<pre_verified_facts>
Established this session. Do not re-derive.

- `percil/relaticle` is a fork of `relaticle/relaticle`; its `default_branch` is `development`.
- Actions are enabled on the fork (`allowed_actions: all`), and Tests, Warm Caches, and Build and
  Push Docker Image are all registered `active`. Trigger config is the only thing blocking them.
- `gh run list` returns an empty array. No workflow has ever run in this fork.
- `DOCKERHUB_USERNAME` and `DOCKERHUB_TOKEN` both exist as repo secrets (set 2026-09-11).
- docker-publish.yml has TWO `docker/metadata-action@v6` steps with an identical `tags:` block:
  `meta` in the `build` job and `meta-dockerhub` in the `merge` job. Both need the same new rule.
- Every other conditional in docker-publish.yml keys off `github.event_name`, never the ref, so a
  push event on a branch already flows through them exactly like a push event on a tag. The
  Docker Hub login step, the build job's `outputs:` push-by-digest expression, and the merge job's
  `if:` need no changes.
- `security-audit.yml` and `skills-evals.yml` are `pull_request`-only and have no push trigger to
  fix. `filament-view-monitor-simple.yml` is schedule-driven and ref-agnostic (GitHub reports it
  `disabled_fork`, which is standard for scheduled workflows on forks and out of scope).
</pre_verified_facts>

<tasks>

<task type="auto">
  <name>Task 1: Trigger Tests and Warm Caches on development pushes</name>
  <files>.github/workflows/tests.yml, .github/workflows/warm-caches.yml, .github/workflows/ci.yml</files>
  <action>
Per D-01, widen the push branch list in both workflows. In `.github/workflows/tests.yml`, change
the `on.push.branches` flow-sequence value from `[main]` to `[main, development]`. In
`.github/workflows/warm-caches.yml`, make the identical change to its `on.push.branches` value.
Keep `main` in both: it still exists for upstream syncs from `relaticle/relaticle`. Change nothing
else in either trigger block. `tests.yml` keeps its bare `pull_request:` key, and `warm-caches.yml`
keeps both its `paths:` filter (`composer.lock`, `.github/workflows/warm-caches.yml`) and its
`workflow_dispatch:` key.

Then repair the two comments this change falsifies. These are orphans created by the edit, not
opportunistic cleanup, so they are in scope. In `tests.yml`, the concurrency rationale block
currently opens "Pushes to main get a group per commit" and later says "no refreshed main-scoped
cache". Swap only those two references so they describe any tracked branch rather than `main`
specifically. The concurrency expression itself already keys off `github.event_name`, so the
rationale stays true verbatim once the word `main` is generalized. In `.github/workflows/ci.yml`,
the header docstring says it is "Called by tests.yml on pull requests and pushes to main"; change
that clause to describe branch pushes. Do not rewrite, reflow, expand, or reformat any comment
beyond those word-level swaps, and do not add new comments.

Leave `warm-caches.yml`'s own header comment untouched. It already reads correctly: it says
tag-triggered release runs can only restore caches created on the default branch, and the default
branch is `development`, so this edit is precisely what makes the file deliver on its stated
purpose.

Preserve existing indentation, key order, and flow-vs-block style exactly. This is a surgical
value edit, not a reformat.
  </action>
  <verify>
    <automated>python3 -c 'import yaml; f=[yaml.safe_load(open(p)) for p in (".github/workflows/tests.yml",".github/workflows/warm-caches.yml")]; t=[d.get("on", d.get(True)) for d in f]; assert t[0]["push"]["branches"]==["main","development"], t[0]; assert "pull_request" in t[0], t[0]; assert t[1]["push"]["branches"]==["main","development"], t[1]; assert sorted(t[1]["push"]["paths"])==sorted(["composer.lock",".github/workflows/warm-caches.yml"]), t[1]; assert "workflow_dispatch" in t[1], t[1]; assert f[0]["jobs"]["verify"]["uses"]=="./.github/workflows/ci.yml"; print("OK")'</automated>
    <automated>python3 -c 'import subprocess; got=set(subprocess.run(["git","diff","--name-only","--",".github/workflows/"],capture_output=True,text=True,check=True).stdout.split()); ok={".github/workflows/tests.yml",".github/workflows/warm-caches.yml",".github/workflows/ci.yml"}; assert got<=ok, "out of scope: "+str(got-ok); print("OK")'</automated>
  </verify>
  <done>`tests.yml` and `warm-caches.yml` both list `[main, development]` under `on.push.branches`, every other trigger key in both files is unchanged, the two falsified comments in `tests.yml` and `ci.yml` no longer name `main` as the only push branch, and no workflow file outside those three is touched.</done>
</task>

<task type="auto">
  <name>Task 2: Publish a rolling dev image on development pushes</name>
  <files>.github/workflows/docker-publish.yml</files>
  <action>
Per D-02, make a push to `development` build and publish `percil/relaticle:dev`.

First, widen the trigger. Under `on.push`, add a `branches: [development]` key alongside the
existing `tags: ['v*']`. Add it as a new line and leave the existing `tags:` line exactly where
and as it is. When a push event carries both a `branches` and a `tags` filter, GitHub runs the
workflow if either matches, so the `v*` release path is unaffected. Do not touch the
`pull_request: branches: [main]` block.

Second, add the tag rule to BOTH `docker/metadata-action@v6` steps: the `meta` step in the `build`
job and the `meta-dockerhub` step in the `merge` job. Their `tags:` blocks are currently identical
two-line block scalars. Append a third line to each, at the same 12-space indentation as the
existing entries, reading exactly:
`type=raw,value=dev,enable=${{ github.ref == 'refs/heads/development' }}`

Both steps must receive it. The `merge` job is where the multi-arch manifest actually gets its
Docker Hub tags, so omitting it there produces an empty tag list and `docker buildx imagetools
create` fails with no `-t` argument. Leave the existing `type=semver,pattern={{raw}}` and
`type=raw,value=latest,...` lines byte-identical, and add no other tag rule.

Because the `tags:` input overrides metadata-action's defaults entirely, a push to `development`
resolves to exactly one tag, `dev`: the semver rule only matches tag refs, and the `latest` rule
is guarded by `startsWith(github.ref, 'refs/tags/')`. The `version` output likewise resolves to
`dev`, which is what the final `imagetools inspect` step reads.

Change nothing else in this file. The Docker Hub login step's `if: github.event_name !=
'pull_request'`, the build job's `outputs:` push-by-digest expression, and the merge job's `if:`
already admit a branch push correctly. Do not touch `deploy.yml` or `publish-mcp.yml` (D-03).
  </action>
  <verify>
    <automated>python3 -c 'import yaml; d=yaml.safe_load(open(".github/workflows/docker-publish.yml")); on=d.get("on", d.get(True)); assert on["push"].get("tags")==["v*"], on["push"]; assert on["push"].get("branches")==["development"], on["push"]; assert on["pull_request"]=={"branches":["main"]}, on["pull_request"]; st=[s for j in d["jobs"].values() for s in j["steps"] if str(s.get("uses","")).startswith("docker/metadata-action")]; assert len(st)==2, len(st); assert sorted(s.get("id") for s in st)==["meta","meta-dockerhub"], [s.get("id") for s in st]; need=["type=semver,pattern={{raw}}","type=raw,value=latest,enable=","type=raw,value=dev,enable=","refs/heads/development","startsWith(github.ref"]; bad=[(s.get("id"), s["with"]["tags"]) for s in st if len([l for l in s["with"]["tags"].splitlines() if l.strip()])!=3 or any(n not in s["with"]["tags"] for n in need)]; assert not bad, bad; guard="github.event_name != \x27pull_request\x27"; assert [s for s in d["jobs"]["build"]["steps"] if s.get("name")=="Log in to Docker Hub"][0]["if"]==guard; assert d["jobs"]["merge"]["if"]==guard; print("OK")'</automated>
    <automated>git diff --quiet -- .github/workflows/deploy.yml .github/workflows/publish-mcp.yml</automated>
  </verify>
  <done>A push to `development` matches the workflow trigger, both metadata-action steps carry the same three tag rules with the new `dev` raw rule last, the tag-release and PR paths are unchanged, and `deploy.yml` and `publish-mcp.yml` have no diff.</done>
  <reversibility rating="reversible">A rolling `:dev` tag is a new mutable tag in a namespace nothing consumes yet; deleting the tag rule and the tag fully undoes it.</reversibility>
</task>

<task type="auto">
  <name>Task 3: Commit the trigger fix and stage the live confirmation</name>
  <files>.github/workflows/tests.yml, .github/workflows/warm-caches.yml, .github/workflows/docker-publish.yml, .github/workflows/ci.yml</files>
  <action>
Commit the workflow changes from Tasks 1 and 2 as a single atomic commit. Stage only the four
files in `files_modified`. Use a `ci:` scope, subject in the imperative, no em-dash, and the
attribution trailers this session requires.

Do not push. Pushing to `development` is the developer's call, and the push is what triggers the
first real workflow run. The `<human-check>` below records what they should observe once they do.

Report in the summary that `gh run list --repo percil/relaticle` currently returns an empty array,
so any run appearing after the push is itself the proof that the trigger fix worked.
  </action>
  <verify>
    <automated>python3 -c 'import subprocess; r=subprocess.run(["git","status","--porcelain","--",".github/workflows/"],capture_output=True,text=True,check=True); assert not r.stdout.strip(), "uncommitted workflow changes: "+r.stdout; n=subprocess.run(["git","show","--stat","--name-only","--format=","HEAD"],capture_output=True,text=True,check=True).stdout.split(); ok={".github/workflows/tests.yml",".github/workflows/warm-caches.yml",".github/workflows/docker-publish.yml",".github/workflows/ci.yml"}; assert set(n)<=ok, "commit touches extra files: "+str(set(n)-ok); assert set(n), "HEAD commit has no workflow files"; print("OK")'</automated>
    <human-check>
After pushing `development` to `origin`, confirm the fork's first-ever workflow runs:

1. `gh run list --repo percil/relaticle --limit 10` now lists runs where it previously returned
   an empty array. Expect Tests and Build and Push Docker Image. Warm Caches will NOT appear
   unless `composer.lock` changed, because its `paths:` filter is intact. That absence is correct.
2. Tests completes with the ci.yml jobs (code quality plus the suite).
3. Build and Push Docker Image runs the `build` matrix on `linux/amd64` and `linux/arm64`, then
   runs `merge`, whose final "Inspect Docker Hub image" step inspects `percil/relaticle:dev`.
4. Docker Hub shows `percil/relaticle:dev` with both architectures.

If the run fails in the merge job's `imagetools create` step with a missing `-t` argument, the
`meta-dockerhub` step did not receive the new tag rule. Re-check Task 2.
    </human-check>
  </verify>
  <done>All four workflow files are committed in one commit that touches nothing else, and the working tree under `.github/workflows/` is clean.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| git ref -> GitHub Actions runner | Which ref is allowed to start a credentialed workflow |
| Actions runner -> Docker Hub | Credentialed image push using `DOCKERHUB_USERNAME` / `DOCKERHUB_TOKEN` |

This change adds no new attack surface. It widens which git ref starts workflows that already
exist and were already reviewed, and reuses the exact login step and secrets the `v*` tag path
already uses. No new secret, no new action, no new network destination, no new permission.

## STRIDE Threat Register

| Threat ID | Category | Component | Severity | Disposition | Mitigation Plan |
|-----------|----------|-----------|----------|-------------|-----------------|
| T-mjn-01 | Elevation of Privilege | `docker-publish.yml` push trigger | low | accept | A credentialed Docker Hub push is now reachable from a `development` push, not only a `v*` tag. Both are gated by the same repo write permission, so no new principal gains publish access. |
| T-mjn-02 | Tampering | `github.event_name != 'pull_request'` guards | medium | mitigate | Fork PRs must never reach the Docker Hub login. Task 2 asserts the login-step `if:`, the merge-job `if:`, and `pull_request.branches: [main]` are all unchanged, so a PR still builds without credentials. |
| T-mjn-03 | Tampering | rolling `percil/relaticle:dev` tag | low | accept | `:dev` is mutable by design (D-02) and advances with every `development` commit. Nothing consumes it: the deployment compose file pins `percil/relaticle:latest`, which only a `v*` tag can move. |
| T-mjn-04 | Information Disclosure | secrets in workflow logs | low | accept | Unchanged. `docker/login-action@v4` masks the token, and no step added here echoes a secret. |
| T-mjn-SC | Tampering | supply chain / package installs | low | accept | No package-manager install is added. Every `uses:` action reference in the touched files is pre-existing and unmodified, so no legitimacy audit is triggered. |
</threat_model>

<verification>
1. Both automated checks in Task 1 pass (trigger shape plus scope gate).
2. Both automated checks in Task 2 pass (tag rules in both steps, guards intact, out-of-scope files clean).
3. Task 3's automated check passes: one commit, four files, clean tree.
4. Task 3's `<human-check>` is harvested into UAT and confirmed by the developer after they push.
</verification>

<success_criteria>
- `on.push.branches` is `[main, development]` in both `tests.yml` and `warm-caches.yml`.
- `docker-publish.yml` triggers on a `development` push and on `v*` tags, and both metadata-action
  steps carry the same `type=raw,value=dev,enable=${{ github.ref == 'refs/heads/development' }}` rule.
- No behavior change to the `v*` tag-release path or the PR build-only path.
- `deploy.yml` and `publish-mcp.yml` show no diff.
- A real push to `development` produces workflow runs where the fork previously had none.
</success_criteria>

<source_audit>
## Multi-Source Coverage Audit

| Source | Item | Covered by |
|--------|------|------------|
| GOAL | Tests, Warm Caches, and Docker Build+Push run on pushes to `development` | Tasks 1, 2, 3 |
| CONTEXT | D-01: add `development` to `push.branches` in tests.yml and warm-caches.yml, keeping `main` | Task 1 |
| CONTEXT | D-02: add `branches: [development]` to docker-publish.yml's push trigger plus a `type=raw` dev rule in BOTH metadata-action steps | Task 2 |
| CONTEXT | D-03: leave `deploy.yml` and `publish-mcp.yml` unchanged | Task 2 verify (`git diff --quiet` assertion) |
| REQUIREMENTS | No REQUIREMENTS.md. Quick task, so requirements are the three locked decisions | n/a |
| RESEARCH | No research phase run for this task | n/a |

No unplanned items. Explicitly excluded and confirmed not gaps: `deploy.yml` and `publish-mcp.yml`
(D-03, out of scope by decision); `security-audit.yml` and `skills-evals.yml` (`pull_request`-only,
no push trigger to fix); `filament-view-monitor-simple.yml` (schedule-driven, ref-agnostic).

One item was added beyond the stated scope: the one-clause docstring fix in `ci.yml` (Task 1). It
is included because Task 1's edit is what makes that sentence false, which makes it an orphan of
this change rather than opportunistic cleanup. Drop it if you would rather keep the diff to three
files.
</source_audit>

<output>
Create `.planning/quick/260914-mjn-fix-github-actions-triggers-so-tests-war/260914-mjn-SUMMARY.md` when done
</output>

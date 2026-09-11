---
phase: quick-260911-mrc
plan: 01
type: execute
wave: 1
depends_on: []
files_modified: []
autonomous: true
requirements: [QUICK-260911-MRC]

estimate:
  tokens: 45000
  raw_tokens: 45000
  tasks: 3
  confidence: low

must_haves:
  truths:
    - "No tracked source file carries a leftover git merge conflict marker."
    - "composer.json and composer.lock agree, and pnpm-lock.yaml satisfies package.json."
    - "The application boots and every non-vendor route resolves its action class."
    - "The CI gate (lint, refactor, type coverage, static analysis, full Pest suite) passes locally on the development branch."
    - "Every failure found is diagnosed at its correct layer, then either fixed with a minimal committed change or reported with evidence. Nothing is fixed speculatively."
  artifacts:
    - ".planning/quick/260911-mrc-forked-branch-updated-from-origin-check-/260911-mrc-SUMMARY.md"
  key_links:
    - "route:list resolution vs the marketing purge commits (42ada70c through 5710a9da): those deleted controllers, views, mail classes and the sitemap command, so an orphaned route target or view reference is the single most likely breakage."
    - "composer.lock and pnpm-lock.yaml vs the installed vendor/ and node_modules/: a lock that drifted during the sync makes local green and CI red."
    - "phpunit.xml and phpunit.ci.xml testsuite lists: tests/Arch/TestSuiteIntegrityTest.php fails when the two drift, which is exactly what a branch sync can cause."
---

<objective>
Verify that the `development` branch, freshly synced from origin, is aligned and working. This is a read-only health check first and a repair task only second.

Purpose: the branch carries 73 commits ahead of origin, including a marketing surface purge (deleted controllers, views, mail classes, config, and the sitemap command) and a rewritten docker-publish workflow. Those are exactly the change shapes that leave orphaned references, stale test assertions, or lock drift behind after a sync. Confirm the tree is clean before any further work builds on it.

Output: a SUMMARY recording each check, its exact command, and its result. Plus, only if a check surfaces a concrete defect, one minimal commit fixing it.

No tracer task here on purpose: this plan builds no new capability, so there is no vertical slice to prove. The three tasks are diagnostic gates in increasing cost order, so the cheapest signal arrives first.
</objective>

<execution_context>
@~/.claude/gsd-core/workflows/execute-plan.md
@~/.claude/gsd-core/templates/summary.md
</execution_context>

<context>
@.planning/STATE.md
@CLAUDE.md
@.ai/rules/index.md
@.github/workflows/ci.yml
</context>

<tasks>

<task type="auto">
  <name>Task 1: Repo integrity sweep. No merge debris, locks in sync, app boots.</name>
  <files>(read-only: no files modified)</files>
  <read_first>.github/workflows/ci.yml</read_first>
  <action>
Run the cheap structural checks first, in this order, and capture each command's exact output for the SUMMARY.

1. Merge debris. Search tracked files for leftover git conflict markers at line start, excluding `.planning` and `.gsd` so planning artifacts cannot match their own pattern. The exact pattern is in the verify block below. A hit is a hard failure, not a warning.

2. Dependency lock alignment. Run `composer validate --strict --no-check-publish`. Under `--strict` a lock file that no longer matches `composer.json` becomes an error rather than a notice. Then run `pnpm install --frozen-lockfile --dry-run` to prove `pnpm-lock.yaml` still satisfies `package.json` without writing to `node_modules`. If that pnpm flag combination is rejected by the installed pnpm version, fall back to `pnpm ls --depth=0` and record which command was used.

3. Application boot. Run `php artisan about --only=environment` and `php artisan route:list --except-vendor`. `route:list` instantiates every non-vendor route target, so it is the direct detector for a controller, Livewire component, or invokable class that the marketing purge removed while a route still points at it. Redact any credential value before quoting `about` output in the SUMMARY.

4. Migration alignment. Run `php artisan migrate:status`. If the local PostgreSQL database is unreachable, record that as an environment gap and move on. Do not provision, create, or migrate a database to make this check pass. Do not run `migrate`, `migrate:fresh`, or any seeder.

5. Test suite registry drift. Confirm `phpunit.xml` and `phpunit.ci.xml` still declare the same testsuite list, since `tests/Arch/TestSuiteIntegrityTest.php` fails when they diverge and a sync is a plausible cause. Task 2's suite run is the authoritative gate here, so a manual read is enough at this stage.

Fix nothing in this task. Record every result, pass or fail, and carry the failures into Task 3.
  </action>
  <verify>
    <automated>git grep -nE '^([<]{7}|[>]{7}) ' -- . ':(exclude).planning' ':(exclude).gsd'; test $? -eq 1 && composer validate --strict --no-check-publish && php artisan route:list --except-vendor >/dev/null && php artisan about --only=environment >/dev/null</automated>
  </verify>
  <done>Conflict-marker search returns no hits in tracked source outside planning directories. `composer validate --strict` passes. The pnpm lock check passes or its fallback is recorded. `route:list --except-vendor` exits 0 with every route target resolving. `migrate:status` result is recorded, including "database unreachable" as a valid recorded outcome. Any failure is written down with its command and output, and nothing is edited.</done>
</task>

<task type="auto">
  <name>Task 2: Run the CI gate locally, in CI order.</name>
  <files>(read-only: no files modified)</files>
  <read_first>.github/workflows/ci.yml</read_first>
  <action>
Run the same checks `.github/workflows/ci.yml` runs, in the same order, so a local pass means a CI pass:

1. `composer test:lint` (`pint --test --parallel`, whole repo). Use this, not `pint --dirty`. `--dirty` only sees uncommitted files, so a style break committed earlier in this branch stops being checked locally and surfaces only in CI. That is precisely the failure mode a post-sync check exists to catch.
2. `composer test:refactor` (rector dry-run against both `rector.php` and `rector-tests.php`).
3. `composer test:type-coverage` (must hold at 100%).
4. `composer test:types` (phpstan). Remember `packages/SystemAdmin` is excluded from phpstan, so a phpstan pass does not clear that directory.
5. `composer test:pest:full` (`pest --parallel --no-tia`). Use the full non-TIA run, not `composer test:pest`. TIA replays a cached pass whenever a test's traced edges are unchanged, which is unsound here: a branch sync changes many edges TIA did not record, so a TIA-accelerated pass would be evidence of nothing.

Browser suite, conditional. `composer test:browser` requires the app served and the Vite bundle built. Probe first with `curl -sfI https://relaticle.test -o /dev/null` and only run the suite if the probe succeeds. If it does not, record "browser suite not run: local app not served" in the SUMMARY rather than starting a server, running a build, or claiming coverage that was never exercised.

Capture the failing output verbatim for anything that fails. Do not fix anything in this task, and do not re-run a failing command with narrower filters hoping for green.
  </action>
  <verify>
    <automated>composer test:lint && composer test:refactor && composer test:type-coverage && composer test:types && composer test:pest:full</automated>
  </verify>
  <done>All five CI gate commands have been run and their pass or fail status recorded with output. Browser suite status is recorded as run, or as skipped with the reason. No file was edited in this task.</done>
</task>

<task type="auto">
  <name>Task 3: Triage findings. Repair only concrete defects, then report.</name>
  <files>(conditional: only files a confirmed defect requires, plus the SUMMARY)</files>
  <action>
If Tasks 1 and 2 produced zero failures, skip straight to the report: write the SUMMARY, state that the branch is aligned, and change no source file. An all-green run is a complete and valid outcome for this plan.

For each failure, diagnose the layer before touching anything. A red check means one of: a real defect left by the fork changes, a stale test assertion, a test-state leak, or a local environment gap (unreachable database, app not served, missing build). Name which one, with evidence, before proposing any edit.

Repair is allowed only for a concrete defect traceable to this branch's own commits. The likely shapes, given the purge: a route, view, translation key, or config reference pointing at something deleted, a test asserting against removed marketing behavior, or a style break in a file committed earlier in the branch.

Hard stop and report instead of fixing when the repair would:
- change production behavior on its own merits rather than restore the intended state,
- add a PHPStan ignore entry,
- weaken, skip, or delete a test assertion to turn the suite green,
- spread across more than a handful of files,
- require a database, service, or credential the local environment does not have.

After any fix, re-run the exact command that failed and show the new output. "Should work now" is not done. Then do a second independent verification pass over the whole gate: `composer test:lint` and `composer test:pest:full`, plus `vendor/bin/pint --dirty --format agent` on anything edited.

Commit any repair as a single conventional commit scoped `fix(260911-mrc): ...`, with the touched source files only. Do not merge, push, tag, reset, or delete a branch. Leave the pre-existing untracked paths (`.gsd/`, `.planning/research/.cache/`, `.planning/state.json`) alone.

Write the SUMMARY last: one row per check, its exact command, pass or fail, and for each fix, the defect, the layer, the change, and the re-run evidence. Redact credentials.
  </action>
  <verify>
    <automated>composer test:lint && composer test:types && composer test:pest:full</automated>
  </verify>
  <done>Every failure from Tasks 1 and 2 is resolved or explicitly reported as out of scope with its reason. The full gate passes, or a remaining failure is documented as an environment gap rather than a repo defect. The SUMMARY exists and lists every check with its command and result. Any repair is a single scoped commit, nothing was pushed, merged, or tagged, and no test assertion was weakened.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| local shell to repo | Diagnostic commands read the tree and the local database. No untrusted input crosses in. |
| diagnostic output to committed SUMMARY | `php artisan about` and `migrate:status` can print environment and connection values that must not be committed. |

## STRIDE Threat Register

| Threat ID | Category | Component | Severity | Disposition | Mitigation Plan |
|-----------|----------|-----------|----------|-------------|-----------------|
| T-260911-01 | Information Disclosure | `php artisan about` / `migrate:status` output quoted into the SUMMARY | medium | mitigate | Task 1 and Task 3 require redacting credential and connection-string values before any output is written to the SUMMARY. Use `--only=environment` to narrow what is printed. |
| T-260911-02 | Tampering | local database via `migrate:status` | low | mitigate | Task 1 forbids `migrate`, `migrate:fresh`, and seeders. An unreachable database is recorded as an environment gap, never provisioned. |
| T-260911-03 | Tampering | dependency tree via lock checks | low | mitigate | Node lock check runs as `--frozen-lockfile --dry-run`, which does not write `node_modules`. No `composer update`, no `pnpm add`, no package installs in this plan, so no package legitimacy gate applies. |
| T-260911-04 | Denial of Service | remote branch state | low | mitigate | Plan explicitly forbids merge, push, tag, force push, `reset --hard`, and branch deletion. All git operations are read-only except one optional local commit. |
</threat_model>

<verification>
- Conflict-marker search over tracked source returns no hits.
- `composer validate --strict --no-check-publish` passes.
- `php artisan route:list --except-vendor` exits 0.
- `composer test:lint`, `composer test:refactor`, `composer test:type-coverage`, `composer test:types`, `composer test:pest:full` all pass, or each failure is documented with its diagnosis layer.
- Working tree contains no unexplained modifications. `git status` shows only the expected pre-existing untracked paths, the planning artifacts, and any file a documented repair required.
</verification>

<success_criteria>
The branch is either confirmed aligned and working with evidence per check, or every misalignment is named, located, and classified as defect, stale assertion, state leak, or environment gap. Repairs, if any, are minimal, committed once, and re-verified against the original failing command. Nothing was pushed, merged, or tagged.
</success_criteria>

<output>
Create `.planning/quick/260911-mrc-forked-branch-updated-from-origin-check-/260911-mrc-SUMMARY.md` when done
</output>

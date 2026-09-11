---
phase: quick-260911-nxv
plan: 01
type: execute
wave: 1
depends_on: []
files_modified: []
autonomous: false
requirements: [QUICK-260911-NXV]

estimate:
  tokens: 60000
  raw_tokens: 60000
  tasks: 4
  confidence: low

must_haves:
  truths:
    - "From inside the dev stack's app AND horizon containers, built from the current development branch, both Ollama Cloud catalog rows resolve with a configured provider and a live probe is accepted by ollama_cloud."
    - "The same two proofs hold inside compose.yml's own app and horizon containers when the image carries the current branch code, which isolates the env wiring from the image contents."
    - "The as-shipped default prod deployment is evidenced, by command and output, either to contain or to lack the ollama_cloud provider."
    - "No real API key value is printed to the terminal, written to a log, or committed to any file."
    - "The machine is left as found: the compose.yml stack running under project `relaticle` on its original pinned image, the dev stack down, and every scratch container, volume and file created by this plan removed."
    - "Each misalignment is reported at its correct layer. A source change is applied only where its premise is verified, and the compose.yml image reference is decided by the developer, not chosen autonomously."
  artifacts:
    - ".planning/quick/260911-nxv-forked-branch-updated-from-origin-check-/260911-nxv-SUMMARY.md"
  key_links:
    - "host .env to compose ${VAR} interpolation to the container `environment:` block to config/ai.php to ProviderModelCatalog and ChatProviderCheck. The hop that breaks is `environment:`, because compose never auto-injects a var that is not an explicit key. Fork commits d522d654 and 9b2b77ac already closed that hop in both files."
    - "compose.yml's `image: ghcr.io/relaticle/relaticle:latest` versus this fork's publish target `percil/relaticle` in .github/workflows/docker-publish.yml. Correct env wiring in front of an image that never shipped the provider still yields an unavailable provider."
    - "CatalogEntry::isServable() requires a Measurement with supportsTools. Seed rows carry capabilities null, so on a freshly migrated database ollama_cloud is legitimately absent from the health registry until probed. Misreading that as an env defect is the single most likely false negative in this plan."
    - "Dockerfile sets AUTORUN_LARAVEL_CONFIG_CACHE=true and the app service leaves AUTORUN_ENABLED on, so config is cached at entrypoint in `app`. An exec-time `-e` env var is invisible there. Only horizon and reverb (AUTORUN_ENABLED=false) read env live at exec time."
    - "Compose project name versus named volumes. compose.yml's postgres volume under project `relaticle` holds the developer's existing local prod-stack data, and the app service runs AUTORUN_LARAVEL_MIGRATION=true. Bringing a branch-code image up under that same project would migrate real local data."
---

<objective>
Prove whether Ollama Cloud is actually available from the two default deployment paths, `compose.dev.yml` and `compose.yml`, by bringing each stack up and making a live call to the provider from inside the containers that use it.

Purpose: the code-level alignment of this branch was already established earlier in this session by quick task 260911-mrc, which took the full CI gate green after the origin sync. What that did not answer is the second half of the request. Static reading confirms both compose files forward `OLLAMA_CLOUD_API_KEY` and `OLLAMA_CLOUD_BASE_URL` to `app` and `horizon`, but forwarding a variable is not the same as the provider being reachable. This plan replaces reading with running.

Output: a SUMMARY recording, per deployment path and per container, the exact command and its result. Plus, only where a defect's premise is verified and the developer has chosen the fix, one minimal commit.

Pre-planning discovery already established four facts that shape the tasks. Do not re-derive them, but do re-confirm each with its own command, because containers and images may change between planning and execution.

1. The `compose.yml` stack is already running on this machine right now, under compose project `relaticle`, publishing host port 80. That, not `compose.dev.yml`, is what the task description's "port 80" refers to. `compose.dev.yml` publishes `${APP_PORT:-8080}`, deliberately, so it never collides with native Herd. That default is a settled decision from phase 02-01 and is out of scope here. Report the discrepancy, do not change the port.
2. That running stack uses `image: ghcr.io/relaticle/relaticle:latest`, pulled ten days ago. Grepping inside the running container returned a count of zero for `ollama_cloud` in both `config/ai.php` and `packages/Chat/config/chat.php`, and the image still carries the marketing views this fork purged. That image is upstream Relaticle's, not this fork's. On the evidence available at planning time, Ollama Cloud is not available from the default prod deployment, and the cause sits in the image reference rather than in the env wiring.
3. This fork publishes `percil/relaticle` to Docker Hub, tag-triggered on `v*`. An anonymous lookup of that repository returns 404, which means either nothing was ever published or the repository is private. Both readings matter and the plan must not guess between them.
4. `laravel/tinker` sits in `require`, not `require-dev`, so it survives the image's `composer install --no-dev`. Even so, prefer `php artisan chat:models`, which is purpose-built for this question, prints provider and availability without printing any credential, and can make a real provider call through `--probe`.

Task 1 is the tracer: one thin path from the host `.env` through compose interpolation, the container environment, `config/ai.php`, the model registry, and out to Ollama Cloud's API, proven end to end before anything expands sideways.
</objective>

<execution_context>
@~/.claude/gsd-core/workflows/execute-plan.md
@~/.claude/gsd-core/templates/summary.md
</execution_context>

<context>
@.planning/STATE.md
@CLAUDE.md
@.ai/rules/index.md
@compose.dev.yml
@compose.yml
@config/ai.php
@app/Health/ChatProviderCheck.php
</context>

<tasks>

<task type="tracer">
  <name>Task 1: Dev stack end to end. Host .env to a live Ollama Cloud call from inside app and horizon.</name>
  <files>(read-only for the repository; scratch files only under the session scratchpad)</files>
  <precondition>The Docker daemon is running, the host `.env` at the repository root carries a non-empty Ollama Cloud API key, and the compose-required variables resolve. Assert the daemon with `docker info --format '{{.ServerVersion}}'` and assert compose resolution with `docker compose -f compose.dev.yml config --quiet`, which validates and prints nothing. Halt and report if either fails.</precondition>
  <read_first>compose.dev.yml</read_first>
  <action>
Secrets discipline applies to every step of this plan and is not negotiable. Never run `cat .env`, `grep` over `.env`, `docker compose config` without `--quiet`, `docker inspect` against a container, or `env` / `printenv` inside a container. Each of those prints the resolved API key. Every check in this plan reports a boolean, an exit code, an HTTP status, or a provider name. If a command's output would carry a credential, do not run that command, find a different one.

Step 0. Snapshot and reclaim the machine. Record the as-found Docker state with `docker compose -f compose.yml -p relaticle ps -a` and `docker images --format '{{.Repository}}:{{.Tag}}\t{{.ID}}\t{{.CreatedSince}}' | grep -i relaticle`, and write both into the notes that will become the SUMMARY. At planning time this showed app, horizon, scheduler, postgres and redis healthy, and reverb stuck in `Created` having never started. Then bring that stack down with `docker compose -f compose.yml -p relaticle down`. Never pass `-v` to that command anywhere in this plan: those named volumes hold the developer's existing local data. Bringing it down first removes all host-port ambiguity between the three stacks this plan touches, and Task 4 restores it.

Step 1. Rebuild the dev image from current branch code. The existing `relaticle-dev:local` tag was built eight days ago and predates the origin sync, so it is evidence of nothing. Run `docker compose -f compose.dev.yml build` and then `docker compose -f compose.dev.yml up -d --wait`. The build is slow, that is expected. If `--wait` is not supported by the installed compose version, poll `docker compose -f compose.dev.yml ps` until app and horizon report healthy, and record which form was used. Migrations run automatically in `app` against the pre-existing `relaticle-dev-postgres` volume. That is this stack's documented purpose, so accept it and record it as a side effect rather than working around it.

Step 2. Catalog resolution, in `app` and again in `horizon`. Run `docker compose -f compose.dev.yml exec -T app php artisan chat:models` and the same against `horizon`. Read the printed table. Both Ollama Cloud rows, ids `gpt-oss:20b` and `gpt-oss:120b`, must appear with provider `ollama_cloud` and `available` reading yes. `available` reading no for `ollama_cloud` while other providers read yes means the key did not reach that container, which is the env-wiring defect this step exists to detect. Quote the relevant table rows into the notes. The table prints no credential.

Step 3. Live provider call, in `app` and again in `horizon`. Run `docker compose -f compose.dev.yml exec -T app php artisan chat:models --probe=gpt-oss:20b` and the same against `horizon`. The id is the bare model tag because `ModelDescriptor` builds its id from the catalog entry's model. Success prints `gpt-oss:20b: accepted by ollama_cloud` plus a capability table and exits 0. This is the load-bearing proof in the whole plan: it exercises the real key against the real endpoint from inside the real container, so it simultaneously clears the env forwarding, the network path, the base URL, and the key's own validity. Failure exits 1 and prints the provider's error. Capture that error verbatim, and classify it before reacting: an auth error points at the key, a 404 at the model tag, a connection error at the container's egress.

Step 4. Confirmatory health check, in `horizon` only. Run `docker compose -f compose.dev.yml exec -T -e HEALTH_CHECKS_ENABLED=true horizon php artisan health:check`. Use horizon rather than app on purpose: the app service runs the image's Laravel automations including config caching, so an exec-time variable cannot reach `config('app.health_checks_enabled')` there, while horizon sets AUTORUN_ENABLED false and reads env live. This step is diagnostic, not a gate. If no `Chat provider: ollama_cloud` check appears, do not call it a defect until you have ruled out the expected cause: `ChatProviderCheck::reachableModels()` filters on `CatalogEntry::isServable()`, which demands a Measurement carrying supportsTools, and the seed rows carry capabilities null until probed. On a freshly migrated database that absence is correct behavior, not breakage. Record which of the two explanations the evidence supports.

Note for the SUMMARY, do not act on it here: neither compose file forwards `HEALTH_CHECKS_ENABLED`, so a self-hoster who sets it in their own `.env` still cannot turn health checks on in a containerized deployment. Task 3 puts that in front of the developer.

Leave the dev stack running when this task ends. Task 2 brings it down.
  </action>
  <verify>
    <automated>docker compose -f compose.dev.yml exec -T app php artisan chat:models --probe=gpt-oss:20b && docker compose -f compose.dev.yml exec -T horizon php artisan chat:models --probe=gpt-oss:20b</automated>
  </verify>
  <done>The as-found Docker state is recorded before anything is changed. The dev image is rebuilt from current branch code and app plus horizon report healthy. `chat:models` in both containers lists gpt-oss:20b and gpt-oss:120b under provider ollama_cloud with available yes. `chat:models --probe=gpt-oss:20b` exits 0 in both containers with the accepted-by-ollama_cloud line. The horizon health-check result is recorded along with which explanation the evidence supports. No credential value appears in any captured output.</done>
</task>

<task type="auto">
  <name>Task 2: Prod path. Evidence the shipped default, then prove compose.yml's own definition under a scratch project.</name>
  <files>(read-only for the repository; one scratch override file under the session scratchpad)</files>
  <read_first>compose.yml</read_first>
  <action>
This task separates two questions the prod path conflates: does the image `compose.yml` pins carry the provider, and does `compose.yml`'s own service definition deliver the provider when the image does carry it. Answer them in that order.

Step 0. Bring the dev stack down with `docker compose -f compose.dev.yml down`, no `-v`, so its host ports are free.

Step 1. Evidence the shipped default. Against the locally present image, run `docker run --rm --entrypoint sh ghcr.io/relaticle/relaticle:latest -c "grep -c ollama_cloud /var/www/html/config/ai.php /var/www/html/packages/Chat/config/chat.php"`. Do not pull, the tag is already local and pulling would silently swap the artifact under test. Record the two counts verbatim. At planning time both read zero, which means the provider is absent from the image entirely: no entry under `ai.providers`, no catalog rows, therefore no picker entry and no health check, regardless of how correctly the env is forwarded. If the counts now read non-zero, the finding has changed and the rest of this plan's prod narrative must be rewritten from the new evidence rather than from the planning note.

Step 2. Build the scratch override. Write a small compose override file into the session scratchpad directory, not into the repository, declaring only `services:` with `app`, `horizon`, `scheduler` and `reverb`, each carrying a single `image: relaticle-dev:local` key. Nothing else. That tag is the image Task 1 just built from `Dockerfile` `target: production`, which is exactly the target `.github/workflows/docker-publish.yml` builds and pushes, so it is a faithful stand-in for the image this fork would publish. Set no build context in the override, so no path resolves relative to anything.

Step 3. Bring `compose.yml` up under a scratch project name: `docker compose -p relaticle-prodcheck -f compose.yml -f <scratchpad-override> up -d --wait`. The separate project name is mandatory and is the single most important safety property of this task. The as-found `relaticle` project's postgres volume holds the developer's real local data, and compose.yml's app service sets AUTORUN_LARAVEL_MIGRATION true, so reusing that project would run this branch's migrations against it. A distinct project name gives the scratch stack its own fresh volumes, which Task 4 then destroys. Never run this stack under project `relaticle`.

`compose.yml` guards APP_KEY, DB_PASSWORD and the three REVERB_APP_* variables with `:?`, so a missing one aborts the up with an error naming the variable and no value. The as-found stack was running at planning time, which is direct evidence those variables already resolve from the host `.env`. If an abort happens anyway, report the named variable and stop, do not invent a value.

Step 4. Repeat Task 1's two proofs against this stack, in `app` and again in `horizon`: `docker compose -p relaticle-prodcheck -f compose.yml -f <scratchpad-override> exec -T app php artisan chat:models`, then the same with `--probe=gpt-oss:20b`, then both against `horizon`. A pass here says compose.yml's env wiring is correct and the only thing standing between a self-hoster and Ollama Cloud is which image the file pins.

Step 5. Record the reverb observation, bounded. The as-found stack left `relaticle-reverb-1` in `Created`, never started, which means chat streaming was not working in that deployment. Check whether reverb comes up healthy in the scratch project and, if it does not, read its logs once with `docker compose -p relaticle-prodcheck -f compose.yml -f <scratchpad-override> logs reverb | tail -40`. One read, then stop. This is an observation for the SUMMARY, not work for this plan. Do not fix it here.

Leave this stack running when the task ends. Task 4 tears it down.
  </action>
  <verify>
    <automated>docker run --rm --entrypoint sh ghcr.io/relaticle/relaticle:latest -c "grep -c ollama_cloud /var/www/html/config/ai.php /var/www/html/packages/Chat/config/chat.php"; docker compose -p relaticle-prodcheck -f compose.yml -f "$OVERRIDE" exec -T app php artisan chat:models --probe=gpt-oss:20b && docker compose -p relaticle-prodcheck -f compose.yml -f "$OVERRIDE" exec -T horizon php artisan chat:models --probe=gpt-oss:20b</automated>
  </verify>
  <done>The pinned image's ollama_cloud counts are recorded from a direct command. The scratch override exists outside the repository and pins only the image tag. compose.yml is up under project relaticle-prodcheck with its own volumes, and the project `relaticle` volumes were never touched. `chat:models` and `chat:models --probe=gpt-oss:20b` both succeed in app and in horizon under that project, or the failure is captured with its provider error. The reverb state is recorded as an observation with at most one log read. No credential value appears in any captured output.</done>
</task>

<task type="checkpoint:decision" gate="blocking">
  <name>Task 3: Decision. What compose.yml's image reference should point at, and whether to forward HEALTH_CHECKS_ENABLED.</name>
  <decision>
Two questions, batched so they can be answered in one message per this repository's decision convention. Question 1: what should `compose.yml`'s `image:` line point at for `app`, `horizon`, `scheduler` and `reverb`. Question 2: should `HEALTH_CHECKS_ENABLED` be forwarded by the compose files, or only reported.
  </decision>
  <context>
Lead with the evidence, three lines, taken from this run and not from the plan text: the `ollama_cloud` counts from Task 2 Step 1, which say whether the pinned image carries the provider at all; the pass or fail of Task 2 Step 4, which says whether compose.yml's own env wiring is sound; and the standing fact that `.github/workflows/docker-publish.yml` publishes `percil/relaticle` on `v*` tags while `compose.yml` pins `ghcr.io/relaticle/relaticle:latest`, which is upstream Relaticle's image, not this fork's.

At planning time those counts read zero and zero. If that holds, the answer to the original request for the prod path is that Ollama Cloud is not available there, the cause is the image reference rather than the env wiring, and Question 1 is the fix.

Question 2 stands on its own evidence: `HEALTH_CHECKS_ENABLED` defaults false in `config/app.php` and neither compose file forwards it, so `php artisan health:check` registers nothing in any container and a self-hoster cannot turn it on from their own `.env`. The minimal fix would be one passthrough line, `HEALTH_CHECKS_ENABLED: ${HEALTH_CHECKS_ENABLED:-false}`, in the services that would run it.

Recommendation: Question 1, option B, conditional on the developer confirming they own `percil/relaticle` on Docker Hub, with option C as the follow-up they perform themselves. If ownership cannot be confirmed in this session, fall back to option A and record the gap. Question 2, report only. It is adjacent to this plan's question rather than part of it, and the same surgical reasoning that keeps the dev stack's port at 8080 applies here.

Do not pick either answer autonomously, and edit no file before the answers arrive.
  </context>
  <options>
    <option id="q1-a-leave-pinned">
      <name>Q1-A. Leave compose.yml pointing at ghcr.io/relaticle/relaticle:latest</name>
      <pros>Zero change, zero risk, no supply-chain exposure. Correct if this fork is never meant to be self-hosted from its own compose file.</pros>
      <cons>The default prod deployment keeps shipping upstream's application, which carries neither Ollama Cloud nor the fork's marketing purge. The original request's prod half stays unanswered in the negative.</cons>
    </option>
    <option id="q1-b-repoint-fork-image">
      <name>Q1-B. Repoint the four services to percil/relaticle:latest</name>
      <pros>Aligns the shipped compose file with what this fork actually builds and publishes. One line per service, nothing else touched. Task 2 already proved the env wiring around it is sound.</pros>
      <cons>Cannot be verified by pulling, because the anonymous lookup returns 404 and that reading is ambiguous between a private repository the developer owns and a name nobody has claimed. If the name is unclaimed, whoever claims it later controls what every self-hoster pulls. Viable only on confirmed ownership.</cons>
    </option>
    <option id="q1-c-repoint-and-release">
      <name>Q1-C. Repoint as in B, then cut a release tag so the image exists</name>
      <pros>The only option that leaves a default prod deployment that actually works end to end, since the publish workflow is tag-triggered on `v*` and would populate both the version tag and `latest`.</pros>
      <cons>This repository reserves merging and tagging for explicit instruction, and this plan's constraints forbid pushing and tagging outright. The tagging half is therefore a recommendation the developer runs themselves, never an action taken here.</cons>
    </option>
    <option id="q2-a-report-only">
      <name>Q2-A. Report the HEALTH_CHECKS_ENABLED gap, change nothing</name>
      <pros>Keeps this plan surgical and scoped to the provider question. The gap is recorded in the SUMMARY where it can become its own task.</pros>
      <cons>Containerized health-check observability stays unreachable until someone picks that task up.</cons>
    </option>
    <option id="q2-b-forward-now">
      <name>Q2-B. Add the passthrough line now</name>
      <pros>One line, closes the gap immediately, and makes `ChatProviderCheck` usable in a real deployment where it currently cannot run at all.</pros>
      <cons>Scope creep on a plan whose question is provider availability. Also only half a fix for the `app` service, which caches config at entrypoint, so the behavior would differ per service and warrants its own thinking.</cons>
    </option>
  </options>
  <resume-signal>Answer both, for example "Q1: B, I own the Docker Hub repo" or "Q1: A. Q2: A". Question 1's answer must state whether the developer owns the percil/relaticle Docker Hub repository, because option B is not applied without it.</resume-signal>
  <done>Both questions were presented with this run's own evidence, the options, and the recommendation. Both answers are recorded verbatim in the notes that become the SUMMARY. No file was edited before the answers arrived.</done>
</task>

<task type="auto">
  <name>Task 4: Apply the decision if any, restore the machine, write the SUMMARY.</name>
  <files>(conditional: compose.yml only if Task 3 authorized it, plus the SUMMARY)</files>
  <reversibility rating="costly">Repointing a shipped compose file's image reference redirects every future self-hoster's pull. Reversible in git, but not reversible for anyone who pulled in between, which is why Task 3 gates it on confirmed ownership.</reversibility>
  <action>
Step 1. Apply the authorized change, if there is one. If Task 3 answered A, or answered B without confirmed ownership, edit nothing and say so plainly in the SUMMARY. If Task 3 authorized B, change the `image:` line in `compose.yml` for `app`, `horizon`, `scheduler` and `reverb`, and nothing else in the file. Do not reformat, do not touch adjacent comments, do not adjust ports. Then state honestly in the SUMMARY that the new reference could not be verified by pulling it, because the image is not published yet, and name the follow-up the developer must run.

Step 2. Tear down the scratch stack, volumes included: `docker compose -p relaticle-prodcheck -f compose.yml -f <scratchpad-override> down -v`. The `-v` is correct here and only here, because this plan created those volumes. Delete the scratch override file. Then confirm with `docker volume ls | grep prodcheck` returning nothing, and confirm the `relaticle` project's own volumes still exist.

Step 3. Restore the machine as found. Bring the original stack back with `docker compose -f compose.yml -p relaticle up -d`, using the file's own image reference, whatever Step 1 left it as. The pinned image is already local so no pull is required. Confirm with `docker compose -f compose.yml -p relaticle ps`. Compare against the Task 1 Step 0 snapshot and record any deviation, including whether reverb now starts rather than sitting in `Created`. Also confirm the dev stack is down and that no image tag was created by this plan beyond the rebuilt `relaticle-dev:local`, which replaces a tag that already existed.

Step 4. Write the SUMMARY at the path in the output block. Structure it as: one row per proof, giving deployment path, container, command, and result. Then a findings section carrying, at minimum, the four items this plan surfaced: the pinned image lacking the provider, the port-80 attribution belonging to compose.yml rather than compose.dev.yml, the unforwarded `HEALTH_CHECKS_ENABLED`, and the reverb container that never started. For each finding state the layer and whether it was fixed, deferred, or reported. Then state the plain answer to the original question, per deployment path, in one sentence each. No em-dashes anywhere in the file, per this repository's writing rules. No credential value anywhere in the file.

Step 5. Commit only if Step 1 edited a file. One conventional commit scoped `fix(260911-nxv): ...` carrying the source change, plus the planning artifacts. Do not push, do not merge, do not tag, do not touch branches. Leave the pre-existing untracked paths `.gsd/`, `.planning/research/.cache/` and `.planning/state.json` alone. If `compose.yml` was edited, that is a YAML file and Pint does not apply, but re-run `docker compose -f compose.yml config --quiet` to prove the file still parses.
  </action>
  <verify>
    <automated>docker compose -f compose.yml config --quiet && docker compose -f compose.yml -p relaticle ps --format '{{.Service}} {{.State}}' && test -z "$(docker volume ls -q | grep prodcheck)" && test -f .planning/quick/260911-nxv-forked-branch-updated-from-origin-check-/260911-nxv-SUMMARY.md && ! grep -rIn 'OLLAMA_CLOUD_API_KEY=[A-Za-z0-9]' .planning/quick/260911-nxv-forked-branch-updated-from-origin-check-/</automated>
  </verify>
  <done>Any authorized edit is applied and scoped to the image lines alone, or no file was edited and the SUMMARY says why. The relaticle-prodcheck project and its volumes are gone and the scratch override file is deleted. The relaticle project is running again from compose.yml with its deviations from the as-found snapshot recorded. The SUMMARY exists, lists every proof with its command and result, names all four findings with their layer and disposition, answers the original question per deployment path, contains no em-dash and no credential value. Any repair is a single scoped commit, and nothing was pushed, merged or tagged.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| host `.env` to container process environment | The real Ollama Cloud credential crosses here through compose interpolation, and several natural diagnostic commands would print it. |
| container to Ollama Cloud API | An outbound authenticated call to a third-party service leaves the machine during the probe. |
| this plan's commands to the developer's existing local stack | Compose project names and named volumes decide whether a scratch bring-up writes to real local data. |
| `compose.yml` image reference to the public registry | Whatever that line names is what every self-hoster executes. |

## STRIDE Threat Register

| Threat ID | Category | Component | Severity | Disposition | Mitigation Plan |
|-----------|----------|-----------|----------|-------------|-----------------|
| T-260911nxv-01 | Information Disclosure | the credential, via diagnostic output into the terminal, logs, or the committed SUMMARY | critical | mitigate | Task 1 forbids `cat .env`, `grep` over `.env`, `docker compose config` without `--quiet`, `docker inspect` on a container, and `env` or `printenv` inside a container. Every check reports a boolean, an exit code, or a provider name. `chat:models` and `chat:models --probe` were chosen specifically because they prove reachability without printing the key. Task 4's verify negative-greps the plan's own directory for a key-shaped assignment. |
| T-260911nxv-02 | Tampering | the developer's existing local prod-stack postgres volume under compose project `relaticle` | high | mitigate | compose.yml's app service runs AUTORUN_LARAVEL_MIGRATION true, so Task 2 brings the branch-code stack up under the distinct project `relaticle-prodcheck`, which allocates fresh volumes. `-v` is forbidden against project `relaticle` everywhere in this plan and permitted only against `relaticle-prodcheck` in Task 4. |
| T-260911nxv-SC | Tampering | `compose.yml` image reference repointed at `percil/relaticle` | high | mitigate | The anonymous 404 cannot distinguish a private repository the developer owns from a name nobody has claimed. Pointing a shipped compose file at an unclaimed registry name hands control of every self-hoster's pull to whoever claims it. Task 3 gates the change on the developer confirming ownership, and Task 4 refuses to apply it otherwise. No npm, pip or cargo install occurs in this plan, so no package legitimacy table applies. |
| T-260911nxv-03 | Spoofing | the image under test | medium | mitigate | Task 2 Step 1 forbids pulling `ghcr.io/relaticle/relaticle:latest`, since a pull mid-plan would silently replace the artifact the finding was measured against. Task 1 Step 1 mandates a rebuild of the dev image, because the eight-day-old tag predates the origin sync and would otherwise be treated as evidence. |
| T-260911nxv-04 | Denial of Service | host ports 80, 8080 and 8081, and the developer's running stack | medium | mitigate | All three stacks are operated strictly sequentially: the as-found stack comes down in Task 1 Step 0, the dev stack comes down in Task 2 Step 0, the scratch stack comes down in Task 4 Step 2, and the as-found stack is restored in Task 4 Step 3 with a snapshot comparison. |
| T-260911nxv-05 | Repudiation | the SUMMARY's claims | medium | mitigate | Every truth in this plan is backed by a named command and its captured output. A provider reported as reachable without a successful `--probe` exit code is not an acceptable result. |
</threat_model>

<verification>
- `chat:models` lists gpt-oss:20b and gpt-oss:120b under provider ollama_cloud with available yes, in the dev stack's app and horizon and in the prodcheck stack's app and horizon.
- `chat:models --probe=gpt-oss:20b` exits 0 with the accepted-by-ollama_cloud line in all four of those containers.
- The `ollama_cloud` occurrence counts inside `ghcr.io/relaticle/relaticle:latest` are recorded from a direct command, with the planning-time reading of zero either confirmed or superseded.
- The horizon health-check result is recorded, and any absent ollama_cloud check is attributed either to unprobed capabilities or to an env defect, with evidence for which.
- `docker volume ls` shows no prodcheck volume, the relaticle project volumes survive, and `docker compose -f compose.yml -p relaticle ps` matches the Task 1 snapshot except for recorded deviations.
- `git status` shows only the planning artifacts, any authorized compose.yml edit, and the pre-existing untracked paths.

</verification>

<success_criteria>
The question is answered per deployment path with live evidence rather than with a reading of the compose files. Where Ollama Cloud is reachable, a real call to the provider from inside the container proves it. Where it is not, the blocking layer is named, and the fix is either applied under the developer's explicit authorization or reported with the reason it was not. The credential never appears in output or in any file. The machine is left as it was found.
</success_criteria>

<output>
Create `.planning/quick/260911-nxv-forked-branch-updated-from-origin-check-/260911-nxv-SUMMARY.md` when done
</output>

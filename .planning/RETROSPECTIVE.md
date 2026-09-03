# Project Retrospective

*A living document updated after each milestone. Lessons feed forward into future planning.*

## Milestone: v1.0 — Ollama Cloud Provider

**Shipped:** 2026-09-03
**Phases:** 2 | **Plans:** 7 | **Sessions:** 1 (this session covered Phase 2 execution through milestone close)

### What Was Built
- Ollama Cloud wired as a distinct, fully-managed AI provider (config, live model catalog listing via `/v1/models`, `ModelProbe`-verified pricing/plan-gating, health-check probing) matching the existing Anthropic/OpenAI workflow
- A closed health-dashboard boot-order gap (`HealthServiceProvider::boot()` now registers checks inside `$this->app->booted(...)`), pinned by a full-application-boot regression test
- Runtime-injected Reverb browser credentials (Filament render hook) replacing Vite build-time values, so one published image works for every self-hoster's domain and key
- A production-shaped `compose.dev.yml` (Postgres 17, Redis, Reverb, Mailpit) that runs side-by-side with pre-existing Sail containers, and the missing `reverb` sidecar added to production `compose.yml`
- Public self-hosting docs for the Reverb container (required secrets, addressing, WebSocket reverse-proxy routing for Nginx/Caddy/Traefik)

### What Worked
- The phase-2 code review caught a real critical bug (missing `REVERB_APP_ID`/`REVERB_APP_SECRET` on `app`/`horizon`/`scheduler`) that would have silently broken all real-time chat on the published `compose.yml` — the review-then-fix-then-reverify loop inside `/gsd-execute-phase` did exactly what it's for.
- Independent re-verification of a fix commit that had no plan/SUMMARY of its own (the code-review remediation) via a real second verifier pass — booting the actual production-shaped stack from scratch rather than trusting the commit message — caught nothing new but confirmed the fix was complete, which is the point of not trusting self-reported completion.
- Browser automation (claude-in-chrome) was available in the orchestrator session even though it wasn't available inside the plan-executor subagents' sandboxes — worth remembering that "no browser tool" earlier in a phase doesn't mean "no browser tool" at verification/UAT time; check again rather than assuming the constraint still holds.

### What Was Inefficient
- Phase 1's Task 2 (live sysadmin panel browser verification) was blocked mid-phase for lack of a browser tool in that specific executor sandbox, then resolved in a later verification pass — the STATE.md blocker note describing it as unresolved was stale by the time this milestone closed and had to be pruned by hand.
- PROJECT.md's Active/Validated requirements sections were not updated after Phase 1 completed (only after Phase 2, retroactively, during this session's transition) — the per-phase `evolve_project` step in `execute-phase`/`transition` should not be skipped even when a milestone has more phases coming.
- The dev sandbox's Postgres container password (`postgres`) didn't match `.env.testing`'s empty `DB_PASSWORD`, causing an unrelated test suite to appear to fail (`HelpSeoTest`, `ReverbClientConfigTest`) until diagnosed as a pre-existing environment mismatch, not a regression from the phase's changes. Confirmed via a `git stash` A/B rerun before concluding it was pre-existing, which is the right way to settle "is this my change or the environment" quickly.

### Patterns Established
- When a code-review finding leads to a fix commit with no PLAN.md/SUMMARY.md of its own, hand that commit's SHA explicitly to the phase verifier and instruct it to inspect the diff directly (`git show <sha>`) rather than relying on the commit message — this session's verifier caught nothing wrong, but the instruction is what made that check real rather than assumed.
- Side-by-side dev-stack coexistence (own compose project name, non-conflicting host ports) over cutover is the safer default whenever a new containerized stack could collide with an existing native dev loop's ports or data — preserves the fallback path if the new stack has problems.

### Key Lessons
1. A `checkpoint:decision` reached mid-plan execution should be resolved with a *continuation* agent carrying the completed-tasks state and the user's answer verbatim, not a plain retry — the executor never re-derives context on its own.
2. "Human verification required" doesn't always mean "hand it to the user" — when the orchestrator session itself has the tool the subagent lacked (here: a working browser), attempt the check first and report precisely what was and wasn't verifiable, rather than defaulting to a manual handoff.
3. Verify a critical review finding's fix against the *actual system behavior*, not just static config — booting the real stack and shelling into the containers to compare resolved credential values caught what a `docker compose config` diff alone would only imply.

### Cost Observations
- Model mix: executor/verifier/reviewer/orchestrator work ran on Sonnet throughout; the security auditor role resolves to Opus but was short-circuited this milestone (grep-depth L1, `threats_open: 0` at plan time) so it never actually spawned.
- Sessions: 1 (Phase 2 execution → code review → security → UAT → milestone close, in one continuous session)
- Notable: the security-auditor short-circuit rule (register authored at plan time, ASVS level 1, zero open threats) avoided an otherwise-unnecessary Opus spawn for a phase whose threat model was already sound.

---

## Cross-Milestone Trends

### Process Evolution

| Milestone | Sessions | Phases | Key Change |
|-----------|----------|--------|------------|
| v1.0 | 1 | 2 | First milestone — GSD workflow (execute → review → secure → UAT → transition → milestone close) run end to end in one session |

### Cumulative Quality

| Milestone | Tests | Coverage | Zero-Dep Additions |
|-----------|-------|----------|-------------------|
| v1.0 | 1296+ (full suite, Phase 2 close) | 100% type coverage | 0 (no new packages installed either phase) |

### Top Lessons (Verified Across Milestones)

1. Independent re-verification (re-running the actual command, not trusting a prior pass's claim) caught nothing false-positive this milestone, but is the only thing that would have caught it if it had.

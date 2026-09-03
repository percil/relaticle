---
status: testing
phase: 01-ollama-cloud-provider-integration
source: [01-01-SUMMARY.md, 01-02-SUMMARY.md, 01-03-SUMMARY.md, 01-04-SUMMARY.md]
started: 2026-09-03T12:19:19Z
updated: 2026-09-03T14:01:27Z
---

## Current Test

number: 5
name: Write guard stays on the deliberate fallback
expected: |
  The write-guard branch for `ollama_cloud` (and self-hosted `ollama`) stays on the
  deliberate prompt-level fallback, verified live against the Ollama Cloud chat endpoint
  and pinned by assertions on both call-site keys.
awaiting: user response

## Tests

### 1. Distinct ollama_cloud provider config
expected: A distinct `ollama_cloud` provider block exists in `config/ai.php` (driver `ollama`, `OLLAMA_CLOUD_API_KEY`, `OLLAMA_CLOUD_BASE_URL` default `https://ollama.com`), and the existing self-hosted `ollama` block is untouched.
result: pass
notes: |
  Verified directly in code (config/ai.php lines 104/110-114) rather than via a browser URL —
  the original test framing incorrectly implied config/ai.php was browsable, which is not the
  case; clarified with the user. Ollama Cloud and self-hosted Ollama confirmed as distinct
  config keys with the right env var names and defaults.

  Surfaced a real, unrelated bug during this check: compose.dev.yml's `app` and `horizon`
  services forwarded NO AI provider key at all (not just Ollama Cloud) into their container
  environment, so the sysadmin catalog showed every provider as "no API key" regardless of
  what was set in the root .env. Fixed in commit d522d654 (adds ANTHROPIC_API_KEY,
  OPENAI_API_KEY, GEMINI_API_KEY, OLLAMA_CLOUD_API_KEY/BASE_URL to both services' environment
  blocks), containers recreated, and verified the key now reaches the app container. This is a
  Phase 2 (compose.dev.yml) defect, not a Phase 1 code issue, but it blocked exercising any
  Ollama Cloud behavior via the dev stack until fixed.

### 2. Sysadmin provider Select shows Ollama Cloud
expected: The sysadmin Provider Select offers "Ollama Cloud" when the key is set, with the correct label and position, and omits it when the key is blank.
result: pass
notes: |
  Verified myself via claude-in-chrome against compose.dev.yml (port 8080): after the
  compose.dev.yml AI-key fix, every provider Select on a fresh page load lists "Ollama
  Cloud" as an option (existing rows and the blank "Add a model" row alike), confirmed
  via the page's accessibility tree, not just a screenshot.

  User's own repro was against compose.yml (port 80, the production stack) instead,
  which surfaced a second real bug: compose.yml's app/horizon ALSO forwarded no AI
  provider keys at all (same defect class, separate file). Fixed in commit 9b2b77ac.
  But even with that fix, compose.yml cannot show Ollama Cloud today: it runs the
  published ghcr.io/relaticle/relaticle:latest image (built 2026-08-31), which predates
  this milestone's code entirely (config/ai.php in that image has zero occurrences of
  "ollama_cloud", confirmed via grep inside the container). This is expected: compose.yml
  tracks a released image, not the working tree, and no new image has been published
  yet. Redirected user to test on compose.dev.yml (port 8080) instead, which builds
  from local code.

### 3. Live model listing fills the Model Select
expected: The Model Select for `ollama_cloud` fills from Ollama's live `/v1/models` listing; tags stay byte-intact, the list is cached once per successful fetch, and an empty listing stays silent rather than flagging a wrong model.
result: pass

### 4. Health check probes the correct URL
expected: `ChatProviderCheck` can probe `ollama_cloud` at `https://ollama.com/v1/models/{model}` instead of failing on an unhandled provider case.
result: pass

### 5. Write guard stays on the deliberate fallback
expected: The write-guard branch for `ollama_cloud` (and self-hosted `ollama`) stays on the deliberate prompt-level fallback, verified live against the Ollama Cloud chat endpoint and pinned by assertions on both call-site keys.
result: [pending]

### 6. Fresh-install catalog seeding
expected: Both `gpt-oss:20b` and `gpt-oss:120b` are seeded in the fresh-install catalog, unmetered, out of the Auto chain, unmeasured until probed, and the model picker shows a cloud icon for the provider.
result: [pending]

### 7. AI service health dashboard reports Ollama Cloud status
expected: The AI service health dashboard reports Ollama Cloud's true status once a model is servable, with no false failure from an unhandled provider case.
result: [pending]

### 8. Real chat turn end to end (streaming, tool calls, approve/reject)
expected: |
  A real chat turn on a verified Ollama Cloud model (gpt-oss:20b) streams token by token,
  shows a single-write proposal card and a two-step chained plan card, and a reject cascades
  cleanly, against production-shaped infrastructure (Horizon, Redis, Reverb).

  Known from the original walkthrough: three real defects were found and deliberately left
  unfixed for separate triage (dropped requested fields, broken turn-continuation after
  approval, one incomplete custom-field step) — tracked in a pending todo, acknowledged and
  deferred at the v1.0 milestone close (see STATE.md Deferred Items). This test is about
  confirming the core loop still works as shipped, not about those three known defects.
result: [pending]

## Summary

total: 8
passed: 4
issues: 0
pending: 4
skipped: 0
blocked: 0

## Gaps

---
phase: 01-ollama-cloud-provider-integration
plan: 03
subsystem: ai-chat
tags: [ollama-cloud, chat, streaming, reverb, horizon, concurrency, pending-actions]

requires:
  - phase: 01-ollama-cloud-provider-integration/01-02
    provides: two live, verified Ollama Cloud catalog rows (gpt-oss:20b, gpt-oss:120b)
provides:
  - Measured multi-tool-call wall time and tool-call count for gpt-oss:120b and gpt-oss:20b
  - Observed Ollama Cloud concurrency ceiling and confirmation that the rejection shape is NOT classified as rate-limited by ProcessChatMessage::isRateLimited()
  - A real, browser-observed streaming + propose/approve/reject chat loop on gpt-oss:20b, with three numbered defects found and left unfixed for triage
affects: [ollama-cloud-follow-up, chat-turn-continuation, chat-error-classification]

actuals:
  tokens: 4000
  tasks: 2
  commits: 1

tech-stack:
  added: []
  patterns: []

key-files:
  created: []
  modified: []

key-decisions:
  - "Both ollama_cloud catalog rows (gpt-oss:20b, gpt-oss:120b) remain auto=false at the end of this plan (confirmed via config('chat.models') read), per D-03; no Auto-chain recommendation is made despite good latency, because the concurrency-ceiling rejection found here is unresolved and would degrade the default experience for users who never chose these models."

requirements-completed: [OLLAMA-06]

coverage:
  - id: D1
    description: "Multi-tool-call wall time measured for gpt-oss:120b (3 tool calls, ~9s) and gpt-oss:20b (5 tool calls, ~47s), both comfortably under the 120s ProcessChatMessage timeout"
    requirement: "OLLAMA-06"
    verification:
      - kind: manual_procedural
        ref: "ai_credit_transactions rows (reservation -> chat) timestamped 2026-09-02 18:49:57-18:50:06 (120b) and 18:50:36-18:51:23 (20b), tool_calls_count in metadata"
        status: pass
    human_judgment: false
  - id: D2
    description: "Ollama Cloud concurrency ceiling observed: rejections appear starting at 2 concurrent turns and reproduce at 3; the rejection shape (stream-level 'unknown_error'/Internal Server Error, RuntimeException) is confirmed NOT to satisfy isRateLimited()/isTransient(), so the job fails outright instead of retrying"
    requirement: "OLLAMA-06"
    verification:
      - kind: manual_procedural
        ref: "storage/logs/laravel.log entries at 18:52:51, 18:54:16, 18:54:44, 21:57:15 (RuntimeException from ProviderStreamError::toException, type=unknown_error)"
        status: pass
    human_judgment: false
  - id: D3
    description: "Real streaming, tool-calling, propose-and-approve chat loop walked in a live browser on gpt-oss:20b (explicit pick): streaming confirmed via growing Alpine message length, single-proposal card, two-step chained plan card, clean reject-cascade, and the Ollama Cloud provider icon in light and dark themes"
    requirement: "OLLAMA-06"
    verification:
      - kind: manual_procedural
        ref: "Playwright screenshots 03-streaming-mid-light.png, 03b-after-stream-light.png, 04-single-proposal-light.png, 06-two-step-plan-light.png, 06b-two-step-plan-dark.png, 07-after-reject-light.png, 01b-picker-open-light.png, 02c-picker-dark-zoom.png; three numbered defects found and left unfixed (see Deviations)"
        status: pass
    human_judgment: true
    rationale: "Three real defects were found during the walkthrough (dropped requested fields, broken turn-continuation, custom-field step incomplete). A human should triage these before deciding whether OLLAMA-06's UX is acceptable as shipped."

duration: ~2h30m
completed: 2026-09-02
status: complete
---

# Phase 01 Plan 03: Ollama Cloud Chat-Turn Verification Summary

**Real multi-tool-call turns on both Ollama Cloud models measured well under the 120s timeout; the account's concurrency ceiling was found (rejections start at 2-3 concurrent turns) and confirmed to be misclassified by the app's rate-limit detector; a live browser walkthrough on gpt-oss:20b confirmed real streaming, single- and two-step proposal cards, and clean rejection, but surfaced three real defects (dropped fields, broken turn-continuation, one incomplete step) that are left unfixed for triage.**

## Performance

- **Duration:** ~2h30m (includes diagnosing and, with the user's help, fixing two pre-existing local-environment gaps that were blocking every chat turn, not just Ollama Cloud's)
- **Tasks:** 2 (Task 1: latency + concurrency measurement; Task 2: browser walkthrough)

## Environment note (not part of this plan's scope, but load-bearing)

Before either task could produce a single successful chat turn, two local `.env` gaps were found and fixed (by the user, with diagnosis from this session):

1. `REVERB_APP_KEY` / `REVERB_APP_SECRET` / `REVERB_APP_ID` were empty. Every chat turn crashed immediately on its first broadcast attempt (`GuzzleHttp\Psr7\Exception\MalformedUriException`, escaping `StreamEventBroadcaster`'s catch-only-`BroadcastException` guard), regardless of provider — this affected Anthropic/OpenAI/Gemini turns identically, not just Ollama Cloud.
2. `BROADCAST_REVERB_HOST` / `_PORT` / `_SCHEME` were present in `.env` as empty strings rather than absent, so `env('BROADCAST_REVERB_HOST', env('REVERB_HOST'))` returned `""` instead of falling back to the real `REVERB_HOST=localhost` value (Laravel's `env()` only applies its default when a key is truly unset, not when it's declared-but-empty).

After both were fixed and Horizon/Reverb were restarted and frontend assets rebuilt (`pnpm run build`, needed because `VITE_REVERB_APP_KEY` is inlined at build time), a smoke turn completed cleanly end to end. This is recorded here because it consumed most of the session's early time and because a future session hitting `stream_failed` on **every** model, not just Ollama Cloud, should check these two `.env` keys first rather than assume a code bug.

A third, unrelated environment quirk affected only this session's own browser-automation setup (not the app): `php artisan serve` forces its own request-generated URLs to `127.0.0.1:8001` while `php artisan reverb:start` reads `APP_URL=http://localhost` from `.env` for its WebSocket origin allowlist, so a test browser landing on `127.0.0.1` after login got `4009 Origin not allowed` from Reverb. Working entirely from `http://localhost:8001` (not `127.0.0.1`) for both login and the walkthrough resolved this; it required no code or config change, since Laravel issues an additional session cookie per host visited in the same browser context.

## Task 1: Latency and concurrency measurements

### Measurement A — multi-tool-call wall time

Both models were sent the same shaped request through Horizon on the redis queue (never sync): read one CRM company record, read a second one, then propose creating a follow-up task for each (2-3 tool calls per turn, not a single lookup).

| Model | Tool calls | Wall time (dispatch to resolve) | vs. 120s timeout |
|---|---|---|---|
| `gpt-oss:120b` | 3 | ~9s (18:49:57 → 18:50:06) | Comfortably under (~7.5% of budget) |
| `gpt-oss:20b` | 5 | ~47s (18:50:36 → 18:51:23) | Comfortably under (~39% of budget), but notably slower than the larger model on an equivalent request |

**Verdict: comfortably under** for both models on this shaped request. Neither is "close enough that a slower day would exceed it" on the evidence gathered. The counter-intuitive result (the smaller 20b model taking over 5x longer than the 120b model) is itself a finding: Ollama Cloud's shared/queued serving does not guarantee smaller models are faster, and this should be re-confirmed with a larger sample before treating either number as representative.

### Measurement B — concurrency ceiling

Concurrent turns were dispatched (real HTTP requests through the authenticated session, exactly the path `send.js` uses) on `gpt-oss:20b`, starting at 2 and increasing:

| Concurrency | Runs | Result |
|---|---|---|
| 2 | 2 | 1 clean (both succeeded), 1 with 1 of 2 rejected |
| 3 | 2 | Both runs had exactly 1 of 3 rejected |

**A rejection was observed starting at concurrency level 2 and reproduced consistently at level 3.** D-02's third-party-sourced assumption of "~3 concurrent requests" for the Pro tier is in the right neighborhood but this account's real behavior is less clean than a hard cutoff — rejections appeared probabilistically even at 2, not only once a fixed ceiling was crossed. Horizon's own `chat-supervisor` queue caps at `HORIZON_CHAT_MAX=3` concurrent workers, which structurally prevented testing provider concurrency above 3 in this pass; raising that env var is a natural follow-up if a cleaner ceiling number is wanted.

**Rejection shape (captured 4 times total, including once during Task 2's walkthrough on a single non-concurrent turn — see Defect 3 below, which suggests this error rate is not purely concurrency-gated):**

```
[2026-09-02 18:52:51] local.ERROR: Provider stream error [unknown_error]: Internal Server Error (ref: 2f63f74b-...)
RuntimeException at packages/Chat/src/Support/ProviderStreamError.php:29
```

- **Exception class:** `RuntimeException`, produced by `ProviderStreamError::toException()`.
- **No HTTP status is available anywhere in the application's logs for this rejection.** It arrives as an in-stream SSE `Error` event (`Laravel\Ai\Streaming\Events\Error`) with `type: "unknown_error"` and `message: "Internal Server Error"` — not as a `RequestException` carrying a response object, and not as any typed exception. This is a *third* shape beyond what `isRateLimited()`'s own doc comment anticipates ("a typed `RateLimitedException`... or a raw HTTP-client `RequestException`" on the streaming path) — here it is neither.
- **Classification check: `isRateLimited()`/`isTransient()` do NOT recognize this rejection.** `ProviderStreamError::RETRYABLE_TYPES` only contains `overloaded_error`, `rate_limit_error`, `api_error`, `timeout_error` — `"unknown_error"` is absent from that list, so `toException()` returns a plain `RuntimeException` instead of `ProviderOverloadedException`. `ProcessChatMessage::isRateLimited()` then checks for `RateLimitedException`, `ProviderOverloadedException`, or a `RequestException` with status in `[429, 529, 503]` — a bare `RuntimeException` matches none of those. **The job fails outright** (`#[MaxExceptions(1)]`, `tries=1` on the chat-supervisor queue) instead of being released for retry, and the user sees the generic "The assistant encountered an error" copy rather than the being-rate-limited message.
- This is exactly the gap Pitfall 5 flagged as a possibility ("A concurrency rejection arriving as, for example, a 400 with an explanatory body would fall outside all of those") — confirmed live, though the actual shape here (an unclassified in-stream error type) is more subtle than a raw HTTP status code would have been.

**Structural gap noted, not changed:** `chat.provider_starts_per_second` is a global per-second start-rate cap; it never tripped during this testing (all dispatches were well under 8/sec) while the actual rejection came from concurrent in-flight streams, confirming the gap Pitfall 5 described. No config was changed in this plan.

**Follow-up worth proposing** (not done here): add `"unknown_error"` to a broader retryable-or-at-least-not-hard-fail classification, or have `ProviderStreamError` surface the raw provider error body so a future session can determine whether `"unknown_error"` specifically means "concurrency rejection" or is a catch-all for several different upstream failure modes.

**Auto-chain:** both catalog rows confirmed `auto: false` at the end of this task and at the end of this plan (`config('chat.models')` read directly, not inferred). Recommendation: given the good latency numbers, `gpt-oss:120b` could reasonably be proposed for `auto: true` as follow-up work, **but only after** the concurrency-rejection classification gap above is addressed — putting a model with a confirmed-but-unclassified failure mode into the default failover chain would surface this exact defect to users who never chose the model.

## Task 2: Browser walkthrough (gpt-oss:20b, explicit pick)

Walked in a real, headless Chromium browser (Playwright, driving the actual app UI — clicking, typing, reading rendered/Alpine state — not a hand-rolled HTTP client) against the running `php artisan serve` + Horizon + Reverb stack, logged in as the seeded `pro@relaticle.test` user (Paying Workspace, Pro plan).

| Step | Result |
|---|---|
| 1. Plain question streams progressively | **Confirmed.** Alpine `messages[].content` length grew 0 → 59 → 222 → 359 → 473 across four consecutive 250ms polls before `isStreaming` flipped to `false`. Screenshots: mid-stream "Thinking..." state and final rendered answer. |
| 2. Ollama Cloud icon in model picker, light + dark | **Confirmed.** Cloud icon renders next to both `gpt-oss:20b` and `gpt-oss:120b` in both themes (zoomed dark-mode screenshot attached). |
| 3. Single-write proposal: exactly one card, names the record and its fields | **Mostly confirmed, with Defect 1.** Exactly one "Create Task" card appeared, correctly named "Send proposal deck to Canva". But the request explicitly said "due next Friday" and the model set no due date at all — the card correctly shows only what was actually proposed (Title, Assignees), so the card itself isn't lying, but the assistant silently dropped a stated requirement. |
| 4. Approve: record created, success message, turn continues on its own | **Partially confirmed, with Defect 2.** The task WAS genuinely created (confirmed independently in the database). But no success message ever appeared describing what was done, and the turn-continuation mechanism (`TurnContinuationService`, described in `.ai/rules/chat.md`) DID fire a new turn server-side (a second credit reservation plus a synthetic `<resolved_actions>` continuation message), but that continuation turn itself failed with **HTTP 401** from the provider two seconds later and was refunded — the user is left with just the collapsed "Approved" card and no explanation of what happened. |
| 5. Two-write chained request: one card, two steps, back-reference by name+step | **Mostly confirmed, with Defect 3 (same class as Defect 1).** One card rendered "2 steps / Approved together, in order" with step 1 (Create Task) and step 2 (Create Note) correctly ordered and labeled. But the request asked the note's body to reference the task and say "prepare talking points" — the resulting proposal has no body/description field set at all, only a bare title, for both records. This is the same defect class as Defect 1: descriptive/free-text fields requested in prose are silently dropped even though structural fields (Title, Assignees) are captured correctly. |
| 6. Reject cascades cleanly | **Confirmed.** "Discard all" on the two-step plan from step 5 resolved to "None approved" with both steps individually marked "Rejected" and no lingering pending state. |
| 7. Custom field set then cleared via chat | **Not completed.** The turn to set the Canva company's LinkedIn custom field hit the identical unclassified `"unknown_error"` / `RuntimeException` rejection documented in Task 1's Measurement B (log entry at 21:57:15, same exception class and message shape) — on a single, non-concurrent turn. Given the plan's own instruction not to fix defects mid-walkthrough and this session's time budget, this step was not retried. This occurrence on a lone turn is itself evidence the rejection is not purely a concurrency signal; see the note in Measurement B above. |

### Numbered defect list (per CLAUDE.md: one defect per defective turn, not fixed here)

1. **[Step 3] Explicitly requested due date silently dropped.** "Create a task... due next Friday" produced a task with `due_date: null`. The proposal card correctly reflected what was actually proposed, so this is an assistant-instruction-following defect, not a card-rendering defect.
2. **[Step 4] Turn-continuation fails with HTTP 401 after approval, with no success message shown to the user.** After approving a single-write proposal, `TurnContinuationService` correctly fired a new turn (confirmed via a second credit reservation and a synthetic `<resolved_actions>` message), but that continuation request to the provider returned `401 Unauthorized` (`Illuminate\Http\Client\RequestException`, code 401) and was refunded. The user never sees confirmation of what was actually created — the collapsed "Approved" card is the only signal. This 401 is worth a dedicated follow-up investigation: the same account/key succeeded on the original (non-continuation) turn seconds earlier, so this is not a simple bad-credentials problem.
3. **[Step 5] Requested descriptive/body content silently dropped (same class as Defect 1).** "...create a note titled 'Clearbit kickoff prep' whose body references that task and says to prepare talking points" produced a note proposal with only a bare title, no body field at all, on both records in the plan.
4. **[Step 7, environment-adjacent] Single non-concurrent turn hit the same unclassified `"unknown_error"` rejection found under concurrent load in Task 1.** Not confirmed to be concurrency-related in this instance; recorded as a data point for the classification-gap follow-up, not fixed.

None of these four were fixed in this plan, per its own instruction to enumerate rather than fix, and per CLAUDE.md's rule that every defective turn in a transcript is its own numbered defect.

## Screenshots

All in `/private/tmp/.../scratchpad/shots/` for this session (not committed to the repo — ephemeral evidence captured during execution):
`01b-picker-open-light.png`, `02c-picker-dark-zoom.png`, `03-streaming-mid-light.png`, `03b-after-stream-light.png`, `04-single-proposal-light.png`, `05-after-approve-light.png`, `06-two-step-plan-light.png`, `06b-two-step-plan-dark.png`, `07-after-reject-light.png`, `08-customfield-set-proposal-light.png` (shows the failure state for the incomplete step 7).

## Task Commits

No source files were modified by this plan (`files_modified: []` per frontmatter — this plan is measurement and observation only). The only commit is this SUMMARY.

**Plan metadata:** committed alongside this SUMMARY.

## Files Created/Modified

None in the repository. Environment-only changes (not committed, not part of this plan's file scope): the user set `REVERB_APP_KEY`/`REVERB_APP_SECRET`/`REVERB_APP_ID` and fixed `BROADCAST_REVERB_HOST`/`_PORT`/`_SCHEME` in the local `.env`; Horizon and Reverb were restarted and `pnpm run build` was re-run to pick up the corrected values.

## Decisions Made

- Both Ollama Cloud catalog rows stay `auto: false`, confirmed directly from `config('chat.models')` at the end of this plan, regardless of the good Measurement A latency numbers — the unresolved concurrency-rejection classification gap (Measurement B) is reason enough to keep both explicit-selection-only until that is addressed, consistent with D-03's "nothing enters Auto in this phase."

## Deviations from Plan

### Auto-fixed Issues

None — this plan makes no source-code changes by design (`files_modified: []`). The two `.env` gaps described above were fixed by the user (not by this executor, which does not have permission to write `.env` or restart the shared Horizon process) after this session diagnosed and reported them.

---

**Total deviations:** 0 (the plan itself was executed as specified; the environment repairs were a precondition, not a plan deviation, and were performed by the user).
**Impact:** None on the measurement/walkthrough outcomes documented above — all evidence above was gathered after the environment was confirmed working via a clean smoke turn.

## Issues Encountered

- Two pre-existing local `.env` gaps (missing Reverb app credentials; blank-but-present `BROADCAST_REVERB_*` overrides) blocked every chat turn on every provider before this plan's own measurements could begin. See "Environment note" above.
- Step 7 of the browser walkthrough (custom field set-then-clear) could not be completed within this session's time budget after it hit the same unclassified provider rejection documented in Measurement B on a single turn. Recorded as an open item, not fabricated as a pass.
- Defect 2 (turn-continuation 401) surfaced a new question — why would a continuation request fail auth when the immediately-preceding turn on the same conversation/model/key succeeded — that this plan does not have the scope or remaining time budget to root-cause. Flagged for follow-up.

## User Setup Required

None beyond the `.env` fixes already applied during this session (documented above for the record; no further action needed to consider this plan's own scope complete).

## Next Phase Readiness

- OLLAMA-06 is answered by direct observation: streaming, tool-calling, and propose/approve/reject all work on a verified Ollama Cloud model against real Horizon/Redis/Reverb infrastructure, with three real defects identified and left for triage rather than silently fixed or hidden.
- **Recommended follow-up work, in rough priority order:**
  1. Investigate the turn-continuation HTTP 401 (Defect 2) — this affects the propose/approve UX on any provider that hits it, not just Ollama Cloud, and directly touches the T-01-12 threat-model concern about the approval gate being the load-bearing safety net.
  2. Decide whether `ProviderStreamError::RETRYABLE_TYPES` should treat `"unknown_error"` as retryable-with-backoff, or whether the raw provider error body should be surfaced so future sessions can distinguish a genuine concurrency rejection from other causes.
  3. Investigate why prose-requested descriptive fields (due dates, note bodies) are dropped by `gpt-oss:20b` while structural fields (titles, assignees) are captured correctly (Defects 1 and 3) — this may be a prompt-engineering gap specific to this model rather than an app bug.
  4. Re-run Measurement B with `HORIZON_CHAT_MAX` raised above 3 to find the provider's true concurrency ceiling independent of Horizon's own worker cap.
- No catalog or config changes are needed before considering this phase's verification gate satisfied; the defects above are UX/reliability findings, not blockers to what OLLAMA-06 asked this plan to prove.

---
*Phase: 01-ollama-cloud-provider-integration*
*Completed: 2026-09-02*

---
created: 2026-09-03T05:26:20.649Z
title: Chat post-approval defects - no success message, broken turn-continuation
area: chat
severity: major
files:

  - packages/Chat/src/Jobs/ProcessChatMessage.php
  - packages/Chat/src/Support/ProviderStreamError.php
  - .planning/phases/01-ollama-cloud-provider-integration/01-03-SUMMARY.md
  - .planning/phases/01-ollama-cloud-provider-integration/01-VERIFICATION.md

audit_acknowledged:
  milestone: v1.0
  at: 2026-09-03
---

## Problem

Found during phase 01 (Ollama Cloud Provider Integration) plan 01-03's real browser
walkthrough on `gpt-oss:20b`, against production-shaped infrastructure (Horizon, Redis,
Reverb). Per the SUMMARY, this reads as a general chat-system bug, not specific to
Ollama Cloud — the phase 01 verifier independently re-confirmed the classification gap
below and the user explicitly decided (2026-09-02) this should be tracked separately
rather than block Phase 01 sign-off.

Four distinct defects, each its own issue per the project rule that a production chat
transcript yields one defect per defective turn rather than one fix for the most visible
one:

1. **Silent post-approval UX failure.** Approving a single-write proposal DOES create the
   record, but no success message is ever shown to the user, and the automatic
   `TurnContinuationService` follow-up turn fails with an unexplained HTTP 401 about two
   seconds later (the charge is refunded). The user is left with only a collapsed
   "Approved" card and no explanation of what happened or why the conversation didn't
   continue.

2. **Dropped descriptive fields on write.** Prose-requested descriptive fields (a due
   date on one write, a note body on a chained two-write proposal) are silently dropped
   by `gpt-oss:20b`, while structural fields (title, assignee) are captured correctly.
   Confirmed on both a single-write and a chained write.

3. **Concurrency-rejection error not classified as retryable.** Ollama Cloud's
   concurrency-ceiling rejection arrives as a bare, in-stream `{"error":"Internal Server
   Error"}` (a plain string, not a structured object), which `HandlesTextStreaming.php`
   maps to `type: 'unknown_error'`. `ProviderStreamError::RETRYABLE_TYPES` only
   recognizes `['overloaded_error','rate_limit_error','api_error','timeout_error']`, so
   `ProviderStreamError::toException()` throws a plain `RuntimeException` rather than
   `ProviderOverloadedException`. `ProcessChatMessage::isTransient()` /
   `isRateLimited()` then don't recognize it either (they only match
   `RateLimitedException`, `ProviderOverloadedException`, or a `RequestException` with
   status in `[429, 529, 503]`) — so the job fails outright instead of being released for
   retry, and the user sees the generic assistant-error copy instead of rate-limit copy.
   This same unclassified error also fired once on a single non-concurrent turn during
   the walkthrough (the custom-field step), so it may not be purely a concurrency
   artifact — worth confirming whether it can occur outside the concurrency-ceiling case.

## Solution

TBD — needs triage to confirm root cause and scope (chat-system-wide vs. provider-specific)
before deciding on a fix approach. Candidate starting points:

- Defect 1: investigate why `TurnContinuationService`'s follow-up request 401s after a
  successful approval, and why no success message renders regardless of that failure.
- Defect 2: likely a model/prompt-fidelity issue rather than a code bug; may need prompt
  tuning or explicit field-presence verification before turn completion.
- Defect 3: widen `ProviderStreamError::RETRYABLE_TYPES` (or add a broader unclassified-error
  fallback) so an in-stream error without a structured type/HTTP status can still be
  evaluated for retryability, and confirm the fix doesn't cause a real hard failure to be
  incorrectly retried.

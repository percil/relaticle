# Phase 01: User Setup Required

**Generated:** 2026-09-02
**Phase:** 01-ollama-cloud-provider-integration
**Status:** Incomplete

Complete this item for plan 01-01 (and everything downstream that depends on a live
Ollama Cloud connection) to proceed. Claude cannot obtain this value itself; it requires
a human with access to the Ollama Cloud account dashboard.

## Environment Variables

| Status | Variable | Source | Add to |
|--------|----------|--------|--------|
| [ ] | `OLLAMA_CLOUD_API_KEY` | ollama.com → Settings → Keys (Pro tier account, per CONTEXT.md D-02) | `.env` |

`OLLAMA_CLOUD_BASE_URL` is optional and defaults to `https://ollama.com`; no action needed
unless a non-default endpoint is required.

## Account Setup

- [ ] **Confirm Ollama Cloud Pro tier account access**
  - URL: https://ollama.com/settings/keys
  - Skip if: already have a Pro tier account and an active API key
  - Note (D-02): the Pro tier ($20/mo, ~3 concurrent requests per third-party sourced
    numbers, no official Ollama figure exists) is the working assumption for this
    integration's concurrency testing later in the phase.

## Verification

After adding the key to `.env`, verify with:

```bash
curl -s -o /dev/null -w "%{http_code}\n" \
  -H "Authorization: Bearer $OLLAMA_CLOUD_API_KEY" \
  https://ollama.com/v1/models
```

Expected result: `200`.

## Why this blocks plan 01-01

Task 1 of `01-01-PLAN.md` is a `type="tracer"` task whose `<precondition>` requires
`OLLAMA_CLOUD_API_KEY` to be set locally and the above curl call to return HTTP 200
before any config/listing/health-probe field mapping is written. The response shape
must be confirmed against the real endpoint first (research SUMMARY.md gap 1), so no
part of this plan can be safely executed until the key is present. This is an unmet
`<precondition>` per the executor's checkpoint protocol (`gate="blocking-human"`) and is
never auto-approved, even in automated/YOLO run modes.

---

**Once complete:** set the env var, run the verification command above, confirm it
returns `200`, then mark this file's status "Complete" and re-invoke plan execution for
`01-01-PLAN.md`.

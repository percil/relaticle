---
phase: "02"
slug: "orchestration-with-docker-compose-prepare-orchestration-to-e"
status: verified
# threats_open = count of OPEN threats at or above workflow.security_block_on severity (the blocking gate)
threats_open: 0
asvs_level: 1
created: "2026-09-03"
---

# Phase 02 — Security

> Per-phase security contract: threat register, accepted risks, and audit trail.

---

## Trust Boundaries

| Boundary | Description | Data Crossing |
|----------|-------------|---------------|
| unauthenticated browser to app HTTP | The panel login page is public and renders Reverb client credentials into its head | Public Reverb app key |
| browser to reverb WebSocket | Any internet client can open a socket with the public app key and attempt channel subscriptions | WebSocket frames, private-channel auth tokens |
| container to container on the Docker network | `app`, `horizon`, and `scheduler` push broadcasts to `reverb` over plain HTTP | Broadcast payloads, Reverb app id/key/secret |
| developer host to dev stack | Published host ports expose Postgres, Redis, and Mailpit to anything on the machine | DB/cache credentials, mail traffic |
| internet to published reverb port | The reverb container publishes a host port so a browser can open the socket directly or through a proxy | WebSocket handshake |
| self-hoster environment to compose | Reverb credentials arrive as environment variables from the operator's own `.env` | REVERB_APP_ID/KEY/SECRET |
| public documentation to operator action | The self-hosting guide tells operators which secrets to generate and how to expose a socket | Guidance text only |

---

## Threat Register

| Threat ID | Category | Component | Severity | Disposition | Mitigation | Status |
|-----------|----------|-----------|----------|-------------|------------|--------|
| T-02-01 | Information Disclosure | `echo-assets-hook.blade.php` rendering `reverb-app-key` on the public `/app/login` page | low | accept | Reverb app key is a public client identifier by protocol design; grants nothing on its own. | closed |
| T-02-02 | Spoofing | Attacker opens a socket with the public key and subscribes to another tenant's private chat channel | high | mitigate | `ChatServiceProvider::registerChannels()` authorization gate, unchanged. Verified: `tests/Feature/Chat/ChannelAuthTest.php` + `ChannelAuthHijackTest.php`, 9/9 passing. | closed |
| T-02-03 | Information Disclosure | Reverb app secret leaking into the browser payload via the new Blade view | high | mitigate | Negative assertion test that `reverb.apps.apps.0.secret` never appears in rendered panel HTML. Verified: `tests/Feature/Chat/ReverbClientConfigTest.php`, passing. | closed |
| T-02-04 | Tampering | Broadcast payloads travelling `app` to `reverb` unencrypted over the Docker bridge network | medium | accept | Traffic stays on the private compose network; `BROADCAST_REVERB_VERIFY` is the documented escape hatch for anyone terminating TLS in front of Reverb. | closed |
| T-02-05 | Elevation of Privilege | Hardcoded local-only Reverb/Postgres credentials in `compose.dev.yml` copied into a real deployment | medium | mitigate | `compose.dev.yml` header comment marks it a local-only supplement; `compose.yml` keeps `${VAR:?...is required}` guards. Strengthened during review remediation: `app`, `horizon`, and `scheduler` now also require `REVERB_APP_ID`/`REVERB_APP_SECRET` (previously only `reverb` did), so none of the four services can start on a placeholder. Verified: `docker compose -f compose.yml config` with required vars set renders all four services correctly; refuses to render with any unset. | closed |
| T-02-06 | Denial of Service | Published reverb host port reachable from the internet without a proxy | medium | accept | Reverb ships connection/message limits (`REVERB_APP_MAX_CONNECTIONS`, `REVERB_APP_MAX_MESSAGE_SIZE`, `REVERB_APP_RATE_LIMITING_ENABLED`) already exposed in `config/reverb.php`. The reverse-proxy path is documented as the hardened option. | closed |
| T-02-07 | Spoofing | A client connecting from an unexpected origin | medium | mitigate | `config/reverb.php` derives `allowed_origins` from `config('app.url')`; `compose.yml`'s `reverb` service is given `APP_URL` explicitly, with `REVERB_ALLOWED_ORIGIN_EXTRA` as the single documented escape hatch. Verified present in current `compose.yml` (lines ~190). | closed |
| T-02-08 | Information Disclosure | `REVERB_APP_SECRET` present in the reverb container's environment | low | accept | Required by the daemon to authenticate server-side pushes; never leaves the container. Guarded with `${REVERB_APP_SECRET:?...is required}`. | closed |
| T-02-09 | Tampering | Broadcast payloads pushed over plain HTTP between containers | medium | accept | Same rationale as T-02-04: traffic stays on the private compose network; `BROADCAST_REVERB_VERIFY` remains available. | closed |
| T-02-10 | Information Disclosure | Guide failing to distinguish the public Reverb key from the private secret, leading an operator to treat the secret as safe to share | medium | mitigate | Environment variable section states the distinction explicitly. Verified: `REVERB_APP_SECRET` documented as "must never be exposed" in `self-hosting.md`; distinction language unchanged by the review remediation (only the `openssl` generation command was normalized for consistency). | closed |
| T-02-11 | Spoofing | Guide showing a permissive WebSocket proxy that forwards any Host or Origin | medium | mitigate | Proxy snippets set forwarded headers correctly; `REVERB_ALLOWED_ORIGIN_EXTRA` documented as the single narrow escape hatch, no wildcard origin suggested. | closed |
| T-02-12 | Denial of Service | Operator publishing the reverb port straight to the internet because the guide never mentions the proxy path | medium | mitigate | WebSocket routing added to all three reverse-proxy subsections (Nginx/Caddy/Traefik) so the proxied path is the documented default. | closed |
| T-02-SC | Tampering | npm/pip/cargo installs | high | mitigate | Not triggered across all three plans and the review remediation commit: no new packages or dependencies were installed in this phase. | closed |

*Status: open · closed · open — below {block_on} threshold (non-blocking)*
*Severity: critical > high > medium > low — only open threats at or above workflow.security_block_on count toward threats_open*
*Disposition: mitigate (implementation required) · accept (documented risk) · transfer (third-party)*

---

## Accepted Risks Log

| Risk ID | Threat Ref | Rationale | Accepted By | Date |
|---------|------------|-----------|-------------|------|
| R-02-01 | T-02-01 | Reverb app key is a public client identifier by protocol design (same as a Pusher key); grants nothing without the private secret, which is never rendered. | Plan 02-01 author | 2026-09-03 |
| R-02-02 | T-02-04, T-02-09 | Internal broadcast traffic never leaves the private Docker compose network; `BROADCAST_REVERB_VERIFY` exists for anyone terminating TLS in front of Reverb. | Plans 02-01/02-02 authors | 2026-09-03 |
| R-02-03 | T-02-06 | Publishing the reverb port is required for the documented quick-start to work at all; Reverb's built-in connection/message limits apply, and the reverse-proxy path is the documented hardened alternative. | Plan 02-02 author | 2026-09-03 |
| R-02-04 | T-02-08 | `REVERB_APP_SECRET` is required in the reverb container's own environment to authenticate server-side pushes; it never leaves the container and is guarded against placeholder values. | Plan 02-02 author | 2026-09-03 |

---

## Security Audit Trail

| Audit Date | Threats Total | Closed | Open | Run By |
|------------|---------------|--------|------|--------|
| 2026-09-03 | 13 | 13 | 0 | /gsd-secure-phase orchestrator (L1 grep-depth, threats_open:0 short-circuit — plan-time register, ASVS level 1) |

Note: this audit runs after a post-plan-execution code review (`02-REVIEW.md`) found and a follow-up commit (`4b7e229f`) fixed a critical functional bug (missing `REVERB_APP_ID`/`REVERB_APP_SECRET` on `app`/`horizon`/`scheduler`). That fix is a functional correctness fix, not a new threat surface: it only completed the credential wiring T-02-05/T-02-08's mitigations already called for, using the same `${VAR:?...is required}` guard pattern already present on `reverb`. No new trust boundary or STRIDE category is introduced by it.

---

## Sign-Off

- [x] All threats have a disposition (mitigate / accept / transfer)
- [x] Accepted risks documented in Accepted Risks Log
- [x] `threats_open: 0` confirmed
- [x] `status: verified` set in frontmatter

**Approval:** verified 2026-09-03

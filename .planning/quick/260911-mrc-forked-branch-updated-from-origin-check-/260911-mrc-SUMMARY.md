---
phase: quick/260911-mrc
plan: 01
subsystem: infra
tags: [git, merge, ci, marketing-purge, billing, mail]

requires: []
provides:
  - development branch merged cleanly with origin/development (27 commits: auth hardening, MFA, passkeys, email challenges, turnstile)
  - Merge-introduced references to the already-purged marketing surface repaired
  - Full CI-equivalent gate green except one pre-existing, documented, unrelated failure
affects: [routes, billing, mail-preview, chat-service-provider, ci-workflow]

actuals:
  tokens: n/a (interactive session, not delegated to a sub-agent)
  tasks: 4
  commits: 2

tech-stack:
  added: []
  patterns:
    - "Cross-panel/public links use mailto: config('relaticle.contact.email') now that the marketing contact form is permanently gone (see .ai/rules/panel-links.md for the related wire:navigate/CORS hazard)"

key-files:
  modified:
    - .github/workflows/docker-publish.yml
    - config/relaticle.php
    - packages/Chat/src/ChatServiceProvider.php
    - routes/web.php
    - app/Support/Mail/MailPreview.php
    - resources/views/filament/pages/billing.blade.php
    - tests/Feature/Billing/BillingPageTest.php
    - tests/Feature/Email/MailPreviewTest.php
  deleted:
    - 20 marketing/vitrine files already removed by 260904-cyu, confirmed deleted rather than restored (see Task 1 below)
    - resources/views/ai-native-crm.blade.php (new marketing page added upstream, depends entirely on purged routes/components)
    - tests/Feature/Public/AiNativeCrmPageTest.php
    - tests/Feature/Email/NewContactSubmissionMailTest.php (tested the deleted mail class)

key-decisions:
  - "This plan was written assuming a clean, already-synced tree. At execution time the tree was actually mid-merge (git merge origin/development in progress, MERGE_HEAD present, real unresolved conflicts). Resolving that merge was a prerequisite the plan didn't anticipate, so it was done first, then the plan's own diagnostic gate ran against the completed merge."
  - "Every merge conflict was resolved in favor of keeping this fork's marketing/vitrine purge (260904-cyu) intact, confirmed against that task's own SUMMARY.md rather than guessed: all 'deleted by us' conflicts (ContactController, GenerateSitemapCommand, blog view overrides, pricing/press/ai/self-hosted views, competitor-facts, terms/policy markdown, and their tests) were resolved by keeping the deletion."
  - "packages/Chat/src/ChatServiceProvider.php's HEAD_END render-hook conflict was a genuine two-feature merge, not an either/or: kept HEAD's echo-assets-hook view (Reverb meta tags + vite) as one registerRenderHook call and added origin's recordChipIconScript() as a second one, confirmed Filament renders every hook registered at a location rather than replacing (same pattern already used for SIDEBAR_FOOTER per 260904-cyu)."
  - "docker-publish.yml and config/relaticle.php conflicts were resolved by keeping this branch's own explicit, more recent decisions (Docker Hub-only publishing; the AGPL source_url block) over origin's older/absent versions."
  - "The merge brought a new marketing page (ai-native-crm.blade.php) and dev-tooling change (MailPreview.php's contact-submission entry, billing.blade.php's Enterprise CTA hrefs) that referenced routes, controllers, and mail classes 260904-cyu had already deleted. These are orphans the merge itself introduced, not pre-existing bugs, so they were repaired rather than reported as out of scope."
  - "Enterprise 'contact us' CTAs on the billing page now use mailto: config('relaticle.contact.email') instead of route('contact', ...), matching the existing pattern in .well-known/security.txt. This keeps the CTA functional without reintroducing any purged marketing route."
  - "vendor/ and node_modules/ were stale relative to the merged composer.lock/pnpm-lock.yaml (rector-laravel 2.5.0 installed vs 2.6.2 locked, causing a rector fatal about unknown skip rules). Ran composer install and pnpm install --frozen-lockfile to sync; this is expected post-merge maintenance, not a repo defect."
  - "No local Postgres/Redis were running and Docker's daemon was stopped. Started Docker Desktop and scratch postgres:17-alpine (POSTGRES_HOST_AUTH_METHOD=trust, role 'root' created to match this app's expected local DB_USERNAME) and redis:7-alpine containers to run the full suite, then removed both containers afterward. No compose files or persistent infra were touched."
  - "PHP CLI memory_limit (128M) was insufficient for phpstan/type-coverage/parallel Pest under paratest's forked workers. Added a temporary /opt/homebrew/etc/php/8.5/conf.d/99-scratch-memory-limit.ini (1G) so child processes spawned by paratest inherit it, then deleted it and confirmed memory_limit reverted to 128M before finishing."
  - "Left .ai/rules/panel-links.md's route('contact', ...) code example untouched: it illustrates a still-relevant, real cross-origin wire:navigate bug class (PR #654) for ANY public route linked from a panel, not specifically the contact route. Fixing the example is a documentation nit outside this plan's blast radius (see Known Gaps)."

requirements-completed: [QUICK-260911-MRC]

status: complete
duration: ~2h (interactive)
completed: 2026-09-11
---

# Quick Task 260911-mrc: Forked Branch Health Check Summary

**Found the branch mid-merge with real conflicts (not the clean post-sync state the plan assumed), resolved all conflicts in favor of the fork's existing marketing/vitrine purge decision, completed the merge, then ran the full CI-equivalent gate and repaired three merge-introduced orphans. Final state: lint, rector, phpstan, 100% type coverage, and the full parallel Pest suite (4378 tests) all green except one pre-existing, already-documented, unrelated failure.**

## Status

**All three plan tasks + the unplanned merge-resolution prerequisite: COMPLETE.**

## What actually happened vs. the plan

The plan (`260911-mrc-PLAN.md`) was written on the assumption that `development` was already fully synced from `origin/development` (73 commits ahead, no mention of an in-progress merge). At execution time `git status` showed something different: an active `git merge` with `MERGE_HEAD` present and real unresolved conflicts in 4 files, plus 20 "deleted by us / modified by them" conflicts. Task 1's own read-only checks were meaningless against a half-merged tree, so resolving the merge came first, as a prerequisite the plan itself didn't anticipate.

## Merge Conflict Resolution

**Text conflicts (4 files), each resolved by inspecting both sides' git history and intent, not by picking a side blindly:**

1. **`.github/workflows/docker-publish.yml`** — kept this branch's own recent, explicit decision (commits `5cf1e560`/`9230a925`) to reduce publishing to Docker Hub-only, tag-triggered. Dropped origin's GHCR login/build/push steps.
2. **`config/relaticle.php`** — kept this branch's AGPL section-13 `source_url` config block and Feature Flags header comment; origin's side was empty here.
3. **`packages/Chat/src/ChatServiceProvider.php`** — genuine two-feature merge. HEAD's `HEAD_END` hook (Reverb meta tags + vite asset loading, from the Reverb-in-containers work) and origin's `HEAD_END` hook (`recordChipIconScript()`, backing `window.RECORD_CHIP_ICONS` consumed by `chat.js` for record chip icons) were BOTH needed. Verified via `vendor/filament/support/src/View/ViewManager.php` that Filament appends every `registerRenderHook()` call for a location rather than replacing it, so kept both as separate calls (dropping origin's duplicate `@vite(...)` call, since HEAD's view already loads the same two files).
4. **`routes/web.php`** — two conflicts. Imports: kept `JoinTeamViaLinkController` (deduped), added `ResendEmailChallengeController`/`VerifyEmailChallengeController`/`MailPreviewController`/`UnsubscribeController` (all used by already-merged, non-conflicting code elsewhere in the file), dropped `ComparisonController`/`ContactController`/`HomeController`/`PrivacyPolicyController` (verified these classes no longer exist on disk — deleted by 260904-cyu). Route registration: kept HEAD's domain-guarded `/` → panel redirect and origin's legitimate `/mail/unsubscribe/*` group; dropped origin's entire marketing route group (`/`, `/terms-of-service`, `/privacy-policy`, `/pricing`, `/press`, `/ai`, `/ai-native-crm`, `/self-hosted`, `/compare/*`, `/alternatives/*`, `/contact`).

**"Deleted by us" conflicts (20 files):** cross-checked every one against `.planning/quick/260904-cyu-.../260904-cyu-SUMMARY.md`, which documents exactly this purge (marketing controllers, views, data, mail, and their tests, deleted 2026-09-04 for AGPL compliance). All 20 matched that summary's "Files Created/Modified" deletion list exactly. Resolved every one by confirming the deletion (`git rm`), not restoring origin's still-modified copies.

Merge committed as `3cce6f0c`.

## Post-Merge Orphans (found by the plan's own Task 2 gate, repaired in Task 3)

Running the full CI gate against the completed merge surfaced three references the merge itself had introduced to already-purged code — none of these existed before the merge, so they are merge defects, not pre-existing bugs and not stale-from-a-sync-but-harmless:

1. **`app/Support/Mail/MailPreview.php`** — its `contact-submission` registry entry instantiated `App\Mail\NewContactSubmissionMail`, deleted by the purge. PHPStan caught this directly (`class.notFound`). Removed the entry and its import; fixed `MailPreviewTest.php`'s hardcoded registry count (17 → 16).
2. **`resources/views/filament/pages/billing.blade.php`** — both Enterprise-plan CTAs linked to `route('contact', ...)`, deleted by the purge. This didn't surface in PHPStan (Blade isn't analyzed) but broke 30 tests at runtime (`RouteNotFoundException` rendering the billing page whenever the managed-plan or Enterprise-offer sections render). Repointed both CTAs to `mailto:{{ config('relaticle.contact.email') }}`, the same config-driven contact pattern already used in `.well-known/security.txt`. Updated the two `BillingPageTest.php` assertions that checked the old absolute `route('contact')` URLs (also removed their now-pointless `config()->set('app.url', 'https://marketing.test')` setup lines, since the href no longer depends on `app.url`).
3. **`tests/Feature/Email/NewContactSubmissionMailTest.php`** — a new test file, added upstream, that directly instantiated the deleted mail class. Deleted (same treatment as the other purge-era test deletions).

Also found and removed during merge resolution (same class of orphan, caught before the gate even ran): `resources/views/ai-native-crm.blade.php` (a new marketing page origin added after the purge's merge-base) and its `tests/Feature/Public/AiNativeCrmPageTest.php`, both entirely dependent on purged routes (`ai`, `selfHosted`, `pricing`, its own `aiNativeCrm`) and a purged component directory (`x-marketing.*`) and class (`App\Support\CompetitorFacts`).

## Full CI-Equivalent Gate Results

Ran the exact commands `.github/workflows/ci.yml` runs, in the same order:

| Check | Command | Result |
|---|---|---|
| Lint | `composer test:lint` (whole repo, not `--dirty`) | ✅ passed |
| Refactor | `composer test:refactor` | ✅ passed (0 changed files) |
| Type coverage | `composer test:type-coverage` (min 100%) | ✅ 100.0% |
| Static analysis | `composer test:types` (phpstan) | ✅ 0 errors |
| Full test suite | `composer test:pest:full` (`pest --parallel --no-tia`) | ✅ 4378 tests, 4375 passed, 1 failed, 2 skipped |

**The one remaining failure** (`AiSpendStatsWidgetTest::it_has_a_cost_rate_on_every_catalog_entry_the_app_can_select`, `"gpt-oss:20b has no rate"`) is the exact pre-existing, unrelated failure already documented in 260904-cyu's Known Gaps #7 (an AI model pricing-catalog gap from the prior Ollama Cloud Provider milestone). Confirmed still present, still unrelated to this merge or the marketing purge, and correctly out of scope for this plan per its own hard-stop rules (would require touching `chat.models` config or `ModelRegistry`, unrelated production code).

Also verified per Task 1, before the CI gate: no leftover conflict markers in tracked source, `composer validate --strict` clean (one pre-existing cosmetic warning about the AGPL-3.0 SPDX identifier, unrelated), `route:list --except-vendor` resolves all 174 non-vendor routes with no missing controller classes, and all pending migrations (including the new email-challenge tables from origin) applied cleanly to a scratch database.

## Environment Notes (this session only, not committed)

- `vendor/` and `node_modules/` were stale relative to the merged lock files (rector-laravel 2.5.0 installed vs. 2.6.2 locked — this caused Rector's first run to fail with "unknown skip rules"). Ran `composer install` and `pnpm install --frozen-lockfile` to resync.
- No local Postgres/Redis were reachable and Docker's daemon was stopped. Started Docker Desktop, then scratch `postgres:17-alpine` (trust auth, `root` superuser role created to match this app's local `DB_USERNAME`) and `redis:7-alpine` containers, port-mapped to the app's expected 5432/6379. Removed both containers after the gate finished; no compose files or persistent config touched.
- PHP CLI `memory_limit` (128M) was too low for phpstan/type-coverage/parallel-Pest under paratest's forked worker processes (which don't inherit a `php -d` flag passed to the parent). Added a temporary `/opt/homebrew/etc/php/8.5/conf.d/99-scratch-memory-limit.ini` (1G), ran the gate, deleted the file, and confirmed `memory_limit` reverted to 128M.
- `vendor/pestphp/pest-plugin-type-coverage/.temp/` is a read-only directory in this environment (pre-existing, unrelated to this task), so the type-coverage plugin's file cache warns on every run. Cosmetic only — the check itself completes and reports 100%.

## Known Gaps (Deferred, Not Fixed)

- **`.ai/rules/panel-links.md`** still shows `route('contact', absolute: false)` as its illustrative code example for the cross-origin `wire:navigate` hazard (PR #654). That underlying rule is still correct and still applies to any panel-to-public link (e.g. `/help`), but the specific example now references a deleted route. A documentation nit with no functional impact (not analyzed by PHPStan, not exercised by any test) — left alone per this plan's scope (diagnostic + minimal repair of concrete defects, not a docs sweep).
- **`tests/.pest/shards.json`** still has a stale `Tests\Feature\Email\NewContactSubmissionMailTest` entry now that the file is deleted. Harmless (Pest's shard plugin only writes on a fully green run and simply ignores a shard entry for a class that no longer exists); the 260904-cyu summary already noted this same file wasn't regenerable at the time. Recommend `composer test:update-shards` in a follow-up once convenient.

## Files Created/Modified

**Merge commit `3cce6f0c`:** resolved 4 text conflicts (`.github/workflows/docker-publish.yml`, `config/relaticle.php`, `packages/Chat/src/ChatServiceProvider.php`, `routes/web.php`) and 20 delete/modify conflicts (confirmed deletions), plus removed 2 new-but-orphaned files (`resources/views/ai-native-crm.blade.php`, `tests/Feature/Public/AiNativeCrmPageTest.php`).

**Fixup commit `2d179ac8`:** `app/Support/Mail/MailPreview.php`, `resources/views/filament/pages/billing.blade.php`, `tests/Feature/Billing/BillingPageTest.php`, `tests/Feature/Email/MailPreviewTest.php` modified; `tests/Feature/Email/NewContactSubmissionMailTest.php` deleted.

## Self-Check: PASSED

Both commits (`3cce6f0c`, `2d179ac8`) confirmed present in `git log`. Full gate re-run after the fixup commit confirms 4378 tests / 4375 passed / 1 pre-existing unrelated failure / 2 skipped, matching the state described above. Working tree clean except the three pre-existing untracked paths (`.gsd/`, `.planning/research/.cache/`, `.planning/state.json`), left alone per the plan.

---
*Quick task: 260911-mrc*
*Completed: 2026-09-11*

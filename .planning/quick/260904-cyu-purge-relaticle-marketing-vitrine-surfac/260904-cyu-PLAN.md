---
phase: quick/260904-cyu
plan: 01
type: execute
wave: 1
depends_on: []
autonomous: false
requirements: [FORK-07, FORK-09]
files_modified:
  - routes/web.php
  - config/relaticle.php
  - config/ink.php
  - config/comparisons.php
  - bootstrap/app.php
  - app/Providers/Filament/AppPanelProvider.php
  - app/Http/Controllers/HomeController.php
  - app/Http/Controllers/ComparisonController.php
  - app/Http/Controllers/AlternativesController.php
  - app/Http/Controllers/ContactController.php
  - app/Http/Controllers/TermsOfServiceController.php
  - app/Http/Controllers/PrivacyPolicyController.php
  - app/Http/Requests/ContactRequest.php
  - app/Mail/NewContactSubmissionMail.php
  - app/Support/MarketingNavigation.php
  - app/Support/NavItem.php
  - app/Support/CompetitorFacts.php
  - app/Console/Commands/GenerateSitemapCommand.php
  - app/Console/Commands/ReportStaleCompetitorFactsCommand.php
  - packages/Documentation/src/Http/Controllers/HelpController.php
  - packages/Documentation/resources/views/components/shell.blade.php
  - packages/Documentation/resources/views/components/article.blade.php
  - packages/Documentation/resources/views/help/hub.blade.php
  - resources/views/filament/auth/footer.blade.php
  - resources/views/filament/app/logo.blade.php
  - resources/views/filament/app/logo-empty.blade.php
  - resources/views/filament/app/source-link.blade.php
  - resources/data/competitor-facts.php
  - lang/en/auth.php
  - lang/fr/auth.php
  - NOTICE.md
  - README.md
  - tests/.pest/shards.json

estimate:
  tokens: 190000
  raw_tokens: 95000
  tasks: 7
  confidence: low

must_haves:
  truths:
    - "GET /pricing, /press, /ai, /self-hosted, /contact, /terms-of-service, /privacy-policy, /compare/relaticle-vs-twenty and /alternatives/attio all return 404."
    - "GET / no longer renders Relaticle marketing content; on a path-mode deployment it redirects to the CRM panel, and on a domain-mode deployment it is not registered at all."
    - "GET /help, GET /developers and GET /llms.txt still return 200 with no RouteNotFoundException, because every removed route name has been dropped from their callers."
    - "The CRM panel at /app boots and no longer presents the brand name Relaticle."
    - "The security.txt contact address and the contact-email config default both resolve from configuration rather than a hardcoded upstream address."
    - "NOTICE.md exists at the repository root, names the upstream project, states this is a modified fork, and gives the modification start date."
    - "A signed-in panel user sees a visible link to this fork's source repository."
    - "The full quality gate passes: vendor/bin/phpstan analyse, composer test:type-coverage at 100%, composer test:lint, composer test:pest:full."
  artifacts:
    - NOTICE.md
    - resources/views/filament/app/source-link.blade.php
    - routes/web.php
    - config/relaticle.php
  key_links:
    - "packages/Documentation (HelpController, shell.blade.php, article.blade.php, help/hub.blade.php) no longer calls any removed route name, so /help and /developers keep rendering."
    - "AppPanelProvider registers a SIDEBAR_FOOTER render hook that renders the AGPL source link for every signed-in user, not only workspace admins."
    - "bootstrap/app.php withSchedule() no longer schedules app:generate-sitemap after the command is deleted."
    - "resources/views/filament/app/logo.blade.php keeps resolving through x-brand.logo-lockup, which resources/views/layouts/invitation.blade.php also depends on."
---

<objective>
Purge the Relaticle marketing and vitrine surface from this fork, and add the AGPL section 5(a) and section 13 compliance artifacts.

Purpose: this fork is a private CRM deployment. Serving Relaticle's marketing site, comparison pages, press kit and contact form from it is wrong on every axis: it publishes another party's commercial positioning, it puts a public form on a private deployment, and the `/` route will collide with the panel root the moment `APP_PANEL_DOMAIN` is set. AGPL-3.0 separately requires a dated modification notice and, because the panel is network-reachable, an offer of source to remote users.

Output: marketing routes, controllers, views, support classes and data removed; every downstream caller fixed so nothing 500s; panel rebranded off the upstream name; NOTICE.md and a panel source link added; the whole suite green.
</objective>

<execution_context>
@~/.claude/gsd-core/workflows/execute-plan.md
@~/.claude/gsd-core/templates/summary.md
</execution_context>

<context>
@.planning/STATE.md
@CLAUDE.md
@.ai/rules/index.md
@.ai/rules/boost/http-routes.md
@.ai/rules/boost/livewire-views.md
@.ai/rules/boost/tests.md
</context>

<discovery_findings>
Verified live against the working tree before this plan was written. Do not re-derive; do confirm anything that looks stale.

**Panel routing.** `AppPanelProvider::panel()` calls `->domain(config('app.app_panel_domain'))` when that env value is set, otherwise `->path(config('app.app_panel_path', 'app'))`. Locally the panel is path-mode at `/app`, so `/` and the panel do not currently collide. The collision is latent, not live.

**Cross-package coupling (the load-bearing discovery).** `packages/Documentation` is NOT self-contained. It calls removed route names in five places: `HelpController::llmsTxt()` and its helpers call `route('press')`, `route('ai')`, `route('selfHosted')`, `route('compare.show')` and `route('alternatives.show')`; `components/shell.blade.php` calls `route('pricing')` and `url('/')`; `components/article.blade.php` and `help/hub.blade.php` call `route('contact')`. `route()` on an unregistered name throws `RouteNotFoundException`, so deleting the marketing routes without fixing these turns `/help`, `/developers` and `/llms.txt` into 500s.

**Documentation stays enabled.** It is reachable from inside the product: `resources/views/filament/pages/access-tokens.blade.php` links `route('documentation.show', ['type' => 'mcp'])` and `packages/Chat/src/Tools/SearchDocsTool.php` embeds `route('help.index')`. Disabling the Documentation feature flag is therefore NOT an acceptable shortcut. Fix the callers instead.

**Panel auth footer.** `resources/views/filament/auth/footer.blade.php` links `/terms-of-service`, `/privacy-policy` and `/contact` through `url()`, so it will not throw, but it will render three dead links on the login page.

**Blog.** `resources/views/blog/` exists and is explicitly in scope for removal. `config/ink.php` overrides ink's view names to point at those app views. `App\Features\Blog` resolves from `relaticle.features.blog`, default `false`, and `config/ink.php` sets `features.public_routes => false`, so no blog route is registered today. `resources/css/app.css` line 17 has `@source "../../vendor/relaticle/ink/resources/views"`, which proves the ink package ships its own views, so dropping the `views` override in `config/ink.php` is safe.

**Client data.** A case-insensitive repo-wide scan for `engie` and `silva` (excluding vendor, node_modules and .git) returns zero hits. Task 7 re-verifies rather than assumes.

**Fork divergence date.** The first commit authored by this fork's maintainer is `1d1277fd`, dated 2026-09-02. That is the AGPL 5(a) "relevant date".

**Marketing-only support surface.** `App\Support\MarketingNavigation`, `App\Support\NavItem`, `App\Support\CompetitorFacts`, `resources/data/competitor-facts.php`, `config/comparisons.php`, `app/Console/Commands/ReportStaleCompetitorFactsCommand.php` and `resources/views/components/layout/*` have no non-marketing consumer. `App\Support\SupportForms`, `App\Support\BrandColors`, `App\Support\DetectsPublicMarkdownRequest` and `resources/views/components/brand/*` DO have non-marketing consumers. Keep those.
</discovery_findings>

<out_of_scope>
Stated explicitly so no task drifts into them.

- Renaming `Relaticle\SystemAdmin`, `Relaticle\Chat`, `Relaticle\Documentation`, `Relaticle\ImportWizard`, `Relaticle\OnboardSeed` or any other package namespace.
- Rewriting the prose content of `packages/Documentation/resources/content/**`, which still contains upstream URLs such as the hosted MCP endpoint. That is a content rebranding job, not a route purge. Surface it in the summary as a follow-up.
- DNS, Traefik, certificates, `.env` values, deployment config, or anything outside this repository's tracked source.
- Removing the `spatie/laravel-sitemap` composer dependency. Task 4 deletes its consumer but leaves the package installed, because dependency changes need approval per CLAUDE.md.
- Seeded demo accounts on the upstream domain (`app/Console/Commands/ResetDemoAccountCommand.php`, `database/seeders/SystemAdministratorSeeder.php`). They are local and test fixtures, not published surface. Note them; do not change them here.
</out_of_scope>

<tasks>

<task type="tracer">
  <name>Task 1: Cut the marketing surface off at the route layer, end to end</name>
  <files>routes/web.php, packages/Documentation/src/Http/Controllers/HelpController.php, packages/Documentation/resources/views/components/shell.blade.php, packages/Documentation/resources/views/components/article.blade.php, packages/Documentation/resources/views/help/hub.blade.php, resources/views/filament/auth/footer.blade.php, lang/en/auth.php, lang/fr/auth.php</files>
  <read_first>routes/web.php, tests/Feature/Routing/PrimaryHostRoutingTest.php, packages/Documentation/src/Http/Controllers/HelpController.php (the llmsTxt method and its private helpers), lang/en/auth.php (the footer key group)</read_first>
  <action>
Remove the entire `Route::middleware([ProvideMarkdownResponse::class, AddVaryAcceptHeader::class])` group in `routes/web.php` that registers `/`, `/terms-of-service`, `/privacy-policy`, `/pricing`, `/press`, `/ai`, `/self-hosted`, `/compare/relaticle-vs-{competitor}`, `/alternatives/{competitor}` and both `/contact` verbs. Drop the now-unused imports for the six marketing controllers and for `ProtectAgainstSpam`. Keep `ProvideMarkdownResponse` and `AddVaryAcceptHeader` imported only if something else in the file still uses them; if not, drop them too.

Replace the root route with a guarded redirect. Register `Route::get('/', ...)` returning `redirect()->to(url()->getAppUrl())` ONLY when `config('app.app_panel_domain')` is null, mirroring the existing `/dashboard` route's body. Guarding on the config value eliminates the panel-root collision by construction rather than relying on route registration order, which is the failure the requester flagged. Add a short comment above it explaining that guard.

Make the security.txt contact address configuration driven: swap the hardcoded upstream mailbox for `config('relaticle.contact.email')`. Leave the config default alone here; Task 5 changes the default value.

Fix every caller of a removed route name so nothing throws:
- `HelpController`: delete `llmsTxtProductEntries()` and `llmsTxtComparisonEntries()` entirely, delete the Company section block that links the press kit, and delete the call sites and the section headings that fed them. Keep the docs, help and blog sections. Remove the now-unused `CompetitorFacts` import and any now-unused `config('comparisons.*')` reads.
- `components/shell.blade.php`: delete the Pricing link from the footer nav and the Product link that pointed at the marketing home. Retarget both `url('/')` brand anchors at the docs index via `route('documentation.index')` so the shell's logo still goes somewhere real.
- `components/article.blade.php` and `help/hub.blade.php`: replace the contact-page anchors with a `mailto:` built from `config('relaticle.contact.email')`.
- `resources/views/filament/auth/footer.blade.php`: this footer's three links now point at deleted pages. Remove the terms notice paragraph and the privacy and support anchors, leaving only the copyright line. Delete the orphaned `footer.terms_notice`, `footer.terms_of_service`, `footer.privacy_policy` and `footer.support` keys from `lang/en/auth.php` and their counterparts in `lang/fr/auth.php`. Keep `footer.copyright`; Task 5 rebrands its value.

Wrap any new user-facing string in `__()` and add it to both `lang/en` and `lang/fr`. Do not introduce an em-dash anywhere.
  </action>
  <verify>
    <automated>php artisan route:list --except-vendor 2>&1 | grep -Ec '(^| )(pricing|press|self-hosted|contact|privacy-policy|terms-of-service|compare/|alternatives/)' | grep -qx 0 &amp;&amp; php artisan test --compact --filter='HelpRoutes|HelpSeo|PrimaryHostRouting'</automated>
    <manual>curl -sS -o /dev/null -w '%{http_code}\n' http://relaticle.test/help http://relaticle.test/developers http://relaticle.test/llms.txt http://relaticle.test/pricing and confirm 200 200 200 404.</manual>
  </verify>
  <done>Every marketing URI is absent from `route:list`. `/help`, `/developers` and `/llms.txt` return 200. `/pricing` returns 404. No `RouteNotFoundException` appears in `storage/logs` or `browser-logs` during the walk.</done>
  <reversibility rating="reversible">Route and view deletions are recoverable from git history; no data or external state is touched.</reversibility>
</task>

<task type="auto">
  <name>Task 2: Delete the orphaned marketing controllers, request, mail and support classes</name>
  <files>app/Http/Controllers/HomeController.php, app/Http/Controllers/ComparisonController.php, app/Http/Controllers/AlternativesController.php, app/Http/Controllers/ContactController.php, app/Http/Controllers/TermsOfServiceController.php, app/Http/Controllers/PrivacyPolicyController.php, app/Http/Requests/ContactRequest.php, app/Mail/NewContactSubmissionMail.php, app/Support/MarketingNavigation.php, app/Support/NavItem.php, app/Support/CompetitorFacts.php</files>
  <read_first>app/Mail/NewContactSubmissionMail.php (to find the Blade view it renders, then delete that view too)</read_first>
  <action>
Delete the six marketing controllers, `ContactRequest`, `NewContactSubmissionMail` and the mail Blade view that `NewContactSubmissionMail` renders. Delete `App\Support\MarketingNavigation`, `App\Support\NavItem` and `App\Support\CompetitorFacts`.

Before deleting each file, grep the whole repository for its class name to confirm the only remaining references are the tests removed in Task 7 and the views removed in Task 3. `NavItem` is used only by `MarketingNavigation` and the three layout components going away in Task 3. `CompetitorFacts` has one caller outside marketing, `ReportStaleCompetitorFactsCommand`, which Task 4 deletes; if you run this task before Task 4, delete that command here instead of leaving a broken import.

Do NOT delete `App\Support\SupportForms` (used by `AppPanelProvider`), `App\Support\BrandColors`, or `App\Support\DetectsPublicMarkdownRequest` (used by `tests/Arch/ArchTest.php` and the Documentation tests).

Do not touch `app/Console/Commands/ResetDemoAccountCommand.php` or the seeders; those are called out as out of scope.
  </action>
  <verify>
    <automated>vendor/bin/pint --dirty --format agent &amp;&amp; vendor/bin/phpstan analyse</automated>
  </verify>
  <done>The six controllers, the contact request, the contact mailable and its view, and the three marketing support classes are gone. PHPStan reports no new errors and no unresolved class references.</done>
</task>

<task type="auto">
  <name>Task 3: Delete the orphaned marketing views, data and config</name>
  <files>resources/views/home/, resources/views/compare/, resources/views/alternatives/, resources/views/blog/, resources/views/partials/, resources/views/components/marketing/, resources/views/components/home/, resources/views/components/blog/, resources/views/components/layout/, resources/views/layouts/guest.blade.php, resources/views/ai.blade.php, resources/views/press.blade.php, resources/views/pricing.blade.php, resources/views/self-hosted.blade.php, resources/views/contact.blade.php, resources/views/terms.blade.php, resources/views/policy.blade.php, resources/markdown/, resources/data/competitor-facts.php, config/comparisons.php, config/ink.php</files>
  <read_first>resources/views/components/layout/head.blade.php, resources/views/layouts/invitation.blade.php, resources/markdown/ (list the four files and grep each filename across the repo before deleting)</read_first>
  <action>
Delete the marketing view tree: the `home`, `compare`, `alternatives`, `blog` and `partials` directories under `resources/views`; the `marketing`, `home`, `blog` and `layout` directories under `resources/views/components`; `resources/views/layouts/guest.blade.php`; and the seven standalone marketing Blade files (`ai`, `press`, `pricing`, `self-hosted`, `contact`, `terms`, `policy`).

Delete `resources/data/competitor-facts.php` and `config/comparisons.php`.

For `resources/markdown`: grep each of the four filenames (`terms.md`, `policy.md`, `brand-assets.md`, `font-sources.md`) across `app`, `packages`, `resources` and `config` first. Delete only the ones whose sole consumers were the deleted controllers and views. If any file still has a live consumer, keep it and record which in the summary.

In `config/ink.php`, delete the whole `views` override block so ink falls back to its own package views, which `resources/css/app.css` already sources from `vendor/relaticle/ink/resources/views`. Then retarget the branded defaults: pull `feed.title`, `feed.description`, `feed.author_email`, `publisher.name` and `publisher.url` off the upstream values and onto env-backed values that default to `config('app.name')`, `config('relaticle.contact.email')` and `config('app.url')` as appropriate. Leave `publisher.logo` pointing at the existing manifest icon.

Do NOT delete `resources/views/components/brand/` (the panel logo and the invitation layout both render `x-brand.logo-lockup`), `resources/views/components/icons/`, `resources/views/layouts/invitation.blade.php` or `resources/views/layouts/filament-standalone.blade.php`.

`resources/views/layouts/invitation.blade.php` links its logo at `url('/')`. Since `/` is now a panel redirect on path-mode and unregistered on domain-mode, retarget that anchor at `url()->getAppUrl()` so it never points at a 404.

After deleting, grep `resources/views` and `packages/*/resources/views` for `x-layout.`, `layouts.guest`, `x-marketing.`, `x-home.` and `x-blog.` and confirm every remaining hit lives in a file you also deleted. `packages/Documentation/resources/views/components/shell.blade.php` was reported as a hit by the pre-plan grep, so read it and confirm whether it truly renders a deleted component or only matched on an unrelated string. If it renders one, replace it with the Documentation package's own equivalent.
  </action>
  <verify>
    <automated>pnpm run build &amp;&amp; php artisan view:clear &amp;&amp; php artisan config:clear &amp;&amp; php artisan about > /dev/null</automated>
    <manual>Load the panel login page and one help article in agent-browser, light and dark, and confirm no missing-component exception and no unstyled regression.</manual>
  </verify>
  <done>The marketing view tree, competitor data and comparisons config are gone. `pnpm run build` succeeds. `php artisan about` boots. No remaining Blade file references a deleted component.</done>
</task>

<task type="auto">
  <name>Task 4: Retire the sitemap and competitor-facts commands</name>
  <files>app/Console/Commands/GenerateSitemapCommand.php, app/Console/Commands/ReportStaleCompetitorFactsCommand.php, bootstrap/app.php, config/sitemap.php, public/robots.txt, public/sitemap.xml</files>
  <read_first>bootstrap/app.php (the withSchedule closure), public/robots.txt</read_first>
  <action>
`GenerateSitemapCommand` crawls the marketing site and stitches in documentation and blog URLs. With the marketing surface gone, it would publish a sitemap for a private CRM. Delete the command, delete `ReportStaleCompetitorFactsCommand` (its only data source, `resources/data/competitor-facts.php`, no longer exists), and remove the `app:generate-sitemap` line from the `withSchedule()` closure in `bootstrap/app.php`.

Delete `config/sitemap.php` and any committed `public/sitemap.xml`. In `public/robots.txt`, remove the `Sitemap:` directive if one is present, and confirm the file does not otherwise advertise upstream marketing paths.

Leave `spatie/laravel-sitemap` in `composer.json`. Removing a dependency needs approval; note it in the summary as a follow-up so someone can prune it deliberately.

Confirm no other scheduled command or provider references either deleted command class.
  </action>
  <verify>
    <automated>php artisan schedule:list > /dev/null &amp;&amp; php artisan list 2>&amp;1 | grep -c 'app:generate-sitemap' | grep -qx 0</automated>
  </verify>
  <done>Neither command is registered. `php artisan schedule:list` runs without error and no longer lists the sitemap job. `robots.txt` advertises no sitemap and no removed path.</done>
</task>

<task type="auto">
  <name>Task 5: Rebrand the panel and config defaults off the upstream name</name>
  <files>app/Providers/Filament/AppPanelProvider.php, resources/views/filament/app/logo.blade.php, resources/views/filament/app/logo-empty.blade.php, config/relaticle.php, lang/en/auth.php, lang/fr/auth.php, .env.example</files>
  <read_first>app/Providers/Filament/AppPanelProvider.php lines 195 to 225, config/relaticle.php, resources/views/components/brand/logo-lockup.blade.php</read_first>
  <action>
In `AppPanelProvider::panel()`, replace the literal `->brandName('Relaticle')` with a configuration read. Add a `brand.name` key to `config/relaticle.php` backed by an env var and defaulting to `config('app.name')`, and have `brandName()` read it. `config('app.name')` itself defaults to `Laravel` from `env('APP_NAME')`, so the fork's own `.env` becomes the single source of truth for the displayed name.

Update `resources/views/filament/app/logo-empty.blade.php` so its screen-reader label reads the configured brand name instead of the hardcoded upstream one. Update `resources/views/filament/app/logo.blade.php` and `resources/views/components/brand/logo-lockup.blade.php` the same way for any hardcoded wordmark text or aria-label. Keep `x-brand.logo-lockup` as the component name; `resources/views/layouts/invitation.blade.php` depends on it. If `logo-lockup` renders an inline SVG wordmark that spells the upstream name, replace it with a text wordmark rendering the configured brand name rather than shipping a mislabelled mark.

In `config/relaticle.php`, change the `contact.email` default off the upstream mailbox. Default it to `null` and make the callers degrade gracefully, or default it to a neutral placeholder, whichever keeps `security.txt` and the Documentation mailto links valid. Whichever you pick, `.env.example` must document `CONTACT_EMAIL` so an operator sets it. Change the `company.name` default the same way.

Update the `footer.copyright` string in `lang/en/auth.php` and `lang/fr/auth.php` so the year line no longer hardcodes the upstream name; interpolate the configured brand name as a second placeholder.

Search `app`, `resources/views/filament`, `config` and `lang` for any other user-facing hardcoded occurrence of the upstream product name and fix each one the same way. Do not touch PHP namespaces, class names, package directory names, composer package names, or the `vendor/relaticle/*` paths.

Respect the i18n rules: every user-facing string goes through `__()`, and the two custom PHPStan rules will fail the build if you add a hardcoded one to a guarded method or static property.
  </action>
  <verify>
    <automated>vendor/bin/pint --dirty --format agent &amp;&amp; vendor/bin/phpstan analyse &amp;&amp; composer test:type-coverage</automated>
    <manual>Log into the panel in agent-browser and screenshot the sidebar and the login page in light and dark. Confirm the header, the browser tab title and the login footer all show the configured brand name and not the upstream one.</manual>
  </verify>
  <done>The panel brand name, logo label and auth footer copyright all resolve from configuration. PHPStan and type coverage stay clean. No hardcoded user-facing occurrence of the upstream product name remains in `app`, `resources/views/filament`, `config` or `lang`.</done>
  <reversibility rating="reversible">Config-driven branding; reverting is a one-line default change.</reversibility>
</task>

<task type="auto">
  <name>Task 6: AGPL compliance - NOTICE.md, license integrity, and the panel source link</name>
  <files>NOTICE.md, README.md, LICENSE, config/relaticle.php, .env.example, app/Providers/Filament/AppPanelProvider.php, resources/views/filament/app/source-link.blade.php, lang/en/filament/, lang/fr/filament/</files>
  <read_first>LICENSE (confirm it is AGPL-3.0 and unmodified), README.md, app/Providers/Filament/AppPanelProvider.php (the SIDEBAR_FOOTER render hook block around line 358), resources/views/filament/app/sidebar-footer.blade.php</read_first>
  <action>
Create `NOTICE.md` at the repository root. It must satisfy AGPL-3.0 section 5(a): state prominently that this is a modified version of the upstream Relaticle project, name the upstream project and its repository URL, and give the date modification began. Use 2026-09-02, the date of commit `1d1277fd`, the first commit authored by this fork's maintainer. Also state that the work remains licensed under AGPL-3.0 and that the full license text is in `LICENSE`. Keep it short, plain and factual. No em-dash, no emoji, one idea per sentence.

Verify `LICENSE` is intact and unmodified. Run `git log --follow --oneline -- LICENSE` and confirm this fork has made no commit against it. If the fork has touched it, restore the upstream content. Confirm the file is the full AGPL-3.0 text. Confirm no upstream copyright headers have been stripped from source files by spot-checking a handful of files that carry one.

Add a short upstream-attribution section to `README.md` pointing at `NOTICE.md` and the upstream repository. Do not rewrite the rest of the README in this task.

Add the AGPL section 13 source offer. The CRM panel is network-reachable, so remote users are entitled to the corresponding source. Add a `source_url` key to `config/relaticle.php`, env-backed, defaulting to this fork's own repository URL, and document it in `.env.example`. Create `resources/views/filament/app/source-link.blade.php` rendering one small, quiet, always-visible anchor to that URL with `target="_blank" rel="noopener noreferrer"`, its label wrapped in `__()` and added to both `lang/en` and `lang/fr`. Register it in `AppPanelProvider` as a second `PanelsRenderHook::SIDEBAR_FOOTER` hook. It must be a separate hook from the existing `filament.app.sidebar-footer` view, because that view is gated behind a workspace-admin check and would hide the link from everyone else. If the configured `source_url` is empty, render nothing.

Confirm against the installed Filament version that stacking two hooks on the same `SIDEBAR_FOOTER` location renders both rather than replacing one. If it replaces, move the source link to `PanelsRenderHook::BODY_END` instead and say so in the summary.
  </action>
  <verify>
    <automated>test -f NOTICE.md &amp;&amp; grep -q '2026-09-02' NOTICE.md &amp;&amp; git log --oneline 1d1277fd..HEAD -- LICENSE | grep -qc . ; test $? -eq 1 &amp;&amp; vendor/bin/pint --dirty --format agent &amp;&amp; vendor/bin/phpstan analyse</automated>
    <manual>Log into the panel in agent-browser as a NON-admin member of a workspace and screenshot the sidebar in light and dark. Confirm the source link is visible, and confirm clicking it opens the configured repository URL in a new tab.</manual>
  </verify>
  <done>`NOTICE.md` exists, names the upstream project and the 2026-09-02 modification date, and points at `LICENSE`. `LICENSE` has no fork commits against it. A non-admin panel user sees a working source link in the sidebar footer.</done>
  <reversibility rating="costly">NOTICE.md and the source link are the fork's public AGPL compliance posture. Removing them later after the fork has been served to remote users is a licensing regression, not a neutral revert.</reversibility>
</task>

<task type="auto">
  <name>Task 7: Sweep the test suite, re-verify client data, and run the full gate</name>
  <files>tests/Feature/Public/, tests/Feature/Commands/GenerateSitemapCommandTest.php, tests/Feature/Commands/ReportStaleCompetitorFactsCommandTest.php, tests/Feature/Documentation/, tests/Feature/Filament/PanelNoindexTest.php, tests/Feature/Routing/PrimaryHostRoutingTest.php, tests/Feature/Support/FeatureFlagsTest.php, tests/Smoke/RouteTest.php, tests/Arch/, tests/.pest/shards.json</files>
  <read_first>tests/Feature/Public/PublicPagesTest.php, tests/Feature/Routing/PrimaryHostRoutingTest.php, tests/Feature/Filament/PanelNoindexTest.php, tests/Feature/Support/FeatureFlagsTest.php, tests/Arch/TestSuiteIntegrityTest.php</read_first>
  <action>
Delete the test files whose entire subject is gone: `tests/Feature/Public/ComparisonPagesTest.php`, `ContactFormTest.php`, `MarketingNavigationTest.php`, `PressPageTest.php`, `PricingPageTest.php`, `ProductPagesTest.php`, and `tests/Feature/Commands/GenerateSitemapCommandTest.php` and `ReportStaleCompetitorFactsCommandTest.php`.

`tests/Feature/Public/PublicPagesTest.php` is mixed: it covers the deleted home, terms and policy controllers AND `DetectsPublicMarkdownRequest`, which survives. Read it, keep the surviving coverage by moving it into `tests/Feature/Documentation/MarkdownResponseTest.php` or an equivalent existing file, then delete the rest. Update the `mutates(...)` declaration to name only surviving classes.

Update, do not delete, the tests that merely touch a removed route: `tests/Feature/Documentation/HelpRoutesTest.php`, `HelpSeoTest.php`, `MarkdownResponseTest.php`, `tests/Feature/Filament/PanelNoindexTest.php`, `tests/Feature/Routing/PrimaryHostRoutingTest.php`, `tests/Feature/Support/FeatureFlagsTest.php`.

Add positive coverage for the new behavior in the file where it belongs. `PrimaryHostRoutingTest` is the right home for two new assertions: that each removed marketing URI returns 404, and that the root route is a redirect to the panel when no panel domain is configured and is absent when one is. Never weaken an existing assertion to make the suite green; if one now asserts a stale value, fix the assertion at the test layer.

Re-verify the client-data constraint independently rather than trusting the pre-plan scan. Run a case-insensitive recursive grep for the two client names across the whole repository excluding `vendor`, `node_modules` and `.git`, and separately check `.env`, `.env.example`, `.env.ci`, `database/seeders/` and any committed fixture or capture directory. Record the result in the summary either way.

Run `tests/Arch/TestSuiteIntegrityTest.php` and `tests/Arch/ConventionsTest.php` explicitly: the first fails if a test file ends up outside a declared suite, the second enforces the em-dash ban across `app`, `packages`, `resources`, `lang`, `config`, `database`, `routes` and `bootstrap`, which every string this plan touches falls under.

Finish by refreshing the shard balance with `composer test:update-shards` and committing `tests/.pest/shards.json`. A stale file silently drops classes out of CI time-balancing.
  </action>
  <verify>
    <automated>vendor/bin/pint --format agent &amp;&amp; vendor/bin/rector --dry-run &amp;&amp; vendor/bin/phpstan analyse &amp;&amp; composer test:type-coverage &amp;&amp; composer test:lint &amp;&amp; composer test:pest:full</automated>
  </verify>
  <done>Pint, Rector, PHPStan and 100% type coverage all pass. `composer test:pest:full` is green with no skipped or weakened assertions. `tests/.pest/shards.json` is regenerated and committed. The client-data scan result is recorded in the summary.</done>
</task>

<task type="checkpoint:human-verify" gate="blocking-human">
  <name>Task 8: Verify the purged app in a real browser</name>
  <action>
Walk the app in agent-browser before this is reported done. Tests passing is not sufficient for UI work per the project rules.

Confirm, in light and dark, and on a mobile viewport for the panel:
1. `/` redirects to the CRM panel, and no marketing page renders anywhere.
2. `/pricing`, `/press`, `/ai`, `/self-hosted`, `/contact`, `/terms-of-service`, `/privacy-policy`, `/compare/relaticle-vs-twenty` and `/alternatives/attio` all return 404.
3. `/help`, one help article, `/developers` and `/llms.txt` all render with no dead links and no exception.
4. The panel login page renders with the rebranded footer and no dead legal links.
5. The panel sidebar shows the configured brand name and the AGPL source link, for both a workspace admin and a non-admin member.
6. `browser-logs` shows no new JavaScript errors on any of the above.

Report the screenshots and the observed status codes. Do not report this plan complete on test output alone.
  </action>
  <done>The developer has seen the screenshots and confirmed all six checks.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| public internet to web routes | Anonymous requests hit `routes/web.php` and the Documentation package routes. |
| public internet to CRM panel | The panel is network-reachable, which is what triggers AGPL section 13. |
| repository to license obligations | The fork's published source and notices are what downstream recipients rely on. |

## STRIDE Threat Register

| Threat ID | Category | Component | Severity | Disposition | Mitigation Plan |
|-----------|----------|-----------|----------|-------------|-----------------|
| T-cyu-01 | Information disclosure | `routes/web.php` root route vs panel root | high | mitigate | Task 1 registers `/` only when `app_panel_domain` is null, so the panel root is never shadowed by an app route on a domain-mode deployment. |
| T-cyu-02 | Denial of service | `packages/Documentation` callers of removed route names | high | mitigate | Task 1 fixes all five call sites in the same commit as the route removal, so `/help` and `/developers` never enter a 500 state between commits. |
| T-cyu-03 | Spoofing | `/.well-known/security.txt` advertising an upstream mailbox | medium | mitigate | Task 1 makes the contact address configuration driven; Task 5 changes the default so a vulnerability report cannot be misrouted to a third party. |
| T-cyu-04 | Repudiation | Missing AGPL 5(a) modification notice | high | mitigate | Task 6 adds `NOTICE.md` with the upstream attribution and the 2026-09-02 modification date, and verifies `LICENSE` is unmodified. |
| T-cyu-05 | Repudiation | Missing AGPL 13 source offer on a network-reachable panel | high | mitigate | Task 6 adds an always-visible panel source link driven by `relaticle.source_url`. |
| T-cyu-06 | Information disclosure | Public contact form on a private deployment | medium | mitigate | Task 1 and Task 2 remove the route, controller, request and mailable entirely. |
| T-cyu-07 | Information disclosure | Committed client data in env, seeders or fixtures | medium | mitigate | Task 7 re-runs an independent repo-wide scan rather than trusting the pre-plan result, and records the outcome. |
| T-cyu-08 | Information disclosure | `sitemap.xml` and `robots.txt` advertising a private CRM | low | mitigate | Task 4 deletes the generator, the schedule entry, the config and any committed sitemap, and strips the robots directive. |
| T-cyu-SC | Tampering | npm/pip/cargo installs | high | accept | This plan installs no packages. No package legitimacy gate is required. |
</threat_model>

<verification>
Run in this order, matching the project's documented pre-commit sequence:

1. `vendor/bin/pint --format agent` across the whole repo, not `--dirty`, because files committed earlier in this branch stop being covered by `--dirty`.
2. `vendor/bin/rector --dry-run`, then apply if it suggests changes.
3. `vendor/bin/phpstan analyse` with no new ignores added.
4. `composer test:type-coverage` at 100%.
5. `composer test:lint`, which is what CI runs.
6. `composer test:pest:full`, not the TIA-accelerated `test:pest`, because this change deletes routes and views that TIA's cached dependency graph will not have re-traced.
7. `php artisan route:list --except-vendor` and confirm zero marketing URIs.
8. The Task 8 browser walk.
</verification>

<success_criteria>
- No marketing or vitrine route, controller, view, support class or data file remains in the repository.
- Nothing in the app or in any package calls a removed route name.
- `/help`, `/developers`, `/llms.txt`, the panel and the invitation flow all render.
- The panel presents the configured brand name and a visible AGPL source link.
- `NOTICE.md` exists with the upstream attribution and the 2026-09-02 modification date, and `LICENSE` is unmodified.
- The full quality gate is green and `tests/.pest/shards.json` is regenerated.
- The summary records the client-data scan result, and lists the two acknowledged follow-ups: pruning `spatie/laravel-sitemap` from `composer.json`, and rebranding the prose content under `packages/Documentation/resources/content/`.
</success_criteria>

<output>
Create `.planning/quick/260904-cyu-purge-relaticle-marketing-vitrine-surfac/260904-cyu-SUMMARY.md` when done.
</output>

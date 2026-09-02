# Technology Stack

**Analysis Date:** 2026-09-02

## Languages

**Primary:**
- PHP ^8.5 - Backend, all of `app/` and `packages/*/src`
- JavaScript/TypeScript (ESM, `"type": "module"`) - Frontend interactivity, Vite-bundled assets in `resources/js`

**Secondary:**
- Blade templates - Views across `resources/views`, package `resources/views`
- CSS (Tailwind v4) - `resources/css`

## Runtime

**Environment:**
- PHP 8.5, served locally via Laravel Herd (`*.test` domains, no manual serve commands)
- Node.js >=22.12.0 (`package.json` engines) for asset build only

**Package Manager:**
- Composer (PHP) - lockfile `composer.lock` present
- pnpm 11.24.0 (JS, pinned via `packageManager` field) - lockfile `pnpm-lock.yaml` expected

## Frameworks

**Core:**
- Laravel Framework ^13.0 - `laravel/laravel` base project
- Filament ^5.0 - Admin/app panel UI framework (CRM app panel + SystemAdmin panel)
- Livewire ^4.0 - Reactive components underpinning Filament and custom UI

**Testing:**
- Pest ^5.0 with plugins: `pest-plugin-laravel`, `pest-plugin-browser`, `pest-plugin-rector`, `pest-plugin-type-coverage`
- PHPUnit under the hood (via Pest)

**Build/Dev:**
- Vite ^8.2.1 with `laravel-vite-plugin` ^3.1.3 - asset bundling
- Tailwind CSS ^4.3.3 (`@tailwindcss/vite`, `@tailwindcss/forms`, `@tailwindcss/typography`)
- Playwright ^1.62.1 - browser automation (used by Pest browser plugin / agent-browser tooling)
- Laravel Pint ^1.21 - code style (run via `vendor/bin/pint --dirty --format agent`)
- Rector ^2.0 + `driftingly/rector-laravel` ^2.0 - automated refactors
- Larastan (PHPStan) ^3.0 - static analysis (`vendor/bin/phpstan analyse`)
- Laravel Boost ^2.0 - MCP dev server / project-specific tooling and skills

## Key Dependencies

**Critical:**
- `laravel/ai` ~0.11.0 - First-party AI SDK powering the Chat package (OpenAI/Anthropic/Ollama/self-hosted providers)
- `laravel/cashier` ^16.5 - Stripe billing/subscriptions
- `laravel/horizon` ^5.29 - Redis queue dashboard/monitoring
- `laravel/reverb` ^1.10 - WebSocket broadcasting server
- `laravel/fortify` ^1.37 + `laravel/jetstream` ^5.2 - Authentication scaffolding (login, 2FA, teams)
- `laravel/passkeys` ^0.2 (+ `@laravel/passkeys` JS) - WebAuthn passkey login
- `laravel/passport` ^13 - OAuth2 provider (used for MCP directory submissions per `.env.example`)
- `laravel/sanctum` ^4.0 - API token auth
- `laravel/socialite` ^5.16 + `socialiteproviders/microsoft` ^4.10 - Social/OAuth login (Google, Microsoft Entra ID)
- `laravel/mcp` ^0.9.1 - Model Context Protocol server implementation (app's MCP endpoints)
- `laravel/pennant` ^1.23 - Feature flags
- `relaticle/custom-fields` ^3.8.0 - Custom fields engine (wrapped by `App\Models\CustomField*` per project rules)
- `relaticle/activity-log` ^1.2, `relaticle/flowforge` ^4.0, `relaticle/ink` ^2.3 - First-party Relaticle packages
- `spatie/laravel-data` ^4.15 - Structured DTOs (`App\Data`)
- `spatie/laravel-settings` ^3.3 - Settings storage
- `spatie/laravel-medialibrary` ^11.12 - File/media attachments
- `spatie/laravel-query-builder` ^7.0 - API filtering/sorting
- `spatie/laravel-health` ^1.37 + `spatie/cpu-load-health-check` + `spatie/security-advisories-health-check` - Health check endpoints
- `spatie/laravel-honeypot` ^4.7 - Spam protection on public forms
- `sentry/sentry-laravel` ^4.13 - Error tracking

**Infrastructure:**
- `doctrine/dbal` ^4.4 - DB schema introspection/migrations support
- `symfony/http-client` ^8.1, `symfony/postmark-mailer` ^8.1 - HTTP client, transactional mail transport
- `spatie/laravel-mailcoach-mailer` ^1.5 + `spatie/laravel-mailcoach-sdk` ^1.4 - Mailcoach newsletter/mailing integration
- `knuckleswtf/scribe` ^5.8 - API documentation generation
- `scalar/laravel` ^0.2.0 - API reference UI
- `ralphjsmit/laravel-seo` + `ralphjsmit/laravel-filament-seo` + `spatie/schema-org` + `spatie/laravel-sitemap` - SEO/sitemap tooling for public/marketing pages
- `propaganistas/laravel-disposable-email` ^2.5 - Disposable email domain blocking

**Frontend (JS):**
- `alpinejs` ^3.15.12 (+ `@alpinejs/collapse`) - Lightweight reactivity alongside Livewire
- `laravel-echo` ^2.4.0 + `pusher-js` ^8.6.0 - Reverb/WebSocket client
- `@tiptap/*` ^3.29.2 - Rich text editor (used in Chat/notes UI)
- `marked` ^18.0.9, `shiki` ^4.4.2 - Markdown rendering and syntax highlighting (chat message rendering)
- `dompurify` ^3.4.13 - HTML sanitization
- `motion` ^13.0.0 - Animation library

## Configuration

**Environment:**
- `.env` file (git-ignored), bootstrapped from `.env.example` via composer's `post-root-package-install` script
- Config files in `config/` cover: `app`, `auth`, `broadcasting` (Reverb), `cashier` (Stripe), `custom-fields`, `filament`, `horizon`, `mail`, `pennant`, `queue`, `reverb`, `sanctum`, `sentry`, `relaticle` (app-specific feature flags), plus per-package configs (`ai.php`, `boost.php`, `ink.php`, `scribe.php`, etc.)
- Feature flags via env: `RELATICLE_FEATURE_ONBOARD_SEED`, `RELATICLE_FEATURE_SOCIAL_AUTH`, `RELATICLE_FEATURE_DOCUMENTATION`, `RELATICLE_FEATURE_SUPPORT_MENU`, `RELATICLE_FEATURE_BLOG`, `RELATICLE_FEATURE_BILLING`
- Panel routing configurable via domain or path env vars: `APP_PANEL_DOMAIN`/`APP_PANEL_PATH`, `SYSADMIN_DOMAIN`/`SYSADMIN_PATH`, `MCP_DOMAIN`, `API_DOMAIN`

**Build:**
- `vite.config.js` (not read in detail here; referenced via `laravel-vite-plugin`)
- `tsconfig`/JS config not present as a dedicated TypeScript project; JS is plain ESM
- `pint.json`/Pint defaults (Laravel preset) enforced via `vendor/bin/pint`
- `phpstan.neon` - static analysis config with grandfathered ignores (see project rules; SystemAdmin package excluded)

## Platform Requirements

**Development:**
- Laravel Herd (serves `*.test` domains automatically, no manual `serve` needed)
- PostgreSQL 5432 (exclusively; no SQLite/MySQL support)
- Redis (cache store `redis`, `phpredis` client) for cache, and required for Horizon queue processing
- Local queue worker via `bin/workspace-queue.sh` (used in `composer dev` concurrently task)
- Reverb WebSocket server for real-time features (chat, presence)

**Production:**
- PostgreSQL exclusively, UTC-only timestamp columns (`timestamp without time zone`)
- Redis-backed queues via Horizon (`QUEUE_CONNECTION=redis` in production; `.env.example` defaults to `database` for local)
- Stripe (via Cashier) gated behind `RELATICLE_FEATURE_BILLING` flag
- Deployable to Laravel Cloud (per Boost guidelines) or any Laravel-compatible host

---

*Stack analysis: 2026-09-02*

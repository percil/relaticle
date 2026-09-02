# External Integrations

**Analysis Date:** 2026-09-02

## APIs & External Services

**AI Providers (packages/Chat, `laravel/ai`):**
- OpenAI - Chat model provider, default in `config/ai.php`
  - Auth: `OPENAI_API_KEY` env var
  - Also: `OPENAI_APPS_CHALLENGE_TOKEN` for OpenAI Apps integration
- Anthropic (Claude) - Powers Claude models in chat picker and summary model
  - Auth: `ANTHROPIC_API_KEY`
- Ollama (self-hosted, optional) - Local model serving
  - Config: `OLLAMA_BASE_URL`, `OLLAMA_MODEL`
- Ollama Cloud - Managed, subscription-billed Ollama hosting; distinct provider key from self-hosted Ollama
  - Auth: `OLLAMA_CLOUD_API_KEY`
  - Config: `OLLAMA_CLOUD_BASE_URL` (default `https://ollama.com`)
  - Listing: OpenAI-compatible `GET /v1/models`; health probe: `GET /v1/models/{model}`
- Generic OpenAI-compatible self-hosted endpoint (vLLM/LM Studio/LocalAI)
  - Config: `SELF_HOSTED_AI_URL`, `SELF_HOSTED_AI_KEY`, `SELF_HOSTED_AI_MODELS`
  - Models only appear in chat picker when their provider env vars are set

**Payments:**
- Stripe - Billing/subscriptions via `laravel/cashier` ^16.5
  - Auth: `STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`
  - Price IDs: `STRIPE_PRICE_PRO_MONTHLY`, `STRIPE_PRICE_PRO_YEARLY`, `STRIPE_PRICE_CREDITS_1K`, `STRIPE_PRICE_CREDITS_5K`
  - Feature-gated: `RELATICLE_FEATURE_BILLING` (default `false`)
  - `STRIPE_MANAGED_PAYMENTS` toggle
  - Config: `config/cashier.php`

**Email Marketing:**
- Mailcoach - Newsletter/subscriber sync via `spatie/laravel-mailcoach-mailer` + `-sdk`
  - Auth: `MAILCOACH_API_TOKEN`
  - Config: `MAILCOACH_DOMAIN`, `MAILCOACH_API_ENDPOINT`, `MAILCOACH_SUBSCRIBERS_LIST_ID`, `MAILCOACH_ENABLED_SUBSCRIBERS_SYNC`

**Analytics:**
- Fathom Analytics - `FATHOM_ANALYTICS_SITE_ID`

**Error Tracking:**
- Sentry - `sentry/sentry-laravel` ^4.13
  - Auth: `SENTRY_LARAVEL_DSN`, `SENTRY_TRACES_SAMPLE_RATE`
  - Config: `config/sentry.php`

**Health Monitoring:**
- Oh Dear (optional, opt-in) - `HEALTH_CHECKS_ENABLED`, `OH_DEAR_HEALTH_CHECK_SECRET`
  - Config: `config/health.php`, uses `spatie/laravel-health`

**Support/Help Forms:**
- Maxforms-hosted support forms (external form host)
  - `SUPPORT_CONTACT_FORM_URL`, `SUPPORT_BUG_FORM_URL`, `SUPPORT_FEATURE_FORM_URL`
  - Prefilled with `user_email`, `user_name`, `workspace_id`, `source_url`, `app_version`

**Favicon Fetching:**
- `ashallendesign/favicon-fetcher` ^3.8 - fetches favicons for external URLs (likely used for company/contact enrichment)

## Data Storage

**Databases:**
- PostgreSQL exclusively (`DB_CONNECTION=pgsql`)
  - Connection env: `DB_HOST`, `DB_PORT` (5432), `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`
  - Client/ORM: Eloquent (Laravel), with `doctrine/dbal` for schema introspection
  - Timezone: pgsql connection pinned to UTC; all datetime columns are `timestamp without time zone` in UTC

**File Storage:**
- Local filesystem by default (`FILESYSTEM_DISK=local`)
- AWS S3 supported (env present but blank by default): `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`, `AWS_USE_PATH_STYLE_ENDPOINT`
- Media handled via `spatie/laravel-medialibrary` ^11.12

**Caching:**
- Redis (`CACHE_STORE=redis`, `CACHE_PREFIX=relaticle_cache`)
  - Client: `phpredis` (`REDIS_CLIENT=phpredis`)
  - Connection: `REDIS_HOST`, `REDIS_PORT` (6379), `REDIS_USERNAME`, `REDIS_PASSWORD`
  - Also used by Horizon for queue backend in production

**Queues:**
- Database queue by default locally (`QUEUE_CONNECTION=database`)
- Redis in production, managed via Laravel Horizon (`laravel/horizon` ^5.29)
- Named queues: `default` (general jobs), `imports` (CSV import jobs, dedicated workers with higher timeout/memory)
- Horizon dashboard access restricted via `HORIZON_ADMIN_EMAILS` (comma-separated emails)

## Authentication & Identity

**Auth Provider:**
- Custom, built on `laravel/fortify` + `laravel/jetstream` (teams-based auth)
  - 2FA/TOTP supported via Fortify
  - Passkeys (WebAuthn) via `laravel/passkeys` + `@laravel/passkeys` JS SDK
    - `PASSKEYS_USER_HANDLE_SECRET` env var stabilizes user handles across `APP_KEY` rotation

**Social/OAuth Login:**
- Google OAuth via `laravel/socialite`
  - `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`
- Microsoft (Entra ID) via `socialiteproviders/microsoft`
  - `MICROSOFT_CLIENT_ID`, `MICROSOFT_CLIENT_SECRET`
  - Redirect URI convention: `https://<app-host>/auth/callback/microsoft`
- Feature-gated: `RELATICLE_FEATURE_SOCIAL_AUTH`

**API Auth:**
- `laravel/sanctum` ^4.0 - API token auth for first-party API consumers
- `laravel/passport` ^13 - OAuth2 provider, used specifically for MCP directory submissions
  - Keys: `PASSPORT_PRIVATE_KEY`, `PASSPORT_PUBLIC_KEY` (or generated via `php artisan passport:keys`)

## Monitoring & Observability

**Error Tracking:**
- Sentry (see above)

**Logs:**
- Laravel default logging (`LOG_CHANNEL=stack`, `LOG_STACK=single`, `LOG_LEVEL=debug` in dev)
- `laravel/pail` (dev dependency) for tailing logs during local development (`php artisan pail`)

**Queue Monitoring:**
- Laravel Horizon dashboard (Redis-backed queue metrics)

**Health Checks:**
- `spatie/laravel-health` with `spatie/cpu-load-health-check` and `spatie/security-advisories-health-check`

## CI/CD & Deployment

**Hosting:**
- Laravel-compatible host; Laravel Cloud referenced in Boost guidelines as fast-path option
- Local dev served by Laravel Herd (`*.test` domains)

**CI Pipeline:**
- GitHub Actions (referenced in recent commit history: "Unify the CI and release pipelines behind one reusable workflow")
- Composer scripts drive CI: `test:ci` runs lint, refactor check, type coverage, static analysis, and sharded Pest run (`test:pest:ci`)

## Environment Configuration

**Required env vars (core):**
- `APP_KEY`, `APP_URL`, `DB_*` (pgsql), `SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION`, `REDIS_*`

**Required for specific features:**
- Billing: `STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET` + `RELATICLE_FEATURE_BILLING=true`
- AI chat: at least one of `OPENAI_API_KEY`, `ANTHROPIC_API_KEY`, or self-hosted/Ollama config
- Real-time: `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME` (+ matching `VITE_REVERB_*` for the Echo client)
- Social login: Google/Microsoft OAuth client credentials
- Passport (MCP directory): `PASSPORT_PRIVATE_KEY`/`PASSPORT_PUBLIC_KEY` or generated keys in `storage/`

**Secrets location:**
- `.env` (git-ignored, never committed)
- Passport keys land in `storage/` (gitignored) unless supplied via env

## Webhooks & Callbacks

**Incoming:**
- Stripe billing webhooks (Cashier), verified with `STRIPE_WEBHOOK_SECRET`
- OAuth callbacks: `/auth/callback/microsoft` (Microsoft), Google's Socialite callback route, Passkey WebAuthn ceremonies

**Outgoing:**
- Mailcoach subscriber sync (when `MAILCOACH_ENABLED_SUBSCRIBERS_SYNC=true`)
- Sentry error/event reporting
- Fathom Analytics page tracking (client-side)

---

*Integration audit: 2026-09-02*

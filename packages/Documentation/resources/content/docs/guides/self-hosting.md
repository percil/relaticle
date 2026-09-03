---
title: Self-Hosting Guide
description: Deploy Relaticle with Docker or manually.
order: 1
updated: "2026-09-03"
---

Deploy Relaticle on your own infrastructure with Docker or manually.

---

## Quick Start

Get Relaticle running in 5 steps:

1. Download the compose file:

```bash
curl -o compose.yml https://raw.githubusercontent.com/Relaticle/relaticle/main/compose.yml
```

2. Generate an application key and three Reverb secrets:

```bash
echo "APP_KEY=base64:$(openssl rand -base64 32)"
echo "REVERB_APP_ID=$(openssl rand -hex 16)"
echo "REVERB_APP_KEY=$(openssl rand -hex 16)"
echo "REVERB_APP_SECRET=$(openssl rand -hex 16)"
```

3. Create a `.env` file with your settings:

```bash
APP_KEY=base64:your-generated-key-here
DB_PASSWORD=your-secure-database-password
APP_URL=https://crm.example.com
REVERB_APP_ID=your-generated-reverb-id
REVERB_APP_KEY=your-generated-reverb-key
REVERB_APP_SECRET=your-generated-reverb-secret
```

4. Start the containers:

```bash
docker compose up -d
```

5. Create your admin account:

```bash
docker compose exec app php artisan make:filament-user
```

Your CRM is now available at `{APP_URL}/app`.

---

## Requirements

| Resource | Minimum | Recommended |
|----------|---------|-------------|
| RAM | 2 GB | 4 GB |
| CPU | 1 core | 2 cores |
| Disk | 10 GB | 20 GB+ |
| Docker | 20.10+ | Latest |
| Docker Compose | v2.0+ | Latest |

---

## Environment Variables

### Required

These must be set or the containers will refuse to start.

| Variable | Description |
|----------|-------------|
| `APP_KEY` | Encryption key. Generate with `openssl rand -base64 32`, then prefix with `base64:`. |
| `DB_PASSWORD` | PostgreSQL password. Use a strong random value. |
| `REVERB_APP_ID` | Reverb application identifier, used to namespace WebSocket connections. Generate with `openssl rand -hex 16`. Use a different value for every install. |
| `REVERB_APP_KEY` | Reverb's public client key. Generate with `openssl rand -hex 16`. This value is sent to every browser and appears in page source by design, the same way a Pusher key is public. |
| `REVERB_APP_SECRET` | Reverb's private secret, used to authenticate server-to-server broadcasts. Generate with `openssl rand -base64 32`. Unlike `REVERB_APP_KEY`, this value must never be exposed; keep it out of client code and public repositories. |

### Application

| Variable | Default | Description |
|----------|---------|-------------|
| `APP_NAME` | `Relaticle` | Displayed in the browser tab and emails. |
| `APP_ENV` | `production` | Set to `production` for self-hosting. |
| `APP_DEBUG` | `false` | Set to `true` only for debugging. Never in production. |
| `APP_TIMEZONE` | `UTC` | Application timezone. |
| `APP_URL` | `http://localhost` | Full URL where Relaticle is accessible. Include the scheme. |
| `APP_PORT` | `80` | Host port the app container binds to. |
| `APP_PANEL_DOMAIN` | (empty) | Set for subdomain routing (e.g., `app.example.com`). Leave empty for path mode (`/app`). |
| `REVERB_HOST` | `localhost` | The address the browser opens a WebSocket to for real-time chat streaming. Set it to your public hostname once a reverse proxy is in front, matching how you route the `reverb` container in the [Reverse Proxy](#reverse-proxy-and-ssl) section below. |
| `REVERB_PORT` | `8080` | The port the browser connects to, and the host port the `reverb` container publishes. Set it to whatever port your reverse proxy terminates the WebSocket on. |
| `REVERB_SCHEME` | `http` | `http` or `https`. Set to `https` once a reverse proxy is terminating TLS in front of the `reverb` container. |
| `PASSKEYS_USER_HANDLE_SECRET` | `APP_KEY` | Derives the opaque WebAuthn user handle stored on each authenticator. Set it to its own random value (`openssl rand -base64 32`) before anyone registers a passkey, so rotating `APP_KEY` later does not change every user's handle. |
| `REQUIRE_EMAIL_VERIFICATION` | `true` | When `false`, users sign in without verifying their email. That is useful for self-hosters who haven't configured SMTP yet. The admin you create via `make:filament-user` is auto-verified regardless, so the default of `true` is safe for fresh Docker installs. Only set to `false` if your panel is on a private network: with verification disabled, anyone who can reach the sign-in page can create a working account. |
| `LOG_CHANNEL` | `stderr` | Where logs go. `stderr` is recommended for Docker. |
| `LOG_LEVEL` | `warning` | Minimum log level. Use `debug` for troubleshooting. |

**Note**: The browser reads its `REVERB_APP_KEY`, `REVERB_HOST`, `REVERB_PORT` and `REVERB_SCHEME` values from the server at request time, not from values baked into the image at build time. The same published image works for any domain without rebuilding. Live chat streaming needs an image built at or after this change: if you pin an older tag, every container reports healthy but the browser never opens a socket, because the served page does not carry these values yet.

### Mail

| Variable | Default | Description |
|----------|---------|-------------|
| `MAIL_MAILER` | `log` | Mail driver: `smtp`, `ses`, `mailgun`, `postmark`, or `log`. |
| `MAIL_HOST` | (empty) | SMTP host (e.g., `smtp.mailgun.org`). |
| `MAIL_PORT` | `587` | SMTP port. |
| `MAIL_USERNAME` | (empty) | SMTP username. |
| `MAIL_PASSWORD` | (empty) | SMTP password. |
| `MAIL_ENCRYPTION` | `tls` | `tls` or `ssl`. |
| `MAIL_FROM_ADDRESS` | `hello@example.com` | Sender email address. |
| `MAIL_FROM_NAME` | `Relaticle` | Sender display name. |

### Database and Redis

These are pre-configured in `compose.yml` and generally don't need changing.

| Variable | Default | Description |
|----------|---------|-------------|
| `DB_DATABASE` | `relaticle` | PostgreSQL database name. |
| `DB_USERNAME` | `relaticle` | PostgreSQL username. |
| `REDIS_PASSWORD` | `null` | Redis password. Leave as `null` for no auth. |

### Optional

| Variable | Description |
|----------|-------------|
| `GOOGLE_CLIENT_ID` | Google OAuth client ID for social login. |
| `GOOGLE_CLIENT_SECRET` | Google OAuth client secret. |
| `GOOGLE_REDIRECT_URI` | Absolute callback URL (`https://your-host/auth/callback/google`), or a relative path (`/auth/callback/google`) to derive the host from the request. |
| `MICROSOFT_CLIENT_ID` | Microsoft Entra ID client id. The Microsoft button appears only when this is set; register `https://your-host/auth/callback/microsoft` as the redirect URI. |
| `MICROSOFT_CLIENT_SECRET` | Microsoft Entra ID client secret. |
| `SENTRY_LARAVEL_DSN` | Sentry DSN for error tracking. |
| `FATHOM_ANALYTICS_SITE_ID` | Fathom Analytics site ID. |

### AI Assistant

The AI assistant works with cloud providers, a self-hosted server, or both. Models appear in the chat model picker only when their provider is configured. With none configured, the assistant cannot answer.

There are two ways to run a self-hosted model, and you can use either or both:

- **[Ollama](https://ollama.com)** (`OLLAMA_MODEL`): the simplest path, with the best tool-calling support.
- **Any OpenAI-compatible endpoint** (`SELF_HOSTED_AI_*`): point at [vLLM](https://docs.vllm.ai) (best for many concurrent users), LM Studio, LocalAI, or a hosted gateway. Exposes multiple models at once.

| Variable | Default | Description |
|----------|---------|-------------|
| `ANTHROPIC_API_KEY` | (empty) | Enables the Claude models in the chat picker. |
| `OPENAI_API_KEY` | (empty) | Enables the GPT models in the chat picker. |
| `OLLAMA_BASE_URL` | `http://localhost:11434` | URL of your Ollama server. From inside a Docker container, `localhost` is the container itself. Use `http://host.docker.internal:11434` (or your host's LAN IP) to reach Ollama running on the host. |
| `OLLAMA_MODEL` | (empty) | Ollama model tag (e.g. `qwen3:14b`). Setting this adds the model to the chat picker. |
| `SELF_HOSTED_AI_URL` | (empty) | Base URL of an OpenAI-compatible endpoint (e.g. `http://localhost:8000/v1`). |
| `SELF_HOSTED_AI_KEY` | (empty) | API key for that endpoint, if it requires one (blank for most local servers). |
| `SELF_HOSTED_AI_MODELS` | (empty) | Comma-separated model tags to expose (e.g. `qwen3:14b,llama3.1:70b`). Each becomes a picker entry. |

**Choosing a model.** The assistant calls over 30 CRM tools (search, create, update, delete). Only models with strong tool-calling support work reliably. `qwen3` and `llama3.1:70b`-class models are good starting points. Small models tend to claim an action succeeded without actually invoking the tool. Verify a model before trusting it with `php artisan chat:models --probe=<id>`, which lists the registry and smoke-tests tool-calling against its endpoint (self-hosted models only; cloud models run through the vendor SDK and are declined).

**Known limitations with self-hosted models:**

- Cloud providers enforce one-write-proposal-at-a-time at the API level; self-hosted models have no equivalent switch, so this is enforced by prompt instructions only. Every write still requires your explicit approval before anything is saved.
- Each response must complete within 120 seconds. If a slow model exceeds it, the turn stops cleanly. Your message stays in the conversation with a "didn't respond within the time limit" note and nothing is silently lost, so you can just retry. To avoid the limit, use a smaller/faster model or a GPU; "thinking" models like `qwen3` reason before answering and reach it sooner, so a non-thinking model or a shorter prompt helps for interactive use.

### Feature Flags

Toggle features on or off. All are enabled by default. Useful for forks and custom deployments that want to disable specific functionality without modifying code.

| Variable | Default | Description |
|----------|---------|-------------|
| `RELATICLE_FEATURE_ONBOARD_SEED` | `true` | Seed demo data (sample companies, contacts, tasks) when a new team is created. Set to `false` to start with an empty workspace. |
| `RELATICLE_FEATURE_SOCIAL_AUTH` | `true` | Enable Google and Microsoft social login. Set to `false` to use only email, password, and passkey sign-in. |
| `RELATICLE_FEATURE_DOCUMENTATION` | `true` | Enable the `/developers` and `/help` documentation module. Set to `false` to remove documentation routes and navigation links. |

---

## Architecture

The Docker setup runs 6 containers:

| Container | Image | Purpose |
|-----------|-------|---------|
| **app** | `ghcr.io/relaticle/relaticle:latest` | Web server (nginx + PHP-FPM) on port 8080. Runs migrations automatically on startup. |
| **horizon** | `ghcr.io/relaticle/relaticle:latest` | Queue worker powered by Laravel Horizon. Processes background jobs. |
| **scheduler** | `ghcr.io/relaticle/relaticle:latest` | Runs `schedule:work` for recurring tasks (e.g., cleanup, notifications). |
| **reverb** | `ghcr.io/relaticle/relaticle:latest` | WebSocket server powered by Laravel Reverb. Carries real-time chat message streaming to the browser. |
| **postgres** | `postgres:17-alpine` | PostgreSQL 17 database. |
| **redis** | `redis:7-alpine` | Cache, sessions, and queue backend. Runs with append-only persistence. |

### Volumes

| Volume | Purpose |
|--------|---------|
| `postgres` | Database files. Back this up. |
| `redis` | Redis persistence data. |
| `storage` | Uploaded files and application storage. Back this up. |

### Networking

The app container listens on port 8080 internally and maps to `APP_PORT` (default 80) on the host. The reverb container also listens on port 8080 internally and maps to `REVERB_PORT` (default 8080) on the host, so the browser has a socket to connect to. Every other container, postgres, redis, horizon and scheduler, communicates only through Docker's internal network and publishes nothing to the host.

---

## Creating Your Admin Account

After starting the containers, create your first admin user:

```bash
docker compose exec app php artisan make:filament-user
```

When prompted to pick a panel, choose `app`, then enter a name, email, and password. Once created, access the CRM panel at `{APP_URL}/app`. (To create the instance-wide system administrator instead, see the next section.)

**Note**: By default the CRM panel is available at the `/app` path. To use subdomain routing instead (e.g., `app.example.com`), set the `APP_PANEL_DOMAIN` environment variable.

### System Administrator Account

The `sysadmin` panel at `{APP_URL}/sysadmin` is a separate, instance-wide admin surface for managing every team and user on your installation. To create a system administrator, you can either pick `sysadmin` from the `make:filament-user` panel prompt, or use the dedicated command:

```bash
docker compose exec app php artisan sysadmin:create
```

---

## Reverse Proxy and SSL

The app container serves HTTP on port 8080 internally. Place a reverse proxy in front to handle SSL termination and route traffic to the container.

SSL is not optional if you want passkeys: browsers run WebAuthn over HTTPS only, and a passkey is bound to the host it was registered on. That host is `APP_PANEL_DOMAIN` when subdomain routing is on, and the `APP_URL` host otherwise, so serve the panel from one hostname and keep it stable.

### Nginx

```nginx
server {
    listen 443 ssl http2;
    server_name crm.example.com;

    ssl_certificate /etc/letsencrypt/live/crm.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/crm.example.com/privkey.pem;

    location / {
        proxy_pass http://127.0.0.1:80;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}

server {
    listen 80;
    server_name crm.example.com;
    return 301 https://$server_name$request_uri;
}
```

The reverb container needs its own server block, proxying to its published host port (`REVERB_PORT`, default 8080) with the `Upgrade` and `Connection` headers set and response buffering off, both required for a WebSocket handshake to survive the proxy:

```nginx
server {
    listen 443 ssl http2;
    server_name ws.crm.example.com;

    ssl_certificate /etc/letsencrypt/live/ws.crm.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/ws.crm.example.com/privkey.pem;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_read_timeout 86400;
        proxy_buffering off;
    }
}
```

Set `REVERB_HOST=ws.crm.example.com` and `REVERB_SCHEME=https` so the browser connects through this block instead of the reverb container's plain HTTP port directly.

### Caddy

```
crm.example.com {
    reverse_proxy 127.0.0.1:80
}

ws.crm.example.com {
    reverse_proxy 127.0.0.1:8080
}
```

Caddy handles SSL certificates automatically via Let's Encrypt, and detects a WebSocket `Upgrade` header on its own, so the reverb block needs no extra headers. Set `REVERB_HOST=ws.crm.example.com` and `REVERB_SCHEME=https` to match.

### Traefik

Add these labels to the `app` service in your `compose.yml`:

```yaml
labels:
  - "traefik.enable=true"
  - "traefik.http.routers.relaticle.rule=Host(`crm.example.com`)"
  - "traefik.http.routers.relaticle.entrypoints=websecure"
  - "traefik.http.routers.relaticle.tls.certresolver=letsencrypt"
  - "traefik.http.services.relaticle.loadbalancer.server.port=8080"
```

Add these labels to the `reverb` service in your `compose.yml`, on a dedicated hostname since it is a separate container:

```yaml
labels:
  - "traefik.enable=true"
  - "traefik.http.routers.relaticle-reverb.rule=Host(`ws.crm.example.com`)"
  - "traefik.http.routers.relaticle-reverb.entrypoints=websecure"
  - "traefik.http.routers.relaticle-reverb.tls.certresolver=letsencrypt"
  - "traefik.http.services.relaticle-reverb.loadbalancer.server.port=8080"
```

Traefik forwards WebSocket upgrades transparently once the router matches, so no extra label is needed. Set `REVERB_HOST=ws.crm.example.com` and `REVERB_SCHEME=https` to match this router.

**Note**: When using a reverse proxy, set `APP_URL` to your public HTTPS URL (e.g., `https://crm.example.com`). The app trusts `X-Forwarded-*` headers from RFC1918 private networks, loopback, and IPv6 ULA/link-local, which covers Coolify/Dokploy/Traefik on a Docker network and reverse proxies on the host. Headers from public IPs are rejected, preventing spoofing.

---

## Deploying on Dokploy

[Dokploy](https://dokploy.com/) is an open-source deployment platform. Here's how to deploy Relaticle on it.

### 1. Create a Project

In the Dokploy dashboard, click **Create Project** and give it a name (e.g., "Relaticle").

### 2. Add a Compose Service

1. Inside your project, click **Create Service** and select **Compose**
2. Set the source to **Raw** and paste the contents of the [compose.yml](https://raw.githubusercontent.com/Relaticle/relaticle/main/compose.yml)
3. Click **Create**

### 3. Set Environment Variables

In the service's **Environment** tab, add the required variables:

```bash
APP_KEY=base64:your-generated-key-here
DB_PASSWORD=your-secure-database-password
APP_URL=https://crm.yourdomain.com
APP_PORT=8080
REVERB_APP_ID=your-generated-reverb-id
REVERB_APP_KEY=your-generated-reverb-key
REVERB_APP_SECRET=your-generated-reverb-secret
```

Generate your `APP_KEY` with:

```bash
echo "base64:$(openssl rand -base64 32)"
```

Generate the three `REVERB_*` values with `openssl rand -hex 16`, a different value for each and for every install.

**Note**: Set `APP_PORT=8080` so the container maps port 8080:8080, avoiding conflicts with Dokploy's own port 80.

### 4. Deploy

Click **Deploy**. Dokploy will pull the images and start all 6 containers. Wait for health checks to pass.

### 5. Configure Domain

1. Go to the **Domains** tab of the `app` service
2. Add your domain (e.g., `crm.yourdomain.com`)
3. Set the container port to `8080`
4. Enable HTTPS (Dokploy handles Let's Encrypt automatically)

### 6. Create Admin User

Open the Dokploy terminal for the `app` container and run:

```bash
php artisan make:filament-user
```

Your Relaticle instance is now live at `https://crm.yourdomain.com/app`.

---

## Deploying on Coolify

[Coolify](https://coolify.io/) is an open-source, self-hostable platform for deploying applications.

### 1. Create a New Project

In the Coolify dashboard, click **New Project** and give it a name.

### 2. Add a Docker Compose Resource

1. Click **Add Resource** in your project
2. Select **Docker Compose**
3. Choose **Empty** as the source, then paste the contents of the [compose.yml](https://raw.githubusercontent.com/Relaticle/relaticle/main/compose.yml)

### 3. Configure Environment Variables

In the resource's **Environment Variables** section, add:

```bash
APP_KEY=base64:your-generated-key-here
DB_PASSWORD=your-secure-database-password
APP_URL=https://crm.yourdomain.com
REVERB_APP_ID=your-generated-reverb-id
REVERB_APP_KEY=your-generated-reverb-key
REVERB_APP_SECRET=your-generated-reverb-secret
```

### 4. Set Up Domain

1. Go to the app service's **Settings**
2. Set your domain (e.g., `crm.yourdomain.com`)
3. Coolify will automatically provision an SSL certificate

### 5. Deploy

Click **Deploy**. Coolify will pull the images, create containers, and start the services.

### 6. Create Admin User

Use Coolify's **Terminal** feature to run in the `app` container:

```bash
php artisan make:filament-user
```

Access your CRM at `https://crm.yourdomain.com/app`.

---

## Upgrading

### 1. Back Up Your Data

Always back up before upgrading. See the Backup and Restore section below.

### 2. Pull the Latest Images

```bash
docker compose pull
```

### 3. Restart the Containers

```bash
docker compose up -d
```

Database migrations run automatically on startup. The app container will apply any pending migrations before serving traffic.

**Note**: Check the [release notes](https://github.com/Relaticle/relaticle/releases) before upgrading. Breaking changes will be documented there.

---

## Backup and Restore

### Database Backup

```bash
docker compose exec postgres pg_dump -U relaticle relaticle > backup-$(date +%Y%m%d).sql
```

### Database Restore

```bash
docker compose exec -T postgres psql -U relaticle relaticle < backup-20260320.sql
```

### Storage Backup

Back up the storage volume which contains uploaded files:

```bash
docker compose cp app:/var/www/html/storage/app ./storage-backup
```

### Automated Backups

Add a cron job to back up daily:

```bash
0 3 * * * cd /path/to/relaticle && docker compose exec -T postgres pg_dump -U relaticle relaticle | gzip > /backups/relaticle-$(date +\%Y\%m\%d).sql.gz
```

---

## Manual Deployment

If you prefer not to use Docker, you can deploy Relaticle directly on a server.

### Requirements

- PHP 8.5+ with extensions: pdo_pgsql, gd, bcmath, mbstring, xml, redis
- PostgreSQL 17+
- Redis 7+
- Node.js 22+
- Composer 2+
- Nginx or Apache
- Supervisor (for queue workers)

### Installation

1. Clone the repository:

```bash
git clone https://github.com/Relaticle/relaticle.git /var/www/relaticle
cd /var/www/relaticle
```

2. Install dependencies:

```bash
composer install --no-dev --optimize-autoloader
pnpm install --frozen-lockfile && pnpm run build
```

3. Configure the environment:

```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env` with your database credentials, mail settings, and `APP_URL`.

4. Set up the database:

```bash
php artisan migrate --force
php artisan storage:link
```

5. Set permissions:

```bash
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
```

6. Create your admin user:

```bash
php artisan make:filament-user
```

### Nginx Configuration

```nginx
server {
    listen 80;
    server_name crm.example.com;
    root /var/www/relaticle/public;

    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.5-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

### Supervisor for Horizon

Create `/etc/supervisor/conf.d/relaticle-horizon.conf`:

```ini
[program:relaticle-horizon]
process_name=%(program_name)s
command=php /var/www/relaticle/artisan horizon
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/relaticle/storage/logs/horizon.log
stopwaitsecs=3600
```

Then start it:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start relaticle-horizon
```

### Scheduler Cron

Add to `www-data`'s crontab:

```bash
* * * * * cd /var/www/relaticle && php artisan schedule:run >> /dev/null 2>&1
```

---

## Troubleshooting

### "APP_KEY is required" error on startup

The `APP_KEY` environment variable is not set. Generate one:

```bash
echo "base64:$(openssl rand -base64 32)"
```

Add it to your `.env` file and restart:

```bash
docker compose up -d
```

### Container restart loops

Check the logs:

```bash
docker compose logs app --tail 50
```

Common causes:
- Missing required environment variables (`APP_KEY`, `DB_PASSWORD`)
- PostgreSQL not ready yet (the app waits for a health check, but custom configs may skip this)
- Insufficient memory (need at least 2 GB)

### 500 errors after deployment

1. Check `APP_DEBUG=true` temporarily to see the full error
2. View logs: `docker compose logs app --tail 100`
3. Ensure `APP_URL` matches your actual domain (including scheme)
4. Run `docker compose exec app php artisan optimize:clear` to clear all caches

### Email not sending

1. Verify `MAIL_MAILER` is set to `smtp` (not `log`)
2. Check your SMTP credentials are correct
3. Test with: `docker compose exec app php artisan tinker --execute "Mail::raw('Test', fn(\$m) => \$m->to('you@example.com')->subject('Test'))"`
4. Check logs: `docker compose logs app | grep -i mail`

### Horizon not processing jobs

1. Check Horizon status: `docker compose exec app php artisan horizon:status`
2. Verify the horizon container is running: `docker compose ps`
3. Check horizon logs: `docker compose logs horizon --tail 50`
4. Restart Horizon: `docker compose restart horizon`

### Permission errors on storage

```bash
docker compose exec app chown -R www-data:www-data /var/www/html/storage
docker compose exec app chmod -R 775 /var/www/html/storage
```

### Database connection refused

1. Verify PostgreSQL is running: `docker compose ps postgres`
2. Check PostgreSQL logs: `docker compose logs postgres --tail 20`
3. Ensure `DB_PASSWORD` matches between the app and postgres containers (both read from the same `.env` variable by default)

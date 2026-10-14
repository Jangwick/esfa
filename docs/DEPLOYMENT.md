# Deployment

SFA deploys like PDMS: **one Vercel project, two services** (see `vercel.json`), region `sin1`, and its **own**
Supabase PostgreSQL project. This document prepares a deployment; it does not record one.

| Path | Service |
|---|---|
| `/api/*` | `backend` — FrankenPHP 1 / PHP 8.4 container from `backend/Dockerfile.vercel` |
| `/*` | `frontend` — Vite build (`frontend/dist`), unknown paths fall back to `index.html` |
| Cron `0 16 * * *` (00:00 Manila) | `GET /api/v1/cron/run` |

## 1. One-time setup

1. **Database.** Create a Supabase project for SFA (do not share PDMS's pooler). Use the **session pooler** (IPv4)
   connection values. The free pooler allows about 15 connections shared by everything, so never set `DB_PERSISTENT`.
2. **Vercel project.** Import the repository and select **Services** as the Framework Preset. Keep Root Directory at
   the repository root and leave project-wide build/output overrides unset. Vercel reads `vercel.json` and creates
   the two services. See [Services](https://vercel.com/docs/services).
3. **Environment variables** (Vercel dashboard → Settings → Environment Variables, Production):

   | Variable | Value |
   |---|---|
   | `APP_KEY` | Preserve the existing local key when importing data; generate a new key only for an empty installation |
   | `APP_URL` | `https://<your-domain>` |
   | `APP_ENV` / `APP_DEBUG` | Set by the image: `production` / `false`. Do not override. |
   | `DB_CONNECTION` | `pgsql` |
   | `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Supabase session pooler values |
   | `DB_SSLMODE` | `require` |
   | `CACHE_STORE` | `database` — shared login throttles and cache invalidation across instances |
   | `SESSION_DRIVER` | `array` — the API authenticates with Sanctum bearer tokens |
   | `CRON_SECRET` | A long random string; Vercel Cron sends it as `Authorization: Bearer …` |
   | `SFA_ALLOWED_ORIGINS` | `https://<your-domain>` (the SPA origin; comma separated if several) |
   | `SFA_TIMEZONE` | `Asia/Manila` |
   | `SFA_LOGIN_OTP` | `off`, `bound` or `all` |
   | `SFA_SANDBOX_GATEWAY` | `true` until a verified real gateway driver exists |
   | `SFA_SANDBOX_WEBHOOK_SECRET` | Random string if sandbox webhooks are used; blank rejects them |
   | `SFA_WEBHOOK_ALLOW_LOCALHOST` | `false` |
   | `SFA_SIMULATOR` | `false` |
   | `SFA_SCHEDULER_ENABLED` | `false` during transfer/verification (pauses cron, post-request, artisan and simulator runs); enable after reviewing imported due work |
   | `SFA_COMMIT` | Optional; defaults to Vercel's `VERCEL_GIT_COMMIT_SHA` |
   | `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` | Gmail SMTP app password, only if sign-in codes are on |
   | `VITE_API_URL` | `/api/v1` (frontend service) |

   Keep **Automatically expose System Environment Variables** enabled: the backend trusts Vercel's forwarded
   HTTPS/client-IP headers only when `VERCEL=1`, and only from the loopback proxy inside its container.

   Secrets live only in the dashboard; `.env` files are excluded by `.vercelignore` and `.dockerignore`.
4. **Schema.** Vercel cannot run `php artisan`. From a local machine with production values in a temporary
   `backend/.env.production` (never committed):

   ```bash
   php artisan migrate --env=production --force
   php artisan db:seed --env=production --force
   ```

   The production seed creates only roles, permissions and settings. Create the first Admin with
   `php artisan tinker --env=production` (`User::create([...])->assignRole('Admin')`) and sign in to create the rest
   from **Users & Roles**.

### Import an existing local database

For an existing installation, migrate the empty PostgreSQL schema but **do not seed it**. Configure the production
connection in the current shell or a temporary excluded environment file. Preserve the local `APP_KEY` in Vercel,
so seller payout details and courier/webhook secrets still decrypt. A Supabase personal access token manages the
project; it is not `DB_PASSWORD`. Use a dedicated PostgreSQL login through the session pooler on port `5432`.

Keep a consistent SQLite backup and pass that fixed snapshot to the transfer command:

```bash
cd backend
php artisan sfa:import-sqlite database/pre-supabase-20261011.sqlite --env=production --dry-run
php artisan sfa:import-sqlite database/pre-supabase-20261011.sqlite --env=production --report=../docs/data-migration-report.json
php artisan sfa:import-sqlite database/pre-supabase-20261011.sqlite --env=production --verify-only
```

The command opens the source read-only, makes a consistent temporary snapshot, and refuses a destination containing
application records. It requires matching tables, columns and migration names, with row-level security enabled on
every table. It preserves record IDs, opaque encrypted values, base64 file contents and sequence high-water marks
(including deleted IDs). PostgreSQL retains its own migration batch metadata. The import verifies every field using
type-normalized SHA-256 digests, validates foreign keys, restores their original deferral settings, and commits only
after verification. The report contains counts and hashes, never record contents or credentials.

Keep scheduled work paused until verification finishes. Review imported pending payments, subscriptions, settlements
and webhook destinations before enabling it: copying data does not convert localhost integrations into online ones.
After importing, changing production data makes `--verify-only` against the old snapshot unsuitable for later checks.

## 2. Deploy

Push `main` (production) or run `vercel --prod` from a clean checkout. Verify:

```bash
curl https://<your-domain>/api/v1/health            # {"status":"ok","commit":"…","database":"connected"}
curl https://<your-domain>/api/v1/openapi.yaml      # the API contract
curl -H "Authorization: Bearer $CRON_SECRET" https://<your-domain>/api/v1/cron/run   # {"ran":true,…,"failed_jobs":[]}
```

Then sign in, open each sidebar module, and check that `APP_DEBUG` is off (an unknown API path returns a plain
`{"message":…}` with no stack trace).

## 3. Scheduled work

The Hobby plan allows one cron run per day, so SFA also runs due work after authenticated API requests (after the
response, at most every five minutes, behind a database lock). Both paths are idempotent. To force a run:
`curl -H "Authorization: Bearer $CRON_SECRET" https://<your-domain>/api/v1/cron/run`.

`SFA_SCHEDULER_ENABLED=false` pauses every trigger (Vercel Cron, the `pg_cron` job below, post-request runs, `artisan
sfa:run-scheduled` and the simulator). A paused cron call still checks the secret and answers
`200 {"ran":false,"paused":true,…}` without doing anything.

**Every five minutes without staff online (optional, free).** Supabase `pg_cron` + `pg_net` can call the cron route.
Run this once in the Supabase SQL editor (Database → Extensions: enable `pg_cron` and `pg_net` first). The secret is
kept in Supabase Vault, never in the job text:

```sql
select vault.create_secret('<the CRON_SECRET value>', 'sfa_cron_secret');

select cron.schedule('sfa-run-scheduled', '*/5 * * * *', $$
  select net.http_get(
    url := 'https://<your-domain>/api/v1/cron/run',
    headers := jsonb_build_object('Authorization', 'Bearer ' ||
      (select decrypted_secret from vault.decrypted_secrets where name = 'sfa_cron_secret')),
    timeout_milliseconds := 60000
  );
$$);
```

Check it with `select * from cron.job_run_details order by start_time desc limit 5;` and
`select status_code, content from net._http_response order by created desc limit 5;`. After rotating `CRON_SECRET`,
update the Vault entry (`select vault.update_secret(id, '<new value>') from vault.secrets where name =
'sfa_cron_secret';`). Remove the job with `select cron.unschedule('sfa-run-scheduled');`. Overlapping calls are safe:
the database lock lets one run at a time and the others return `ran: false`.

## 4. Limits to respect

- Request and response bodies up to 4.5 MB (attachments are capped at 4 MB; PHP allows 5M/6M).
- No persistent disk: files are stored in the database, caches and compiled views in `/tmp`.
- The container registry keeps 50 images per repository; delete old images when a deploy fails with
  "repository has reached the maximum allowed number of images".

## 5. Recovery

| Situation | Action |
|---|---|
| A deploy breaks the site | Vercel → Deployments → promote the previous deployment (instant rollback). |
| A migration must be undone | `php artisan migrate:rollback --env=production --step=1` from a local machine, after a backup. |
| Data loss or corruption | Restore from Supabase backups (Database → Backups) to a point in time; re-run the scheduler afterwards. |
| `APP_KEY` leaked | Set the new key and add the old one to `APP_PREVIOUS_KEYS` so encrypted webhook and courier secrets still decrypt; then rotate those secrets. |
| An integration token leaked | Integrations → Clients → revoke the token (or deactivate the client), issue a new one. |
| A webhook secret leaked | Integrations → Webhooks → edit → rotate secret. Courier secrets: Logistics → Couriers → rotate. |
| `CRON_SECRET` leaked | Change it in Vercel; cron requests with the old value get `401`. |
| Webhook endpoint disabled after failures | Fix the receiver, re-enable the endpoint, then resend failed deliveries. |
| Staff account compromised | Users & Roles → deactivate (all its tokens are deleted), then reset the password. |
| The only Admin is locked out for 24 hours | Another Admin can use Users & Roles → Unlock sign-in. With no other Admin, wait for the lock to expire, or after confirming it was really them delete the lock in the Supabase SQL editor: `delete from login_lockouts where email = '<admin email, lowercase>';` (locally: `php artisan tinker --execute="App\Models\LoginLockout::clear('<email>')"`). |
| Scheduler appears stuck | `GET /api/v1/cron/run`; a crashed run's lock expires after five minutes. `failed_jobs` lists any job that failed. |

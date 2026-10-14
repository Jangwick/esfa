# Archon Nell — Support, Finance & Administration (SFA)

The back office of the Archon Nell marketplace: customer support, payments and refunds, logistics and Philippine
locations, seller subscriptions and commissions, analytics, and administration. It shares the PDMS stack and design.

- `backend/` — Laravel 12 JSON API (`/api/v1`), Sanctum 4 tokens, Spatie Permission 6, PHP 8.2+
- `frontend/` — React 19 + Vite 8 SPA (React Router 7, TanStack Query 5, Zustand 5, Axios, Tailwind CSS 3, Chart.js 4)
- `docs/` — `SPECIFICATION.md`, `DESIGN.md`, `TECH-STACK.md`, `LOGISTICS.md`, `DECISIONS.md`, `INTEGRATION.md`,
  `DEPLOYMENT.md`, `openapi.yaml`

## Run locally (Windows / XAMPP or any PHP 8.2+)

Requirements: PHP 8.2+ with `pdo_sqlite`, `bcmath`, `mbstring`, `openssl`, `fileinfo`, `curl`; Composer 2; Node.js 20+.

```bash
cd backend
php ../composer.phar install
cp .env.example .env
php artisan key:generate
php artisan migrate:fresh --seed     # SQLite file database/database.sqlite with the full demo dataset
php artisan serve --port=8000
```

```bash
cd frontend
npm install
cp .env.example .env                 # VITE_API_URL=/api/v1 (Vite proxies /api to http://127.0.0.1:8000)
npm run dev                          # http://127.0.0.1:5173
```

Seeding prints one development token per demo integration client. Run due scheduled work with
`php artisan sfa:run-scheduled` (or `php artisan schedule:work` for every minute).

### Demo accounts

All passwords are `password`.

| Email | Role |
|---|---|
| `admin@example.com` | Admin |
| `finance@example.com` | Finance |
| `support.one@example.com`, `support.two@example.com` | Support Agent |
| `logistics@example.com` | Logistics Coordinator |
| `analyst@example.com` | Analyst |

The demo data is fictional: about 60 `demo-storefront` orders over the last 45 days, payments in every status,
tickets in every status and SLA state, shipments in every status, Free/Growth/Pro demo plans, commission rules,
two settlement periods and six demo integration clients. Production seeding creates only roles and settings.

## Check

```bash
cd backend && php artisan test --compact      # feature tests on in-memory SQLite
cd frontend && npm run lint && npm run build
```

After changing routes, refresh the API contract (and its container copy):

```bash
cd backend && php artisan route:list --json --except-vendor > ../docs/.route-manifest.json
cd .. && python scripts/build-openapi.py
```

## Configuration

`backend/.env.example` lists every variable with a safe placeholder. The important ones: `SFA_TIMEZONE`,
`SFA_LOGIN_OTP` (`off|bound|all`), `SFA_ALLOWED_ORIGINS`, `CRON_SECRET`, `SFA_SANDBOX_WEBHOOK_SECRET`,
`SFA_SCHEDULER_ENABLED`, `SFA_SIMULATOR` (Admin process simulator; unset = on except in production), `SFA_PARTNER_URL`
(where `tools/partner-simulator` listens), `DB_*`, `MAIL_*`.
Never commit `.env` files or secrets.

## Deploy

See `docs/DEPLOYMENT.md` (Vercel frontend service + FrankenPHP container, Supabase PostgreSQL, Vercel Cron).
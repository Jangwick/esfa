# TECH-STACK.md — Archon Nell systems

The stack, versions and conventions of the Performance & Development Management System (PDMS). Systems built in the
same cluster use the same stack so they can share people, patterns and deployment. Versions are the ones in PDMS's
`frontend/package.json` and `backend/composer.json`.

## 1. Frontend — single-page app

| Package | Version | Role |
|---|---|---|
| react, react-dom | ^19.2.8 | UI (JavaScript + JSX, no TypeScript) |
| vite | ^8.2.0 | Dev server and build (`@vitejs/plugin-react` ^6.0.4) |
| react-router-dom | ^7.18.2 | Routing (`BrowserRouter`, lazy-loaded pages with `React.lazy` + `Suspense`) |
| @tanstack/react-query | ^5.101.4 | Server state, caching, mutations |
| zustand | ^5.0.15 | Auth store (token, user, roles, permissions in `localStorage`) |
| axios | ^1.19.0 | HTTP client with a bearer-token interceptor |
| tailwindcss | ^3.4.19 | Styling (with postcss ^8.5.26, autoprefixer ^10.5.4) |
| chart.js, react-chartjs-2 | ^4.5.1, ^5.3.1 | Charts |
| @fortawesome/fontawesome-free | ^7.3.1 | Main icon set |
| bootstrap-icons | ^1.11.3 | Secondary icons (pagination, dialogs) |
| oxlint | ^1.75.0 | Linter (`npm run lint`) |
| Inter (Google Fonts) | 400/500/600/700 | Font |

Frontend layout:

```
frontend/
├── index.html                 # <title>Archon Nell</title>, favicon /favicon.png
├── public/                    # logo.png, logo-dark.png, favicon.png
├── tailwind.config.js         # brand colors (see DESIGN.md)
├── postcss.config.js
└── src/
    ├── main.jsx               # QueryClient defaults, icon + css imports
    ├── App.jsx                # routes; <SessionTimeout/> while signed in
    ├── index.css              # Inter, body colors, select picker, scrollbar
    ├── components/
    │   ├── auth/              # ProtectedRoute, SessionTimeout
    │   ├── layout/            # MainLayout, Sidebar, Topbar
    │   ├── ui/                # Modal, ConfirmDialog, Pagination, PageLoader
    │   ├── notifications/     # NotificationBell
    │   └── <module>/          # one folder per module: forms, rows, cards, panels
    ├── hooks/                 # e.g. useNotifications
    ├── lib/                   # api.js (axios + signOut), dates.js, csv.js, <module>.js (labels, status tones, helpers)
    ├── pages/                 # one page per sidebar entry + Login, Settings
    └── store/authStore.js     # zustand
```

React Query defaults (from `main.jsx`): `staleTime` 5 min, `gcTime` 10 min, `placeholderData: keepPreviousData`,
`refetchOnWindowFocus: false`, `retry: 1`, and a `MutationCache` whose `onSuccess` marks every query stale
(`invalidateQueries({ refetchType: 'none' })`) so other pages reload their data when next opened.

`api.js`: `baseURL` = `import.meta.env.VITE_API_URL` (or `/api` in production builds, `http://127.0.0.1:8000/api` in dev);
headers `Content-Type: application/json`, `Accept: application/json`; the request interceptor adds `Authorization: Bearer <token>`;
a 401 from anything but `/login` clears the session and sends the user to `/login`. `signOut()` clears the local session
and calls `POST /logout` with the old token so the server deletes it.

## 2. Backend — JSON API

| Package | Version | Role |
|---|---|---|
| PHP | ^8.2 (8.2 locally on XAMPP, 8.4 in the hosted container) | Runtime |
| laravel/framework | ^12.0 | API framework (no Blade pages except mail templates) |
| laravel/sanctum | ^4.3 | Bearer-token authentication (personal access tokens) |
| spatie/laravel-permission | ^6.25 | Roles and permissions |
| laravel/tinker | ^2.10.1 | Console |
| phpunit/phpunit | ^11.5.50 | Tests (feature tests on in-memory SQLite) |
| laravel/pint | ^1.24 | Code style |
| fakerphp/faker, mockery, nunomaduro/collision, laravel/pail, laravel/sail | dev | Testing and tooling |

Backend layout: standard Laravel 12 — `app/Http/Controllers` (one controller per resource), `app/Models`,
`app/Services` (business logic that is more than CRUD), `app/Http/Middleware`, `app/Http/Resources`,
`database/migrations` (in foreign-key dependency order, because PostgreSQL enforces references at creation),
`database/seeders`, `routes/api.php`, `routes/console.php` (artisan commands), `tests/Feature`.

### 2.1 API conventions (follow these exactly)

- Base path `/api`. JSON in, JSON out. Clients send `Accept: application/json`.
- **Health:** `GET /api/health` → `{ "status": "ok" | "degraded", "commit": "<git sha>", "database": "connected" | "error" }`.
- **Auth:** `POST /api/login` with `{ email, password }` → `{ "user": {...}, "token": "<sanctum token>" }`.
  Every other route is in `Route::middleware(['auth:sanctum', 'audit.log'])` and needs `Authorization: Bearer <token>`.
  `POST /api/logout` deletes the current token. `GET /api/user` → `{ id, name, email, roles: [...], permissions: [...] }`.
- **Lists** return Laravel's paginator as-is plus an optional `summary` object for the page's summary cards:
  ```json
  {
    "current_page": 1, "data": [ ... ], "first_page_url": "...", "from": 1, "last_page": 3,
    "last_page_url": "...", "links": [ ... ], "next_page_url": "...", "path": "...", "per_page": 20,
    "prev_page_url": null, "to": 20, "total": 57,
    "summary": { "total": 57, "active": 40, "inactive": 12, "terminated": 5 }
  }
  ```
  Query parameters: `page`, `per_page` (default 20, clamped to 1–100), `search` (case-insensitive `LIKE` over the main text columns),
  and one parameter per filter (`status`, `department_id`, …). Newest first unless the screen needs another order.
- **Show:** the object with its relations loaded. **Create:** `201` with the created object. **Update:** `200` with the object.
  **Delete:** `200` with `{ "message": "Training deleted successfully." }`.
- **Errors:** `401 {"message":"Unauthenticated."}`, `403 {"message":"This action requires ..."}`, `404`,
  `422 {"message":"...", "errors": {"field": ["..."]}}` (Laravel validation), `429` with a `Retry-After` header.
- **Roles:** Spatie roles checked in controllers (PDMS: `Admin` manages, `Employee` views and acts on their own records);
  permissions are named `"<action> <module>"`, e.g. `view goals`, `create goals`.
- **Caching:** read-heavy `GET` lists use a `cache.api:<seconds>` middleware keyed by user + full URL; any successful write
  (POST/PUT/PATCH/DELETE) bumps a cache version so every cached list is refreshed.
- **Audit log:** an `audit.log` middleware records writes in `audit_logs` (`user_id`, `action`, `entity_type`, `entity_id`,
  `old_values`, `new_values` (JSON), `ip_address`, `user_agent`, timestamps). Admins read it at `GET /api/audit-logs`.
- **Notifications:** `notifications` table (`user_id`, `type`, `title`, `message`, `data` JSON, `is_read`, `read_at`) with
  `GET /notifications`, `GET /notifications/unread-count` (polled every 30 s), `PATCH /notifications/mark-all-as-read`,
  `PATCH /notifications/{id}/read`, `DELETE /notifications/{id}`.
- **Reports:** `GET /reports/dashboard` (counts, breakdowns, recent activity) and `GET /reports/charts` (`[{ name, value }]` series).
- **Time:** instants stored in UTC; dates without time stored as `YYYY-MM-DD`; business time zone `Asia/Manila`
  (config `PDMS_TIMEZONE`).
- **Sign-in protection:** failed sign-ins per email: five free, then locked 5 min, 10 min, 24 h (reset by a successful sign-in or a
  quiet day); each refusal says how many attempts are left; optional emailed 6-digit code (`PDMS_LOGIN_OTP`).
- **Session:** the SPA signs out after 15 minutes without activity (a "stay or log out" dialog first; see DESIGN.md §5.15).

## 3. Data

- **PostgreSQL on Supabase**, reached through the **session pooler** (IPv4) with `DB_SSLMODE=require`.
  The free pooler allows about 15 connections in total, shared by everything that uses the database.
- Every table migration ends with `ALTER TABLE <table> ENABLE ROW LEVEL SECURITY` when the driver is `pgsql`
  (the app connects as the owner, so it is unaffected; the public Supabase API is locked out).
- Local fallback and tests: SQLite. Seeders create demo data and accounts (`@example.com`, password `password`).

## 4. Hosting and environments

- **Local (Windows):** XAMPP Apache + PHP 8.2 serving the Laravel API on `http://localhost:8000`, Vite on `http://localhost:5173`.
  Under Apache's threaded PHP, `Env::disablePutenv()` is called in `bootstrap/app.php` (for `apache2handler` only) so requests do not
  read each other's `.env` values. CORS allows `http://localhost:5173`.
- **Production: Vercel Services**, one project, two services (see `vercel.json`): the Vite build is served as static files for `/*`,
  and `/api/*` goes to a **FrankenPHP container** built from `backend/Dockerfile.vercel` (`dunglas/frankenphp:1-php8.4-alpine`,
  `install-php-extensions pdo_pgsql pgsql bcmath ...`, `CACHE_STORE=array`, `SESSION_DRIVER=array`, `LOG_CHANNEL=stderr`,
  Laravel caches redirected to `/tmp`). Region `sin1`.
- **Container start-up gate (required):** FrankenPHP 1.12 can open its port before its PHP threads exist, and a request that arrives in
  that gap is never answered (it hangs until Vercel's 300-second limit). So `backend/Caddyfile` serves on `127.0.0.1:9180`, and
  `backend/start.sh` (the container `CMD`) waits until `http://127.0.0.1:9180/ready.php` answers before `socat` forwards `$PORT` to it:
  ```sh
  #!/bin/sh
  (
      until wget -q -T 1 -O /dev/null http://127.0.0.1:9180/ready.php; do sleep 0.1; done
      exec socat TCP-LISTEN:"${PORT:-80}",fork,reuseaddr TCP:127.0.0.1:9180
  ) &
  exec frankenphp run --config /etc/caddy/Caddyfile
  ```
  `public/ready.php` is `<?php echo 'ready';`. The Dockerfile adds `apk add --no-cache socat` and strips CRLF from `start.sh`.
- Vercel limits to respect: request and response bodies up to 4.5 MB (PHP upload limits set to 5M/6M in the image);
  no `php artisan` on Vercel (run migrations from a local machine against the same database); no persistent disk;
  the container registry keeps at most 50 images per repository (delete old ones when a deploy fails with
  "repository has reached the maximum allowed number of images").
- Never use persistent database connections on Vercel (`DB_PERSISTENT` unset): each PHP thread would hold a pooler slot for ever.
- Environment variables are set in the Vercel dashboard; secrets (DB password, mail password, API keys) are never committed.
- Deploys: pushing `main` to GitHub deploys production; `vercel --prod` deploys from a local checkout.

## 5. Other services

- **Mail:** Gmail SMTP (`MAIL_MAILER=smtp`, `smtp.gmail.com:587`, app password) for emailed sign-in codes.
- **AI (optional):** Google Gemini through `App\Services\AiService` (plain HTTP to `generateContent`, no SDK). Model
  `gemini-3.5-flash-lite` with `gemini-3.1-flash-lite` as the fallback, pinned in `SFA_GEMINI_MODEL` /
  `SFA_GEMINI_FALLBACK_MODEL` (the largest free-tier request quota: 15 a minute and 500 a day each).
  All numbers are computed in PHP and the model only words, labels and recommends; every answer is checked against SFA's
  own lists and stored in `ai_insights`. Each feature has a switch in Settings > Automation (all off by default) and a
  daily token limit applies. A missing key, a quota error or a busy model degrades gracefully with a clear message.
  Every AI feature checks a numbered rulebook (`App\Services\AiRules`, read-only at `GET /api/v1/ai/rules`): SFA's code
  checks what its records prove, the model judges only what needs reading, and the outcome follows from the rule results.

## 6. Quality bar

- Feature tests for every endpoint (status codes, validation, role checks, the shape of list responses).
- `npm run lint` and `npm run build` pass; `php artisan test` passes.
- No secrets in the repository; `.env.example` lists every variable with a safe placeholder.

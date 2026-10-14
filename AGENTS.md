# AGENTS.md

Guidance for anyone (human or AI) changing SFA. Requirements: `docs/SPECIFICATION.md`; design: `docs/DESIGN.md`;
stack: `docs/TECH-STACK.md`; recorded choices: `docs/DECISIONS.md`.

## Commands

| Task | Command |
|---|---|
| Install | `cd backend && php ../composer.phar install`; `cd frontend && npm install` |
| Fresh demo database | `cd backend && php artisan migrate:fresh --seed` (overwrites `database/database.sqlite`) |
| API server | `cd backend && php artisan serve --port=8000` |
| SPA | `cd frontend && npm run dev` |
| Backend tests | `cd backend && php artisan test --compact` |
| Lint / build | `cd frontend && npm run lint && npm run build` |
| Scheduled work | `cd backend && php artisan sfa:run-scheduled` |
| API contract | `php artisan route:list --json --except-vendor > ../docs/.route-manifest.json`, then `python scripts/build-openapi.py` |

All of these must pass before work is called done.

## Stack rules

Only the packages in `docs/TECH-STACK.md` (plus optional `leaflet`/`react-leaflet` for the shipment map). No other UI
kit, icon set, chart library, TypeScript, gradients or dark mode. Copy class strings from `docs/DESIGN.md`. Use the
logo files in `frontend/public` as they are.

## Backend conventions

- Every route lives under `/api/v1`. Staff routes use `auth:sanctum` + `staff` + `audit.log`; integration routes use
  `integration.only` + `abilities:<scope>`. Authorize on the server for every endpoint, including lists, details,
  option lists, exports, summaries and dashboards. Support Agents only see records linked to their own or unassigned
  tickets; integration clients only see records whose `source_system` equals their `system_code`.
- Register static routes (`options`, `generate`, `unread-count`) before `{id}` routes.
- Money: integer centavos in `*_amount` columns, `currency = PHP`, `Money::halfUp()` once per calculation; never
  floats. Money-changing work runs in a transaction with `lockForUpdate()` and writes balanced ledger pairs.
- State machines reject invalid transitions with `422` (`ValidationException`).
- Webhooks: `WebhookService::emitFor($type, $data, $sourceSystem)` — events go only to the owning active client.
- Lists return the paginator plus `summary`, accept `page`, `per_page` (1–100), `search`, filters, `sort`, and
  `export=csv` through `ListResponse` (which neutralizes spreadsheet formulas).
- Migrations run in foreign-key order and end with `ALTER TABLE … ENABLE ROW LEVEL SECURITY` on PostgreSQL. Add a new
  migration instead of editing one that may have run.
- Every endpoint has feature tests (401, 403, 422, happy path, list shape). A route missing from `docs/openapi.yaml`
  fails `OpenApiTest`.
- Scheduled jobs must be idempotent and live behind `App\Services\Scheduler`.

## Frontend conventions

Pages in `src/pages`, module components in `src/components/<module>`, helpers in `src/lib`. Server state through
TanStack Query; show loading, empty and error states with a "Try again" action, disable buttons while saving, show
server validation messages, and keep every page usable at 375 px. Printable pages render outside `MainLayout`.

## Deployment

See `docs/DEPLOYMENT.md`. Never commit secrets; production values live in the Vercel dashboard.
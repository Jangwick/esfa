# SFA handoff: unfinished work

Written 2026-10-11. For whoever continues this project (human or AI).

## 1. Project in one paragraph

SFA ("E-Commerce Support, Finance and Administration") is a Laravel 12 API (`backend/`) with a React 19 + Vite SPA (`frontend/`). Read these first:

- `AGENTS.md`: the rules every change must follow.
- `docs/SPECIFICATION.md`, `docs/DESIGN.md`, `docs/TECH-STACK.md` and `docs/DECISIONS.md`.
- [plan.md](plan.md): the approved automation megaplan, with every task's files, acceptance criteria and tests.
- [todo.md](todo.md): the checklist for that plan.

The project is **not** a git repository, so there is no diff to review. Check what's on disk directly.

## 2. Where things stand

| Item | State |
|---|---|
| Automation megaplan, Phases 0–6 | Built. Every box in todo.md is ticked. Phase 6 rules are all off by default (`refund_auto_approve_cap_amount` 0, `logistics_courier_pod` false, `settlement_carry_forward` false, `default_plan_id` null, `risk_review_hold_hours` 0) |
| Interrupted reviewer edits (11:18) | Checked; the full checkpoint re-ran green at ~14:00 (252 tests) |
| Integration Simulator (Task 9) | Built: one tab per connected system. Audited: `GET /simulator` gives each order, payment, ticket and courier-shipment row server-computed `actions` and `next_step`, and every form offers only what SFA accepts |
| Integrations page | Removed from the frontend at the owner's request (backups in `backups/removed-integrations/`). The backend endpoints, tests and `docs/openapi.yaml` stay |
| AI assistant (tasks/ai-plan.md, ai-todo.md) | **Built 2026-10-12, not deployed.** Google Gemini via `AiService` (`gemini-3.5-flash-lite`, fallback `gemini-3.1-flash-lite`; free tier 500 requests a day each): ticket triage, reply drafts, finance review + optional second opinion, address fill, failed-delivery reason, Ask your data. Every switch is off by default (Settings > Automation). To go live the owner must: set `SFA_GEMINI_API_KEY` (and optionally the model variables) in Vercel, run the `ai_insights` migration on Supabase, rotate the key that was pasted in chat, and decide on billing (Google's free tier may use prompts to improve its products, and its per-minute limit is small) |
| Dashboard "Needs attention" card | Removed from the frontend at the owner's request; the backend attention data stays |
| Final full checkpoint (after the simulator audit gap fixes) | **Passed on 2026-10-11**: `migrate --force` (nothing to migrate), 286 backend tests (4483 assertions), route manifest + OpenAPI rebuild (140 paths, 172 operations), scheduled work twice (no new actions, `failed_jobs none`), lint and build. These fixes are local only; production still runs the ~22:30 deploy |
| 375 px browser check of the new UI | **Done 2026-10-11/12** by two runtime browser sweeps at desktop and 375 px: every non-simulator page (37 findings, 35 fixed + 6 reviewer fixes) and every Process Simulator feature on an isolated database copy (20 fixes, plus a "What happens?" guide on every simulator card, checked against real sends) |
| Production (Vercel + Supabase) | **Last deployed 2026-10-12 ~00:50** (deployment `esfa-kzxtgwisl…`, aliased to https://esfa-eta.vercel.app), after a green checkpoint: 289 backend tests, OpenAPI rebuilt (140 paths), scheduled work twice, lint and build. Health ok with the database connected, protected routes answer 401, the served OpenAPI matches the local file byte for byte. No new migrations since the 2026-10-11 deploy. Scheduled work is still paused (`SFA_SCHEDULER_ENABLED=false`) |

**Simulator audit gap fixes (done, not yet deployed):**

- **Order status rules.** `MirrorController::closeOrder` is the one cancel rule for `POST /orders/{id}/cancel` and for an order upsert that sets `status_snapshot` to `cancelled`. It returns 422 `order` when money was captured, cancels shipments through `LogisticsService::cancelForOrder`, then cancels the pending and authorized payments, all in the upsert's transaction. Sending `cancelled` again returns 200; any other status on a cancelled order returns 422 `status_snapshot` (a cancelled order stays cancelled).
- **Reconciliation scope.** `ReconciliationService::gatewayCaptures` (non-COD, captured, inside the business-timezone window) decides the `missing_in_report` rows. Subscription and invoice charges stay in, because they go through the sandbox gateway. `GET /simulator/settlement-file` also leaves COD out (422 when only COD was captured), and its short row stays positive.
- **Webhook delivery search.** The id matches only a whole UUID by equality, so PostgreSQL no longer errors on `uuid LIKE`.
- **Simulator.** `POST /simulator/run-scheduled` is throttled (`throttle:6,1`). `GET /simulator` adds seller profiles, record links and `quote_cities`. The storefront forms use them: store profile refill, quotable cities only, an optional shipment on tickets and an optional ticket on refunds. Lookups refresh after each successful send.
- **Courier webhook cache.** `CourierWebhookController` already bumps the API response cache version, so only the payment webhook route lacks it (see below).

**Still open (owner's payment-integration code, section 4):**

- `payment.dispute_won` and `payment.dispute_lost` send no storefront webhook, because `PaymentService::processEvent` does not emit them.
- The payment webhook route (`routes/payments-webhooks.php`, `PaymentController::webhook`) does not flush the API response cache, so cached lists can show a gateway status change up to 30 seconds late.

## 3. Unfinished tasks, in order

### Task 1: Verify the interrupted reviewer edits (do this first)

1. Run `php -l` on `PaymentService.php`, `BillingService.php` and `TicketService.php`. The last two edits landed as the agents were stopped, so they may be half done.
2. Read each file in the table above and look for incomplete edits:
   - unfinished methods;
   - a test that calls a method that doesn't exist;
   - a test that asserts behaviour no code implements.
3. Run the full checkpoint (Task 3). Fix failures at their root.
4. If a reviewer change can't be completed sensibly, revert just that change. Keep the behaviour that passed at 11:08.

**Never weaken or delete a test to make it pass.** Only change a test where the approved plan changed that behaviour.

### Task 2: Finish the per-module review the reviewers didn't complete

Review each module against its acceptance criteria in plan.md section 6:

| Module | Tasks |
|---|---|
| Foundation | 0.1–0.5 |
| Support | 1.1–1.8 |
| Payments | 2.1–2.7 |
| Logistics | 3.1–3.9 |
| Billing | 4.1–4.5 |
| Platform | 5.1–5.5 |

Check each one for:
- **Authorization:** on every endpoint and every count, including role and ownership scoping. Support Agents see only their own and unassigned tickets. Integration clients see only records whose `source_system` is theirs.
- **Status changes:** an invalid transition returns 422.
- **Money:** amounts are integer centavos. Changes run in a transaction with `lockForUpdate`, and every money change writes a balanced ledger pair.
- **Scheduled jobs:** each is idempotent, locks and re-checks each record, and wraps each record in its own try/catch.
- **Notifications:** sent only to active users who hold the right permission.
- **Forbidden code:** nothing edited in the payment-integration code listed in section 4.
- **Frontend:** loading, empty and error states with "Try again"; buttons disabled while saving; server validation messages shown; usable at 375 px.

Add a focused test for every fix.

### Task 3: Final full checkpoint

Run these:

```bash
cd backend && php -l app/Services/PaymentService.php && php -l app/Services/BillingService.php && php -l app/Services/TicketService.php
cd backend && php artisan migrate --force
cd backend && php artisan test --compact
cd backend && php artisan route:list --json --except-vendor > ../docs/.route-manifest.json
python scripts/build-openapi.py
cd backend && php artisan sfa:run-scheduled
cd frontend && npm run lint && npm run build
```

What must hold:
- `migrate` uses the local SQLite DB only. Never use `migrate:fresh`, because it wipes the demo data.
- Every backend test passes. The last good count was 226 passed; there will be more if reviewers added tests.
- `php artisan sfa:run-scheduled`, run twice: the second run reports zero new automatic actions and `failed_jobs none`.
- Lint and build both pass.

When it's all green, change the status line in todo.md.

### Task 4: Manual 375 px browser check (done 2026-10-12 by the runtime browser sweeps; repeat it after big UI changes)

Start the app:

```bash
cd backend && php artisan serve --port=8000
```

```bash
cd frontend && npm run dev
```

Sign in with a local demo account (credentials are in the seeders). Then check these pages at 375 px wide:

| Page or area | What to check |
|---|---|
| Support, Logistics, Payments (refunds, risk, reconciliation), Subscriptions (settlements) | The bulk-selection bars |
| Logistics | The "Awaiting booking" tab |
| `/logistics/labels?ids=…` | The printable labels page |
| Ticket detail | The reply composer ("After sending" select, suggested reply chips) |
| Simulator (`/simulator`, Admin) | Each tab's send forms, "In SFA now" panels and "Receives from SFA" |
| Settings | The Automation tab |

### Task 5: Known limits the build accepted (both fixed)

- **PostgreSQL deadlock: fixed.** Order cancel (`MirrorController::cancel` and `order`, through `closeOrder`), every shipment change (`LogisticsService::lockForMutation`) and COD capture all lock the order first, then its payments by id, then its shipments by id (DECISIONS.md, Logistics). Two of them on the same order queue on the order row instead of deadlocking.
- **Duplicated SLA recompute: fixed.** A shipment's third failed attempt raises the linked ticket's priority through `TicketService::changePriorityLocked`, the same SLA recompute as a staff priority change.

### Task 6: Phase 6 (only if the owner approves)

Built (all five rules, off by default) and covered by Checkpoint 6. Details are in plan.md under "Phase 6".

| Task | Decision needed |
|---|---|
| 6.1 Refund auto-approve under a cap | Relaxes the second-approver rule (SPECIFICATION.md:229). Proposed: ₱500 cap, ₱2,000 per requester per day, off by default |
| 6.2 Courier proof of delivery, prepaid parcels only | |
| 6.3 Negative settlement carried into the seller's next settlement | Needs a new migration |
| 6.4 Free-plan auto-subscribe for new sellers | |
| 6.5 Risk-review hold window | Amends SPECIFICATION.md:207 |

### Task 7: Deploy the automation work to production (steps 1–4 done 2026-10-11; 5–6 are still the owner's call)

Production is one Vercel project, `esfa`, with the frontend and a FrankenPHP backend container. The database is Supabase Postgres in ap-northeast-1. The live URL is https://esfa-eta.vercel.app. Follow `docs/DEPLOYMENT.md`.

1. Finish Tasks 1–3 first.
2. Run the 3 new migrations on Supabase as the table-owner role `sfa_app`:
   - `2026_10_11_000100_add_last_result_to_scheduler_locks`
   - `2026_10_11_000310_add_attention_flags_to_shipments`
   - `2026_10_11_000600_add_recovered_by_settlement_id_to_settlements`
3. Deploy with the Vercel CLI. It may need reinstalling: `npm i -g vercel`.
4. Check that `/api/v1/health` returns `"database":"connected"`.
5. Production still has `SFA_SCHEDULER_ENABLED=false`, so scheduled work stays paused. Turn it on only after reviewing the copied demo data that is already due: renewals, dunning, settlements.
6. After that, add the 5-minute Supabase `pg_cron` trigger. The recipe is in DEPLOYMENT.md, and it's documentation only so far.

**The pause guard (task 0.1) must be live before the trigger is enabled.** It is live since the 2026-10-11 deploy, so cron, artisan and the simulator now all respect `SFA_SCHEDULER_ENABLED=false`.

### Task 8: Production security follow-ups (owner action, still open)

1. Change the passwords of every demo account (`admin@example.com` and the rest). They all use `password`, and the site is public.
2. Rotate the Supabase access token and the Postgres database password. Both were pasted into a chat. Then put the new DB password into Vercel and redeploy.
3. Delete the `personal_access_tokens` copied from the local DB. These are local staff sessions and demo partner keys, and they still work in production.
4. Deactivate the webhook endpoint that points at `http://localhost:8787/webhooks`.
5. Production `APP_KEY` was set equal to the local `backend/.env` key so the copied encrypted fields decrypt. Keep that `.env` private. Rotating the key means re-encrypting three columns: `couriers.webhook_secret`, `sellers.payout_destination` and `webhook_endpoints.secret`.

### Task 9: Paused feature, the tabbed Integration Simulator

Done (see section 2); kept here for the original request. It was requested before the automation work, then paused half-built:
- `frontend/src/lib/simulator.js` is incomplete.
- `frontend/src/pages/Simulator.jsx` was partly changed but still renders the old Process Simulator and works.

What the owner asked for:
- Inside SFA's Process Simulator (Admin only), one **tab per connected outside system**.
- Inside each tab, show **only the features that system actually exchanges with SFA**: what it sends and what it receives.
- The owner chose "read-only access to all" for the simulator's view of integration data.

A separate partner simulator app already exists at `tools/partner-simulator/`. It runs on port 8787 and plays the storefront client `demo-storefront`. Reuse it.

## 4. Rules for whoever continues

- **Payment integration is off limits.** Do not modify any of these; the owner will do this work personally:
  - `PaymentController::createIntegration` and `showIntegration`
  - `RefundController::createIntegration` and `showIntegration`
  - `backend/app/Gateways/*`
  - `PaymentController::webhook` and `PaymentService::processEvent`

  Calling existing shared methods is fine.
- **Keep the existing work and dependencies:**
  - Don't reset the repo or delete existing work.
  - Don't replace `package-lock.json` or `composer.lock` unless there is a real dependency problem.
- **Frontend limits:**
  - No new UI kit, icon set or chart library.
  - No TypeScript, no gradients, no dark mode.
  - Copy class strings from `docs/DESIGN.md`.
  - Don't redraw or recolour the logo in `frontend/public`.
- **Secrets and claims:**
  - Never commit secrets.
  - Never put real credentials in docs.
  - Don't claim something was deployed or verified unless you actually did it.
- **Production data:** production must never get *new* demo users, tokens, records or webhook endpoints. The current demo data was copied there on purpose by the owner.
- **Automation defaults the owner approved:**
  - Automatic behaviour is ON by default.
  - Rule-based behaviour is OFF by default.
  - Auto-capture of later-authorized payments is ON.
  - Order cancel may cancel shipments (MirrorController edit approved).

  Current defaults in `backend/config/sfa.php`:
  - **On:** `auto_capture_authorized`, `webhook_auto_recover`, `logistics_auto_ticket`.
  - **Off:** `auto_assign`, `ticket_auto_acknowledge`, `logistics_courier_fill_gaps`.
  - **0 = off:** `ticket_waiting_auto_resolve_days`, `risk_auto_clear_min_captured`, `settlement_auto_finalize_days`.
  - **Timers:** `finance_reminder_hours` 4, `token_expiry_reminder_days` 7, `notification_retention_days` 90, `logistics_stuck_hours` 48.

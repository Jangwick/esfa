# SFA Automation Megaplan (draft for approval)

This plan is based on reading the code only. No files were changed. Nothing gets built until you approve it.

**Off-limits (your payment-integration work):** `PaymentController::createIntegration/showIntegration`, `RefundController::createIntegration/showIntegration`, `backend/app/Gateways/*` and the inbound payment webhook (`PaymentController::webhook` → `PaymentService::processEvent`, PaymentService.php:144-150). Some staff-side items below call existing shared methods (`captureLocked`, `cancel`, `requestRefund`). None of them edits those methods.

---

## 1. Current workflow

| Module | Step | Who | Manual today? |
|---|---|---|---|
| Support | A ticket arrives through the integration or the staff form. SLA due times are set, and auto-assign runs only if `auto_assign` is on (TicketService.php:48-74, :62-66) | System / Agent | Partly |
| Support | Link the order, then the payment, then the shipment from three dependent selects (SupportForms.jsx:15-17) | Agent | Yes |
| Support | Open each ticket and press "Assign to me" (TicketDetail.jsx:49). The list has no row selection (Support.jsx:52-56) | Agent | Yes, per ticket |
| Support | "Send reply", then a separate "Change status" (TicketDetail.jsx:69-78, :48) | Agent | Yes, 2 actions |
| Support | A customer replies on a waiting ticket. Nobody is told; `message()` only emits a webhook (TicketService.php:154), so the agent must move the ticket back by hand | Agent | Yes, no alert |
| Support | Chase or resolve tickets stuck in waiting_on_customer (guidance.js:7) | Agent | Yes |
| Support | A deactivated agent's open tickets become invisible to everyone else (UserController.php:70-99, TicketService.php:31) | Admin | Yes |
| Support | An SLA breach reaches only the assignee (TicketService.php:220). A low CSAT is saved without any alert (:160-167) | Admin | Yes |
| Payments | Risk review is two steps: Clear/Block (RiskReviewController.php:11-13), then open the payment and press Capture/Cancel (Payments.jsx:52, :58) | Finance | Yes |
| Payments | A payment the gateway authorizes later sits until someone presses Capture, or it expires after 30 minutes (PaymentService.php:144, :169-175) | Finance | Yes |
| Payments | A COD order above the risk threshold cannot be marked delivered: the capture throws inside the proof upload (PaymentService.php:62; LogisticsService.php:110) | Coordinator + Finance | Yes (deadlock) |
| Payments | Approve or reject refunds one at a time with a confirm each (Payments.jsx:49, :58) | Finance | Yes |
| Payments | Remember to check aging refunds, risk reviews and disputes; nothing reminds anyone (PaymentService.php:46, :103, :148) | Finance | Yes |
| Payments | Upload the settlement CSV, then resolve each unmatched row with its own note (ReconciliationService.php:14-36) | Finance | Yes |
| Logistics | Find paid orders that have no shipment. The picker shows only the 200 newest orders (LogisticsController.php:113) | Coordinator | Yes |
| Logistics | Press "Get shipping quotes" and pick a radio before saving (LogisticsForms.jsx:38-47) | Coordinator | Yes |
| Logistics | Each status step is per shipment: modal, Timeline, select, Update, Confirm (Logistics.jsx:73, :97; ShipmentDetail.jsx:31) | Coordinator | Yes, about 4 clicks per step |
| Logistics | No labels exist; staff copy reference, tracking number and address by hand (App.jsx:63-65 has only receipt and billing print pages) | Coordinator | Yes |
| Logistics | Spot late or silent parcels by scanning the "Promised delivery" column (Logistics.jsx:81). There is no logistics scheduler job (Scheduler.php:52-54) | Coordinator | Yes |
| Logistics | After a failed delivery, open a support ticket by hand. A failed attempt only rings the bell (LogisticsService.php:111, :144-147) | Agent | Yes |
| Logistics | A cancelled source order leaves its shipments alive (MirrorController.php:52-56) | Coordinator | Yes |
| Billing | Empty settlement drafts are created for every seller and can't be removed: void gets rebuilt within minutes (BillingService.php:122-143, :126, :191-194) | Finance | Yes |
| Billing | Finalize each draft, then fill one payout form per seller (Subscriptions.jsx:43; BillingForms.jsx:33) | Finance | Yes |
| Billing | Void the invoice left open after a cancel. Press Charge after a late capture (BillingService.php:59-68, :86-102) | Finance | Yes |
| Billing | Notice subscriptions that dunning has cancelled; only a webhook fires (BillingService.php:102) | Finance | Yes |
| Platform | Know whether scheduled work ran, or force a run (curl with CRON_SECRET; the counts are discarded at Scheduler.php:61) | Admin | Yes |
| Platform | Recover a webhook endpoint: enable it, filter Failed, then Resend and confirm each delivery (Integrations.jsx:63, :66, :97) | Admin | Yes |
| Platform | Token expiry and 24-hour sign-in locks show only when someone looks (Integrations.jsx:107; Users.jsx:65) | Admin | Yes |
| Platform | Fan-out notifications stay unread for everyone after one person acts (NotificationController.php:34) | All staff | Yes |
| Platform | Tour every module to find pending work; the dashboard shows KPIs only (ReportingService.php:104-118) | All staff | Yes |

## 2. Already automatic (do not duplicate)

- **Scheduler:** `Scheduler::run` (Scheduler.php:16-66) takes a DB lock with a 5-minute lease and runs isolated jobs: idempotency pruning, webhook delivery with retries, payments, tickets and billing. Triggers are the daily Vercel cron (vercel.json:36-41 → CronController.php:10-17), a piggyback run after authenticated requests at most every 5 minutes (RunScheduler.php:19-30), and `sfa:run-scheduled` every minute locally (routes/console.php:7-21).
- **Support:** auto-assign to the least-loaded agent at create (opt-in); SLA due times and pause/resume; `first_responded_at`; SLA at-risk/breached notifications once each; auto-close 7 days after resolve with an audit row (TicketService.php:212-228); canned-response placeholders; ticket webhooks.
- **Payments:** auto-capture at create when the payment is authorized and risk is `none` (PaymentService.php:42-44); risk flagging and notification; 30-minute expiry; COD capture on delivery; balanced ledger pairs; commission entries and reversals; refund request/completion notifications; outbound webhooks with retries; reconciliation matching on import.
- **Logistics:** auto return after the 3rd failed attempt (LogisticsService.php:109); COD capture; exception bells; signed and deduped courier webhooks; server-side fees, ETA and summary cards.
- **Billing:** renewals, trial conversion, deferred downgrades, period-end cancels, dunning at 1/3/7 days then cancel, upgrade proration, idempotent charge, weekly/monthly settlement generation, commission rule hand-over (BillingService.php:172-196).
- **Platform:** endpoint auto-disable after 20 failures with an Admin alert (WebhookService.php:146-161); stuck-delivery requeue; sign-in lockouts that expire on their own; an audit row for every staff write (AuditWrites.php:28-43); response cache.

## 3. Automation catalogue

Kinds: **auto** = no click. **one-click** = N steps or N records become one action. **rule** = a setting replaces a human decision, and the manual path stays as the fallback.

| # | Module | Process | Today (manual) | After | Trigger | Panel | Size |
|---|---|---|---|---|---|---|---|
| ADM-10 | Platform | Kill switch honoured everywhere | Cron, artisan and the simulator ignore `SFA_SCHEDULER_ENABLED`, so renewals could charge during a paused window | auto (bug fix) | `Scheduler::run` guard | med | S |
| ADM-01 | Platform | Scheduler status + "Run due work now" + failed-job alert | curl and read JSON; failures only go to `report()` | one-click | Button / each run | high | M |
| ADM-02 | Platform | Automation tab: every rule with toggle, threshold and last result | Hidden, hard-coded | rule (visibility) | Settings > Automation | high | M |
| ADM-08 | Platform | "Needs attention" queue with deep links | Tour every module | one-click | GET /dashboard | high | M |
| SUP-1 | Support | Status follows replies; agent alerted on customer reply; first reply claims the ticket | 2-3 manual moves per conversation, no reply alert | auto | Inside `message()` | high | M |
| SUP-2 | Support | Reply and set status (Waiting / Resolved) in one send | 2 forms, 2 requests | one-click | Reply submit | high | S |
| SUP-3 | Support | Bulk claim / assign / change status | Per ticket inside the modal | one-click | Row selection | high | S |
| SUP-4 | Support | Unassigned queue heals itself: release a deactivated agent's tickets, sweep-assign | Admin reassigns one by one; tickets go invisible | auto | User update + scheduler (`auto_assign`) | med | S |
| SUP-6 | Support | SLA breach escalated to Admins | Only the assignee hears | auto | Scheduler | med | S |
| SUP-12 | Support | Low-CSAT (≤2) alert | Silent | auto | `csat()` | med | S |
| SUP-8 | Support | A customer reply after resolve postpones auto-close | Closed regardless, staff not told | auto | Scheduler | med | S |
| SUP-9 | Support | Auto-resolve tickets waiting N days with no customer reply | Agent must remember | rule (off) | Scheduler | med | S |
| SUP-7 | Support | Auto-acknowledge new tickets | Agent inserts the canned "Acknowledgement" | rule (off) | `create()` | low | S |
| SUP-10 | Support | Auto-link the only payment or shipment of the order (category-gated) | 3 dependent selects | auto | `create()` | med | S |
| SUP-11 | Support | Suggested canned replies and KB articles | Unranked dropdown, no KB access | one-click | Conversation tab | med | S |
| SUP-13 | Support×Pay | Refund outcome noted on the linked ticket; requester told when a refund is rejected | Agent checks the Refunds list | auto | `approve()` / `reject()` | med | S |
| SUP-14 | Support | Refund form prefilled with the refundable balance | Blank amount every time | one-click | Open form | low | S |
| SUP-15 | Support | One SLA editor (the Settings SLA fieldset is dead) | Edits silently ignored (SlaPolicy.php:14-16) | deletion | n/a | low | S |
| PAY-3 | Payments | "Clear and capture" (COD: capture if ready) | Clear, open, Capture | one-click | Risk tab | high | S |
| PAY-4 | Payments | "Block and cancel"; queue shows only open reviews | Block, open, Cancel; the queue never empties | one-click | Risk tab | med | S |
| PAY-5 | Pay×Logistics | COD risk hold instead of delivery deadlock; ship guard; amount-mismatch alert | Proof upload fails with 422 until Finance acts | auto | Shipment delivered / created | high | S |
| PAY-1 | Payments | Auto-capture later-authorized payments; invoice marked paid (merges BIL-4) | Capture by hand or lose it in 30 min; invoice waits for a retry | auto (setting) | Scheduler | high | M |
| PAY-9 | Payments | Overdue reminders: refunds, reviews (incl. COD holds), disputes | Remember to look | auto (hours, 0 = off) | Scheduler | med | S |
| PAY-7 | Payments | Approve selected refunds | One by one | one-click | Row selection | high | S |
| PAY-10 | Payments | Auto-resolve earlier "missing in report" rows a later file matches | Resolve each by hand | auto | CSV import | med | S |
| PAY-12 | Payments | "Resolve all (N)" with one note; locked single resolve | One note form per row; race condition | one-click | Reconciliation detail | med | S |
| PAY-6 | Payments | Auto-clear trusted repeat customers under a cap | Every review is manual | rule (off) | Scheduler | med | S |
| LOG-3 | Logistics | Bulk forward status for selected shipments | About 4 clicks × N | one-click | Row selection | high | S |
| LOG-4 | Logistics | Printable labels for the selection | Copy by hand | one-click | Bulk bar | med | M |
| LOG-1 | Logistics | Cheapest quote auto-loaded and preselected; courier optional on create | Get quotes + radio; even tracking-only edits force a re-quote | auto | Form inputs valid / create | high | M |
| LOG-2 | Logistics | "Awaiting booking" queue with a prefilled form | Hunt orders, retype the address | one-click | Logistics page | high | M |
| LOG-5 | Logistics | Overdue and stuck detection: digest, filter, Late pill | Scan the column | auto | Scheduler | high | M |
| LOG-6 | Logistics×Support | Auto-open or update a delivery ticket on a failed attempt | Bell, then a manual ticket | auto (toggle, on) | Shipment transition | high | M |
| LOG-11 | Logistics×Pay | Parcel returned: pending COD payment cancelled; prepaid order flagged to Finance | Payment pending forever | auto | returned_to_sender | med | S |
| LOG-12 | Logistics | Hygiene: tracking number required before pickup; bells only to `edit shipments`; `emitFor`; cache bump after courier events | Silent failures; scope leak | auto | Transition / webhook | low | S |
| LOG-7 | Logistics | Order cancel cancels open shipments (422 once with courier) | Cancel each one; COD cash gap | auto (decision) | Order cancel / upsert | med | S |
| LOG-8 | Logistics | Courier gap-fill for skipped forward steps; rejected events alerted | 422, event lost | rule (off) | Courier webhook | med | S |
| BIL-1 | Billing | Settlement run only for sellers with sales; one digest notification | Empty drafts that can't be voided; N bells | auto | Scheduler | high | S |
| BIL-5/6/10 | Billing | Cancel closes leftover invoices; Charge on a cancelled subscription returns 422; dunning-cancel alert; gateway "pending" no longer counts as a failure | Void by hand; silent reactivation bug; false alarms | auto | `cancel()` / `charge()` | med | S |
| BIL-3 | Billing | Finalize selected drafts / record selected payouts (method defaults to the seller's) | One click + one form per seller | one-click | Row selection | high | S |
| BIL-2 | Billing | Auto-finalize scheduler drafts after N days (Void = hold) | Finalize each | rule (off) | Scheduler | high | S |
| BIL-11 | Billing | Catch-up generation for older periods with unsettled entries | Remember custom Generate | auto | Scheduler | med | S |
| ADM-03 | Platform | "Resend failed (N)" and "Enable and resend failed" | Resend + confirm × N | one-click | Deliveries / Webhooks tab | high | S |
| ADM-04 | Platform | Auto-recover auto-disabled endpoints (probe, re-enable, requeue) | Admin notices and does 3 steps | rule (on) | Scheduler | med | M |
| ADM-05 | Platform | Token expiry reminders | Partner gets a 401 | auto (days, 0 = off) | Scheduler | med | S |
| ADM-07 | Platform | 24-hour lock alert + "Sign-in locked" filter | Scan the Users list | auto | 8th failed sign-in | med | S |
| ADM-09 | Platform | Auto-clear notifications whose item is resolved; prune old read ones | Mark read by hand; rows pile up forever | auto (retention) | Scheduler | med | M |
| PAY-8 | Payments | Auto-approve small refunds (cap, reasons, daily limit) | Every refund needs an approver | rule (off, **decision**) | Refund request | high | M |
| LOG-9 | Logistics | Accept courier proof of delivery for prepaid parcels | Staff photo upload for every parcel | rule (off) | Courier webhook | med | M |
| BIL-12 | Billing | Negative settlement carried forward into the next one | Debt recovered outside SFA | auto | Settlement generate | med | M |
| BIL-7 | Billing | Auto-subscribe new sellers to a free plan | Per-seller form | rule (off) | Scheduler | low | M |
| PAY-2 | Payments | Risk-review hold window | 30-minute expiry | rule (0 = today) | Scheduler | low | S |

**Dropped after critique:**
- ADM-06 daily digest: duplicates the dashboard, and the timing doesn't work.
- Switches that turn off behaviour the spec requires (auto-close, SLA alerts, settlement generation, the 20-failure disable).
- Configurable dunning days.
- Risk auto-block.
- `logistics_quote_preference`: cheapest only; add "fastest" when asked.
- `ticket_auto_close_days`: the spec fixes 7 days.
- Token pruning.
- Chaining print → status change.
- Server bulk endpoints for tickets, refunds, shipments, settlements and reconciliation rows. The F7 client loop replaces them.

## 4. Keep manual (and why)

- **Refund rejection, refunds outside the PAY-8 policy, and gateway-failed refunds.** These are judgment calls, and separation of duties applies (PaymentService.php:113).
- **Risk decisions no rule matches, manual payment cancel, dispute outcomes, "Simulate webhook".** These are fraud judgment, or they sit in inbound webhook code that is off-limits. PAY-9 alerts on disputes without touching that code.
- **Settlement-file fetch (KM-1), gateway fee adoption (PAY-11), and void + `payment.expired` webhook on expiry.** All three need gateway or integration-contract changes you own. The import is already ready to be fed by a job later.
- **Disputed or lost payments inside settlements.** Settlement adjustment stays Finance's call; BIL-2 excludes these drafts from auto-finalize.
- **Reopening a resolved ticket on a customer reply.** Reopen wipes CSAT (TicketService.php:133). SUP-1 alerts the agent and SUP-8 pauses the close instead.
- **Reassign or priority bump on breach, KB publishing, ticket deletion, reply content.** These risk ping-pong, are editorial or destructive, and are judgment. SUP-11 only suggests replies.
- **Fully automatic shipment booking at payment.** Orders have no weight, dimensions or PSGC address (migration 000200:26-36). The storefront can already auto-book through `POST /integrations/shipments`; document this in INTEGRATION.md.
- **COD proof and the "cash collected" tick.** This is cash custody (SPECIFICATION.md:276-277).
- **Courier, zone, rate and warehouse config; secret rotation; PSGC import.** These are commercial and security decisions, and the import is already one atomic action.
- **Payout disbursement and bank files.** SFA has no payout rail, and destinations are stored encrypted (Seller.php:9). BIL-3 only records payouts that a person has attested.
- **Plan changes, voids, commission rules, finalizing a negative-net settlement.** These are pricing and contract decisions.
- **Staff accounts, roles and activation; token issue/revoke/rotation; early unlock of a 24-hour lock; settings changes; audit review; simulator actions; password reset.** These are security and governance decisions, and SPECIFICATION.md:1326 rules out a "forgot password" flow.

## 5. Shared foundation

These pieces reuse existing mechanisms and add no new packages or tables, apart from one column.

- **F1. One settings store.** Use the existing `settings` table, defaults in `config/sfa.php` `settings` (:14-30), rules in `SettingsRequest.php`, the OpenAPI schema at `scripts/build-openapi.py:61`, and existing GET/PUT `/settings` (Admin).
  - Change `Setting::valueFor` (Setting.php:12-15) to fall back to `config("sfa.settings.$key")` when no default is passed. Today the defaults are repeated inline (PaymentService.php:179).
  - Convention: each rule gets one setting, either a boolean or a number where `0` means off. Read it once per run, not per row.
  - Values that Finance or Logistics pages need are returned from `/payment-options` or `/billing/options`.
- **F2. Settings > Automation tab** (Admin; `Settings.jsx:17` moves from `useState('profile')` to `useUrlState`).
  - A registry array `AUTOMATIONS` in `src/lib/admin.js` holds key, module, plain-language "what it does", trigger text, setting key and type, and `last_result` keys.
  - Each row shows the toggle or number field (existing `Field`, `fieldClass`, checkbox pattern), a "Last run: N" count, or an `Always on` `StatusPill` for integrity jobs (expiry, renewals, dunning, retries).
  - Saving uses the existing PUT `/settings`. The button is disabled while saving and server messages are shown.
- **F3. Run state.**
  - A new migration adds nullable json `scheduler_locks.last_result`; RLS is already enabled.
  - `Scheduler::run` writes `$result + finished_at` before returning (Scheduler.php:61).
  - New `AutomationController`: `GET /automation` and `POST /automation/run` (`throttle:6,1`), both under `requirePermission('manage settings')`. `run` returns 422 when paused.
  - A failed job notifies active Admins with `scheduler_job_failed`, at most once per job per day.
- **F4. Activity log.**
  - Automatic actions write `AuditLog` rows with `user_id = null` and `new_values.automation = '<rule key>'`, through one small `AuditLog::system($entity, $old, $new, $rule)` helper. The precedent is TicketService.php:227.
  - Humans are told through notifications. The Audit log page shows "System" when there is no user.
- **F5. Production trigger.**
  - Guard inside `Scheduler::run` (ADM-10), which covers cron, artisan and the simulator.
  - Keep the daily Vercel cron and the 5-minute piggyback run.
  - Add a free Supabase `pg_cron` + `pg_net` job every 5 minutes that calls `/api/v1/cron/run`, with the Bearer secret kept in Vault. This is a docs-only change in DEPLOYMENT.md §3 and DECISIONS.md, and it is a decision for you.
- **F6. Dashboard "Needs attention" (ADM-08).**
  - Each count is gated by the permission of its target list. Ticket counts go through `TicketService::visibleQuery`.
  - Each row has a deep link.
  - Holders of `manage settings` get a **Run due work now** button and see scheduler health on the card.
- **F7. Bulk-action pattern with no new bulk endpoints.**
  - `useBulkAction(ids, fn, {concurrency, isOk})` in `src/lib/adminHooks.js`: `Promise.allSettled` over the existing per-record endpoint. Concurrency is 1 for gateway calls. The `isOk` check catches 200 responses that carry `status: 'failed'`. It returns `{done, failed:[{reference, message}]}` and invalidates queries once.
  - `SelectCell` + `BulkBar` in `AdminUI.jsx`: a select-all on the current page, cleared on page or filter change. The bar wraps at 375 px and shows a `ConfirmDialog` with count and total, then a `Notice` reading "N done, M skipped: …".
  - Every row keeps its own authorization, 422 checks, `lockForUpdate` transaction and audit row (AuditWrites runs per request). It also avoids long serverless requests for sequential gateway calls. Staff routes are not throttled.
- **F8. Deep links.** `useFilters` (adminHooks.js:37-48) seeds its state from URL params whose keys exist in `initial`. `clear()` keeps the blank `initial`.
- **F9. Scheduled-job conventions.**
  - Jobs live in the module service's `runScheduled()`, registered in `Scheduler.php:52-54`. Add a `'logistics'` job. Platform jobs are private `Scheduler` methods.
  - Each record runs in its own `DB::transaction` with `lockForUpdate` and a re-check, wrapped in try/catch with `report()`.
  - At most 100 rows per job. Idempotency keys are stored and compared as strings (portable between SQLite and PostgreSQL).
  - Every new result key is added to `SchedulerTest.php:41`.
- **F10. Notifications.** Each new type gets an icon, tone and link in `NotificationBell.jsx:5-48`. Recipients are always `is_active = true`, and `WebhookService.php:154` is aligned to the same rule.

## 6. Phases

Each checkpoint runs `cd backend && php artisan test --compact`, `cd frontend && npm run lint && npm run build`, and `php artisan sfa:run-scheduled` (counts appear, and a second run gives 0). When routes or schemas change, it also runs `php artisan route:list --json --except-vendor > ../docs/.route-manifest.json && python scripts/build-openapi.py`, followed by a manual 375 px pass on the touched pages.

### Phase 0: Foundation

**0.1 Scheduler safety and settings fallback** (ADM-10, F1, F4) | S
- Backend: Scheduler.php (paused guard at the top of `run`); RunScheduler.php:22 (drop the duplicate check); CronController.php (200 `{paused:true}`); config/sfa.php:8 (delete the unused `cron_secret`); Setting.php:14 (config fallback); AuditLog model (`system()` helper); DEPLOYMENT.md §3 and DECISIONS.md (pg_cron recipe).
- Frontend: none.
- Accept: with `scheduler_enabled=false`, cron, artisan and the simulator leave a due payment unexpired and a subscription unrenewed. `Setting::valueFor('auto_assign')` returns the config default.
- Tests: IntegrationFoundationTest (cron paused, cron 401 kept), SchedulerTest paused case.

**0.2 Run state + Automation API + failed-job alert** (ADM-01, F3) | M
- Backend: new migration `add_last_result_to_scheduler_locks`; SchedulerLock cast; Scheduler.php (persist the result, `scheduler_job_failed` once per job per day); new AutomationController; routes/api.php staff group; build-openapi.py.
- Frontend: none yet.
- Accept: GET `/automation` returns enabled, running, last_run_at and last_result. POST runs the jobs and is idempotent; while the lock is held it returns `ran:false`.
- Tests: new AutomationTest (401; 403 for Finance, Support Agent and Analyst; 422 paused; happy path; shape), OpenApiTest.

**0.3 Settings > Automation tab** (ADM-02, F2) | M
- Frontend: Settings.jsx (tab via `useUrlState`, rule rows, "Run due work now"); src/lib/admin.js (`AUTOMATIONS` registry); remove the dead SLA fieldset (SUP-15; Settings.jsx:62, saved at :53).
- Backend: SettingsRequest.php and build-openapi.py:61 drop `sla_policies` from writable settings. The config default stays as the SlaPolicy fallback.
- Accept: every rule is listed with its control or "Always on" and its last count. Run now shows the result grid (copied from Simulator.jsx:141). Usable at 375 px.
- Tests: SettingsTest (PUT `sla_policies` is ignored or returns 422).

**0.4 Bulk pattern + URL-seeded filters** (F7, F8) | S
- Frontend: src/lib/adminHooks.js (`useBulkAction`, `useFilters` seeding); src/components/admin/AdminUI.jsx (`SelectCell`, `BulkBar`).
- Accept: selection clears on page or filter change. Partial failures are listed with their reasons. `/users?status=inactive` opens filtered and Clear resets it.
- Tests: lint and build.

**0.5 Dashboard "Needs attention"** (ADM-08, F6) | M
- Backend: ReportingService.php `dashboard()` adds `attention[]`:
  - failed deliveries, disabled endpoints and expiring tokens (`manage integrations`);
  - locked sign-ins (Admin);
  - refunds requested (`approve refunds`);
  - open risk reviews (`edit payments`, status pending or authorized);
  - unassigned and breached tickets (`view tickets`, via `visibleQuery`);
  - past_due subscriptions;
  - draft settlements;
  - unresolved reconciliation rows (status not `matched` and `resolved_at` null);
  - scheduler stale for more than 26 hours or a failed job (`manage settings`).
- Frontend: Dashboard.jsx section with skeleton, empty state ("All clear") and existing "Try again", plus a Run-now button for Admins.
- Accept: each role sees only its own counts, and zero counts are hidden.
- Tests: AnalyticsTest per role (Admin, Finance, Support Agent scoping, Logistics Coordinator, Analyst gets an empty list).

**Checkpoint 0:** full checkpoint, including the OpenAPI rebuild.

### Phase 1: Support

**1.1 Status follows replies + customer-reply alert + auto-claim** (SUP-1) | M
- Backend (TicketService.php):
  - Extract `applyTransition(Ticket $locked, string $status, ?User $actor, ?string $note)` from `transition()` (:117-138).
  - `message()` (:142-157) calls it in these cases:
    - a public staff reply on `open` or `reopened` → `in_progress`;
    - a requester reply on `waiting_on_customer` → `in_progress`.
  - A requester reply notifies the assignee with `ticket_customer_replied`.
  - A Support Agent's public reply on an unassigned ticket sets `assignee_id`.
  - `emit()` (:206-210) → `WebhookService::emitFor`.
- Frontend: guidance.js:5-10 text; NotificationBell type `ticket_customer_replied`.
- Accept:
  - A reply on an open ticket moves it to in_progress with a system note; an internal note does not.
  - A customer reply on a waiting ticket resumes the SLA and notifies the assignee once.
  - A Support Agent's first reply claims an unassigned ticket.
- Tests: SupportTest (cases above, the transition matrix still passes at SupportTest.php:93-107), SimulatorTest (simulator reply on waiting → in_progress).

**1.2 Reply and set status** (SUP-2) | S
- Backend:
  - `TicketMessageRequest` (:13) adds `status` (staff only; `waiting_on_customer` or `resolved`), prohibited for integrations and when `is_internal_note` is true.
  - `message()` applies the status in the same transaction, so an invalid state returns 422 and saves nothing.
  - build-openapi.py:106 adds the field to TicketMessageInput.
- Frontend: TicketDetail.jsx composer gets an "After sending" select; the button label follows the choice.
- Accept: reply + resolved returns 201 with `resolved_at` set. An invalid status returns 422 and stores no message.
- Tests: SupportTest (happy path, 422 closed, 422 for an integration, 422 on an internal note), OpenApiTest.

**1.3 Bulk claim / assign / status** (SUP-3) | S
- Frontend: Support.jsx (`SelectCell` column with `edit tickets`, `BulkBar`) calling the existing `/assign` and `/status` through F7. Non-Admins only get "Assign to me". The status options are the intersection of transitions in `lib/support.js` across the selected rows.
- Accept: 3 unassigned tickets are claimed in one action and each gets its own audit row and notification. Invalid rows are reported as skipped.
- Tests: lint and build (no new endpoint).

**1.4 Self-healing unassigned queue** (SUP-4, SUP-5) | S
- Backend:
  - `TicketService::releaseAssignments(User): int`, called in the `UserController::update` transaction when the user becomes inactive or loses Admin/Support Agent. Use the `$roles` array, not `hasAnyRole` after sync. The count goes into `audit.new_values`.
  - `leastLoadedAgent()` is extracted from :63-64.
  - The `runScheduled` sweep runs when `auto_assign` is on, per-ticket try/catch, result key `tickets_auto_assigned`.
- Frontend: Users.jsx:46 notice text; Settings help text for `auto_assign`.
- Accept: deactivating an agent releases only their open tickets. With `auto_assign` on, the sweep assigns them on the next run, and a second run assigns 0.
- Tests: AdminAuthorizationTest or UsersTest; SupportTest; SchedulerTest key.

**1.5 Alerts and close clock** (SUP-6, SUP-12, SUP-8) | S
- Backend (TicketService.php):
  - :220 adds active Admins on breach.
  - `csat()` notifies `ticket_low_csat` inside a transaction only when the score drops to 2 or below.
  - The auto-close query skips tickets with a customer or seller message in the last 7 days, per-ticket try/catch. Seven days stays as the spec requires.
- Frontend: NotificationBell `ticket_low_csat`; guidance.js:8.
- Accept: a breach notifies the assignee and the Admins exactly once. A re-submitted low score does not re-alert. A ticket with a customer reply 2 days after resolve stays resolved.
- Tests: SupportTest, SchedulerTest.

**1.6 Support rules, default off** (SUP-9, SUP-7) | S
- Backend:
  - `ticket_waiting_auto_resolve_days` (0-90, 0 = off) sweep. Skip tickets with a customer message after `resolution_paused_at`. Lock and re-check, then `applyTransition` in_progress → resolved with a note and the `auto_resolved` tag, plus `AuditLog::system`. Result key `tickets_auto_resolved`.
  - `ticket_auto_acknowledge` (bool, false): a public `system` message in `create()` using `$hours` from :60. It does not set `first_responded_at`.
  - Add both keys to config, SettingsRequest and OpenAPI.
- Frontend: Automation tab rows; TicketDetail.jsx:48 labels a public system message "Automatic reply".
- Accept: with days=5, a ticket paused 6 days with no reply is resolved; one with a reply is left alone. The acknowledgement goes only to the owning client and SLA stays honest.
- Tests: SupportTest, SettingsTest (422), SchedulerTest; the SupportTest.php:194 count still passes with the default.

**1.7 Auto-link on create** (SUP-10) | S
- Backend: `TicketService::create` before `validateLinks` (:59). Link the shipment only for `delivery` or `refund_return` when the order has exactly one shipment (scoped to the seller for seller requesters). Link the payment only for `payment` or `refund_return` when there is exactly one payment.
- Frontend: SupportForms.jsx preselects under the same gate.
- Accept: the category gate is respected, and cross-source links still return 422.
- Tests: SupportTest; update DemoSeedTest counts if they change.

**1.8 Agent assist** (SUP-11, SUP-14) | S
- Frontend: TicketDetail.jsx shows "Suggested" chips: canned responses plus published KB articles via `fetchChoices`, ranked by naive word overlap (marked with a ponytail comment). The RefundForm amount is prefilled with the available balance (:85).
- Accept: chips are hidden when nothing matches, and they wrap at 375 px.
- Tests: lint and build.

**Checkpoint 1:** full checkpoint plus the OpenAPI rebuild (TicketMessageInput, Settings).

### Phase 2: Payments (staff side)

**2.1 One-click risk decisions** (PAY-3, PAY-4) | M
- Backend:
  - `PaymentService::clearRisk(Payment)`: lock Order then Payment; return 422 unless pending or authorized; set risk to none; `captureLocked` when authorized, not COD and the order is not cancelled; COD goes through 2.2.
  - `blockRisk(Payment)` in one transaction: set blocked, then call the existing `cancel()` for non-COD payments. A gateway refusal returns 422 and rolls back. `cancel()` itself is unchanged.
  - RiskReviewController.php:10-13 delegates, and `index` defaults to pending/authorized.
  - Update the OpenAPI descriptions.
- Frontend: Payments.jsx:52 buttons read "Clear and capture" and "Block and cancel". The success handler shows a failure Notice with `failure_reason` when the response status is `failed`. Update guidance.js:70-71, navigation.js:20 and Simulator.jsx:32.
- Accept:
  - Clear on an authorized review payment returns `captured` with a balanced ledger.
  - Block on a non-COD payment returns `cancelled` and sends a `payment.cancelled` webhook to the owning client only.
  - Captured, expired and cancelled rows leave the queue.
- Tests: rewrite PaymentsTest.php:60-66 and AdminAuthorizationTest.php:209-210; add 401/403/422 cases, a gateway refusal returning 422 with risk unchanged, and a cancelled order that is not captured.

**2.2 COD risk hold, not delivery deadlock** (PAY-5, LOG-10) | S
- Backend:
  - LogisticsService shipment create (:82-85): return 422 when the COD payment's risk is not `none`.
  - New `PaymentService::captureCodWhenReady(Payment): bool` with a single readiness rule: all COD shipments delivered with cash and proof, and the sum equals the payment amount. When risk is not `none`, notify `cod_risk_hold` instead of throwing. When the sums mismatch, notify `cod_amount_mismatch`.
  - Reused by `LogisticsService::captureCodIfCollected` (:129-136, keeping its locks), `PaymentController::cod` and `clearRisk`.
  - DECISIONS.md: cash ledger posting waits for the risk clear.
- Frontend: NotificationBell routes both types to the risk tab or the payment.
- Accept: a COD order above the threshold can be marked delivered (200) and Finance gets one alert. Clear then captures it with a balanced ledger.
- Tests: LogisticsTest (proof 200, ship guard 422, existing COD auto-capture still passes at LogisticsTest.php:85-97), PaymentsTest (`/cod-collected` 422 and 200).

**2.3 Auto-capture later-authorized + invoice handoff** (PAY-1, BIL-4) | M
- Backend:
  - In `PaymentService::runScheduled`, before the expiry loop and when `auto_capture_authorized` is on: select authorized payments with risk `none`, not COD, whose order is not cancelled (or which belong to an invoice). Each runs in its own transaction: lock Order then Payment, re-check, `captureLocked`, try/catch. Result keys `payments_auto_captured` and `payments_auto_capture_errors`.
  - `BillingService::runScheduled` sweep: open invoices with a captured payment. If the subscription is not cancelled, call `charge()`. If it is cancelled, mark the invoice paid without reactivating the subscription and notify `subscription_payment_after_cancel` once. Result key `invoices_reconciled`.
- Frontend: guidance.js:14 and :48 text; Automation row.
- Accept:
  - A sandbox "98" payment that the gateway authorizes later is captured on the next run with ledger and commissions, and a second run captures 0.
  - Payments on cancelled orders, under review, or COD are never captured.
  - The invoice is paid in the same run.
- Tests: SchedulerTest, BillingTest, SettingsTest.

**2.4 Finance reminders + refund feedback to tickets** (PAY-9, SUP-13) | S
- Backend:
  - `PaymentService::remind()` in `runScheduled` (`finance_reminder_hours`, default 4, 0 = off) sends `refund_approval_overdue`, `risk_review_overdue` (pending/authorized, including COD holds) and `payment_disputed`, deduped per record.
  - `approve()` and `reject()` add an internal `system` TicketMessage on `refunds.ticket_id` and notify the assignee.
  - `reject()` calls `notifyRequester(..., 'refund_rejected')`.
  - `processEvent` is untouched.
- Frontend: NotificationBell types.
- Accept: one reminder per approver per overdue refund; the linked ticket shows "Refund RFD-x completed / failed / rejected: reason".
- Tests: SchedulerTest (idempotent; no Support Agents or inactive users), PaymentsTest.

**2.5 Approve selected refunds** (PAY-7) | S
- Frontend: Payments.jsx refunds tab: `SelectCell` where `canReviewRefund(row)` is true, "Approve selected (N) · ₱total", F7 with concurrency 1 and `isOk: status === 'completed'`.
- Accept: gateway failures and self-requested refunds are reported as skipped, and each approval keeps its own audit row.
- Tests: lint and build.

**2.6 Reconciliation** (PAY-10, PAY-12) | S
- Backend:
  - `ReconciliationService::import` (:27-29): lock earlier unresolved `missing_in_report` items whose `payment_id` is matched in this run, and resolve them with `resolved_by = null` and a note.
  - `resolve()` (:33-36) gets a transaction, `lockForUpdate` and a re-check, so a second resolve returns 422.
- Frontend: Payments.jsx:58 adds an "Auto-resolved" pill and "Resolve all (N)", which uses one NoteForm and then F7.
- Accept: importing file A then file B that contains the missing payment resolves A's row. An amount mismatch never auto-resolves.
- Tests: PaymentsTest. The demo needs an overlapping-period CSV (SimulatorController.php:219-221).

**2.7 Risk auto-clear rule, default off** (PAY-6) | S
- Backend: a `runScheduled` sweep, run before the expiry loop. It is enabled when `risk_auto_clear_min_captured` is above 0. A payment qualifies when it is within `risk_auto_clear_max_amount`, the customer has no disputed or lost payments, and recent failures are within the existing threshold. It calls `clearRisk` plus `AuditLog::system`, with try/catch. Result key `risk_auto_cleared`.
- Frontend: Automation rows; amounts via `pesosToCentavos`.
- Accept: a trusted customer under the cap is captured, and anyone else stays in review.
- Tests: SchedulerTest (on, off, disputed customer, idempotent), SettingsTest.

**Checkpoint 2:** full checkpoint plus the OpenAPI rebuild (risk descriptions, Settings).

### Phase 3: Logistics

**3.1 Bulk forward status** (LOG-3) | S
- Frontend: Logistics.jsx gets a checkbox column and a `BulkBar`. Allowed statuses are `ready_for_pickup`, `picked_up`, `in_transit`, `out_for_delivery` and `returned_to_sender`, intersected with `lib/logistics.js` transitions. It calls the existing event endpoint through F7 with an optional location and note. Cancel, failed attempt and delivered stay per row.
- Accept: 30 parcels move in one action; invalid rows are skipped with a reason.
- Tests: lint and build.

**3.2 Printable labels** (LOG-4) | M
- Frontend: new src/pages/ShipmentLabels.jsx at `/logistics/labels?ids=`, outside MainLayout like PaymentReceipt (App.jsx:63), gated by `view shipments`. It uses `useQueries` on the existing show endpoint and adds a Print button. The bulk bar gets "Print labels" next to a separate "Mark ready for pickup".
- Accept: labels show the reference, the tracking number in large monospace, courier, origin, destination and contact, COD and weight. It has loading, error and "Try again" states.
- Tests: lint and build.

**3.3 Recommended quote, courier optional** (LOG-1) | M
- Backend:
  - `LogisticsService::quotes` marks the first (cheapest) quote `recommended`.
  - `create()` (:80-86) uses it when `courier_id` is absent and writes `$quote` courier and service.
  - LogisticsRequest.php:94 makes `courier_id` and `service_type` nullable and `required_with` each other.
  - Update build-openapi.py:118 and docs/INTEGRATION.md.
- Frontend: ShipmentForm (LogisticsForms.jsx:38-47) loads quotes through `useQuery` once the inputs are valid. It preselects the recommended quote, or the current one on edit. Remove the blocker at :47 and the rate reset at :40.
- Accept: creating without a courier picks the cheapest rate, an explicit courier is honoured, and a tracking-only edit saves without re-quoting.
- Tests: LogisticsTest (staff and integration create scoped to source_system; no rate returns 422), OpenApiTest.

**3.4 Awaiting booking** (LOG-2) | M
- Backend:
  - New `GET /logistics/awaiting-booking`, registered before `/logistics/{kind}`, with `create shipments`, through `ListResponse` (pagination, search, summary).
  - An order qualifies when it is not cancelled, has a captured payment or a pending COD payment, and has a seller pair without a shipment.
  - Each pair carries suggested fields: warehouse, COD amount (single-seller orders only) and the customer's last shipment address.
  - Add an `awaiting_booking` summary count and an attention item.
- Frontend: Logistics.jsx gets a SummaryCard and a list, and the Book button opens ShipmentForm with a `prefill`.
- Accept: refunded, cancelled, unpaid and already-shipped orders are excluded. Support Agents and Finance get 403. With 3.3 in place, booking only needs weight and dimensions.
- Tests: LogisticsTest (401, 403, 422, shape, exclusions), OpenApiTest.

**3.5 Overdue and stuck detection** (LOG-5) | M
- Backend:
  - New migration adding `shipments.overdue_notified_at` and `stuck_notified_at`.
  - `LogisticsService::runScheduled` (registered as `'logistics'` in Scheduler.php:52-54), using `business_timezone` and `logistics_stuck_hours` (48, 0 = off). It locks, re-checks and stamps rows, then sends one `shipments_attention` digest to `edit shipments`.
  - `LogisticsController::index` gets a live `overdue` filter and summary count, plus an attention item.
- Frontend: "Overdue" card and filter, red "Late" pill, Automation row.
- Accept: each overdue shipment is flagged once. Stuck shipments re-alert only after a newer event. Final statuses are never flagged.
- Tests: SchedulerTest keys, LogisticsTest (filter, scoping, timezone edge), OpenApiTest.

**3.6 Auto delivery ticket** (LOG-6) | M
- Backend:
  - `TicketService::create` accepts a null actor (system). The first message's `author_type` is `system`.
  - `Ticket::CHANNELS` adds `system`, and TicketRequest.php:19 rejects it with `notIn`.
  - LogisticsService.php after :111, when `logistics_auto_ticket` is on (default true): `DB::afterCommit(fn () => rescue(fn () => $this->deliveryTicket($id)))`. It locks the shipment, then either adds an internal note (and sets priority high when attempts reach 3) to the open ticket, or creates a ticket with category `delivery` and tags `['auto','delivery']`. On `delivered` it adds an internal note.
  - DECISIONS.md entry.
- Frontend: ShipmentDetail "Related ticket" link; Automation row.
- Accept: the first failure creates exactly one linked ticket. Later failures add notes. A ticket error never rolls back the shipment update.
- Tests: LogisticsTest (staff and courier paths, concurrency gives one ticket, setting off), SupportTest (channel `system` returns 422).

**3.7 Returns and hygiene** (LOG-11, LOG-12) | S
- Backend (LogisticsService.php):
  - On `returned_to_sender` or `cancelled` with no active COD shipment left, cancel the pending COD payment through the existing `PaymentService::cancel` (CodGateway is local). A prepaid order gets a `parcel_returned` notification to `edit payments`.
  - `notify()` (:146) goes to `edit shipments`.
  - `emit()` (:138-142) → `emitFor`.
  - `ready_for_pickup → picked_up` requires a tracking number (422).
  - Bump the `sfa.api.version` cache key after a successful courier webhook and after scheduler flags.
- Frontend: "No tracking" pill (Logistics.jsx:77).
- Accept: a returned COD order leaves no pending payment, and Support Agents no longer get shipment bells.
- Tests: LogisticsTest; AdminAuthorizationTest.php:213 still passes.

**3.8 Order cancel cancels shipments** (LOG-7, needs decision Q5) | S
- Backend: `LogisticsService::cancelForOrder`, called first in MirrorController::cancel (:53, before the payments loop), in SimulatorController::cancel (:113-130), and in `MirrorController::order` when the status becomes `cancelled`. It returns 422 when any shipment is already with the courier. Update docs/INTEGRATION.md:34 and the partner README.
- Accept: pending shipments are cancelled with a webhook, and an in-transit shipment returns 422 with the order unchanged.
- Tests: IntegrationFoundation / LogisticsTest.

**3.9 Courier gap-fill, default off** (LOG-8) | S
- Backend: CourierWebhookController.php:147-161 infers the intermediate forward steps when `logistics_courier_fill_gaps` is on. A rejected event is caught outside the transaction, notifies `courier_event_rejected` to `edit shipments`, and is rethrown. Update LOGISTICS.md:13 and the partner README:113.
- Accept: pending + picked_up records an inferred ready_for_pickup. It never infers delivered. A rejection always alerts.
- Tests: LogisticsTest.

**Checkpoint 3:** full checkpoint plus migration and OpenAPI rebuild (awaiting-booking, ShipmentInput, filters, Settings).

### Phase 4: Billing

**4.1 Settlement run only where there are sales** (BIL-1) | S
- Backend: `BillingService::generate(..., bool $scheduled = false)` restricts sellers to those with unsettled entries in the period. When scheduled it skips per-settlement notifications and sends one `settlement_ready` digest with the period. Result key `settlements_generated` (keep `settlements_checked`).
- Frontend:
  - NotificationBell links `settlement_ready` with a period to `/subscriptions?tab=settlements`.
  - Subscriptions.jsx `useUrlState` adds `status`.
  - guidance.js:62-63 text covers the void rebuild rule and says statements reach sellers automatically through BillingIntegrationController.
- Accept: a seller without entries gets no draft; one bell per Finance user per run.
- Tests: BillingTest, SchedulerTest; SimulatorTest.php:100 manual generate is unchanged.

**4.2 Invoice and dunning hygiene** (BIL-5, BIL-6, BIL-10) | S
- Backend (BillingService.php):
  - `closeOpenInvoices(sub, status)` is called from an immediate `cancel()` (:59-68) and from the dunning cancel (:102). The invoice becomes uncollectible if the subscription was past_due, otherwise void. Invoices with an in-flight payment are skipped.
  - `charge()` returns 422 on a cancelled subscription, placed after :88.
  - The dunning cancel notifies `subscription_cancelled_unpaid`.
  - A gateway pending or authorized result leaves the invoice open with no `retry_count++`, webhook or notification, and `next_retry_at` set to now + 31 minutes.
- Frontend: hide Charge for cancelled subscriptions (Subscriptions.jsx:42); cancel dialog text (BillingForms.jsx:31).
- Accept: cancelling a past_due subscription writes off its invoice. Charge on that invoice returns 422, and Charge on a paid invoice still returns 200.
- Tests: BillingTest (including the existing :117 and :179 cases).

**4.3 Finalize and pay selected settlements** (BIL-3) | S
- Backend: the pay request `method` becomes nullable and defaults to the seller's method (`account_number` → bank_transfer, `number` → gcash, none → 422). Update OpenAPI.
- Frontend: Subscriptions.jsx settlements tab:
  - "Finalize N drafts (₱sum)" through F7.
  - "Record payouts for N" with a required "I confirm these transfers were sent" checkbox, also through F7.
- Accept: void rows and sellers without a destination are skipped with a reason. A repeated click adds no ledger rows.
- Tests: BillingTest (method default, 422 with no destination), OpenApiTest.

**4.4 Auto-finalize, default off** (BIL-2) | S
- Backend: `runScheduled` uses `settlement_auto_finalize_days` (0-30). It only touches scheduler drafts (`created_by` null) with net ≥ 0, older than N days. It excludes drafts rebuilt after a void, so Void works as a hold, and drafts with disputed payments or pending refunds. It calls `settlementAction('finalize')` plus `AuditLog::system`, with try/catch. Result key `settlements_auto_finalized`. The days value is exposed in `/billing/options`.
- Frontend: Automation row; the draft modal shows "Finalizes automatically on {date}. Press Void to hold it."
- Accept: it finalizes once with the gateway_fee ledger pair and webhook. Held, negative and disputed drafts stay as drafts.
- Tests: BillingTest, SettingsTest.

**4.5 Catch-up periods** (BIL-11) | S
- Backend: `runScheduled` also generates, as scheduled, the last 8 periods that still have unsettled entries.
- Accept: a voided older draft is rebuilt automatically, and a second run creates nothing.
- Tests: BillingTest.

**Checkpoint 4:** full checkpoint plus the OpenAPI rebuild (pay request, Settings).

### Phase 5: Platform

**5.1 Resend failed** (ADM-03) | S
- Backend:
  - `WebhookService::requeueFailed(?int $endpointId)` does a conditional UPDATE on at most 500 failed deliveries whose endpoint and client are active.
  - `POST /webhook-deliveries/resend-failed`, registered before routes/api.php:59.
  - It does not call the scheduler inline.
- Frontend: Integrations.jsx "Resend failed (N)"; for a disabled endpoint, "Enable and resend failed" (PUT, then POST).
- Accept: only failed rows on active endpoints are requeued, and a second call queues 0.
- Tests: IntegrationsTest (401, 403, 422, happy path), OpenApiTest.

**5.2 Endpoint auto-recovery** (ADM-04) | M
- Backend:
  - `WebhookEndpointController::update` clears `disabled_at` on an explicit disable (:85-88).
  - `WebhookService::recoverDisabled()` sends at most one signed `webhook.test` probe per run, through a private `post()` that keeps WebhookUrlGuard, with 3s connect and 5s total timeouts. On 2xx it locks and re-enables the endpoint, calls `requeueFailed`, and notifies active Admins with `webhook_endpoint_recovered`. Otherwise it touches the endpoint so the next probe waits at least 1 hour.
  - `webhook_auto_recover` (default true).
  - WebhookService.php:154 notifies active Admins only.
- Accept: an endpoint that was auto-disabled and now answers 2xx is re-enabled and its failures requeued. An endpoint disabled by hand is never probed.
- Tests: SchedulerTest with `Http::fake` (200, 500, manual disable, setting off).

**5.3 Token expiry reminders** (ADM-05) | S
- Backend: a private Scheduler job using `token_expiry_reminder_days` (7, 0 = off). It sends `integration_token_expiring` to active `manage integrations` users, deduped by `data.token_id` as a string. No pruning.
- Frontend: tokens modal shows "Expires in N days" or "Expired"; bell link.
- Accept: one reminder per user per token; Finance gets none.
- Tests: SchedulerTest.

**5.4 Lock alert + filter** (ADM-07) | S
- Backend:
  - AuthController adds `lockAlert()` at both lock points (:56, :140). It writes the missing `account_locked` audit on the OTP path and sends `sign_in_locked` to active Admins on the 24-hour lock.
  - `UserController::index` accepts `status=locked`.
- Frontend: Users.jsx filter option; bell link to `/users?status=locked` (via F8).
- Accept: the 8th failure alerts once and shorter locks don't. Document recovery when the only Admin is the locked account.
- Tests: AuthTest, UsersTest.

**5.5 Notification hygiene** (ADM-09) | M
- Backend: a private Scheduler job with a type → `[model, key, stillActionable]` map. It covers every new type from Phases 1-5 and checks risk notifications against status pending/authorized. It batches one `whereIn` per type, marks rows read, then prunes read rows older than `notification_retention_days` (90, 0 = keep).
- Frontend: Automation row.
- Accept: when one Finance user approves, every copy of that refund alert is cleared. Unread notifications are never deleted.
- Tests: SchedulerTest.

**Checkpoint 5:** full checkpoint plus the OpenAPI rebuild.

### Phase 6: Policy rules (only after your decisions)

| Task | Backend / Frontend | Accept | Size |
|---|---|---|---|
| 6.1 PAY-8 refund auto-approve | Extract `approveLocked()`. `requestRefund(..., bool $allowAutoApprove = false)`: only `RefundController::store` passes true, so `createIntegration` is untouched. Lock the requester row for the daily limit. Approve with `approved_by = null`, null-safe ledger links and `AuditLog::system`. Cap and reasons exposed in `/payment-options`, with a hint in RefundForm and TicketDetail. DECISIONS.md amends SPECIFICATION.md:229 | Under the cap: completed with a balanced ledger. Over cap, wrong reason or daily limit: stays requested. Integration refunds are never auto-approved | M |
| 6.2 LOG-9 courier POD (prepaid only) | CourierWebhookController adds `recipient_name` and `proof_photo` (strict base64, about 2 MB cap, image MIME checked with finfo), then `AttachmentService::store($file, $shipment, null)`. Requires `out_for_delivery` and `cod_amount === 0`. OpenAPI :121; DECISIONS.md | COD still returns 422; setting off still returns 422 | M |
| 6.3 BIL-12 negative carry-forward | New migration `settlements.recovered_by_settlement_id`. `generate()` sets `adjustments_amount` from unrecovered negative finalized settlements under the seller lock (no new ledger pair) | Demo settlement #8 (-310) is netted from the seller's next settlement | M |
| 6.4 BIL-7 free-plan auto-subscribe | Scheduler sweep for sellers with no subscription when `default_plan_id` is set. The plan must be active with both prices 0 and `commission_rate_bp` null (SettingsRequest `after()`). MirrorController is not touched | Each new seller gets one subscription; the webhook goes only to the owner | M |
| 6.5 PAY-2 review hold | `risk_review_hold_hours` (0 = today, max 168). Extend `expires_at` instead of skipping. DECISIONS.md amends SPECIFICATION.md:207 | Default keeps PaymentsTest.php:65 green | S |

**Checkpoint 6:** full checkpoint plus migrations and OpenAPI.

**Totals:** Phases 0-5 have 35 tasks (24 S, 11 M) and 4 new endpoints (`GET /automation`, `POST /automation/run`, `POST /webhook-deliveries/resend-failed`, `GET /logistics/awaiting-booking`). Phase 6 has 5 optional tasks.

## 7. Risks

| Risk | Where | Mitigation |
|---|---|---|
| Money moves without a click | PAY-1, PAY-6, BIL-2, PAY-8 | Rules are off by default; PAY-1 needs your yes. Cancelled-order, dispute and pending-refund exclusions. Void works as a hold. Lock + re-check per record. `AuditLog::system` row for every action |
| Automations look idle in production | All scheduled items | Today the scheduler runs only from the daily cron and while a staff tab is visible. Add a 5-minute pg_cron (Q1); "Run due work now"; UI copy says "within minutes" |
| Scheduler lease overrun (5 min) | Scheduler.php:25 | 100 rows per job, 1 endpoint probe with a 5s timeout, per-record try/catch, failed-job alert |
| Data leaks across roles | Notifications, attention counts | Shipment bells go to `edit shipments`; counts go through `visibleQuery` and permissions; per-role tests |
| Bulk partial failure | F7 | Per-row state machine and 422; result list with reasons; confirm dialog with count and total |
| Spec drift | SPEC :172, :207, :229, :276; DECISIONS.md:66, :74 | Spec-fixed behaviour is not made switchable. DECISIONS.md entries for PAY-5, PAY-8, PAY-2, LOG-6, LOG-9 and notification retention |
| Collision with your payment-integration work | PaymentService shared methods, MirrorController | Only call `captureLocked`, `cancel` and `requestRefund` (defaulted parameter). No edits to `*Integration`, Gateways or `processEvent`. LOG-7 is gated on Q5 |
| Cross-module edits | LogisticsService (PAY-5, LOG-*), TicketService (LOG-6) | Phase order: Payments edits LogisticsService before Phase 3; TicketService extraction happens in 1.1 |
| Notification noise | Settlements, shipments, reminders | Digests, once-per-record dedupe, ADM-09 auto-clear |
| Customer-facing automatic text or resolution | SUP-7, SUP-9 | Off by default; `auto_resolved` tag so reports can filter it; the acknowledgement is not counted as the first response |
| Demo data or test counts shift | SUP-10, BIL-1 | Update DemoSeedTest and SchedulerTest expectations in the same task |

## Decisions for you

1. **Production defaults and cadence.** May "auto" items ship ON and "rule" items OFF in production, and may we add the free Supabase pg_cron trigger every 5 minutes? Without it, automations run only while staff are online and at midnight.
2. **PAY-1 auto-capture.** Should payments that the gateway authorizes later be captured automatically (default ON)? This is the same policy `create()` already applies (PaymentService.php:42-44).
3. **PAY-8 refund auto-approve.** Build it, which relaxes the second-approver rule in SPECIFICATION.md:229? If yes, the suggested defaults are a cap of ₱500, a per-requester daily limit of ₱2,000, one auto-approval per payment, reasons chosen by you, and OFF until enabled.
4. **BIL-2 auto-finalize.** Keep it at 0 (off), or default to 2 days (the demo seeder could set 2)?
5. **LOG-7.** May we edit `MirrorController` (order cancel and upsert, which lives in `routes/payments-integrations.php` next to your payment work)? It adds a new 422 to the order-cancel contract when a shipment is already with the courier.

**Approval:** reply "approve" (optionally with answers to 1-5, or the phases to skip). Building starts with Phase 0.
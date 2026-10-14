# Decisions

Choices made where the specification left room, and the reasons. Newest operational facts are at the end.

## Money

- **Centavo storage.** Every amount is a `bigint` number of centavos in a column ending in `_amount`, with a
  `currency` column fixed to `PHP`. PHP never uses floats for money; arithmetic that can exceed 64 bits or needs
  ratios goes through `bcmath` (`App\Support\Money`). The SPA converts peso input to centavos by string parsing and
  formats with `Intl.NumberFormat('en-PH', { currency: 'PHP' })`.
- **Rounding.** One half-up rounding to the nearest centavo, once, at the end of each calculation
  (`Money::halfUp(amount, numerator, denominator)`): commissions (`gross × rate_bp ÷ 10 000`), proportional refund
  reversals, upgrade proration, annual-plan MRR (`annual ÷ 12`) and COD fees. Example: ₱333.33 × 7.5 % = ₱24.99975 → ₱25.00.
- **Percentages** are basis points (`rate_bp`, 1 % = 100).
- **Ledger.** Append-only (`LedgerEntry` refuses updates and deletes). Every business event writes one balanced
  debit/credit pair sharing an `event_id`; zero-amount events write nothing. Corrections are new entries.
- **Gateway fees.** A payment's `fee_amount` is deducted from seller settlements and posted to the ledger
  (`gateway_fee`, seller payable → gateway clearing) **when the settlement is finalized**, so it is posted exactly once
  and a voided draft never needs a reversal. When one payment covers several sellers, shares use cumulative rounding
  (ordered by seller id: each seller gets the difference of the rounded running totals), so the shares always add up
  to the fee portion of the items and never exceed the fee. Shipping is not allocated to sellers.
- **Subscription fees.** Sellers pay subscription invoices directly (`subscription_charge`, gateway clearing →
  platform revenue), so `subscription_fees_amount` on settlements is `0`: a fee is never also deducted from payouts.
- **Commission rules.** Precedence seller → plan → category → global; within one scope the latest `effective_from`
  wins. A plan's `commission_rate_bp` is used when the plan has no plan-scoped rule. **Global rules are open-ended**:
  creating a later global rule ends the current one at the new start time, a global rule with an end date is rejected,
  and a change that would leave zero or two active global rules is rejected, so exactly one is always active.
- **Settlements.** Generated per seller and period, idempotently (the same seller and period returns the existing
  open settlement). Voiding a draft releases its commission entries, and the period can then be generated again; a
  partial unique index (`status <> 'void'`) enforces one open settlement per seller and period.
- **Scheduled settlements and payouts.** Scheduled generation builds drafts only for sellers with unsettled commission
  entries in the period and sends Finance one `settlement_ready` digest per run (`period_start`, `period_end`,
  `count`); a manual Generate still covers every requested seller and notifies per settlement. Each run covers the
  last complete period and catches up the seven before it, so a voided draft or a late entry in any of them is rebuilt
  by the next run: Void corrects a statement rather than skipping it. With `settlement_auto_finalize_days` above 0
  (default 0, off), scheduled work finalizes its own drafts once they are that many days old and net payable is zero
  or more, through the same finalize as the button, with a system audit row. A draft rebuilt after a void is never
  auto-finalized, so Void is how Finance holds a period; drafts with a disputed or lost payment or a refund still in
  flight also wait for Finance. A payout's method
  defaults to the seller's stored destination (bank account → `bank_transfer`, wallet number → `gcash`); with
  nothing stored the request returns 422. Finance can finalize or record payouts for selected settlements; each row
  still goes through its own endpoint and checks.
- **Negative settlements carried forward (BIL-12, Phase 6, off by default).** With `settlement_carry_forward` on,
  generating a settlement (scheduled or Generate) for a seller with sales in the period adds the net payable of the
  seller's finalized negative settlements not yet recovered to `adjustments_amount`, under the seller lock, and stamps
  each of them with `recovered_by_settlement_id` (new nullable column; the foreign key exists on PostgreSQL only,
  because on SQLite it would rebuild the table and lose the partial one-open-settlement index). The debt is already in
  seller payable, so no ledger entries are written; the smaller payout settles it. A settlement without sales carries
  nothing, voiding the draft releases the debt for the next one, and a draft that stays negative is finalized by
  Finance and carried again. The "Payable to sellers" card leaves recovered settlements out so a debt is not counted
  twice. When off, a negative settlement stays finalized and is recovered outside SFA, as before.
- **Free plan for new sellers (BIL-7, Phase 6, off by default).** With `default_plan_id` set, each scheduled run
  subscribes up to 100 sellers that never had any subscription (a cancelled one counts) to that plan, monthly, through
  the normal subscribe (zero invoice marked paid, `subscription.created` and `invoice.paid` to the seller's own client
  only), with an `AuditLog::system` row (`subscription_auto_subscribe`) and the count in `subscriptions_auto_created`.
  `PUT /settings` accepts only an active plan with both prices 0 and no `commission_rate_bp` (422 otherwise), and
  each run re-checks it, so a plan that stops qualifying is skipped. Sellers created by the storefront are not
  subscribed at creation; `MirrorController` is unchanged.

## Time

- **Business time zone** `Asia/Manila` (setting `business_timezone`, default from `SFA_TIMEZONE`). Instants are stored
  in UTC. Calendar dates — report filters, settlement periods, reference years, ETAs, invoice due dates, the latest
  allowed settlement end date — are computed in the business time zone.
- **Subscription periods** are calendar months or years in the business time zone (a period starting
  1 Oct 00:00 Manila ends 1 Nov 00:00 Manila). Upgrade proration counts business-time-zone calendar days:
  `(new − old) × remaining days ÷ days in period`, half up. Downgrades apply at the next renewal.
- **Dunning.** A failed charge makes the subscription `past_due` and retries 1, 3 and 7 days after the invoice due
  date; after the third failed retry the invoice becomes `uncollectible` and the subscription `cancelled`. An invoice
  never gets a second payment while one is still awaiting the gateway, and a paid invoice is never charged again.
- **Invoice hygiene.** A charge left pending (or authorized) at the gateway is not a failure: the invoice stays open,
  `retry_count` is unchanged, no past-due webhook or alert is sent, and the charge is retried 31 minutes later (after
  the 30-minute payment window). The dunning cancel notifies Finance (`subscription_cancelled_unpaid`). Cancelling,
  immediately or through dunning, closes the subscription's open invoices: `uncollectible` when it was past due,
  otherwise `void`; an invoice whose payment is pending, authorized or captured is left open for that payment.
  Charging an invoice of a cancelled or expired subscription returns 422, so a charge never reactivates it silently.

## Payments

- **Sandbox gateway behaviour.** `SandboxGateway` is deterministic: amounts ending in 99 centavos are declined
  ("Card declined"), 98 stay pending until a signed webhook (`SFA_SANDBOX_WEBHOOK_SECRET`), everything else is
  authorized and captured. It reports no gateway fee. Admins can simulate gateway events from the payment modal.
  `CodGateway` never calls out; COD is captured when every COD shipment of the order is delivered with cash collected
  and their COD amounts equal the payment.
- **No real provider drivers.** Real gateway (and courier) drivers were not written because their endpoints, fields
  and signature schemes could not be verified against official documentation from this environment. Payments go
  through the `PaymentGateway` interface, so a verified driver can be added without touching the services. The
  payments integration surface is owned by the project owner.
- **Demo gateway fees.** The demo seeder records a clearly synthetic 2.5 % fee on captured sandbox order payments so
  settlements show the deduction. Demo pending and authorized sandbox payments expire 30 minutes after seeding,
  exactly like real ones; COD payments stay pending until delivery.
- **One-click risk decisions.** "Clear and capture" (`PaymentService::clearRisk`) sets the risk to none and captures an
  authorized non-COD payment in the same transaction, unless its order was cancelled. "Block and cancel"
  (`blockRisk`) cancels a non-COD payment at the gateway; a gateway refusal returns 422 and leaves the risk unchanged.
  A blocked COD payment stays pending, because the parcel may already be on its way. The Risk review queue lists
  pending and authorized payments only.
- **COD risk hold, not a delivery deadlock.** A COD shipment cannot be created while its payment is under review or
  blocked. If a parcel is delivered while the payment is held, the delivery is still recorded and Finance gets one
  `cod_risk_hold` alert. The cash ledger posting waits until the risk is cleared, and Clear then captures it. COD
  amounts that do not add up to the payment send one `cod_amount_mismatch` alert and capture nothing.
  `PaymentService::captureCodWhenReady` holds the single readiness rule for delivery, "Record COD collected" and Clear.
- **Late authorizations are captured automatically (approved, on by default).** Scheduled work captures a payment the
  gateway authorizes after creation, which is the policy `create()` already applies at once. It skips COD, risk
  reviews and cancelled orders, locks the order then the payment and re-checks, and writes an `AuditLog::system` row
  (`auto_capture_authorized`). The `auto_capture_authorized` setting turns it off. In the same run, an open invoice
  whose payment is now captured is paid through `charge()`. When its subscription was cancelled in the meantime, the
  invoice is marked paid without reactivating the subscription, and Finance gets one
  `subscription_payment_after_cancel` alert to resume it or refund the payment.
- **Finance reminders.** `finance_reminder_hours` (default 4, 0 = off) sends one reminder per record to active
  approvers: `refund_approval_overdue` and `risk_review_overdue` (COD holds included). Disputes have no other alert,
  so `payment_disputed` goes out on the next run without waiting. A refund linked to a ticket gets an internal system
  note with its outcome ("Refund RFD-… completed / failed: … / rejected: …"), and the ticket's assignee is told
  unless they made the decision or requested the refund. A rejection now notifies the requester too.
- **Trusted customers can skip risk review (off by default).** When `risk_auto_clear_min_captured` is above 0,
  scheduled work runs Clear and capture on an open review (risk `review`, pending or authorized, order not cancelled)
  if the customer has at least that many captured payments, no disputed or lost payment, no more failed attempts in
  the last hour than `risk_failed_attempts`, and the amount is within `risk_auto_clear_max_amount` (default
  ₱100,000.00, twice the default review threshold). COD reviews qualify too; their cash is still captured only on
  delivery. Blocked payments are never touched. Each clear writes an `AuditLog::system` row (`risk_auto_clear`).
- **Reconciliation follow-ups.** A later settlement file that reports a payment with matching amount and fee resolves
  the earlier unresolved "missing in report" row for it (no resolver, a note naming the file, and an
  `AuditLog::system` row `reconciliation_auto_resolve`); an amount mismatch never does. Manual resolve locks the row,
  so a second resolve returns 422. "Resolve all" and "Approve selected" refunds reuse the per-record endpoints
  (refund approvals one at a time, since each calls the gateway) and list the rows they skipped.
- **Order status rules and reconciliation scope (2026-10-11 simulator audit).** An order upsert that sets
  `status_snapshot` to `cancelled` follows `POST /orders/{id}/cancel` exactly (`MirrorController::closeOrder`): 422 when
  money was captured, otherwise shipments, then pending and authorized payments, are cancelled in the same
  transaction. A cancelled order stays cancelled: resending `cancelled` returns 200, any other status returns 422
  `status_snapshot`, and the storefront sends a new order instead. "Missing in report" covers gateway captures only
  (`ReconciliationService::gatewayCaptures`). COD is left out because the courier collects that cash and no gateway
  reports it. Subscription and invoice charges stay in, because they go through the sandbox gateway.
- **Small refunds can skip the approver (PAY-8, approved, off by default). Amends SPECIFICATION.md:229**, which has
  every refund approved by a holder of `approve refunds`. When `refund_auto_approve_cap_amount` is above 0, a refund
  requested by staff (`POST /refunds`) is approved and refunded in the same transaction when it is within the cap, its
  reason is in `refund_auto_approve_reasons` (default: every reason except `other`), its payment has no earlier
  automatic approval, and the requester's automatic approvals for the business day stay within
  `refund_auto_approve_daily_limit` (default ₱2,000.00). The requester row is locked for that check. Refunds the
  gateway declines count toward neither limit. An automatic approval leaves `approved_by` null (with `approved_at`
  set), has ledger rows without `created_by`, notes "approved automatically" on a linked ticket and writes an
  `AuditLog::system` row (`refund_auto_approve`). Integration refunds never take this path: only `RefundController::store`
  passes `$allowAutoApprove` to `PaymentService::requestRefund`. `GET /payment-options` returns the three settings so
  the refund forms can say which refunds will be approved at once.
- **Risk-review hold (PAY-2, off by default). Amends SPECIFICATION.md:207**, where pending payments expire after 30
  minutes. With `risk_review_hold_hours` above 0 (at most 168), a pending or authorized payment under risk review
  (`review`, not `blocked`) expires that many hours after it was created. Scheduled work moves its `expires_at` rather
  than skipping it, so held rows never fill the 100-row expiry batch (`payments_held_for_review`). Lowering the setting
  does not shorten an expiry already moved; other payments still expire after 30 minutes.

## Integrations and webhooks

- **Seller and record ownership.** Every webhook event goes only to the active integration client whose `system_code`
  equals the record's `source_system` (order for payments, refunds and shipments; ticket; seller for billing events).
  `WebhookService::emit()` requires that client; `emitFor()` resolves it and sends nothing when there is no active
  owner. Private financial events are never broadcast.
- **Webhook retry behaviour.** Deliveries are written inside the business transaction and sent by the scheduler after
  commit. Retries at 1 min, 5 min, 30 min, 2 h and 12 h (five retries), then `failed`. 20 consecutive failures disable
  the endpoint and notify Admins. A delivery stuck in `processing` for five minutes is returned to `pending`. Outbound
  URLs are HTTPS on public addresses with DNS pinned per request and redirects disabled.
- **Webhook recovery (ADM-03/ADM-04).** "Resend failed" (`POST /webhook-deliveries/resend-failed`) only requeues up to
  500 failed deliveries on enabled endpoints; scheduled work sends them. `disabled_at` marks only the automatic
  20-failure disable; disabling an endpoint by hand clears it. With `webhook_auto_recover` on (default), scheduled work
  sends one signed `webhook.test` probe per run (5 s timeout) to an auto-disabled endpoint at most once an hour; a 2xx
  re-enables it, requeues its failed deliveries, writes a system audit row and notifies active Admins. An endpoint
  disabled by hand is never probed. The hourly probe is reserved before sending; its response can enable only the
  unchanged endpoint of a still-active client, so changing its URL, secret or owner while probing requires a new check.
- **Inbound webhooks** verify HMAC-SHA256 over the raw body with a five-minute timestamp tolerance, store every event
  id (unique per gateway or courier) and ignore duplicates. Rate limit: 300 per minute per gateway and per courier.

## Scheduler

- One `Scheduler` service runs every due job, in this order: pruning of expired idempotency keys, webhook endpoint
  recovery, webhook retries, payments (risk auto-clear, late-authorization capture, expiry, Finance reminders),
  tickets (auto-resolve, auto-assign, SLA notifications, auto-close), billing (renewals, invoice reconcile, dunning
  retries, settlement generation for the last complete week or month plus seven catch-up periods, auto-finalize),
  logistics (overdue and stuck shipments), token expiry reminders and, last, notification clean-up. Settings >
  Automation lists each rule with its switch and last counts.
- **Locks.** A row in `scheduler_locks` taken with `SELECT … FOR UPDATE` allows one run at a time. The lock is held
  for at most five minutes, so a crashed run is recovered automatically when it expires.
- **Isolation.** Each job runs in its own `try` block and each subscription in its own transaction; failures are
  reported and listed in `failed_jobs`, and the other jobs still run. Every job is idempotent (running it twice changes
  nothing the second time).
- **Triggers.** `GET /api/v1/cron/run` with `Authorization: Bearer <CRON_SECRET>` (Vercel Cron, daily at 00:00 Manila
  on the Hobby plan); after any authenticated API request, after the response is sent, at most once every five
  minutes; locally `php artisan sfa:run-scheduled` or `php artisan schedule:work`.
- **Pause switch.** `SFA_SCHEDULER_ENABLED=false` is checked inside `Scheduler::run`, so it pauses every trigger
  (cron, post-request runs, artisan and the simulator) before the lock is taken. Paused cron calls still require the
  secret and answer `200` with `paused: true`; the test suite runs paused unless a test opts in.
- **Five-minute production trigger (documented, not provisioned).** Vercel Hobby allows one cron a day, so automations
  would otherwise run only while staff are signed in. DEPLOYMENT.md §3 gives a free Supabase `pg_cron` + `pg_net`
  recipe that calls the cron route every five minutes with the secret kept in Supabase Vault. It is applied by hand.
- **Run state and failures.** Each completed run stores its counts and `finished_at` in
  `scheduler_locks.last_result`. `GET /automation` shows it; `POST /automation/run` (`manage settings`, 6 a minute)
  runs due work now and returns 422 while paused. A failed job notifies active Admins with `scheduler_job_failed` at
  most once per job per day.
- **Settings defaults.** `Setting::valueFor($key)` without a default falls back to `config('sfa.settings.<key>')`,
  so each rule's default lives in one place.
- **Automatic actions in the audit log.** Changes made by a rule are written with `AuditLog::system()`: no user
  (shown as "System") and `new_values.automation` naming the rule.
- **Settings > Automation.** Admins see every automatic rule on one tab, listed from the `AUTOMATIONS` registry in
  `frontend/src/lib/admin.js`: its switch or number (saved through `PUT /settings`) or "Always on" for integrity jobs,
  the counts from the last run, and "Run due work now". The ticket auto-assign switch moved there from System
  settings. SLA hours are edited only under Support > SLA policies: `PUT /settings` now rejects `sla_policies` with
  422, because SLA due times read the `sla_policies` table and silently ignored the settings copy (SPECIFICATION.md
  lists SLA policies under Settings). The stored copy remains the fallback when a priority has no table row.
- **Token expiry reminders.** Each run reminds active users who manage integrations once per token (`data.token_id`,
  stored as a string so the JSON lookup is the same on SQLite and PostgreSQL) when an active client's token expires
  within `token_expiry_reminder_days` (7; 0 = off). Already-reminded tokens are excluded before the 100-token batch
  limit. Each token's reminders are transactional and isolated from other tokens. Expired tokens are not pruned;
  revoking them stays manual.
- **Notification clean-up and retention.** The last job of each run marks a notification read for every recipient
  once its record no longer needs action: a refund no longer requested, a payment no longer in risk review, a resolved
  or closed ticket, a parcel no longer in failed attempt, a subscription no longer past due, a finalized settlement,
  an endpoint re-enabled or disabled by hand, a revoked token or an expired sign-in lock. The type → record map is
  `Scheduler::notificationChecks()`; outcome and FYI notes and digests without a record id are left for people to
  read. Read notifications older than `notification_retention_days` (90; 0 = keep) are deleted; unread ones never are.
  Read token-expiry reminders remain until that token is revoked, preserving deduplication when retention is shorter
  than the reminder window.

## Access

- **Full Admin authorization.** The Admin role grants every staff permission through the server's authorization gate, including permissions added after seeding. User/profile responses and notification recipient queries reflect that effective access even if the Admin role's individual grants are empty. Other roles keep their existing permissions and record scopes.
- **Admin approvals and ticket handling.** Admin can approve or reject their own refund requests, with the actor and outcome recorded in the audit trail; other reviewers still cannot review their own requests. Admin can view/manage every canned response, including other authors' personal templates, and manually assign tickets to any active Admin or Support Agent, including themselves. Automatic assignment still selects Support Agents.
- **Operational validation.** Full Admin authorization does not skip financial balance checks, valid status transitions, active staff authentication, required proof/reasons, or integration-client token and source-system isolation.
- **Sign-in lock alert.** Every sign-in lock writes an `account_locked` audit row, on the password and the sign-in code
  paths. The 24-hour lock (8th failure) on a staff account also sends `sign_in_locked` to active Admins once; shorter
  locks and unknown emails do not. Users & Roles filters by `status=locked`. Early unlock stays a manual Admin decision;
  if the only Admin is the locked account, see DEPLOYMENT.md §5.
- Support Agents see tickets assigned to them or unassigned, and only the orders, payments, refunds and shipments
  linked to those tickets (in lists, details, downloads, option lists, summaries and dashboards). Logistics
  Coordinators see tickets linked to shipments and receive no payment data. Finance and Admin see every record.
- **Ticket status follows replies.** A public staff reply moves an open or reopened ticket to in progress, and a
  Support Agent's public reply claims an unassigned ticket (an Admin's does not, so Admins can answer without taking
  the queue). A requester reply moves a waiting ticket back to in progress and notifies the assignee. A reply on a
  resolved ticket does not reopen it, because reopening clears the satisfaction score. These moves write a system
  audit row (`ticket_reply_follow_up`). Staff can also set Waiting on customer or Resolved with the reply; that status
  is checked before the message is saved, so an invalid one returns 422 and stores nothing.
- **The unassigned queue heals itself.** Deactivating a staff account, or removing both its Admin and Support Agent
  roles, returns its open tickets to the unassigned queue (the count is in the user's audit row as
  `released_tickets`); resolved and closed tickets keep their assignee. With `auto_assign` on, scheduled work also
  gives unassigned open tickets to the least-loaded active Support Agent (`auto_assign` system audit row).
- **Escalation and the close clock.** An SLA breach also notifies the active Admins, once. A satisfaction score of 2 or
  below notifies the Admins and the assignee when the score first drops there. Auto-close stays at 7 days after
  resolution, but waits while the requester has written in the last 7 days, so a late reply is not closed under them
  (a refinement of SPECIFICATION.md's "automatically 7 days after resolved").
- **Support rules that talk to customers are off by default.** `ticket_waiting_auto_resolve_days` (0 = off) resolves a
  ticket left waiting on the customer that long with no requester reply. It moves through in progress, so the waiting
  time is credited to the SLA as usual, and adds the `auto_resolved` tag so reports can tell it apart
  (`ticket_auto_resolve` system audit row). `ticket_auto_acknowledge` adds a public system message to tickets that
  staff or a client open (not to tickets SFA opens itself); it is not a first response, so the first-response SLA stays
  honest. Linking the order's only payment or shipment on create is always on and gated by category; it never guesses
  between two records.
- Audit metadata set by controllers is written to the request's own attribute bag; every `FormRequest` shares that bag,
  so the audit log records the right entity even when the controller receives a form request.
- CSV exports prefix cells that start with `=`, `+`, `-`, `@`, tab, CR or LF with `'`, except plain numbers.

## Logistics

- **Overdue and stuck shipments (LOG-5).** A shipment is overdue when it is not delivered, returned, cancelled or
  returning and its `promised_to` day has passed in the business timezone; the shipments list filters on it live
  (`overdue=1`, `summary.overdue`) and the Late pill uses the same rule. Scheduled work stamps `overdue_notified_at`
  once per shipment, and `stuck_notified_at` when an open shipment has had no event for `logistics_stuck_hours`
  (default 48, 0 = off); any new event clears the stuck stamp, so a parcel re-alerts only after it moved and went quiet
  again. Each run sends one `shipments_attention` digest to active users who can edit shipments. The stamps are hidden
  from API responses and webhooks.
- **Automatic delivery ticket (LOG-6, on by default).** With `logistics_auto_ticket` on, a failed delivery attempt
  (staff or courier) opens a `delivery` ticket for the order's customer, linked to the order and shipment, with channel
  `system`, tags `auto` and `delivery`, and a system first message; when the shipment already has an open ticket it adds
  an internal note instead. From the third attempt the ticket is high priority (SLA due times recomputed), and a
  delivery notes the open ticket. It runs after the shipment transaction commits, under the shipment lock, and skips a
  note it already wrote, so concurrent or repeated calls give one ticket and a ticket error is reported without undoing
  the shipment update. Nobody can create or switch a ticket to channel `system` (422); a system ticket keeps it when
  staff edit other fields. The ticket goes to the storefront's `ticket.created` webhook like any other.
- **Returned parcels settle the payment (LOG-11).** When a COD parcel is returned to the sender or cancelled, and no
  other COD parcel of the order is still open and every seller of the order has a shipment, the pending COD payment is
  cancelled through `PaymentService::cancel` (audit automation `logistics_return_cod_cancel`). Delivered COD parcels
  keep it open, because their cash was collected. A returned or cancelled parcel of a prepaid order only notifies users
  who edit payments (`parcel_returned`); refunding stays Finance's decision. Picking up a parcel needs a tracking
  number, and shipment bells go to `edit shipments` holders only.
- **Order cancel cancels shipments (LOG-7, decision 5).** `POST /integrations/orders/{external_id}/cancel`, an order
  upsert that changes `status_snapshot` to `cancelled` (the Integration Simulator uses the same routes) both cancel the
  order's pending and ready-for-pickup shipments first (each with `shipment.status_changed`). A shipment already with the courier or
  delivered makes the cancel return 422 with nothing changed; the storefront waits for the return instead.
- **Courier gap-fill (LOG-8, off by default).** With `logistics_courier_fill_gaps` on, a courier event that skips forward
  steps records the skipped ones first, noted as inferred and in the same transaction. Delivered is never inferred,
  because it needs proof. Every refused courier event (422) notifies `edit shipments` holders once per event id
  (`courier_event_rejected`), outside the rolled-back transaction.
- **Courier proof of delivery for prepaid parcels (LOG-9, Phase 6, off by default).** Amends SPECIFICATION.md:276,
  where only staff record proof. With `logistics_courier_pod` on, a courier `delivered` event may carry
  `recipient_name` and `proof_photo` (raw base64, at most 2 MB decoded, PNG, JPG or WEBP checked by content with
  finfo). For a shipment that is `out_for_delivery` with `cod_amount` 0, the photo is stored through
  `AttachmentService::store` with no uploader, the recipient and note are set, and the parcel is delivered in the same
  locked transaction as the event. A COD parcel, a parcel in another status, the setting off or a bad photo returns 422
  with nothing stored, and staff are told as for any refused courier event. COD stays staff-only because the
  coordinator must confirm the cash collected before the payment is captured.
- **One row-lock order: order, then payments, then shipments.** Order cancel (`MirrorController::cancel` and `order`,
  through `LogisticsService::cancelForOrder`), every shipment change (`LogisticsService::lockForMutation`, used by
  events, proof of delivery, edits and courier webhooks) and COD capture (`PaymentService::captureCodWhenReady`,
  `clearRisk`) lock the order first, then its payments by id, then its shipments by id. Two of them on the same order
  therefore queue on the order row instead of deadlocking on PostgreSQL. A delivery that raises its ticket to high
  priority calls `TicketService::changePriorityLocked`, the same SLA recompute as a staff priority change.

## Locations and maps

- **PSGC strategy.** Regions, provinces, cities/municipalities and barangays carry official 10-digit PSA PSGC codes.
  Only a small verified Metro Manila subset is seeded (see `LOGISTICS.md`); the full list is loaded by an Admin CSV
  import of the PSA publication (idempotent upsert by code). NCR has no provinces, so `province_id` is nullable. No
  codes are invented.
- **Optional map.** Leaflet was not added. The shipment modal shows the text address; a map can be added later with
  `leaflet` + `react-leaflet` and OpenStreetMap attribution when coordinates are present.

## Demo data

- `DatabaseSeeder` always creates roles, permissions and settings. Outside production it adds six staff accounts
  (`@example.com`, password `password`); outside production **and** tests it runs the domain demo seeders: plans and
  rules → about 60 orders over 45 days with payments in every status → shipments in every status → tickets in every
  status and SLA state → subscriptions and two settlement periods → integration clients with printed tokens and a
  disabled `http://localhost:8787/webhooks` endpoint. All demo mirrors use `source_system = demo-storefront`. Feature
  tests seed only the foundation so their counts stay exact. Production never creates demo users, tokens, records or
  endpoints.

## Process simulator and guidance

- No storefront, customer app or live payment gateway is connected yet, so Admins get a Process Simulator
  (`/simulator`, API `/api/v1/simulator/*`). It stands in for the outside systems only: it adds customers and sellers
  (storefront `simulator`), places orders with server-computed totals, pays them through the existing sandbox gateway
  or COD (`PaymentService::create`), cancels unpaid orders, posts a customer reply or satisfaction score on a ticket,
  runs the scheduled work now and builds a sample gateway settlement CSV for reconciliation. Every later step happens
  in the normal module pages. The integration endpoints and gateway drivers are unchanged.
- `SFA_SIMULATOR` switches it; unset means on everywhere except `APP_ENV=production`, so production never gets it by
  default. It appears as a last sidebar entry, after the nine modules of SPECIFICATION §1.2, only for Admins on servers
  where it is on. Records it creates are ordinary records marked with the `simulator` storefront.
- Ticket replies and scores from the simulator reuse the customer-side ticket paths by passing an unsaved integration
  client for the ticket's storefront, so `TicketService` has no simulator-specific branches.
- Each page tab shows a one-line hint, and each ticket, payment, refund, reconciliation, shipment, subscription,
  invoice and settlement detail shows a "Next step" box (`frontend/src/lib/guidance.js`). Tabs and open records are
  kept in the URL (`?tab=refunds&refund=12`), so notifications open the record they are about and Back returns to the
  previous tab.
- To show the integrations themselves, `tools/partner-simulator` is a separate dependency-free Node app (port 8787)
  that plays the marketplace storefront, the payment gateway and the courier. It calls only the public integration
  API and the signed gateway and courier webhooks, and receives SFA's signed webhooks, so the traffic in both
  directions is real. The Admin "Connect partner simulator" action registers it as `demo-storefront` with a fresh
  token and webhook endpoint and hands the secrets to it server-to-server; secrets never reach the browser.
- In simulator mode, queued webhooks are sent as soon as the request finishes instead of on the next scheduled run,
  so a demo partner sees events within seconds. Failed attempts still follow the normal retry schedule.
- Commission rule dates entered as a plain date start at midnight in the business time zone and are stored in UTC.
  Before this change Eloquent dropped the `+08:00` offset and rules started eight hours late.

## Integration Simulator (2026-10-11, HANDOFF Task 9)

This supersedes the in-system storefront stand-ins and the "Connect partner simulator" bullet above.

- The Process Simulator page is tabbed by connected system: the storefront (Group 2 E-Commerce Core, client
  `demo-storefront`), the finance system (Group 10 Financial Management, client `demo-finance-gl`), the courier
  (Group 6 Fleet & Transportation, signed courier webhook) and the third-party payment gateway (signed sandbox webhook
  and the settlement CSV). HR, Facilities and Supply Chain exchange nothing with SFA and are not shown. Each tab shows
  only what that system sends to or receives from SFA.
- Sends use the real integration API with each system's own token, called from the Admin's browser so the HTTP traffic
  is visible, or real signed webhooks. Receives are the webhooks SFA queued for `demo-storefront` (its delivery log) and
  data pulled with the read endpoints. `POST /simulator/customers`, `/sellers`, `/orders`, `/orders/{order}/pay`,
  `/orders/{order}/cancel` and `/partner/connect` were removed; their tests now run through the integration API.
  Ticket answers from the customer, the scheduler clock and the settlement file keep their simulator endpoints, because
  the integration API has no equivalent.
- `POST /simulator/connect` (Admin, simulator mode only) ensures both demo clients are active, replaces each one's token
  named `simulator` with a 12-hour token carrying the seeded scopes, keeps the storefront webhook endpoint at
  `SFA_PARTNER_URL/webhooks` active (its secret is kept), reuses the first active courier's signing secret, and returns
  the tokens, the courier secret and the sandbox gateway secret. **These secrets reach the Admin browser only in
  simulator mode**, which is off by default in production; the page keeps them in memory only, and the audit log drops
  them. The same storefront configuration is pushed to `tools/partner-simulator` (best effort), which stays the webhook
  receiver. This replaces the earlier rule that simulator secrets never reach the browser.
- Finance-wide read access (owner decision "read-only access to all"): `integration_clients.reads_all_financials`,
  off by default and set by Admins, lets a client's analytics summary, seller subscriptions and seller statements reads
  cover every storefront. A seller external ID found in more than one storefront needs `source_system` (422 without it).
  It never widens writes, tickets, shipments, the knowledge base or payment and refund lookups. `demo-finance-gl` has it
  on after Connect.
- Tokens named `simulator` are left out of the token-expiry reminders and the dashboard's expiring-tokens count: Connect
  renews them every time the page opens, so a reminder would only be noise.
- A webhook delivery attempt bumps the API response cache version, so the cached delivery log (the receive panels poll
  it every 5 seconds) shows the new status at once instead of after up to 30 seconds.

## Integrations page removed (2026-10-11)

- The Integrations admin page (sidebar item, `/integrations` route, `pages/Integrations.jsx` and its forms) was removed
  at the user's request. Copies are in `backups/removed-integrations/`. Its notifications (webhook endpoint disabled or
  recovered, token expiring) still arrive but no longer link anywhere.
- Integration clients, tokens and webhook endpoints now come from the seeders and the Process Simulator's Connect step.
  The `reads_all_financials` switch is turned on for `demo-finance-gl` by Connect, and can be set through the staff API.
- The backend is unchanged: the integration API, the staff endpoints for integration clients, tokens, webhook endpoints
  and deliveries, their tests and `docs/openapi.yaml` stay for API users.

## AI assistant (2026-10-12, tasks/ai-plan.md)

- Google Gemini, called by `AiService` over plain HTTP (`generateContent` with `x-goog-api-key`), so no new package. The
  model is pinned, never a `-latest` alias: `gemini-3.5-flash-lite`, with `gemini-3.1-flash-lite` as the fallback. Both
  are stable with a 1,048,576-token input, structured JSON and function calling. Request quota decided it: on the free
  tier each allows 15 requests a minute and 500 a day, while every Flash model allows 5 and 20, and 3.6/3.8 Flash ran past
  their 20 a day during one morning of testing (3.8 also kept answering 503 "high demand"). With billing on, a Flash model
  is only an env change away.
- AI labels and suggests; people decide. Triage only replaces the category "other" and only raises the default priority.
  Reply drafts, finance reviews and address fill only fill a box or show a recommendation. The second opinion can only add
  an uncaptured payment to Risk reviews (a captured one only gets a notice) and never blocks, cancels or refunds. The
  payment-integration code (`processEvent`, gateways, integration endpoints, payment webhook) is untouched.
- Numbers come from PHP: amounts are formatted from centavos before they are sent, "Ask your data" reads only
  `ReportingService` through function calls with the asker's permissions, and figures the answer cites that are in none
  of the results are listed as unverified.
- Private data: each feature sends a fixed list of fields. `AiService::mask` replaces emails, phone numbers and runs of 8+
  digits. Customer names and payout details are not sent. Reply drafts greet `{first_name}`, which the browser fills in. A
  pasted address keeps its phone number in SFA. Google's free tier may use prompts to improve its products, so the paid
  tier is advised before real customer data goes through.
- Every answer, failure and token count is stored in `ai_insights`: the same input reuses the stored answer, the day's total
  enforces `ai_daily_token_limit`, and the row records who asked. On-screen requests get one more try on the main model
  after 2 s when both models are busy; automatic work keeps one attempt and catches up on its own schedule.
- Risk reviews get no note draft because Clear and Block take no note; refund rejections do, and the draft fills the
  Reject dialog.

## AI compliance rulebooks (2026-10-12)

- Why: the refund review answered "Need more info" with reasons nobody could trace to a policy. Every AI feature now
  checks a numbered rulebook in `App\Services\AiRules` (`refund` REF-1..9 since the photo rules below, `risk` RSK-1..5, `reply` RPL-1..7, `triage`
  TRI-1..4, `address` ADR-1..4, `delivery` DLV-1..2, `ask` ASK-1..3; spec in `tasks/ai-rules-plan.md`), and every response
  carries `checks[]`, one row per rule in order, so each recommendation shows the checklist it rests on.
- Two kinds of check: an **SFA check** is computed by SFA's own code from what its records prove (payment state, the
  refundable balance, dates, deliveries, earlier refunds, amounts and references in a draft); an **AI check** is judged by
  the model only where reading is needed (does the conversation describe the problem, does the draft promise anything the
  records do not support). The model returns its verdicts in a `checks` array of its JSON answer; a verdict on an SFA rule
  is ignored and a rule nobody judged is "unclear".
- The outcome is worked out in PHP by `AiRules::outcome()`, never by the model: a failed required rule fails, an unclear
  required rule or a failed or unclear advisory rule needs review, otherwise it passes. Refund reviews map that to
  approve / reject / need_more_info and risk reviews to clear / block / need_more_info; the model only explains. The
  checks replace the refund review's `policy_flags` and `sfa_flags`.
- Thresholds are settings without a screen yet (`refund_window_days` 15, `refund_max_per_30_days` 2,
  `risk_amount_multiple` 5, `risk_new_customer_days` 7, plus the existing `risk_failed_attempts`): `PUT /settings` and
  config/sfa.php change them, and `AiRules::book()` fills them into the rule text, so the rulebook page always shows the
  numbers in force.
- Failed deliveries: the model only picks the reason; the next step comes from `AiRules::DELIVERY_PLAYBOOK`, so staff
  get one fixed step per reason instead of free text.
- `GET /api/v1/ai/rules` returns every rulebook and the playbook to any signed-in staff member (no extra permission; the
  rules are policy, not data) for the read-only "Rules the AI follows" section of the Help page.

## Refund photos in the compliance check (owner, 2026-10-12)

- Staff can attach up to 3 photos of the problem to a refund request (`photos[]`, PNG, JPEG or WebP, 4 MB each). The
  compliance check shows Gemini up to 4 evidence photos: the refund's own upload plus images from its linked tickets'
  conversation, together with the order's items and their prices. The response lists the photos the AI was shown, in
  order, and the panel shows them under "Photos the AI looked at".
- Photos are sent to Gemini on any key, the free tier included. The owner accepts that Google may use them to improve its
  products (the general advice above to move to the paid tier before real customer data still stands).
- The refund rulebook grows to REF-1..REF-9: REF-7 (photo evidence of a damaged or wrong item) is now **required** for
  those two reasons instead of advisory, REF-8 checks that the photo matches the order and REF-9 that the amount fits the
  affected items. Other reasons do not need a photo.
- The outcome is still worked out by SFA from the rule results (`AiRules::outcome()`); the photos only inform the AI's
  verdicts on its own rules.

## Risk check wording (owner, 2026-10-12)

- A payment's risk review panel identifies risk and must not look like a compliance checker: it is titled "Risk check",
  its button reads "Check the risk", the verdict reads Low / Some / High risk, and the rule list shows "Risk signals"
  (a failed rule is "Risk found"). The rules, the outcome and the manual run are unchanged; refunds keep the compliance
  check.

## Deployment assumptions

- One Vercel project with two services (`vercel.json`): the Vite build as static files with an SPA fallback, and
  `/api/*` routed to a FrankenPHP (PHP 8.4) container built from `backend/Dockerfile.vercel`, region `sin1`. The
  container starts behind the `start.sh` readiness gate, uses array cache and sessions, logs to stderr and keeps
  Laravel caches in `/tmp`.
- PostgreSQL on its own Supabase project through the session pooler with `DB_SSLMODE=require`; every table enables
  row-level security in its migration. Migrations run from a local machine against the production database.
- `docs/openapi.yaml` is canonical; `backend/resources/openapi.yaml` is the copy the container serves
  (`scripts/build-openapi.py` writes both, and a test fails if they differ).

## Not verifiable locally

- The Vercel Services build and routing, Vercel Cron delivery, the container image build and the FrankenPHP start-up
  gate were prepared from the PDMS configuration but not run from this machine. No deployment was performed.
- PostgreSQL-specific behaviour (row-level security statements, partial unique indexes and foreign keys added by the
  late integrity migration) is written for both drivers but was exercised only on SQLite here.
- Gmail SMTP delivery of sign-in codes and real gateway, courier and map providers.

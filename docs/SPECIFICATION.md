# Prompt — build the "E-Commerce Support, Finance & Administration" system

> **How to use this file.** Copy everything below the line into ChatGPT as one message (or upload this file and write
> "Follow the prompt in the attached file exactly"). Appendix A (DESIGN.md) and Appendix B (TECH-STACK.md) are part of the
> prompt; keep them. ChatGPT will build the system in phases and stop after each one; reply **continue** to get the next phase.

---

## 0. Your role and the rules you must follow

You are a senior full-stack engineer. You will design and write the complete source code of a production-ready web system
named **"E-Commerce Support, Finance & Administration"** (short name **SFA**) for the company **Archon Nell**.

SFA is one of several systems of Archon Nell's e-commerce marketplace cluster. Another team already built the
**Performance & Development Management System (PDMS)**. SFA must use **the same technology stack** (Appendix B) and
**look identical** to PDMS (Appendix A), so that a user moving between the two systems sees one product.

Rules (all mandatory):

1. **Design:** follow Appendix A exactly. Copy its color tokens, `index.css`, `tailwind.config.js`, `MainLayout`, `Modal`
   and the class strings of every component verbatim. Build every screen from its page anatomy (§4) and components (§5).
   Do not add any other UI kit, CSS framework, icon set, font, chart library, color, gradient or dark mode.
2. **Stack:** use exactly the packages and major versions in Appendix B. The only additions allowed are listed in §9.
   Frontend in JavaScript + JSX (no TypeScript). Backend in Laravel 12 with Sanctum and spatie/laravel-permission.
3. **API conventions:** follow Appendix B §2.1 (paginator shape + `summary`, status codes, error bodies, `per_page` clamp,
   search, audit log, notifications, health). This system prefixes every route with **`/api/v1`** (PDMS uses `/api`) so the
   integration contract in §6 can evolve without breaking clients.
4. **Completeness:** write complete files. Never write "...", "rest of the code", "same as above", "TODO" or placeholder
   functions. Before every file, write its path on its own line, then the full file in one fenced code block. If a phase is too
   long for one reply, end the reply at a file boundary with "PART X OF Y — reply continue" and resume with the next file.
5. **Consistency:** names of tables, columns, routes, permissions, query keys and components must match across all files and
   phases. Keep a running list in `docs/DECISIONS.md` and update it every phase.
6. **No guessing about third parties:** do not invent APIs of payment gateways, couriers or map providers. Third-party calls go
   through interfaces (§4.2, §4.3) with a fully working built-in **sandbox** implementation. A real-provider driver may only be
   written from that provider's official documentation; if you cannot verify an endpoint, field or signature scheme, say so in
   `docs/DECISIONS.md` and leave that driver out instead of guessing.
7. **When something is not specified,** choose the option most consistent with PDMS (Appendices A and B), apply it, and record
   it in `docs/DECISIONS.md`. Do not stop to ask questions.
8. **Correctness first:** money is always an integer number of centavos (§3.3); every state change follows the state machines in
   §4 (reject invalid transitions with 422); every write is validated and authorized on the server; every endpoint has a feature test.

---

## 1. What SFA is

The back office that runs the marketplace's **customer support, payments, deliveries, seller subscriptions and commissions**,
plus the **analytics and administration** for all of it. It has five modules, each with a sidebar entry:

| # | Module (sidebar name) | Covers |
|---|---|---|
| 1 | **Customer Support** | Customer Support System |
| 2 | **Payments & Transactions** | Payment & Transaction System |
| 3 | **Logistics & Locations** | Logistics & Location System |
| 4 | **Subscriptions & Commissions** | Subscription & Commission Management |
| 5 | **Dashboard** + **Analytics & Reports** + admin pages | Analytics & Admin Dashboard |

The storefront, shopping cart, checkout, product catalog, seller shops and customer accounts are **other systems** of the cluster.
They talk to SFA through the integration API (§6): they send customers, sellers, categories and orders in, create payments,
request shipping quotes, open tickets, and receive webhooks when something changes. SFA stores what it needs from them as
**mirror records** keyed by `external_id` (string) + `source_system` (string), never as foreign keys into other databases.

Back-office users (people who sign in to SFA) are Archon Nell staff. Customers and sellers do not sign in to SFA.

### 1.1 Roles and permissions

Spatie roles, checked on the server for every endpoint and used on the client to hide what a user cannot do.
Permission names follow PDMS: `"<action> <module>"` with actions `view`, `create`, `edit`, `delete`, plus the special ones listed.

| Role | Can |
|---|---|
| **Admin** | All staff views and actions regardless of individual permission grants, including own refund approval/rejection, ticket assignment, users and roles, integration clients and tokens, webhooks, settings, audit logs |
| **Finance** | Payments, refunds (approve/reject), ledger, reconciliation, risk review, plans, subscriptions, invoices, commission rules, settlements, payouts; read support tickets and shipments |
| **Support Agent** | Tickets (own and unassigned), knowledge base, canned responses; read payments, refunds, shipments of the related order; request refunds |
| **Logistics Coordinator** | Shipments, shipment events and proof of delivery, couriers, zones, rates, warehouses, locations; read tickets linked to shipments |
| **Analyst** | Read-only access to dashboard, analytics, reports and CSV exports |

Special permissions: `approve refunds`, `finalize settlements`, `pay settlements`, `manage integrations`, `view audit logs`, `manage settings`.
Sidebar items are shown only to users who have the module's `view` permission.

### 1.2 Sidebar (in this order, Font Awesome 7 icons)

| Name | Path | Icon |
|---|---|---|
| Dashboard | `/dashboard` | `fa-border-all` |
| Customer Support | `/support` | `fa-headset` |
| Payments & Transactions | `/payments` | `fa-credit-card` |
| Logistics & Locations | `/logistics` | `fa-truck-fast` |
| Subscriptions & Commissions | `/subscriptions` | `fa-file-invoice-dollar` |
| Analytics & Reports | `/analytics` | `fa-chart-pie` |
| Integrations *(Admin)* | `/integrations` | `fa-plug` |
| Users & Roles *(Admin)* | `/users` | `fa-user-shield` |
| Audit Logs *(Admin)* | `/audit-logs` | `fa-shield-halved` |

The profile menu (top bar) has "Profile" → `/settings` and "Logout". Admins also get a "System settings" tab on `/settings`.
Login page subtitle: **"Support, Finance & Administration"**.

---

## 2. Shared platform features (build these first)

1. **Auth** exactly like PDMS (Appendix B §2.1): `POST /api/v1/login` → `{ user, token }`; failed-attempt lockout per email
   (5 free, then 5 min, 10 min, 24 h; message says how many attempts are left; `retry_after` + `Retry-After` on 429);
   optional emailed 6-digit sign-in code controlled by `SFA_LOGIN_OTP` (`off` | `bound` | `all`, default `off`);
   `POST /api/v1/logout` deletes the token; `GET /api/v1/user`; profile and password change.
2. **Session timeout** in the SPA: 15 minutes idle → "Are you still there?" dialog → sign-out after 60 s without an answer
   (Appendix A §5.15). Sign-out always calls `/logout`.
3. **Audit log** of every write (who, what, entity, old/new values, IP, user agent) with an Admin page (filters: user, action,
   entity type, date range; CSV export).
4. **Notifications** (bell in the top bar, 30-second unread-count polling) for: ticket assigned to me, SLA at risk / breached,
   refund awaiting approval, refund completed/failed, risk review needed, shipment failed attempt / returned, subscription past due,
   settlement ready to finalize, webhook endpoint disabled after repeated failures.
5. **Response cache + cache flush on write** like PDMS (`cache.api:<seconds>` on read-heavy lists).
6. **Health:** `GET /api/v1/health` → `{ status, commit, database }` (do not include raw database error text in the response).
7. **Settings** (Admin): company name, support email, business time zone (default `Asia/Manila`), currency (`PHP`, fixed),
   SLA policies, auto-assign toggle, risk thresholds, settlement schedule, volumetric divisor default.
8. **CSV export** on every list page (secondary "Export CSV" button in the page header), using the current filters.
9. **Printable documents** (browser print, no PDF library): payment receipt, subscription invoice, seller statement.
   They are plain pages outside `MainLayout` with the light logo, Archon Nell header, and `@media print` rules.

---

## 3. Data rules

### 3.1 Identifiers

- Internal primary keys: auto-increment `id`. Public references are human-readable and unique:
  `TCK-000123` (ticket), `PAY-2026-000123` (payment), `RFD-2026-000123` (refund), `SHP-2026-000123` (shipment),
  `INV-2026-000123` (subscription invoice), `STL-2026-000123` (settlement), `PYO-2026-000123` (payout). The year is the business-time-zone year.
- Mirror records from other systems: unique (`source_system`, `external_id`).

### 3.2 Time

Instants are stored in UTC (`timestampTz` on PostgreSQL) and shown in the business time zone. Dates without time are `YYYY-MM-DD`.
Date pickers for future things block past dates (Appendix A §5.2).

### 3.3 Money

- Every amount is an **integer in centavos** in a `bigint` column whose name ends in `_amount` (e.g. `gross_amount`), with a
  `currency` column fixed to `PHP`. Never use floats for money, in PHP or JavaScript.
- Percentages are stored in **basis points** (`integer`, 1 % = 100 bp).
- Rounding: commissions and prorations round **half up to the nearest centavo** once, at the end of each calculation.
- The UI formats with `new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' })` after dividing by 100; inputs accept pesos
  with up to 2 decimals and convert to centavos exactly (string parsing, not `parseFloat(x) * 100`).
- The **ledger is append-only**: rows are never updated or deleted; corrections are new rows.

### 3.4 Files

Attachments (ticket files, proof-of-delivery photos, refund photos, reconciliation CSVs) are stored in the database like PDMS stores learning
materials: a table with `filename`, `mime_type`, `size`, `content` (base64 text, hidden from JSON), uploaded through PHP,
**4 MB maximum per file** (Vercel refuses bodies over 4.5 MB). Allowed types: PDF, PNG, JPG, WEBP, TXT, CSV. Download through an
authorized endpoint that streams the file with `Content-Disposition`.

---

## 4. Modules — features, data and rules

For each module: build the backend (migrations, models, controllers, services, form requests, policies or role checks, seeders,
feature tests) and the frontend (page with summary cards, tabs, section cards with filters + table + pagination, create/edit/view
modals, confirm dialogs, notices, empty/loading/error states, CSV export) exactly in the PDMS style.

### 4.1 Customer Support (`/support`)

Tabs: **Tickets** · **Knowledge base** · **Canned responses** · **SLA policies**.

**Tickets**
- Fields: `reference`, `subject`, `description`, `channel` (`email`, `chat`, `phone`, `web_form`, `marketplace_app`),
  `category` (`order`, `payment`, `delivery`, `refund_return`, `account`, `product_inquiry`, `seller_concern`, `other`),
  `priority` (`low`, `normal`, `high`, `urgent`), `status`, `requester_type` (`customer` | `seller`) + `requester_id` (mirror record),
  optional links `order_id`, `payment_id`, `shipment_id`, `assignee_id` (user), `tags` (JSON array), `first_response_due_at`,
  `resolution_due_at`, `first_responded_at`, `resolved_at`, `closed_at`, `csat_score` (1–5, nullable), `csat_comment`.
- Status machine: `open` → `in_progress` → `waiting_on_customer` ↔ `in_progress` → `resolved` → `closed`;
  `resolved`/`closed` → `reopened` → `in_progress`. Closing is allowed only from `resolved` (or automatically 7 days after `resolved`).
- SLA policies per priority (defaults, calendar hours): urgent 1 h first response / 8 h resolution; high 4 h / 24 h; normal 8 h / 48 h;
  low 24 h / 72 h. Due times are set when the ticket is created and recomputed if the priority changes. Time in
  `waiting_on_customer` does not count (pause the resolution clock and extend `resolution_due_at` by the paused time).
- Computed `sla_state`: `on_track`, `at_risk` (less than 25 % of the window left), `breached`, `met` (pill tones: blue, amber, red, emerald).
- Conversation: `ticket_messages` (`author_type` user/customer/seller/system, `author_id`, `body`, `is_internal_note`, attachments).
  The first public reply by an agent sets `first_responded_at`. Internal notes are never sent to integrations.
- Assignment: "Assign to me", assign to an agent, or auto-assign (setting) to the active Support Agent with the fewest open tickets.
- List filters: status, priority, category, assignee (incl. "Unassigned", "Me"), SLA state, channel, date range, search (reference,
  subject, requester name/email). Summary cards: Open, Unassigned, At risk, Breached, Resolved today, Average CSAT.
- Ticket view modal: header with reference, status pill, priority pill, SLA timer; segmented tabs **Conversation** · **Details** ·
  **Related** (order, payment, refunds, shipment with their status pills) · **History** (audit entries). Reply box with
  "Reply" / "Internal note" toggle, canned-response picker, attachments, and status change.
- "Request refund" button (Support Agent) opens the refund form prefilled from the linked payment (§4.2).
- CSAT: integrations post a 1–5 score after resolution; the dashboard shows the average for the period.

**Knowledge base**: `kb_categories` (name, sort order) and `kb_articles` (title, slug, body in Markdown stored as text, category,
status `draft`/`published`, published_at, `helpful_count`, `not_helpful_count`). Rendered as escaped text with line breaks
(no HTML injection). Published articles are readable by integrations for the storefront help center.

**Canned responses**: title, body with placeholders `{customer_name}`, `{ticket_reference}`, `{agent_name}`, shared or personal.

### 4.2 Payments & Transactions (`/payments`)

Tabs: **Payments** · **Refunds** · **Ledger** · **Reconciliation** · **Risk review**.

**Orders (mirror)**: `orders` (`external_id`, `source_system`, `customer_id`, `status_snapshot`, `subtotal_amount`,
`shipping_amount`, `discount_amount`, `total_amount`, `placed_at`) and `order_items` (`order_id`, `seller_id`, `category_id`,
`product_name`, `sku`, `quantity`, `unit_price_amount`, `line_total_amount`). Sent by the order system through §6.

**Payments**
- Fields: `reference`, `order_id`, `customer_id`, `method` (`card`, `gcash`, `maya`, `bank_transfer`, `cod`), `gateway`
  (`sandbox`, `cod`, or a verified real driver), `gateway_reference`, `amount`, `captured_amount`, `refunded_amount`, `fee_amount`,
  `status`, `failure_reason`, `risk_level` (`none`, `review`, `blocked`), `authorized_at`, `captured_at`, `failed_at`, `expires_at`.
- Status machine: `pending` → `authorized` → `captured` → `partially_refunded` → `refunded`; `pending`/`authorized` → `failed` |
  `cancelled` | `expired` (pending payments expire after 30 minutes); `captured`/`partially_refunded` → `disputed` → `dispute_won` |
  `dispute_lost`. COD: `pending` → `captured` when the shipment is delivered and cash is marked collected (§4.3).
- One order can have several payment attempts; at most one may be `captured`.
- `PaymentGateway` interface: `createPayment(Payment): GatewayResult`, `capture(Payment, amount)`, `cancel(Payment)`,
  `refund(Payment, amount, reason): GatewayResult`, `verifyWebhook(Request): bool`, `parseWebhook(Request): GatewayEvent`.
  **SandboxGateway** (default) simulates outcomes deterministically: an amount whose last two digits are `99` centavos fails with
  "Card declined", `98` stays pending until a webhook arrives, anything else succeeds; it also offers a "Simulate webhook" button
  for Admins in the payment modal. **CodGateway** never calls out.
- Inbound gateway webhooks: `POST /api/v1/webhooks/payments/{gateway}` (public route, signature verified by the driver, every event
  stored in `gateway_events` with a unique `(gateway, event_id)` so replays are ignored).
- Every money movement writes **ledger** rows (`ledger_entries`: `entry_type` = `payment_capture`, `refund`, `chargeback`,
  `chargeback_reversal`, `gateway_fee`, `commission`, `commission_reversal`, `subscription_charge`, `payout`, `adjustment`;
  `direction` = `debit`/`credit`; `account` = `customer_funds`, `platform_revenue`, `seller_payable`, `gateway_clearing`, `cash_on_delivery`;
  `amount`, `currency`, `payment_id`/`refund_id`/`settlement_id`/`payout_id`, `memo`, `created_by`, `created_at`). Each business event
  writes balanced pairs (total debits = total credits); a test asserts the ledger always balances.
- Receipt: `/payments/:id/receipt` printable page.

**Refunds**
- Fields: `reference`, `payment_id`, `ticket_id` (optional), `amount`, `reason` (`item_not_received`, `damaged`, `wrong_item`,
  `cancelled_order`, `duplicate_charge`, `other`), `notes`, `status`, `requested_by`, `approved_by`, `approved_at`, `rejection_reason`,
  `gateway_reference`, `completed_at`.
- Status machine: `requested` → `approved` → `processing` → `completed` | `failed`; `requested` → `rejected`.
  Only users with `approve refunds` approve/reject. Admin may review their own request; other reviewers must not be the requester. Amount must be > 0 and ≤ captured − already refunded
  (checked inside a database transaction with a row lock on the payment).
- Completing a refund updates the payment status, writes ledger rows and commission reversals (§4.4), notifies the requester, and emits
  `refund.completed`.
- Photos: a staff refund request may carry up to 3 photos of the problem (`photos[]` in a multipart `POST /refunds`; PNG,
  JPEG or WebP, at most 4 MB each), stored as attachments (§3.4). JSON requests without photos still work. The refund
  detail lists them (`photos: [{id, filename, mime_type, size, created_at}]`) and staff open each one through
  `GET /refunds/{refund}/photos/{attachment}`, which needs the same access as the refund.

**Reconciliation**: upload the gateway's settlement CSV (columns: `date`, `gateway_reference`, `amount`, `fee`; amounts in pesos);
each row is matched to a payment by `gateway_reference` → item status `matched`, `amount_mismatch`, `missing_in_sfa`,
`missing_in_report` (captured in the period but absent from the file). Show a run summary and let Finance resolve items with a note.

**Risk review**: rules in settings — more than N failed attempts by one customer within 1 hour (default 3), or an amount above a
threshold (default ₱50,000.00). Matching payments get `risk_level = review` and appear in the queue; Finance clears or blocks them.
Blocked payments cannot be captured.

Summary cards: Captured today (₱), Pending, Failed today, Refunds awaiting approval, Disputes open, Unmatched reconciliation items.

### 4.3 Logistics & Locations (`/logistics`)

Tabs: **Shipments** · **Couriers & rates** · **Zones** · **Warehouses** · **Locations**.

**Locations**: `regions`, `provinces`, `cities` (city or municipality), `barangays` with official PSGC codes, loaded by an Admin
**CSV import** of the Philippine Statistics Authority's PSGC publication (saved as CSV) (`POST /api/v1/locations/import`, idempotent upsert by code).
Seed only a small demo subset for development (Metro Manila with a few cities and barangays) and do not invent codes.
Cascading selects (region → province → city → barangay) in every address form.
`addresses`: `line1`, `line2`, `barangay_id`, `city_id`, `province_id`, `region_id`, `postal_code`, `latitude`, `longitude`, `contact_name`, `contact_phone`.

**Warehouses / pickup points**: name, code, type (`platform_hub`, `seller_pickup`), address, seller (for seller pickups), active.

**Couriers**: name, code, service types (`standard`, `express`, `same_day`), `tracking_url_template` (e.g. `…?tracking={tracking_number}`),
`webhook_secret` (for status updates), active. Seed demo couriers by name only (no real API integration).

**Zones and rates**: a zone is a set of provinces and/or cities with a lead time (`min_days`, `max_days`). A rate belongs to
courier + service + origin zone + destination zone: `base_weight_grams`, `base_fee_amount`, `additional_per_500g_amount`,
`cod_fee_bp` (basis points of the COD amount), `max_weight_grams`. Chargeable weight = max(actual weight, volumetric weight), where
volumetric grams = L × W × H (cm) ÷ divisor × 1000 and the divisor is set per courier (default from settings, 3500).

**Quotes**: `POST /api/v1/logistics/quotes` with origin, destination (city ids or PSGC codes), weight, dimensions and COD amount →
every active courier/service that has a rate: fee breakdown, total, `eta_from`/`eta_to` dates (business time zone).

**Shipments**
- One shipment per order per seller: `reference`, `order_id`, `seller_id`, `courier_id`, `service_type`, `tracking_number`,
  origin warehouse, destination address, `weight_grams`, dimensions, `shipping_fee_amount`, `cod_amount`, `status`, `promised_from`,
  `promised_to`, `delivered_at`, `attempt_count`.
- Status machine: `pending` → `ready_for_pickup` → `picked_up` → `in_transit` → `out_for_delivery` → `delivered`;
  `out_for_delivery` → `failed_attempt` → `out_for_delivery` (at most 3 attempts, then → `returning`) → `returned_to_sender`;
  `pending`/`ready_for_pickup` → `cancelled`.
- `shipment_events` timeline (status, location text, note, `occurred_at`, `source` = `manual` | `courier_webhook` | `integration`)
  shown as a vertical timeline in the shipment modal.
- Proof of delivery: recipient name, photo (attachment), note; required to mark `delivered`. For COD, the coordinator also confirms
  "Cash collected", which captures the COD payment (§4.2).
- Courier status updates: `POST /api/v1/webhooks/couriers/{courier_code}` with an HMAC-SHA256 signature using the courier's secret
  (same header format as §6.4), mapped onto the status machine.
- On-time = `delivered_at` ≤ end of `promised_to` day. Summary cards: To ship, In transit, Out for delivery, Failed attempts,
  Delivered today, On-time rate (30 days).
- Map: optional Leaflet map in the shipment modal (§9); if coordinates are missing show the text address only.

### 4.4 Subscriptions & Commissions (`/subscriptions`)

Tabs: **Subscriptions** · **Plans** · **Invoices** · **Commission rules** · **Settlements & payouts**.

**Plans**: `name`, `code`, `monthly_price_amount`, `annual_price_amount`, `trial_days`, `features` (JSON list of strings),
`limits` (JSON: e.g. `max_products`), `commission_rate_bp` (nullable override), `is_active`, `sort_order`.
Seed three demo plans (Free, Growth, Pro) clearly marked as demo data.

**Seller subscriptions**: `seller_id`, `plan_id`, `billing_cycle` (`monthly`, `annual`), `status` (`trialing`, `active`, `past_due`,
`cancelled`, `expired`), `trial_ends_at`, `current_period_start`, `current_period_end`, `cancel_at_period_end`, `cancelled_at`.
- Renewal at period end creates an invoice and charges it through the payment module (`subscription_charge` ledger entry).
- Failed charge → `past_due`; retries 1, 3 and 7 days later; after the last failure → `cancelled` and `subscription.cancelled` event.
- Upgrade: immediate, charge the prorated difference = (new price − old price) × remaining days ÷ days in period (rounded half up).
  Downgrade: takes effect at the end of the period. Cancel: at period end by default, or immediately (no refund) when chosen.

**Invoices**: `reference`, `subscription_id`, `seller_id`, `period_start`, `period_end`, `line_items` (JSON), `subtotal_amount`,
`total_amount`, `status` (`draft`, `open`, `paid`, `void`, `uncollectible`), `due_date`, `paid_at`, `payment_id`. Printable page.

**Commission rules**: scope `global` | `category` | `plan` | `seller` (+ `scope_id`), `rate_bp`, `fixed_fee_amount` per item,
`effective_from`, `effective_to` (nullable). Precedence when several match: seller > plan > category > global; within the same scope the
most recent `effective_from` wins. Exactly one active global rule must always exist.

**Commission entries**: created when a payment is captured, one per order item: `order_item_id`, `seller_id`, `rule_id`,
`gross_amount`, `rate_bp`, `fixed_fee_amount`, `commission_amount` = round_half_up(gross × rate_bp ÷ 10 000) + fixed fee,
`seller_net_amount` = gross − commission; and ledger rows. Refunds create proportional reversal entries
(commission × refunded ÷ captured, rounded half up, never more than what remains).

**Settlements and payouts**: weekly by default (settings), per seller: `reference`, `seller_id`, `period_start`, `period_end`,
`gross_sales_amount`, `refunds_amount`, `commission_amount`, `gateway_fees_amount`, `subscription_fees_amount`, `adjustments_amount`,
`net_payable_amount`, `status` (`draft` → `finalized` → `paid`; `draft` → `void`). Generated by the scheduler or a "Generate" button
(idempotent per seller and period). Finalizing locks the numbers; paying records a `payout` (`reference`, `settlement_id`, `method`
`bank_transfer`/`gcash`, `destination_masked`, `amount`, `status` `pending`/`paid`/`failed`, `paid_at`) and ledger rows.
Seller statement: printable page + CSV.

Summary cards: Active subscriptions, Trialing, Past due, MRR (monthly recurring revenue, annual plans ÷ 12), Commission this month,
Payable to sellers.

### 4.5 Dashboard, Analytics & Reports, administration

**Dashboard** (`/dashboard`) — PDMS dashboard layout (Appendix A §4): dark hero "Welcome back, {first name}" with shortcuts
(New ticket, Payments, Shipments, Reports); stat cards: GMV this month, Captured payments, Net platform revenue (commissions +
subscription fees − gateway fees), Refund rate, Open tickets, SLA compliance, Shipments in transit, On-time delivery rate, Active
subscriptions; distribution cards (ticket status, payment status, shipment status); recent activity table. Each user sees only what
their role allows.

**Analytics & Reports** (`/analytics`) — filters: date range (default last 30 days), seller, payment method, courier. Charts
(Chart.js, Appendix A §5.13): Revenue by day (bar), Payments by method (pie), Refunds by reason (bar), Tickets by category (bar),
Ticket status (pie), Shipments by status (pie), On-time rate by courier (bar), Top 10 sellers by GMV (bar), MRR by plan (pie).
Every chart has an "Export CSV". All numbers are computed in SQL/PHP for the chosen range in the business time zone.

**Integrations** (`/integrations`, Admin) — tabs: **Clients** (create, scopes, show token once, revoke), **Webhooks** (endpoints,
event selection, secret shown once, "Send test event", enable/disable), **Deliveries** (status, attempts, response code, payload,
"Resend"), **API docs** (link to the OpenAPI document and a short getting-started text).

**Users & Roles** (`/users`, Admin) — create users, assign roles, activate/deactivate, unlock sign-in, attach the email used for
sign-in codes.

**Audit Logs** (`/audit-logs`, Admin) — as in PDMS.

---

## 5. Back-office API (used by the SFA frontend)

All under `/api/v1`, `auth:sanctum` + audit middleware, JSON, PDMS conventions. Lists accept `page`, `per_page`, `search`, the
filters named in §4, and `sort` (`-created_at` default). Every list returns the paginator + `summary`.

```
GET    /health
POST   /login                     POST /login/otp          POST /login/otp/resend
POST   /logout                    GET  /user               GET|PUT /profile        POST /change-password

GET|POST          /support/tickets
GET|PUT|DELETE    /support/tickets/{ticket}
POST              /support/tickets/{ticket}/assign          (assignee_id | "me")
POST              /support/tickets/{ticket}/status          (status, note)
GET|POST          /support/tickets/{ticket}/messages
GET               /support/attachments/{attachment}/download
GET|POST          /support/kb/categories     PUT|DELETE /support/kb/categories/{category}
GET|POST          /support/kb/articles       GET|PUT|DELETE /support/kb/articles/{article}
GET|POST          /support/canned-responses  PUT|DELETE /support/canned-responses/{response}
GET|PUT           /support/sla-policies

GET               /payments                  GET /payments/{payment}
POST              /payments/{payment}/capture      POST /payments/{payment}/cancel
POST              /payments/{payment}/simulate-webhook          (Admin, sandbox only)
POST              /payments/{payment}/cod-collected
GET               /payments/{payment}/receipt
GET|POST          /refunds                   GET /refunds/{refund}
POST              /refunds/{refund}/approve  POST /refunds/{refund}/reject
GET               /refunds/{refund}/photos/{attachment}
GET               /ledger                    (filters: entry_type, account, date range)
GET|POST          /reconciliations           GET /reconciliations/{run}
POST              /reconciliation-items/{item}/resolve
GET               /risk-reviews              POST /risk-reviews/{payment}/clear   POST /risk-reviews/{payment}/block

GET|POST          /logistics/shipments       GET|PUT /logistics/shipments/{shipment}
POST              /logistics/shipments/{shipment}/events
POST              /logistics/shipments/{shipment}/proof-of-delivery
POST              /logistics/quotes
GET|POST          /logistics/couriers        PUT|DELETE /logistics/couriers/{courier}
GET|POST          /logistics/zones           PUT|DELETE /logistics/zones/{zone}
GET|POST          /logistics/rates           PUT|DELETE /logistics/rates/{rate}
GET|POST          /logistics/warehouses      PUT|DELETE /logistics/warehouses/{warehouse}
GET               /locations/regions   /locations/provinces?region_id=   /locations/cities?province_id=   /locations/barangays?city_id=
POST              /locations/import

GET|POST          /plans                     PUT|DELETE /plans/{plan}
GET|POST          /subscriptions             GET /subscriptions/{subscription}
POST              /subscriptions/{subscription}/change-plan    /cancel    /resume
GET               /invoices                  GET /invoices/{invoice}
POST              /invoices/{invoice}/charge  POST /invoices/{invoice}/void
GET|POST          /commission-rules          PUT|DELETE /commission-rules/{rule}
GET               /commissions
GET               /settlements               POST /settlements/generate     GET /settlements/{settlement}
POST              /settlements/{settlement}/finalize   POST /settlements/{settlement}/pay   POST /settlements/{settlement}/void
GET               /payouts

GET               /dashboard                 GET /analytics/overview   GET /analytics/charts   GET /analytics/export?report=
GET|POST          /users                     PUT /users/{user}   POST /users/{user}/unlock
GET               /roles
GET|POST          /integration-clients       PUT|DELETE /integration-clients/{client}
POST              /integration-clients/{client}/tokens       DELETE /integration-clients/{client}/tokens/{token}
GET|POST          /webhook-endpoints         PUT|DELETE /webhook-endpoints/{endpoint}
POST              /webhook-endpoints/{endpoint}/test
GET               /webhook-deliveries        POST /webhook-deliveries/{delivery}/resend
GET               /audit-logs
GET               /notifications   GET /notifications/unread-count   PATCH /notifications/mark-all-as-read
PATCH             /notifications/{notification}/read    DELETE /notifications/{notification}
GET|PUT           /settings
```

---

## 6. Integration API (for the other systems of the cluster)

### 6.1 Clients and authentication

- An Admin creates an **integration client** (`name`, `system_code` e.g. `orders`, `checkout`, `storefront`, `accounts`, `catalog`,
  `finance-gl`, `contact_email`, `is_active`) and issues tokens for it. Tokens are Sanctum personal access tokens on the
  `IntegrationClient` model (it uses `HasApiTokens`), with **abilities = scopes**, shown once, revocable, `last_used_at` tracked.
- Requests: `Authorization: Bearer <token>`, `Accept: application/json`. Integration routes check abilities with Sanctum's
  `abilities`/`ability` middleware; staff tokens cannot call them and integration tokens cannot call back-office routes.
- Scopes: `customers:write`, `sellers:write`, `catalog:write`, `orders:write`, `payments:read`, `payments:write`, `refunds:write`,
  `quotes:read`, `shipments:read`, `shipments:write`, `tickets:read`, `tickets:write`, `kb:read`, `subscriptions:read`,
  `commissions:read`, `analytics:read`, `webhooks:manage`.
- Rate limit 120 requests per minute per client (429 + `Retry-After`).

### 6.2 Idempotency

`POST` requests that create money movements or records (`/integrations/payments`, `/integrations/refunds`,
`/integrations/shipments`, `/integrations/tickets`) **require** an `Idempotency-Key` header (max 255 chars). Store
`(client_id, key, request_hash, response_status, response_body, created_at)` for 24 hours: the same key with the same body
returns the stored response; the same key with a different body returns 422.

### 6.3 Endpoints (prefix `/api/v1/integrations`)

```
PUT    /customers/{external_id}             customers:write   upsert name, email, phone, default address
PUT    /sellers/{external_id}               sellers:write     upsert shop name, owner, email, payout destination (masked), pickup address
PUT    /categories/{external_id}            catalog:write     upsert name, parent
PUT    /orders/{external_id}                orders:write      upsert order + items (sellers/customers/categories by external_id)
POST   /orders/{external_id}/cancel         orders:write

POST   /payments                            payments:write    create a payment for an order → { payment, next_action }
GET    /payments/{reference}                payments:read
POST   /refunds                             refunds:write     request a refund (goes to Finance approval)
GET    /refunds/{reference}                 payments:read

POST   /quotes                              quotes:read       shipping options with fee and ETA
POST   /shipments                           shipments:write   create a shipment for an order + seller
GET    /shipments/{reference}               shipments:read    status + timeline + tracking URL

POST   /tickets                             tickets:write     open a ticket from the storefront / seller center
GET    /tickets/{reference}                 tickets:read      public conversation only (no internal notes)
POST   /tickets/{reference}/messages        tickets:write     customer or seller reply
POST   /tickets/{reference}/csat            tickets:write     score 1–5 + comment, only when resolved/closed
GET    /kb/articles  /kb/articles/{slug}    kb:read           published articles only

GET    /subscriptions?seller={external_id}  subscriptions:read
GET    /sellers/{external_id}/statements    commissions:read  settlements (finalized/paid)
GET    /analytics/summary?from=&to=         analytics:read    GMV, captured, refunds, revenue, tickets, on-time rate

GET|POST /webhook-endpoints                 webhooks:manage   a client manages its own endpoints
PUT|DELETE /webhook-endpoints/{id}          webhooks:manage
```

Every response uses the same shapes as the back-office API; money fields are integers in centavos with `currency: "PHP"`.

### 6.4 Outbound webhooks

- Endpoint: `url` (HTTPS only, except `http://localhost` in development), `events` (list), `secret` (shown once), `is_active`.
- Delivery: `POST` JSON `{ "id": "evt_…", "type": "payment.captured", "created_at": "<ISO 8601 UTC>", "data": { … } }` with headers
  `X-SFA-Event`, `X-SFA-Delivery` (unique id), `X-SFA-Signature: t=<unix seconds>,v1=<hex HMAC-SHA256 of "<t>.<raw body>" with the secret>`.
  Receivers should reject timestamps older than 5 minutes. A 2xx answer within 10 seconds is a success.
- Retries: 1 min, 5 min, 30 min, 2 h, 12 h (5 attempts), then `failed`; an endpoint with 20 consecutive failures is disabled and
  Admins are notified. Every attempt is logged in `webhook_deliveries` (request body, response status, first 2 KB of the response, duration).
- Events: `ticket.created`, `ticket.status_changed`, `ticket.replied` (public replies only), `payment.authorized`, `payment.captured`,
  `payment.failed`, `payment.cancelled`, `payment.disputed`, `refund.requested`, `refund.approved`, `refund.rejected`,
  `refund.completed`, `refund.failed`, `shipment.created`, `shipment.status_changed`, `shipment.delivered`,
  `subscription.created`, `subscription.renewed`, `subscription.past_due`, `subscription.cancelled`, `invoice.paid`,
  `settlement.finalized`, `payout.paid`. Document each payload in the OpenAPI file.

### 6.5 Scheduled work (works on Vercel)

Vercel has no long-running workers, and on the Hobby plan a Vercel Cron job can run at most once per day (check the current
limits of the plan in use). So:
- Due work (expire pending payments, SLA state notifications, auto-close resolved tickets, subscription renewals and dunning,
  webhook retries, weekly settlement generation) is implemented as idempotent jobs in one `Scheduler` service.
- `GET /api/v1/cron/run` runs all due jobs; it is protected by `Authorization: Bearer <CRON_SECRET>` (Vercel Cron sends this header
  when the `CRON_SECRET` environment variable is set) and registered in `vercel.json` `crons`.
- In addition, after any authenticated request the app runs the scheduler **after the response is sent** at most once every
  5 minutes, guarded by a database lock, so time-based work happens even without frequent cron calls.
- Locally: `php artisan sfa:run-scheduled` (and `schedule:work` calling it every minute).

### 6.6 Documentation

`docs/openapi.yaml` (OpenAPI 3.1) describes every back-office and integration endpoint, schemas, errors, security schemes and
webhook payloads; it is served at `GET /api/v1/openapi.yaml`. `docs/INTEGRATION.md` explains clients, scopes, idempotency, signatures
(with a PHP and a Node.js verification example) and the event catalog. A feature test fails if a route in `routes/api.php` is missing
from `openapi.yaml`.

---

## 7. Security

- Server-side authorization on every route (roles/permissions/abilities); never trust the client.
- Validate every input with Form Requests (types, enums, ranges, existence); reject unknown status transitions with 422.
- Never store card numbers, CVV or wallet credentials; only gateway references. Mask payout destinations (`•••• 1234`).
- Webhook signatures checked with `hash_equals`; inbound events de-duplicated; payloads stored for audit.
- Money-changing operations run in database transactions with row locks (`lockForUpdate`) to prevent double refunds/payouts.
- Rate limits: login (lockout as in PDMS), integration API (120/min/client), webhooks inbound (300/min/gateway).
- Secrets only in environment variables; `.env.example` lists every variable; tokens and webhook secrets are shown once and stored hashed
  (webhook secrets encrypted with Laravel's encrypter because they are needed to sign).
- CORS limited to the SPA origin(s); `APP_DEBUG=false` in production; the health endpoint never exposes error details.
- Every PostgreSQL table enables row-level security in its migration (PDMS convention).

---

## 8. Seed data and demo accounts

- Roles and permissions (§1.1). Users (password `password`, all `@example.com`): `admin@example.com` (Admin),
  `finance@example.com` (Finance), `support.one@example.com` and `support.two@example.com` (Support Agent),
  `logistics@example.com` (Logistics Coordinator), `analyst@example.com` (Analyst).
- Demo customers, sellers, categories and about 60 orders over the last 45 days with payments in every status, refunds, tickets in
  every status and SLA state, shipments in every status with timelines, plans, subscriptions (incl. trialing and past due),
  commission rules, entries and two settlement periods. Demo data is created only by seeders and is clearly fake.
- One integration client per sibling system with a printed demo token (development only), and one webhook endpoint pointing to
  `http://localhost:8787/webhooks` disabled by default.

---

## 9. Allowed additions to the PDMS stack

Only these, and only for the stated purpose (pin versions that are compatible with React 19 and Laravel 12, and say which in `docs/DECISIONS.md`):

- `leaflet` + `react-leaflet` with OpenStreetMap tiles for the optional shipment map (with the required OSM attribution).
- Nothing else. Charts stay on Chart.js, icons on Font Awesome 7 (+ Bootstrap Icons where PDMS uses them), state on TanStack Query + Zustand,
  HTTP on Axios, CSV and printing done by hand like PDMS.

---

## 10. Repository layout and deployment

```
sfa/
├── AGENTS.md                 # how to run, test, deploy; conventions (like PDMS's)
├── vercel.json               # services: frontend (vite) + backend (container); /api/* → backend; crons
├── docs/                     # DECISIONS.md, INTEGRATION.md, openapi.yaml, DESIGN.md (copy of Appendix A), TECH-STACK.md
├── backend/                  # Laravel 12
│   ├── Dockerfile.vercel     # FrankenPHP 1 / PHP 8.4 alpine, pdo_pgsql, socat, uploads 5M/6M, CMD sh /app/start.sh
│   ├── Caddyfile             # :9180, bind 127.0.0.1, root /app/public, php_server
│   ├── start.sh              # the start-up gate from Appendix B §4
│   ├── public/ready.php
│   └── …
└── frontend/                 # React 19 + Vite 8 SPA, copy of the PDMS shell (layout, ui, auth, lib/api.js, lib/dates.js)
```

- Deploy like PDMS (Appendix B §4): one Vercel project with two services, its **own** Supabase project (do not share PDMS's
  connection pool), region `sin1`, environment variables in the dashboard, migrations run from a local machine.
- Environment variable prefix for app settings: `SFA_` (e.g. `SFA_TIMEZONE`, `SFA_LOGIN_OTP`, `SFA_SANDBOX_GATEWAY=true`), plus
  `CRON_SECRET`, `DB_*`, `MAIL_*`, `APP_*`, `VITE_API_URL=/api/v1`.

---

## 11. Tests and acceptance

Backend (PHPUnit feature tests, SQLite in memory):
- Auth: login, lockout steps and messages, OTP on/off, logout deletes the token, session token cannot reach integration routes.
- For every endpoint: 401 without token, 403 for a role without permission, 422 on invalid input, happy path, list shape + summary.
- State machines: every allowed transition succeeds and every other one returns 422 (tickets, payments, refunds, shipments,
  subscriptions, invoices, settlements, payouts).
- Money: ledger balances after every scenario; commission rounding cases (e.g. ₱333.33 × 7.5 %); refund cannot exceed captured;
  concurrent refund requests cannot over-refund; proration examples; settlement totals equal the sum of their parts.
- Integration: scopes enforced, idempotency replay and conflict, webhook signature format (verify with a known secret and body),
  retries and disabling, inbound gateway/courier webhooks de-duplicated, OpenAPI covers every route.
- Scheduler: each job is idempotent (running it twice changes nothing the second time).

Frontend: `npm run lint` and `npm run build` pass. Each page has loading skeletons, empty states, error states with "Try again",
confirm dialogs for destructive actions, disabled buttons while saving, CSV export, and works at 375 px width.

Acceptance (check before declaring a phase done):
1. Put SFA next to PDMS: same sidebar, top bar, fonts, colors, cards, tables, pills, modals, buttons, login page, footer.
2. Every route in §5 and §6 exists, is in `openapi.yaml`, and has tests.
3. A full scenario works end to end with the demo data: order synced → payment captured (sandbox) → commission entries → shipment
   created, delivered with proof → ticket opened about it → refund requested by support, approved by finance, completed →
   commission reversed → weekly settlement generated, finalized, paid → webhooks delivered and signed → dashboard and analytics updated.

---

## 12. Delivery plan (stop after each phase and wait for "continue")

- **Phase 1 — Foundation:** repository layout, `composer.json`, `package.json`, configs, Tailwind/Vite setup, the PDMS shell
  (MainLayout, Sidebar with this system's items, Topbar, Modal, ConfirmDialog, Pagination, PageLoader, NotificationBell,
  SessionTimeout, ProtectedRoute), Login page, auth backend (login, lockout, OTP, logout, profile), roles/permissions seeder, users
  admin, audit log, notifications, settings, health, response cache, integration clients + tokens + scopes, idempotency middleware,
  webhook infrastructure (endpoints, deliveries, signer, retries), scheduler + cron endpoint, `docs/DECISIONS.md`, tests.
- **Phase 2 — Customer Support** (backend, frontend, seeders, tests).
- **Phase 3 — Payments & Transactions** (orders mirror, gateway interface + sandbox + COD, payments, ledger, refunds, reconciliation,
  risk review, receipts, tests).
- **Phase 4 — Logistics & Locations** (PSGC import, addresses, warehouses, couriers, zones, rates, quotes, shipments, events, proof
  of delivery, courier webhooks, optional map, tests).
- **Phase 5 — Subscriptions & Commissions** (plans, subscriptions, invoices, dunning, proration, commission rules and entries,
  settlements, payouts, statements, tests).
- **Phase 6 — Dashboard, Analytics & Reports** (dashboard, analytics endpoints and page, CSV exports, tests).
- **Phase 7 — Integration API and release:** all `/integrations` endpoints, `openapi.yaml` + coverage test, `INTEGRATION.md`,
  full demo seeders, Vercel files (`vercel.json`, `Dockerfile.vercel`, `Caddyfile`, `start.sh`, `ready.php`), `AGENTS.md`,
  `.env.example`, and the final acceptance checklist with the commands to run it.

At the start of Phase 1, write a short plan (files per phase) and the assumptions you made, then start writing code in the same reply.
At the end of every phase list: the files written, the commands to run (migrate, seed, test, lint, build), and what the next phase contains.

---

# APPENDIX A — DESIGN.md (authoritative design system, copied from PDMS)

# DESIGN.md — Archon Nell design system

The visual language of the Performance & Development Management System (PDMS) of Archon Nell.
Every system built in the same cluster must follow it, so that all of them look and behave like one product.

Everything here is taken from the PDMS source (`frontend/src`). Class strings are Tailwind CSS 3 utilities and
are meant to be copied exactly. When this document and a guess disagree, this document wins.

---

## 1. Brand

| Item | Value |
|---|---|
| Company name | Archon Nell (full: "Archon Nell Incorporated") |
| Browser tab title | `Archon Nell` |
| Logo on light backgrounds | `/logo.png` (login card, documents) |
| Logo on the dark sidebar | `/logo-dark.png` (same logo with white lettering) |
| Favicon / touch icon | `/favicon.png` |
| Product subtitle pattern | "<System name>" under the company name, e.g. "Performance & Development Management" |
| Footer text | `© {current year} Archon Nell. All rights reserved.` |

Copy the three image files from the PDMS `frontend/public/` folder; do not redraw or recolor the logo.

---

## 2. Foundations

### 2.1 Color tokens (`tailwind.config.js`, verbatim)

```js
/** @type {import('tailwindcss').Config} */
export default {
  content: [
    "./index.html",
    "./src/**/*.{js,ts,jsx,tsx}",
  ],
  theme: {
    extend: {
      colors: {
        'brand-dark': '#0f172a',
        'brand-blue': '#1e3a8a',
        'brand-light-blue': '#e0f2fe',
        'brand-orange': '#ea580c',
        'brand-light-orange': '#ffedd5',
        'brand-red': '#dc2626',
        'brand-gray': '#f8fafc',
        'sidebar-bg': '#0f172a',
        'sidebar-hover': '#1e293b',
      },
      fontFamily: {
        sans: ['Inter', 'sans-serif'],
      },
    },
  },
  plugins: [],
}
```

| Token | Hex | Used for |
|---|---|---|
| `brand-dark` | `#0f172a` | Login background, dashboard hero banner, dark buttons, avatar initials tiles |
| `brand-blue` | `#1e3a8a` | Primary buttons, active tab, links, focus borders, current page in pagination, eyebrow text |
| `brand-gray` | `#f8fafc` | Background of the content area inside the app shell |
| `sidebar-bg` | `#0f172a` | Sidebar |
| `sidebar-hover` | `#1e293b` | Sidebar item hover |
| `brand-red` | `#dc2626` | Destructive emphasis |
| `brand-orange`, `brand-light-orange`, `brand-light-blue` | | Available accents (rarely used) |

Everything else uses the stock Tailwind palette, mostly `slate` for neutrals:

| Purpose | Classes |
|---|---|
| Page body (outside the shell) | `#f1f5f9` background, `#1e293b` text (set in `index.css`) |
| Headings | `text-slate-900` |
| Body text | `text-slate-700` / `text-slate-600` |
| Secondary / helper text | `text-slate-500`, quieter `text-slate-400` |
| Card border | `border-slate-200`; dividers inside tables `divide-slate-100` |
| Input border | `border-slate-300` |
| Subtle fills | `bg-slate-50`, `bg-slate-100` |

Semantic tones (light background + dark text, used by pills, icon tiles and notices):

| Meaning | Pill / tile | Ring (pills) |
|---|---|---|
| Success / active / approved / paid | `bg-emerald-50 text-emerald-700` | `ring-emerald-600/20` |
| Warning / pending / inactive-ish | `bg-amber-50 text-amber-700` | `ring-amber-600/20` |
| Danger / failed / terminated / rejected | `bg-red-50 text-red-700` | `ring-red-600/20` |
| Info / in progress | `bg-blue-50 text-blue-700` | `ring-blue-600/20` |
| Neutral / draft / unknown | `bg-slate-100 text-slate-700` | `ring-slate-500/20` |
| Extra category accents | `violet`, `teal`, `orange`, `purple` at `-50` background / `-700` text | |

### 2.2 Global stylesheet (`src/index.css`, verbatim)

```css
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');

@tailwind base;
@tailwind components;
@tailwind utilities;

@layer base {
  body {
    font-family: 'Inter', sans-serif;
    background-color: #f1f5f9;
    color: #1e293b;
  }
}

/* Force <select> option lists to always open downward.
   Uses Chromium's customizable select (appearance: base-select); other
   browsers ignore this and keep native behavior. */
select,
::picker(select) {
  appearance: base-select;
}

::picker(select) {
  position-area: block-end span-inline-end;
  position-try-fallbacks: none;
  margin-block-start: 4px;
  min-width: anchor-size(width);
  max-height: 16rem;
  overflow-y: auto;
  border: 1px solid #cbd5e1;
  border-radius: 0.5rem;
  background: #ffffff;
  box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
}

select option {
  padding: 0.5rem 0.75rem;
  font-size: 0.875rem;
}

select option:hover,
select option:focus {
  background-color: #eff6ff;
}

@layer components {
  .custom-scrollbar::-webkit-scrollbar {
    width: 6px;
  }
  .custom-scrollbar::-webkit-scrollbar-track {
    background: transparent;
  }
  .custom-scrollbar::-webkit-scrollbar-thumb {
    background-color: #cbd5e1;
    border-radius: 20px;
  }
}
```

`postcss.config.js`:

```js
export default {
  plugins: {
    tailwindcss: {},
    autoprefixer: {},
  },
}
```

Imports at the top of `src/main.jsx` (both icon sets are used):

```js
import '@fortawesome/fontawesome-free/css/all.min.css'
import 'bootstrap-icons/font/bootstrap-icons.css'
import './index.css'
```

### 2.3 Typography

Font: **Inter**, weights 400, 500, 600, 700 only.

| Role | Classes |
|---|---|
| Page title (h1) | `text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl` |
| Page eyebrow (above h1) | `text-xs font-semibold uppercase tracking-[0.18em] text-brand-blue` |
| Page description | `text-sm text-slate-500` |
| Section / card title (h2) | `text-base font-semibold text-slate-900` |
| Section description | `mt-0.5 text-xs text-slate-500` |
| Card label / stat label | `text-xs font-semibold uppercase tracking-wider text-slate-500` |
| Big stat number | `text-3xl font-bold tracking-tight text-slate-900` (stat card), `text-2xl font-bold text-slate-900` (summary card) |
| Table header | `text-[11px] font-semibold uppercase tracking-wider text-slate-500` |
| Body / table cell | `text-sm` |
| Form label | `text-xs font-semibold text-slate-700` |
| Codes / IDs (e.g. employee code, reference no.) | `font-mono text-xs font-semibold text-slate-600` |
| Numbers in tiles | add `tabular-nums` |

### 2.4 Shape, depth, spacing

| Item | Value |
|---|---|
| Cards, sections, panels | `rounded-xl border border-slate-200 bg-white shadow-sm` |
| Card padding | `p-5` (`sm:p-6` for large cards); compact summary cards `p-4` |
| Buttons, inputs, icon tiles | `rounded-lg` |
| Pills / badges / avatars | `rounded-full` |
| Login card | `rounded-2xl shadow-xl` |
| Dropdown menus | `rounded-xl border border-slate-200 bg-white shadow-lg` |
| Vertical rhythm of a page | `space-y-6` between sections |
| Grid gaps | `gap-4` (stat cards), `gap-3` (summary cards), `gap-5`/`gap-6` (charts, larger cards) |
| Content width | `mx-auto w-full min-w-0 max-w-7xl` |

### 2.5 Icons

- **Font Awesome Free 7** is the main set: `fa-solid` for actions and navigation, `fa-regular` for light glyphs
  (`fa-regular fa-eye`, `fa-regular fa-bell`, `fa-regular fa-calendar`, `fa-regular fa-user`).
- **Bootstrap Icons 1.11** appear in a few places: pagination chevrons (`bi bi-chevron-left/right`),
  the confirm dialog icon and score badges. Keep them there; use Font Awesome everywhere else.
- Decorative icons always get `aria-hidden="true"`. Icon-only buttons always get an `aria-label`.
- Spinner: `fa-solid fa-circle-notch animate-spin`. Refresh: `fa-solid fa-rotate-right` (spins while fetching).

### 2.6 Motion

Only small, functional motion: `transition` / `transition-colors` on interactive elements, `hover:-translate-y-0.5 hover:shadow-md`
on stat cards, `animate-pulse` skeletons, `animate-spin` spinners, sidebar slide `transition-transform duration-200 ease-in-out`.
No page transitions, no bouncing, no parallax.

---

## 3. App shell

```
┌──────────────┬──────────────────────────────────────────────────────┐
│              │ Topbar  h-16  white, border-b                        │
│  Sidebar     ├──────────────────────────────────────────────────────┤
│  w-64        │ main  p-4 sm:p-6  bg-brand-gray  (scrolls)           │
│  #0f172a     │   max-w-7xl centered                                 │
│  (off-canvas │   page content …                                     │
│   below md)  │   footer (border-t, © Archon Nell …)                 │
└──────────────┴──────────────────────────────────────────────────────┘
```

### 3.1 `MainLayout.jsx` (verbatim)

```jsx
import { useState } from 'react'
import Sidebar from './Sidebar'
import Topbar from './Topbar'

export default function MainLayout({ children }) {
  const [sidebarOpen, setSidebarOpen] = useState(false)

  return (
    <div className="flex h-screen overflow-hidden text-slate-800">
      <Sidebar isOpen={sidebarOpen} onClose={() => setSidebarOpen(false)} />

      <div className="flex min-w-0 flex-1 flex-col overflow-hidden bg-brand-gray">
        <Topbar sidebarOpen={sidebarOpen} onMenuClick={() => setSidebarOpen(true)} />

        <main className="custom-scrollbar flex-1 overflow-y-auto overflow-x-hidden p-4 sm:p-6">
          <div className="mx-auto w-full min-w-0 max-w-7xl">
            {children}

            <footer className="mt-8 flex flex-col items-center justify-between gap-4 border-t border-slate-200 pb-2 pt-4 md:flex-row">
              <p className="text-center text-xs text-slate-500 md:text-left">
                &copy; {new Date().getFullYear()} Archon Nell. All rights reserved.
              </p>
              <div className="flex flex-wrap items-center justify-center gap-4 text-xs text-slate-500">
                <a className="hover:text-brand-blue" href="#">Privacy Policy</a>
                <a className="hover:text-brand-blue" href="#">Terms of Service</a>
                <a className="hover:text-brand-blue" href="#">Help Center</a>
              </div>
            </footer>
          </div>
        </main>
      </div>
    </div>
  )
}
```

Every signed-in page renders inside `<MainLayout>`. The login page does not.

### 3.2 Sidebar

- `aside`: `fixed inset-y-0 left-0 z-40 flex h-full w-72 max-w-[85vw] flex-col bg-sidebar-bg text-white shadow-2xl transform transition-transform duration-200 ease-in-out md:static md:z-auto md:w-64 md:max-w-none md:translate-x-0 md:shadow-none`,
  plus `translate-x-0` when open and `-translate-x-full` when closed (mobile only).
- Logo bar: `flex h-16 shrink-0 items-center justify-between gap-3 border-b border-slate-700 px-4 md:px-6`, containing
  `<img src="/logo-dark.png" alt="Archon Nell Incorporated" className="h-14 w-auto shrink-0" />` and, below `md`,
  a close button (`fa-solid fa-xmark`).
- Nav list wrapper: `flex-1 overflow-y-auto custom-scrollbar py-4 px-3`; list `space-y-1 text-sm font-medium`.
- Nav item: `flex items-center gap-3 px-3 py-2 rounded-lg transition-colors` +
  active `bg-blue-900/50 text-blue-400`, inactive `text-slate-300 hover:bg-sidebar-hover hover:text-white`.
- Item icon: `<i className="fa-solid {icon} w-5 text-center" />`, then the module name in Title Case.
- Bottom spacer: `p-4 border-t border-slate-700`.
- Mobile backdrop (when open): `fixed inset-0 z-30 bg-slate-950/55 backdrop-blur-[1px] md:hidden`; Escape closes it; body scroll locked.
- Items come from one array `{ name, path, icon }`; Admin-only items are appended for Admins (PDMS adds "Audit Logs" with `fa-shield-halved`).
- Active rule: exact match, or `location.pathname.startsWith(item.path)`; Dashboard is also active on `/`.

### 3.3 Topbar

- `header`: `relative z-20 flex h-16 shrink-0 items-center justify-between gap-2 border-b border-slate-200 bg-white px-3 sm:px-6`.
- Left: hamburger (below `md`, `fa-solid fa-bars`, `h-10 w-10 rounded-lg border border-slate-200 bg-white text-slate-500 shadow-sm`),
  the page title on phones (`text-sm font-semibold text-slate-700 sm:hidden`), and from `sm` up a search box:
  wrapper `relative hidden w-full max-w-md sm:block`, icon `fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-sm text-slate-400`,
  input `w-full rounded-lg border border-slate-200 bg-slate-50 py-2 pl-9 pr-4 text-sm transition-all focus:border-brand-blue/50 focus:outline-none focus:ring-2 focus:ring-brand-blue/20`.
- Right (`flex shrink-0 items-center gap-3 sm:gap-5`): notification bell, then the profile pill.
- Profile pill: `flex items-center gap-2 rounded-full border border-slate-200 bg-white py-1.5 pl-1.5 pr-3 text-left shadow-sm transition-all hover:border-slate-300 hover:bg-slate-50`,
  avatar `h-8 w-8 rounded-full` (fallback `https://ui-avatars.com/api/?name=<name>&background=1e3a8a&color=fff`),
  name `text-sm font-semibold leading-tight text-slate-700` and role `text-xs leading-tight text-slate-500` (from `md`), chevron rotates 180° when open.
- Profile menu: `absolute right-0 z-50 mt-2 w-48 rounded-xl border border-slate-200 bg-white py-1 shadow-lg`; header block with name/email;
  items `flex w-full items-center gap-2 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50` ("Profile" → `/settings`);
  "Logout" item `text-red-600 hover:bg-red-50` with `fa-solid fa-arrow-right-from-bracket`. Closes on outside click.
- Notification bell: `fa-regular fa-bell text-lg` in `relative text-slate-400 hover:text-slate-600 rounded-lg p-1`; unread badge
  `absolute -right-1 -top-1 flex h-4 min-w-4 items-center justify-center rounded-full border-2 border-white bg-red-500 px-1 text-[10px] font-bold text-white`;
  panel `absolute right-0 z-50 mt-2 w-80 sm:w-96 rounded-xl border border-slate-200 bg-white py-2 shadow-lg`, header row
  "Notifications" + "Mark all read" link (`text-xs font-medium text-brand-blue`) + refresh icon, list `max-h-96 overflow-y-auto`. Unread count polls every 30 s.

---

## 4. Page anatomy

A list/management page is built from these blocks, top to bottom, with `space-y-6`:

1. **Page header**
   ```jsx
   <header className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
     <div>
       <p className="text-xs font-semibold uppercase tracking-[0.18em] text-brand-blue">People operations</p>
       <h1 className="mt-1 text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">Employee directory</h1>
       <p className="mt-1 text-sm text-slate-500">Search and view workforce records.</p>
     </div>
     <div className="flex flex-wrap gap-2">{/* secondary buttons first, primary button last */}</div>
   </header>
   ```
2. **Notice** (optional, after an action) — see §5.10.
3. **Summary cards row** — `grid grid-cols-2 gap-3 lg:grid-cols-4` of summary cards (§5.6).
4. **Main section card** with a toolbar, the list (table or card grid), and pagination (§5.7, §5.11).
5. **Modals** for create / edit / view (§5.8).

The **dashboard** opens with a dark hero banner instead of a page header:

```jsx
<section className="overflow-hidden rounded-xl bg-brand-dark text-white shadow-sm">
  <div className="grid gap-6 px-5 py-6 sm:px-7 lg:grid-cols-[1fr_auto] lg:items-center">
    <div>
      <p className="text-xs font-semibold uppercase tracking-[0.18em] text-blue-200">Organization overview</p>
      <h1 className="mt-2 text-2xl font-bold tracking-tight sm:text-3xl">Welcome back, {firstName}</h1>
      <p className="mt-2 max-w-2xl text-sm leading-6 text-slate-300">One-line description of the system.</p>
      <p className="mt-4 flex items-center gap-2 text-xs font-medium text-slate-400">
        <i className="fa-regular fa-calendar" aria-hidden="true" />{today /* e.g. "Friday, October 9" */}
      </p>
    </div>
    <nav className="flex flex-wrap gap-2" aria-label="Dashboard shortcuts">
      <a className="inline-flex items-center gap-2 rounded-lg border border-white/15 bg-white/10 px-3.5 py-2.5 text-xs font-semibold text-white transition hover:border-white/25 hover:bg-white/15 focus:outline-none focus:ring-2 focus:ring-blue-300">
        <i className="fa-solid fa-bullseye" aria-hidden="true" />Shortcut
      </a>
    </nav>
  </div>
</section>
```

Then: a "snapshot" section of stat cards (`grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3`), health/score cards,
status distribution cards (`grid grid-cols-1 gap-5 lg:grid-cols-3`), and a "Recent activity" table card.

Section heading pattern (outside cards):

```jsx
<div className="mb-3 flex items-end justify-between gap-4">
  <div>
    <h2 className="text-base font-semibold text-slate-900">Workforce snapshot</h2>
    <p className="mt-0.5 text-xs text-slate-500">Key indicators across the organization</p>
  </div>
  {isFetching && !isLoading && <span className="text-xs font-medium text-slate-400">Refreshing data...</span>}
</div>
```

---

## 5. Components

### 5.1 Buttons

| Kind | Classes |
|---|---|
| Primary (create, save) | `inline-flex items-center justify-center gap-2 rounded-lg bg-brand-blue px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-900 focus:outline-none focus:ring-2 focus:ring-blue-300 disabled:opacity-60` |
| Dark (strong secondary, "Try again", login submit) | `inline-flex items-center justify-center gap-2 rounded-lg bg-brand-dark px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-400` |
| Secondary / outline (Cancel, Export CSV) | `inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-slate-300 disabled:opacity-60` |
| Ghost (Clear filters) | `rounded-lg px-3 py-2.5 text-sm font-semibold text-slate-500 transition hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-300` |
| Danger (confirm delete) | `px-4 py-2 rounded-lg text-sm font-medium transition-colors bg-red-600 hover:bg-red-700 text-white` |
| Small dark (inline actions) | `inline-flex items-center gap-2 rounded-lg bg-brand-dark px-3 py-2 text-xs font-semibold text-white hover:bg-slate-800` |
| Icon-only (row actions) | `flex h-8 w-8 items-center justify-center rounded-lg text-slate-500 transition hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-300` + `title` + `aria-label` |
| Text link | `inline-flex items-center gap-1.5 text-xs font-semibold text-brand-blue hover:text-blue-700` (often ends with `fa-arrow-right`) |

Rules: every button has a leading Font Awesome icon (`fa-plus` create, `fa-floppy-disk` save, `fa-file-arrow-down` export,
`fa-pen` edit, `fa-trash` delete, `fa-regular fa-eye` view). While busy the icon becomes `fa-circle-notch animate-spin`
and the label becomes "Saving...", "Exporting...", etc. Buttons are disabled while their request runs. Labels are verb-first,
sentence case: "Create course", "Export CSV", "Update goal".

### 5.2 Form fields

```jsx
const fieldClass = 'mt-1.5 w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-brand-blue focus:ring-2 focus:ring-blue-100'

function Field({ label, htmlFor, children }) {
  return (
    <div>
      <label htmlFor={htmlFor} className="text-xs font-semibold text-slate-700">{label}</label>
      {children}
    </div>
  )
}
```

- `input`, `select` and `textarea` all use `fieldClass`. Every control has a `<label htmlFor>`.
- Optional hint under a field: `mt-1 text-xs text-slate-500`.
- Layout: `form className="space-y-5"`; two columns with `grid grid-cols-1 gap-4 sm:grid-cols-2`.
- Grouped fields: `<fieldset className="rounded-lg border border-slate-200 p-4">` with `<legend className="px-1 text-xs font-semibold text-slate-700">`.
- Toggle row (checkbox with explanation):
  `<label className="flex cursor-pointer items-center gap-3 rounded-lg border border-slate-200 bg-slate-50 p-3">`, checkbox first,
  then a `text-sm font-semibold text-slate-700` title and a `text-xs text-slate-500` line.
- Form-level error at the top of the form:
  ```jsx
  <div role="alert" className="flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">
    <i className="fa-solid fa-circle-exclamation mt-0.5" aria-hidden="true" />{formError}
  </div>
  ```
- Footer (always last):
  ```jsx
  <div className="flex flex-col-reverse gap-3 border-t border-slate-200 pt-4 sm:flex-row sm:justify-end">
    <button type="button" onClick={onCancel} className="rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">Cancel</button>
    <button type="submit" disabled={isPending} className="inline-flex items-center justify-center gap-2 rounded-lg bg-brand-blue px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-900 disabled:opacity-60">
      <i className={`fa-solid ${isPending ? 'fa-circle-notch animate-spin' : 'fa-floppy-disk'}`} aria-hidden="true" />
      {isPending ? 'Saving...' : editingId ? 'Update item' : 'Create item'}
    </button>
  </div>
  ```
- Native validation is used (`required`, `min`, `max`, `maxLength`); server 422 messages are shown in the form-level alert.
- Date fields for things still to come get `min` = today (local date, `YYYY-MM-DD`, built from `getFullYear/getMonth/getDate`, never
  `toISOString()`); end dates get `min` = the start date. A record opened for editing keeps its existing past date valid.

Filter toolbar controls (inside a section card) use a slightly different, compact style:

- Search: `<label className="relative">` with `fa-solid fa-magnifying-glass absolute left-3 top-3 text-xs text-slate-400` and input
  `w-full rounded-lg border border-slate-300 py-2.5 pl-9 pr-3 text-sm outline-none focus:border-brand-blue focus:ring-2 focus:ring-blue-100` (`type="search"`, debounced 350 ms, resets to page 1).
- Filter select: `rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-700 outline-none focus:border-brand-blue focus:ring-2 focus:ring-blue-100`, first option "All …", with `aria-label`.
- A ghost "Clear" button resets all filters.

### 5.3 Status pills

```jsx
const statusColors = {
  active: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  inactive: 'bg-slate-100 text-slate-700 ring-slate-500/20',
  terminated: 'bg-red-50 text-red-700 ring-red-600/20',
}

<span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold capitalize ring-1 ring-inset ${statusColors[status] || statusColors.inactive}`}>{status}</span>
```

Map every status of a new system onto the tones in §2.1 (success, warning, danger, info, neutral). Keep one map per
entity in a `src/lib/<module>.js` file, next to the human labels.

Score/health badge (no ring, with an icon): `inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold` + tone, e.g.
`bg-emerald-50 text-emerald-700` + `bi bi-trophy-fill`.

### 5.4 Avatars / initials

Initials tile in tables: `flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg bg-brand-dark text-xs font-bold text-white`
(first letters of the first two name parts, uppercase). Photo avatars: `h-8 w-8 rounded-full`.

### 5.5 Stat card (dashboard)

```jsx
export default function StatCard({ title, value, icon, iconBg, iconColor, accent, helper, isLoading }) {
  return (
    <article className="relative overflow-hidden rounded-xl border border-slate-200 bg-white p-5 shadow-sm transition duration-200 hover:-translate-y-0.5 hover:shadow-md">
      <span className={`absolute inset-x-0 top-0 h-1 ${accent}`} aria-hidden="true" />
      <div className="flex items-start justify-between gap-4">
        <div className="min-w-0">
          <p className="text-xs font-semibold uppercase tracking-wider text-slate-500">{title}</p>
          {isLoading ? (
            <div className="mt-3 h-8 w-16 animate-pulse rounded bg-slate-100" aria-label={`Loading ${title}`} />
          ) : (
            <p className="mt-2 text-3xl font-bold tracking-tight text-slate-900">{value}</p>
          )}
          <p className="mt-2 truncate text-xs text-slate-500">{helper}</p>
        </div>
        <div className={`flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-lg ${iconBg} ${iconColor}`}>
          <i className={`fa-solid ${icon} text-base`} aria-hidden="true" />
        </div>
      </div>
    </article>
  )
}
```

Accent/icon pairs used: `bg-slate-800` + `bg-slate-100 text-slate-700`; `bg-blue-600` + `bg-blue-50 text-blue-700`;
`bg-violet-600` + `bg-violet-50 text-violet-700`; `bg-teal-600` + `bg-teal-50 text-teal-700`;
`bg-amber-500` + `bg-amber-50 text-amber-700`; `bg-orange-500` + `bg-orange-50 text-orange-700`.

### 5.6 Summary card (top of list pages)

```jsx
function SummaryCard({ label, value, icon, tone, isLoading }) {
  return (
    <article className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
      <div className="flex items-center justify-between gap-3">
        <div>
          <p className="text-xs font-semibold uppercase tracking-wider text-slate-500">{label}</p>
          {isLoading ? <div className="mt-2 h-7 w-12 animate-pulse rounded bg-slate-100" /> : <p className="mt-1 text-2xl font-bold text-slate-900">{value}</p>}
        </div>
        <span className={`flex h-10 w-10 items-center justify-center rounded-lg ${tone}`}><i className={`fa-solid ${icon}`} aria-hidden="true" /></span>
      </div>
    </article>
  )
}
```

`tone` is a semantic tone from §2.1 (e.g. `bg-emerald-50 text-emerald-700`). The numbers come from a `summary` object the list API returns (§ API conventions in the tech stack document).

### 5.7 Section card with table

```jsx
<section className="rounded-xl border border-slate-200 bg-white shadow-sm" aria-labelledby="list-heading">
  <div className="border-b border-slate-200 p-4 sm:p-5">
    <div className="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
      <div>
        <h2 id="list-heading" className="text-base font-semibold text-slate-900">Employees</h2>
        <p className="mt-0.5 text-xs text-slate-500">{total} matching records</p>
      </div>
      <div className="grid flex-1 grid-cols-1 gap-2 sm:grid-cols-2 xl:max-w-4xl xl:grid-cols-[1.5fr_repeat(3,1fr)_auto]">
        {/* search, filter selects, Clear */}
      </div>
    </div>
  </div>

  <div className="overflow-x-auto">
    <table className="w-full min-w-[940px] text-left text-sm">
      <thead className="border-b border-slate-200 bg-slate-50 text-[11px] font-semibold uppercase tracking-wider text-slate-500">
        <tr><th className="py-3 pl-4 pr-4">Name</th><th className="py-3 pr-4">Status</th><th className="py-3 pr-4 text-right">Actions</th></tr>
      </thead>
      <tbody className="divide-y divide-slate-100">
        <tr className="transition-colors hover:bg-slate-50">
          <td className="py-3.5 pl-4 pr-4">…</td><td className="py-3.5 pr-4">…</td>
          <td className="py-3.5 pr-4"><div className="flex items-center justify-end gap-1">{/* icon buttons */}</div></td>
        </tr>
      </tbody>
    </table>
  </div>
  {isFetching && !isLoading && <div className="border-t border-slate-100 px-5 py-2 text-xs font-medium text-slate-400">Refreshing...</div>}
  <div className="px-4 pb-4"><Pagination … /></div>
</section>
```

Cell content: primary text `font-semibold text-slate-900` with a secondary line `text-xs text-slate-500`; plain columns
`text-slate-600`; dates `text-slate-500`; missing values render as an em dash `—`. Money is right-aligned with `tabular-nums`.

Lists of rich items (courses, trainings) are a vertical stack of expandable white cards (`space-y-4`); tiles such as badges use
`grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3`. Both use the card classes from §2.4.

### 5.8 Modal (verbatim)

```jsx
import { useEffect, useRef } from 'react'

export default function Modal({ isOpen, onClose, title, children }) {
  const closeRef = useRef(null)
  const onCloseRef = useRef(onClose)
  onCloseRef.current = onClose

  useEffect(() => {
    if (!isOpen) return
    const handleKey = (e) => { if (e.key === 'Escape') onCloseRef.current() }
    document.addEventListener('keydown', handleKey)
    closeRef.current?.focus()
    document.body.style.overflow = 'hidden'
    return () => {
      document.removeEventListener('keydown', handleKey)
      document.body.style.overflow = ''
    }
  }, [isOpen])

  if (!isOpen) return null

  return (
    <div className="fixed inset-0 z-50 flex items-start sm:items-center justify-center p-4 bg-slate-900/50 backdrop-blur-sm" onClick={onClose} role="dialog" aria-modal="true" aria-labelledby="modal-title">
      <div className="bg-white rounded-xl border border-slate-200 shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-y-auto" onClick={(e) => e.stopPropagation()}>
        <div className="flex items-center justify-between p-5 border-b border-slate-200">
          <h2 id="modal-title" className="text-lg font-semibold text-slate-800">{title}</h2>
          <button ref={closeRef} onClick={onClose} className="w-8 h-8 rounded-full hover:bg-slate-100 flex items-center justify-center text-slate-500 transition-colors" aria-label="Close modal">
            <i className="fa-solid fa-xmark"></i>
          </button>
        </div>
        <div className="p-5">{children}</div>
      </div>
    </div>
  )
}
```

Create, edit and detail views all open in this modal; the page does not navigate. Titles: "Create course" / "Edit course" / "Employee profile".

### 5.9 Confirm dialog

Built on the modal. Message row: a `w-10 h-10 rounded-full bg-slate-100` circle with a Bootstrap icon
(`bi-exclamation-triangle-fill text-red-500` for danger, `bi-info-circle-fill text-brand-blue` for primary,
`bi-exclamation-triangle-fill text-amber-500` for warning) and `text-sm text-slate-600 pt-2` text. Buttons right-aligned
(`flex justify-end gap-3 pt-2`): Cancel `px-4 py-2 rounded-lg text-sm font-medium text-slate-600 bg-slate-100 hover:bg-slate-200`,
confirm `px-4 py-2 rounded-lg text-sm font-medium` + `bg-red-600 hover:bg-red-700 text-white` (danger) /
`bg-brand-blue hover:bg-blue-700 text-white` (primary) / `bg-amber-500 hover:bg-amber-600 text-white` (warning).
Every delete asks first. Never use `window.confirm`.

### 5.10 Notices, alerts and states

- Success / error notice after an action (top of the page, disappears after 4 seconds):
  `role="status" aria-live="polite"` + `flex items-center gap-2 rounded-lg border p-3 text-sm font-medium` +
  success `border-emerald-200 bg-emerald-50 text-emerald-700` (`fa-circle-check`) or error `border-red-200 bg-red-50 text-red-700` (`fa-circle-exclamation`).
- Page-level load error:
  ```jsx
  <div className="flex flex-col gap-3 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800 sm:flex-row sm:items-center sm:justify-between" role="alert">
    <div className="flex items-start gap-3"><i className="fa-solid fa-circle-exclamation mt-0.5" aria-hidden="true" />
      <div><p className="font-semibold">Dashboard data could not be loaded</p><p className="mt-0.5 text-red-700">{message}</p></div></div>
    <button type="button" onClick={refetch} className="inline-flex items-center justify-center gap-2 rounded-lg border border-red-300 bg-white px-3 py-2 text-xs font-semibold text-red-700 transition hover:bg-red-100">
      <i className="fa-solid fa-rotate-right" aria-hidden="true" />Try again</button>
  </div>
  ```
- Error inside a list card: centered `p-10 text-center`, `fa-solid fa-triangle-exclamation text-2xl text-red-500`, `mt-3 font-semibold text-slate-900` title,
  `mt-1 text-sm text-slate-500` message, and `mt-4 rounded-lg bg-brand-dark px-4 py-2 text-sm font-semibold text-white` "Try again".
- Empty state in a table: one row spanning all columns, `px-4 py-14 text-center`, a relevant icon `text-2xl text-slate-300`,
  `mt-3 font-semibold text-slate-700` title ("No employees found"), `mt-1 text-sm text-slate-500` hint ("Try adjusting or clearing the current filters.").
- Empty state for a card list: `rounded-xl border border-dashed border-slate-300 bg-white p-12 text-center`, icon `text-3xl text-slate-300`,
  `mt-3 font-semibold text-slate-800` title ("No courses found"), `mt-1 text-sm text-slate-500` hint ("Create one or adjust the current filters.").
- Loading: skeleton rows `h-10 animate-pulse rounded-lg bg-slate-100` (6 rows), skeleton numbers `h-8 w-16 animate-pulse rounded bg-slate-100`.
  Never a blank page; never a spinner where the layout is known. Route-level loader (lazy pages): centered
  `h-8 w-8 animate-spin rounded-full border-2 border-slate-300 border-t-brand-blue` with "Loading…" (`text-sm text-slate-500`) on `bg-brand-gray`.
- Background refresh: keep the previous data on screen and show "Refreshing..." (`text-xs font-medium text-slate-400`).

### 5.11 Pagination

Laravel paginator data drives it (`current_page`, `last_page`, `total`, `from`, `to`). Hidden when `total` is 0.
Wrapper `flex flex-col sm:flex-row items-center justify-between gap-3 px-4 py-3 border-t border-slate-200`; text
"Showing **from** to **to** of **total** results" (`text-sm text-slate-600`, numbers `font-medium text-slate-900`); prev/next
buttons with `bi bi-chevron-left/right` (`px-3 py-1.5 text-sm font-medium text-slate-600 rounded-lg border border-slate-200 hover:bg-slate-50 disabled:opacity-40 disabled:cursor-not-allowed`);
page buttons `min-w-[32px] px-2 py-1.5 text-sm font-medium rounded-lg border`, current `bg-brand-blue text-white border-brand-blue`,
others `text-slate-600 border-slate-200 hover:bg-slate-50`; first, last and current ±2 pages are shown, gaps as `…`.

### 5.12 Tabs

- Page-level tabs (underline): container `flex items-center gap-4 border-b border-slate-200`; tab `pb-2 text-sm font-medium transition-colors`
  + active `border-b-2 border-brand-blue text-brand-blue`, inactive `text-slate-500 hover:text-slate-700`.
- In-card tabs (underline with icon): `-mb-px inline-flex items-center gap-2 border-b-2 px-3 pb-2 text-sm font-semibold transition`
  + active `border-brand-blue text-brand-blue`, inactive `border-transparent text-slate-500 hover:text-slate-800`.
- Segmented tabs (inside modals, e.g. Today / Upcoming / Past): container `flex gap-1 rounded-lg bg-slate-100 p-1` `role="tablist"`;
  tab `flex-1 rounded-md px-3 py-1.5 text-xs font-semibold transition` + active `bg-white text-brand-blue shadow-sm`, inactive `text-slate-600 hover:text-slate-900`;
  count bubble `ml-1.5 rounded-full px-1.5 py-0.5 text-[10px]` (active `bg-blue-50 text-brand-blue`, inactive `bg-slate-200 text-slate-600`).
- Tabs use `role="tab"` and `aria-selected`.

### 5.13 Charts (Chart.js 4 + react-chartjs-2)

```js
import { Chart as ChartJS, ArcElement, BarElement, CategoryScale, LinearScale, Tooltip, Legend } from 'chart.js'
ChartJS.register(ArcElement, BarElement, CategoryScale, LinearScale, Tooltip, Legend)

const PALETTE = ['#1e3a8a', '#2563eb', '#f59e0b', '#10b981', '#8b5cf6', '#ef4444', '#06b6d4', '#ec4899']

const pieOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } } },
}

const barOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: { legend: { display: false } },
  scales: {
    x: { ticks: { font: { size: 11 } }, grid: { display: false } },
    y: { beginAtZero: true, ticks: { precision: 0, font: { size: 11 } }, grid: { color: '#f1f5f9' } },
  },
}
// Pie/doughnut datasets: backgroundColor from PALETTE in order, borderColor '#fff', borderWidth 2.
// Bar datasets: one color, borderRadius 6, maxBarThickness 50.
```

Chart container:

```jsx
function ChartCard({ title, children, height = 280 }) {
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
      <h3 className="mb-4 text-sm font-semibold text-slate-800">{title}</h3>
      <div style={{ height }}>{children}</div>
    </div>
  )
}
// Empty: <div className="flex h-full items-center justify-center text-sm text-slate-400">No data available</div>
```

Charts sit in `grid grid-cols-1 gap-6 lg:grid-cols-2`. A ring/progress gauge is an SVG circle: track `#e2e8f0`, value `#1e3a8a`, stroke width 10, round caps.

### 5.14 Login page

- Page: `min-h-screen flex items-center justify-center bg-brand-dark p-6`.
- Card: `bg-white rounded-2xl shadow-xl w-full max-w-md p-8`.
- Header (centered, `mb-8`): `<img src="/logo.png" className="mx-auto mb-4 h-24 w-auto max-w-full" />`, `h1` "Archon Nell" (`text-2xl font-bold text-slate-800`),
  subtitle = system name (`text-sm text-slate-500 mt-1`).
- Inputs with a leading icon (`fa-regular fa-envelope`, `fa-solid fa-lock`, `absolute left-3 top-1/2 -translate-y-1/2 text-slate-400`):
  `w-full pl-9 pr-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand-blue/20 focus:border-brand-blue/50 transition-all`;
  labels `block text-sm font-medium text-slate-700 mb-1`; show/hide password eye button on the right.
- Submit: `w-full bg-brand-dark hover:bg-slate-800 text-white py-2.5 rounded-lg text-sm font-medium transition-colors flex items-center justify-center gap-2 disabled:opacity-70 disabled:cursor-not-allowed`,
  label "Sign In" + `fa-arrow-right`; while busy `fa-circle-notch fa-spin`; while locked `fa-lock` and a countdown.
- Error box above the form: `mb-4 p-3 rounded-lg bg-red-50 border border-red-200 text-red-600 text-sm flex items-center gap-2` with `fa-circle-exclamation`.
- Last line of the card: `<p className="text-center text-xs text-slate-500 mt-6">&copy; {year} Archon Nell. All rights reserved.</p>`.
- No "Remember me" and no "Forgot password" link.

### 5.15 Session timeout dialog

After 15 minutes without mouse, keyboard, scroll or touch input, a modal titled "Are you still there?" shows a clock tile
(`h-10 w-10 rounded-full bg-amber-50` + `fa-solid fa-clock text-lg text-amber-500`), the text "You have not done anything for 15 minutes.
For your security you will be signed out in **N seconds**. Do you want to stay signed in?", and two buttons: "Log out"
(`rounded-lg bg-slate-100 px-4 py-2 text-sm font-medium text-slate-600 transition-colors hover:bg-slate-200`) and "Stay signed in"
(`rounded-lg bg-brand-blue px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-blue-700`). With no answer in 60 seconds the user is
signed out and the login page shows an amber notice (`mb-4 p-3 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-sm flex items-center gap-2`, `fa-clock`):
"You were signed out after 15 minutes without activity." The idle clock is a timestamp in `localStorage` shared by all tabs.

---

## 6. Content and formatting

- Sentence case everywhere except sidebar module names and page titles, which use Title Case ("Learning Management").
- Plain, specific wording: "No employees found", "Could not load employees", "3 matching records".
- Dates: `toLocaleDateString()` for tables; long form `Intl.DateTimeFormat('en', { weekday: 'long', month: 'long', day: 'numeric' })` in the hero.
  Business time zone is `Asia/Manila`; the API stores UTC instants and dates without time as `YYYY-MM-DD`.
- Money (new systems): Philippine peso, `new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' })` → `₱1,234.50`; right-aligned, `tabular-nums`.
- Percentages: one decimal at most; "—" when there is no value.
- CSV export: a secondary "Export CSV" button in the page header; the file name includes the date, e.g. `Reports 2026-10-09.csv`.

## 7. Accessibility

- Visible focus on every control (`focus:ring-2` + a tone ring); never remove outlines without a replacement.
- Icon-only buttons have `aria-label` and `title`; decorative icons `aria-hidden="true"`.
- Alerts use `role="alert"`; success notices `role="status" aria-live="polite"`.
- Dialogs: `role="dialog" aria-modal="true" aria-labelledby`, focus moves to the close button, Escape closes.
- Sections use `aria-labelledby` pointing at their `h2`. Tables use real `<table>/<thead>/<th>`.
- Body text is at least `text-xs` (12 px); only counters use `text-[10px]`/`text-[11px]`.

## 8. Responsive behavior

- Breakpoints are Tailwind defaults (`sm` 640, `md` 768, `lg` 1024, `xl` 1280).
- Below `md` the sidebar is off-canvas (hamburger in the topbar); from `md` it is static at `w-64`.
- Tables scroll horizontally inside their card (`overflow-x-auto` + `min-w-[...]`); the page itself never scrolls sideways.
- Headers and toolbars stack vertically on small screens (`flex-col … lg:flex-row`), form grids collapse to one column.
- Modals are full-width with `p-4` margin on phones and `max-w-2xl` from `sm`.

## 9. Do and don't

Do:
- Reuse the exact class strings above; build new screens from §4 and §5.
- Keep each module's labels, status tones and option lists in `src/lib/<module>.js`.
- Show skeletons while loading, keep previous data while refetching, confirm before deleting, disable buttons while saving.

Don't:
- Add other UI kits (MUI, Ant, Chakra, shadcn, Bootstrap CSS) or another icon set, font, or chart library.
- Introduce new brand colors, gradients, glassmorphism, dark mode, or rounded-3xl/pill-shaped buttons.
- Use `alert()`/`confirm()`, toasts that cover content, or full-page spinners for data inside the shell.

---

# APPENDIX B — TECH-STACK.md (stack and conventions, copied from PDMS)

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
  The refund compliance check (REF-1..REF-9) sends the order's items with their prices and up to 4 evidence photos, taken
  from the refund's own upload and its linked tickets, so the model can judge whether the photos show the problem
  (REF-7, required for a damaged or wrong item), match the order (REF-8) and fit the amount asked for (REF-9). The
  response lists the photos it was shown (`photos: [{id, filename, source}]`, photo 1 first).

## 6. Quality bar

- Feature tests for every endpoint (status codes, validation, role checks, the shape of list responses).
- `npm run lint` and `npm run build` pass; `php artisan test` passes.
- No secrets in the repository; `.env.example` lists every variable with a safe placeholder.

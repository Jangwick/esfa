# Archon Market partner simulator

A separate, tiny app that plays the three outside systems SFA integrates with, so a panel can watch the
integration traffic in both directions:

| Role | How it talks to SFA |
|---|---|
| **Storefront** (Archon Market) | SFA's public integration API under `/api/v1/integrations/*` with a Bearer token; `Idempotency-Key` on payments, refunds and tickets |
| **Payment gateway** | Signed webhook to `POST /api/v1/webhooks/payments/sandbox` |
| **Courier** | Signed webhook to `POST /api/v1/webhooks/couriers/{courier_code}` |
| **Webhook receiver** | SFA calls `POST http://localhost:8787/webhooks`; every event is signature-checked |

It never reads SFA's database or code. Zero dependencies: Node built-ins only, plain HTML/CSS/JS, no build step.

## Run it

```bash
node tools/partner-simulator/server.mjs            # http://localhost:8787
node tools/partner-simulator/server.mjs --check    # self-test (signatures, centavos, redaction); exits 0 or 1
```

Needs Node 20 or newer (tested on Node 26). Settings, all optional:

| Variable | Default | Meaning |
|---|---|---|
| `PARTNER_PORT` | `8787` | Port of this app (it listens on 127.0.0.1 only) |
| `SFA_API_URL` | `http://127.0.0.1:8000/api/v1` | SFA API until Setup or SFA's Connect button saves another one |
| `SFA_APP_URL` | `http://127.0.0.1:5174` | Target of the "Open SFA back office" link |

The repo's `.claude/launch.json` has a `partner-sim` entry that runs it against the API on port 8010.

Runtime files live in `storage/` (git-ignored): `config.json` (connection and secrets) and `data.json` (local
storefront records, the outgoing call log and received webhooks, newest 100 each). Delete them, or use
**Setup → Clear simulator data**, to start over. A missing or corrupt file just starts fresh.

## Connect it to SFA

**One click (recommended).** Start SFA (API and SPA) and this app. In SFA sign in as Admin, open **Process
Simulator** and press **Connect partner simulator**. SFA issues a fresh `demo-storefront` token, activates the
webhook endpoint `http://localhost:8787/webhooks` with a new secret, rotates the demo courier's signing secret and
posts all of it to this app's `/api/setup`, server to server. The pill turns **Connected as demo-storefront**.
SFA reads its own address for this from `SFA_PARTNER_URL` (default `http://localhost:8787`).

**By hand (fallback).** Open tab **1 Setup** and paste:

| Field | Where in SFA |
|---|---|
| SFA API address | Where the API runs, ending in `/api/v1` |
| Client code, access token | Integrations → Clients → Issue token (shown once) |
| Webhook signing secret | Integrations → Webhooks → add `http://localhost:8787/webhooks` → Rotate secret |
| Courier code, signing secret | Logistics & Locations → Couriers & rates → Rotate signing secret |
| Payment gateway secret | `SFA_SANDBOX_WEBHOOK_SECRET` in `backend/.env` |

SFA delivers to `http://localhost` only in a local environment with `SFA_WEBHOOK_ALLOW_LOCALHOST` on (the
default). With SFA's simulator mode on (any non-production environment) events go out right after each request;
otherwise run `php artisan sfa:run-scheduled` to send them.

## What each tab does

| Tab | Calls |
|---|---|
| 1 Setup | Shows what is saved (never the secret values), manual connection, clear local data |
| 2 Shop | `PUT customers/{id}`, `PUT sellers/{id}`, `PUT orders/{id}` (integer centavos, SFA re-checks every total), `POST payments`, `POST orders/{id}/cancel` (also cancels shipments not yet with the courier; 422 once one is), `POST quotes` (PSGC city codes), `GET payments|refunds|shipments/{reference}` |
| 3 Customer support | `POST tickets`, `POST tickets/{ref}/messages`, `GET tickets/{ref}` (public messages only), `POST tickets/{ref}/csat`, `POST refunds` |
| 4 Payment gateway | Signed `payment.authorized`, `captured`, `failed`, `cancelled`, `disputed`, `dispute_won`, `dispute_lost` |
| 5 Courier | Signed status updates by tracking number |

The right-hand **Live traffic** column merges every outgoing call (→ SFA: method, path, HTTP status, redacted
headers, body, response up to 4 KB) with every incoming webhook (← SFA: event, delivery id, ✓ signature verified,
duplicate flag). Click a row to see the JSON. The page refreshes every 3 seconds.

Sandbox payment rules (SFA's `SandboxGateway`): a total ending in **.99** is declined, **.98** stays pending until
a gateway event, anything else is approved and captured. Above ₱50,000 waits for a Finance risk review. Cash on
delivery stays pending until delivery.

**Not simulated: booking shipments.** `POST /integrations/shipments` needs SFA's internal `origin_warehouse_id`
and a destination address made of internal `region_id`, `city_id` and `barangay_id`; the integration API offers no
way to look those up. So the Logistics Coordinator books shipments in SFA, this app learns about them from
`shipment.created` webhooks, and the courier tab moves them along. The seeded shipments `DEMO-TRK-0001` to
`DEMO-TRK-0015` work too.

## Safety

- Listens on 127.0.0.1. `/api/*` answers only loopback callers whose `Host` (and `Origin`, when sent) is this app,
  so other websites cannot drive it through the presenter's browser.
- `/api/status` and `/api/state` never return secrets. The log redacts `Authorization` and `X-SFA-Signature`.
- Webhooks: HMAC-SHA256 over `t + "." + raw body`, constant-time compare, timestamps more than 300 s away rejected
  (401), missing secret 503, repeated event ids answered 200 and marked duplicate.

## 10-minute panel demo

Put SFA (left) and this app (right) side by side. Sign in to SFA as Admin; use the other demo roles where noted.

1. **Connect (0:00–1:00).** SFA → Process Simulator → Connect partner simulator. The pill turns green. Say: the
   partner holds only a token and two signing secrets; it never sees SFA's database.
2. **Mirror the shop (1:00–2:30).** Tab 2: save a customer, a seller, then place an order. Point at the three
   `PUT … 201` rows and open one to show the centavo amounts. Submit an empty customer name once to show SFA's
   validation message landing on the field.
3. **Checkout (2:30–4:00).** Pay with GCash. Two webhooks arrive: `payment.authorized`, `payment.captured`, both
   ✓ signature verified. Show the same payment as Captured in SFA → Payments & Transactions.
4. **Decline and pending (4:00–5:00).** Place an order with shipping `80.99` and pay: declined, `payment.failed`
   comes back. Place one with shipping `80.98` and pay: pending. Tab 4 → Captured → send. SFA processes the signed
   gateway event and tells the storefront.
5. **Support (5:00–7:00).** Tab 3: open a ticket linked to the order. In SFA → Customer Support → Tickets, open
   it and send a public reply: `ticket.replied` arrives and the conversation shows the agent's message. Add an
   internal note: nothing arrives (internal notes never leave SFA). Resolve it (`ticket.status_changed`), then
   rate it 5/5 in tab 3.
6. **Refund (7:00–8:00).** Tab 3: request a ₱100 refund on the captured payment (`refund.requested`). Sign in as
   Finance, open SFA → Payments & Transactions → Refunds and approve it: `refund.approved` and
   `refund.completed` arrive.
7. **Delivery (8:00–9:30).** As the Logistics Coordinator, open SFA → Logistics & Locations → Shipments and book
   a shipment for the paid order with a tracking number, e.g. `ARC-0001` (`shipment.created` arrives). Tab 5: send Ready for pickup → Picked up → In transit.
   Each update shows on SFA's shipment timeline and comes back as `shipment.status_changed`. Try skipping a step
   to show SFA refusing it: the Logistics Coordinator gets a "Courier update refused" notification. With Settings →
   Automation → Fill skipped courier steps on, SFA records the skipped steps as inferred instead. Picked up needs the
   tracking number, and delivery itself needs proof of delivery recorded in SFA.
8. **Security close (9:30–10:00).** Point at the redacted token, the Idempotency-Key on payments and the ✓ on
   every webhook. Optional: paste a wrong gateway secret in Setup and send an event: SFA answers 401. Press
   Connect in SFA again to restore.

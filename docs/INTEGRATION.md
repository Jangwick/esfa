# Integrating with SFA

The integration API lets the other systems of the Archon Nell marketplace cluster (storefront, orders, checkout,
accounts, catalog, finance) send records to Support, Finance & Administration (SFA) and receive signed webhooks.
The machine-readable contract is `docs/openapi.yaml` (OpenAPI 3.1, served at `GET /api/v1/openapi.yaml`).

## 1. Client setup

1. An Admin opens **Integrations → Clients → New client** and enters a name, a `system_code` (lowercase letters,
   digits and hyphens, e.g. `storefront`) and a contact email.
2. **Issue token** and choose only the scopes the system needs. The token is shown **once**; SFA stores only its
   hash. Revoke it from the same screen; `last_used_at` shows when it was last used.
3. Deactivating a client rejects all of its tokens immediately (`403 This integration client is inactive.`).

The `system_code` is the client's identity. Every mirror record the client creates is stored with
`source_system = <system_code>`, and the client can only read or change records with that source system (the one
exception is the read-only finance-wide access in section 6).

Development seeding prints one demo token per sibling client (`demo-storefront`, `demo-orders`, `demo-checkout`,
`demo-accounts`, `demo-catalog`, `demo-finance-gl`). Production never creates demo clients or tokens.

## 2. Requests

```
Authorization: Bearer <integration token>
Accept: application/json
Content-Type: application/json
```

Base path: `/api/v1/integrations`. Staff tokens cannot call integration routes, and integration tokens cannot call
back-office routes (both get `403`). Each client may make **120 requests per minute** (`429` with `Retry-After`).

| Scope | Endpoints |
|---|---|
| `customers:write`, `sellers:write`, `catalog:write`, `orders:write` | `PUT /customers/{external_id}`, `PUT /sellers/{external_id}`, `PUT /categories/{external_id}`, `PUT /orders/{external_id}`, `POST /orders/{external_id}/cancel` |
| `payments:write`, `payments:read`, `refunds:write` | `POST /payments`, `GET /payments/{reference}`, `POST /refunds`, `GET /refunds/{reference}` |
| `quotes:read`, `shipments:write`, `shipments:read` | `POST /quotes`, `POST /shipments`, `GET /shipments/{reference}` |
| `tickets:write`, `tickets:read`, `kb:read` | `POST /tickets`, `GET /tickets/{reference}`, `POST /tickets/{reference}/messages`, `POST /tickets/{reference}/csat`, `GET /attachments/{id}/download`, `GET /kb/articles`, `GET /kb/articles/{slug}` |
| `subscriptions:read`, `commissions:read`, `analytics:read` | `GET /subscriptions?seller=`, `GET /sellers/{external_id}/statements`, `GET /analytics/summary?from=&to=` |
| `webhooks:manage` | `GET, POST /webhook-endpoints`, `PUT, DELETE /webhook-endpoints/{id}` (own endpoints only) |

`POST /orders/{external_id}/cancel` first cancels the order's shipments that are still pending or ready for pickup
(each sends `shipment.status_changed`), then its pending or authorized payments. It returns `422` with nothing changed
when money was captured (request a refund instead) or when a shipment is already with the courier or delivered. A
`PUT /orders/{external_id}` that changes `status_snapshot` to `cancelled` cancels the shipments the same way and
returns the same `422`.

Issue a token with chosen scopes (staff Admin token):

```bash
curl -X POST https://sfa.example.com/api/v1/integration-clients/3/tokens \
  -H "Authorization: Bearer $ADMIN_TOKEN" -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"name":"storefront production","abilities":["tickets:read","tickets:write","kb:read"]}'
```

## 3. Response shapes

- **Lists** return Laravel's paginator plus a `summary` object: `current_page`, `data`, `first_page_url`, `from`,
  `last_page`, `last_page_url`, `links`, `next_page_url`, `path`, `per_page`, `prev_page_url`, `to`, `total`, `summary`.
  Use `page` and `per_page` (default 20, clamped to 1–100).
- **Errors**: `401 {"message":"Unauthenticated."}`, `403 {"message":"..."}`, `404 {"message":"..."}`,
  `422 {"message":"...","errors":{"field":["..."]}}`, `429` with `Retry-After`. A record owned by another source system
  answers `404`, exactly like a record that does not exist.

## 4. Money and time

- Every amount is an **integer number of centavos** in a field ending in `_amount`, with `currency: "PHP"`.
  ₱1,234.50 is `123450`. Never send floats.
- Percentages are **basis points** (`rate_bp`, 1 % = 100).
- Instants are UTC ISO 8601. Report filters (`from`, `to`, `YYYY-MM-DD`) are calendar days in the business time zone
  (`Asia/Manila` by default).

## 5. Idempotency

`POST /payments`, `POST /refunds`, `POST /shipments` and `POST /tickets` **require** an `Idempotency-Key` header
(1–255 characters, e.g. a UUID). SFA stores the client, key, a hash of method + path + body, and the response for
**24 hours**:

| Situation | Response |
|---|---|
| New key | Processed normally; the response is stored |
| Same key, same request | The stored response, with `Idempotency-Replayed: true` (no second payment, refund, shipment or ticket) |
| Same key, different body or path | `422` on `idempotency_key` |
| Same key while the first request is still running | `409` with `Retry-After: 5` |
| A `5xx` response | Not stored; the key may be retried |

Retry network failures with the **same** key.

## 6. Example requests

The payments and refunds integration endpoints are maintained by the project owner and may change; `openapi.yaml`
is the source of truth for their current shape.

Upsert a customer, a seller and an order:

```bash
curl -X PUT $SFA/api/v1/integrations/customers/cus-1001 -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"name":"Juan Dela Cruz","email":"juan@example.com","phone":"09170000000"}'
curl -X PUT $SFA/api/v1/integrations/sellers/sel-77 -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"shop_name":"Sample Shop","owner_name":"Maria Santos","payout_destination":{"bank_name":"Sample Bank","account_number":"001234567890","account_name":"Maria Santos"}}'
curl -X PUT $SFA/api/v1/integrations/orders/ord-5001 -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"customer_external_id":"cus-1001","status_snapshot":"placed","subtotal_amount":150000,"shipping_amount":8000,"discount_amount":0,"total_amount":158000,"placed_at":"2026-10-09T02:00:00Z",
       "items":[{"seller_external_id":"sel-77","product_name":"Sample item","sku":"SKU-1","quantity":2,"unit_price_amount":75000,"line_total_amount":150000}]}'
```

The payout destination is stored encrypted and only ever returned masked (`payout_destination_masked: "•••• 7890"`).

Create a payment (sandbox: amounts ending in `99` centavos are declined, `98` stay pending until a gateway webhook,
anything else is authorized and captured):

```bash
curl -X POST $SFA/api/v1/integrations/payments -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" -H "Content-Type: application/json" \
  -H "Idempotency-Key: 6f1c2a3e-0001" -d '{"order_external_id":"ord-5001","method":"gcash"}'
# 201 {"payment":{"reference":"PAY-2026-000123","status":"captured","amount":158000,"currency":"PHP",...},"next_action":null}
```

Request a refund (it waits for an authorized Finance or Admin reviewer; only Admin may review their own staff request):

```bash
curl -X POST $SFA/api/v1/integrations/refunds -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" -H "Content-Type: application/json" \
  -H "Idempotency-Key: 6f1c2a3e-0002" -d '{"payment_reference":"PAY-2026-000123","amount":75000,"reason":"damaged","notes":"One item arrived broken."}'
```

Quote and create a shipment (one per order and seller; SFA recomputes the fee and promised dates). Quotes are sorted
cheapest first and the first one has `"recommended": true`. `courier_id` and `service_type` are optional but go
together: leave both out and SFA books the recommended quote, so a storefront can book every paid order automatically
with only the warehouse, destination, weight and dimensions. No covering rate returns `422` on `courier_id`.

```bash
curl -X POST $SFA/api/v1/integrations/quotes -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"origin_code":"1380200000","destination_code":"1380300000","weight_grams":1200,"dimensions":{"length_cm":"30","width_cm":"20","height_cm":"10"},"cod_amount":0}'
curl -X POST $SFA/api/v1/integrations/shipments -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" -H "Content-Type: application/json" \
  -H "Idempotency-Key: 6f1c2a3e-0003" -d '{"order_external_id":"ord-5001","seller_external_id":"sel-77","courier_id":1,"service_type":"standard","origin_warehouse_id":1,
       "destination":{"line1":"12 Sample Street","region_id":1,"province_id":null,"city_id":1,"barangay_id":1,"postal_code":"1740","contact_name":"Juan Dela Cruz","contact_phone":"09170000000"},
       "weight_grams":1200,"dimensions":{"length_cm":"30","width_cm":"20","height_cm":"10"},"cod_amount":0,"tracking_number":"TRK-0001"}'
```

Open a ticket, reply, and rate it after resolution:

```bash
curl -X POST $SFA/api/v1/integrations/tickets -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" -H "Content-Type: application/json" \
  -H "Idempotency-Key: 6f1c2a3e-0004" -d '{"requester_type":"customer","requester_external_id":"cus-1001","order_external_id":"ord-5001","payment_reference":"PAY-2026-000123",
       "subject":"Item arrived damaged","description":"The box was crushed.","channel":"marketplace_app","category":"refund_return","priority":"high"}'
curl -X POST $SFA/api/v1/integrations/tickets/TCK-000045/messages -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" -F "body=Here is a photo." -F "attachments[]=@photo.jpg"
curl -X POST $SFA/api/v1/integrations/tickets/TCK-000045/csat -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" -H "Content-Type: application/json" -d '{"score":5,"comment":"Quick help."}'
```

`GET /tickets/{reference}` returns the public conversation only; internal notes and their attachments are never
returned or sent.

The status follows the conversation. A reply on a `waiting_on_customer` ticket moves it back to `in_progress`, and the
first public staff reply moves an `open` or `reopened` ticket to `in_progress`. Each move sends `ticket.status_changed`
as well as `ticket.replied`.

Two optional rules, both off unless an Admin turns them on: an automatic acknowledgement arrives right after
`ticket.created` as a `ticket.replied` whose message has `author_type: "system"`, and a ticket left waiting on the
customer for the configured number of days is resolved (two `ticket.status_changed` events, ending in `resolved`; the
ticket's `tags` in `GET /tickets/{reference}` include `auto_resolved`).

When a ticket names an order but no payment or shipment, SFA links the order's only payment (payment and
refund_return categories) or only shipment (delivery and refund_return).

Read a seller's subscriptions and finalized or paid statements:

```bash
curl "$SFA/api/v1/integrations/subscriptions?seller=sel-77" -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"
curl "$SFA/api/v1/integrations/sellers/sel-77/statements?per_page=12" -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"
```

Statements contain only `finalized` and `paid` settlements of a seller whose source system is the calling client.

### Finance-wide read access

A finance system (for example the general ledger, `demo-finance-gl`) may need every storefront's figures. An Admin
turns this on per client with **Read financial data of every storefront (read-only)** in Integrations → Clients
(`reads_all_financials`, off by default). It widens exactly three reads:

- `GET /analytics/summary` covers every storefront instead of the client's own source system.
- `GET /subscriptions?seller=` and `GET /sellers/{external_id}/statements` find the seller in any storefront. When the
  external ID exists in more than one storefront, add `source_system` (for example `&source_system=storefront`);
  without it the request returns `422` with an error on `source_system`. Unknown sellers return `404`.

The client's scopes still apply, and the switch never affects writes, tickets, shipments, the knowledge base or payment
and refund lookups: those stay limited to the client's own `source_system`. Turning it off takes effect on the next
request.

```bash
curl "$SFA/api/v1/integrations/sellers/sel-77/statements?source_system=storefront" -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"
```

## 7. Outbound webhooks

Register an endpoint (Admin in **Integrations → Webhooks**, or the client itself with `webhooks:manage`). URLs must be
HTTPS on a public address; `http://localhost` is accepted only in local development. The signing secret is shown
once and stored encrypted.

Each delivery is a `POST` with this JSON body:

```json
{ "id": "evt_8f0c…", "type": "payment.captured", "created_at": "2026-10-09T02:15:00+00:00", "data": { … } }
```

Headers: `X-SFA-Event` (type), `X-SFA-Delivery` (unique delivery id), and
`X-SFA-Signature: t=<unix seconds>,v1=<hex HMAC-SHA256 of "<t>.<raw body>" keyed with the secret>`.

Answer with any `2xx` within **10 seconds**. Failed deliveries are retried after **1 min, 5 min, 30 min, 2 h and
12 h**, then marked `failed`. After **20 consecutive failures** the endpoint is disabled and Admins are notified;
Admins can resend any delivery. Deduplicate on `id`, because a delivery can arrive more than once.

### Event ownership

Events are private to the client that owns the record: the client whose `system_code` equals the record's
`source_system` (the order's for payments, refunds and shipments; the ticket's for tickets; the seller's for
subscriptions, invoices, settlements and payouts). When no active client owns the record, nothing is sent; events are
never broadcast to other clients.

| Events | Sent when |
|---|---|
| `ticket.created`, `ticket.status_changed`, `ticket.replied` | A ticket opens, changes status, or gets a public reply (never internal notes) |
| `payment.authorized`, `payment.captured`, `payment.failed`, `payment.cancelled`, `payment.disputed` | A payment changes state |
| `refund.requested`, `refund.approved`, `refund.rejected`, `refund.completed`, `refund.failed` | A refund changes state |
| `shipment.created`, `shipment.status_changed`, `shipment.delivered` | A shipment is created or moves along its timeline |
| `subscription.created`, `subscription.renewed`, `subscription.past_due`, `subscription.cancelled`, `invoice.paid` | Seller billing changes |
| `settlement.finalized`, `payout.paid` | A seller settlement is locked or paid out |

Each payload's `data` is documented under `webhooks` in `openapi.yaml`.

### Verifying signatures

Verify against the **raw** request body before parsing it, compare in constant time, and reject timestamps more than
**five minutes** from your clock.

PHP:

```php
function sfa_verify(string $rawBody, string $header, string $secret, int $tolerance = 300): bool
{
    if (! preg_match('/^t=(\d+),v1=([a-f0-9]{64})$/', $header, $m) || abs(time() - (int) $m[1]) > $tolerance) {
        return false;
    }

    return hash_equals(hash_hmac('sha256', $m[1].'.'.$rawBody, $secret), $m[2]);
}

$valid = sfa_verify(file_get_contents('php://input'), $_SERVER['HTTP_X_SFA_SIGNATURE'] ?? '', getenv('SFA_WEBHOOK_SECRET'));
```

Node.js (Express; keep the raw body):

```js
import crypto from 'node:crypto'
import express from 'express'

function sfaVerify(rawBody, header, secret, toleranceSeconds = 300) {
  const match = /^t=(\d+),v1=([a-f0-9]{64})$/.exec(header || '')
  if (!match || Math.abs(Date.now() / 1000 - Number(match[1])) > toleranceSeconds) return false
  const expected = crypto.createHmac('sha256', secret).update(`${match[1]}.${rawBody}`).digest('hex')
  return crypto.timingSafeEqual(Buffer.from(expected, 'hex'), Buffer.from(match[2], 'hex'))
}

const app = express()
app.post('/webhooks/sfa', express.raw({ type: 'application/json' }), (req, res) => {
  if (!sfaVerify(req.body.toString('utf8'), req.get('X-SFA-Signature'), process.env.SFA_WEBHOOK_SECRET)) return res.sendStatus(401)
  const event = JSON.parse(req.body)
  // Deduplicate on event.id, then process.
  res.sendStatus(204)
})
```

## 8. Inbound webhooks

Both inbound endpoints are public, verify the signature over the raw body, store every event id, and ignore
duplicates. Each gateway and each courier may send **300 requests per minute**.

- **Payment gateway** — `POST /api/v1/webhooks/payments/{gateway}`. The built-in `sandbox` gateway accepts
  `{"event_id","reference","type","amount"?}` signed with `SFA_SANDBOX_WEBHOOK_SECRET` in the same `X-SFA-Signature`
  format. Events: `payment.authorized|captured|failed|cancelled|disputed|dispute_won|dispute_lost`.
- **Courier** — `POST /api/v1/webhooks/couriers/{courier_code}` with `{"id","tracking_number","status","location"?,"note"?,"occurred_at"?}`
  signed with the courier's webhook secret (shown once when the courier is created or its secret rotated). Status
  changes follow the shipment state machine; a `delivered` event is rejected until proof of delivery exists. When SFA
  turns on `logistics_courier_pod`, a `delivered` event for a prepaid parcel that is out for delivery may carry the
  proof itself as `"recipient_name"` and `"proof_photo"` (raw base64 PNG, JPG or WEBP up to 2 MB); COD parcels always
  need staff proof.

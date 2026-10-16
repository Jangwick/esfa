import { createContext, useContext, useState } from 'react'
import api from './api'
import { businessDate, pesosToCentavos } from './admin'
import { label, money } from './payments'

// Process simulator catalog: the sibling systems that exchange data with SFA, their submodules, and per submodule only the
// features that send data to SFA or receive data from it. `sends` and `reads` are feature keys that pages/Simulator.jsx
// maps to components; `events` are the webhook types SFA sends that submodule ("type:status" keeps only the events whose
// record has that status). HR, Facilities and Supply Chain exchange nothing with SFA, so they are not here.
const COURIER_RECEIVES = "SFA answers each courier event with the parcel as it now stands (shown under the form) and sends the courier no webhooks. It tells the parcel's storefront instead: shipment.status_changed appears under E-Commerce Core > Order & Delivery Management."
export const SYSTEMS = [
  { key: 'storefront', label: 'E-Commerce Core (Group 2)', credential: 'storefront', hint: 'E-Commerce Core Business Operations owns customers, sellers, products and orders. It calls the integration API as demo-storefront and receives signed webhooks for its own records.', modules: [
    { key: 'accounts', label: 'User & Account Management', exchange: 'Sends customer and seller accounts and help-center tickets; receives ticket updates, the public conversation and published help articles. Accounts and satisfaction ratings change SFA without a webhook back; tickets and replies send one.', sends: ['customer', 'sellerAccount', 'ticket', 'ticketReply', 'csat'], events: ['ticket.created', 'ticket.status_changed', 'ticket.replied'], reads: ['ticketLookup', 'kbArticles'] },
    { key: 'catalog', label: 'Product & Store Management', exchange: "Sends product categories and store profiles, which SFA saves without a webhook back. Receives the seller's plan, invoices, settlements and payouts.", sends: ['category', 'storeProfile'], events: ['subscription.created', 'subscription.renewed', 'subscription.past_due', 'subscription.cancelled', 'invoice.paid', 'settlement.finalized', 'payout.paid'], reads: ['plans', 'statements'], receives: 'These webhooks come from Finance working in SFA (Subscriptions: subscribe a seller, finalize or pay a settlement) and from scheduled renewals, not from the sends on this tab.' },
    { key: 'checkout', label: 'Shopping & Checkout', exchange: 'Sends orders, shipping quote requests and payments; receives the payment result.', sends: ['order', 'quote', 'payment'], events: ['payment.authorized', 'payment.captured', 'payment.failed', 'payment.cancelled', 'payment.disputed'], reads: ['paymentLookup'] },
    { key: 'delivery', label: 'Order & Delivery Management', exchange: 'Sends order status updates; receives the shipments the Logistics Coordinator books in SFA and their tracking.', sends: ['orderStatus'], events: ['shipment.created', 'shipment.status_changed', 'shipment.delivered'], reads: ['shipmentLookup'] },
    { key: 'returns', label: 'Returns, Cancellation & Refund', exchange: 'Sends order cancellations and refund requests; receives refund decisions and the payment and shipment cancellations a cancelled order causes.', sends: ['cancelOrder', 'refund'], events: ['refund.requested', 'refund.approved', 'refund.rejected', 'refund.completed', 'refund.failed', 'payment.cancelled', 'shipment.status_changed:cancelled'], reads: ['refundLookup'] },
  ] },
  { key: 'finance', label: 'Financial Management (Group 10)', credential: 'finance', hint: 'The Financial Management System only reads from SFA, as demo-finance-gl with read-only scopes. Connecting lets its client read the financial data of every storefront (its "reads all financials" setting), so pickers list sellers of all storefronts.', modules: [
    { key: 'reporting', label: 'Financial Reporting & Analytics', exchange: 'Reads platform revenue, refunds, fees, support and delivery figures for a period, plus current subscription and delivery counts.', sends: [], reads: ['analytics'] },
    { key: 'receivable', label: 'Accounts Receivable & Collection', exchange: "Reads each seller's subscription: plan, price, status and billing dates, which is what sellers owe the platform.", sends: [], reads: ['plans'] },
    { key: 'payable', label: 'Accounts Payable & Disbursement', exchange: 'Reads finalized seller settlements and payouts, which is what the platform owes sellers.', sends: [], reads: ['statements'] },
  ] },
  { key: 'fleet', label: 'Fleet & Transportation (Group 6)', credential: 'courier', hint: 'Fleet & Transportation acts as the courier. It reports shipment progress to SFA with signed courier webhooks. Delivery itself is recorded in SFA by the Logistics Coordinator with proof of delivery.', modules: [
    { key: 'dispatch', label: 'Vehicle Reservation & Dispatch', exchange: 'Receives the shipments booked with the courier and reports each pickup.', sends: ['courierPickup'], reads: ['courierShipments'], receives: COURIER_RECEIVES },
    { key: 'trips', label: 'Driver & Trip Performance', exchange: 'Reports trip progress: in transit, out for delivery, failed attempts and returns to sender.', sends: ['courierTrip'], reads: ['courierShipments'], receives: `${COURIER_RECEIVES} A failed attempt can also open a delivery ticket (ticket.created, under User & Account Management).` },
  ] },
  { key: 'gateway', label: 'Payment gateway (third party)', credential: 'gateway', hint: "The payment gateway confirms storefront payments with signed webhooks and sends the settlement report that Finance reconciles. SFA's built-in sandbox driver plays its charge API inside SFA.", modules: [
    { key: 'events', label: 'Payment events', exchange: 'Sends signed payment results for storefront card, e-wallet and bank payments: authorizations, captures, failures, cancellations, disputes and dispute outcomes.', sends: ['gatewayEvent'], reads: [], receives: 'Charges, captures and refunds reach the gateway from inside SFA (its sandbox driver), so nothing travels to this simulator. SFA tells the storefront instead: payment.* webhooks appear under E-Commerce Core > Shopping & Checkout. A dispute won or lost sends no webhook.' },
    { key: 'settlement', label: 'Settlement report', exchange: 'Sends the settlement CSV that Finance imports on Reconciliation.', sends: ['settlementFile'], reads: [], receives: 'SFA sends nothing back; Finance resolves every unmatched row on the Reconciliation tab.' },
  ] },
]
export const CREDENTIAL_LABELS = { storefront: 'Storefront (Group 2)', finance: 'Finance (Group 10)', courier: 'Courier (Group 6)', gateway: 'Payment gateway' }
export const PAYMENT_METHODS = ['card', 'gcash', 'maya', 'bank_transfer', 'cod']
export const TRIP_STATUSES = ['in_transit', 'out_for_delivery', 'failed_attempt', 'returned_to_sender']
// Seeded NCR cities (PSGC codes), the same defaults as tools/partner-simulator.
export const QUOTE_DEFAULTS = { origin_code: '1380200000', destination_code: '1380300000', weight_grams: '1200', length_cm: '20', width_cm: '15', height_cm: '10', cod: '0.00' }
export const PARTNER_COMMAND = 'node tools/partner-simulator/server.mjs'
// SFA_PARTNER_URL on this computer is the Node partner tool; on any other host it is SFA's built-in receiver
// (/api/v1/simrx) on a hosted demo. An empty or unreadable URL counts as local, the default setup.
export function isLocalPartner(url) {
  let host
  try { host = new URL(url).hostname } catch { return true }
  return !host || ['localhost', '127.0.0.1', '::1', '[::1]'].includes(host) || host.endsWith('.localhost')
}

// "What happens?" guides, keyed by the feature keys of SYSTEMS (sends and reads), in plain language for people watching
// a demo. Each is SFA's behaviour as the code and a live run show it. `before` is what must exist first; the other
// fields are numbered steps in the order written (their titles are in components/simulator/SimUI.jsx). In any text,
// [words](/path) becomes a link inside SFA.
const SF = (module, text) => `[${text}](/simulator?system=storefront&module=${module})`
export const GUIDES = {
  customer: {
    sends: 'The storefront sends a shopper\'s account when they sign up or edit it: PUT /integrations/customers/{id}, where {id} is the storefront\'s own customer id.',
    checks: 'A full name is required and an email must be a real address; otherwise SFA answers 422 and saves nothing. An id SFA already has updates that customer instead of adding a second one.',
    changes: 'SFA creates the customer (HTTP 201) or updates it (HTTP 200) for this storefront only. No money moves and no staff are notified.',
    back: 'Nothing: SFA sends no webhook for accounts, so nothing new appears under Receives from SFA or in the partner app.',
    see: 'Staff can now choose them as the requester of a [new ticket in Customer Support](/support?new=ticket). The Order and Help-center ticket forms here list them too.',
  },
  sellerAccount: {
    sends: 'The storefront sends a new shop: PUT /integrations/sellers/{id} with its shop name, owner and contact details, and optionally where its earnings are paid (a bank account or a GCash number).',
    checks: 'A shop name is required and an email must be a real address (422 otherwise). This form lets through only a GCash number like 0917 123 4567 or a bank account of 7 to 20 digits, because Finance can pay out only to a number ending in four digits.',
    changes: 'SFA creates the seller (HTTP 201). The payout destination is stored encrypted and shown to staff only masked, like BPI •••• 7890. The seller has no plan until Finance subscribes it.',
    back: 'Nothing yet: SFA sends no webhook for accounts. The plan, invoice, settlement and payout webhooks of Product & Store Management start once Finance subscribes the seller.',
    see: 'Finance subscribes the seller in [Subscriptions & Commissions](/subscriptions?tab=subscriptions) and later pays its settlements in [Settlements & payouts](/subscriptions?tab=settlements) to the stored destination.',
  },
  ticket: {
    before: 'The customer or seller who asks must already be in SFA: send a Customer account or Seller account above.',
    sends: 'A customer or seller asks for help on the storefront: POST /integrations/tickets with the requester, subject, message, category, channel, priority and, optionally, the order, payment or parcel it is about.',
    checks: 'The requester and any order must be this storefront\'s (404 otherwise). The order must be the requester\'s own, and a payment or parcel must belong to that order (a seller\'s: only its own parcel), or SFA answers 422.',
    changes: 'SFA opens the ticket as Open with a TCK reference, and sets its first-reply and resolution deadlines from the SLA policy for its priority. A payment, delivery or refund question about an order with only one payment or parcel gets that record linked by SFA itself.',
    back: 'ticket.created, under Receives from SFA on this tab and in the partner app.',
    see: 'The ticket is in [Customer Support, Tickets](/support?tab=tickets); the panel under the form links to it.',
  },
  ticketReply: {
    before: 'A storefront ticket that is not closed. Send a Help-center ticket above if none is listed.',
    sends: 'The customer or seller answers in the storefront\'s help center: POST /integrations/tickets/{reference}/messages with the reply.',
    checks: 'SFA refuses a reply to a closed ticket (422, "Reopen the ticket before adding a reply") and to another storefront\'s ticket (404), so closed tickets are not listed.',
    changes: 'The reply joins the ticket\'s public conversation, and the assigned agent, if any, gets a "replied" notification. A ticket Waiting on customer moves back to In progress; any other status stays.',
    back: 'ticket.replied, or ticket.status_changed and then ticket.replied when the status moved. Both appear under Receives from SFA on this tab and in the partner app.',
    see: 'Open the ticket in [Customer Support, Tickets](/support?tab=tickets): the reply is in its conversation.',
  },
  csat: {
    before: 'A storefront ticket that is Resolved or Closed. In [Customer Support](/support?tab=tickets), resolve one first if none is listed.',
    sends: 'The requester rates the help they got: POST /integrations/tickets/{reference}/csat with a score from 1 to 5 and an optional comment.',
    checks: 'SFA takes a rating only for a resolved or closed ticket (422, "A satisfaction score can only be submitted after resolution") and only a score from 1 to 5.',
    changes: 'SFA saves the score and comment on the ticket, replacing any earlier rating; a blank comment removes the old one. A score of 2 or lower notifies the Admins and the assigned agent ("Low satisfaction score"), unless the ticket was already rated that low.',
    back: 'Nothing: SFA sends no webhook for a rating.',
    see: 'The rating shows on the ticket in [Customer Support](/support?tab=tickets), and a low-score alert under the bell in the top bar.',
  },
  category: {
    sends: 'The storefront sends a product category, new or changed: PUT /integrations/categories/{id} with its name and an optional parent category.',
    checks: 'A name is required. SFA refuses an unknown parent (404), and a parent that is the category itself or one below it, which would make a loop (422). The Parent list offers only what SFA takes.',
    changes: 'SFA creates the category (HTTP 201) or renames or moves it (HTTP 200), with exactly the parent sent: None makes it top level. No money moves.',
    back: 'Nothing: SFA sends no webhook for categories.',
    see: 'Finance can give the category its own rate in [Commission rules](/subscriptions?tab=commission-rules). It applies to order lines in that category when their payment is captured, unless a rule for the seller or for the seller\'s plan comes first.',
  },
  storeProfile: {
    before: `A storefront seller: send a Seller account on ${SF('accounts', 'User & Account Management')} first.`,
    sends: 'The seller edits their store: PUT /integrations/sellers/{id} with the shop name and whatever else changed, including a new payout destination.',
    checks: 'A shop name is required and an email must be a real address (422 otherwise). The form lets through only a GCash number like 0917 123 4567 or a bank account of 7 to 20 digits, the numbers Finance can pay out to.',
    changes: 'SFA updates the seller (HTTP 200). Blank fields are left out, so SFA keeps what it had; a new payout destination replaces the old one, stored encrypted and shown masked.',
    back: 'Nothing: SFA sends no webhook for store profiles. The webhooks listed on this tab come from Finance\'s work in Subscriptions & Commissions.',
    see: 'Finance sees the masked destination when paying a settlement in [Settlements & payouts](/subscriptions?tab=settlements): a GCash number is paid by GCash, a bank account by bank transfer.',
  },
  order: {
    before: `A storefront customer and seller: send them on ${SF('accounts', 'User & Account Management')} first.`,
    sends: 'The customer checks out: PUT /integrations/orders/{id} with the customer, each line (seller, product, quantity, unit price), the shipping fee and the discount, all in whole centavos.',
    checks: 'SFA re-checks the money: each line must equal quantity times unit price, and the total must equal the lines plus shipping minus the discount (422 otherwise). An unknown customer, seller or category is refused (404).',
    changes: 'SFA saves the order as Placed with its lines (HTTP 201). Nothing is charged and nothing reaches the ledger until a payment is captured.',
    back: 'Nothing: SFA sends no webhook for orders.',
    see: 'Send its Payment next (below). Once paid, or with a cash-on-delivery payment, the order waits in [Logistics & Locations, Awaiting booking](/logistics?tab=awaiting-booking).',
  },
  quote: {
    sends: 'Checkout asks what delivery will cost: POST /integrations/quotes with the from and to cities (as PSGC codes), the parcel\'s weight and size, and any cash-on-delivery amount.',
    checks: 'SFA needs city codes it knows and a weight and size above zero (422 otherwise). It prices only routes covered by an active rate of an active courier between active zones.',
    changes: 'Nothing is saved. SFA prices the parcel on the spot by the heavier of its real and its size-based weight, adds a cash-on-delivery fee when asked, and lists each courier service with its price and delivery days, the cheapest marked.',
    back: 'No webhook: the prices come back as the answer to this call.',
    see: 'Staff get the same prices with Get shipping quote in [Logistics & Locations](/logistics). Rates are in [Couriers & rates](/logistics?tab=routing), and the cities each one covers in [Zones](/logistics?tab=zones).',
  },
  payment: {
    before: 'An order SFA still takes a payment for: not cancelled, above ₱0.00, nothing captured and no charge still open. Send one with the Order form above.',
    sends: 'The customer pays: POST /integrations/payments with the order and the method (card, GCash, Maya, bank transfer or cash on delivery).',
    checks: 'SFA refuses a cancelled order, a ₱0.00 order and an order already paid (422). A total over the risk limit in [Settings](/settings?tab=system), or a customer with too many failed payments in the last hour, is held for a Finance risk review.',
    changes: 'Card, e-wallet and bank charges go to the sandbox gateway: a total ending in .99 is declined, .98 waits up to 30 minutes for a gateway result, and anything else is captured at once, which posts the money and each seller\'s commission to the [Ledger](/payments?tab=ledger). Cash on delivery stays Pending until the delivery is recorded with the cash collected.',
    back: 'payment.authorized and payment.captured for a capture, or payment.failed for a decline, under Receives from SFA on this tab. A charge that waits, or cash on delivery, sends nothing yet; a gateway charge held for review sends only payment.authorized.',
    see: 'The payment is in [Payments & Transactions](/payments), a held one in [Risk review](/payments?tab=risk-reviews). A paid order moves to [Awaiting booking](/logistics?tab=awaiting-booking) in Logistics & Locations.',
  },
  orderStatus: {
    before: `An open storefront order: send one on ${SF('checkout', 'Shopping & Checkout')} first.`,
    sends: 'The storefront resends the order with the same lines and amounts and its own new status (processing, packed, shipped and so on): PUT /integrations/orders/{id}.',
    checks: 'After a payment attempt SFA refuses any change to the customer, lines or amounts, and a cancelled order stays cancelled (422). Cancelled is refused once money is captured or a parcel is with the courier, so the form offers it only when SFA would take it.',
    changes: 'SFA stores the new status and nothing else reacts to it, except Cancelled: that cancels the order\'s open payments and its parcels not yet with the courier, exactly like Order cancellation. No money had moved, so none moves back.',
    back: 'Nothing for an ordinary status. Cancelled sends payment.cancelled for each open payment and shipment.status_changed for each parcel it cancels.',
    see: 'The status shows in the panel under the form. Cancelled payments are in [Payments & Transactions](/payments) and cancelled parcels in [Logistics & Locations](/logistics?tab=shipments).',
  },
  cancelOrder: {
    before: `An order with no money captured and no parcel with the courier, for example a new order paid by cash on delivery, or by card with a total ending in .98 (send them on ${SF('checkout', 'Shopping & Checkout')}).`,
    sends: 'The customer cancels: POST /integrations/orders/{id}/cancel.',
    checks: 'SFA refuses once money was captured, even if it was refunded later (422, "A captured order needs a refund rather than cancellation"), and when a parcel is already with the courier (422).',
    changes: 'The order becomes Cancelled. SFA cancels its pending or authorized payments, also those held for risk review, and its parcels not yet picked up; it then takes no payment or status for the order. No money moves.',
    back: 'payment.cancelled for each payment it cancelled and shipment.status_changed for each parcel, under Receives from SFA on this tab and in the partner app.',
    see: 'The cancelled payment is in [Payments & Transactions](/payments) and any cancelled parcel in [Logistics & Locations](/logistics?tab=shipments).',
  },
  refund: {
    before: `A captured storefront payment with money left to refund: send an Order and a card Payment on ${SF('checkout', 'Shopping & Checkout')}.`,
    sends: 'The customer asks for money back: POST /integrations/refunds with the payment, the amount, a reason, notes and, optionally, the help-center ticket about it.',
    checks: 'The amount must be above zero and within what is left: captured, minus refunded, minus refunds still waiting (422, "The refund exceeds the unreserved captured balance"). A ticket must be this storefront\'s and linked to the same payment (422).',
    changes: 'SFA records the refund as Requested and notifies the staff who approve refunds. No money moves until Finance approves it, but the amount is held so it cannot be asked for twice.',
    back: 'refund.requested now. Finance\'s decision later sends refund.approved and refund.completed (or refund.failed when the gateway declines it, as it does an amount ending in .99), or refund.rejected.',
    see: 'Finance approves or rejects it in [Payments & Transactions, Refunds](/payments?tab=refunds). A completed refund is posted to the [Ledger](/payments?tab=ledger) and noted on its ticket.',
  },
  courierPickup: {
    before: 'A paid order booked with this courier, with a tracking number and marked Ready for pickup. Book it in [Logistics & Locations, Awaiting booking](/logistics?tab=awaiting-booking), then mark it ready in [Shipments](/logistics?tab=shipments).',
    sends: 'The courier reports that its driver collected the parcel: a signed POST /webhooks/couriers/{code} with an event id, the tracking number, the status Picked up and the time.',
    checks: 'SFA checks the signature, made with the courier\'s secret and under 5 minutes old (401 otherwise), that the tracking number is one of this courier\'s parcels (404), and that pickup is a valid next step (422). A refused event notifies staff; an event id sent twice is processed once.',
    changes: 'The parcel becomes Picked up, and the event is added to its timeline as a courier update. No money moves.',
    back: `The courier gets the parcel as it now stands, not a webhook. The order's storefront gets shipment.status_changed, under Receives from SFA on ${SF('delivery', 'Order & Delivery Management')}.`,
    see: 'The parcel\'s timeline in [Logistics & Locations, Shipments](/logistics?tab=shipments); the panel under the form shows the same steps.',
  },
  courierTrip: {
    before: 'A picked-up parcel: send a Pickup on [Vehicle Reservation & Dispatch](/simulator?system=fleet&module=dispatch) first.',
    sends: 'The courier reports progress on the road: a signed POST /webhooks/couriers/{code} with the status in transit, out for delivery, failed attempt or returned to sender.',
    checks: 'The same signature and parcel checks as Pickup. SFA takes only a valid next step, never a time before the previous event, and no fourth try after three failed attempts (422). Delivered never comes from here: staff record it with proof.',
    changes: 'The parcel\'s status and timeline change. A failed attempt counts toward the limit of three and notifies logistics staff, and the third one starts the return to the seller (Returning); returned to sender cancels a pending cash-on-delivery payment, or asks Finance whether to refund a prepaid one.',
    back: `The storefront gets shipment.status_changed for each step (${SF('delivery', 'Order & Delivery Management')}), and payment.cancelled when a return cancels a cash-on-delivery payment. With Delivery tickets on in [Settings, Automation](/settings?tab=automation), a failed attempt also opens a delivery ticket (ticket.created).`,
    see: 'The parcel in [Logistics & Locations, Shipments](/logistics?tab=shipments), any delivery ticket in [Customer Support](/support?tab=tickets), and the staff notifications under the bell.',
  },
  gatewayEvent: {
    before: `A card, GCash, Maya or bank payment still waiting for a result: send an Order whose total ends in .98 and a Payment for it on ${SF('checkout', 'Shopping & Checkout')}. A captured payment can be disputed.`,
    sends: 'The payment gateway reports what happened to a charge: a signed POST /webhooks/payments/sandbox with an event id, the payment reference and the result.',
    checks: 'SFA checks the signature (401), finds the gateway payment (404; cash on delivery never goes through the gateway) and takes only a result that fits its status (422). A capture must be the full amount, and an event id sent twice is processed once.',
    changes: 'The payment\'s status changes. A capture posts the money and each seller\'s commission to the ledger; a dispute posts a chargeback of what was captured minus refunds, and a dispute won reverses it. Failed and cancelled move no money.',
    back: `The storefront gets the matching payment.authorized, payment.captured, payment.failed, payment.cancelled or payment.disputed (${SF('checkout', 'Shopping & Checkout')}); capturing a charge that was still pending sends payment.authorized first. A dispute won or lost sends no webhook.`,
    see: 'The payment in [Payments & Transactions](/payments) and its money movements in the [Ledger](/payments?tab=ledger).',
  },
  settlementFile: {
    before: 'At least one card, GCash, Maya or bank payment captured in the period. Cash on delivery is never in the file.',
    sends: 'The gateway\'s settlement report: a CSV with the date, gateway reference, amount and fee of each gateway payment captured in the period. Here you download it (GET /simulator/settlement-file) and import it as Finance would.',
    checks: 'On import SFA needs those four columns, dates inside the period you type and no blank or repeated reference; otherwise it answers 422 naming the row. A period with no gateway capture has no file to download.',
    changes: 'The import creates a reconciliation that marks each row Matched, Amount mismatch, Missing in SFA or Missing in report. With three or more captures this test file reports one ₱1.00 short, leaves one out and adds one SFA does not know, so every kind shows; a later file that reports the missing payment resolves it automatically.',
    back: 'Nothing: SFA sends no webhook for reconciliation.',
    see: 'Import and resolve it in [Payments & Transactions, Reconciliation](/payments?tab=reconciliations).',
  },
  ticketLookup: {
    asks: 'The help center asks where a ticket stands: GET /integrations/tickets/{reference}.',
    checks: 'SFA finds only this storefront\'s tickets (404 for any other).',
    answers: 'The subject, status, priority, any rating and every public message. Internal staff notes never leave SFA, and nothing changes.',
    see: 'The same ticket in [Customer Support, Tickets](/support?tab=tickets).',
  },
  kbArticles: {
    asks: 'The help center lists help articles, optionally matching a search: GET /integrations/kb/articles.',
    checks: 'SFA returns only published articles; drafts stay inside SFA.',
    answers: 'Each article\'s title, category and text. Nothing changes.',
    see: 'Support writes and publishes them in [Customer Support, Knowledge base](/support?tab=articles).',
  },
  plans: {
    asks: 'The seller\'s dashboard, or Finance checking what sellers owe, reads a seller\'s subscription: GET /integrations/subscriptions?seller={id}.',
    checks: 'The storefront sees only its own sellers. Finance\'s read-only client may read any storefront\'s seller, because it is allowed to read all financials.',
    answers: 'The plan, its price, the status (trial, active, past due, cancelled) and the next billing date. Nothing changes.',
    see: 'Finance subscribes sellers and manages their plans in [Subscriptions & Commissions](/subscriptions?tab=subscriptions).',
  },
  statements: {
    asks: 'The seller, or Finance checking what the platform owes, reads a seller\'s settlements: GET /integrations/sellers/{id}/statements.',
    checks: 'The storefront sees only its own sellers; Finance may read any storefront\'s. Draft settlements are never returned.',
    answers: 'Each finalized settlement: sales, refunds, commission and fees, the net owed, and the payouts that paid it. Nothing changes.',
    see: 'Finance generates, finalizes and pays settlements in [Settlements & payouts](/subscriptions?tab=settlements).',
  },
  analytics: {
    asks: 'The general ledger reads platform figures for a period: GET /integrations/analytics/summary with From and To dates.',
    checks: 'Its token has read scopes only, so it can send nothing. It reads every storefront\'s figures because Connect lets it read all financials; without that, every figure is 0.',
    answers: 'Sales, captured payments, refunds, commission, subscription and gateway fees and net revenue for the period, plus current subscription, support and delivery counts. Nothing changes.',
    see: 'Staff see the platform\'s own reports in [Analytics & Reports](/analytics).',
  },
  paymentLookup: {
    asks: 'Checkout asks for a payment\'s latest state, for example after the gateway answered later: GET /integrations/payments/{reference}.',
    checks: 'SFA finds only payments of this storefront\'s orders (404 otherwise).',
    answers: 'The status, method, total, captured and refunded amounts, risk review and any failure reason. Nothing changes.',
    see: 'The same payment in [Payments & Transactions](/payments).',
  },
  shipmentLookup: {
    asks: 'The order page shows where a parcel is: GET /integrations/shipments/{reference}.',
    checks: 'SFA finds only parcels of this storefront\'s orders (404 otherwise).',
    answers: 'The status, tracking number, courier, promised date, failed attempts and every timeline event. Nothing changes.',
    see: 'The same parcel in [Logistics & Locations, Shipments](/logistics?tab=shipments), where staff book and update it.',
  },
  refundLookup: {
    asks: 'The returns page asks for a refund decision: GET /integrations/refunds/{reference}.',
    checks: 'SFA finds only refunds of this storefront\'s payments (404 otherwise).',
    answers: 'The status, amount, reason, payment and any rejection reason. Nothing changes.',
    see: 'Finance decides refunds in [Payments & Transactions, Refunds](/payments?tab=refunds).',
  },
  courierShipments: {
    asks: 'Nothing goes to SFA. A real courier knows its bookings from its own system; here the list is read from SFA itself (GET /simulator): every parcel booked with this courier that is not yet delivered, cancelled or returned, newest 50.',
    checks: 'For each parcel SFA works out, with its own rules, which courier reports it takes now, or what staff must do first: add the tracking number, or mark the parcel Ready for pickup.',
    answers: 'Each row names the parcel, its tracking number, status and failed attempts, and that next step. The Pickup and Trip progress forms offer exactly these parcels and steps. Nothing changes.',
    see: 'Staff book parcels in [Logistics & Locations, Awaiting booking](/logistics?tab=awaiting-booking) and follow them in [Shipments](/logistics?tab=shipments).',
  },
  // The "Webhooks SFA sent" panel under Receives from SFA (components/simulator/ReceivedEvents.jsx).
  receivedEvents: {
    before: `The storefront's webhook receiver must be running: ${PARTNER_COMMAND}, from the project folder (see Connection to SFA). While it is off, webhooks wait and are retried.`,
    back: 'When something happens to one of this storefront\'s own records, SFA sends a signed POST to the storefront\'s receiver with the event type and the record. Events about other storefronts\' records never come here.',
    checks: 'SFA counts a webhook as Received only when the receiver answers with HTTP 2xx within 10 seconds. Otherwise it tries again later, further apart each time (up to 12 hours), and marks it Not received after 6 tries. Send again now tries once more at once.',
    see: 'The rows here, newest first: open one for the exact body SFA signed. The partner app lists the same events.',
  },
}
// "How this part works", keyed by module key: where this part sits in the real process, the order to try its sends, and
// where the results show. Same link syntax as GUIDES.
export const HOW_IT_WORKS = {
  accounts: ['This is the storefront\'s sign-up and help-center pages. Start with Customer account and Seller account: every other storefront send names a customer or seller SFA already has.', 'Then send a Help-center ticket, answer it as staff in [Customer Support](/support?tab=tickets), and send the Requester reply or, once the ticket is resolved, a Satisfaction rating.', 'Ticket webhooks appear on the right, under Receives from SFA, and in the partner app. The two reads ask SFA for one ticket and for the published help articles.'],
  catalog: ['This is the seller\'s side of the storefront: product categories and the store profile, including where the seller is paid.', 'Neither send gets a webhook back. The webhooks on the right come from Finance working in [Subscriptions & Commissions](/subscriptions?tab=subscriptions): subscribe the seller, then finalize and pay its settlement.', 'The two reads show the seller\'s plan and settlement statements as the store dashboard would.'],
  checkout: ['This is the cart and checkout. Send an Order, optionally ask for a Shipping quote, then send its Payment.', 'The last two digits of the order total pick the sandbox gateway\'s answer: .99 declines, .98 waits for a gateway event, anything else is captured. The result comes back on the right as payment webhooks.', 'A paid order then waits in [Logistics & Locations, Awaiting booking](/logistics?tab=awaiting-booking), where the Logistics Coordinator books its parcel.'],
  delivery: ['This is the storefront\'s order page. It sends its own order status and follows the parcels SFA ships for the order.', 'Staff book each parcel in [Logistics & Locations](/logistics?tab=awaiting-booking) and the courier moves it on the [Fleet & Transportation](/simulator?system=fleet&module=trips) tab; both send webhooks here.', 'Shipment tracking reads one parcel\'s status and timeline.'],
  returns: ['This is the storefront\'s returns page: cancel an order before money is captured, or ask for a refund after.', 'A refund waits in [Payments & Transactions, Refunds](/payments?tab=refunds) until Finance approves or rejects it; the decision comes back as refund webhooks on the right.', 'Refund decision reads one refund\'s current status.'],
  reporting: ['The Financial Management System (Group 10) keeps the general ledger. It only reads from SFA, with a read-only token.', 'Pick a period and read the platform figures. Connect allows this client to read the figures of every storefront.', 'Reading changes nothing in SFA. Staff see the platform\'s own reports in [Analytics & Reports](/analytics).'],
  receivable: ['This is what sellers owe the platform: their subscription plans and billing dates.', 'Subscribe a seller in [Subscriptions & Commissions](/subscriptions?tab=subscriptions) first, then read it here.', 'Reading changes nothing in SFA.'],
  payable: ['This is what the platform owes sellers: finalized settlements and the payouts that paid them.', 'Generate and finalize a settlement in [Settlements & payouts](/subscriptions?tab=settlements) and record its payout, then read it here.', 'Reading changes nothing in SFA.'],
  dispatch: ['This is the courier\'s dispatch desk. The Logistics Coordinator books a paid order with this courier in [Logistics & Locations](/logistics?tab=awaiting-booking) and marks the parcel Ready for pickup.', 'The parcel then appears under Pickup. Sending it tells SFA the driver has the parcel.', 'SFA answers the courier directly and tells the storefront with a webhook.'],
  trips: ['This is the driver on the road reporting each step: in transit, out for delivery, failed attempt, returned to sender.', 'Delivered is recorded by staff in [Logistics & Locations](/logistics?tab=shipments) with proof of delivery, and the cash for cash on delivery.', 'Three failed attempts send the parcel back to the seller. Every step reaches the storefront as a webhook.'],
  events: ['The payment gateway confirms with a signed webhook what happened to a charge after checkout.', `To have a payment waiting for it, send an Order whose total ends in .98 and a card Payment on ${SF('checkout', 'Shopping & Checkout')}; it waits 30 minutes.`, 'Then send Authorized, Captured, Failed or Cancelled. A captured payment can then be disputed, and the dispute won or lost.'],
  settlement: ['The gateway regularly sends a settlement report of what it captured, and Finance checks it against SFA (reconciliation).', 'Download the file here for a period with gateway captures, then import it in [Payments & Transactions, Reconciliation](/payments?tab=reconciliations) with the same dates.', 'Open the import to see each row matched or flagged, and resolve the flagged ones.'],
}

// Provided by pages/Simulator.jsx: connection (POST /simulator/connect), pickers (GET /simulator), traffic (this
// session's calls), log(entry), clearTraffic(), refresh(), reconnect (the connect mutation) and connectedAt.
export const SimulatorContext = createContext(null)
export const useSimulator = () => useContext(SimulatorContext)
// The feature key (SYSTEMS sends and reads) of the features inside, set by pages/Simulator.jsx; it picks the GUIDES entry.
export const GuideKey = createContext('')
export const useStorefront = () => useSimulator().pickers?.storefront || {}
// Picker rows (GET /simulator) as <Select> options: [value, text].
export const options = {
  customers: (rows = []) => rows.map((row) => [row.external_id, `${row.name} · ${row.external_id}`]),
  sellers: (rows = []) => rows.map((row) => [row.external_id, `${row.shop_name} · ${row.external_id}`]),
  categories: (rows = []) => rows.map((row) => [row.external_id, `${row.name} · ${row.external_id}`]),
  orders: (rows = []) => rows.map((row) => [row.external_id, `${row.external_id} · ${money(row.total_amount)} · ${label(row.status_snapshot)}`]),
  payments: (rows = []) => rows.map((row) => [row.reference, `${row.reference} · ${money(row.total_amount)} · ${label(row.method)} · ${label(row.status)}`]),
  tickets: (rows = []) => rows.map((row) => [row.reference, `${row.reference} · ${row.subject} (${label(row.status)})`]),
  shipments: (rows = []) => rows.map((row) => [row.reference, `${row.reference} · ${row.tracking_number || 'no tracking number'} · ${label(row.status)}`]),
  refunds: (rows = []) => rows.map((row) => [row.reference, `${row.reference} · ${money(row.total_amount)} · ${label(row.status)} · ${row.payment_reference}`]),
}

// What SFA accepts now, worked out by SFA (GET /simulator): each order, payment, ticket and courier parcel row lists its
// `actions`, and `next_step` says what to do in SFA first when there is none. Forms offer only these, never their own
// copy of SFA's rules.
export const rowsWith = (rows = [], action) => rows.filter((row) => row.actions?.includes(action))

const hex = (bytes) => [...bytes].map((byte) => byte.toString(16).padStart(2, '0')).join('')
export const uuid = () => globalThis.crypto?.randomUUID?.() ?? hex(crypto.getRandomValues(new Uint8Array(16)))
const randomHex = (length) => hex(crypto.getRandomValues(new Uint8Array(Math.ceil(length / 2)))).slice(0, length)
export const newId = (prefix) => `${prefix}-${randomHex(6)}`
export const newOrderId = () => `ord-${businessDate(new Date().toISOString()).slice(2).replaceAll('-', '')}-${randomHex(4)}`
// Drops empty optional fields so an upsert never overwrites a stored value with a blank one.
export const compact = (values) => Object.fromEntries(Object.entries(values).map(([key, value]) => [key, typeof value === 'string' ? value.trim() : value]).filter(([, value]) => value !== '' && value != null))

// How far SFA's clock is ahead of this computer's, from the connect answer (expires_at is SFA's time plus the 12-hour
// token life). Courier events are stamped on SFA's clock: SFA refuses an occurred_at in the future or before the
// parcel's previous event, which a computer clock running a little fast or slow would otherwise trip. Measured when the
// answer arrives, the estimate runs a second or two behind SFA, never ahead.
export function clockOffset(expiresAt) {
  const offset = Date.parse(expiresAt) - 12 * 3600 * 1000 - Date.now()
  return Number.isFinite(offset) ? offset : 0
}

// Web Crypto (needed for signing) exists only in secure contexts: localhost or HTTPS.
export const canSign = () => Boolean(globalThis.crypto?.subtle)
export const SIGNING_UNAVAILABLE = 'This page cannot sign webhooks: browsers allow Web Crypto only on localhost or HTTPS. Open SFA at http://localhost or over HTTPS.'
// X-SFA-Signature, the scheme SFA verifies: t=<unix seconds>,v1=<hex HMAC-SHA256 keyed with the secret over "<t>.<raw body>">.
export async function signature(secret, raw, timestamp = Math.floor(Date.now() / 1000)) {
  if (!canSign()) throw new Error(SIGNING_UNAVAILABLE)
  const encode = (text) => new TextEncoder().encode(text)
  const key = await crypto.subtle.importKey('raw', encode(secret), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign'])
  return `t=${timestamp},v1=${hex(new Uint8Array(await crypto.subtle.sign('HMAC', key, encode(`${timestamp}.${raw}`))))}`
}

// Integration calls go out with fetch, not the staff axios client: they carry the system's own token, and a 401 from them
// must never sign the Admin out. The base URL is the one the SPA already uses.
export const apiBase = String(api.defaults.baseURL).replace(/\/+$/, '')
const parse = (text) => { try { return text ? JSON.parse(text) : null } catch { return text } }
// Signatures are stamped on SFA's clock (clockOffset), because SFA refuses one more than 5 minutes off its own time.
export async function callSfa({ method = 'GET', path, body, token, secret, clockOffset = 0, idempotent = false }) {
  const raw = body === undefined ? undefined : JSON.stringify(body)
  const headers = { Accept: 'application/json', 'Content-Type': 'application/json', ...(idempotent && { 'Idempotency-Key': uuid() }) }
  const signed = secret ? await signature(secret, raw ?? '', Math.floor((Date.now() + clockOffset) / 1000)) : null
  // What the traffic log shows: the real request without its credentials (no Authorization header, no signature digest).
  const request = { headers: { ...headers, ...(signed && { 'X-SFA-Signature': signed.replace(/v1=.*/, 'v1=[hidden]') }) }, body: body ?? null }
  try {
    const response = await fetch(`${apiBase}${path}`, { method, body: raw, signal: AbortSignal.timeout(20000), headers: { ...headers, ...(token && { Authorization: `Bearer ${token}` }), ...(signed && { 'X-SFA-Signature': signed }) } })
    return { status: response.status, data: parse(await response.text()), request }
  } catch (error) {
    return { status: 0, data: { message: error.name === 'TimeoutError' ? 'SFA did not answer within 20 seconds.' : `SFA is not reachable at ${apiBase}. Is the API running?` }, request }
  }
}
const NOT_FOUND = {
  courier: "SFA found no parcel with this tracking number among the courier's shipments, or the courier is no longer active. Press Refresh lists, or Reconnect.",
  gateway: 'SFA found no gateway payment with this reference. Cash on delivery never goes through the gateway.',
}
export function failureMessage(status, data, credential) {
  if (status === 401) return `${data?.message && data.message !== 'Unauthenticated.' ? data.message : 'SFA rejected the credentials.'} Press Reconnect to issue fresh ones.`
  if (status === 403) return `${data?.message ? `${data.message} ` : ''}This token lacks the scope for this call, or its client is inactive.`
  if (status === 404) return NOT_FOUND[credential] || 'SFA found no matching record for this system. A record of another storefront answers 404 too.'
  if (status === 409) return 'SFA is still processing an identical request. Try again in a few seconds.'
  if (status === 422 && data?.errors) return Object.values(data.errors).flat().join(' ')
  if (status === 429) return 'Too many requests. Wait a minute and try again.'
  if (status >= 500) return 'SFA hit an internal error. Check the API terminal or backend/storage/logs/laravel.log.'
  return data?.message || `SFA answered with HTTP ${status}.`
}
// The token or secret a system signs its calls with, or why it has none yet.
export function credentialsFor(connection, credential) {
  if (!connection) return { error: 'Not connected to SFA yet. Wait for the connection or press Reconnect.' }
  if (credential === 'storefront' || credential === 'finance') return connection.systems?.[credential]?.token ? { token: connection.systems[credential].token } : { error: 'This system has no token. Press Reconnect.' }
  const secret = credential === 'courier' ? connection.courier?.secret : connection.gateway?.secret
  if (!secret) return { error: credential === 'courier' ? 'SFA has no active courier. Activate one in Logistics & Locations, then press Reconnect.' : 'Set SFA_SANDBOX_WEBHOOK_SECRET in backend/.env, restart the API, then press Reconnect.' }
  return canSign() ? { secret, clockOffset: connection.clock_offset || 0 } : { error: SIGNING_UNAVAILABLE }
}

// One call to SFA from a feature form: busy flag, per-field 422 errors, the response, and an entry in the traffic log.
// `fields` maps SFA's error keys to the form's field names (e.g. { order_id: 'order_external_id' }) so the error shows
// under the field that caused it. After a write the pickers refresh, also when SFA refused it: the record may have
// moved on since the list was loaded.
const idle = { busy: false, status: null, data: null, message: '', errors: {} }
export function useSend(credential) {
  const sim = useSimulator()
  const [state, setState] = useState(idle)
  async function send(request, { onSuccess, fields = {} } = {}) {
    const credentials = credentialsFor(sim.connection, credential)
    if (credentials.error) return setState({ ...idle, message: credentials.error })
    setState({ ...idle, busy: true })
    let result
    try { result = await callSfa({ ...request, ...credentials }) } catch (error) { return setState({ ...idle, message: error.message }) }
    sim.log({ id: uuid(), at: new Date().toISOString(), credential, method: request.method || 'GET', path: request.path, status: result.status, request: result.request, response: result.data })
    const ok = result.status >= 200 && result.status < 300
    const errors = Object.fromEntries(Object.entries(result.status === 422 && result.data?.errors || {}).map(([key, value]) => [fields[key] || key, value]))
    // `at` lets a 401 answer tell whether the page has reconnected since (SimUI Outcome).
    setState({ ...idle, status: result.status, data: result.data, message: ok ? '' : failureMessage(result.status, result.data, credential), errors, at: Date.now() })
    if ((ok || [404, 409, 422].includes(result.status)) && request.method && request.method !== 'GET') sim.refresh()
    if (ok) onSuccess?.(result.data)
  }
  // clear(): forget the last answer, for when the form's record changes and the answer would read as this record's.
  return { ...state, ok: state.status >= 200 && state.status < 300, send, clear: () => setState(idle), fail:(errors, message = 'Check the highlighted fields.') => setState({ ...idle, errors, message }) }
}
export function useForm(initial) {
  const [form, setForm] = useState(initial)
  const bind = (key) => ({ value: form[key], onChange: (event) => setForm((current) => ({ ...current, [key]: event.target.value })) })
  return { form, setForm, bind, reset: () => setForm(initial) }
}

// Pesos typed in the order form become exact integer centavos; SFA re-checks every line total, the subtotal and the total.
// Error keys use SFA's field names so local and server errors land under the same inputs.
export function orderBody(form) {
  const errors = {}
  const amount = (field, value) => { try { return pesosToCentavos(String(value).trim() === '' ? '0' : value) } catch (error) { errors[field] = [error.message]; return 0 } }
  const items = form.items.map((item, index) => {
    const quantity = Number(item.quantity)
    if (!Number.isInteger(quantity) || quantity < 1) errors[`items.${index}.quantity`] = ['Enter a whole number of at least 1.']
    const unit = amount(`items.${index}.unit_price_amount`, item.price)
    return { seller_external_id: item.seller, ...(item.category && { category_external_id: item.category }), product_name: item.product.trim(), quantity, unit_price_amount: unit, line_total_amount: quantity * unit }
  })
  const subtotal = items.reduce((sum, item) => sum + item.line_total_amount, 0)
  const shipping = amount('shipping_amount', form.shipping)
  const discount = amount('discount_amount', form.discount)
  const totals = { subtotal_amount: subtotal, shipping_amount: shipping, discount_amount: discount, total_amount: subtotal + shipping - discount }
  if (totals.total_amount < 0) errors.discount_amount = ['The discount cannot be larger than the items plus shipping.']
  if (![...items.map((item) => item.line_total_amount), ...Object.values(totals)].every(Number.isSafeInteger)) errors.total_amount = ['An amount is too large.']
  return { errors, body: { customer_external_id: form.customer, status_snapshot: 'placed', ...totals, currency: 'PHP', placed_at: new Date().toISOString(), items } }
}

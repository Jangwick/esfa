import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { businessDate, dateTime } from '../../lib/admin'
import { label, money } from '../../lib/payments'
import { options, useSend, useSimulator, useStorefront } from '../../lib/simulator'
import { DateFilter, StatusPill } from '../admin/AdminUI'
import { Empty, Feature, FeatureHead, Input, Outcome, Rows, Select, Send } from './SimUI'

// What a connected system reads back from SFA with its own token (GET calls, shown in the traffic log like any other).
const pill = (status) => <StatusPill status={status}>{label(status)}</StatusPill>
const query = (values) => { const text = new URLSearchParams(Object.entries(values).filter(([, value]) => value)).toString(); return text ? `?${text}` : '' }
// Where staff see the same record in SFA.
const SfaLink = ({ to, children }) => <Link to={to} className="inline-flex items-center gap-1.5 text-xs font-semibold text-brand-blue hover:text-blue-900">{children} <i className="fa-solid fa-arrow-right" aria-hidden="true" /></Link>

// A read's answer belongs to what was asked: once the Admin changes the choice, it is hidden until they read again.
function Lookup({ title, endpoint, description, credential = 'storefront', field = 'Reference', choices, empty, path, render }) {
  const call = useSend(credential)
  const { traffic } = useSimulator()
  const [reference, setReference] = useState('')
  // The reference whose answer is on screen. After every accepted send on this page (the newest successful write in the
  // traffic log changes), it is read again, so the answer never shows a state SFA has already moved past.
  const [shown, setShown] = useState('')
  const lastWrite = traffic.find((entry) => entry.method !== 'GET' && entry.status >= 200 && entry.status < 300)?.id
  useEffect(() => { if (shown) call.send({ path: path(shown) }) }, [lastWrite]) // eslint-disable-line react-hooks/exhaustive-deps
  const submit = (event) => { event.preventDefault(); setShown(reference); call.send({ path: path(reference) }) }
  return <Feature title={title} endpoint={endpoint} description={description} onSubmit={submit}>
    <Select label={field} required placeholder={choices.length ? 'Choose one' : 'Nothing to look up yet'} options={choices} value={reference} onChange={(event) => setReference(event.target.value)} help={choices.length ? undefined : empty} />
    <Send call={call} icon="fa-magnifying-glass" disabled={!choices.length}>Look up</Send>{reference === shown && <Outcome call={call}>{call.ok && call.data && render(call.data)}</Outcome>}
  </Feature>
}

export function TicketLookup() {
  const store = useStorefront()
  return <Lookup title="Ticket status and conversation" endpoint="GET /integrations/tickets/{reference}" description="The help center shows the requester where their ticket stands and every public reply. Internal notes never leave SFA." choices={options.tickets(store.tickets)} empty="Send a Help-center ticket on this tab first; it then appears here." path={(reference) => `/integrations/tickets/${encodeURIComponent(reference)}`} render={(ticket) => <div className="space-y-2"><Rows rows={[['Subject', ticket.subject], ['Status', pill(ticket.status)], ['Priority', label(ticket.priority)], ['Rating', ticket.csat_score ? `${ticket.csat_score} of 5${ticket.csat_comment ? ` · "${ticket.csat_comment}"` : ''}` : null]]} /><SfaLink to={`/support?ticket=${ticket.id}`}>Open the ticket in Support</SfaLink><ul className="space-y-2">{(ticket.messages || []).map((message) => <li key={message.id} className="rounded-lg bg-white p-2 text-slate-700"><p className="text-xs text-slate-500">{label(message.author_type)} · {dateTime(message.created_at)}</p><p className="whitespace-pre-wrap break-words">{message.body}</p></li>)}</ul></div>} />
}

export function KbArticles() {
  const call = useSend('storefront')
  const [search, setSearch] = useState('')
  const [asked, setAsked] = useState(null)
  const submit = (event) => { event.preventDefault(); setAsked(search.trim()); call.send({ path: `/integrations/kb/articles${query({ search: search.trim(), per_page: 10 })}` }) }
  const articles = call.data?.data || []
  return <Feature title="Published help articles" endpoint="GET /integrations/kb/articles" description="The help center lists the articles support has published in SFA; open one to read it. Drafts stay inside SFA." onSubmit={submit}>
    <Input label="Search (optional)" maxLength={255} value={search} onChange={(event) => setSearch(event.target.value)} />
    <Send call={call} icon="fa-book-open">Read articles</Send>
    {(asked === null || asked === search.trim()) && <Outcome call={call}>{articles.length ? <ul className="divide-y divide-emerald-200">{articles.map((article) => <li key={article.id} className="py-1.5"><details><summary className="cursor-pointer font-semibold">{article.title}</summary><p className="mt-1 text-xs">{article.category?.name || 'No category'}</p><p className="mt-1 whitespace-pre-wrap break-words text-slate-700">{article.body}</p></details></li>)}</ul> : <p>No published article matches. Support writes and publishes articles in <Link to="/support?tab=articles" className="font-semibold underline">Customer Support, Knowledge base</Link>.</p>}</Outcome>}
  </Feature>
}

// Storefront: its own sellers. Finance (reads all financials): any storefront's seller, named with source_system.
function useSellerChoice(credential) {
  const { pickers } = useSimulator()
  const [choice, setChoice] = useState('')
  const finance = credential === 'finance'
  const rows = finance ? pickers?.finance_sellers || [] : pickers?.storefront?.sellers || []
  const choices = rows.map((row) => [JSON.stringify([row.external_id, finance ? row.source_system : '']), `${row.shop_name} · ${row.external_id}${finance ? ` · ${row.source_system}` : ''}`])
  const [seller, source] = choice ? JSON.parse(choice) : ['', '']
  return { choices, choice, setChoice, seller, source }
}
function SellerRead({ credential, title, endpoint, description, path, render }) {
  const call = useSend(credential)
  const pick = useSellerChoice(credential)
  const [asked, setAsked] = useState('')
  const submit = (event) => { event.preventDefault(); setAsked(pick.choice); call.send({ path: path(pick.seller, pick.source) }) }
  return <Feature title={title} endpoint={endpoint} description={description} onSubmit={submit}>
    <Select label="Seller" call={call} name="source_system" required placeholder={pick.choices.length ? 'Choose a seller' : 'No seller yet'} options={pick.choices} value={pick.choice} onChange={(event) => pick.setChoice(event.target.value)} help={pick.choices.length ? undefined : <>Storefronts send their sellers to SFA: send a Seller account on <Link to="/simulator?system=storefront&module=accounts" className="font-semibold underline">E-Commerce Core, User & Account Management</Link> first.</>} />
    <Send call={call} icon="fa-magnifying-glass">Read</Send>{pick.choice === asked && <Outcome call={call}>{call.ok && render(call.data?.data || [])}{call.ok && call.data?.total > (call.data?.data?.length || 0) && <p className="text-xs">Showing the newest {call.data.data.length} of {call.data.total}.</p>}</Outcome>}
  </Feature>
}
// A subscription line worded by its status: the price, and the billing date that matters for that status.
const planPrice = (row) => row.plan ? `${money(row.billing_cycle === 'annual' ? row.plan.annual_price_amount : row.plan.monthly_price_amount)} a ${row.billing_cycle === 'annual' ? 'year' : 'month'}` : ''
function planDates(row) {
  const day = (value) => value ? businessDate(value) : 'not set'
  if (row.status === 'trialing') return `Free trial until ${day(row.trial_ends_at)}`
  if (row.status === 'past_due') return `Last charge failed${row.next_retry_at ? `; SFA retries on ${day(row.next_retry_at)}` : ''}`
  if (row.status === 'cancelled') return `Cancelled${row.cancelled_at ? ` on ${day(row.cancelled_at)}` : ''}`
  if (row.status === 'expired') return `Ended on ${day(row.current_period_end)}`
  return row.cancel_at_period_end ? `Ends on ${day(row.current_period_end)} (cancelled at period end)` : `Renews on ${day(row.current_period_end)}`
}
// How SFA reaches a settlement's net (BillingService): sales less refunds, commission and fees, plus any adjustment.
function statementSum(row) {
  const parts = [`Sales ${money(row.gross_sales_amount)}`, `− refunds ${money(row.refunds_amount)}`, `− commission ${money(row.commission_amount)}`, `− gateway fees ${money(row.gateway_fees_amount)}`]
  if (row.subscription_fees_amount) parts.push(`− subscription fees ${money(row.subscription_fees_amount)}`)
  if (row.adjustments_amount) parts.push(`${row.adjustments_amount < 0 ? '−' : '+'} adjustments ${money(Math.abs(row.adjustments_amount))}`)
  return parts.join(' ')
}
export function SellerPlans({ credential }) {
  return <SellerRead credential={credential} title={credential === 'finance' ? "Seller's subscription billing" : "Seller's plan"} endpoint="GET /integrations/subscriptions?seller={id}" description={credential === 'finance' ? 'What the seller owes the platform: plan, price, status and the next billing date. Finance may read every storefront, so it names the storefront too.' : 'The store dashboard shows the seller which SFA plan they are on, what it costs and when it renews.'} path={(seller, source) => `/integrations/subscriptions${query({ seller, source_system: source })}`} render={(rows) => rows.length ? <ul className="divide-y divide-emerald-200">{rows.map((row) => <li key={row.id} className="space-y-0.5 py-1.5"><p className="flex flex-wrap items-center justify-between gap-2"><span className="font-semibold">{row.plan?.name} · {planPrice(row)}</span>{pill(row.status)}</p><p className="text-xs">{planDates(row)}{row.next_plan ? ` · moves to ${row.next_plan.name} at renewal` : ''}</p></li>)}</ul> : <p>This seller has no subscription yet. Finance subscribes sellers in <Link to="/subscriptions?tab=subscriptions" className="font-semibold underline">Subscriptions & Commissions</Link>.</p>} />
}
export function SellerStatements({ credential }) {
  return <SellerRead credential={credential} title={credential === 'finance' ? 'Seller payables and payouts' : 'Settlement statements'} endpoint="GET /integrations/sellers/{id}/statements" description={credential === 'finance' ? 'What the platform owes the seller: finalized settlements and the payouts that paid them.' : 'The seller sees each finalized settlement: sales, refunds, commission, fees and the net paid out.'} path={(seller, source) => `/integrations/sellers/${encodeURIComponent(seller)}/statements${query({ source_system: source })}`} render={(rows) => rows.length ? <ul className="divide-y divide-emerald-200">{rows.map((row) => <li key={row.id} className="space-y-1 py-2"><p className="flex flex-wrap items-center justify-between gap-2"><span className="font-mono font-semibold">{row.reference}</span>{pill(row.status)}</p><p className="text-xs">{businessDate(row.period_start)} to {businessDate(row.period_end)}</p><p className="text-xs">{statementSum(row)} = <b>net {money(row.net_payable_amount)}</b></p>
      {row.payouts?.length ? <ul className="space-y-0.5 text-xs">{row.payouts.map((payout) => <li key={payout.id}>Payout {payout.reference || ''} · {money(payout.amount)} · {label(payout.status)}{payout.paid_at ? ` on ${businessDate(payout.paid_at)}` : ''} · {label(payout.method)} {payout.destination_masked}</li>)}</ul> : <p className="text-xs">{row.net_payable_amount > 0 ? 'Not paid out yet. Finance pays it from Settlements.' : 'No payout: the net is not above zero, so there is nothing to pay.'}</p>}</li>)}</ul> : <p>No finalized settlement yet. Drafts stay hidden until Finance finalizes them in <Link to="/subscriptions?tab=settlements" className="font-semibold underline">Subscriptions & Commissions, Settlements</Link>.</p>} />
}

// [key, title, format, note]: "now" figures are current counts whatever the period; a rate names what it is out of, so 0%
// of nothing reads differently from 0% of something.
const percent = (value) => `${value}%`
const kpis = [['gmv_amount', 'Gross sales (GMV)', money], ['captured_amount', 'Captured payments', money, (data) => `${data.captured_count ?? 0} payment(s)`], ['refund_amount', 'Completed refunds', money], ['refund_rate', 'Refund rate', percent], ['commission_amount', 'Commission earned', money], ['subscription_amount', 'Subscription fees', money], ['gateway_fees_amount', 'Gateway fees', money], ['net_revenue_amount', 'Net platform revenue', money], ['mrr_amount', 'Monthly recurring revenue (now)', money], ['active_subscriptions', 'Active subscriptions (now)', String], ['open_tickets', 'Tickets opened in the period and still open', String], ['sla_compliance', 'SLA met', percent, (data) => data.sla_resolved_count ? `of ${data.sla_resolved_count} resolved ticket(s)` : 'no ticket resolved yet'], ['shipments_in_transit', 'Shipments on the road (now)', String], ['on_time_rate', 'Delivered on time', percent, (data) => data.delivered_count ? `of ${data.delivered_count} delivered parcel(s)` : 'nothing delivered yet']]
export function AnalyticsSummary() {
  const call = useSend('finance')
  const today = businessDate(new Date().toISOString())
  const [period, setPeriod] = useState({ date_from: `${today.slice(0, 8)}01`, date_to: today })
  const [asked, setAsked] = useState(null)
  const submit = (event) => { event.preventDefault(); setAsked(period); call.send({ path: `/integrations/analytics/summary${query(period)}` }) }
  const same = !asked || (asked.date_from === period.date_from && asked.date_to === period.date_to)
  return <Feature title="Platform figures for a period" endpoint="GET /integrations/analytics/summary" description='The general ledger reads revenue, refunds, fees and service figures for the period, for every storefront. Figures marked "now" are current counts whatever the period. If this client is not allowed to read all financials (Connect turns that on), every figure is 0: the ledger owns no orders itself.' onSubmit={submit}>
    <div className="grid gap-2 sm:grid-cols-2"><DateFilter label="From" value={period.date_from} max={period.date_to} onChange={(value) => setPeriod({ ...period, date_from: value })} /><DateFilter label="To" value={period.date_to} min={period.date_from} onChange={(value) => setPeriod({ ...period, date_to: value })} /></div>
    <Send call={call} icon="fa-chart-column">Read figures</Send>
    {same && <Outcome call={call}>{call.ok && <><p className="text-xs">Every storefront, {asked.date_from ? `${asked.date_from} to ${asked.date_to || 'today'}` : `the 30 days up to ${asked.date_to || 'today'}`}:</p><div className="grid grid-cols-2 gap-2 sm:grid-cols-3">{kpis.filter(([key]) => call.data?.[key] !== undefined).map(([key, title, format, note]) => <div key={key} className="min-w-0 rounded-lg bg-white p-3"><p className="text-xs text-slate-500">{title}</p><p className="mt-0.5 break-words font-bold tabular-nums text-slate-900">{format(call.data[key])}</p>{note && <p className="text-xs text-slate-500">{note(call.data)}</p>}</div>)}</div></>}</Outcome>}
  </Feature>
}

export function PaymentLookup() {
  const store = useStorefront()
  return <Lookup title="Payment result" endpoint="GET /integrations/payments/{reference}" description="Checkout asks SFA for the latest state of a payment, for example after the gateway answered later." choices={options.payments(store.payments)} empty="Send an Order, then a Payment for it, on this tab first; the payment then appears here." path={(reference) => `/integrations/payments/${encodeURIComponent(reference)}`} render={(payment) => <Rows rows={[['Payment', payment.reference], ['Status', pill(payment.status)], ['Method', label(payment.method)], ['Total', money(payment.total_amount)], ['Captured', money(payment.captured_amount)], ['Refunded', money(payment.refunded_amount)], ['Risk', label(payment.risk_level)], ['Failure reason', payment.failure_reason || undefined]]} />} />
}

export function ShipmentLookup() {
  const store = useStorefront()
  return <Lookup title="Shipment tracking" endpoint="GET /integrations/shipments/{reference}" description="The order page shows the customer where their parcel is. The integration API could book a parcel too, but it needs SFA's own warehouse and address ids, so here the Logistics Coordinator books each shipment in SFA, which then sends shipment.created." choices={options.shipments(store.shipments)} empty="The Logistics Coordinator has booked no shipment for this storefront's orders yet. Book one in Logistics & Locations (Awaiting booking); it then appears here." path={(reference) => `/integrations/shipments/${encodeURIComponent(reference)}`} render={(shipment) => <div className="space-y-2"><Rows rows={[['Status', pill(shipment.status)], ['Tracking number', shipment.tracking_number], ['Courier', shipment.courier?.name], ['Promised by', shipment.promised_to ? businessDate(shipment.promised_to) : null], ['Failed attempts', shipment.attempt_count ?? 0]]} /><ul className="space-y-1 text-xs">{[...(shipment.events || [])].sort((left, right) => String(right.occurred_at).localeCompare(String(left.occurred_at)) || right.id - left.id).map((event) => <li key={event.id}>{dateTime(event.occurred_at)} · {label(event.status)}{event.location ? ` · ${event.location}` : ''}</li>)}</ul></div>} />
}

export function RefundLookup() {
  const store = useStorefront()
  return <Lookup title="Refund decision" endpoint="GET /integrations/refunds/{reference}" field="Refund" description="The returns page shows whether Finance approved, rejected or completed the refund." choices={options.refunds(store.refunds)} empty="Send a Refund request on this tab first; it then appears here." path={(reference) => `/integrations/refunds/${encodeURIComponent(reference)}`} render={(refund) => <Rows rows={[['Status', pill(refund.status)], ['Amount', money(refund.total_amount)], ['Reason', label(refund.reason)], ['Payment', refund.payment?.reference], ['Rejection reason', refund.rejection_reason || undefined]]} />} />
}

export function CourierShipments() {
  const { pickers } = useSimulator()
  const rows = pickers?.courier_shipments || []
  const code = pickers?.courier?.code
  // Each row says what the courier may report next, as SFA works it out, or what staff must do in SFA first.
  return <div className="space-y-3 rounded-lg border border-slate-200 p-4"><FeatureHead title="Shipments booked with this courier" description={`SFA's own booking list for courier ${code || '—'}: every parcel booked with it that is not yet delivered, cancelled or returned (newest 50), with what the courier can report next. Parcels booked with another courier are not listed. A real courier gets these bookings from its own system; the Logistics Coordinator books them in SFA.`} />
    {!code ? <Empty>SFA has no active courier. <Link to="/logistics?tab=routing" className="font-semibold text-brand-blue hover:text-blue-900">Activate one in Logistics & Locations</Link>, then press Reconnect.</Empty>
      : rows.length ? <ul className="divide-y divide-slate-100 text-sm">{rows.map((row) => <li key={row.id} className="space-y-1 py-2"><div className="flex flex-wrap items-center justify-between gap-2"><span><Link to={`/logistics?shipment=${row.id}`} className="font-mono text-xs font-bold text-slate-900 hover:text-brand-blue">{row.reference}</Link><span className="block text-xs text-slate-500">{row.tracking_number || 'No tracking number yet'}{row.attempt_count > 0 ? ` · ${row.attempt_count} of 3 failed attempts` : ''}</span></span>{pill(row.status)}</div><p className="text-xs text-slate-600">{row.actions?.length ? `The courier can report: ${row.actions.map((action) => label(action).toLowerCase()).join(' or ')}.${row.status === 'out_for_delivery' ? ' Delivered is recorded by staff with proof of delivery.' : ''}` : row.next_step}</p></li>)}</ul>
      : <Empty>No open shipment for this courier. <Link to="/logistics?tab=awaiting-booking" className="font-semibold text-brand-blue hover:text-blue-900">Book one in Logistics & Locations (Awaiting booking)</Link> with courier {code} and a tracking number.</Empty>}
  </div>
}

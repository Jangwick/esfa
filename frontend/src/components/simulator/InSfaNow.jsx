import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import api from '../../lib/api'
import { apiError, dateTime, unwrap } from '../../lib/admin'
import { label, money } from '../../lib/payments'
import { Button, StatusPill } from '../admin/AdminUI'
import { SlaPill } from '../support/TicketDetail'
import { Rows } from './SimUI'

// The record a storefront form works on, as SFA holds it now, beside the form (like ShipmentProgress for the courier).
// Tickets and payments are read from the staff API with the Admin's own session, under the same query keys as their
// staff pages; every simulator send invalidates all queries, so they refetch on their own. Refresh catches what staff
// or scheduled work changed elsewhere.
const pill = (status) => <StatusPill status={status}>{label(status)}</StatusPill>
const Head = ({ children }) => <p className="flex flex-wrap items-center gap-2"><span className="text-xs font-semibold uppercase tracking-wider text-slate-500">In SFA now</span>{children}</p>
const Open = ({ to, children }) => <Link to={to} className="inline-flex items-center gap-1.5 text-xs font-semibold text-brand-blue hover:text-blue-900">{children} <i className="fa-solid fa-arrow-right" aria-hidden="true" /></Link>
const box = 'space-y-3 rounded-lg border border-slate-200 bg-slate-50 p-4'

function Fetched({ query, what, children }) {
  if (query.isError) return <div role="alert" className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">{apiError(query.error, `Could not load ${what}.`)} <button type="button" className="font-semibold underline" onClick={() => query.refetch()}>Try again</button></div>
  if (query.isPending) return <div className="h-24 animate-pulse rounded-lg bg-slate-100" aria-label={`Loading ${what}`} />
  return <section className={box} aria-label={`${what} in SFA now`}>{children(query.data)}<Button variant="secondary" icon="fa-rotate-right" busy={query.isFetching} onClick={() => query.refetch()}>Refresh</Button></section>
}

export function TicketNow({ id }) {
  const query = useQuery({ placeholderData: undefined, enabled: Boolean(id), queryKey: ['support-ticket', id], queryFn: async () => unwrap(await api.get(`/support/tickets/${id}`)) })
  if (!id) return null
  return <Fetched query={query} what="the ticket">{(ticket) => { const last = (ticket.messages || []).filter((message) => !message.is_internal_note).at(-1); return <>
    <Head><span className="font-mono text-xs font-semibold text-slate-700">{ticket.reference}</span>{pill(ticket.status)}<SlaPill state={ticket.sla_state} /></Head>
    {/* Linked to: what SFA saved, including the order's only payment or shipment it links on its own. */}
    <Rows rows={[['Assigned to', ticket.assignee?.name || 'Unassigned'], ['Priority', label(ticket.priority)], ['Linked to', [ticket.order?.external_id, ticket.payment?.reference, ticket.shipment?.reference].filter(Boolean).join(' · ') || 'No order'], ['Rating', ticket.csat_score ? `${ticket.csat_score} of 5${ticket.csat_comment ? ` · "${ticket.csat_comment}"` : ''}` : 'Not rated']]} />
    {last && <p className="break-words text-xs text-slate-600"><span className="font-semibold">Last public message, {label(last.author_type)}, {dateTime(last.created_at)}:</span> {last.body}</p>}
    <Open to={`/support?ticket=${ticket.id}`}>Open in Customer Support</Open>
  </> }}</Fetched>
}

export function PaymentNow({ id }) {
  const query = useQuery({ placeholderData: undefined, enabled: Boolean(id), queryKey: ['payment-detail', 'payments', id], queryFn: async () => (await api.get(`/payments/${id}`)).data })
  if (!id) return null
  return <Fetched query={query} what="the payment">{(payment) => <>
    <Head><span className="font-mono text-xs font-semibold text-slate-700">{payment.reference}</span>{pill(payment.status)}{payment.risk_level && payment.risk_level !== 'none' && <StatusPill status={payment.risk_level}>Risk review</StatusPill>}</Head>
    <Rows rows={[['Method', label(payment.method)], ['Total', money(payment.total_amount)], ['Captured', money(payment.captured_amount)], ['Refunded', money(payment.refunded_amount)], ['Expires', ['pending', 'authorized'].includes(payment.status) && payment.expires_at ? dateTime(payment.expires_at) : undefined], ['Failure reason', payment.failure_reason || undefined]]} />
    {Boolean(payment.refunds?.length) && <ul className="space-y-1 text-xs text-slate-600">{payment.refunds.map((refund) => <li key={refund.id} className="flex flex-wrap items-center gap-2"><span className="font-mono font-semibold">{refund.reference}</span>{money(refund.total_amount)}{pill(refund.status)}</li>)}</ul>}
    <Open to={`/payments?payment=${payment.id}`}>Open in Payments & Transactions</Open>
  </>}</Fetched>
}

// A customer, seller or category row from GET /simulator (refreshed after every send): the record SFA holds now.
function RowNow({ id, rows }) {
  return <section className={box} aria-label={`${id} in SFA now`}>
    <Head><span className="font-mono text-xs font-semibold text-slate-700">{id}</span></Head>
    <Rows rows={rows} />
  </section>
}
export const CustomerNow = ({ customer }) => customer ? <RowNow id={customer.external_id} rows={[['Full name', customer.name], ['Email', customer.email], ['Phone', customer.phone]]} /> : null
// The payout destination as Finance sees it: the bank or e-wallet and the masked number.
export const SellerNow = ({ seller }) => seller ? <RowNow id={seller.external_id} rows={[['Shop name', seller.shop_name], ['Owner name', seller.owner_name], ['Email', seller.email], ['Phone', seller.phone], ['Payout destination', seller.payout_destination_masked ? `${seller.payout_channel || ''} ${seller.payout_destination_masked}`.trim() : 'None']]} /> : null
// The parent may be older than the 50 listed categories; then only its id is known.
export const CategoryNow = ({ category, categories = [] }) => {
  if (!category) return null
  const parent = categories.find((row) => row.external_id === category.parent_external_id)
  return <RowNow id={category.external_id} rows={[['Name', category.name], ['Parent', parent ? `${parent.name} · ${parent.external_id}` : category.parent_external_id || 'None (top level)']]} />
}

// An order row from GET /simulator (already refreshed after every send): its status and every payment attempt on it.
export function OrderNow({ order }) {
  if (!order) return null
  return <section className={box} aria-label={`Order ${order.external_id} in SFA now`}>
    <Head><span className="font-mono text-xs font-semibold text-slate-700">{order.external_id}</span>{pill(order.status_snapshot)}</Head>
    <p className="text-xs text-slate-600">{money(order.total_amount)} · {order.customer?.name} · {order.items?.length || 0} line(s)</p>
    {order.payments?.length ? <ul className="space-y-1 text-xs text-slate-600">{order.payments.map((payment) => <li key={payment.reference} className="flex flex-wrap items-center gap-2"><span className="font-mono font-semibold">{payment.reference}</span>{label(payment.method)}{pill(payment.status)}</li>)}</ul> : <p className="text-xs text-slate-600">No payment yet.</p>}
  </section>
}

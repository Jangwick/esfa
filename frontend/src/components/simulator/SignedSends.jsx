import { useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { useIsFetching, useQuery, useQueryClient } from '@tanstack/react-query'
import api from '../../lib/api'
import { apiError, businessDate, dateTime } from '../../lib/admin'
import { useExport } from '../../lib/adminHooks'
import { gatewayEvents, label, money } from '../../lib/payments'
import { TRIP_STATUSES, options, useForm, useSend, useSimulator, useStorefront, uuid } from '../../lib/simulator'
import { Button, DateFilter, FormError, StatusPill } from '../admin/AdminUI'
import { Feature, FeatureHead, Input, Outcome, Rows, Select, Send } from './SimUI'
import ShipmentProgress from './ShipmentProgress'

// Signed webhooks into SFA: the courier (Group 6) and the payment gateway. The body is signed exactly as sent.
// Every parcel and payment row of GET /simulator carries "actions": what SFA takes for it right now, worked out on the
// server with SFA's own rules (LogisticsService::courierSteps, PaymentService::gatewayEvents), or "next_step" when SFA
// needs something done first. The forms offer only those actions, so they never send what SFA would refuse.
const linkClass = 'font-semibold underline'
const hintClass = 'space-y-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800'

// A 404 from a signed webhook means SFA found no parcel or payment for it; the shared wording is about storefront records.
const notFound = (call, message) => call.status === 404 ? { ...call, message } : call
// Re-reads the pickers, for changes made in SFA in another tab (a parcel marked ready, a risk review cleared).
function RefreshList({ children = 'Refresh list' }) {
  const client = useQueryClient()
  const busy = useIsFetching({ queryKey: ['simulator'] }) > 0
  return <Button variant="secondary" icon="fa-rotate-right" busy={busy} onClick={() => client.invalidateQueries({ queryKey: ['simulator'] })}>{children}</Button>
}

function CourierEvent({ title, description, statuses, empty }) {
  const { pickers, connection } = useSimulator()
  const call = useSend('courier')
  const { form, bind } = useForm({ shipment: '', status: '', location: '', note: '' })
  const steps = (row) => (row.actions || []).filter((action) => statuses.includes(action))
  const all = pickers?.courier_shipments || []
  const rows = all.filter((row) => steps(row).length)
  const waiting = all.filter((row) => row.next_step)
  const selected = rows.find((row) => row.reference === form.shipment)
  const choices = selected ? steps(selected) : []
  const status = choices.includes(form.status) ? form.status : choices[0] || statuses[0]
  const third = status === 'failed_attempt' && selected?.attempt_count >= 2
  // Bumped after every accepted event so the progress panel refetches what SFA now shows staff.
  const [version, setVersion] = useState(0)
  const [sent, setSent] = useState(null)
  function submit(event) {
    event.preventDefault()
    if (!selected) return
    setSent({ reference: selected.reference, status })
    call.send({ method: 'POST', path: `/webhooks/couriers/${encodeURIComponent(connection?.courier?.code || '')}`, body: { id: `crr_${uuid()}`, tracking_number: selected.tracking_number, status, location: form.location.trim() || null, note: form.note.trim() || null, occurred_at: new Date(Date.now() + (connection?.clock_offset || 0)).toISOString() } }, { onSuccess: () => setVersion((value) => value + 1) })
  }
  const shipment = call.data?.shipment
  // Keep showing the parcel just updated even when it can no longer take this form's step (it left the picker).
  const progressId = selected?.id ?? shipment?.id
  // The answer belongs to the parcel it was sent for: choosing another parcel hides it until that one is sent.
  const current = !selected || selected.reference === sent?.reference
  const help = third ? 'This is the third failed attempt: SFA sets the parcel to Returning.' : selected?.status === 'out_for_delivery' ? 'Delivered is not sent from here: staff record it in Logistics & Locations with proof of delivery.' : undefined
  return <Feature title={title} endpoint="POST /webhooks/couriers/{code} (signed)" description={description} onSubmit={submit}>
    <div className="grid gap-3 sm:grid-cols-2"><Select label="Shipment" call={call} name="tracking_number" required placeholder={rows.length ? 'Choose a shipment' : 'No parcel can take this step now'} options={options.shipments(rows)} {...bind('shipment')} value={selected ? form.shipment : ''} />{statuses.length > 1 && <Select label="Status" call={call} name="status" required disabled={!selected} placeholder={selected ? undefined : 'Choose a shipment first'} options={choices.map((value) => [value, label(value)])} {...bind('status')} value={selected ? status : ''} help={selected ? help : 'Pick a shipment first; the steps SFA takes for it then appear here.'} />}<Input label="Location (optional)" call={call} name="location" maxLength={255} {...bind('location')} /><Input label="Note (optional)" call={call} name="note" maxLength={10000} {...bind('note')} /></div>
    {!rows.length && <div className={hintClass}><p>{pickers?.courier ? empty : 'SFA has no active courier. Activate one in Logistics & Locations (Routing, Couriers), then press Reconnect.'}</p>{waiting.length > 0 && <ul className="list-disc space-y-1 pl-4">{waiting.slice(0, 5).map((row) => <li key={row.id}><Link to={`/logistics?shipment=${row.id}`} className={linkClass}>{row.reference}</Link> ({label(row.status)}): {row.next_step}</li>)}</ul>}<div className="flex flex-wrap items-center gap-3"><Link to="/logistics?tab=awaiting-booking" className={linkClass}>Open Logistics & Locations</Link><RefreshList /></div></div>}
    <ShipmentProgress id={progressId} version={version} />
    <Send call={call} icon="fa-truck" disabled={!rows.length}>Send {selected || statuses.length === 1 ? label(status).toLowerCase() : 'update'}</Send>
    {current && <Outcome call={notFound(call, 'SFA has no parcel with this tracking number for this courier, or the courier is no longer active. Staff may have changed the parcel since the list loaded: refresh the list.')}>{call.data?.duplicate ? <p>SFA had already processed this event.</p> : shipment && sent && <><p className="flex flex-wrap items-center gap-2">SFA accepted <b>{label(sent.status).toLowerCase()}</b> for <b className="font-mono">{shipment.reference}</b>; it is now <StatusPill status={shipment.status}>{label(shipment.status)}</StatusPill></p><CourierEffects status={sent.status} shipment={shipment} /></>}</Outcome>}
  </Feature>
}
// What else SFA did with an accepted event (LogisticsService::transition): the storefront's webhook, and for a failed
// attempt or a return the staff notification, the delivery ticket and the payment follow-up.
function CourierEffects({ status, shipment }) {
  return <div className="space-y-1 text-xs">
    <p>The order's storefront gets <b>shipment.status_changed</b>: see <Link to="/simulator?system=storefront&module=delivery" className={linkClass}>Storefront, Order & Delivery Management</Link>.</p>
    {status === 'failed_attempt' && <><p>Logistics staff get a notification (the bell in the top bar).{shipment.status === 'returning' ? ' This was the third failed attempt, so SFA started the return to the sender.' : ''}</p><DeliveryTicket shipment={shipment} /></>}
    {status === 'returned_to_sender' && <p>Logistics staff get a notification. {shipment.cod_amount > 0 ? 'The cash on delivery was never collected, so SFA cancels the pending cash-on-delivery payment once no other cash-on-delivery parcel of the order is open (the storefront then gets payment.cancelled).' : 'The order was prepaid, so Finance gets a notification to decide whether to refund its payment.'}</p>}
  </div>
}
// The delivery ticket SFA opens on a failed attempt (and notes on each later one) while Delivery tickets is on.
function DeliveryTicket({ shipment }) {
  const query = useQuery({ queryKey: ['simulator-delivery-ticket', shipment.id, shipment.attempt_count], queryFn: async () => (await api.get('/support/tickets', { params: { search: shipment.reference, per_page: 10 } })).data.data?.find((ticket) => ticket.shipment_id === shipment.id) ?? null })
  if (query.isPending) return <p>Checking Support for the delivery ticket...</p>
  if (query.isError) return <p role="alert" className="text-red-700">{apiError(query.error, 'Could not check Support for a delivery ticket.')} <button type="button" className={linkClass} onClick={() => query.refetch()}>Try again</button></p>
  if (!query.data) return <p>SFA opened no delivery ticket: Delivery tickets is off in Settings, Automation.</p>
  return <p>Delivery ticket <Link to={`/support?ticket=${query.data.id}`} className={linkClass}>{query.data.reference}</Link> in Support is {label(query.data.status).toLowerCase()}, {label(query.data.priority).toLowerCase()} priority. SFA opens one on a failed attempt when the parcel has no open delivery ticket (the storefront gets <b>ticket.created</b> under <Link to="/simulator?system=storefront&module=accounts" className={linkClass}>User & Account Management</Link>), and adds a note to it on each later one.</p>
}
export function CourierPickup() {
  const code = useSimulator().pickers?.courier?.code
  return <CourierEvent title="Pickup" statuses={['picked_up']} description={`The driver collects the parcel from the seller, and SFA records it on the shipment's timeline. Only parcels SFA takes a pickup for are listed: booked with this courier${code ? ` (${code})` : ''}, with a tracking number, and marked Ready for pickup (or still Pending while Settings, Automation, "Fill skipped courier steps" is on).`} empty={`No parcel can be picked up yet. In Logistics & Locations, book a paid storefront order on the Awaiting booking tab with the ${code || 'first active'} courier and its tracking number, then mark the shipment Ready for pickup. It then appears here.`} />
}
export function CourierTrip() {
  return <CourierEvent title="Trip progress" statuses={TRIP_STATUSES} description={'Progress on the road. Each parcel offers only the steps SFA takes for it now: picked up, in transit, out for delivery, failed attempt, then out for delivery again. The third failed attempt sends the parcel back (Returning), and the courier reports returned to sender once the seller has it. With Settings, Automation, "Fill skipped courier steps" on, SFA also takes later steps and records the skipped ones. Delivered is not sent from here: staff record it in Logistics & Locations with proof of delivery.'} empty={<>No parcel is on the road. Send a pickup first on <Link to="/simulator?system=fleet&module=dispatch" className={linkClass}>Vehicle Reservation & Dispatch</Link>; the parcel then appears here.</>} />
}

// Plain-language names for the gateway results, and what SFA does with each (PaymentService::processEvent). The gateway
// sends an amount only with a capture, which must be the full payment; a chargeback is always captured minus refunded.
const RESULTS = { 'payment.authorized': ['Authorized', 'the bank approved the charge'], 'payment.captured': ['Captured', 'the money was taken'], 'payment.failed': ['Failed', 'the bank declined the charge'], 'payment.cancelled': ['Cancelled', 'the charge was voided'], 'payment.disputed': ['Disputed', "the customer's bank opened a chargeback"], 'payment.dispute_won': ['Dispute won', 'the platform keeps the money'], 'payment.dispute_lost': ['Dispute lost', "the customer's bank keeps the money"] }
function effect(type, payment) {
  const chargeback = money(payment.captured_amount - payment.refunded_amount)
  return {
    'payment.authorized': `SFA marks it authorized and sends payment.authorized. ${payment.risk_level === 'none' ? 'Scheduled work then captures it while automatic capture is on (the default).' : 'It then waits for the risk review before any capture.'}`,
    'payment.captured': `The gateway sends the full ${money(payment.total_amount)} (SFA refuses a partial capture). SFA posts the capture and the sellers' commissions to the ledger and sends payment.captured.`,
    'payment.failed': "SFA marks it failed and sends payment.failed. No money moves; the failure counts toward the customer's risk check.",
    'payment.cancelled': 'SFA marks it cancelled and sends payment.cancelled. No money moves.',
    'payment.disputed': `The bank takes back ${chargeback} (captured minus refunded; the gateway sends no amount). SFA posts a chargeback to the ledger and sends payment.disputed.`,
    'payment.dispute_won': `The ${chargeback} comes back: SFA reverses the chargeback. The storefront gets no webhook for a dispute outcome.`,
    'payment.dispute_lost': 'The chargeback stands and the payment is closed. The storefront gets no webhook for a dispute outcome.',
  }[type]
}

// The payment as SFA holds it, from the picker row (refetched after every send).
function PaymentNow({ payment }) {
  return <section aria-label={`${payment.reference} in SFA`} className="space-y-3 rounded-lg border border-slate-200 bg-slate-50 p-4">
    <div className="flex flex-wrap items-center justify-between gap-2"><div className="flex flex-wrap items-center gap-2"><p className="text-xs font-semibold uppercase tracking-wider text-slate-500">In SFA now</p><span className="font-mono text-xs font-semibold text-slate-700">{payment.reference}</span><StatusPill status={payment.status}>{label(payment.status)}</StatusPill></div><RefreshList>Refresh</RefreshList></div>
    <Rows rows={[['Total', money(payment.total_amount)], ['Captured', money(payment.captured_amount)], ['Refunded', money(payment.refunded_amount)], ['Method', label(payment.method)], ['Risk review', payment.risk_level === 'none' ? 'None' : label(payment.risk_level)], ['Expires without a result', payment.expires_at ? dateTime(payment.expires_at) : undefined]]} />
    {payment.risk_level !== 'none' && <p className="text-xs font-medium text-amber-700">SFA takes no capture until Finance clears the risk review in <Link to="/payments?tab=risk-reviews" className={linkClass}>Payments & Transactions, Risk reviews</Link>.</p>}
    {payment.next_step && <p className="text-xs text-slate-600">{payment.next_step}</p>}
    <Link to={`/payments?payment=${payment.id}`} className="inline-flex items-center gap-1.5 text-xs font-semibold text-brand-blue hover:text-blue-900">Open in Payments & Transactions <i className="fa-solid fa-arrow-right" aria-hidden="true" /></Link>
  </section>
}

export function GatewayEvent() {
  const store = useStorefront()
  const call = useSend('gateway')
  const refreshing = useIsFetching({ queryKey: ['simulator'] }) > 0
  const { form, bind } = useForm({ payment: '', type: '' })
  const results = (row) => (row.actions || []).filter((action) => gatewayEvents.includes(action))
  const all = store.payments || []
  const payments = all.filter((row) => results(row).length)
  const selected = payments.find((row) => row.reference === form.payment)
  const choices = selected ? results(selected) : []
  const type = choices.includes(form.type) ? form.type : choices[0] || ''
  const [sent, setSent] = useState(null)
  function submit(event) {
    event.preventDefault()
    if (!selected) return
    setSent({ reference: selected.reference, type, from: selected.status, effect: effect(type, selected) })
    call.send({ method: 'POST', path: '/webhooks/payments/sandbox', body: { event_id: `gw_${uuid()}`, reference: selected.reference, type, ...(type === 'payment.captured' && { amount: selected.total_amount }) } })
  }
  // Keep showing the payment just updated even when it takes no more results (it left the picker). The answer belongs to
  // that payment: choosing another one hides it until that one is sent.
  const after = all.find((row) => row.reference === sent?.reference)
  const shown = selected || after
  const current = !selected || selected.reference === sent?.reference
  return <Feature title="Payment result" endpoint="POST /webhooks/payments/sandbox (signed)" description="The gateway reports what happened to a card, GCash, Maya or bank transfer charge. Each payment offers only the results SFA takes for it now: a pending charge can be authorized, captured, failed or cancelled, a captured one disputed, and a disputed one won or lost. Cash on delivery never goes through the gateway." onSubmit={submit}>
    <div className="grid gap-3 sm:grid-cols-2"><Select label="Storefront payment" call={call} name="reference" required placeholder={payments.length ? 'Choose a payment' : 'No payment can take a result now'} options={payments.map((row) => [row.reference, `${row.reference} · ${money(row.total_amount)} · ${label(row.method)} · ${label(row.status)}${row.risk_level !== 'none' ? ' · risk review' : ''}`])} {...bind('payment')} value={selected ? form.payment : ''} /><Select label="Result" call={call} name="type" required disabled={!selected} placeholder={selected ? undefined : 'Choose a payment first'} options={choices.map((value) => [value, RESULTS[value].join(': ')])} {...bind('type')} value={type} help={selected ? effect(type, selected) : 'Pick a payment first; the results SFA takes for it then appear here.'} /></div>
    {!payments.length && <div className={hintClass}><p>No storefront payment can take a gateway result now. To make one, open <Link to="/simulator?system=storefront&module=checkout" className={linkClass}>Storefront, Shopping & Checkout</Link>, send an Order whose total ends in .98, then a card, GCash, Maya or bank transfer Payment for it: it waits 30 minutes for this result. A payment captured there can then be disputed here.</p><RefreshList /></div>}
    {shown && <PaymentNow payment={shown} />}
    <Send call={call} icon="fa-building-columns" disabled={!payments.length}>Send result</Send>
    {current && <Outcome call={notFound(call, 'SFA has no gateway payment with this reference: it may have been removed, and cash on delivery never goes through the gateway. Refresh the list.')}>{call.data?.processed === false ? <p>SFA had already processed this event, so nothing changed.</p> : sent && <><p className="flex flex-wrap items-center gap-2">SFA accepted <b>{RESULTS[sent.type][0].toLowerCase()}</b> for <b className="font-mono">{sent.reference}</b>{after && !refreshing && <>; it is now <StatusPill status={after.status}>{label(after.status)}</StatusPill></>}</p><p className="text-xs">{sent.effect}</p>{/* A dispute outcome's effect already says the storefront gets no webhook. */}{!['payment.dispute_won', 'payment.dispute_lost'].includes(sent.type) && <p className="text-xs">The storefront gets <b>{sent.type === 'payment.captured' && sent.from === 'pending' ? 'payment.authorized, then payment.captured' : sent.type}</b>: see <Link to="/simulator?system=storefront&module=checkout" className={linkClass}>Storefront, Shopping & Checkout</Link>.</p>}</>}</Outcome>}
  </Feature>
}

export function SettlementFile() {
  // The download's answer stays under its button until the next download or a new period (useNotice would hide it).
  const [error, setError] = useState('')
  const [saved, setSaved] = useState('')
  const failed = useRef(false)
  const exporter = useExport((message) => { failed.current = true; setError(message) })
  // The newest captured gateway payment, so the period starts on a day the file has rows for: SFA refuses a period with
  // none, and cash on delivery never passes through the gateway, so a cash-on-delivery capture day may have no rows.
  const latest = useQuery({ queryKey: ['payments', 'latest-gateway-capture'], staleTime: 0, refetchOnWindowFocus: true, queryFn: async () => (await api.get('/payments', { params: { status: 'captured', sort: '-captured_at', per_page: 100 } })).data.data?.find((row) => row.gateway !== 'cod') ?? null })
  // The newest reconciliation run, so the Admin sees the import land without leaving the simulator.
  const run = useQuery({ queryKey: ['reconciliations', 'latest'], staleTime: 0, refetchOnWindowFocus: true, queryFn: async () => (await api.get('/reconciliations', { params: { per_page: 1 } })).data.data?.[0] ?? null })
  const suggested = businessDate(latest.data?.captured_at || new Date().toISOString())
  const [chosen, setChosen] = useState(null)
  const period = chosen || { date_from: suggested, date_to: suggested }
  const choose = (next) => { setChosen(next); setError(''); setSaved('') }
  const name = `Sandbox gateway settlement ${period.date_from} to ${period.date_to}`
  async function download() {
    failed.current = false; setError(''); setSaved('')
    await exporter.exportCsv('/simulator/settlement-file', period, name)
    if (!failed.current) setSaved(name)
  }
  const tryAgain = (query) => <button type="button" className={linkClass} onClick={() => query.refetch()}>Try again</button>
  return <div className="space-y-3 rounded-lg border border-slate-200 p-4"><FeatureHead title="Settlement report" endpoint="GET /simulator/settlement-file" description="The CSV the gateway sends for the card, GCash, Maya, bank transfer and subscription payments SFA captured in a period. Cash on delivery is collected by the courier, so it is never in this file. The first file for a period reports one amount ₱1.00 short, leaves one payment out and adds one row SFA does not know, so with three or more captures the reconciliation shows every outcome. A later file for the same period includes the payment left out, and importing it resolves that row automatically." />
    <p className="text-xs text-slate-600">{latest.isPending ? 'Finding the newest gateway capture...' : latest.isError ? <>{apiError(latest.error, 'Could not find the newest gateway capture.')} {tryAgain(latest)}</> : latest.data ? `Newest captured gateway payment: ${latest.data.reference}, captured ${dateTime(latest.data.captured_at)}. The period starts on that day.` : <>SFA has no captured gateway payment yet. Send a card, GCash, Maya or bank transfer Payment on <Link to="/simulator?system=storefront&module=checkout" className={linkClass}>Storefront, Shopping & Checkout</Link> first (a total not ending in .98 or .99 is captured at once).</>}</p>
    <div className="grid gap-2 sm:grid-cols-2"><DateFilter label="From" value={period.date_from} max={period.date_to} onChange={(value) => choose({ ...period, date_from: value })} /><DateFilter label="To" value={period.date_to} min={period.date_from} onChange={(value) => choose({ ...period, date_to: value })} /></div>
    <div className="flex flex-wrap items-center gap-3"><Button variant="secondary" icon="fa-file-arrow-down" busy={exporter.busy} disabled={!period.date_from || !period.date_to} onClick={download}>Download file</Button><Link to="/payments?tab=reconciliations" className="inline-flex items-center gap-1.5 text-sm font-semibold text-brand-blue hover:text-blue-900">Import it on Reconciliation <i className="fa-solid fa-arrow-right" aria-hidden="true" /></Link></div>
    {(!period.date_from || !period.date_to) && <p className="text-xs text-amber-700">Choose both dates to download the file.</p>}
    <FormError message={error} />
    {saved && <p role="status" className="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-xs text-emerald-800"><i className="fa-solid fa-circle-check mr-1" aria-hidden="true" />Downloaded. The file is in your browser's downloads; its name starts with <b className="break-words">{saved}</b>.</p>}
    <p className="text-xs text-slate-600">On Reconciliation, choose Import CSV and type the same period: From <b>{period.date_from}</b> and To <b>{period.date_to}</b>. A shorter period makes SFA refuse the file; a longer one flags captures the file does not cover.</p>
    <section aria-label="Latest reconciliation in SFA" className="space-y-2 rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs text-slate-600"><div className="flex flex-wrap items-center justify-between gap-2"><p className="font-semibold uppercase tracking-wider text-slate-500">In SFA now</p><Button variant="secondary" icon="fa-rotate-right" busy={run.isFetching} onClick={() => run.refetch()}>Refresh</Button></div>
      {run.isPending ? <div className="h-8 animate-pulse rounded bg-slate-100" aria-label="Loading the latest reconciliation" /> : run.isError ? <p className="text-red-700" role="alert">{apiError(run.error, 'Could not load the latest reconciliation.')} {tryAgain(run)}</p> : run.data ? <p>Latest import: <b>{run.data.filename}</b>, {String(run.data.date_from).slice(0, 10)} to {String(run.data.date_to).slice(0, 10)}: {run.data.items_count} rows, <b>{run.data.unmatched_count} unmatched</b>. <Link to={`/payments?tab=reconciliations&reconciliation=${run.data.id}`} className={linkClass}>View in Payments & Transactions</Link></p> : <p>No settlement file has been imported yet.</p>}
    </section>
  </div>
}

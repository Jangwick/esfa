import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import api from '../../lib/api'
import { centavosToPesos, pesosToCentavos, unwrap } from '../../lib/admin'
import { fetchChoices } from '../../lib/adminHooks'
import { label, money, parsePesos, refundReasons } from '../../lib/payments'
import { ticketCategories, ticketChannels, ticketPriorities } from '../../lib/support'
import { PAYMENT_METHODS, QUOTE_DEFAULTS, compact, newId, newOrderId, options, orderBody, rowsWith, useForm, useSend, useSimulator, useStorefront } from '../../lib/simulator'
import { Button, StatusPill } from '../admin/AdminUI'
import { CategoryNow, CustomerNow, OrderNow, PaymentNow, SellerNow, TicketNow } from './InSfaNow'
import { Feature, Input, Outcome, Rows, Select, Send, TextArea } from './SimUI'

// What E-Commerce Core (Group 2) sends to SFA, each through the real integration API with the demo-storefront token.
// The pickers spell out the two stored values a panelist would not read at a glance.
const plain = (value) => ({ cod: 'Cash on delivery', refund_return: 'Refund or return' })[value] ?? label(value)
const asPairs = (values) => values.map((value) => [value, plain(value)])
const go = (to, text) => <Link to={to} className="font-semibold underline">{text}</Link>
const tab = (module, system = 'storefront') => `/simulator?system=${system}&module=${module}`
// What to do in SFA (or on another tab) before this form has anything to send.
const Needs = ({ children }) => <p className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800">{children}</p>
const Note = ({ children }) => <p className="text-xs">{children}</p>
const NO_WEBHOOK = 'SFA sends no webhook for this, so nothing new appears under Receives from SFA.'
// Settings (risk threshold and failed-attempt limit), the same query the Settings page uses.
const useSettings = () => useQuery({ queryKey: ['settings'], queryFn: async () => unwrap(await api.get('/settings')) }).data

export function CustomerSend() {
  const store = useStorefront()
  const call = useSend('storefront')
  const blank = { customer: '', name: '', email: '', phone: '' }
  const { form, setForm, bind } = useForm(blank)
  const customers = store.customers || []
  const current = customers.find((row) => row.external_id === form.customer)
  // A new choice starts from that customer's account as SFA holds it, so nothing typed for another customer is sent.
  const choose = (event) => { call.clear(); const row = customers.find((item) => item.external_id === event.target.value); setForm({ ...blank, customer: row?.external_id || '', name: row?.name || '', email: row?.email || '', phone: row?.phone || '' }) }
  const saved = call.data
  function submit(event) {
    event.preventDefault()
    const { customer, ...values } = form
    call.send({ method: 'PUT', path: `/integrations/customers/${encodeURIComponent(customer || newId('cus'))}`, body: compact(values) }, { onSuccess: () => { if (!customer) setForm(blank) } })
  }
  return <Feature title="Customer account" endpoint="PUT /integrations/customers/{id}" description="A shopper signs up, or edits their account. SFA keeps a copy so support, payments and refunds can name them. Choosing a customer fills in the account SFA holds now. Blank optional fields keep what SFA has; they cannot be cleared from here." onSubmit={submit}>
    <Select label="Customer" call={call} name="external_id" placeholder="New customer" help="Choose an existing customer to send a change to their account instead." options={options.customers(customers)} value={form.customer} onChange={choose} />
    <div className="grid gap-3 sm:grid-cols-2"><Input label="Full name" call={call} name="name" required maxLength={255} {...bind('name')} /><Input label="Email (optional)" call={call} name="email" type="email" maxLength={255} {...bind('email')} /><Input label="Phone (optional)" call={call} name="phone" maxLength={80} {...bind('phone')} /></div>
    <CustomerNow customer={current} />
    <Send call={call} icon="fa-user-plus">{form.customer ? 'Send change' : 'Send customer'}</Send>
    <Outcome call={call}>{saved?.external_id && <><p>Customer <b className="font-mono">{saved.external_id}</b> was {call.status === 201 ? 'created' : 'updated'} in SFA. It now holds:</p><Rows rows={[['Full name', saved.name], ['Email', saved.email ?? null], ['Phone', saved.phone ?? null]]} /><Note>Staff can now pick them as a requester in Customer Support (New ticket), and they are in this page's Order and Help-center ticket lists. {NO_WEBHOOK}</Note></>}</Outcome>
  </Feature>
}

// A seller's optional payout destination. SFA stores it encrypted and shows staff only a masked number; with one, Finance
// pays the seller's settlements without typing a destination.
const payoutBlank = { bank_name: '', account_name: '', account_number: '' }
// Finance pays settlements by bank transfer or GCash only (BillingService::settlementAction picks the method from the
// stored destination: account_number means a bank, number means GCash), so the choices are those two kinds.
const GCASH = 'GCash'
const PAYOUT_CHANNELS = ['BDO Unibank', 'BPI', 'Metrobank', 'Land Bank of the Philippines', 'UnionBank', 'Security Bank', 'PNB', 'RCBC', 'China Bank', GCASH]
function sellerBody({ bank_name, account_name, account_number, ...profile }) {
  // No channel chosen means no destination: leftovers typed before clearing the choice are not sent.
  // Spaces typed for readability are dropped: Finance's payout shows the stored number masked down to its last four digits.
  const digits = account_number.replace(/\s+/g, '')
  const payout = !bank_name ? {} : compact(bank_name === GCASH ? { bank_name, account_name, number: digits } : { bank_name, account_name, account_number: digits })
  return { ...compact(profile), ...(Object.keys(payout).length > 0 && { payout_destination: payout }) }
}
// update: a store profile update, where no choice keeps the destination SFA holds instead of sending none.
function PayoutFields({ call, bind, update = false }) {
  const channel = bind('bank_name').value
  const gcash = channel === GCASH
  const help = channel ? `Finance will pay this seller's settlements by ${gcash ? 'GCash' : 'bank transfer'} without typing a destination.`
    : update ? 'Left on Keep the current one, SFA keeps the destination it holds (In SFA now, below). Choose a bank or e-wallet to replace it.'
      : "Choose a bank or e-wallet first to enter the account. Without one, Finance types a destination each time it pays this seller's settlement."
  return <fieldset className="grid gap-3 rounded-lg border border-slate-200 p-3 sm:grid-cols-3"><legend className="px-1 text-xs font-semibold text-slate-700">Payout destination (optional)</legend>
    <Select label="Bank or e-wallet" call={call} name="payout_destination.bank_name" placeholder={update ? 'Keep the current one' : 'No payout destination'} options={PAYOUT_CHANNELS.map((name) => [name, name === GCASH ? 'GCash (e-wallet)' : name])} {...bind('bank_name')} />
    <Input label="Account name" call={call} name="payout_destination.account_name" maxLength={255} required={Boolean(channel)} disabled={!channel} {...bind('account_name')} />
    {/* The patterns keep what Finance's payout needs: a number whose last four characters are digits. */}
    <Input label={gcash ? 'GCash mobile number' : 'Account number'} call={call} name={gcash ? 'payout_destination.number' : 'payout_destination.account_number'} maxLength={40} inputMode="numeric" placeholder={gcash ? '0917 123 4567' : '0012 3456 7890'} pattern={gcash ? '09[0-9]{2} ?[0-9]{3} ?[0-9]{4}' : '(?:[0-9] ?){6,19}[0-9]'} title={gcash ? 'An 11-digit mobile number starting with 09, like 0917 123 4567.' : 'Digits only, 7 to 20 of them; spaces are allowed.'} required={Boolean(channel)} disabled={!channel} {...bind('account_number')} />
    <p className="text-xs text-slate-500 sm:col-span-3">{help}</p></fieldset>
}
const SellerSaved = ({ call, what }) => call.data?.external_id ? <><p>{what} <b className="font-mono">{call.data.external_id}</b> was {call.status === 201 ? 'created' : 'updated'} in SFA{call.data.payout_destination_masked ? `; payouts go to ${call.data.payout_destination_masked}` : ''}.</p><Note>{NO_WEBHOOK} The plan, invoice, settlement and payout webhooks of Product & Store Management come only after Finance subscribes the seller in {go('/subscriptions?tab=subscriptions', 'Subscriptions & Commissions')}.</Note></> : null

export function SellerAccountSend() {
  const store = useStorefront()
  const call = useSend('storefront')
  const { form, bind, reset } = useForm({ shop_name: '', owner_name: '', email: '', phone: '', ...payoutBlank })
  const submit = (event) => { event.preventDefault(); call.send({ method: 'PUT', path: `/integrations/sellers/${newId('sel')}`, body: sellerBody(form) }, { onSuccess: reset }) }
  return <Feature title="Seller account" endpoint="PUT /integrations/sellers/{id}" description="A shop opens on the marketplace. SFA bills its subscription and pays out its settlements. A new seller has no plan until Finance subscribes it in SFA." onSubmit={submit}>
    <div className="grid gap-3 sm:grid-cols-2"><Input label="Shop name" call={call} name="shop_name" required maxLength={255} {...bind('shop_name')} /><Input label="Owner name (optional)" call={call} name="owner_name" maxLength={255} {...bind('owner_name')} /><Input label="Email (optional)" call={call} name="email" type="email" maxLength={255} {...bind('email')} /><Input label="Phone (optional)" call={call} name="phone" maxLength={80} {...bind('phone')} /></div>
    <PayoutFields call={call} bind={bind} />
    <Send call={call} icon="fa-store">Send seller</Send><Outcome call={call}><SellerSaved call={call} what="Seller" /></Outcome>
    {call.ok && <SellerNow seller={(store.sellers || []).find((row) => row.external_id === call.data?.external_id)} />}
  </Feature>
}

export function StoreProfileSend() {
  const store = useStorefront()
  const call = useSend('storefront')
  const blank = { seller: '', shop_name: '', owner_name: '', email: '', phone: '', ...payoutBlank }
  const { form, setForm, bind } = useForm(blank)
  const sellers = store.sellers || []
  const current = sellers.find((row) => row.external_id === form.seller)
  // A new choice starts from that seller's profile as SFA holds it, so nothing typed for the previous seller is sent to this
  // one. The payout destination is never prefilled: SFA shows staff only a masked number.
  function choose(event) {
    call.clear()
    const row = sellers.find((item) => item.external_id === event.target.value)
    setForm({ ...blank, seller: event.target.value, ...Object.fromEntries(['shop_name', 'owner_name', 'email', 'phone'].map((key) => [key, row?.[key] || ''])) })
  }
  function submit(event) { event.preventDefault(); const { seller, ...profile } = form; call.send({ method: 'PUT', path: `/integrations/sellers/${encodeURIComponent(seller)}`, body: sellerBody(profile) }) }
  return <Feature title="Store profile update" endpoint="PUT /integrations/sellers/{id}" description="The seller edits their store. Choosing a seller fills in the profile SFA holds now; In SFA now shows its payout destination masked, as Finance sees it, so the form never prefills it. The shop name is sent every time. Blank fields are left out, so SFA keeps what it has; nothing can be cleared from here." onSubmit={submit}>
    <Select label="Seller" call={call} name="external_id" required placeholder={sellers.length ? 'Choose a seller' : 'No seller yet'} options={options.sellers(sellers)} value={form.seller} onChange={choose} />
    {!sellers.length && <Needs>No storefront seller yet. Send one with Seller account on {go(tab('accounts'), 'User & Account Management')}.</Needs>}
    <div className="grid gap-3 sm:grid-cols-2"><Input label="Shop name" call={call} name="shop_name" required maxLength={255} {...bind('shop_name')} /><Input label="Owner name (optional)" call={call} name="owner_name" maxLength={255} {...bind('owner_name')} /><Input label="Email (optional)" call={call} name="email" type="email" maxLength={255} {...bind('email')} /><Input label="Phone (optional)" call={call} name="phone" maxLength={80} {...bind('phone')} /></div>
    <PayoutFields call={call} bind={bind} update />
    <SellerNow seller={current} />
    <Send call={call} icon="fa-store" disabled={!sellers.length}>Send profile</Send><Outcome call={call}><SellerSaved call={call} what="Store profile of" /></Outcome>
  </Feature>
}

export function CategorySend() {
  const store = useStorefront()
  const call = useSend('storefront')
  const blank = { category: '', name: '', parent_external_id: '' }
  const { form, setForm, bind } = useForm(blank)
  const categories = store.categories || []
  const current = categories.find((row) => row.external_id === form.category)
  // SFA lists the parents it takes for each category (not itself, nothing below it); a new category may go under any.
  const parents = current ? categories.filter((row) => current.parent_choices?.includes(row.external_id)) : categories
  const parent = parents.some((row) => row.external_id === form.parent_external_id) ? form.parent_external_id : ''
  // A new choice starts from the category as SFA holds it, parent included, so a rename never moves it by accident.
  const choose = (event) => { call.clear(); const row = categories.find((item) => item.external_id === event.target.value); setForm({ ...blank, category: event.target.value, name: row?.name || '', parent_external_id: row?.parent_external_id || '' }) }
  function submit(event) {
    event.preventDefault()
    call.send({ method: 'PUT', path: `/integrations/categories/${encodeURIComponent(form.category || newId('cat'))}`, body: compact({ name: form.name, parent_external_id: parent }) }, { onSuccess: () => { if (!form.category) setForm(blank) } })
  }
  return <Feature title="Product category" endpoint="PUT /integrations/categories/{id}" description="The catalog's categories. Order lines can name one, and Finance can set a commission rule for a category. Choose an existing category to rename or move it; its name and parent are filled in as SFA holds them. SFA saves exactly the parent you send (None makes it top level), so Parent lists only what SFA takes: never the category itself or one below it." onSubmit={submit}>
    <Select label="Category" placeholder="New category" options={options.categories(categories)} value={form.category} onChange={choose} />
    <div className="grid gap-3 sm:grid-cols-2"><Input label="Category name" call={call} name="name" required maxLength={255} {...bind('name')} /><Select label="Parent category (optional)" call={call} name="parent_external_id" placeholder="None" options={options.categories(parents)} {...bind('parent_external_id')} value={parent} /></div>
    <CategoryNow category={current} categories={categories} />
    <Send call={call} icon="fa-tags">{form.category ? 'Send change' : 'Send category'}</Send>
    <Outcome call={call}>{call.data?.external_id && <><p>Category <b className="font-mono">{call.data.external_id}</b> was {call.status === 201 ? 'created' : 'updated'} in SFA.</p><Note>It is in the Order form's Category list here and in {go('/subscriptions?tab=commission-rules', 'Commission rules')}. {NO_WEBHOOK}</Note></>}</Outcome>
    {call.ok && !form.category && <CategoryNow category={categories.find((row) => row.external_id === call.data?.external_id)} categories={categories} />}
  </Feature>
}

// A storefront help center sends web forms, app messages and chats; SFA also takes email and phone from other channels.
const storefrontChannels = ticketChannels.filter((channel) => !['email', 'phone'].includes(channel))
export function TicketSend() {
  const store = useStorefront()
  const call = useSend('storefront')
  const links = { order_external_id: '', payment_reference: '', shipment_reference: '' }
  const blank = { requester_type: 'customer', requester_external_id: '', subject: '', description: '', category: 'order', channel: 'web_form', priority: 'normal', ...links }
  const { form, setForm, bind } = useForm(blank)
  // Changing who asks clears every link, and changing the order clears its payment and shipment, so a choice made for
  // another requester or order never comes back.
  const pick = (key, cleared) => (event) => setForm({ ...form, ...cleared, [key]: event.target.value })
  const customer = form.requester_type === 'customer'
  const requester = ((customer ? store.customers : store.sellers) || []).find((row) => row.external_id === form.requester_external_id)
  // SFA links only the requester's own order (for a seller, one with its items), and only a payment or shipment of that
  // order (for a seller, only its own shipment).
  const orders = requester ? (store.orders || []).filter((row) => customer ? row.customer?.external_id === requester.external_id : row.items?.some((item) => item.seller_external_id === requester.external_id)) : []
  const order = orders.find((row) => row.external_id === form.order_external_id)
  const payment = order?.payments?.find((row) => row.reference === form.payment_reference)
  const shipments = order ? (store.shipments || []).filter((row) => row.order_external_id === order.external_id && (customer || row.seller_external_id === requester.external_id)) : []
  const shipment = shipments.find((row) => row.reference === form.shipment_reference)
  function submit(event) {
    event.preventDefault()
    const body = compact({ ...form, requester_external_id: requester?.external_id, order_external_id: order?.external_id, payment_reference: payment?.reference, shipment_reference: shipment?.reference })
    call.send({ method: 'POST', path: '/integrations/tickets', body, idempotent: true }, { fields: { requester_id: 'requester_external_id', order_id: 'order_external_id', payment_id: 'payment_reference', shipment_id: 'shipment_reference' }, onSuccess: () => setForm({ ...blank, requester_type: form.requester_type, requester_external_id: form.requester_external_id }) })
  }
  return <Feature title="Help-center ticket" endpoint="POST /integrations/tickets" description="A customer or seller asks for help on the storefront, and SFA's support team answers it. Order lists only the requester's own orders (a seller's: orders with its items), and Payment and Shipment only that order's (a seller's: only its own parcels), the links SFA accepts. With none chosen, SFA links the order's only payment to a payment or refund ticket, and its only shipment to a delivery or refund ticket." onSubmit={submit}>
    <div className="grid gap-3 sm:grid-cols-2">
      <Select label="Who is asking" call={call} name="requester_type" options={[['customer', 'Customer'], ['seller', 'Seller']]} value={form.requester_type} onChange={pick('requester_type', { ...links, requester_external_id: '' })} />
      <Select label={customer ? 'Customer' : 'Seller'} call={call} name="requester_external_id" required placeholder="Choose one" options={customer ? options.customers(store.customers) : options.sellers(store.sellers)} onChange={pick('requester_external_id', links)} value={requester ? form.requester_external_id : ''} />
      <Select label="Category" call={call} name="category" options={asPairs(ticketCategories)} {...bind('category')} />
      <Select label="Channel" call={call} name="channel" options={asPairs(storefrontChannels)} {...bind('channel')} />
      <Select label="Priority" call={call} name="priority" options={asPairs(ticketPriorities)} {...bind('priority')} />
      {/* Dependent pickers stay disabled until their parent is chosen; the help line says what unlocks them. */}
      <Select label="Order (optional)" call={call} name="order_external_id" disabled={!orders.length} placeholder={!requester ? 'Choose who is asking first' : orders.length ? 'None' : 'This requester has no order'} help={!requester ? `Pick the ${customer ? 'customer' : 'seller'} above first; their orders then appear here.` : orders.length ? undefined : `No order for this ${customer ? 'customer' : 'seller'} yet. Send one in Shopping & Checkout, or leave this empty.`} options={options.orders(orders)} onChange={pick('order_external_id', { payment_reference: '', shipment_reference: '' })} value={order ? form.order_external_id : ''} />
      <Select label="Payment (optional)" call={call} name="payment_reference" disabled={!order?.payments?.length} placeholder={!order ? 'Choose an order first' : order.payments?.length ? 'None' : 'This order has no payment'} help={!order ? 'Pick an order first; its payments then appear here.' : order.payments?.length ? undefined : 'This order has no payment yet. Send one in Shopping & Checkout, or leave this empty.'} options={(order?.payments || []).map((row) => [row.reference, `${row.reference} · ${label(row.method)} · ${label(row.status)}`])} {...bind('payment_reference')} value={payment ? form.payment_reference : ''} />
      <Select label="Shipment (optional)" call={call} name="shipment_reference" disabled={!shipments.length} placeholder={!order ? 'Choose an order first' : shipments.length ? 'None' : 'This order has no shipment'} help={!order ? 'Pick an order first; its shipments then appear here.' : shipments.length ? undefined : `No shipment${customer ? '' : ' of this seller'} for this order yet. Logistics & Locations books it once the order is paid; or leave this empty.`} options={options.shipments(shipments)} {...bind('shipment_reference')} value={shipment ? form.shipment_reference : ''} />
    </div>
    <Input label="Subject" call={call} name="subject" required maxLength={255} {...bind('subject')} />
    <TextArea label="Message" call={call} name="description" required maxLength={20000} {...bind('description')} />
    <Send call={call} icon="fa-life-ring">Send ticket</Send>
    <Outcome call={call}>{call.data?.reference && <><p className="flex flex-wrap items-center gap-2">Ticket <b className="font-mono">{call.data.reference}</b> <StatusPill status={call.data.status}>{label(call.data.status)}</StatusPill></p><Note>SFA sent ticket.created back (Receives from SFA). Below is the ticket as Customer Support sees it.</Note></>}</Outcome>
    {call.ok && <TicketNow id={call.data?.id} />}
  </Feature>
}

export function TicketReplySend() {
  const store = useStorefront()
  const call = useSend('storefront')
  const { form, setForm, bind } = useForm({ ticket: '', body: '' })
  const tickets = rowsWith(store.tickets, 'reply')
  const selected = tickets.find((row) => row.reference === form.ticket)
  // The panel keeps showing the chosen ticket even after SFA closes it and it leaves the list.
  const current = (store.tickets || []).find((row) => row.reference === form.ticket)
  // The replied ticket and its status before the reply, so the answer says what the reply changed.
  const [before, setBefore] = useState(null)
  const replied = (store.tickets || []).find((row) => row.id === call.data?.ticket_id)
  function submit(event) {
    event.preventDefault()
    if (!selected) return call.fail({}, 'Choose a ticket that SFA still takes replies for.')
    setBefore({ id: selected.id, status: selected.status })
    call.send({ method: 'POST', path: `/integrations/tickets/${encodeURIComponent(selected.reference)}/messages`, body: { body: form.body } }, { onSuccess: () => setForm({ ...form, body: '' }) })
  }
  return <Feature title="Requester reply" endpoint="POST /integrations/tickets/{reference}/messages" description="The customer or seller answers in the storefront's help center. A reply to a ticket Waiting on customer moves it back to In progress (SFA sends ticket.status_changed, then ticket.replied); any other open ticket keeps its status. A resolved ticket stays resolved, and the reply restarts its 7-day automatic close; only staff can reopen it. Closed tickets take no reply, so they are not listed." onSubmit={submit}>
    <Select label="Ticket" call={call} name="ticket" required placeholder={tickets.length ? 'Choose a ticket' : 'No ticket takes a reply now'} options={options.tickets(tickets)} onChange={(event) => { call.clear(); setForm({ ...form, ticket: event.target.value }) }} value={selected ? form.ticket : ''} />
    {!tickets.length && <Needs>Every storefront ticket is closed, and SFA takes no reply on a closed ticket. Send a new Help-center ticket above.</Needs>}
    <TextArea label="Reply" call={call} name="body" required maxLength={20000} {...bind('body')} />
    <TicketNow id={current?.id} />
    <Send call={call} icon="fa-reply" disabled={!tickets.length}>Send reply</Send><Outcome call={call}><p>Reply saved on ticket <b className="font-mono">{replied?.reference}</b>. {replied && before?.id === replied.id && before.status !== replied.status ? `SFA moved it from ${label(before.status)} to ${label(replied.status)} and sent ticket.status_changed, then ticket.replied.` : 'SFA sent ticket.replied back; a reply changes the status only of a ticket Waiting on customer (In SFA now, above).'}</p></Outcome>
  </Feature>
}

export function CsatSend() {
  const store = useStorefront()
  const call = useSend('storefront')
  const { form, setForm, bind } = useForm({ ticket: '', score: '5', comment: '' })
  const tickets = rowsWith(store.tickets, 'csat')
  const selected = tickets.find((row) => row.reference === form.ticket)
  const current = (store.tickets || []).find((row) => row.reference === form.ticket)
  // A new choice starts from that ticket's rating as SFA holds it, so a score or comment typed for another ticket is never
  // sent, and re-rating keeps the comment unless it is changed.
  function choose(event) { call.clear(); const row = tickets.find((item) => item.reference === event.target.value); setForm({ ticket: event.target.value, score: String(row?.csat_score || 5), comment: row?.csat_comment || '' }) }
  function submit(event) {
    event.preventDefault()
    if (!selected) return call.fail({}, 'Choose a resolved or closed ticket.')
    call.send({ method: 'POST', path: `/integrations/tickets/${encodeURIComponent(selected.reference)}/csat`, body: compact({ score: Number(form.score), comment: form.comment }) })
  }
  return <Feature title="Satisfaction rating" endpoint="POST /integrations/tickets/{reference}/csat" description="Once a ticket is resolved or closed, the requester rates the help; only those tickets are listed. Choosing a rated ticket fills in its score and comment as SFA holds them. Rating again replaces both (a blank comment removes the old one). A score of 2 or lower alerts the Admins and the assigned agent, once per low rating. SFA sends no webhook for a rating." onSubmit={submit}>
    <div className="grid gap-3 sm:grid-cols-2"><Select label="Ticket" call={call} name="ticket" required placeholder={tickets.length ? 'Choose a ticket' : 'No resolved ticket yet'} options={tickets.map((row) => [row.reference, `${row.reference} · ${row.subject} (${label(row.status)}${row.csat_score ? `, rated ${row.csat_score} of 5` : ''})`])} value={selected ? form.ticket : ''} onChange={choose} /><Select label="Score" call={call} name="score" options={[5, 4, 3, 2, 1].map((score) => [String(score), `${score} of 5`])} {...bind('score')} /></div>
    {!tickets.length && <Needs>No storefront ticket is resolved or closed yet. In {go('/support?tab=tickets', 'Customer Support')}, resolve one first: reply with After sending: Resolved, or change its status.</Needs>}
    <Input label="Comment (optional)" call={call} name="comment" maxLength={2000} {...bind('comment')} />
    <TicketNow id={current?.id} />
    <Send call={call} icon="fa-star" disabled={!tickets.length}>Send rating</Send><Outcome call={call}><p>Rating {call.data?.csat_score} of 5 saved on ticket <b className="font-mono">{call.data?.reference}</b>.{call.data?.csat_comment ? ` Comment saved: "${call.data.csat_comment}"` : ' No comment saved.'}{call.data?.csat_score <= 2 && ' A score this low alerts the Admins and the assigned agent (the bell in the top bar), unless the ticket was already rated 2 or lower.'} {NO_WEBHOOK}</p></Outcome>
  </Feature>
}

const blankItem = { seller: '', category: '', product: '', quantity: '1', price: '' }
export function OrderSend() {
  const store = useStorefront()
  const call = useSend('storefront')
  const limit = useSettings()?.risk_amount_threshold
  const { form, setForm, bind } = useForm({ customer: '', shipping: '50.00', discount: '0.00', items: [blankItem] })
  const preview = orderBody(form)
  const total = Object.keys(preview.errors).length ? null : preview.body.total_amount
  const ready = Boolean(store.customers?.length && store.sellers?.length)
  const setItem = (index, key) => (event) => setForm((current) => ({ ...current, items: current.items.map((item, position) => position === index ? { ...item, [key]: event.target.value } : item) }))
  // What SFA will do with a payment for this total (PaymentService and the sandbox gateway).
  const hints = total === null ? [] : [
    total <= 0 && 'SFA saves a ₱0.00 order but refuses every payment for it, so it cannot be sent from here.',
    [98, 99].includes(total % 100) && `Ends in .${total % 100}: a card, e-wallet or bank payment ${total % 100 === 99 ? 'is declined by the sandbox gateway' : 'waits for a gateway event'}. Cash on delivery is not affected.`,
    limit != null && total > Number(limit) && `Over ${money(Number(limit))}, the risk threshold in Settings: its payment waits for a Finance risk review instead of being captured.`,
  ].filter(Boolean)
  function submit(event) {
    event.preventDefault()
    const { errors, body } = orderBody(form)
    if (Object.keys(errors).length) return call.fail(errors)
    if (body.total_amount <= 0) return call.fail({}, 'The order total must be above ₱0.00: SFA refuses every payment for a ₱0.00 order.')
    call.send({ method: 'PUT', path: `/integrations/orders/${newOrderId()}`, body }, { onSuccess: () => setForm((current) => ({ ...current, items: [blankItem] })) })
  }
  const saved = call.data
  return <Feature title="Order" endpoint="PUT /integrations/orders/{id}" description="The customer checks out. Prices are typed in pesos and sent as exact centavos; SFA re-checks every line, the subtotal and the total." onSubmit={submit}>
    {!ready && <Needs>An order needs a storefront customer and seller. Send them on {go(tab('accounts'), 'User & Account Management')} first.</Needs>}
    <Select label="Customer" call={call} name="customer_external_id" required placeholder="Choose a customer" options={options.customers(store.customers)} {...bind('customer')} />
    {form.items.map((item, index) => <div key={index} className="grid gap-3 rounded-lg border border-slate-200 p-3 sm:grid-cols-2">
      <Select label="Seller" call={call} name={`items.${index}.seller_external_id`} required placeholder="Choose a seller" options={options.sellers(store.sellers)} value={item.seller} onChange={setItem(index, 'seller')} />
      <Select label="Category (optional)" call={call} name={`items.${index}.category_external_id`} placeholder="None" options={options.categories(store.categories)} value={item.category} onChange={setItem(index, 'category')} />
      <Input label="Product" call={call} name={`items.${index}.product_name`} required maxLength={255} value={item.product} onChange={setItem(index, 'product')} />
      <div className="grid grid-cols-2 gap-3"><Input label="Quantity" call={call} name={`items.${index}.quantity`} required inputMode="numeric" value={item.quantity} onChange={setItem(index, 'quantity')} /><Input label="Unit price (₱)" call={call} name={`items.${index}.unit_price_amount`} required inputMode="decimal" placeholder="0.00" value={item.price} onChange={setItem(index, 'price')} /></div>
      {form.items.length > 1 && <div className="sm:col-span-2"><Button variant="ghost" icon="fa-trash" onClick={() => setForm({ ...form, items: form.items.filter((_, position) => position !== index) })}>Remove item</Button></div>}
    </div>)}
    <Button variant="secondary" icon="fa-plus" disabled={form.items.length >= 20} onClick={() => setForm({ ...form, items: [...form.items, blankItem] })}>{form.items.length >= 20 ? 'Up to 20 items per order here' : 'Add another item'}</Button>
    <div className="grid gap-3 sm:grid-cols-3"><Input label="Shipping fee (₱)" call={call} name="shipping_amount" required inputMode="decimal" {...bind('shipping')} /><Input label="Discount (₱)" call={call} name="discount_amount" required inputMode="decimal" {...bind('discount')} /><div className="rounded-lg bg-slate-50 p-3"><p className="text-xs font-semibold uppercase tracking-wider text-slate-500">Order total</p><p className="mt-1 text-xl font-bold tabular-nums text-slate-900">{total === null ? '—' : money(total)}</p></div></div>
    {hints.length > 0 && <ul className="list-disc space-y-1 pl-4 text-xs text-amber-700">{hints.map((hint) => <li key={hint}>{hint}</li>)}</ul>}
    <Send call={call} icon="fa-cart-shopping" disabled={!ready}>Send order</Send>
    <Outcome call={call}>{saved?.external_id && <><p>Order <b className="font-mono">{saved.external_id}</b> for {money(saved.total_amount)} is in SFA as {label(saved.status_snapshot)}: {saved.customer?.name}, {saved.items?.length} line(s) from {[...new Set((saved.items || []).map((item) => item.seller?.shop_name))].join(', ')}.</p><Note>The Payment form below has it selected. SFA sends no webhook for orders; Logistics & Locations lists it under Awaiting booking once it is paid or has a cash-on-delivery payment.</Note></>}</Outcome>
  </Feature>
}

export function QuoteSend() {
  const call = useSend('storefront')
  // SFA lists the cities it can price (GET /simulator quote_cities). Only when it has none does the form fall back to every
  // city, so a quote can still show SFA answering with no rate.
  const quotable = useSimulator().pickers?.quote_cities || []
  const all = useQuery({ queryKey: ['locations', 'all-cities'], queryFn: () => fetchChoices('/locations/cities'), enabled: !quotable.length })
  const cities = quotable.length ? quotable : all.data
  const cityChoices = (cities || []).map((city) => [city.code, `${city.name} · ${city.code}`])
  const { form, bind } = useForm(QUOTE_DEFAULTS)
  // The route and parcel the answer on screen is for, so changing the form never makes the last answer look current.
  const [asked, setAsked] = useState('')
  const cityName = (code) => cities?.find((row) => row.code === code)?.name || code
  // A default city that SFA cannot price is not preselected.
  const listed = (code) => cityChoices.some(([value]) => value === code) ? code : ''
  function submit(event) {
    event.preventDefault()
    let cod
    try { cod = pesosToCentavos(form.cod) } catch (error) { return call.fail({ cod_amount: [error.message] }) }
    setAsked(`${cityName(listed(form.origin_code))} to ${cityName(listed(form.destination_code))}, ${form.weight_grams} g, ${form.length_cm} × ${form.width_cm} × ${form.height_cm} cm${cod ? `, COD ${money(cod)}` : ''}`)
    call.send({ method: 'POST', path: '/integrations/quotes', body: { origin_code: listed(form.origin_code), destination_code: listed(form.destination_code), weight_grams: Number(form.weight_grams), dimensions: { length_cm: form.length_cm, width_cm: form.width_cm, height_cm: form.height_cm }, cod_amount: cod } })
  }
  const quotes = call.data?.data || []
  const city = (key, title) => <Select label={title} call={call} name={key} required disabled={!cities} placeholder={all.isFetching && !cities ? 'Loading cities...' : 'Choose a city'} options={cityChoices} {...bind(key)} value={listed(form[key])} />
  return <Feature title="Shipping quote" endpoint="POST /integrations/quotes" description="Checkout asks SFA what delivery costs; the city is sent as its PSGC code. The lists offer only the cities SFA can price: those in a delivery zone of an active rate whose courier is active and still offers that service. Two listed cities still answer with no rate when no rate runs between their zones." onSubmit={submit}>
    {!quotable.length && <Needs>SFA can price no city yet: no active rate with an active courier and active zones. Every city is listed, and each quote answers with no rate until {go('/logistics?tab=zones', 'Logistics & Locations')} has one.</Needs>}
    {all.isError && <div role="alert" className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">Could not load the city list. <button type="button" className="font-semibold underline" onClick={() => all.refetch()}>Try again</button></div>}
    <div className="grid gap-3 sm:grid-cols-2">{city('origin_code', 'From city')}{city('destination_code', 'To city')}</div>
    <div className="grid grid-cols-2 gap-3 sm:grid-cols-5"><Input label="Weight (g)" call={call} name="weight_grams" required inputMode="numeric" {...bind('weight_grams')} /><Input label="Length (cm)" call={call} name="dimensions.length_cm" required inputMode="decimal" {...bind('length_cm')} /><Input label="Width (cm)" call={call} name="dimensions.width_cm" required inputMode="decimal" {...bind('width_cm')} /><Input label="Height (cm)" call={call} name="dimensions.height_cm" required inputMode="decimal" {...bind('height_cm')} /><Input label="COD (₱)" call={call} name="cod_amount" required inputMode="decimal" {...bind('cod')} /></div>
    <Send call={call} icon="fa-truck-fast" disabled={!cities}>Ask for quotes</Send>
    <Outcome call={call}><p className="text-xs font-semibold">For {asked}:</p>{quotes.length ? <><ul className="divide-y divide-emerald-200">{quotes.map((quote) => <li key={quote.rate_id} className="py-1.5"><p className="flex flex-wrap items-center justify-between gap-2"><span>{quote.courier_name} · {label(quote.service_type)}{quote.recommended && <span className="ml-2 rounded-full bg-white px-2 py-0.5 text-[11px] font-semibold text-emerald-700">Cheapest</span>}</span><span className="font-semibold tabular-nums">{money(quote.total_amount)} · {quote.eta_from} to {quote.eta_to}</span></p><p className="text-xs">Charged for {quote.chargeable_weight_grams} g (volumetric {quote.volumetric_weight_grams} g){quote.cod_fee_amount ? ` · COD fee ${money(quote.cod_fee_amount)}` : ''}</p></li>)}</ul><Note>Staff get the same price from Get shipping quote in {go('/logistics', 'Logistics & Locations')}.</Note></> : <p>No active rate covers this route and parcel. Zones and rates are in {go('/logistics?tab=zones', 'Logistics & Locations')}.</p>}</Outcome>
  </Feature>
}

export function PaymentSend() {
  const store = useStorefront()
  const { traffic } = useSimulator()
  const call = useSend('storefront')
  const settings = useSettings()
  const { form, setForm, bind } = useForm({ order: '', method: 'card' })
  const orders = rowsWith(store.orders, 'payment')
  // Until one is chosen, the order this page created last is preselected while SFA still takes a payment for it.
  const created = traffic.find((entry) => entry.method === 'PUT' && entry.path.startsWith('/integrations/orders/') && entry.status === 201)?.response?.external_id
  const order = orders.find((row) => row.external_id === (form.order || created))
  const submit = (event) => { event.preventDefault(); call.send({ method: 'POST', path: '/integrations/payments', body: { order_external_id: order?.external_id, method: form.method }, idempotent: true }) }
  const payment = call.data?.payment
  // The sandbox gateway's test endings (as in the Order form's hints); cash on delivery never reaches the gateway.
  const ending = order && form.method !== 'cod' && [98, 99].includes(order.total_amount % 100) ? order.total_amount % 100 : null
  const limit = settings?.risk_amount_threshold != null ? money(Number(settings.risk_amount_threshold)) : 'the risk threshold in Settings'
  const next = !payment ? null : payment.risk_level && payment.risk_level !== 'none' ? <>It waits for Finance to clear or block it in {go('/payments?tab=risk-reviews', 'Payments & Transactions, Risk review')}.</>
    : payment.method === 'cod' ? <>Next, Logistics & Locations books the parcel ({go('/logistics?tab=awaiting-booking', 'Awaiting booking')}); SFA captures the cash when the delivery is recorded with the cash collected.</>
    : payment.status === 'pending' ? <>Send its result from {go(tab('events', 'gateway'), 'Payment gateway, Payment events')} within 30 minutes, or SFA expires it.</>
    : payment.status === 'captured' ? <>SFA sent payment.authorized and payment.captured back. Logistics & Locations can now book the parcel ({go('/logistics?tab=awaiting-booking', 'Awaiting booking')}).</>
    : payment.status === 'failed' ? <>SFA sent payment.failed back. The order is listed again for another attempt.</> : null
  return <Feature title="Payment" endpoint="POST /integrations/payments" description={`The customer pays. Only orders SFA still takes a payment for are listed: not cancelled, above ₱0.00, nothing captured and no payment still open. Card, e-wallet and bank go to the sandbox gateway: a total ending in .99 is declined, .98 waits up to 30 minutes for a gateway event, and anything else is captured at once, unless the total is over ${limit} or the customer failed more than ${settings?.risk_failed_attempts ?? 3} payments in the last hour; then it waits for a Finance risk review. Cash on delivery stays pending until the delivery is recorded with the cash collected.`} onSubmit={submit}>
    <div className="grid gap-3 sm:grid-cols-2"><Select label="Order" call={call} name="order_external_id" required placeholder={orders.length ? 'Choose an order' : 'No order takes a payment now'} options={orders.map((row) => [row.external_id, `${row.external_id} · ${money(row.total_amount)} · ${row.payments?.length ? `${row.payments.length} earlier attempt(s)` : 'unpaid'}`])} onChange={(event) => { call.clear(); setForm({ ...form, order: event.target.value }) }} value={order?.external_id || ''} /><Select label="Method" call={call} name="method" options={asPairs(PAYMENT_METHODS)} {...bind('method')} /></div>
    {!orders.length && <Needs>No order can take a payment now: cancelled orders, ₱0.00 orders, paid orders and orders whose payment is still open are left out. Send a new order with the Order form above.</Needs>}
    {ending && <p className="text-xs text-amber-700">This total ends in .{ending}: the sandbox gateway {ending === 99 ? 'declines this payment (Failed, payment.failed)' : 'leaves it Pending until a gateway event arrives, for up to 30 minutes'}.</p>}
    <OrderNow order={order} />
    <Send call={call} icon="fa-credit-card" disabled={!orders.length}>Send payment</Send>
    <Outcome call={call}>{payment && <><p className="flex flex-wrap items-center gap-2">Payment <b className="font-mono">{payment.reference}</b> <StatusPill status={payment.status}>{label(payment.status)}</StatusPill>{payment.risk_level && payment.risk_level !== 'none' && <StatusPill status={payment.risk_level}>Risk review</StatusPill>}</p>{call.data.next_action?.message && <p>{call.data.next_action.message}</p>}{payment.failure_reason && <p>{payment.failure_reason}</p>}{next && <Note>{next}</Note>}</>}</Outcome>
    {call.ok && <PaymentNow id={payment?.id} />}
  </Feature>
}

// What cancelling an order closes, read from the lists just before the send (MirrorController::closeOrder): its pending
// or authorized payments and its parcels not yet with the courier. A 200 means SFA cancelled every one of them.
const closing = (store, order) => ({
  payments: (order.payments || []).filter((row) => ['pending', 'authorized'].includes(row.status)).map((row) => row.reference),
  parcels: (store.shipments || []).filter((row) => row.order_external_id === order.external_id && ['pending', 'ready_for_pickup'].includes(row.status)).map((row) => row.reference),
})
// What else the cancellation changed in SFA and the webhooks it sent, by the cancel call or a status update to cancelled.
function CancelEffects({ closed }) {
  const { payments, parcels } = closed
  if (!payments.length && !parcels.length) return <Note>It had no open payment and no parcel waiting for the courier, so nothing else changed and SFA sent no webhook.</Note>
  const sent = [payments.length > 0 && `payment.cancelled for ${payments.join(', ')}`, parcels.length > 0 && `shipment.status_changed for ${parcels.join(', ')}`].filter(Boolean)
  return <Note>SFA also cancelled {[...payments, ...parcels].join(', ')} and sent {sent.join(' and ')} (under Receives from SFA on {go(tab('returns'), 'Returns, Cancellation & Refund')}{parcels.length > 0 && <>; the parcels are in {go('/logistics?tab=shipments', 'Logistics & Locations')}</>}).</Note>
}
export function OrderStatusSend() {
  const store = useStorefront()
  const call = useSend('storefront')
  const { form, setForm, bind } = useForm({ order: '', status: '' })
  const [closed, setClosed] = useState(null)
  const orders = rowsWith(store.orders, 'status_update')
  const order = orders.find((row) => row.external_id === form.order)
  // Keeps showing the order after it is cancelled here, when it has left the list.
  const current = (store.orders || []).find((row) => row.external_id === form.order)
  // SFA lists the statuses it takes for each order now: never its current one, and cancelled only while it can cancel it.
  const statuses = order?.statuses || []
  const status = statuses.includes(form.status) ? form.status : ''
  function submit(event) {
    event.preventDefault()
    if (!order) return call.fail({}, 'This order is no longer open. Choose another one.')
    if (!status) return call.fail({ status_snapshot: ['Choose the new status.'] })
    setClosed(status === 'cancelled' ? closing(store, order) : null)
    const body = { customer_external_id: order.customer.external_id, status_snapshot: status, ...Object.fromEntries(['subtotal_amount', 'shipping_amount', 'discount_amount', 'total_amount', 'placed_at'].map((key) => [key, order[key]])), currency: 'PHP', items: order.items }
    call.send({ method: 'PUT', path: `/integrations/orders/${encodeURIComponent(order.external_id)}`, body }, { onSuccess: () => setForm((now) => ({ ...now, status: '' })) })
  }
  const saved = call.data
  return <Feature title="Order status update" endpoint="PUT /integrations/orders/{id}" description="The storefront resends the same order, lines and amounts unchanged, with its own new status. SFA stores the latest status; nothing else reacts to it (shipments move when Logistics books them and the courier reports), except Cancelled. New status lists only what SFA takes for the chosen order: Cancelled appears only while nothing is captured and no parcel is with the courier, and it works exactly like Order cancellation on Returns, Cancellation & Refund. Cancelled orders are not listed." onSubmit={submit}>
    <div className="grid gap-3 sm:grid-cols-2">
      <Select label="Order" call={call} name="order" required placeholder={orders.length ? 'Choose an order' : 'No open order yet'} options={options.orders(orders)} value={order ? form.order : ''} onChange={(event) => { call.clear(); setForm({ order: event.target.value, status: '' }) }} />
      <Select label="New status" call={call} name="status_snapshot" required disabled={!order} placeholder={order ? 'Choose a status' : 'Choose an order first'} help={order ? undefined : 'Pick an order first; the statuses SFA takes for it then appear here.'} options={statuses.map((value) => [value, label(value)])} {...bind('status')} value={status} />
    </div>
    {status === 'cancelled' && <p className="text-xs text-amber-700">Cancelled closes the order for good: SFA cancels its open payments and the parcels not yet with the courier, and then takes no payment or status for it.</p>}
    {!orders.length && <Needs>No open storefront order. Send one on {go(tab('checkout'), 'Shopping & Checkout')}.</Needs>}
    <OrderNow order={current} />
    <Send call={call} icon="fa-arrows-rotate" disabled={!orders.length}>Send status</Send>
    <Outcome call={call}>{saved?.external_id && (saved.status_snapshot === 'cancelled' ? <><p>Order <b className="font-mono">{saved.external_id}</b> is Cancelled in SFA.</p>{closed && <CancelEffects closed={closed} />}</> :<p>Order <b className="font-mono">{saved.external_id}</b> is now {label(saved.status_snapshot)} in SFA. {NO_WEBHOOK}</p>)}</Outcome>
  </Feature>
}

export function CancelOrderSend() {
  const store = useStorefront()
  const call = useSend('storefront')
  const { form, setForm } = useForm({ order: '' })
  const orders = rowsWith(store.orders, 'cancel')
  const order = orders.find((row) => row.external_id === form.order)
  // Keeps showing the order after the cancel, when it has left the list.
  const current = (store.orders || []).find((row) => row.external_id === form.order)
  const [closed, setClosed] = useState(null)
  const submit = (event) => { event.preventDefault(); if (!order) return call.fail({}, 'SFA no longer takes a cancellation for this order.'); setClosed(closing(store, order)); call.send({ method: 'POST', path: `/integrations/orders/${encodeURIComponent(order.external_id)}/cancel` }) }
  return <Feature title="Order cancellation" endpoint="POST /integrations/orders/{id}/cancel" description="The customer cancels an order before any money is captured. SFA cancels its pending or authorized payments (also those held for a risk review) and its parcels not yet picked up by the courier. Only such orders are listed: once money is captured, even if refunded later, SFA asks for a refund instead, and a parcel already with the courier blocks the cancel." onSubmit={submit}>
    <Select label="Order" call={call} name="order" required placeholder={orders.length ? 'Choose an order' : 'No order can be cancelled now'} options={options.orders(orders)} onChange={(event) => { call.clear(); setForm({ order: event.target.value }) }} value={order ? form.order : ''} />
    {!orders.length && <Needs>No storefront order can be cancelled now: orders with captured money need a refund, and an order whose parcel is with the courier cannot be cancelled. Send a new order on {go(tab('checkout'), 'Shopping & Checkout')}.</Needs>}
    <OrderNow order={current} />
    <Send call={call} icon="fa-ban" disabled={!orders.length}>Send cancellation</Send>
    <Outcome call={call}>{call.data?.external_id && <><p>Order <b className="font-mono">{call.data.external_id}</b> is {label(call.data.status_snapshot)} in SFA.</p>{closed && <CancelEffects closed={closed} />}</>}</Outcome>
  </Feature>
}

export function RefundSend() {
  const store = useStorefront()
  const call = useSend('storefront')
  const payments = rowsWith(store.payments, 'refund')
  const { form, setForm, bind } = useForm({ payment: '', amount: '', reason: 'item_not_received', notes: '', ticket: '' })
  const selected = payments.find((row) => row.reference === form.payment)
  const current = (store.payments || []).find((row) => row.reference === form.payment)
  // SFA links only a ticket of this storefront that is linked to this very payment (RefundController::createIntegration).
  const tickets = selected ? (store.tickets || []).filter((row) => row.payment_reference === selected.reference) : []
  const ticket = tickets.find((row) => row.reference === form.ticket)
  function choose(event) { call.clear(); const row = payments.find((item) => item.reference === event.target.value); setForm({ ...form, payment: event.target.value, ticket: '', amount: row ? centavosToPesos(row.refundable_amount) : '' }) }
  let typed = null
  try { typed = parsePesos(form.amount) } catch { typed = null }
  function submit(event) {
    event.preventDefault()
    if (!selected) return call.fail({}, 'Choose a payment that still has money to refund.')
    let amount
    try { amount = parsePesos(form.amount) } catch (error) { return call.fail({ amount: [error.message] }) }
    call.send({ method: 'POST', path: '/integrations/refunds', body: compact({ payment_reference: selected.reference, amount, reason: form.reason, notes: form.notes, ticket_id: ticket?.id }), idempotent: true }, { fields: { ticket_id: 'ticket' }, onSuccess: () => setForm((now) => ({ ...now, amount: '', notes: '', ticket: '' })) })
  }
  return <Feature title="Refund request" endpoint="POST /integrations/refunds" description="The customer asks for money back. Only payments with money left to refund are listed, and refunds already waiting for Finance hold part of it; the amount starts at everything left. Ticket lists only the help-center tickets linked to that payment, the only ones SFA accepts. The refund waits in SFA until Finance approves it." onSubmit={submit}>
    <div className="grid gap-3 sm:grid-cols-2"><Select label="Captured payment" call={call} name="payment_reference" required placeholder={payments.length ? 'Choose a payment' : 'No payment to refund'} options={payments.map((row) => [row.reference, `${row.reference} · ${money(row.refundable_amount)} refundable`])} value={selected ? form.payment : ''} onChange={choose} /><Input label="Amount (₱)" call={call} name="amount" required inputMode="decimal" help={selected ? `Up to ${money(selected.refundable_amount)}.` : undefined} {...bind('amount')} /><Select label="Reason" call={call} name="reason" options={asPairs(refundReasons)} {...bind('reason')} /><Input label="Notes (optional)" call={call} name="notes" maxLength={10000} {...bind('notes')} />
      <Select label="Ticket (optional)" call={call} name="ticket" disabled={!tickets.length} placeholder={!selected ? 'Choose a payment first' : tickets.length ? 'None' : 'No ticket names this payment'} help={!selected ? 'Pick a payment first; the tickets linked to it then appear here.' : !tickets.length ? <>Send a Help-center ticket with this payment on {go(tab('accounts'), 'User & Account Management')}, or leave this empty.</> : undefined} options={options.tickets(tickets)} {...bind('ticket')} value={ticket ? form.ticket : ''} /></div>
    {selected && selected.method !== 'cod' && typed % 100 === 99 && <p className="text-xs text-amber-700">Ends in .99: the sandbox gateway fails this refund when Finance approves it (refund.failed).</p>}
    {!payments.length && <Needs>No storefront payment has money left to refund. Capture one first with the Payment form on {go(tab('checkout'), 'Shopping & Checkout')}, or wait for Finance to decide the refunds already requested ({go('/payments?tab=refunds', 'Payments & Transactions, Refunds')}).</Needs>}
    <PaymentNow id={current?.id} />
    <Send call={call} icon="fa-rotate-left" disabled={!payments.length}>Send refund request</Send>
    <Outcome call={call}>{call.data?.reference && <><p className="flex flex-wrap items-center gap-2">Refund <b className="font-mono">{call.data.reference}</b> for {money(call.data.total_amount)} <StatusPill status={call.data.status}>{label(call.data.status)}</StatusPill></p><Note>SFA sent refund.requested back. Finance approves or rejects it in {go(`/payments?tab=refunds&refund=${call.data.id}`, 'Payments & Transactions, Refunds')}; the decision comes back as refund.approved and refund.completed (or refund.failed), or refund.rejected.</Note></>}</Outcome>
  </Feature>
}

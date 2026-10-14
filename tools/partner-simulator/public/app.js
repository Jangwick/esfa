// Archon Market partner storefront UI. Every form calls this simulator's own API, which then talks to SFA.
const $ = (selector, root = document) => root.querySelector(selector);
const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];

const STEPS = ['setup', 'shop', 'support', 'gateway', 'courier'];
const CATALOG = [
  ['Wireless earbuds', '1499.00'],
  ['Cotton tote bag', '349.00'],
  ['Arabica coffee beans 500 g', '520.00'],
  ['Phone case', '299.00'],
  ['Desk lamp', '899.00'],
];
const PEOPLE = ['Maria Santos', 'Juan Dela Cruz', 'Ana Reyes', 'Paolo Garcia', 'Liza Mendoza', 'Carlo Bautista'];
const SHOPS = [['Kusina ni Lola', 'Remedios Cruz'], ['Gadget Hub PH', 'Mark Villanueva'], ['Tahanan Home Goods', 'Joy Ramos'], ['Bayani Books', 'Rico Santos']];
const SEEDED_TRACKING = Array.from({ length: 15 }, (_, index) => `DEMO-TRK-${String(index + 1).padStart(4, '0')}`);
const METHODS = { card: 'Card', gcash: 'GCash', maya: 'Maya', bank_transfer: 'Bank transfer', cod: 'Cash on delivery' };
const AUTHORS = { user: 'SFA agent', customer: 'Customer', seller: 'Seller', system: 'System' };
const GOOD = new Set(['placed', 'authorized', 'captured', 'approved', 'completed', 'delivered', 'resolved', 'closed', 'dispute_won']);
const BAD = new Set(['cancelled', 'failed', 'expired', 'rejected', 'disputed', 'dispute_lost', 'failed_attempt', 'returning', 'returned_to_sender']);

let snapshot = { customers: [], sellers: [], orders: [], payments: [], refunds: [], tickets: [], shipments: [], log: [], events: [] };
let lastKey = '';
let loading = false;
let again = false;
let errorCount = 0;
const openRows = new Set();
const fresh = new Set(); // option sources whose selects should jump to the newest record on the next render

// ---------- formatting ----------

const label = (value) => (value ? String(value).replaceAll('_', ' ') : 'unknown');
const pick = (list) => list[Math.floor(Math.random() * list.length)];
const clock = (iso) => new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });

function toCentavos(value) {
  const match = /^(\d{1,13})(?:\.(\d{1,2}))?$/.exec(String(value ?? '').trim().replaceAll(',', ''));
  return match ? Number(match[1]) * 100 + Number((match[2] ?? '').padEnd(2, '0')) : null;
}

function peso(centavos) {
  if (!Number.isSafeInteger(centavos)) return '—';
  const sign = centavos < 0 ? '−' : '';
  const absolute = Math.abs(centavos);
  return `${sign}₱${Math.floor(absolute / 100).toLocaleString('en-PH')}.${String(absolute % 100).padStart(2, '0')}`;
}

function h(tag, props = {}, ...children) {
  const element = document.createElement(tag);
  for (const [key, value] of Object.entries(props)) {
    if (key === 'class') element.className = value;
    else if (key.startsWith('on')) element.addEventListener(key.slice(2), value);
    else if (value !== undefined && value !== null && value !== false) element.setAttribute(key, value === true ? '' : value);
  }
  element.append(...children.flat().filter((child) => child !== null && child !== undefined && child !== false && child !== ''));
  return element;
}

function statusPill(status) {
  const tone = GOOD.has(status) ? 'good' : BAD.has(status) ? 'bad' : 'warn';
  return h('span', { class: `pill pill-${tone}` }, label(status));
}

// Status 0 means no HTTP answer yet; once a response note is stored, the call failed without one.
function httpPill({ status, response }) {
  if (!status) return response ? h('span', { class: 'pill pill-bad' }, 'no response') : h('span', { class: 'pill pill-muted' }, 'sending…');
  const tone = status < 300 ? 'good' : status < 500 ? 'warn' : 'bad';
  return h('span', { class: `pill pill-${tone}` }, String(status));
}

const pre = (value) => h('pre', {}, typeof value === 'string' ? value : JSON.stringify(value, null, 2));

function expandable(key, summary, body) {
  return h('details', { open: openRows.has(key), ontoggle: (event) => (event.target.open ? openRows.add(key) : openRows.delete(key)) },
    h('summary', {}, summary), h('div', { class: 'detail' }, body));
}

// ---------- select and datalist options ----------

const optionsFrom = (rows, value, text) => rows.map((row) => [value(row), text(row)]);
const OPTIONS = {
  customers: () => optionsFrom(snapshot.customers, (c) => c.external_id, (c) => `${c.name} (${c.external_id})`),
  sellers: () => optionsFrom(snapshot.sellers, (s) => s.external_id, (s) => `${s.shop_name} (${s.external_id})`),
  requesters: (select) => OPTIONS[select.form.elements.requester_type.value === 'seller' ? 'sellers' : 'customers'](),
  orders: () => optionsFrom(snapshot.orders, (o) => o.external_id, (o) => `${o.external_id} · ${peso(o.total_amount)}`),
  openOrders: () => OPTIONS.orders().filter(([id]) => snapshot.orders.find((o) => o.external_id === id)?.status !== 'cancelled'),
  payments: () => optionsFrom(snapshot.payments, (p) => p.reference, (p) => `${p.reference} · ${label(p.status)}`),
  capturedPayments: () => OPTIONS.payments().filter(([reference]) => ['captured', 'partially_refunded'].includes(snapshot.payments.find((p) => p.reference === reference)?.status)),
  paymentReferences: () => optionsFrom(snapshot.payments, (p) => p.reference, (p) => `${p.order_external_id ?? ''} ${peso(p.total_amount)} ${label(p.status)}`),
  tickets: () => optionsFrom(snapshot.tickets, (t) => t.reference, (t) => `${t.reference} · ${t.subject ?? ''}`),
  references: () => [...snapshot.payments, ...snapshot.refunds, ...snapshot.shipments].map((row) => [row.reference, label(row.status)]),
  trackingNumbers: () => [...new Set([...snapshot.shipments.map((s) => s.tracking_number).filter(Boolean), ...SEEDED_TRACKING])].map((number) => [number, number]),
};

function fillOptions(root = document) {
  for (const element of $$('[data-options]', root)) {
    const options = OPTIONS[element.dataset.options](element);
    if (element.tagName === 'DATALIST') {
      element.replaceChildren(...options.map(([value, text]) => new Option(text, value)));
      continue;
    }
    const current = fresh.has(element.dataset.options) ? null : element.value;
    const blank = element.hasAttribute('data-optional') ? ['None'] : options.length ? [] : [element.dataset.placeholder];
    element.replaceChildren(...blank.map((text) => new Option(text, '')), ...options.map(([value, text]) => new Option(text, value)));
    if (options.some(([value]) => value === current)) element.value = current;
  }
}

// ---------- lists ----------

function list(container, rows, empty, renderRow) {
  container.replaceChildren(...(rows.length ? rows.map((row) => h('li', {}, renderRow(row))) : [h('li', { class: 'empty' }, empty)]));
}

const head = (title, status) => h('div', { class: 'record-head' }, h('strong', {}, title), status && statusPill(status));
const meta = (...parts) => h('div', { class: 'record-meta' }, parts);

function renderOrders() {
  const names = new Map(snapshot.customers.map((c) => [c.external_id, c.name]));
  list($('#orders-list'), snapshot.orders, 'No orders yet. Add a customer and a seller, then place an order.', (order) => {
    const payment = snapshot.payments.find((p) => p.order_external_id === order.external_id);
    return [
      head(order.external_id, order.status),
      meta(`${names.get(order.customer_external_id) ?? order.customer_external_id} · ${order.items?.length ?? 0} item(s) · ${peso(order.total_amount)}`),
      payment && meta('Payment ', payment.reference, ' ', statusPill(payment.status)),
    ];
  });
}

function renderPayments() {
  for (const container of $$('[data-list="payments"]')) {
    list(container, snapshot.payments, 'No payments yet. Pay for an order in tab 2.', (payment) => [
      head(payment.reference, payment.status),
      meta(`${payment.order_external_id ?? 'order not on this storefront'} · ${METHODS[payment.method] ?? payment.method ?? ''} · ${peso(payment.total_amount)}`),
      payment.refunded_amount > 0 && meta(`Refunded ${peso(payment.refunded_amount)}`),
      payment.risk_level === 'review' && meta('Waiting for a Finance risk review in SFA.'),
      payment.failure_reason && meta(payment.failure_reason),
      payment.next_action && payment.status === 'pending' && meta(payment.next_action),
    ]);
  }
}

function renderTickets() {
  list($('#tickets-list'), snapshot.tickets, 'No tickets yet. Send one with the form above.', (ticket) => {
    const messages = ticket.messages ?? [];
    return [
      head(ticket.reference, ticket.status),
      meta(`${ticket.subject ?? ''} · ${label(ticket.requester_type)} ${ticket.requester_external_id ?? ''}${ticket.csat_score ? ` · rated ${ticket.csat_score}/5` : ''}`),
      expandable(`ticket:${ticket.reference}`, `Conversation (${messages.length} public message${messages.length === 1 ? '' : 's'})`,
        h('ol', { class: 'messages' }, messages.map((message) => h('li', { class: message.author_type === 'user' ? 'from-agent' : 'from-customer' },
          h('div', { class: 'record-meta' }, `${AUTHORS[message.author_type] ?? message.author_type} · ${message.created_at ? clock(message.created_at) : ''}`),
          h('p', {}, message.body))))),
    ];
  });
}

function renderRefunds() {
  list($('#refunds-list'), snapshot.refunds, 'No refunds yet.', (refund) => [
    head(refund.reference, refund.status),
    meta(`${refund.payment_reference ?? ''} · ${peso(refund.amount)} · ${label(refund.reason)}`),
    refund.rejection_reason && meta(`Rejected: ${refund.rejection_reason}`),
  ]);
}

function renderShipments() {
  list($('#shipments-list'), snapshot.shipments, 'No shipments yet. Book one in SFA, or send an update for a seeded tracking number.', (shipment) => [
    head(shipment.tracking_number ?? shipment.reference, shipment.status),
    meta(`${shipment.reference}${shipment.order_external_id ? ` · ${shipment.order_external_id}` : ''}${shipment.shipping_fee_amount !== undefined ? ` · fee ${peso(shipment.shipping_fee_amount)}` : ''}`),
    shipment.last_event && meta(`Last update: ${label(shipment.last_event.status)}${shipment.last_event.location ? ` at ${shipment.last_event.location}` : ''}${shipment.last_event.note ? ` (${shipment.last_event.note})` : ''}`),
  ]);
}

function outgoingRow(entry, key) {
  return h('li', { class: 'event event-out' }, expandable(key,
    [h('span', { class: 'dir dir-out' }, '→ SFA'), h('span', { class: 'actor' }, entry.actor), h('code', {}, `${entry.method} ${entry.path}`), httpPill(entry), h('time', { datetime: entry.at }, clock(entry.at))],
    [h('h3', {}, 'Request'), pre(entry.request), h('h3', {}, 'Response'), pre(entry.response ?? 'Waiting for SFA…')]));
}

function incomingRow(event, key) {
  return h('li', { class: 'event event-in' }, expandable(key,
    [h('span', { class: 'dir dir-in' }, '← SFA'), h('span', { class: 'actor' }, 'Webhook'), h('code', {}, event.event ?? 'event'),
      h('span', { class: 'pill pill-good' }, '✓ signature verified'), event.duplicate && h('span', { class: 'pill pill-warn' }, 'duplicate'),
      h('time', { datetime: event.received_at }, clock(event.received_at))],
    [h('h3', {}, 'Delivery'), pre({ delivery: event.delivery, event_id: event.event_id }), h('h3', {}, 'Data'), pre(event.data)]));
}

function renderTimeline() {
  const rows = [
    ...snapshot.log.map((entry) => [entry.at, `out:${entry.id}`, entry, outgoingRow]),
    ...snapshot.events.map((event) => [event.received_at, `in:${event.event_id}:${event.received_at}`, event, incomingRow]),
  ].sort(([left], [right]) => right.localeCompare(left));
  $('#timeline').replaceChildren(...(rows.length
    ? rows.map(([, key, item, row]) => row(item, key))
    : [h('li', { class: 'empty' }, 'No traffic yet. Use the steps on the left and watch messages appear here.')]));
}

function render() {
  fillOptions();
  fresh.clear();
  renderOrders();
  renderPayments();
  renderTickets();
  renderRefunds();
  renderShipments();
  renderTimeline();
}

// The pill is a live region: rewriting the same text on every 3-second poll would make screen readers repeat it.
function setConnection(text, tone) {
  const pill = $('#connection');
  if (pill.textContent === text) return;
  pill.textContent = text;
  pill.className = `pill pill-${tone}`;
}

function renderStatus(status) {
  setConnection(status.connected ? `Connected as ${status.client_code ?? 'integration client'}` : 'Not connected', status.connected ? 'good' : 'bad');
  $('#sfa-link').href = status.sfa_app_url;
  $('#courier-code').textContent = status.courier_code ?? '{code}';
  const saved = (present) => (present ? 'saved' : 'missing');
  const facts = [
    ['SFA API', status.sfa_api_url], ['SFA reachable with this token', status.connected ? 'yes' : 'no'],
    ['Client code', status.client_code ?? 'not set'], ['Access token', saved(status.has.token)],
    ['Webhook secret', saved(status.has.webhook_secret)], ['Courier', status.has.courier ? `${status.courier_code} (secret saved)` : 'missing'],
    ['Payment gateway secret', saved(status.has.gateway)],
  ];
  $('#setup-status').replaceChildren(...facts.flatMap(([term, value]) => [h('dt', {}, term), h('dd', {}, value)]));
}

async function refresh() {
  if (loading) {
    again = true;
    return;
  }
  loading = true;
  try {
    const response = await fetch('/api/state', { cache: 'no-store' });
    const { status, ...collections } = await response.json();
    if (!response.ok) throw new Error('state unavailable');
    renderStatus(status);
    const key = JSON.stringify(collections);
    if (key !== lastKey) {
      lastKey = key;
      snapshot = collections;
      render();
    }
  } catch {
    setConnection('Simulator offline', 'bad');
  } finally {
    loading = false;
    if (again) {
      again = false;
      refresh();
    }
  }
}

// ---------- forms ----------

function formToJson(form) {
  const body = {};
  for (const [name, value] of new FormData(form)) {
    const path = name.split('.');
    let node = body;
    path.forEach((part, index) => {
      if (index === path.length - 1) node[part] = value;
      else node = node[part] ??= /^\d+$/.test(path[index + 1]) ? [] : {};
    });
  }
  return body;
}

async function post(endpoint, body) {
  try {
    const response = await fetch(endpoint, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
    return await response.json();
  } catch {
    return { ok: false, status: 0, message: 'The partner simulator is not answering. Is node server.mjs still running?' };
  }
}

function busy(button, on) {
  button.dataset.label ??= button.textContent;
  button.disabled = on;
  button.setAttribute('aria-busy', String(on));
  button.textContent = on ? 'Sending…' : button.dataset.label;
}

function clearErrors(form) {
  for (const message of $$('.field-error', form)) message.remove();
  for (const control of $$('[aria-invalid]', form)) {
    control.removeAttribute('aria-invalid');
    control.removeAttribute('aria-describedby');
  }
}

// SFA names some fields differently from these forms: money is "*_amount" (pesos here), links are internal ids.
const FIELD_ALIASES = { order: 'order_external_id', order_id: 'order_external_id', payment_id: 'payment_reference', requester_id: 'requester_external_id', reference: 'payment_reference' };

function fieldError(form, field, message) {
  const control = form.elements.namedItem(field) ?? form.elements.namedItem(FIELD_ALIASES[field] ?? field.replace(/_amount$/, ''));
  if (!control?.closest) return false;
  const id = `field-error-${++errorCount}`;
  control.setAttribute('aria-invalid', 'true');
  control.setAttribute('aria-describedby', id);
  control.closest('label').append(h('small', { class: 'field-error', id }, message));
  return true;
}

const DONE = {
  setup: () => 'Saved. The connection pill updates in a moment.',
  reset: () => 'Simulator data cleared.',
  customer: (d) => `Customer ${d.name} is now in SFA as ${d.external_id}.`,
  seller: (d) => `Seller ${d.shop_name} is now in SFA as ${d.external_id}.`,
  order: (d) => `Order ${d.external_id} is in SFA with a total of ${peso(d.total_amount)}. Pay for it next.`,
  pay: (d) => `Payment ${d.payment.reference} is ${label(d.payment.status)}${d.next_action?.message ? `: ${d.next_action.message}` : '.'}`,
  cancel: (d) => `Order ${d.external_id} is ${label(d.status_snapshot)} in SFA.`,
  ticket: (d) => `Ticket ${d.reference} is in SFA's support queue (${label(d.status)}).`,
  ticket_refresh: (d) => `Ticket ${d.reference} is ${label(d.status)} with ${d.messages?.length ?? 0} public message(s).`,
  ticket_reply: () => 'Reply delivered to the SFA agent.',
  csat: (d) => `Rating ${d.csat_score}/5 saved on ${d.reference}.`,
  refund: (d) => `Refund ${d.reference} for ${peso(d.total_amount)} is ${label(d.status)}. Finance decides in SFA.`,
  lookup: (d) => `${d.reference} is ${label(d.status)} in SFA.`,
  quote: (d) => (d.data.length
    ? h('span', {}, 'SFA quotes:', h('ul', {}, d.data.map((q) => h('li', {}, `${q.courier_name} · ${label(q.service_type)} · ${peso(q.total_amount)} · arrives ${q.eta_from} to ${q.eta_to}`))))
    : 'SFA has no active rate for this route and weight.'),
  gateway: (d) => `SFA: ${d.message}`,
  courier: (d) => `SFA: ${d.message}${d.shipment ? ` ${d.shipment.reference} is now ${label(d.shipment.status)}.` : ''}`,
};

const AFTER = {
  setup: (form) => form.reset(),
  customer: (form) => { ['customers', 'requesters'].forEach((source) => fresh.add(source)); demoCustomer(form); },
  seller: (form) => { ['sellers', 'requesters'].forEach((source) => fresh.add(source)); demoSeller(form); },
  order: () => fresh.add('openOrders'),
  pay: (form, data) => { fresh.add('capturedPayments'); $('#panel-gateway [name="payment_reference"]').value = data.payment.reference; },
  ticket: () => fresh.add('tickets'),
  ticket_reply: (form) => { form.elements.body.value = ''; },
};

function showResult(form, result) {
  const box = $('.result', form);
  box.className = `result ${result.ok ? 'result-ok' : 'result-error'}`;
  const code = result.status ? h('span', { class: 'http' }, `HTTP ${result.status}`) : null;
  if (result.ok) {
    box.replaceChildren(...[code, DONE[form.dataset.done]?.(result.data) ?? 'Done.'].filter(Boolean));
    return;
  }
  const unmatched = Object.entries(result.errors ?? {}).filter(([field, messages]) => !fieldError(form, field, messages.join(' ')));
  box.replaceChildren(...[code, result.message ?? 'Something went wrong.',
    unmatched.length && h('ul', {}, unmatched.map(([field, messages]) => h('li', {}, `${label(field)}: ${messages.join(' ')}`)))].filter(Boolean));
}

async function submit(event) {
  const form = event.target;
  if (!form.matches('form[data-endpoint]')) return;
  event.preventDefault();
  if (form.dataset.confirm && !window.confirm(form.dataset.confirm)) return;
  const button = $('[type="submit"]', form);
  const body = formToJson(form);
  clearErrors(form);
  busy(button, true);
  const result = await post(form.dataset.endpoint, body);
  busy(button, false);
  if (result.ok) AFTER[form.dataset.done]?.(form, result.data);
  showResult(form, result);
  refresh();
}

// ---------- demo defaults and the order builder ----------

function demoCustomer(form) {
  const name = pick(PEOPLE);
  form.elements.name.value = name;
  form.elements.email.value = `${name.toLowerCase().replace(/[^a-z]+/g, '.')}@example.com`;
  form.elements.phone.value = `0917 555 ${String(Math.floor(Math.random() * 10_000)).padStart(4, '0')}`;
}

function demoSeller(form) {
  const [shop, owner] = pick(SHOPS);
  form.elements.shop_name.value = shop;
  form.elements.owner_name.value = owner;
  form.elements.email.value = `${shop.toLowerCase().replace(/[^a-z]+/g, '.')}@example.com`;
}

function renumberItems() {
  const rows = $$('#items .item-row');
  rows.forEach((row, index) => {
    for (const input of $$('[data-item]', row)) input.name = `items.${index}.${input.dataset.item}`;
    const remove = $('.remove-item', row);
    remove.hidden = rows.length === 1;
    remove.setAttribute('aria-label', `Remove item ${index + 1}`);
  });
}

function addItemRow() {
  const row = $('#item-row').content.firstElementChild.cloneNode(true);
  const [name, price] = CATALOG[$('#items').children.length % CATALOG.length];
  $('[data-item="product_name"]', row).value = name;
  $('[data-item="unit_price"]', row).value = price;
  $('#items').append(row);
  renumberItems();
  fillOptions(row);
  updatePreview();
}

function outcomeHint(total) {
  if (total % 100 === 99) return 'Ends in .99: the sandbox gateway will decline it (cash on delivery is not affected).';
  if (total % 100 === 98) return 'Ends in .98: the payment will wait for a gateway event (tab 4).';
  if (total > 5_000_000) return 'Above ₱50,000: Finance must clear a risk review first.';
  return 'The sandbox gateway will approve it.';
}

function updatePreview() {
  const form = $('#order-form');
  const lines = $$('#items .item-row').map((row) => [toCentavos($('[data-item="unit_price"]', row).value), Number($('[data-item="quantity"]', row).value)]);
  const shipping = toCentavos(form.elements.shipping.value || '0');
  const discount = toCentavos(form.elements.discount.value || '0');
  if (shipping === null || discount === null || lines.some(([unit, quantity]) => unit === null || !Number.isInteger(quantity) || quantity < 1)) {
    $('#order-preview').textContent = 'Enter quantities and peso amounts (for example 1499.50) to see the total.';
    return;
  }
  const subtotal = lines.reduce((sum, [unit, quantity]) => sum + unit * quantity, 0);
  const total = subtotal + shipping - discount;
  $('#order-preview').textContent = `Subtotal ${peso(subtotal)} + shipping ${peso(shipping)} − discount ${peso(discount)} = total ${peso(total)}. ${outcomeHint(total)}`;
}

function onOrderInput(event) {
  if (event.target.dataset.item === 'product_name') {
    const match = CATALOG.find(([name]) => name === event.target.value);
    if (match) $('[data-item="unit_price"]', event.target.closest('.item-row')).value = match[1];
  }
  updatePreview();
}

// ---------- steps ----------

function showStep(step) {
  const id = STEPS.includes(step) ? step : 'setup';
  for (const button of $$('[data-step]')) button.setAttribute('aria-current', button.dataset.step === id ? 'step' : 'false');
  for (const panel of $$('.panel')) panel.hidden = panel.id !== `panel-${id}`;
}

function init() {
  $('#catalog').replaceChildren(...CATALOG.map(([name, price]) => new Option(`₱${price}`, name)));
  demoCustomer($('form[data-done="customer"]'));
  demoSeller($('form[data-done="seller"]'));
  addItemRow();
  $('#add-item').addEventListener('click', addItemRow);
  $('#items').addEventListener('click', (event) => {
    if (!event.target.matches('.remove-item')) return;
    event.target.closest('.item-row').remove();
    renumberItems();
    updatePreview();
  });
  $('#order-form').addEventListener('input', onOrderInput);
  $('#ticket-form').elements.requester_type.addEventListener('change', () => fillOptions($('#ticket-form')));
  document.addEventListener('submit', submit);
  for (const button of $$('[data-step]')) {
    button.addEventListener('click', () => {
      history.replaceState(null, '', `#${button.dataset.step}`);
      showStep(button.dataset.step);
    });
  }
  window.addEventListener('hashchange', () => showStep(location.hash.slice(1)));
  showStep(location.hash.slice(1));
  fillOptions();
  refresh();
  setInterval(refresh, 3000);
}

init();

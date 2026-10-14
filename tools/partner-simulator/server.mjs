#!/usr/bin/env node
// Archon Market partner simulator: plays the storefront, payment gateway and courier that SFA integrates with.
// It talks to SFA only through the public integration API and signed webhooks. See README.md.
import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.dirname(fileURLToPath(import.meta.url));
const STORAGE = path.join(ROOT, 'storage');
const CONFIG_FILE = path.join(STORAGE, 'config.json');
const DATA_FILE = path.join(STORAGE, 'data.json');
const PORT = Number(process.env.PARTNER_PORT) || 8787;
const DEFAULT_SFA_API_URL = process.env.SFA_API_URL || 'http://127.0.0.1:8000/api/v1';
const SFA_APP_URL = process.env.SFA_APP_URL || 'http://127.0.0.1:5174';
const CONFIG_KEYS = ['sfa_api_url', 'token', 'webhook_secret', 'courier_code', 'courier_secret', 'gateway_secret', 'client_code'];
const COLLECTIONS = ['customers', 'sellers', 'orders', 'payments', 'refunds', 'tickets', 'shipments', 'log', 'events'];
const LOOKUP_KINDS = ['payment', 'refund', 'shipment'];
const KEEP = 100;
const SIGNATURE_TOLERANCE_SECONDS = 300;
const BODY_LIMIT_BYTES = 1_000_000;
const RESPONSE_LOG_CHARS = 4096;
const STATIC_FILES = { '/': 'index.html', '/index.html': 'index.html', '/app.js': 'app.js', '/style.css': 'style.css' };
const CONTENT_TYPES = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css; charset=utf-8' };
const LOOPBACK = new Set(['127.0.0.1', '::1', '::ffff:127.0.0.1']);
const LOCAL_HOSTS = new Set([`127.0.0.1:${PORT}`, `localhost:${PORT}`, `[::1]:${PORT}`]);

const STOREFRONT_ERRORS = {
  401: 'SFA rejected the token. In SFA press "Connect partner simulator" again, or paste a new token in Setup.',
  403: 'SFA refused this action for this token (missing scope or inactive client).',
  404: 'SFA has no matching record for this partner. Check the ID or reference.',
  409: 'SFA is still processing an identical request. Try again in a few seconds.',
  429: 'Too many requests. Wait a minute and try again.',
  500: 'SFA hit an internal error. Check the API terminal or backend/storage/logs/laravel.log.',
};
const SIGNED_ERRORS = {
  ...STOREFRONT_ERRORS,
  401: 'SFA rejected the signature. Check the secret in Setup (it may have been rotated).',
  404: 'SFA does not know this courier code, tracking number or payment reference.',
};

let config;
let state;
let probe = null;

class HttpError extends Error {
  constructor(status, message, errors) {
    super(message);
    this.status = status;
    this.errors = errors;
  }
}

// ---------- small helpers ----------

const text = (value) => (typeof value === 'string' && value.trim() ? value.trim() : null);
const integerOrRaw = (value) => (/^\d+$/.test(String(value ?? '').trim()) ? Number(value) : text(value));
const defined = (object) => Object.fromEntries(Object.entries(object).filter(([, value]) => value !== undefined));
const nowSeconds = () => Math.floor(Date.now() / 1000);
const randomHex = (length) => crypto.randomBytes(Math.ceil(length / 2)).toString('hex').slice(0, length);

function parseJson(raw) {
  try {
    return raw ? JSON.parse(raw) : null;
  } catch {
    return raw;
  }
}

function isHttpUrl(value) {
  try {
    return ['http:', 'https:'].includes(new URL(value).protocol);
  } catch {
    return false;
  }
}

function required(input, field, label) {
  const value = text(input[field]);
  if (!value) throw new HttpError(422, `Choose or enter ${label}.`, { [field]: [`Choose or enter ${label}.`] });
  return value;
}

// ---------- money (integer centavos only) ----------

// Pesos typed by a person ("1,499.50") become integer centavos by parsing the text, so no float drift creeps in.
function toCentavos(value) {
  const match = /^(\d{1,13})(?:\.(\d{1,2}))?$/.exec(String(value ?? '').trim().replaceAll(',', ''));
  return match ? Number(match[1]) * 100 + Number((match[2] ?? '').padEnd(2, '0')) : null;
}

function toQuantity(value) {
  const quantity = /^\d{1,7}$/.test(String(value ?? '').trim()) ? Number(value) : 0;
  return quantity >= 1 ? quantity : null;
}

function moneyError(field) {
  return new HttpError(422, 'Check the highlighted amount.', { [field]: ['Enter pesos with up to two decimals, for example 1499.50.'] });
}

// Builds the MirrorRequest order body; SFA re-checks every line total, the subtotal and the total.
function buildOrder(input, placedAt = new Date()) {
  const errors = {};
  const money = (field, value) => {
    const amount = toCentavos(value);
    if (amount === null) errors[field] = ['Enter pesos with up to two decimals, for example 1499.50.'];
    return amount;
  };
  const items = (Array.isArray(input.items) ? input.items : []).map((item, index) => {
    const quantity = toQuantity(item?.quantity);
    if (quantity === null) errors[`items.${index}.quantity`] = ['Enter a whole number of at least 1.'];
    const unit = money(`items.${index}.unit_price`, item?.unit_price);
    return { seller_external_id: text(item?.seller_external_id), product_name: text(item?.product_name), quantity, unit_price_amount: unit, line_total_amount: quantity * unit };
  });
  const shipping = money('shipping', text(input.shipping) ?? '0');
  const discount = money('discount', text(input.discount) ?? '0');
  if (Object.keys(errors).length) throw new HttpError(422, 'Check the highlighted fields.', errors);
  const subtotal = items.reduce((sum, item) => sum + item.line_total_amount, 0);
  const totals = { subtotal_amount: subtotal, shipping_amount: shipping, discount_amount: discount, total_amount: subtotal + shipping - discount };
  if (![...items.map((item) => item.line_total_amount), ...Object.values(totals)].every(Number.isSafeInteger)) {
    throw new HttpError(422, 'An amount is too large.', { total_amount: ['An amount is too large.'] });
  }
  return { customer_external_id: text(input.customer_external_id), status_snapshot: 'placed', ...totals, currency: 'PHP', placed_at: placedAt.toISOString(), items };
}

// ---------- signatures (same scheme as SFA's WebhookService::signature) ----------

function hmacHex(secret, timestamp, raw) {
  return crypto.createHmac('sha256', secret).update(`${timestamp}.`).update(raw).digest('hex');
}

function sign(secret, raw, timestamp = nowSeconds()) {
  return `t=${timestamp},v1=${hmacHex(secret, timestamp, raw)}`;
}

function verifySignature(secret, raw, header, now = nowSeconds()) {
  const match = /^t=(\d+),v1=([a-f0-9]{64})$/.exec(typeof header === 'string' ? header : '');
  if (!match || Math.abs(now - Number(match[1])) > SIGNATURE_TOLERANCE_SECONDS) return false;
  return crypto.timingSafeEqual(Buffer.from(hmacHex(secret, match[1], raw), 'hex'), Buffer.from(match[2], 'hex'));
}

function redactHeaders(headers) {
  return Object.fromEntries(Object.entries(headers).map(([name, value]) => {
    const lower = name.toLowerCase();
    if (lower === 'authorization') return [name, /^Bearer /i.test(value) ? 'Bearer [redacted]' : '[redacted]'];
    if (lower === 'x-sfa-signature') return [name, '[redacted]'];
    return [name, value];
  }));
}

// ---------- storage ----------

function readJson(file) {
  try {
    return JSON.parse(fs.readFileSync(file, 'utf8'));
  } catch {
    return null;
  }
}

function writeJson(file, value) {
  const body = JSON.stringify(value, null, 2);
  const temp = `${file}.tmp`;
  try {
    fs.mkdirSync(STORAGE, { recursive: true });
    fs.writeFileSync(temp, body);
    fs.renameSync(temp, file);
  } catch (error) {
    // OneDrive or antivirus can briefly lock the target on Windows; a direct write beats losing the change.
    try {
      fs.writeFileSync(file, body);
      fs.rmSync(temp, { force: true });
    } catch {
      console.error(`Could not save ${path.basename(file)}: ${error.message}`);
    }
  }
}

function stateFrom(saved) {
  return Object.fromEntries(COLLECTIONS.map((name) => [name, Array.isArray(saved?.[name]) ? saved[name] : []]));
}

const save = () => writeJson(DATA_FILE, state);

function mergeConfig(current, input) {
  const next = { ...current };
  for (const key of CONFIG_KEYS) {
    const value = text(input?.[key]);
    if (!value) continue;
    if (key === 'sfa_api_url' && !isHttpUrl(value)) {
      throw new HttpError(422, 'Enter the SFA API address, for example http://127.0.0.1:8000/api/v1.', { sfa_api_url: ['Enter an http:// or https:// address.'] });
    }
    next[key] = key === 'sfa_api_url' ? value.replace(/\/+$/, '') : value;
  }
  return next;
}

function loadConfig() {
  const defaults = { sfa_api_url: DEFAULT_SFA_API_URL };
  try {
    return mergeConfig(defaults, readJson(CONFIG_FILE));
  } catch {
    return defaults;
  }
}

// ---------- local storefront records ----------

function record(name, entry) {
  state[name].unshift(entry);
  state[name].splice(KEEP);
}

function upsert(name, key, item) {
  if (!item[key]) return;
  const list = state[name];
  const index = list.findIndex((existing) => existing[key] === item[key]);
  if (index === -1) list.unshift(item);
  else list[index] = { ...list[index], ...item };
}

const publicMessages = (messages) => messages
  .filter((message) => message && !message.is_internal_note)
  .map((message) => ({ id: message.id, author_type: message.author_type, body: message.body, created_at: message.created_at }));

function savePayment(payment, extra = {}) {
  upsert('payments', 'reference', defined({
    reference: payment.reference, order_external_id: payment.order?.external_id, method: payment.method, status: payment.status,
    total_amount: payment.total_amount, captured_amount: payment.captured_amount, refunded_amount: payment.refunded_amount,
    risk_level: payment.risk_level, failure_reason: payment.failure_reason, ...extra,
  }));
}

function saveRefund(refund) {
  upsert('refunds', 'reference', defined({
    reference: refund.reference, payment_reference: refund.payment?.reference, amount: refund.total_amount,
    reason: refund.reason, status: refund.status, rejection_reason: refund.rejection_reason,
  }));
}

function saveShipment(shipment) {
  const last = shipment.events?.reduce((latest, event) => (latest && latest.occurred_at > event.occurred_at ? latest : event), null);
  upsert('shipments', 'reference', defined({
    reference: shipment.reference, order_external_id: shipment.order?.external_id, tracking_number: shipment.tracking_number,
    status: shipment.status, service_type: shipment.service_type, shipping_fee_amount: shipment.shipping_fee_amount,
    cod_amount: shipment.cod_amount, promised_to: shipment.promised_to, attempt_count: shipment.attempt_count,
    last_event: last ? { status: last.status, location: last.location, note: last.note, occurred_at: last.occurred_at } : undefined,
  }));
}

// Accepts a full ticket (API responses) or a webhook payload, which carries at most one new public message.
function saveTicket(ticket, extra = {}) {
  const existing = state.tickets.find((item) => item.reference === ticket.reference);
  const added = ticket.message ? publicMessages([ticket.message]) : [];
  const kept = existing?.messages ?? [];
  const messages = ticket.messages ? publicMessages(ticket.messages)
    : added.length ? [...kept, ...added.filter((message) => !kept.some((old) => old.id === message.id))] : undefined;
  upsert('tickets', 'reference', defined({
    reference: ticket.reference, subject: ticket.subject, status: ticket.status, requester_type: ticket.requester_type,
    requester_external_id: ticket.requester_external_id ?? ticket.requester?.external_id, csat_score: ticket.csat_score,
    messages, ...extra,
  }));
}

const SAVERS = { payment: savePayment, refund: saveRefund, shipment: saveShipment, ticket: saveTicket };

// ---------- calls to SFA ----------

async function sfaFetch(actor, method, apiPath, { body, headers = {}, signWith } = {}) {
  const url = `${config.sfa_api_url}${apiPath}`;
  const raw = body === undefined ? undefined : JSON.stringify(body);
  const sent = {
    Accept: 'application/json', ...(raw !== undefined && { 'Content-Type': 'application/json' }), ...headers,
    ...(signWith && { 'X-SFA-Signature': sign(signWith, raw) }),
  };
  const entry = { id: crypto.randomUUID(), at: new Date().toISOString(), actor, method, path: new URL(url).pathname, status: 0, request: { headers: redactHeaders(sent), body: body ?? null }, response: null };
  record('log', entry);
  try {
    const response = await fetch(url, { method, headers: sent, body: raw, signal: AbortSignal.timeout(15_000) });
    const responseText = await response.text();
    entry.status = response.status;
    entry.response = responseText.length > RESPONSE_LOG_CHARS ? `${responseText.slice(0, RESPONSE_LOG_CHARS)}… (truncated)` : parseJson(responseText);
    return { status: response.status, data: parseJson(responseText) };
  } catch (error) {
    entry.response = `No response: ${error.message}`;
    throw error.name === 'TimeoutError'
      ? new HttpError(504, `SFA did not answer within 15 seconds at ${config.sfa_api_url}.`)
      : new HttpError(502, `SFA is not reachable at ${config.sfa_api_url}. Is the API running?`);
  }
}

async function storefront(method, apiPath, body, idempotent = false) {
  if (!config.token) throw new HttpError(409, 'Connect to SFA first: open tab 1 Setup.');
  const headers = { Authorization: `Bearer ${config.token}`, ...(idempotent && { 'Idempotency-Key': crypto.randomUUID() }) };
  const result = await sfaFetch('Storefront', method, `/integrations${apiPath}`, { body, headers });
  probe = { at: Date.now(), result: Promise.resolve(result.status !== 401) };
  return result;
}

function outcome({ status, data }, messages = STOREFRONT_ERRORS) {
  if (status >= 200 && status < 300) return { ok: true, status, data };
  return defined({ ok: false, status, message: messages[status] ?? data?.message ?? `SFA answered with HTTP ${status}.`, errors: data?.errors });
}

async function probeSfa() {
  try {
    const response = await fetch(`${config.sfa_api_url}/integrations/kb/articles?per_page=1`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${config.token}` }, signal: AbortSignal.timeout(5_000),
    });
    return response.ok || response.status === 403; // 403: the token is valid but lacks kb:read.
  } catch {
    return false;
  }
}

function isConnected() {
  if (!config.token) return Promise.resolve(false);
  if (!probe || Date.now() - probe.at > 30_000) probe = { at: Date.now(), result: probeSfa() };
  return probe.result;
}

async function status() {
  return {
    connected: await isConnected(), sfa_api_url: config.sfa_api_url, client_code: config.client_code ?? null,
    courier_code: config.courier_code ?? null, sfa_app_url: SFA_APP_URL,
    has: {
      token: Boolean(config.token), webhook_secret: Boolean(config.webhook_secret),
      courier: Boolean(config.courier_code && config.courier_secret), gateway: Boolean(config.gateway_secret),
    },
  };
}

// ---------- storefront actions (POST /api/actions/<name>) ----------

const ACTIONS = {
  async customer(input) {
    const externalId = `cus-${randomHex(6)}`;
    const body = { name: text(input.name), email: text(input.email), phone: text(input.phone) };
    const result = outcome(await storefront('PUT', `/customers/${externalId}`, body));
    if (result.ok) upsert('customers', 'external_id', { external_id: externalId, ...body });
    return result;
  },

  async seller(input) {
    const externalId = `sel-${randomHex(6)}`;
    const body = { shop_name: text(input.shop_name), owner_name: text(input.owner_name), email: text(input.email) };
    const result = outcome(await storefront('PUT', `/sellers/${externalId}`, body));
    if (result.ok) upsert('sellers', 'external_id', { external_id: externalId, ...body });
    return result;
  },

  async order(input) {
    const body = buildOrder(input);
    const externalId = `ord-${new Date().toLocaleDateString('en-CA').slice(2).replaceAll('-', '')}-${randomHex(4)}`;
    const result = outcome(await storefront('PUT', `/orders/${externalId}`, body));
    if (result.ok) upsert('orders', 'external_id', { external_id: externalId, ...body, status: body.status_snapshot });
    return result;
  },

  async pay(input) {
    const body = { order_external_id: text(input.order_external_id), method: text(input.method) };
    const result = outcome(await storefront('POST', '/payments', body, true));
    if (result.ok) savePayment(result.data.payment, { next_action: result.data.next_action?.message ?? null });
    return result;
  },

  async cancel(input) {
    const externalId = required(input, 'order_external_id', 'an order');
    const result = outcome(await storefront('POST', `/orders/${encodeURIComponent(externalId)}/cancel`));
    if (result.ok) upsert('orders', 'external_id', { external_id: externalId, status: result.data.status_snapshot });
    return result;
  },

  async ticket(input) {
    const body = {
      requester_type: text(input.requester_type), requester_external_id: text(input.requester_external_id),
      subject: text(input.subject), description: text(input.description), category: text(input.category), channel: text(input.channel),
      order_external_id: text(input.order_external_id), payment_reference: text(input.payment_reference),
    };
    const result = outcome(await storefront('POST', '/tickets', body, true));
    if (result.ok) saveTicket(result.data, { order_external_id: body.order_external_id });
    return result;
  },

  async ticket_refresh(input) {
    const reference = required(input, 'reference', 'a ticket');
    const result = outcome(await storefront('GET', `/tickets/${encodeURIComponent(reference)}`));
    if (result.ok) saveTicket(result.data);
    return result;
  },

  async ticket_reply(input) {
    const reference = required(input, 'reference', 'a ticket');
    const result = outcome(await storefront('POST', `/tickets/${encodeURIComponent(reference)}/messages`, { body: text(input.body) }));
    if (result.ok) saveTicket({ reference, message: result.data });
    return result;
  },

  async csat(input) {
    const reference = required(input, 'reference', 'a ticket');
    const body = { score: integerOrRaw(input.score), comment: text(input.comment) };
    const result = outcome(await storefront('POST', `/tickets/${encodeURIComponent(reference)}/csat`, body));
    if (result.ok) saveTicket(result.data);
    return result;
  },

  async refund(input) {
    const amount = toCentavos(input.amount);
    if (amount === null) throw moneyError('amount');
    const body = { payment_reference: text(input.payment_reference), amount, reason: text(input.reason), notes: text(input.notes) };
    const result = outcome(await storefront('POST', '/refunds', body, true));
    if (result.ok) saveRefund(result.data);
    return result;
  },

  async lookup(input) {
    if (!LOOKUP_KINDS.includes(input.kind)) throw new HttpError(422, 'Choose payment, refund or shipment.', { kind: ['Choose payment, refund or shipment.'] });
    const reference = required(input, 'reference', 'a reference');
    const result = outcome(await storefront('GET', `/${input.kind}s/${encodeURIComponent(reference)}`));
    if (result.ok) SAVERS[input.kind](result.data);
    return result;
  },

  // Quotes work from PSGC city codes. Booking a shipment (POST /integrations/shipments) is deliberately absent: it needs
  // SFA's internal warehouse and address IDs, which the integration API does not expose. SFA's Logistics Coordinator books it.
  async quote(input) {
    const codAmount = toCentavos(text(input.cod_amount) ?? '0');
    if (codAmount === null) throw moneyError('cod_amount');
    const dimensions = input.dimensions ?? {};
    const body = {
      origin_code: text(input.origin_code), destination_code: text(input.destination_code), weight_grams: integerOrRaw(input.weight_grams),
      dimensions: { length_cm: text(dimensions.length_cm), width_cm: text(dimensions.width_cm), height_cm: text(dimensions.height_cm) },
      cod_amount: codAmount,
    };
    return outcome(await storefront('POST', '/quotes', body));
  },
};

// ---------- simulated payment gateway and courier (signed webhooks into SFA) ----------

async function gatewayEvent(input) {
  if (!config.gateway_secret) throw new HttpError(409, 'Add the payment gateway secret in Setup (SFA_SANDBOX_WEBHOOK_SECRET in backend/.env).');
  const body = { event_id: `gw_${crypto.randomUUID()}`, reference: text(input.payment_reference), type: text(input.type) };
  if (text(input.amount)) {
    body.amount = toCentavos(input.amount);
    if (body.amount === null) throw moneyError('amount');
  }
  return outcome(await sfaFetch('Payment gateway', 'POST', '/webhooks/payments/sandbox', { body, signWith: config.gateway_secret }), SIGNED_ERRORS);
}

async function courierEvent(input) {
  if (!config.courier_code || !config.courier_secret) throw new HttpError(409, 'Add the courier code and signing secret in Setup.');
  const body = {
    id: `crr_${crypto.randomUUID()}`, tracking_number: text(input.tracking_number), status: text(input.status),
    location: text(input.location), note: text(input.note), occurred_at: new Date().toISOString(),
  };
  const result = outcome(await sfaFetch('Courier', 'POST', `/webhooks/couriers/${encodeURIComponent(config.courier_code)}`, { body, signWith: config.courier_secret }), SIGNED_ERRORS);
  if (result.ok && result.data?.shipment) saveShipment(result.data.shipment);
  return result;
}

// ---------- webhooks from SFA ----------

async function receiveWebhook(req, res) {
  const raw = await readBody(req);
  if (!config.webhook_secret) return send(res, 503, { message: 'The webhook secret is not configured yet.' });
  if (!verifySignature(config.webhook_secret, raw, req.headers['x-sfa-signature'])) {
    console.warn(`Rejected a webhook with an invalid or expired signature (${req.headers['x-sfa-event'] ?? 'unknown event'}).`);
    return send(res, 401, { message: 'Invalid or expired signature.' });
  }
  const event = parseJson(raw.toString('utf8'));
  if (!event || typeof event !== 'object') return send(res, 400, { message: 'The webhook body must be JSON.' });
  // Retries and resends reuse the event id; only the newest KEEP events are remembered for this check.
  const duplicate = state.events.some((item) => item.event_id === event.id);
  record('events', {
    received_at: new Date().toISOString(), event: req.headers['x-sfa-event'] ?? event.type ?? null, delivery: req.headers['x-sfa-delivery'] ?? null,
    event_id: event.id ?? null, data: event.data ?? null, verified: true, ...(duplicate && { duplicate: true }),
  });
  const kind = String(event.type).split('.')[0];
  if (!duplicate && Object.hasOwn(SAVERS, kind) && event.data && typeof event.data === 'object') SAVERS[kind](event.data);
  save();
  send(res, 200, { received: true, duplicate });
}

// ---------- HTTP plumbing ----------

function send(res, status, payload) {
  res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store' });
  res.end(JSON.stringify(payload));
}

function readBody(req) {
  return new Promise((resolve, reject) => {
    const chunks = [];
    let size = 0;
    req.on('data', (chunk) => {
      size += chunk.length;
      if (size <= BODY_LIMIT_BYTES) chunks.push(chunk);
    });
    req.on('end', () => (size > BODY_LIMIT_BYTES ? reject(new HttpError(413, 'The request body is too large.')) : resolve(Buffer.concat(chunks))));
    req.on('error', reject);
  });
}

async function readJsonBody(req) {
  const raw = await readBody(req);
  if (raw.length === 0) return {};
  const value = parseJson(raw.toString('utf8'));
  if (value && typeof value === 'object' && !Array.isArray(value)) return value;
  throw new HttpError(400, 'The request body must be a JSON object.');
}

// The API holds SFA credentials, so only this computer may call it (SFA's "Connect" button posts from the same machine),
// and the Host and Origin checks stop other websites from driving it through the presenter's browser.
function assertTrusted(req) {
  const origin = req.headers.origin;
  const trusted = LOOPBACK.has(req.socket.remoteAddress) && LOCAL_HOSTS.has(req.headers.host)
    && (!origin || LOCAL_HOSTS.has(origin.replace(/^http:\/\//, '')));
  if (!trusted) throw new HttpError(403, 'Only this computer may use the simulator API.');
}

async function postRoute(pathname, input) {
  if (pathname === '/api/setup') {
    config = mergeConfig(config, input);
    writeJson(CONFIG_FILE, config);
    probe = null;
    return { ok: true };
  }
  if (pathname === '/api/reset') {
    state = stateFrom(null);
    return { ok: true };
  }
  if (pathname === '/api/gateway') return gatewayEvent(input);
  if (pathname === '/api/courier') return courierEvent(input);
  const name = pathname.slice('/api/actions/'.length);
  if (pathname.startsWith('/api/actions/') && Object.hasOwn(ACTIONS, name)) return ACTIONS[name](input);
  throw new HttpError(404, 'Unknown simulator action.');
}

async function handleApi(req, res, pathname) {
  assertTrusted(req);
  if (req.method === 'GET' && pathname === '/api/status') return send(res, 200, await status());
  if (req.method === 'GET' && pathname === '/api/state') return send(res, 200, { status: await status(), ...state });
  if (req.method !== 'POST') throw new HttpError(404, 'Not found.');
  const input = await readJsonBody(req);
  try {
    const result = await postRoute(pathname, input);
    send(res, result.status ?? 200, result);
  } finally {
    save();
  }
}

async function serveStatic(res, file) {
  const body = await fs.promises.readFile(path.join(ROOT, 'public', file));
  res.writeHead(200, {
    'Content-Type': CONTENT_TYPES[path.extname(file)], 'Cache-Control': 'no-store', 'X-Content-Type-Options': 'nosniff',
    'Content-Security-Policy': "default-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'",
  });
  res.end(body);
}

async function handle(req, res) {
  try {
    const { pathname } = new URL(req.url, 'http://localhost');
    if (req.method === 'POST' && pathname === '/webhooks') return await receiveWebhook(req, res);
    if (pathname.startsWith('/api/')) return await handleApi(req, res, pathname);
    if (req.method === 'GET' && Object.hasOwn(STATIC_FILES, pathname)) return await serveStatic(res, STATIC_FILES[pathname]);
    send(res, 404, { message: 'Not found.' });
  } catch (error) {
    if (error instanceof HttpError) return send(res, error.status, defined({ ok: false, status: error.status, message: error.message, errors: error.errors }));
    console.error(error);
    send(res, 500, { ok: false, status: 500, message: 'The partner simulator hit an unexpected error. See its terminal.' });
  }
}

function start() {
  config = loadConfig();
  state = stateFrom(readJson(DATA_FILE));
  const server = http.createServer((req, res) => handle(req, res).catch((error) => console.error(error)));
  server.on('error', (error) => {
    console.error(error.code === 'EADDRINUSE' ? `Port ${PORT} is already in use. Stop the other process or set PARTNER_PORT.` : error.message);
    process.exit(1);
  });
  server.listen(PORT, '127.0.0.1', () => {
    console.log(`Archon Market partner simulator: http://localhost:${PORT}`);
    console.log(`SFA API: ${config.sfa_api_url}  (webhooks arrive at http://localhost:${PORT}/webhooks)`);
  });
}

// ---------- self-check: node server.mjs --check ----------

function selfCheck() {
  const secret = 'whsec_test';
  const raw = '{"id":"evt_1"}';
  const at = 1_700_000_000;
  const header = sign(secret, raw, at);
  // Known answer, identical to PHP: hash_hmac('sha256', '1700000000.{"id":"evt_1"}', 'whsec_test').
  assert.equal(header, 't=1700000000,v1=c89214b5b5da833daed6f0b8c5bb6bd58cea9022bd80ccc78230f3942d632925');
  assert.equal(verifySignature(secret, Buffer.from(raw), header, at), true);
  assert.equal(verifySignature(secret, raw, header, at + SIGNATURE_TOLERANCE_SECONDS), true);
  assert.equal(verifySignature(secret, raw, header, at + SIGNATURE_TOLERANCE_SECONDS + 1), false);
  assert.equal(verifySignature(secret, raw, header, at - SIGNATURE_TOLERANCE_SECONDS - 1), false);
  assert.equal(verifySignature(secret, '{"id":"evt_2"}', header, at), false, 'tampered body');
  assert.equal(verifySignature('whsec_other', raw, header, at), false, 'wrong secret');
  assert.equal(verifySignature(secret, raw, 'v1=abc', at), false, 'malformed header');

  assert.equal(toCentavos('1,499.50'), 149950);
  assert.equal(toCentavos('0.1'), 10);
  assert.equal(toCentavos('80.99'), 8099);
  for (const bad of ['1.999', '-1', 'abc', '', '1.', null]) assert.equal(toCentavos(bad), null, String(bad));
  const order = buildOrder({
    customer_external_id: 'cus-1', shipping: '80', discount: '0.30',
    items: [{ seller_external_id: 'sel-1', product_name: 'Pouch', quantity: '3', unit_price: '0.10' }, { seller_external_id: 'sel-1', product_name: 'Earbuds', quantity: '1', unit_price: '1499.99' }],
  }, new Date(0));
  assert.deepEqual(order.items.map((item) => item.line_total_amount), [30, 149999]);
  assert.deepEqual([order.subtotal_amount, order.shipping_amount, order.discount_amount, order.total_amount], [150029, 8000, 30, 157999]);
  assert.equal(order.placed_at, '1970-01-01T00:00:00.000Z');
  assert.throws(() => buildOrder({ items: [{ quantity: '0', unit_price: '1.999' }] }), (error) => error.status === 422 && 'items.0.quantity' in error.errors && 'items.0.unit_price' in error.errors);

  const redacted = redactHeaders({ Authorization: 'Bearer 12|plain-secret', 'X-SFA-Signature': header, Accept: 'application/json' });
  assert.deepEqual(redacted, { Authorization: 'Bearer [redacted]', 'X-SFA-Signature': '[redacted]', Accept: 'application/json' });
  assert.equal(redactHeaders({ authorization: 'plain-secret' }).authorization, '[redacted]');
  console.log('partner-simulator self-check passed');
}

if (process.argv.includes('--check')) {
  try {
    selfCheck();
    process.exit(0);
  } catch (error) {
    console.error(error);
    process.exit(1);
  }
} else {
  start();
}

import { useAuthStore } from '../store/authStore'

// A disabled control must look disabled (grey, no-entry cursor); otherwise it reads as a broken field that ignores clicks.
export const fieldClass = 'mt-1.5 w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-brand-blue focus:ring-2 focus:ring-blue-100 disabled:cursor-not-allowed disabled:border-slate-200 disabled:bg-slate-100 disabled:text-slate-500'
export const filterClass = 'rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-700 outline-none focus:border-brand-blue focus:ring-2 focus:ring-blue-100 disabled:cursor-not-allowed disabled:border-slate-200 disabled:bg-slate-100 disabled:text-slate-500'
export const primaryClass = 'inline-flex items-center justify-center gap-2 rounded-lg bg-brand-blue px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-900 focus:outline-none focus:ring-2 focus:ring-blue-300 disabled:cursor-not-allowed disabled:opacity-60'
export const secondaryClass = 'inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-slate-300 disabled:cursor-not-allowed disabled:opacity-60'
export const ghostClass = 'inline-flex items-center gap-2 rounded-lg px-3 py-2.5 text-sm font-semibold text-slate-500 transition hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-300 disabled:cursor-not-allowed disabled:opacity-60'
export const statusColors = {
  active: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  delivered: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  inactive: 'bg-slate-100 text-slate-700 ring-slate-500/20',
  pending: 'bg-amber-50 text-amber-700 ring-amber-600/20',
  queued: 'bg-blue-50 text-blue-700 ring-blue-600/20',
  failed: 'bg-red-50 text-red-700 ring-red-600/20',
  locked: 'bg-red-50 text-red-700 ring-red-600/20',
  disabled: 'bg-slate-100 text-slate-700 ring-slate-500/20',
  trialing: 'bg-blue-50 text-blue-700 ring-blue-600/20',
  past_due: 'bg-red-50 text-red-700 ring-red-600/20',
  expired: 'bg-slate-100 text-slate-700 ring-slate-500/20',
  cancelled: 'bg-slate-100 text-slate-700 ring-slate-500/20',
  captured: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  authorized: 'bg-blue-50 text-blue-700 ring-blue-600/20',
  partially_refunded: 'bg-orange-50 text-orange-700 ring-orange-600/20',
  refunded: 'bg-violet-50 text-violet-700 ring-violet-600/20',
  disputed: 'bg-red-50 text-red-700 ring-red-600/20',
  dispute_won: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  dispute_lost: 'bg-red-50 text-red-700 ring-red-600/20',
  requested: 'bg-amber-50 text-amber-700 ring-amber-600/20',
  approved: 'bg-blue-50 text-blue-700 ring-blue-600/20',
  completed: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  rejected: 'bg-red-50 text-red-700 ring-red-600/20',
  processing: 'bg-blue-50 text-blue-700 ring-blue-600/20',
  draft: 'bg-slate-100 text-slate-700 ring-slate-500/20',
  finalized: 'bg-blue-50 text-blue-700 ring-blue-600/20',
  paid: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  void: 'bg-slate-100 text-slate-700 ring-slate-500/20',
  uncollectible: 'bg-red-50 text-red-700 ring-red-600/20',
  open: 'bg-blue-50 text-blue-700 ring-blue-600/20',
  in_progress: 'bg-amber-50 text-amber-700 ring-amber-600/20',
  waiting_on_customer: 'bg-orange-50 text-orange-700 ring-orange-600/20',
  reopened: 'bg-violet-50 text-violet-700 ring-violet-600/20',
  resolved: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  closed: 'bg-slate-100 text-slate-700 ring-slate-500/20',
  ready_for_pickup: 'bg-blue-50 text-blue-700 ring-blue-600/20',
  picked_up: 'bg-blue-50 text-blue-700 ring-blue-600/20',
  in_transit: 'bg-blue-50 text-blue-700 ring-blue-600/20',
  out_for_delivery: 'bg-amber-50 text-amber-700 ring-amber-600/20',
  failed_attempt: 'bg-red-50 text-red-700 ring-red-600/20',
  returning: 'bg-orange-50 text-orange-700 ring-orange-600/20',
  returned_to_sender: 'bg-slate-100 text-slate-700 ring-slate-500/20',
  low: 'bg-slate-100 text-slate-700 ring-slate-500/20',
  normal: 'bg-slate-100 text-slate-700 ring-slate-500/20',
  high: 'bg-amber-50 text-amber-700 ring-amber-600/20',
  urgent: 'bg-red-50 text-red-700 ring-red-600/20',
  published: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  none: 'bg-slate-100 text-slate-700 ring-slate-500/20',
  review: 'bg-amber-50 text-amber-700 ring-amber-600/20',
  blocked: 'bg-red-50 text-red-700 ring-red-600/20',
  matched: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  amount_mismatch: 'bg-amber-50 text-amber-700 ring-amber-600/20',
  missing_in_sfa: 'bg-red-50 text-red-700 ring-red-600/20',
  missing_in_report: 'bg-orange-50 text-orange-700 ring-orange-600/20',
}
export const auditActions = ['created', 'updated', 'deleted', 'login', 'logout', 'failed_login', 'account_locked', 'otp_sent', 'otp_delivery_failed', 'password_changed', 'unlocked']
// Settings > Automation lists one row per automatic rule, grouped by module. `setting` is a GET/PUT /settings key of
// `type` 'boolean', 'number' (0 = off) or 'money' (centavos, edited in pesos); `unit` labels a number or money field.
// A 'plan' setting holds a plan id, chosen from the active free plans (empty = off).
// Rows without a setting are integrity jobs shown as "Always on". `results` are scheduler result keys whose counts
// from the last run (GET /automation `last_result`) are shown on the row. Add new rules here; keep existing entries.
export const AUTOMATIONS = [
  { key: 'auto_assign', module: 'Support', title: 'Assign tickets', description: 'Gives each new ticket to the active support agent with the fewest open tickets. Scheduled work also assigns tickets left in the unassigned queue, such as those released when an agent is deactivated.', trigger: 'When a ticket is created, and scheduled work', setting: 'auto_assign', type: 'boolean', results: ['tickets_auto_assigned'] },
  { key: 'ticket_release', module: 'Support', title: 'Return tickets to the queue', description: 'When a staff account is deactivated or loses the Admin and Support Agent roles, its open tickets go back to the unassigned queue so other agents can see them.', trigger: 'When a staff account is saved' },
  { key: 'ticket_sla_alerts', module: 'Support', title: 'SLA alerts', description: 'Tells the assignee (every agent when nobody is assigned) once when a ticket is at risk of missing its SLA and again when it is breached. A breach also alerts the Admins.', trigger: 'Scheduled work', results: ['ticket_sla_notifications'] },
  { key: 'ticket_auto_close', module: 'Support', title: 'Close resolved tickets', description: 'Closes tickets 7 days after they are resolved. A customer or seller reply in that time restarts the 7 days from their latest message.', trigger: 'Scheduled work', results: ['tickets_auto_closed'] },
  { key: 'ticket_low_csat', module: 'Support', title: 'Low satisfaction alert', description: 'Tells the Admins and the assignee when a ticket is rated 2 out of 5 or lower. A repeated low rating does not alert again.', trigger: 'When a rating is submitted' },
  { key: 'ticket_reply_follow_up', module: 'Support', title: 'Status follows replies', description: 'A public staff reply moves an open or reopened ticket to In progress, and a support agent\'s reply claims an unassigned ticket. A customer reply moves a waiting ticket back to In progress and tells the assignee.', trigger: 'When a reply is sent' },
  { key: 'ticket_auto_link', module: 'Support', title: 'Link the order\'s payment or shipment', description: 'When a new payment or refund ticket names an order with one payment, links that payment. A delivery or return ticket gets the order\'s only shipment (for a seller, their only shipment). Nothing is linked when there is more than one.', trigger: 'When a ticket is created' },
  { key: 'ticket_auto_acknowledge', module: 'Support', title: 'Acknowledge new tickets', description: 'Adds a public automatic reply to each new ticket that tells the customer or seller when to expect an answer. It does not count as the first response, so the SLA keeps running until an agent replies.', trigger: 'When a ticket is created', setting: 'ticket_auto_acknowledge', type: 'boolean' },
  { key: 'ticket_auto_resolve', module: 'Support', title: 'Resolve quiet waiting tickets', description: 'Resolves a ticket that has waited on the customer this many days with no reply from them, with a note and the auto_resolved tag. The customer is told through the status update, and the resolved ticket closes 7 days later as usual.', trigger: 'Scheduled work', setting: 'ticket_waiting_auto_resolve_days', type: 'number', unit: 'Days (0 = off)', results: ['tickets_auto_resolved'] },
  { key: 'payment_expiry', module: 'Payments', title: 'Expire unpaid payments', description: 'Expires payments that are still pending 30 minutes after they were created.', trigger: 'Scheduled work', results: ['payments_expired'] },
  { key: 'auto_capture_authorized', module: 'Payments', title: 'Capture late authorizations', description: 'Captures payments the gateway authorizes after they were created, before they expire. Cash on delivery, risk reviews and cancelled orders are never captured.', trigger: 'Scheduled work', setting: 'auto_capture_authorized', type: 'boolean', results: ['payments_auto_captured', 'payments_auto_capture_errors'] },
  { key: 'finance_reminders', module: 'Payments', title: 'Finance reminders', description: 'Reminds Finance once about each refund still awaiting approval and each risk review still open (cash on delivery holds included) after this many hours. A disputed payment alerts on the next run.', trigger: 'Scheduled work', setting: 'finance_reminder_hours', type: 'number', unit: 'Hours (0 = off)', results: ['finance_reminders_sent'] },
  { key: 'refund_ticket_note', module: 'Payments', title: 'Refund outcome on the ticket', description: 'When a refund linked to a ticket is completed, fails or is rejected, adds an internal note to the ticket and tells its assignee. A rejection also tells the person who requested the refund.', trigger: 'When a refund is approved or rejected' },
  { key: 'risk_auto_clear', module: 'Payments', title: 'Clear trusted customers', description: 'Clears an open risk review and captures the payment, as Clear and capture does, when the customer already has at least this many captured payments, has never had a disputed or lost payment, has no more failed attempts in the last hour than the risk rule allows, and the amount is within the cap below. Blocked payments and cancelled orders stay with Finance.', trigger: 'Scheduled work', setting: 'risk_auto_clear_min_captured', type: 'number', unit: 'Captured payments needed (0 = off)', results: ['risk_auto_cleared'] },
  { key: 'risk_auto_clear_cap', module: 'Payments', title: 'Cap for clearing trusted customers', description: 'The largest payment the rule above clears on its own. Larger payments always wait for a Finance decision.', trigger: 'Scheduled work', setting: 'risk_auto_clear_max_amount', type: 'money', unit: 'Largest amount (PHP)' },
  { key: 'refund_auto_approve', module: 'Payments', title: 'Approve small refunds', description: 'Approves a refund and sends it to the gateway as soon as staff request it, when it is within this amount, its reason is ticked below, it is the payment\'s first automatic approval, and the requester stays within the daily limit. Anything else waits for an approver, and refunds requested through the integration API always do. Each automatic approval is recorded in the audit log.', trigger: 'When staff request a refund', setting: 'refund_auto_approve_cap_amount', type: 'money', unit: 'Largest amount (PHP, 0 = off)' },
  { key: 'refund_auto_approve_daily_limit', module: 'Payments', title: 'Daily limit for small refunds', description: 'The most one requester\'s automatically approved refunds can add up to in a business day. Refunds the gateway declines do not count.', trigger: 'When staff request a refund', setting: 'refund_auto_approve_daily_limit', type: 'money', unit: 'Per requester per day (PHP)' },
  { key: 'refund_auto_approve_reasons', module: 'Payments', title: 'Reasons for small refunds', description: 'Only refunds with a ticked reason are approved automatically.', trigger: 'When staff request a refund', setting: 'refund_auto_approve_reasons', type: 'options', options: ['item_not_received', 'damaged', 'wrong_item', 'cancelled_order', 'duplicate_charge', 'other'] },
  { key: 'risk_review_hold', module: 'Payments', title: 'Hold payments under risk review', description: 'Keeps a pending or authorized payment that is waiting for a risk decision from expiring until this many hours after it was created, so Finance has time to decide. Other unpaid payments still expire after 30 minutes.', trigger: 'Scheduled work', setting: 'risk_review_hold_hours', type: 'number', unit: 'Hours (0 = off)', results: ['payments_held_for_review'] },
  { key: 'shipment_attention', module: 'Logistics', title: 'Overdue and stuck shipments', description: 'Flags each shipment once when its last promised delivery day has passed in the business time zone, and when it has had no tracking event for the set number of hours (again only after a newer event). Sends one summary to staff who can edit shipments. 0 turns stuck alerts off; overdue alerts are always on.', trigger: 'Scheduled work', setting: 'logistics_stuck_hours', type: 'number', unit: 'Hours without an event (0 = off)', results: ['shipments_overdue_flagged', 'shipments_stuck_flagged'] },
  { key: 'logistics_auto_ticket', module: 'Logistics', title: 'Delivery tickets', description: 'A failed delivery attempt opens a delivery ticket for the customer, linked to the order and shipment, or adds an internal note to the shipment\'s open ticket. The third failed attempt raises it to high priority, and a delivery adds a note.', trigger: 'When a delivery attempt fails or a parcel is delivered', setting: 'logistics_auto_ticket', type: 'boolean' },
  { key: 'logistics_return_cod_cancel', module: 'Logistics', title: 'Settle returned parcels', description: 'When a cash on delivery parcel is returned to the sender or cancelled, and no other cash on delivery parcel of the order can still be delivered, cancels the pending cash on delivery payment. A returned or cancelled parcel of a prepaid order tells Finance to decide on a refund.', trigger: 'When a parcel is returned or cancelled' },
  { key: 'order_cancel_shipments', module: 'Logistics', title: 'Cancel shipments with their order', description: 'When the storefront cancels an order, cancels its shipments that are still pending or ready for pickup. An order with a parcel already with the courier cannot be cancelled.', trigger: 'When an order is cancelled' },
  { key: 'logistics_courier_fill_gaps', module: 'Logistics', title: 'Fill skipped courier steps', description: 'When a courier update skips steps (for example Picked up straight after Pending), records the skipped steps first, noted as inferred. Delivered is never inferred. Staff who edit shipments are told about every courier update SFA refuses, whether this is on or off.', trigger: 'When a courier update arrives', setting: 'logistics_courier_fill_gaps', type: 'boolean' },
  { key: 'logistics_courier_pod', module: 'Logistics', title: 'Courier proof of delivery', description: 'Accepts the recipient name and photo a courier sends with a Delivered update as proof of delivery, and marks the parcel delivered, when it is prepaid and out for delivery. Cash on delivery parcels always need staff to record the proof and the cash collected.', trigger: 'When a courier update arrives', setting: 'logistics_courier_pod', type: 'boolean' },
  { key: 'subscription_renewals', module: 'Billing', title: 'Renew subscriptions', description: 'Renews subscriptions, converts trials, and applies scheduled downgrades and period-end cancellations.', trigger: 'Scheduled work', results: ['subscriptions_renewed'] },
  { key: 'dunning', module: 'Billing', title: 'Retry failed charges', description: 'Retries a failed subscription charge 1, 3 and 7 days later, then cancels the subscription, writes off its open invoices and alerts Finance. A charge still pending at the gateway is retried after 31 minutes instead.', trigger: 'Scheduled work', results: ['billing_retries'] },
  { key: 'invoice_reconcile', module: 'Billing', title: 'Pay invoices captured later', description: 'Marks an open invoice paid as soon as its payment is captured, in the same run. If the subscription was cancelled meanwhile, it stays cancelled and Finance is told to resume it or refund the payment.', trigger: 'Scheduled work', results: ['invoices_reconciled'] },
  { key: 'settlement_generation', module: 'Billing', title: 'Generate settlements', description: 'Creates draft settlements on the settlement schedule for sellers with sales in the period, then sends Finance one notification. It also catches up the seven periods before, so a voided draft or a late sale gets its draft again.', trigger: 'Scheduled work', results: ['settlements_generated'] },
  { key: 'settlement_auto_finalize', module: 'Billing', title: 'Finalize settlement drafts', description: 'Finalizes draft settlements made by scheduled work once they are this many days old and owe the seller zero or more. Drafts rebuilt after a void, and drafts with a disputed payment or a refund in progress, stay drafts for Finance. Void a draft to hold its period.', trigger: 'Scheduled work', setting: 'settlement_auto_finalize_days', type: 'number', unit: 'Days (0 = off)', results: ['settlements_auto_finalized'] },
  { key: 'settlement_carry_forward', module: 'Billing', title: 'Carry negative settlements forward', description: 'When a finalized settlement leaves the seller owing money, deducts that amount from the seller\'s next settlement with sales, shown as Adjustments. Voiding that draft puts the amount back for the one after. When off, recover the money outside SFA.', trigger: 'When a settlement is generated', setting: 'settlement_carry_forward', type: 'boolean' },
  { key: 'default_plan', module: 'Billing', title: 'Free plan for new sellers', description: 'Subscribes each seller that has never had a subscription to this plan, monthly, and tells only that seller\'s storefront. Only active plans that cost nothing and have no commission rate of their own can be chosen; a plan that stops qualifying is skipped.', trigger: 'Scheduled work', setting: 'default_plan_id', type: 'plan', unit: 'Plan', results: ['subscriptions_auto_created'] },
  { key: 'webhook_delivery', module: 'Platform', title: 'Deliver webhooks', description: 'Sends outbound webhooks and retries failed deliveries up to 5 times.', trigger: 'Scheduled work', results: ['webhook_attempts'] },
  { key: 'webhook_auto_recover', module: 'Platform', title: 'Recover disabled endpoints', description: 'About once an hour, sends a signed test event to each endpoint that was disabled after 20 failures. When it answers, re-enables the endpoint, resends its failed deliveries and tells Admins. Endpoints you disable by hand are never checked.', trigger: 'Scheduled work', setting: 'webhook_auto_recover', type: 'boolean', results: ['webhook_endpoints_recovered'] },
  { key: 'idempotency_pruning', module: 'Platform', title: 'Prune idempotency keys', description: 'Deletes stored idempotency keys after 24 hours.', trigger: 'Scheduled work', results: ['idempotency_pruned'] },
  { key: 'token_expiry_reminders', module: 'Platform', title: 'Token expiry reminders', description: 'Reminds active staff who manage integrations, once per token, when an active client\'s integration token will expire within this many days.', trigger: 'Scheduled work', setting: 'token_expiry_reminder_days', type: 'number', unit: 'Days before expiry (0 = off)', results: ['token_expiry_reminders'] },
  { key: 'sign_in_lock_alert', module: 'Platform', title: 'Sign-in lock alert', description: 'Tells active Admins when a staff account is locked for 24 hours after its 8th failed sign-in. The shorter 5- and 10-minute locks are only recorded in the audit log.', trigger: 'When a sign-in fails' },
  { key: 'notification_cleanup', module: 'Platform', title: 'Clear and prune notifications', description: 'Marks a notification read for everyone once its item no longer needs action (for example, a refund someone approved or a ticket that was resolved), then deletes read notifications older than this many days. Unread notifications are never deleted.', trigger: 'Scheduled work', setting: 'notification_retention_days', type: 'number', unit: 'Keep read notifications for (days, 0 = forever)', results: ['notifications_cleared', 'notifications_pruned'] },
  { key: 'ai_enabled', module: 'AI assistant', title: 'AI assistant (Google Gemini)', description: 'Lets SFA ask Gemini for the AI features below. While off, nothing is sent to Gemini. Each feature also has its own switch. The AI only labels and suggests: it never moves money, changes a payment or sends anything to a customer. Emails, phone numbers and long numbers are masked before anything is sent.', trigger: 'Master switch', setting: 'ai_enabled', type: 'boolean' },
  { key: 'ai_ticket_triage', module: 'AI assistant', title: 'Ticket triage', description: 'Reads each new ticket, replaces the category Other with a better fit, raises the priority of urgent requests (it never lowers one, and the SLA follows), and adds tags and the customer\'s mood. Staff can change any label.', trigger: 'When a ticket is created, and scheduled work for tickets it missed', setting: 'ai_ticket_triage', type: 'boolean', results: ['tickets_ai_triaged'] },
  { key: 'ai_reply_drafts', module: 'AI assistant', title: 'Reply drafts', description: 'Draft with AI in a ticket\'s reply box writes a reply from the published knowledge base and the linked order, payment and shipment, and shows the articles it used. The agent edits and sends it; nothing is sent automatically.', trigger: 'When an agent asks', setting: 'ai_reply_drafts', type: 'boolean' },
  { key: 'ai_finance_review', module: 'AI assistant', title: 'Finance review', description: 'Explains why a payment is under risk review or whether a refund request fits the order, and recommends Clear or Block, Approve or Reject, with a draft note. Finance still decides.', trigger: 'When Finance opens a risk review or refund', setting: 'ai_finance_review', type: 'boolean' },
  { key: 'ai_risk_second_opinion', module: 'AI assistant', title: 'Second opinion on payments', description: 'Looks at payments from the last day that the risk rules let through. One not yet captured (such as cash on delivery) that looks wrong is added to Risk reviews; a captured one only gets a notice to Finance. It never blocks, cancels or refunds.', trigger: 'Scheduled work', setting: 'ai_risk_second_opinion', type: 'boolean', results: ['payments_ai_flagged'] },
  { key: 'ai_address_fill', module: 'AI assistant', title: 'Address fill', description: 'Paste an address when creating a shipment and the AI reads it; SFA matches the region, province, city and barangay to its own lists for you to check before saving.', trigger: 'When staff paste an address', setting: 'ai_address_fill', type: 'boolean' },
  { key: 'ai_delivery_reason', module: 'AI assistant', title: 'Failed-delivery reason', description: 'Reads the courier\'s note on a failed delivery attempt and adds the likely reason and a next step to the delivery ticket (needs Delivery tickets on).', trigger: 'When a delivery attempt fails', setting: 'ai_delivery_reason', type: 'boolean' },
  { key: 'ai_ask_data', module: 'AI assistant', title: 'Ask your data', description: 'Answers questions on Analytics & Reports using only SFA\'s own reports, limited to what the person\'s role can see, and shows the figures it used.', trigger: 'When someone asks', setting: 'ai_ask_data', type: 'boolean' },
  { key: 'ai_daily_token_limit', module: 'AI assistant', title: 'Daily AI limit', description: 'The most tokens (prompt, answer and thinking) all AI features may use in a day; at the limit the AI waits until midnight. 1,000,000 tokens cost under $3 on the paid tier.', trigger: 'Every AI request', setting: 'ai_daily_token_limit', type: 'number', unit: 'Tokens per day (0 = no AI)' },
]
export function apiError(error, fallback = 'The request could not be completed. Please try again.') {
  const validation = error?.response?.data?.errors
  return validation ? Object.values(validation).flat().join(' ') : error?.response?.data?.message || fallback
}
export function unwrap(value) { return value?.data?.data ?? value?.data ?? value }
export function initials(name) { return String(name || '').trim().split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toUpperCase() || '—' }
export function businessTimezone() { return useAuthStore.getState().user?.business_timezone || 'Asia/Manila' }
export function dateTime(value, timezone = businessTimezone()) {
  if (!value) return '—'
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? '—' : new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeStyle: 'short', timeZone: timezone }).format(date)
}
// Calendar day (YYYY-MM-DD) of a UTC instant in the business time zone; plain dates are returned unchanged.
export function businessDate(value, timezone = businessTimezone()) {
  if (!value) return '—'
  if (/^\d{4}-\d{2}-\d{2}$/.test(value)) return value
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? '—' : new Intl.DateTimeFormat('en-CA', { timeZone: timezone }).format(date)
}
export function roleNames(roles) { return (roles || []).map((role) => typeof role === 'string' ? role : role.name) }
export function pesosToCentavos(value) {
  const match = String(value).trim().match(/^(\d+)(?:\.(\d{1,2}))?$/)
  if (!match) throw new Error('Enter a peso amount with no more than two decimal places.')
  const amount = BigInt(match[1]) * 100n + BigInt((match[2] || '').padEnd(2, '0'))
  if (amount > BigInt(Number.MAX_SAFE_INTEGER)) throw new Error('The amount is too large.')
  return Number(amount)
}
export function centavosToPesos(value) {
  const amount = BigInt(String(value || 0))
  return `${amount / 100n}.${String(amount % 100n).padStart(2, '0')}`
}

export const paymentStatuses = ['pending', 'authorized', 'captured', 'partially_refunded', 'refunded', 'failed', 'cancelled', 'expired', 'disputed', 'dispute_won', 'dispute_lost']
export const refundStatuses = ['requested', 'approved', 'processing', 'completed', 'failed', 'rejected']
export const refundReasons = ['item_not_received', 'damaged', 'wrong_item', 'cancelled_order', 'duplicate_charge', 'other']
export const gatewayEvents = ['payment.authorized', 'payment.captured', 'payment.failed', 'payment.cancelled', 'payment.disputed', 'payment.dispute_won', 'payment.dispute_lost']
export const ledgerTypes = ['payment_capture', 'refund', 'chargeback', 'chargeback_reversal', 'gateway_fee', 'commission', 'commission_reversal', 'subscription_charge', 'payout', 'adjustment']
export const ledgerAccounts = ['customer_funds', 'platform_revenue', 'seller_payable', 'gateway_clearing', 'cash_on_delivery']
export const label = (value) => ({ cod: 'COD', gcash: 'GCash' })[value] ?? String(value || '').replaceAll('_', ' ').replace(/\bsfa\b/, 'SFA').replace(/^./, (character) => character.toUpperCase())
export const money = (centavos) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(Number(centavos || 0) / 100)
// PAY-8 note for refund forms, from GET /payment-options; empty while automatic approval is off.
export function autoApproveHint(options) {
  const reasons = options?.refund_auto_approve_reasons || []
  if (!(options?.refund_auto_approve_cap_amount > 0) || !reasons.length) return ''
  return `Refunds up to ${money(options.refund_auto_approve_cap_amount)} for ${reasons.map((reason) => label(reason).toLowerCase()).join(', ')} are approved and sent to the gateway at once: one per payment, up to ${money(options.refund_auto_approve_daily_limit)} per requester each day. Other refunds wait for an approver.`
}
export function parsePesos(value) {
  const match = String(value).trim().match(/^(\d+)(?:\.(\d{1,2}))?$/)
  if (!match) throw new Error('Enter pesos with no more than two decimal places.')
  const amount = BigInt(match[1]) * 100n + BigInt((match[2] || '').padEnd(2, '0'))
  if (amount <= 0n || amount > 9000000000000000n) throw new Error('Enter a positive amount within the supported range.')
  return Number(amount)
}

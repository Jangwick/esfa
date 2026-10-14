// "Next step" guidance shown on a record's detail view: what the current status means for the employee and which
// button moves it on. Keep the button names in sync with the UI. Returns '' when there is nothing to say.
const texts = {
  ticket: {
    open: 'Answer the requester with "Send reply". Your first reply moves the ticket to In progress. A support agent\'s reply also claims an unassigned ticket.',
    in_progress: 'Answer the requester. Under "After sending", choose Waiting on customer when you need their answer, or Resolved once it is fixed.',
    waiting_on_customer: 'Waiting for the customer. When they answer, the ticket moves back to In progress by itself and the assignee is notified.',
    resolved: 'Nothing to do. It closes by itself 7 days after it was resolved, or 7 days after the requester\'s latest reply if they write again. Change it to Reopened if the problem comes back.',
    closed: 'Done. If the customer comes back, change the status to Reopened.',
    reopened: 'Continue the conversation with "Send reply"; your reply moves the ticket to In progress.',
  },
  payment: {
    pending: 'Waiting for the payment gateway to confirm it. You can also press Capture to collect it, or Cancel payment.',
    authorized: 'Approved by the gateway but not collected yet. Scheduled work captures it within minutes while automatic capture is on; press Capture to collect it now, or Cancel payment.',
    captured: 'Collected. If the customer is owed money, use Request refund.',
    partially_refunded: 'Part of it was refunded. Use Request refund if more is owed.',
    refunded: 'Fully refunded. Nothing left to do.',
    failed: 'The payment did not go through. Nothing left to do on this payment; the customer pays again with a new one.',
    cancelled: 'Cancelled before it was collected. Nothing left to do.',
    expired: 'The customer did not finish paying in time. Nothing left to do.',
    disputed: 'The customer disputed this payment with their bank. The gateway sends the decision, which changes the status to Dispute won or Dispute lost.',
    dispute_won: 'The dispute was decided in your favour. Nothing left to do.',
    dispute_lost: 'The dispute was lost and the money returned to the customer. Nothing left to do.',
  },
  refund: {
    requested: 'Waiting for approval. An Admin can press Approve or Reject on any request, including their own. Other approvers must review a request made by someone else.',
    approved: 'Approved and sent to the payment gateway. The result appears here by itself.',
    processing: 'The payment gateway is processing it. The result appears here by itself.',
    completed: 'Done. The money was returned and the ledger was updated.',
    failed: 'The payment gateway refused it; the reason is shown below. Check the payment, then request a new refund if needed.',
    rejected: 'Rejected. Request a new refund if the decision changes.',
  },
  shipment: {
    pending: 'Prepare the parcel, then on the Timeline tab set Next status to Ready for pickup and press Update status.',
    ready_for_pickup: 'When the courier collects the parcel, set Next status to Picked up and press Update status.',
    picked_up: 'When the parcel leaves the hub, set Next status to In transit and press Update status.',
    in_transit: 'When the courier is on the way to the customer, set Next status to Out for delivery and press Update status.',
    out_for_delivery: 'If it was delivered, record it on the Proof of delivery tab (tick Cash collected for cash on delivery). If nobody received it, set Next status to Failed attempt.',
    failed_attempt: 'Set Next status to Out for delivery to try again. After the third failed attempt it is sent back to the seller automatically.',
    returning: 'When the parcel is back with the seller, set Next status to Returned to sender and press Update status.',
    returned_to_sender: 'Back with the seller. Nothing left to do in Logistics.',
    delivered: 'Delivered. Nothing left to do.',
    cancelled: 'Cancelled. Nothing left to do.',
  },
  subscription: {
    trialing: 'Free trial. The first invoice is created and charged automatically when the trial ends.',
    active: 'Nothing is due until the period ends; it renews automatically. Use Change plan or Cancel subscription if the seller asks.',
    past_due: 'The last charge failed. Open the open invoice under Invoice history and press Charge, or wait for the automatic retry. A payment the gateway confirms later marks the invoice paid by itself.',
    cancelled: 'Cancelled. Press Resume while the paid period is still running, or start a New subscription.',
    expired: 'Ended. Start a New subscription if the seller comes back.',
  },
  invoice: {
    draft: 'Press Charge to collect it, or Void if it should not be collected.',
    open: 'Press Charge to collect it, or Void if it should not be collected.',
    paid: 'Paid. Use Print invoice if the seller needs a copy.',
    void: 'Voided. Nothing left to do on this invoice.',
    uncollectible: 'Written off: every retry failed, or the subscription was cancelled while past due. Nothing left to do on this invoice.',
  },
  settlement: {
    draft: 'Check the amounts, then press Finalize to lock them, or Void to discard the statement.',
    finalized: 'Locked. After the bank transfer to the seller is confirmed, press Record payout.',
    paid: 'Paid out. The seller\'s storefront already reads this statement through the integration API; use Print statement for a paper copy.',
    void: 'Voided and its sales released. The next scheduled run rebuilds the draft for any of the last eight settlement periods, and never finalizes the rebuilt draft automatically; for an older period press Generate.',
  },
}

export function nextStep(entity, record) {
  if (!record) return ''
  if (entity === 'payment' && ['pending', 'authorized'].includes(record.status)) {
    if (record.risk_level === 'review') return record.method === 'cod' ? 'Held for a risk check: it cannot ship, and cash already collected is not captured yet. Press Clear and capture (or Block and cancel) on the Risk review tab.' : 'Held for a risk check. Press Clear and capture (or Block and cancel) on the Risk review tab.'
    if (record.risk_level === 'blocked') return 'Blocked by risk review, so do not ship it. Press Cancel payment, or Clear and capture on the Risk review tab if the block was a mistake.'
    if (record.method === 'cod') return 'Cash on delivery: it is captured when the delivery is recorded with Cash collected in Logistics & Locations.'
  }
  if (entity === 'invoice' && ['draft', 'open'].includes(record.status) && ['cancelled', 'expired'].includes(record.subscription?.status)) return 'Its subscription has ended, so it cannot be charged. A payment still with the gateway marks it paid by itself; otherwise press Void, or resume the subscription first.'
  if (entity === 'reconciliation') return record.unmatched_count > 0 ? 'Resolve every row that is not Matched: press Resolve on the row, or Resolve all to save one note on every row. A Missing in report row resolves itself when a later file reports that payment with matching amounts.' : 'Every row matches the system. Nothing left to do.'
  if (entity === 'settlement' && record.status === 'finalized' && record.net_payable_amount < 0) return record.recovered_by_settlement_id ? 'The seller owed this amount. It was deducted from their next settlement under Adjustments, so nothing is left to do.' : 'The seller owes this amount, so there is no payout. With "Carry negative settlements forward" on, their next settlement deducts it; otherwise recover it outside SFA.'
  return texts[entity]?.[record.status] || ''
}

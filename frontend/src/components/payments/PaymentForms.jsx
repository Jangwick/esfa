import { useEffect, useRef, useState } from 'react'
import { Field, FormError, FormFooter, IconButton } from '../admin/AdminUI'
import { fieldClass } from '../../lib/admin'
import { autoApproveHint, label, money, parsePesos, refundReasons } from '../../lib/payments'

// The alert sits at the top of a form that can be taller than the dialog on a phone, far from the button just pressed.
const reveal = (formRef) => formRef.current?.scrollIntoView({ block: 'start', behavior: 'smooth' })
// Same limits as POST /refunds: photos[] holds 0-3 PNG, JPEG or WebP images of at most 4 MB each.
const photoTypes = ['image/png', 'image/jpeg', 'image/webp']
function photoProblem(files) {
  if (files.length > 3) return 'Add at most 3 photos.'
  if (files.some((file) => !photoTypes.includes(file.type))) return 'Photos must be PNG, JPEG or WebP images.'
  if (files.some((file) => file.size > 4 * 1024 * 1024)) return 'Each photo must be 4 MB or smaller.'
  return ''
}
export function RefundForm({ payments, policy, busy, error, onSubmit, onClose }) {
  const hint = autoApproveHint(policy)
  const [paymentId, setPaymentId] = useState('')
  const [amount, setAmount] = useState('')
  const [reason, setReason] = useState('other')
  const [notes, setNotes] = useState('')
  const [localError, setLocalError] = useState('')
  const [photos, setPhotos] = useState([])
  const [photoError, setPhotoError] = useState('')
  const selected = payments?.find((payment) => String(payment.id) === paymentId)
  const formRef = useRef(null)
  useEffect(() => { if (error) reveal(formRef) }, [error])
  // Picks add to the list, so photos can be chosen one at a time; clearing the input lets a removed file be picked again.
  function addPhotos(event) { const chosen = [...photos, ...Array.from(event.target.files || [])]; event.target.value = ''; const problem = photoProblem(chosen); setPhotoError(problem); if (!problem) setPhotos(chosen) }
  // JSON as before when there are no photos; multipart with the same fields plus photos[] when there are.
  function payload(fields) { if (!photos.length) return fields; const data = new FormData(); Object.entries(fields).forEach(([key, value]) => data.append(key, value)); photos.forEach((file) => data.append('photos[]', file)); return data }
  return <form ref={formRef} className="space-y-4" onSubmit={(event) => { event.preventDefault(); try { const centavos = parsePesos(amount); if (!paymentId) throw new Error('Select a captured payment.'); if (centavos > selected.captured_amount - selected.refunded_amount) throw new Error('The amount exceeds the refundable balance.'); setLocalError(''); onSubmit(payload({ payment_id: Number(paymentId), amount: centavos, reason, notes })) } catch (failure) { setLocalError(failure.message); reveal(formRef) } }}><FormError message={localError || error} />{!payments?.length && <p className="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-600">No captured payments are available to refund.</p>}{hint && <p className="text-sm text-slate-500">{hint}</p>}<Field label="Payment" htmlFor="refund-payment"><select id="refund-payment" className={fieldClass} value={paymentId} onChange={(event) => setPaymentId(event.target.value)} required><option value="">Select a captured payment</option>{payments?.map((payment) => <option key={payment.id} value={payment.id}>{payment.reference} · {money(payment.captured_amount - payment.refunded_amount)} available</option>)}</select></Field><Field label="Refund amount (₱)" htmlFor="refund-amount"><input id="refund-amount" className={fieldClass} inputMode="decimal" value={amount} onChange={(event) => setAmount(event.target.value)} required /></Field><Field label="Reason" htmlFor="refund-reason"><select id="refund-reason" className={fieldClass} value={reason} onChange={(event) => setReason(event.target.value)}>{refundReasons.map((item) => <option key={item} value={item}>{label(item)}</option>)}</select></Field><Field label="Notes" htmlFor="refund-notes"><textarea id="refund-notes" className={fieldClass} rows={3} maxLength={10000} value={notes} onChange={(event) => setNotes(event.target.value)} /></Field><Field label="Photos of the problem (optional)" htmlFor="refund-photos" help="Up to 3 PNG, JPEG or WebP images, 4 MB each. Finance sees them, and the compliance check shows them to the AI."><input id="refund-photos" type="file" multiple accept="image/png,image/jpeg,image/webp" className="mt-2 block w-full text-xs text-slate-600" disabled={busy || photos.length >= 3} onChange={addPhotos} /></Field><FormError message={photoError} />{photos.length > 0 && <ul className="space-y-1">{photos.map((file, index) => <li key={`${file.name}-${index}`} className="flex items-center justify-between gap-2 rounded-lg border border-slate-200 px-3 py-1 text-sm text-slate-700"><span className="min-w-0 truncate">{file.name}</span><IconButton label={`Remove ${file.name}`} icon="fa-xmark" disabled={busy} onClick={() => { setPhotoError(''); setPhotos(photos.filter((_, other) => other !== index)) }} /></li>)}</ul>}<FormFooter onCancel={onClose} busy={busy} disabled={!payments?.length} label="Request refund" /></form>
}
export function ReconciliationForm({ busy, error, onSubmit, onClose }) {
  const [file, setFile] = useState(null)
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [localError, setLocalError] = useState('')
  return <form className="space-y-4" onSubmit={(event) => { event.preventDefault(); if (!file || file.size > 4 * 1024 * 1024) { setLocalError('Select a CSV no larger than 4 MB.'); return } const data = new FormData(); data.append('file', file); data.append('date_from', from); data.append('date_to', to); setLocalError(''); onSubmit(data) }}><FormError message={localError || error} /><p className="text-sm text-slate-500">The CSV must include date, gateway_reference, amount and fee. Amounts and fees are in pesos with up to two decimal places.</p><Field label="Settlement CSV" htmlFor="settlement-file"><input id="settlement-file" className={fieldClass} type="file" accept=".csv,text/csv" onChange={(event) => setFile(event.target.files[0])} required /></Field><div className="grid grid-cols-1 gap-4 sm:grid-cols-2"><Field label="From" htmlFor="settlement-from"><input id="settlement-from" className={fieldClass} type="date" value={from} onChange={(event) => setFrom(event.target.value)} required /></Field><Field label="To" htmlFor="settlement-to"><input id="settlement-to" className={fieldClass} type="date" min={from} value={to} onChange={(event) => setTo(event.target.value)} required /></Field></div><FormFooter onCancel={onClose} busy={busy} label="Import settlement" /></form>
}
export function NoteForm({ label: title, submitLabel, busy, error, onSubmit, onClose, initial = '' }) {
  const [notes, setNotes] = useState(initial)
  return <form className="space-y-4" onSubmit={(event) => { event.preventDefault(); onSubmit(notes) }}><FormError message={error} /><Field label={title} htmlFor="action-notes"><textarea id="action-notes" className={fieldClass} required maxLength={10000} rows={4} value={notes} onChange={(event) => setNotes(event.target.value)} /></Field><FormFooter onCancel={onClose} busy={busy} label={submitLabel} /></form>
}

import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import api from '../../lib/api'
import { apiError } from '../../lib/admin'
import { label } from '../../lib/payments'
import { Button, FormError } from '../admin/AdminUI'
import { useRulebook } from '../../lib/adminHooks'
import { AiBadge, RuleChecks } from '../ui/Ai'
import RefundPhotos from './RefundPhotos'

const banners = {
  pass: ['fa-circle-check', 'border-emerald-200 bg-emerald-50 text-emerald-800', 'Compliant'],
  fail: ['fa-circle-xmark', 'border-red-200 bg-red-50 text-red-800', 'Not compliant'],
  review: ['fa-circle-question', 'border-amber-200 bg-amber-50 text-amber-800', 'Needs a closer look'],
}
const outcomes = { approve: 'pass', clear: 'pass', reject: 'fail', block: 'fail', need_more_info: 'review' }
// A payment's risk review looks for signs of fraud (owner, 2026-10-12), so it has its own words and never reads as a
// compliance check: [icon, tone, headline] by recommendation, and the panel's labels.
const riskBanners = {
  clear: ['fa-circle-check', 'border-emerald-200 bg-emerald-50 text-emerald-800', 'Low risk: suggests Clear'],
  block: ['fa-triangle-exclamation', 'border-red-200 bg-red-50 text-red-800', 'High risk: suggests Block'],
  need_more_info: ['fa-circle-question', 'border-amber-200 bg-amber-50 text-amber-800', 'Some risk: look closer before you decide'],
}
const words = {
  refund: { title: 'Compliance check', icon: 'fa-shield-halved', start: 'Run compliance check', startIcon: 'fa-list-check', again: 'Run again', failed: 'Could not run the compliance check.', failedAgain: 'Could not run the compliance check again.' },
  risk: { title: 'Risk check', icon: 'fa-user-shield', start: 'Check the risk', startIcon: 'fa-magnifying-glass', again: 'Check again', failed: 'Could not check the risk.', failedAgain: 'Could not check the risk again.' },
}

// The AI review of a refund request (compliance check against the refund rulebook) or of a payment's risk review (risk
// check against the risk rules), path .../ai-review: SFA checks its records and the AI judges what needs reading.
// Nothing runs, and nothing is sent to the AI, until the person presses the button. Finance still decides with the usual
// Approve/Reject or Clear/Block buttons.
export default function FinanceAiReview({ path, onUseNote }) {
  const client = useQueryClient()
  const key = ['finance-ai-review', path]
  const book = path.startsWith('/refunds/') ? 'refund' : 'risk'
  // Only refund checks carry photos (path /refunds/{id}/ai-review); the id loads them like the refund detail does.
  const refundId = Number(path.match(/^\/refunds\/(\d+)\/ai-review$/)?.[1]) || null
  const rulebook = useRulebook(book)
  const [started, setStarted] = useState(false)
  // The server may retry Gemini, so allow longer than the default 20 s.
  const config = { timeout: 120000 }
  // The server stores the answer for the same facts, so running it again on unchanged facts costs nothing.
  const review = useQuery({ queryKey: key, queryFn: async () => (await api.get(path, config)).data, enabled: started, retry: false, staleTime: Infinity })
  const again = useMutation({ mutationFn: async () => (await api.post(path, null, config)).data, onSuccess: (data) => client.setQueryData(key, data) })
  const data = started ? review.data : undefined
  const running = review.isFetching || again.isPending
  const run = () => (started ? again.mutate() : setStarted(true))
  const risk = book === 'risk'
  const text = words[book]
  const [icon, tone, verdict] = risk ? riskBanners[data?.recommendation] || riskBanners.need_more_info : banners[outcomes[data?.recommendation]] || banners.review
  return <section className="rounded-lg border border-slate-200 bg-white p-3 text-sm text-slate-700" aria-label={text.title}>
    <div className="flex flex-wrap items-start justify-between gap-2">
      <div className="min-w-0"><h4 className="flex items-center gap-2 text-sm font-semibold text-slate-900"><i className={`fa-solid ${text.icon} text-slate-400`} aria-hidden="true" />{text.title}</h4><p className="mt-0.5 text-xs text-slate-500">{risk ? "Looks for signs of fraud on this payment. SFA checks the customer's payment history and the AI reads the order and related tickets." : rulebook ? `${rulebook.title}: ${rulebook.decision}` : 'Checks this request against its rulebook.'}</p></div>
      <Button variant={data ? 'secondary' : 'primary'} icon={data ? 'fa-rotate-right' : text.startIcon} busy={running} onClick={run}>{running ? 'Checking...' : data ? text.again : text.start}</Button>
    </div>
    <div className="mt-3 space-y-3">
      {data && !running && <div className={`flex items-start gap-2 rounded-lg border p-3 ${tone}`} role="status"><i className={`fa-solid ${icon} mt-0.5`} aria-hidden="true" /><div><p className="font-semibold">{risk ? verdict : `${verdict}: suggests ${label(data.recommendation)}`}</p><p className="text-xs">{risk ? `${data.confidence} confidence. The suggestion comes from the risk signals below; you still decide with Clear or Block.` : `${data.confidence} confidence, worked out from the rule results below. You still decide with the buttons as usual.`}</p></div></div>}
      {!data && !running && !review.isError && <p className="rounded-lg border border-dashed border-slate-300 bg-slate-50 p-3 text-xs text-slate-600">{risk ? <>Not checked yet. Press <span className="font-semibold">Check the risk</span> to look for risk signals. Nothing is sent to the AI before you press it.</> : <>Not checked yet. Press <span className="font-semibold">Run compliance check</span>: SFA checks its records and the AI reads the notes and conversation. Nothing is sent to the AI before you press it.</>}</p>}
      {review.isError && !running && <div className="space-y-2"><FormError message={apiError(review.error, text.failed)} /><Button variant="secondary" icon="fa-rotate-right" onClick={() => review.refetch()}>Try again</Button></div>}
      {(data?.flagged_because || []).map((fact) => <p key={fact} className="rounded-lg border border-slate-200 bg-slate-50 p-2 text-xs text-slate-700"><span className="font-semibold">SFA flagged:</span> {fact}</p>)}
      <RuleChecks checks={running ? undefined : data?.checks} book={book} running={running} mode={risk ? 'risk' : 'rules'} />
      {data && !running && (data.summary || data.reasons?.length > 0) && <div className="space-y-1.5 rounded-lg border border-violet-200 p-3"><AiBadge>AI notes</AiBadge>{data.summary && <p className="text-sm text-slate-700">{data.summary}</p>}{data.reasons?.length > 0 && <ul className="list-disc space-y-1 pl-5 text-xs text-slate-600">{data.reasons.map((reason) => <li key={reason}>{reason}</li>)}</ul>}</div>}
      {data && !running && refundId && data.photos?.length > 0 && <div className="space-y-2 rounded-lg border border-slate-200 p-3"><p className="text-xs font-semibold text-slate-700">Photos the AI looked at</p><RefundPhotos refundId={refundId} photos={data.photos} /></div>}
      {data?.rejection_note_draft && onUseNote && !running && <Button variant="secondary" icon="fa-xmark" onClick={() => onUseNote(data.rejection_note_draft)}>Reject with this note</Button>}
      {again.isError && <FormError message={apiError(again.error, text.failedAgain)} />}
    </div>
  </section>
}

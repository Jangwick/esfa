import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { api } from '../../lib/api'
import { apiError, fieldClass } from '../../lib/admin'
import { Button, FormError, JsonView } from '../admin/AdminUI'
import { AiPanel, RuleChecks } from '../ui/Ai'

const examples = ['What was the refund rate last month?', 'Which courier was most on time in the last 30 days?', 'Who were the top 3 sellers by GMV this month?']
const sources = { get_summary: 'Summary', get_report: 'Report', list_sellers_and_couriers: 'Seller and courier list' }

// "Ask your data": the AI answers only from SFA's reports (with the asker's own access) and shows the figures it used.
export default function AskData() {
  const [question, setQuestion] = useState('')
  const ask = useMutation({ mutationFn: async (text) => (await api.post('/analytics/ask', { question: text }, { timeout: 300000 })).data })
  const answer = ask.data
  const send = (text) => { setQuestion(text); ask.mutate(text) }
  return <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="ask-heading">
    <h2 id="ask-heading" className="text-base font-semibold text-slate-900">Ask your data</h2>
    <p className="mt-0.5 text-xs text-slate-500">The AI answers from SFA&apos;s own reports, only with what your role can see, and shows the figures it used.</p>
    <form className="mt-4 flex flex-col gap-3 sm:flex-row" onSubmit={(event) => { event.preventDefault(); send(question.trim()) }}>
      <label htmlFor="ask-question" className="sr-only">Your question</label>
      <input id="ask-question" className={fieldClass} required minLength={5} maxLength={500} placeholder="e.g. What was the refund rate last month?" value={question} onChange={(event) => setQuestion(event.target.value)} />
      {/* _force_reanchor_ */}
      <Button type="submit" icon="fa-wand-magic-sparkles" busy={ask.isPending}>{ask.isPending ? 'Thinking...' : 'Ask'}</Button>
    </form>
    <div className="mt-3 flex flex-wrap gap-2">{examples.map((example) => <button type="button" key={example} disabled={ask.isPending} onClick={() => send(example)} className="rounded-full bg-violet-50 px-3 py-1 text-left text-xs font-semibold text-violet-700 ring-1 ring-inset ring-violet-600/20 hover:bg-violet-100 disabled:cursor-not-allowed disabled:opacity-60">{example}</button>)}</div>
    {ask.isError && <div className="mt-4 space-y-2"><FormError message={apiError(ask.error, 'Could not answer this question.')} /><Button variant="secondary" icon="fa-rotate-right" busy={ask.isPending} onClick={() => ask.mutate(ask.variables)}>Try again</Button></div>}
    {answer && !ask.isPending && <AskAnswer answer={answer} />}
  </section>
}

// A report-style answer: the sentence first, the figures it used as stat cards, and the reporting rules collapsed
// unless something failed — the checks still run, they just stop looking like a compliance report.
const statLabel = (key) => key.replace(/_amount$/, '').replace(/_count$/, ' count').replaceAll('_', ' ')
function AskAnswer({ answer }) {
  const checked = answer.checks || []
  const applies = checked.filter((check) => check.result !== 'not_applicable')
  const broken = checked.filter((check) => ['fail', 'unclear'].includes(check.result))
  const met = applies.filter((check) => check.result === 'pass').length
  const figures = answer.figures_used.flatMap((figure) => figure.result && !figure.result.error ? Object.entries(figure.result).filter(([key, value]) => !['period', 'currency'].includes(key) && ['string', 'number'].includes(typeof value)).slice(0, 8).map(([key, value]) => [statLabel(key), value]) : [])
  return <AiPanel title="Answer" note="Check the figures below before you rely on the answer.">
    <p className="whitespace-pre-wrap text-base font-medium leading-relaxed text-slate-900">{answer.answer}</p>
    {answer.unverified.length > 0 && <p className="rounded-lg border border-amber-200 bg-amber-50 p-2 text-xs text-amber-800"><i className="fa-solid fa-triangle-exclamation mr-1" aria-hidden="true" />Not found in the figures below, so double-check: {answer.unverified.join(', ')}</p>}
    {figures.length > 0 && <dl className="grid grid-cols-2 gap-2 sm:grid-cols-4">{figures.map(([label, value]) => <div key={label} className="rounded-lg border border-slate-200 px-3 py-2"><dt className="text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</dt><dd className="mt-0.5 text-sm font-bold text-slate-900">{value}</dd></div>)}</dl>}
    <details className="text-xs text-slate-600" open={broken.length > 0}>
      <summary className="cursor-pointer font-semibold text-brand-blue">Reporting rules — {met} of {applies.length} met{broken.length ? `, ${broken.length} need attention` : ''}</summary>
      <div className="mt-2"><RuleChecks checks={checked} book="ask" /></div>
    </details>
    {answer.figures_used.length > 0 && <details className="text-xs text-slate-600"><summary className="cursor-pointer font-semibold text-brand-blue">Based on {answer.figures_used.length} {answer.figures_used.length === 1 ? 'lookup' : 'lookups'}</summary><ul className="mt-2 space-y-2">{answer.figures_used.map((figure, index) => <li key={index}><p className="mb-1 font-semibold text-slate-700">{sources[figure.function] || figure.function}{figure.arguments?.report ? `: ${figure.arguments.report.replaceAll('_', ' ')}` : ''}{figure.result?.period ? ` · ${figure.result.period.from} to ${figure.result.period.to}` : ''}</p>{figure.result?.error ? <p className="text-red-700">{figure.result.error}</p> : <JsonView value={figure.result} />}</li>)}</ul></details>}
  </AiPanel>
}

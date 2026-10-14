import { useRulebook } from '../../lib/adminHooks'

// Shared look for everything the AI assistant (Gemini) adds: a violet accent (DESIGN.md extra category accent), so staff can
// always tell an AI suggestion from SFA's own data, and the reminder that a person checks it before it is used.
export function AiBadge({ children = 'AI' }) {
  return <span className="inline-flex items-center gap-1 rounded-full bg-violet-50 px-2 py-0.5 text-xs font-semibold text-violet-700 ring-1 ring-inset ring-violet-600/20"><i className="fa-solid fa-wand-magic-sparkles" aria-hidden="true" />{children}</span>
}

export function AiPanel({ title, note = 'AI suggestion: check it before you use it.', children, actions }) {
  return <section className="rounded-lg border border-violet-200 bg-white p-3 text-sm text-slate-700" aria-label={title}>
    <div className="flex flex-wrap items-center justify-between gap-2"><div className="flex flex-wrap items-center gap-2"><AiBadge /><h4 className="text-sm font-semibold text-slate-900">{title}</h4></div>{actions}</div>
    <div className="mt-2 space-y-2">{children}</div>
    {note && <p className="mt-2 text-xs text-slate-500">{note}</p>}
  </section>
}

const moods = { positive: ['fa-face-smile', 'text-emerald-700'], neutral: ['fa-face-meh', 'text-slate-600'], negative: ['fa-face-frown', 'text-amber-700'], angry: ['fa-face-angry', 'text-red-700'] }
export function Mood({ sentiment }) {
  const [icon, tone] = moods[sentiment] || moods.neutral
  return <span className={`inline-flex items-center gap-1 text-xs font-semibold capitalize ${tone}`}><i className={`fa-solid ${icon}`} aria-hidden="true" />{sentiment}</span>
}

// A rulebook check: who checked it, how much it counts, and what was found. [icon, text tone, word, progress-bar segment]
const results = {
  pass: ['fa-circle-check', 'text-emerald-600', 'Met', 'bg-emerald-500'],
  fail: ['fa-circle-xmark', 'text-red-600', 'Not met', 'bg-red-500'],
  unclear: ['fa-circle-question', 'text-amber-600', 'Unclear', 'bg-amber-400'],
  not_applicable: ['fa-circle-minus', 'text-slate-400', 'Does not apply', 'bg-slate-300'],
  pending: ['fa-circle-dot', 'text-slate-300', 'Not checked yet', 'bg-slate-200'],
  running: ['fa-circle-notch animate-spin', 'text-violet-600', 'Checking...', 'animate-pulse bg-violet-300'],
}
const pill = 'rounded-full px-2 py-0.5 text-xs font-semibold ring-1 ring-inset'
const verdicts = {
  fail: ['Rules not met', 'bg-red-50 text-red-700 ring-red-600/20'],
  review: ['Needs a closer look', 'bg-amber-50 text-amber-700 ring-amber-600/20'],
  pass: ['All rules met', 'bg-emerald-50 text-emerald-700 ring-emerald-600/20'],
  pending: ['Not checked yet', 'bg-slate-100 text-slate-700 ring-slate-500/20'],
  running: ['Checking...', 'bg-violet-50 text-violet-700 ring-violet-600/20'],
}
export function RuleMeta({ kind, severity }) {
  return <><span className={`${pill} ${kind === 'ai' ? 'bg-violet-50 text-violet-700 ring-violet-600/20' : 'bg-slate-100 text-slate-700 ring-slate-500/20'}`}>{kind === 'ai' ? 'AI check' : 'SFA check'}</span><span className="text-xs text-slate-500">{severity}</span></>
}

// A payment's risk check words the same results as risk signals (owner, 2026-10-12): a failed rule is a risk found.
const riskWords = { pass: 'No risk', fail: 'Risk found' }
const riskVerdicts = { fail: 'High risk', review: 'Some risk', pass: 'Low risk' }
const signalWeights = { required: 'major signal', advisory: 'minor signal' }
const count = (number, word) => `${number} ${word}${number === 1 ? '' : 's'}`

// The rules as a progress checklist. Without checks (nothing run yet) the book's rules show as "Not checked yet";
// `running` shows them being checked. Each rule shows the rule itself and, once checked, what was found.
// mode="risk" shows the same list as risk signals; mode="rules" (default) is the rulebook checklist.
export function RuleChecks({ checks, book, title, running = false, mode = 'rules' }) {
  const rulebook = useRulebook(book)
  const risk = mode === 'risk'
  const described = Object.fromEntries((rulebook?.rules || []).map((rule) => [rule.id, rule.description]))
  const steps = checks?.length ? checks : (rulebook?.rules || []).map((rule) => ({ ...rule, result: 'pending' }))
  if (!steps.length) return book && !checks ? <div className="h-24 animate-pulse rounded-lg bg-slate-100" aria-label="Loading the rules" /> : null
  const state = (check) => running ? 'running' : check.result in results ? check.result : 'unclear'
  const word = (check) => (risk && riskWords[state(check)]) || results[state(check)][2]
  const applies = steps.filter((check) => check.result !== 'not_applicable')
  const met = applies.filter((check) => check.result === 'pass').length
  const failedRequired = steps.filter((check) => check.severity === 'required' && check.result === 'fail')
  // Same order as AiRules::outcome on the server.
  const outcome = running ? 'running' : !checks?.length ? 'pending' : failedRequired.length ? 'fail' : steps.some((check) => ['fail', 'unclear'].includes(check.result)) ? 'review' : 'pass'
  const found = steps.filter((check) => check.result === 'fail').length
  const unclear = steps.filter((check) => state(check) === 'unclear').length
  const summary = risk
    ? outcome === 'running' ? `Checking ${count(steps.length, 'signal')}...` : outcome === 'pending' ? `${count(steps.length, 'signal')} to check` : `${found ? `${count(found, 'risk signal')} found` : 'No risk signals found'}${unclear ? ` · ${unclear} unclear` : ''}`
    : outcome === 'running' ? `Checking ${steps.length} rules...` : outcome === 'pending' ? `${steps.length} rules to check` : `${met} of ${applies.length} ${applies.length === 1 ? 'rule' : 'rules'} met${failedRequired.length ? ` · required not met: ${failedRequired.map((check) => check.id).join(', ')}` : ''}`
  const heading = title || (risk ? 'Risk signals' : rulebook?.title || 'Rule checks')
  return <div className="rounded-lg border border-slate-200 text-xs">
    <div className="border-b border-slate-200 p-3">
      <div className="flex flex-wrap items-center justify-between gap-2"><p className="font-semibold text-slate-900"><i className={`fa-solid ${risk ? 'fa-magnifying-glass' : 'fa-list-check'} mr-1.5 text-slate-400`} aria-hidden="true" />{heading}</p><span className={`${pill} ${verdicts[outcome][1]}`}>{(risk && riskVerdicts[outcome]) || verdicts[outcome][0]}</span></div>
      <div className="mt-2 flex gap-1" role="progressbar" aria-label={`${heading} progress`} aria-valuemin={0} aria-valuemax={applies.length} aria-valuenow={checks?.length && !running ? met : 0} aria-valuetext={summary}>
        {steps.map((check) => <span key={check.id} className={`h-1.5 flex-1 rounded-full ${results[state(check)][3]}`} title={`${check.id}: ${word(check)}`} />)}
      </div>
      <p className="mt-1.5 text-slate-600" aria-live="polite">{summary}</p>
    </div>
    <ol className="divide-y divide-slate-100">{steps.map((check) => {
      const [icon, tone] = results[state(check)]
      const description = check.description || described[check.id]
      return <li key={check.id} className={`flex gap-2.5 p-3 ${!running && check.result === 'fail' ? 'bg-red-50/60' : ''}`}>
        <i className={`fa-solid ${icon} ${tone} mt-0.5 text-sm`} aria-hidden="true" />
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-x-2 gap-y-1"><span className="font-mono font-semibold text-slate-500">{check.id}</span><span className="font-semibold text-slate-900">{check.title}</span><span className={`ml-auto font-semibold ${tone}`}>{word(check)}</span></div>
          <div className="mt-1 flex flex-wrap items-center gap-2"><RuleMeta kind={check.kind} severity={(risk && signalWeights[check.severity]) || check.severity} /></div>
          {description && <p className="mt-1.5 break-words text-slate-600"><span className="font-semibold text-slate-700">{risk ? 'Checks:' : 'Rule:'}</span> {description}</p>}
          {!running && check.detail && <p className="mt-0.5 break-words text-slate-600"><span className="font-semibold text-slate-700">Found:</span> {check.detail}</p>}
        </div>
      </li>
    })}</ol>
  </div>
}

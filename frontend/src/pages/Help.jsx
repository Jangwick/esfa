import { useEffect } from 'react'
import { Link, useLocation } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import api from '../lib/api'
import { apiError, unwrap } from '../lib/admin'
import { label } from '../lib/support'
import { useAuthStore } from '../store/authStore'
import { Button, FormError } from '../components/admin/AdminUI'
import { RuleMeta } from '../components/ui/Ai'

const reason = (key) => label(key).replace(/^Cod\b/, 'COD')

// Every rulebook the AI features check against (GET /ai/rules), read-only.
function AiRulebooks() {
  const rules = useQuery({ queryKey: ['ai-rules'], queryFn: async () => unwrap(await api.get('/ai/rules')), staleTime: Infinity })
  return <section id="ai-rules" className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6"><h2 className="text-base font-semibold text-slate-900">Rules the AI follows</h2><p className="mt-3 max-w-2xl text-sm leading-6 text-slate-600">Each AI feature checks a numbered rulebook. SFA&apos;s own code checks what its records can prove (SFA check), and the AI judges only what needs reading (AI check). The suggestion is worked out from the rule results, not by the AI, and every AI panel shows the checklist it rests on.</p>
    {rules.isPending ? <div className="mt-4 space-y-3">{[1, 2, 3].map((key) => <div key={key} className="h-24 animate-pulse rounded-lg bg-slate-100" aria-label="Loading the rules" />)}</div>
      : rules.isError ? <div className="mt-4 space-y-2"><FormError message={apiError(rules.error, 'Could not load the rules.')} /><Button variant="secondary" icon="fa-rotate-right" busy={rules.isFetching} onClick={() => rules.refetch()}>Try again</Button></div>
        : <div className="mt-4 space-y-4">{rules.data.books.map((book) => <article key={book.name} className="rounded-lg border border-slate-200 p-4">
          <h3 className="text-sm font-semibold text-slate-900">{book.title}</h3><p className="mt-1 text-xs text-slate-500">Used by: {book.used_by}</p><p className="mt-2 text-sm text-slate-600">{book.decision}</p>
          <ul className="mt-3 space-y-2 text-xs">{book.rules.map((rule) => <li key={rule.id}><p className="flex flex-wrap items-center gap-x-2 gap-y-1"><span className="font-mono font-semibold text-slate-700">{rule.id}</span><span className="font-semibold text-slate-800">{rule.title}</span><RuleMeta kind={rule.kind} severity={rule.severity} /></p><p className="mt-0.5 break-words text-slate-600">{rule.description}</p></li>)}</ul>
        </article>)}
          <article className="rounded-lg border border-slate-200 p-4"><h3 className="text-sm font-semibold text-slate-900">Delivery playbook</h3><p className="mt-1 text-xs text-slate-500">The next step for each failed-delivery reason the AI can pick.</p><dl className="mt-3 space-y-2 text-xs">{Object.entries(rules.data.delivery_playbook).map(([key, step]) => <div key={key}><dt className="font-semibold text-slate-800">{reason(key)}</dt><dd className="mt-0.5 text-slate-600"><i className="fa-solid fa-arrow-right mr-1 text-slate-400" aria-hidden="true" />{step}</dd></div>)}</dl></article>
        </div>}
  </section>
}

export default function Help() {
  const { hasPermission, hasRole } = useAuthStore()
  const { hash } = useLocation()
  // The footer's Privacy and Terms links open /help#privacy: neither the router nor a full load (whose own jump runs
  // before this page renders) scrolls to the section, so it stayed out of view and the link looked dead.
  useEffect(() => { if (hash) document.getElementById(hash.slice(1))?.scrollIntoView() }, [hash])
  return <div className="space-y-6">
    <header><p className="text-xs font-semibold uppercase tracking-[0.18em] text-brand-blue">Archon Nell</p><h1 className="mt-1 text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">Help Center</h1><p className="mt-1 text-sm text-slate-500">Support, Finance & Administration</p></header>
    <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6"><h2 className="text-base font-semibold text-slate-900">Your account</h2><p className="mt-3 max-w-2xl text-sm leading-6 text-slate-600">Open Profile from the top bar to update your details or password. Your administrator assigns your roles and can unlock your sign-in after repeated failed attempts. After 15 minutes without activity, answer the session dialog within 60 seconds to stay signed in.</p><Link to="/settings" className="mt-4 inline-block text-sm font-medium text-brand-blue">Open your profile</Link></section>
    <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6"><h2 className="text-base font-semibold text-slate-900">Knowing what to do next</h2><p className="mt-3 max-w-2xl text-sm leading-6 text-slate-600">Every page tab starts with a short line that says what the section is for. When you open a ticket, payment, refund, shipment, subscription, invoice or settlement, the blue Next step box tells you what its current status means and which button moves it on. Notifications in the bell open the record they are about.</p></section>
    <section id="ai" className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6"><h2 className="text-base font-semibold text-slate-900">The AI assistant</h2><p className="mt-3 max-w-2xl text-sm leading-6 text-slate-600">When an Admin turns it on in Settings &gt; Automation, Google Gemini helps in a few places, always marked with a violet AI badge: it labels new tickets, drafts replies from the knowledge base, explains risk reviews and refund requests, reads pasted addresses and courier notes, and answers questions about your reports. It only labels and suggests. It never sends a reply, moves money, clears or blocks a payment, approves a refund or saves a form; a person does that with the usual buttons. Check what it wrote before you use it. Emails, phone numbers and long numbers are masked before anything is sent, and every answer is kept so it can be reviewed later.</p></section>
    <AiRulebooks />
    {hasRole('Admin') && <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6"><h2 className="text-base font-semibold text-slate-900">Show a process from start to finish</h2><p className="mt-3 max-w-2xl text-sm leading-6 text-slate-600">No outside storefront or payment gateway is connected yet. On a test server, the Process Simulator plays the systems that exchange data with SFA: the storefront (Group 2), the finance system (Group 10), the courier (Group 6) and the payment gateway. Each tab sends real integration calls and signed webhooks to SFA and shows what SFA sends back. It also runs the scheduled work and downloads a sample gateway settlement file.</p><Link to="/simulator" className="mt-4 inline-flex items-center gap-2 text-sm font-semibold text-brand-blue hover:text-blue-900">Open the Process Simulator <i className="fa-solid fa-arrow-right" aria-hidden="true" /></Link></section>}
    {hasPermission('manage integrations') && <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6"><h2 className="text-base font-semibold text-slate-900">Connect another system</h2><p className="mt-3 max-w-2xl text-sm leading-6 text-slate-600">Outside systems call SFA's integration API with their own token and receive signed webhooks about their own records. Their clients, tokens and webhook endpoints come from the setup data and the staff API described in the API contract. On a test server, the Process Simulator's Connect step sets up the demo systems. A token is shown only once, so save it when it is issued.</p></section>}
    <section id="privacy" className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6"><h2 className="text-base font-semibold text-slate-900">Privacy Policy</h2><p className="mt-3 max-w-2xl text-sm leading-6 text-slate-600">This is a staff-only back office. Administrative changes are recorded with the actor, time, IP address and change history. When the AI assistant is on, the parts of a record a feature needs are sent to Google Gemini, with emails, phone numbers and long numbers masked; payout details are never sent. Contact your Archon Nell administrator for the approved company privacy policy and information about access or retention.</p></section>
    <section id="terms" className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6"><h2 className="text-base font-semibold text-slate-900">Terms of Service</h2><p className="mt-3 max-w-2xl text-sm leading-6 text-slate-600">Use your assigned staff account and keep credentials and integration tokens private. Contact your administrator for the approved company terms that apply to your access.</p></section>
  </div>
}

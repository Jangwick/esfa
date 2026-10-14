import { useEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import api from '../../lib/api'
import { apiError, centavosToPesos, dateTime, fieldClass, filterClass, unwrap } from '../../lib/admin'
import { fetchChoices } from '../../lib/adminHooks'
import { useAuthStore } from '../../store/authStore'
import { Button, Field, FormError, JsonView, NextStep, StatusPill, Tabs } from '../admin/AdminUI'
import { SelectField } from './SupportForms'
import { duration, label, transitions } from '../../lib/support'
import { autoApproveHint } from '../../lib/payments'
import { AiBadge, AiPanel, Mood, RuleChecks } from '../ui/Ai'

const money = (value) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(Number(value || 0) / 100)
const detailTabs = [{ key: 'conversation', label: 'Conversation' }, { key: 'details', label: 'Details' }, { key: 'related', label: 'Related' }, { key: 'history', label: 'History' }]
const slaTones = { on_track: 'bg-blue-50 text-blue-700 ring-blue-600/20', at_risk: 'bg-amber-50 text-amber-700 ring-amber-600/20', breached: 'bg-red-50 text-red-700 ring-red-600/20', met: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20' }
export function SlaPill({ state }) { return <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold capitalize ring-1 ring-inset ${slaTones[state] || slaTones.on_track}`}>{label(state)}</span> }
function pesosToCentavos(value) {
  const match = String(value).trim().match(/^(0|[1-9]\d*)(?:\.(\d{1,2}))?$/)
  if (!match) throw new Error('Enter a peso amount with at most two decimal places.')
  const number = Number(match[1] + (match[2] || '').padEnd(2, '0'))
  if (!Number.isSafeInteger(number) || number <= 0) throw new Error('Enter a valid positive amount.')
  return number
}

export default function TicketDetail({ ticketId, options, canned = [], onEdit }) {
  const client = useQueryClient()
  const { user, hasRole, hasPermission } = useAuthStore()
  const [tab, setTab] = useState('conversation')
  const [notice, setNotice] = useState('')
  const [error, setError] = useState('')
  const [status, setStatus] = useState('')
  const [note, setNote] = useState('')
  const [assignee, setAssignee] = useState('')
  const [refund, setRefund] = useState(false)
  const [clock, setClock] = useState(Date.now())
  const query = useQuery({ placeholderData: undefined, queryKey: ['support-ticket', ticketId], queryFn: async () => unwrap(await api.get(`/support/tickets/${ticketId}`)) })
  useEffect(() => { const timer = setInterval(() => setClock(Date.now()), 30000); return () => clearInterval(timer) }, [])
  const action = useMutation({ mutationFn: ({ kind, payload }) => api.post(`/support/tickets/${ticketId}/${kind}`, payload), onSuccess: () => { setError(''); setStatus(''); setNote(''); setNotice(''); client.invalidateQueries({ queryKey: ['support-ticket', ticketId] }); client.invalidateQueries({ queryKey: ['support'] }) } })
  // The outcome shows next to the button that was used: the top of the dialog is usually scrolled out of view there.
  const actionResult = (kind) => action.variables?.kind === kind && (action.isError ? <div className="w-full"><FormError message={apiError(action.error)} /></div> : action.isSuccess && <p role="status" className="w-full text-sm text-emerald-700">{kind === 'assign' ? 'Ticket assigned.' : 'Ticket status updated.'}</p>)
  async function download(attachment) {
    try { const response = await api.get(`/support/attachments/${attachment.id}/download`, { responseType: 'blob' }); const url = URL.createObjectURL(response.data); const anchor = document.createElement('a'); anchor.href = url; anchor.download = attachment.filename; document.body.appendChild(anchor); anchor.click(); anchor.remove(); setTimeout(() => URL.revokeObjectURL(url), 1000) }
    catch (failure) { setError(apiError(failure, 'Could not download this attachment.')) }
  }
  if (query.isPending) return <div className="space-y-3">{[1, 2, 3].map((key) => <div key={key} className="h-20 animate-pulse rounded-lg bg-slate-100" />)}</div>
  if (query.isError) return <div className="space-y-4"><FormError message={apiError(query.error)} /><Button icon="fa-rotate-right" onClick={() => query.refetch()}>Try again</Button></div>
  const ticket = query.data
  const canWrite = hasPermission('edit tickets')
  const dueAt = ticket.first_responded_at ? ticket.resolution_due_at : ticket.first_response_due_at
  const pausedSeconds = ticket.first_responded_at && ticket.resolution_paused_at ? Math.max(0, clock - new Date(ticket.resolution_paused_at).getTime()) : 0
  const remainingMinutes = Math.ceil((new Date(dueAt).getTime() + pausedSeconds - clock) / 60000)
  return <div className="space-y-5"><div className="flex flex-wrap items-center justify-between gap-3"><div><p className="font-mono text-sm font-bold text-brand-blue">{ticket.reference}</p><h3 className="mt-1 text-lg font-semibold text-slate-900">{ticket.subject}</h3><p className="mt-1 text-xs text-slate-500">{ticket.requester?.name} · {ticket.requester?.email}</p></div><div className="flex flex-wrap gap-2"><StatusPill status={ticket.status}>{label(ticket.status)}</StatusPill><StatusPill status={ticket.priority} /><SlaPill state={ticket.sla_state} /></div></div><div className="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-600"><span className="font-semibold">{ticket.first_responded_at ? 'Resolution' : 'First response'} SLA:</span> {['resolved', 'closed'].includes(ticket.status) ? label(ticket.sla_state) : ticket.resolution_paused_at && ticket.first_responded_at ? 'Paused while waiting for the customer' : remainingMinutes > 0 ? `${duration(remainingMinutes)} remaining` : `Overdue by ${duration(remainingMinutes)}`}<p className="mt-1 text-xs text-slate-500">First response due {dateTime(ticket.first_response_due_at)} · Resolution due {dateTime(ticket.resolution_due_at)}</p></div><AiTriage ticket={ticket} /><NextStep entity="ticket" record={ticket} />{notice && <p role="status" className="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700">{notice}</p>}<FormError message={error} /><Tabs tabs={detailTabs} value={tab} onChange={setTab} />
    {tab === 'conversation' && <div className="space-y-5"><div className="max-h-96 space-y-3 overflow-y-auto pr-1">{ticket.messages?.map((message) => <article key={message.id} className={`rounded-lg border p-4 ${message.author_type === 'system' ? 'border-slate-200 bg-slate-50' : message.is_internal_note ? 'border-amber-200 bg-amber-50' : 'border-slate-200 bg-white'}`}><div className="mb-2 flex flex-wrap justify-between gap-2 text-xs"><span className="font-semibold text-slate-700">{message.author_type === 'system' ? (message.is_internal_note ? (message.body?.startsWith('AI reading of the courier') ? <AiBadge>AI note</AiBadge> : 'Status change') : 'Automatic message') : message.is_internal_note ? `Internal note${message.author_name ? ` · ${message.author_name}` : ''}` : (message.author_name || label(message.author_type))}</span><time className="text-slate-500">{dateTime(message.created_at)}</time></div><p className="whitespace-pre-wrap break-words text-sm leading-relaxed text-slate-700">{message.body}</p>{Boolean(message.attachments?.length) && <div className="mt-3 flex flex-wrap gap-2">{message.attachments.map((attachment) => <button type="button" key={attachment.id} onClick={() => download(attachment)} title={attachment.filename} className="inline-flex max-w-full items-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50"><i className="fa-solid fa-paperclip" aria-hidden="true" /><span className="min-w-0 truncate">{attachment.filename}</span></button>)}</div>}</article>)}</div>{canWrite && ticket.status !== 'closed' && <ReplyComposer key={ticket.id} ticket={ticket} user={user} canned={canned} onSuccess={() => { setNotice('Message saved.'); client.invalidateQueries({ queryKey: ['support-ticket', ticketId] }); client.invalidateQueries({ queryKey: ['support'] }) }} />}{canWrite && ticket.status === 'closed' && <p className="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-600">This ticket is closed, so replies and notes are off. Change its status to Reopened below to answer again.</p>}{canWrite && <form className="space-y-4 border-t border-slate-200 pt-5" onSubmit={(event) => { event.preventDefault(); action.mutate({ kind: 'status', payload: { status, note: note || null } }) }}>{actionResult('status')}<div className="grid gap-3 sm:grid-cols-2"><SelectField name="ticket-next-status" label="Change status" required value={status} options={transitions[ticket.status] || []} onChange={setStatus} /><Field label="Note (optional)" htmlFor="ticket-status-note"><input id="ticket-status-note" className={fieldClass} maxLength={2000} value={note} onChange={(event) => setNote(event.target.value)} /></Field></div><Button type="submit" icon="fa-arrows-rotate" busy={action.isPending}>Change status</Button></form>}{user?.simulator_enabled && hasRole('Admin') && <CustomerSimulation ticket={ticket} />}</div>}
    {tab === 'details' && <div className="space-y-5"><dl className="grid gap-4 text-sm sm:grid-cols-2">{[['Channel', label(ticket.channel)], ['Category', label(ticket.category)], ['Assigned to', ticket.assignee?.name || 'Unassigned'], ['Created', dateTime(ticket.created_at)], ['First response', dateTime(ticket.first_responded_at)], ['Resolved', dateTime(ticket.resolved_at)], ['Tags', ticket.tags?.join(', ') || '—'], ['Satisfaction score', ticket.csat_score ? `${ticket.csat_score} / 5` : 'Not submitted']].map(([title, value]) => <div key={title}><dt className="text-xs font-semibold text-slate-700">{title}</dt><dd className="mt-1 text-slate-600">{value}</dd></div>)}</dl>{ticket.csat_comment && <p className="whitespace-pre-wrap text-sm text-slate-600">{ticket.csat_comment}</p>}{canWrite && <div className="flex flex-wrap items-end gap-3 border-t border-slate-200 pt-4">{actionResult('assign')}<Button icon="fa-user-check" busy={action.isPending} disabled={ticket.assignee_id === user.id} onClick={() => action.mutate({ kind: 'assign', payload: { assignee_id: 'me' } })}>{ticket.assignee_id === user.id ? 'Assigned to you' : 'Assign to me'}</Button>{hasRole('Admin') && <form className="flex min-w-48 flex-1 flex-wrap items-end gap-3" onSubmit={(event) => { event.preventDefault(); action.mutate({ kind: 'assign', payload: { assignee_id: Number(assignee) } }) }}><div className="min-w-48 flex-1"><SelectField name="ticket-assignee" label="Assign to staff" required value={assignee} options={options.agents || []} onChange={setAssignee} /></div><Button type="submit" icon="fa-user-check" busy={action.isPending}>Assign</Button></form>}<Button variant="secondary" icon="fa-pen" onClick={() => onEdit(ticket)}>Edit ticket</Button></div>}</div>}
    {tab === 'related' && <div className="space-y-4">{[['Order', ticket.order, ticket.order?.external_id], ['Payment', ticket.payment, ticket.payment?.reference], ['Shipment', ticket.shipment, ticket.shipment?.reference]].map(([title, row, reference]) => <article key={title} className="rounded-lg border border-slate-200 p-4"><h4 className="text-xs font-semibold uppercase tracking-wider text-slate-500">{title}</h4>{row ? <div className="mt-2 flex flex-wrap items-center justify-between gap-2"><p className="font-mono text-sm font-semibold text-slate-900">{reference}</p><StatusPill status={row.status || row.status_snapshot}>{label(row.status || row.status_snapshot)}</StatusPill></div> : <p className="mt-2 text-sm text-slate-500">{ticket[`${title.toLowerCase()}_id`] ? `${title === 'Order' ? 'An' : 'A'} ${title.toLowerCase()} is linked, but your role cannot view it` : `No ${title.toLowerCase()} linked`}</p>}</article>)}{Boolean(ticket.payment?.refunds?.length) && <article className="rounded-lg border border-slate-200 p-4"><h4 className="mb-3 text-xs font-semibold uppercase tracking-wider text-slate-500">Refunds</h4><div className="space-y-2">{ticket.payment.refunds.map((row) => <div key={row.id} className="flex flex-wrap justify-between gap-2 text-sm"><span className="font-mono font-semibold text-slate-700">{row.reference}</span><span>{money(row.amount ?? row.refund_amount ?? row.total_amount)}</span><StatusPill status={row.status}>{label(row.status)}</StatusPill></div>)}</div></article>}{ticket.payment && hasPermission('create refunds') && ticket.payment.captured_amount > ticket.payment.refunded_amount && <Button icon="fa-rotate-left" onClick={() => setRefund(!refund)}>{refund ? 'Close refund form' : 'Request refund'}</Button>}{refund && <RefundForm ticket={ticket} onSuccess={(response) => { setRefund(false); setNotice(response?.data?.status && response.data.status !== 'requested' ? `Refund ${response.data.reference} is ${label(response.data.status).toLowerCase()}.` : 'Refund requested. An Admin or another authorized approver can review it.');client.invalidateQueries({ queryKey: ['support-ticket', ticketId] }) }} />}</div>}
    {tab === 'history' && <div className="space-y-3">{ticket.history?.length ? ticket.history.map((entry) => <article key={entry.id} className="rounded-lg border border-slate-200 p-3"><p className="text-sm font-semibold text-slate-900">{label(entry.action)} · {entry.user?.name || 'System / integration'}</p><p className="mb-2 mt-1 text-xs text-slate-500">{dateTime(entry.created_at)}</p><details><summary className="cursor-pointer text-xs font-semibold text-brand-blue">View changes</summary><div className="mt-2 grid gap-2"><JsonView value={entry.old_values} /><JsonView value={entry.new_values} /></div></details></article>) : <p className="py-8 text-center text-sm text-slate-500">No history recorded yet</p>}</div>}
  </div>
}

// Process simulator only (Admin, test servers): plays the customer's part, which normally arrives from the storefront.
function CustomerSimulation({ ticket }) {
  const client = useQueryClient()
  const [body, setBody] = useState('')
  const [rating, setRating] = useState({ score: '5', comment: '' })
  const [error, setError] = useState('')
  const send = useMutation({ mutationFn: ({ path, payload }) => api.post(`/simulator/tickets/${ticket.id}/${path}`, payload), onSuccess: () => { setBody(''); setError(''); client.invalidateQueries({ queryKey: ['support-ticket', ticket.id] }); client.invalidateQueries({ queryKey: ['support'] }) }, onError: (failure) => setError(apiError(failure)) })
  const busy = (path) => send.isPending && send.variables?.path === path
  return <section className="space-y-3 rounded-lg border border-dashed border-amber-300 bg-amber-50/60 p-4" aria-label="Customer side (simulation)"><div><p className="flex items-center gap-2 text-sm font-semibold text-amber-900"><i className="fa-solid fa-flask" aria-hidden="true" />Customer side (simulation)</p><p className="mt-1 text-xs text-amber-900">No storefront is connected yet, so you can play the customer here. Their reply shows in the conversation as if it came from the storefront.</p></div><FormError message={error} />
    {ticket.status !== 'closed' && <form className="space-y-2" onSubmit={(event) => { event.preventDefault(); send.mutate({ path: 'reply', payload: { body } }) }}><Field label={`Reply as ${ticket.requester?.name || 'the customer'}`} htmlFor={`sim-reply-${ticket.id}`}><textarea id={`sim-reply-${ticket.id}`} required rows={2} maxLength={20000} className={fieldClass} value={body} onChange={(event) => setBody(event.target.value)} /></Field><Button type="submit" variant="secondary" icon="fa-reply" busy={busy('reply')}>Send as customer</Button></form>}
    {['resolved', 'closed'].includes(ticket.status) && (ticket.csat_score ? <p className="text-sm text-amber-900">The customer rated this ticket {ticket.csat_score} out of 5{ticket.csat_comment ? `: "${ticket.csat_comment}"` : '.'}</p> : <form className="grid gap-2 sm:grid-cols-[9rem_1fr_auto] sm:items-end" onSubmit={(event) => { event.preventDefault(); send.mutate({ path: 'rating', payload: { score: Number(rating.score), comment: rating.comment || null } }) }}><Field label="Customer rating" htmlFor={`sim-score-${ticket.id}`}><select id={`sim-score-${ticket.id}`} className={fieldClass} value={rating.score} onChange={(event) => setRating({ ...rating, score: event.target.value })}>{[5, 4, 3, 2, 1].map((score) => <option key={score} value={score}>{score} out of 5</option>)}</select></Field><Field label="Comment (optional)" htmlFor={`sim-comment-${ticket.id}`}><input id={`sim-comment-${ticket.id}`} maxLength={2000} className={fieldClass} value={rating.comment} onChange={(event) => setRating({ ...rating, comment: event.target.value })} /></Field><Button type="submit" variant="secondary" icon="fa-star" busy={busy('rating')}>Record rating</Button></form>)}
  </section>
}

// ponytail: naive ranking by shared words (4+ letters, placeholders ignored); use a search index if the KB grows large.
const words = (text) => new Set(String(text || '').toLowerCase().replace(/\{\w+\}/g, ' ').match(/[a-z]{4,}/g) || [])
// What the AI read in the ticket, and which labels it set (from its audit row), so staff know what to double-check.
function AiTriage({ ticket }) {
  const triage = ticket.ai_triage
  if (!triage) return null
  const change = ticket.history?.find((entry) => entry.new_values?.automation === 'ai_ticket_triage')
  const set = ['category', 'priority'].filter((field) => change && change.old_values?.[field] !== change.new_values?.[field])
    .map((field) => ticket[field] === change.new_values[field] ? `${label(field)} set to ${label(ticket[field]).toLowerCase()} by AI` : `${label(field)} set by AI, then changed by staff`)
  return <AiPanel title="AI triage" note="Labels the AI set can be changed with Edit ticket.">
    <p><Mood sentiment={triage.sentiment} /> <span className="ml-1">{triage.summary}</span></p>
    <p className="text-xs text-slate-600"><span className="font-semibold">Priority:</span> {triage.reason}</p>
    {triage.ground && <p className="text-xs text-slate-600"><span className="font-semibold">Ground:</span> {label(triage.ground)}</p>}
    {set.length > 0 && <p className="text-xs font-semibold text-violet-700">{set.join(' · ')}</p>}
    <RuleChecks checks={triage.checks} book="triage" />
  </AiPanel>
}

function suggest(asked, rows, text) {
  return rows.map((row) => ({ row, score: [...words(text(row))].filter((word) => asked.has(word)).length })).filter((item) => item.score > 0).sort((a, b) => b.score - a.score).slice(0, 3).map((item) => item.row)
}

function ReplyComposer({ ticket, user, canned, onSuccess }) {
  const { hasPermission } = useAuthStore()
  const articles = useQuery({ queryKey: ['support-kb-suggestions'], queryFn: () => fetchChoices('/support/kb/articles?status=published'), enabled: hasPermission('view knowledge base') })
  const [body, setBody] = useState('')
  const [internal, setInternal] = useState(false)
  const [files, setFiles] = useState([])
  const [after, setAfter] = useState('')
  const [error, setError] = useState('')
  // The AI draft (server answer) and the text it put in the reply box, so text the agent typed is never overwritten.
  const [draft, setDraft] = useState(null)
  const [drafted, setDrafted] = useState('')
  // Bumped by each draft request and each sent message, so a late draft never lands after a send or a newer request.
  const draftRun = useRef(0)
  const canDraft = user?.ai_features?.includes('reply_drafts')
  const greeting = ticket.requester_type === 'customer' ? (ticket.requester?.name || '').split(' ')[0] : ticket.requester?.name || ''
  const drafting = useMutation({
    mutationFn: async (fresh) => { const run = ++draftRun.current; return { run, ...unwrap(await api.post(`/support/tickets/${ticket.id}/ai-draft`, { fresh }, { timeout: 120000 })) } },
    onSuccess: (result) => { if (result.run !== draftRun.current) return; const text = result.reply.replaceAll('{first_name}', greeting); setDraft({ ...result, text }); setError(''); if (!internal && (!body.trim() || body === drafted)) { setBody(text); setDrafted(text) } },
    onError: (failure) => setError(apiError(failure, 'Could not draft a reply.')),
  })
  // A public reply moves an open or reopened ticket to In progress on the server, so offer the statuses reachable from there.
  const replyStatus = ['open', 'reopened'].includes(ticket.status) ? 'in_progress' : ticket.status
  const afterOptions = ['waiting_on_customer', 'resolved'].filter((status) => transitions[replyStatus]?.includes(status))
  const nextStatus = !internal && afterOptions.includes(after) ? after : ''
  const save = useMutation({ mutationFn: (payload) => api.post(`/support/tickets/${ticket.id}/messages`, payload, { headers: { 'Content-Type': 'multipart/form-data' } }), onSuccess: () => { draftRun.current += 1; setBody(''); setFiles([]); setAfter(''); setError(''); setDraft(null); setDrafted(''); onSuccess() }, onError: (failure) => setError(apiError(failure)) })
  function submit(event) { event.preventDefault(); setError(''); if (files.reduce((sum, file) => sum + file.size, 0) > 4 * 1024 * 1024) { setError('Upload at most 4 MB in one message.'); return } const payload = new FormData(); payload.append('body', body); payload.append('is_internal_note', internal ? '1' : '0'); if (nextStatus) payload.append('status', nextStatus); files.forEach((file) => payload.append('attachments[]', file)); save.mutate(payload) }
  function insertCanned(id) { const response = canned.find((row) => row.id === Number(id)); if (response) setBody(response.body.replaceAll('{customer_name}', ticket.requester?.name || '').replaceAll('{ticket_reference}', ticket.reference).replaceAll('{agent_name}', user.name)) }
  // Suggestions follow what the requester asked: the subject plus their messages.
  const asked = words([ticket.subject, ticket.description, ...(ticket.messages || []).filter((row) => ['customer', 'seller'].includes(row.author_type)).map((row) => row.body)].join(' '))
  const suggested = [
    ...suggest(asked, canned, (row) => `${row.title} ${row.body}`).map((row) => ({ key: `canned-${row.id}`, icon: 'fa-comment-dots', title: row.title, insert: () => insertCanned(row.id) })),
    ...suggest(asked, articles.data || [], (row) => `${row.title} ${row.body}`).map((row) => ({ key: `article-${row.id}`, icon: 'fa-book-open', title: row.title, insert: () => setBody(`${row.title}\n\n${row.body}`.slice(0, 20000)) })),
  ]
  return <form className="space-y-4 rounded-lg border border-slate-200 bg-slate-50 p-4" onSubmit={submit}><FormError message={error} /><div className="flex flex-wrap items-center justify-between gap-3"><div className="flex gap-1 rounded-lg border border-slate-200 bg-white p-1">{[[false, 'Reply'], [true, 'Internal note']].map(([value, title]) => <button type="button" key={title} onClick={() => setInternal(value)} aria-pressed={internal === value} className={`rounded-md px-3 py-1.5 text-xs font-semibold ${internal === value ? 'bg-brand-dark text-white' : 'text-slate-600 hover:bg-slate-100'}`}>{title}</button>)}</div>{!internal && canDraft && <Button variant="secondary" icon="fa-wand-magic-sparkles" busy={drafting.isPending} onClick={() => drafting.mutate(Boolean(draft))}>{drafting.isPending ? 'Drafting...' : draft ? 'Write another draft' : 'Draft with AI'}</Button>}{canned.length > 0 && <select aria-label="Insert canned response" className={`${filterClass} max-w-full`} value="" onChange={(event) => insertCanned(event.target.value)}><option value="">Insert canned response</option>{canned.map((row) => <option key={row.id} value={row.id}>{row.title}</option>)}</select>}</div>{!internal && suggested.length > 0 && <div className="flex flex-wrap items-center gap-2"><span className="text-xs font-semibold text-slate-500">Suggested</span>{suggested.map((item) => <button type="button" key={item.key} onClick={item.insert} title={`Insert ${item.title}`} className="inline-flex max-w-full items-center gap-1.5 rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700 ring-1 ring-inset ring-blue-600/20 hover:bg-blue-100"><i className={`fa-solid ${item.icon}`} aria-hidden="true" /><span className="min-w-0 truncate">{item.title}</span></button>)}</div>}{!internal && draft && <AiPanel title="AI draft" note="Check every fact before sending; nothing is sent until you press Send." actions={draft.text !== body ? <Button variant="secondary" icon="fa-arrow-down" onClick={() => { setBody(draft.text); setDrafted(draft.text) }}>Use this draft</Button> : <AiBadge>In the reply box</AiBadge>}>
      <p className="text-xs text-slate-600"><span className="font-semibold">Confidence:</span> {label(draft.confidence)}{draft.sources.length > 0 && <> · <span className="font-semibold">Based on:</span> {draft.sources.map((source) => source.title).join(', ')}</>}</p>
      {draft.needs_correction && <p className="text-xs font-semibold text-red-700">Fix before sending: a required guideline failed.</p>}
      {draft.needs_human_reason && <p className="rounded-lg border border-amber-200 bg-amber-50 p-2 text-xs text-amber-800"><i className="fa-solid fa-triangle-exclamation mr-1" aria-hidden="true" />{draft.needs_human_reason}</p>}
      {draft.text !== body && <p className="whitespace-pre-wrap rounded-lg bg-slate-50 p-2 text-xs text-slate-600">{draft.text}</p>}
      <RuleChecks checks={draft.checks} book="reply" />
    </AiPanel>}<Field label={internal ? 'Internal note' : 'Reply'} htmlFor="ticket-reply"><textarea id="ticket-reply" className={fieldClass} required rows={5} maxLength={20000} value={body} onChange={(event) => setBody(event.target.value)} /></Field><Field label="Attachments (up to 4 MB per message)" htmlFor="ticket-files"><input id="ticket-files" type="file" multiple accept=".pdf,.png,.jpg,.jpeg,.webp,.txt,.csv" key={files.length === 0 ? 'empty' : 'selected'} className="mt-2 block w-full text-xs text-slate-600" onChange={(event) => setFiles(Array.from(event.target.files || []))} /></Field>{files.length > 0 && <p className="text-xs text-slate-500">{files.map((file) => file.name).join(', ')}</p>}<div className="flex flex-wrap items-end justify-end gap-3">{!internal && afterOptions.length > 0 && <div className="min-w-48 flex-1 sm:max-w-xs"><SelectField name="ticket-reply-status" label="After sending" placeholder={replyStatus === ticket.status ? 'Keep the current status' : label(replyStatus)} value={nextStatus} options={afterOptions} onChange={setAfter} /></div>}<Button type="submit" icon="fa-paper-plane" busy={save.isPending}>{internal ? 'Add internal note' : nextStatus === 'resolved' ? 'Send and resolve' : nextStatus ? 'Send and wait for customer' : 'Send reply'}</Button></div></form>
}
function RefundForm({ ticket, onSuccess }) {
  // Same balance the server allows: captured, less refunded, less refunds still in flight.
  const reserved = (ticket.payment.refunds || []).filter((row) => ['requested', 'approved', 'processing'].includes(row.status)).reduce((sum, row) => sum + Number(row.total_amount ?? row.amount ?? 0), 0)
  const maximum = ticket.payment.captured_amount - ticket.payment.refunded_amount - reserved
  const [amount, setAmount] = useState(maximum > 0 ? centavosToPesos(maximum) : '')
  const [reason, setReason] = useState('item_not_received')
  const [notes, setNotes] = useState('')
  const [error, setError] = useState('')
  const mutation = useMutation({ mutationFn: (payload) => api.post('/refunds', payload), onSuccess, onError: (failure) => setError(apiError(failure)) })
  // Only for the automatic-approval note; the form works without it.
  const hint = autoApproveHint(useQuery({ queryKey: ['payment-options'], queryFn: async () => (await api.get('/payment-options')).data }).data)
  if (maximum <= 0) return <p className="rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">Refunds still in progress already cover the rest of this payment. A new refund can be requested if one of them is rejected or fails.</p>
  function submit(event) { event.preventDefault(); try { const value = pesosToCentavos(amount); if (value > maximum) throw new Error('The refund exceeds the amount available to refund.'); setError(''); mutation.mutate({ payment_id: ticket.payment.id, ticket_id: ticket.id, amount: value, reason, notes: notes || null }) } catch (failure) { setError(failure.message) } }
  return <form className="space-y-4 rounded-lg border border-slate-200 bg-slate-50 p-4" onSubmit={submit}><FormError message={error} /><p className="text-sm text-slate-600">Available to refund: <span className="font-semibold">{money(maximum)}</span></p>{hint && <p className="text-xs text-slate-500">{hint}</p>}<Field label="Amount (PHP)" htmlFor="ticket-refund-amount"><input id="ticket-refund-amount" inputMode="decimal" required className={fieldClass} value={amount} onChange={(event) => setAmount(event.target.value)} /></Field><SelectField name="ticket-refund-reason" label="Reason" required options={['item_not_received', 'damaged', 'wrong_item', 'cancelled_order', 'duplicate_charge', 'other']} value={reason} onChange={setReason} /><Field label="Notes" htmlFor="ticket-refund-notes"><textarea id="ticket-refund-notes" className={fieldClass} rows={3} maxLength={10000} value={notes} onChange={(event) => setNotes(event.target.value)} /></Field><Button type="submit" icon="fa-rotate-left" busy={mutation.isPending}>Request refund</Button></form>
}

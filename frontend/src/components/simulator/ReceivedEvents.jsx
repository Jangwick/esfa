import { useMutation, useQueries, useQueryClient } from '@tanstack/react-query'
import api from '../../lib/api'
import { apiError, dateTime } from '../../lib/admin'
import { label } from '../../lib/payments'
import { useSimulator } from '../../lib/simulator'
import { Button, FormError, JsonView, StatusPill } from '../admin/AdminUI'
import { Empty, FeatureHead } from './SimUI'

const parse = (text) => { try { return JSON.parse(text) } catch { return text } }
// Whether the receiver got the webhook, worded so it is not read as the record's own status ("Delivered" beside a
// shipment event would look like the parcel arrived).
const DELIVERY = { delivered: 'Received', pending: 'Waiting to send', processing: 'Sending', failed: 'Not received' }
// The record the event is about and the status it reported, from the signed body.
const about = (row) => { const data = parse(row.request_body)?.data; return data ? [data.reference, data.status && label(data.status)].filter(Boolean).join(' · ') : '' }

// The webhooks SFA sent the storefront's receiver, from SFA's own delivery log. One small query per event type (SFA
// filters by exact type), so a busy part of the storefront never pushes a quiet part's events off a shared newest-50 page.
export function ReceivedEvents({ events }) {
  const { connection } = useSimulator()
  const client = useQueryClient()
  const endpointId = connection?.webhook_endpoint_id
  // "type:status" (lib/simulator.js) keeps only the events whose record has that status, read from a longer page.
  const wanted = events.map((event) => { const [type, status] = event.split(':'); return { type, status } })
  const queries = useQueries({ queries: wanted.map(({ type, status }) => ({
    queryKey: ['simulator-deliveries', endpointId, type, status], enabled: Boolean(endpointId), refetchInterval: 5000,
    queryFn: async () => (await api.get('/webhook-deliveries', { params: { endpoint_id: endpointId, event_type: type, per_page: status ? 50 : 10 } })).data,
    select: (data) => status ? { ...data, data: data.data.filter((row) => parse(row.request_body)?.data?.status === status) } : data,
  })) })
  // Sends a waiting or failed webhook again now (the Integrations page's Resend), so a panelist need not wait out the retry delay.
  const resend = useMutation({ mutationFn: async (id) => (await api.post(`/webhook-deliveries/${id}/resend`)).data, onSettled: () => client.invalidateQueries({ queryKey: ['simulator-deliveries'] }) })
  const failed = queries.filter((query) => query.isError)
  const rows = queries.flatMap((query) => query.data?.data || []).sort((left, right) => String(right.created_at).localeCompare(String(left.created_at))).slice(0, 30)
  return <div className="space-y-3 rounded-lg border border-slate-200 p-4">
    <div className="flex flex-wrap items-start justify-between gap-2"><div className="min-w-0 flex-1"><FeatureHead title="Webhooks SFA sent" endpoint={connection?.receiver?.url && `POST ${connection.receiver.url}/webhooks`} description={`${wanted.map(({ type, status }) => status ? `${type} (${label(status).toLowerCase()} only)` : type).join(', ')}. Newest first, checked every 5 seconds. Each names its record and the status it reported; Received means the storefront's receiver accepted it, and one waiting or not received can be sent again from here.`} /></div>{queries.some((query) => query.isFetching && !query.isPending) && <span className="text-xs font-medium text-slate-400">Refreshing...</span>}</div>
    {!endpointId ? <Empty>Connect to SFA to see the webhooks it sends.</Empty>
      : failed.length ? <div role="alert" className="flex flex-col gap-2 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800 sm:flex-row sm:items-center sm:justify-between"><p>{apiError(failed[0].error, 'Could not load the webhook deliveries.')}</p><button type="button" onClick={() => failed.forEach((query) => query.refetch())} className="inline-flex items-center justify-center gap-2 rounded-lg border border-red-300 bg-white px-3 py-2 text-xs font-semibold text-red-700 transition hover:bg-red-100"><i className="fa-solid fa-rotate-right" aria-hidden="true" />Try again</button></div>
      : queries.some((query) => query.isPending) ? <div className="space-y-2">{[0, 1, 2].map((index) => <div key={index} className="h-10 animate-pulse rounded-lg bg-slate-100" />)}</div>
      : rows.length ? <ul className="space-y-2">{rows.map((row) => <li key={row.id}><details className="rounded-lg border border-slate-200 p-3"><summary className="flex cursor-pointer flex-wrap items-center justify-between gap-2"><span className="min-w-0"><span className="break-all font-mono text-xs font-bold text-slate-900">{row.event_type}</span>{about(row) && <span className="block break-words text-xs text-slate-600">{about(row)}</span>}</span><span className="flex items-center gap-2 text-xs text-slate-500">{dateTime(row.created_at)}<StatusPill status={row.status}>{DELIVERY[row.status] || label(row.status)}</StatusPill></span></summary><div className="mt-2 space-y-2">
        <p className="text-xs text-slate-500">{row.status === 'delivered' ? `Delivered on attempt ${row.attempts}; the receiver answered HTTP ${row.response_status}.` : row.status === 'failed' ? `SFA gave up after ${row.attempts} attempts.` : row.next_attempt_at ? `Attempts so far: ${row.attempts}. Next try ${dateTime(row.next_attempt_at)}.` : 'Being sent now.'}</p>
        {row.status !== 'delivered' && row.response_body && <p className="break-words rounded-lg bg-slate-50 p-2 text-xs text-slate-700">Last answer{row.response_status ? ` (HTTP ${row.response_status})` : ''}: {String(row.response_body).slice(0, 300)}</p>}
        {['pending', 'failed'].includes(row.status) && <Button variant="secondary" icon="fa-paper-plane" busy={resend.isPending && resend.variables === row.id} disabled={resend.isPending} onClick={() => resend.mutate(row.id)}>Send again now</Button>}
        {/* The answer to "Send again now", next to the row it was for. */}
        {resend.variables === row.id && (resend.isError ? <FormError message={apiError(resend.error, 'SFA could not send this webhook again.')} />
          : resend.isSuccess && <p role="status" className="text-xs font-medium text-slate-700">{resend.data?.status === 'delivered' ? `Sent again: the receiver accepted it (HTTP ${resend.data.response_status}).` : `Sent again, but the receiver did not accept it${resend.data?.response_status ? ` (HTTP ${resend.data.response_status})` : ''}. Check that the receiver is running (its command is under Connection to SFA), then press Reconnect.`}</p>)}
        <JsonView value={parse(row.request_body)} />
      </div></details></li>)}</ul>
      : <Empty>Nothing yet. SFA sends these events when something they report happens to a storefront record.</Empty>}
  </div>
}

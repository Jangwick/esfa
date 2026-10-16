import { useQuery } from '@tanstack/react-query'
import api from '../../lib/api'
import { apiError, dateTime } from '../../lib/admin'
import { Button, JsonView } from '../admin/AdminUI'
import { Empty } from './SimUI'

// What SFA's built-in partner receiver (/api/v1/simrx, hosted demos only) accepted: its last 30 signed webhooks, newest
// first. Payloads hold customers' names, phones and addresses, so they are read here, Admin only (GET /simulator/simrx),
// never on the receiver's public page. Loaded on open, after each Connect and with Refresh; no polling.
export function ReceivedWebhooks() {
  const query = useQuery({ queryKey: ['simulator-simrx'], queryFn: async () => (await api.get('/simulator/simrx')).data })
  const events = query.data?.events || []
  return <section id="received-webhooks" className="space-y-3 rounded-xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="sim-received-webhooks">
    <div className="flex flex-wrap items-start justify-between gap-3"><div className="min-w-0 flex-1"><h2 id="sim-received-webhooks" className="text-base font-semibold text-slate-900">Received webhooks</h2><p className="mt-1 max-w-3xl text-xs text-slate-500">The signed webhooks SFA's built-in partner receiver accepted, newest first (the last 30). Press Refresh to check for new ones.</p></div><Button variant="secondary" icon="fa-rotate-right" busy={query.isFetching} onClick={() => query.refetch()}>Refresh</Button></div>
    {query.isPending ? <div className="space-y-2">{[0, 1, 2].map((index) => <div key={index} className="h-10 animate-pulse rounded-lg bg-slate-100" />)}</div>
      : query.isError ? <div role="alert" className="flex flex-col gap-2 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800 sm:flex-row sm:items-center sm:justify-between"><p>{apiError(query.error, 'Could not load the received webhooks.')}</p><button type="button" onClick={() => query.refetch()} className="inline-flex items-center justify-center gap-2 rounded-lg border border-red-300 bg-white px-3 py-2 text-xs font-semibold text-red-700 transition hover:bg-red-100"><i className="fa-solid fa-rotate-right" aria-hidden="true" />Try again</button></div>
      : !query.data.configured ? <Empty>The built-in receiver is not set up yet. Press Connect above: SFA then sends it the storefront's settings.</Empty>
      : events.length ? <ul className="space-y-2">{events.map((event, index) => <li key={`${event.delivery || 'event'}-${event.at}-${index}`}><details className="rounded-lg border border-slate-200 p-3"><summary className="flex cursor-pointer flex-wrap items-center justify-between gap-2"><span className="min-w-0"><span className="break-all font-mono text-xs font-bold text-slate-900">{event.event || 'Unnamed event'}</span>{event.delivery && <span className="block text-xs text-slate-500" title={event.delivery}>Delivery {String(event.delivery).slice(0, 8)}</span>}</span><span className="text-xs text-slate-500">{dateTime(event.at)}</span></summary><div className="mt-2"><JsonView value={event.payload} /></div></details></li>)}</ul>
      : <Empty>Nothing received yet. Press Reconnect, then create an order or move a shipment in the Simulator.</Empty>}
  </section>
}

import { useEffect } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import api from '../../lib/api'
import { apiError, dateTime } from '../../lib/admin'
import { label, shipmentColors } from '../../lib/logistics'
import { Button } from '../admin/AdminUI'

// The parcel as SFA's staff see it (the Shipment details timeline), shown beside the courier forms so the Admin can
// watch each courier event land. It reads the staff API with the Admin's own session, the same query the Logistics
// page uses, and refetches whenever `version` changes (after each send).
const FORWARD = ['pending', 'ready_for_pickup', 'picked_up', 'in_transit', 'out_for_delivery', 'delivered']
// A parcel off that path marks no step as current: the steps it reached are ticked and this line says where it stands.
const SIDE = { failed_attempt: 'Delivery failed on the last attempt; the courier can take it out for delivery again.', returning: 'Three attempts failed, so SFA started a return. The courier reports returned to sender next.', returned_to_sender: 'The parcel went back to the sender.', cancelled: 'The shipment was cancelled with its order, so the courier reports nothing more for it.' }

export default function ShipmentProgress({ id, version }) {
  // Also re-read on return to this browser tab, where staff may have moved the parcel on (ready, delivered, cancelled).
  const query = useQuery({ placeholderData: undefined, enabled: Boolean(id), refetchOnWindowFocus: true, queryKey: ['logistics', 'shipment', id], queryFn: async () => (await api.get(`/logistics/shipments/${id}`)).data })
  const { refetch } = query
  useEffect(() => { if (id && version) refetch() }, [id, version, refetch])
  if (!id) return null
  if (query.isError) return <div className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700" role="alert">{apiError(query.error, 'Could not load this shipment.')} <button type="button" className="font-semibold underline" onClick={() => refetch()}>Try again</button></div>
  if (query.isPending) return <div className="h-24 animate-pulse rounded-lg bg-slate-100" aria-label="Loading shipment progress" />
  const shipment = query.data
  const reached = new Set((shipment.events || []).map((entry) => entry.status))
  const current = FORWARD.indexOf(shipment.status)
  const events = [...(shipment.events || [])].reverse()
  return <section className="space-y-3 rounded-lg border border-slate-200 bg-slate-50 p-4" aria-label={`Progress of ${shipment.reference} in SFA`}>
    <div className="flex flex-wrap items-center justify-between gap-2"><div className="flex flex-wrap items-center gap-2"><p className="text-xs font-semibold uppercase tracking-wider text-slate-500">In SFA now</p><span className="font-mono text-xs font-semibold text-slate-700">{shipment.reference}</span><span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset ${shipmentColors[shipment.status]}`}>{label(shipment.status)}</span>{shipment.attempt_count > 0 && <span className="text-xs text-slate-500">{shipment.attempt_count} of 3 failed attempts</span>}</div><Button variant="secondary" icon="fa-rotate-right" busy={query.isFetching} onClick={() => refetch()}>Refresh</Button></div>
    <ol className="grid grid-cols-3 gap-2 sm:grid-cols-6" aria-label="Delivery steps">{FORWARD.map((step, index) => { const done = reached.has(step) || (current >= 0 && index < current); const here = index === current; return <li key={step} className={`rounded-lg border px-2 py-1.5 text-center text-[11px] font-semibold ${here ? 'border-brand-blue bg-blue-50 text-brand-blue' : done ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-slate-200 bg-white text-slate-400'}`}><i className={`fa-solid ${done && !here ? 'fa-circle-check' : here ? 'fa-truck-fast' : 'fa-circle'} mr-1`} aria-hidden="true" />{label(step)}{here && <span className="sr-only"> (current)</span>}</li> })}</ol>
    {SIDE[shipment.status] && <p className="text-xs font-medium text-amber-700"><i className="fa-solid fa-triangle-exclamation mr-1" aria-hidden="true" />{SIDE[shipment.status]}</p>}
    <ol className="max-h-56 space-y-3 overflow-y-auto pr-1">{events.map((entry) => <li key={entry.id} className="flex items-start gap-3"><span className="mt-0.5 flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-700"><i className="fa-solid fa-truck-fast text-xs" aria-hidden="true" /></span><div className="min-w-0"><p className="text-sm font-semibold text-slate-900">{label(entry.status)}</p><p className="mt-0.5 text-xs text-slate-500">{dateTime(entry.occurred_at)} · {label(entry.source)}{entry.location ? ` · ${entry.location}` : ''}</p>{entry.note && <p className="mt-0.5 whitespace-pre-wrap break-words text-xs text-slate-600">{entry.note}</p>}</div></li>)}</ol>
    <Link to={`/logistics?shipment=${shipment.id}`} className="inline-flex items-center gap-1.5 text-xs font-semibold text-brand-blue hover:text-blue-900">Open in Logistics & Locations <i className="fa-solid fa-arrow-right" aria-hidden="true" /></Link>
  </section>
}

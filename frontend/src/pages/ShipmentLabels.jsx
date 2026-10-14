import { Link, useSearchParams } from 'react-router-dom'
import { useQueries } from '@tanstack/react-query'
import api from '../lib/api'
import { apiError } from '../lib/admin'
import { addressText, label, money } from '../lib/logistics'

const heading = 'text-xs font-semibold uppercase tracking-wider text-slate-500'
const back = <Link to="/logistics" className="inline-flex items-center gap-1.5 text-xs font-semibold text-brand-blue hover:text-blue-700"><i className="fa-solid fa-arrow-left" aria-hidden="true" />Back to logistics</Link>

// Printable labels for /logistics/labels?ids=1,2,3 (the Logistics bulk bar opens it for the selected shipments).
export default function ShipmentLabels() {
  const [params] = useSearchParams()
  const ids = [...new Set((params.get('ids') || '').split(',').filter((id) => /^\d+$/.test(id)).map(Number))]
  const queries = useQueries({ queries: ids.map((id) => ({ queryKey: ['logistics', 'shipment', id], queryFn: async () => (await api.get(`/logistics/shipments/${id}`)).data })) })
  const failed = queries.filter((query) => query.isError)
  if (!ids.length) return <div className="mx-auto max-w-3xl p-4 sm:p-8"><div className="rounded-xl border border-slate-200 bg-white p-6 text-center shadow-sm"><i className="fa-solid fa-print text-2xl text-slate-300" aria-hidden="true" /><p className="mt-3 font-semibold text-slate-700">No shipments selected</p><p className="mt-1 mb-4 text-sm text-slate-500">Select shipments on the Logistics page, then choose Print labels.</p>{back}</div></div>
  if (queries.some((query) => query.isPending)) return <div className="mx-auto max-w-3xl space-y-6 p-4 sm:p-8">{ids.map((id) => <div key={id} className="h-72 animate-pulse rounded-lg bg-slate-100" aria-label="Loading label" />)}</div>
  const fetching = failed.some((query) => query.isFetching)
  if (failed.length) return <div className="mx-auto max-w-3xl p-4 sm:p-8"><div role="alert" className="flex flex-col gap-3 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800 sm:flex-row sm:items-center sm:justify-between"><div className="flex items-start gap-3"><i className="fa-solid fa-circle-exclamation mt-0.5" aria-hidden="true" /><div><p className="font-semibold">{failed.length} of {ids.length} labels could not be loaded</p><p className="mt-0.5 text-red-700">{apiError(failed[0].error)}</p></div></div><div className="flex flex-wrap items-center gap-3">{back}<button type="button" disabled={fetching} onClick={() => failed.forEach((query) => query.refetch())} className="inline-flex items-center justify-center gap-2 rounded-lg border border-red-300 bg-white px-3 py-2 text-xs font-semibold text-red-700 transition hover:bg-red-100 disabled:opacity-60"><i className={`fa-solid ${fetching ? 'fa-circle-notch animate-spin' : 'fa-rotate-right'}`} aria-hidden="true" />Try again</button></div></div></div>
  return <main className="mx-auto my-8 max-w-3xl p-4 sm:p-0"><style>{'@media print { body { background: white !important; } .label-controls { display:none !important; } main { margin:0 !important; padding:0 !important; } .shipping-label { break-inside: avoid; } }'}</style>
    <div className="label-controls mb-6 flex flex-wrap items-center justify-between gap-3"><div><h1 className="text-xl font-bold text-slate-900">Shipping labels</h1><p className="mt-1">{back}</p></div><button type="button" onClick={() => window.print()} className="rounded-lg bg-brand-blue px-4 py-2 text-sm font-semibold text-white">Print {ids.length === 1 ? 'label' : `${ids.length} labels`}</button></div>
    <div className="space-y-6">{queries.map(({ data: shipment }) => {
      const origin = shipment.origin_warehouse
      const destination = shipment.destination_address
      return <article key={shipment.id} className="shipping-label rounded-lg border-2 border-slate-900 bg-white p-5 text-sm text-slate-900">
        <header className="flex flex-wrap items-start justify-between gap-3 border-b border-slate-300 pb-3"><img src="/logo.png" alt="Archon Nell Incorporated" className="h-10 w-auto max-w-full" /><div className="text-right"><p className="text-lg font-bold">{shipment.courier?.name}</p><p className={heading}>{label(shipment.service_type)}</p></div></header>
        <div className="border-b border-slate-300 py-4 text-center"><p className={heading}>Tracking number</p><p className="mt-1 break-all font-mono text-3xl font-bold tracking-wider">{shipment.tracking_number || 'Not assigned'}</p><p className="mt-2 font-mono text-xs font-semibold text-slate-600">{shipment.reference} · Order {shipment.order?.external_id}</p></div>
        <div className="grid grid-cols-1 gap-4 border-b border-slate-300 py-4 sm:grid-cols-2"><section><h2 className={heading}>From</h2><p className="mt-1 font-semibold">{origin?.name}</p><p className="mt-1">{addressText(origin?.address)}</p>{origin?.address?.contact_name && <p className="mt-1 text-xs text-slate-600">{origin.address.contact_name} · {origin.address.contact_phone}</p>}</section><section><h2 className={heading}>To</h2><p className="mt-1 text-base font-bold">{destination?.contact_name}</p><p className="font-semibold">{destination?.contact_phone}</p><p className="mt-1">{addressText(destination)}</p></section></div>
        <footer className="flex flex-wrap items-end justify-between gap-3 pt-3"><div><p className={heading}>Cash on delivery</p><p className="text-lg font-bold tabular-nums">{shipment.cod_amount > 0 ? money(shipment.cod_amount) : 'None (prepaid)'}</p></div><div className="text-right"><p className={heading}>Weight</p><p className="text-lg font-bold tabular-nums">{shipment.weight_grams} g</p></div></footer>
      </article>
    })}</div>
  </main>
}

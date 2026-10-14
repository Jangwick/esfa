import { useEffect, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import api from '../../lib/api'
import { label } from '../../lib/payments'

// The photo route needs the signed-in token, which an <img src> cannot send, so the bytes come through the API client
// and show from a blob URL that is revoked when the picture leaves the screen. The Blob itself stays in the query cache.
function Picture({ refundId, photo, large, onOpen }) {
  const query = useQuery({ queryKey: ['refund-photo', refundId, photo.id], queryFn: async () => (await api.get(`/refunds/${refundId}/photos/${photo.id}`, { responseType: 'blob' })).data, staleTime: Infinity })
  const [url, setUrl] = useState('')
  useEffect(() => {
    if (!query.data) return undefined
    const next = URL.createObjectURL(query.data)
    setUrl(next)
    return () => URL.revokeObjectURL(next)
  }, [query.data])
  const box = large ? 'h-64 w-full' : 'h-20 w-20'
  if (query.isError) return <div role="alert" className={`flex flex-col items-center justify-center gap-1 rounded-lg border border-red-200 bg-red-50 p-1 text-center text-[11px] text-red-700 ${box}`}>Could not load<button type="button" className="font-semibold underline focus:outline-none focus:ring-2 focus:ring-red-200" disabled={query.isFetching} onClick={() => query.refetch()}>Try again</button></div>
  if (!url) return <div className={`animate-pulse rounded-lg bg-slate-100 ${box}`} aria-label={`Loading ${photo.filename}`} />
  if (large) return <img src={url} alt={photo.filename} className="max-h-[70vh] w-full rounded-lg border border-slate-200 bg-slate-50 object-contain" />
  return <button type="button" title="Show full size" className="block rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-100" onClick={onOpen}><img src={url} alt={photo.filename} className="h-20 w-20 rounded-lg border border-slate-200 object-cover" /></button>
}

// Thumbnails of a refund's photos ([{id, filename, source?}], photo 1 first); pressing one shows it full size below.
export default function RefundPhotos({ refundId, photos }) {
  const [open, setOpen] = useState(null)
  const index = photos.findIndex((photo) => photo.id === open)
  return <div className="space-y-3">
    <ul className="flex flex-wrap gap-3">{photos.map((photo, number) => <li key={photo.id} className="w-20 text-xs text-slate-500"><Picture refundId={refundId} photo={photo} onOpen={() => setOpen(open === photo.id ? null : photo.id)} /><p className="mt-1 font-semibold text-slate-700">Photo {number + 1}</p>{photo.source && <p className="truncate" title={label(photo.source)}>{label(photo.source)}</p>}</li>)}</ul>
    {index >= 0 && <figure className="space-y-2 rounded-lg border border-slate-200 p-3"><Picture refundId={refundId} photo={photos[index]} large /><figcaption className="flex flex-wrap items-center justify-between gap-2 text-xs text-slate-500"><span className="min-w-0 break-all">Photo {index + 1} · {photos[index].filename}</span><button type="button" className="font-semibold text-brand-blue hover:text-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-100" onClick={() => setOpen(null)}>Close photo</button></figcaption></figure>}
  </div>
}

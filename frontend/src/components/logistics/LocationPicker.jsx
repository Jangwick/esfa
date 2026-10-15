import { useCallback, useEffect, useRef, useState } from 'react'
import L from 'leaflet'
import { MapContainer, Marker, TileLayer, useMapEvents } from 'react-leaflet'
import 'leaflet/dist/leaflet.css'
import markerIcon from 'leaflet/dist/images/marker-icon.png'
import markerIcon2x from 'leaflet/dist/images/marker-icon-2x.png'
import markerShadow from 'leaflet/dist/images/marker-shadow.png'
import { fieldClass, secondaryClass } from '../../lib/admin'
import api from '../../lib/api'

const pin = L.icon({ iconUrl: markerIcon, iconRetinaUrl: markerIcon2x, shadowUrl: markerShadow, iconSize: [25, 41], iconAnchor: [12, 41] })
const PH_CENTER = [12.8797, 121.774]

// Free OpenStreetMap geocoder, limited to the Philippines.
async function geocode(text) {
  const response = await fetch(`https://nominatim.openstreetmap.org/search?${new URLSearchParams({ q: text, format: 'json', limit: '1', countrycodes: 'ph' })}`)
  const [hit] = await response.json()
  return hit ? { lat: Number(hit.lat), lng: Number(hit.lon), label: hit.display_name } : null
}

function ClickToPin({ onPick }) {
  useMapEvents({ click: (event) => onPick(event.latlng.lat, event.latlng.lng) })
  return null
}

function ResizeFix() {
  const map = useMapEvents({})
  useEffect(() => { const timer = setTimeout(() => map.invalidateSize(), 250); return () => clearTimeout(timer) }, [map])
  return null
}

function FlyTo({ position }) {
  const map = useMapEvents({})
  useEffect(() => { if (position) map.flyTo(position, 16) }, [map, position])
  return null
}

/** Pin map for an address: typeahead suggestions ranked by the embeddings endpoint, plus click/drag pinning. */
export default function LocationPicker({ latitude, longitude, hint, onPick, onClear }) {
  const [search, setSearch] = useState('')
  const [suggestions, setSuggestions] = useState([])
  const [flying, setFlying] = useState(null)
  const [status, setStatus] = useState('')
  const autoHint = useRef('')
  const picked = useRef('')
  const debounce = useRef(null)
  const pinned = latitude !== '' && latitude != null && longitude !== '' && longitude != null
  const point = pinned ? [Number(latitude), Number(longitude)] : null

  const locate = useCallback(async (text) => {
    setStatus('Searching...')
    try {
      const hit = await geocode(text)
      if (hit) { setStatus(''); setFlying([hit.lat, hit.lng]); onPick(hit.lat, hit.lng, false) }
      else setStatus('Nothing found. Try a street, barangay and city.')
    } catch { setStatus('Map search is unreachable right now.') }
  }, [onPick])

  // Typeahead: ranked candidates from POST /logistics/address/suggest (embedding-ranked server-side).
  useEffect(() => {
    clearTimeout(debounce.current)
    if (search.trim().length < 3 || search === picked.current) { setSuggestions([]); return }
    debounce.current = setTimeout(async () => {
      try {
        const { data } = await api.post('/logistics/address/suggest', { text: search.trim() })
        setSuggestions(data.data || [])
      } catch { setSuggestions([]) }
    }, 400)
    return () => clearTimeout(debounce.current)
  }, [search])

  // Picking a city/barangay above geocodes it once, until the user pins a point by hand.
  useEffect(() => {
    if (!hint || pinned || autoHint.current === hint) return
    autoHint.current = hint
    locate(hint)
  }, [hint, pinned, locate])

  function choose(hit) {
    picked.current = hit.label
    setSearch(hit.label)
    setSuggestions([])
    setStatus('')
    setFlying([hit.lat, hit.lng])
    onPick(hit.lat, hit.lng, true)
  }

  function submit() {
    if (suggestions.length) choose(suggestions[0])
    else if (search.trim().length > 2) locate(search.trim())
  }

  return <div className="col-span-full space-y-2">
    <div className="flex flex-col gap-2 sm:flex-row">
      <div className="relative flex-1">
        <input type="text" role="combobox" aria-expanded={suggestions.length > 0} aria-autocomplete="list" className={fieldClass + ' mt-0'} placeholder="Search address, e.g. Sterling Industrial Park, Meycauayan" value={search} onChange={(event) => { setSearch(event.target.value) }} onKeyDown={(event) => { if (event.key === 'Enter') { event.preventDefault(); submit() } if (event.key === 'Escape') setSuggestions([]) }} />
        {suggestions.length > 0 && <ul role="listbox" className="absolute z-[1001] mt-1 max-h-56 w-full overflow-auto rounded-lg border border-slate-300 bg-white py-1 shadow-lg">
          {suggestions.map((hit) => <li key={`${hit.lat}-${hit.lng}-${hit.label}`} role="option" aria-selected="false"><button type="button" className="flex w-full items-start gap-2 px-3 py-2 text-left text-sm text-slate-700 hover:bg-blue-50" onClick={() => choose(hit)}><i className="fa-solid fa-location-dot mt-0.5 text-slate-400" aria-hidden="true" /><span>{hit.label}</span></button></li>)}
        </ul>}
      </div>
      <button type="button" className={secondaryClass} disabled={search.trim().length < 3} onClick={submit}><i className="fa-solid fa-magnifying-glass" aria-hidden="true" />Search map</button>
      {pinned && <button type="button" className={secondaryClass} onClick={onClear}><i className="fa-solid fa-location-slash" aria-hidden="true" />Clear pin</button>}
    </div>
    <div className="h-64 overflow-hidden rounded-lg border border-slate-300">
      <MapContainer center={point || PH_CENTER} zoom={point ? 16 : 5} scrollWheelZoom className="h-full w-full">
        <ResizeFix /><FlyTo position={flying} /><ClickToPin onPick={(lat, lng) => { setFlying(null); onPick(lat, lng, true) }} />
        <TileLayer attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>' url="https://tile.openstreetmap.org/{z}/{x}/{y}.png" />
        {point && <Marker position={point} icon={pin} draggable eventHandlers={{ dragend: (event) => { const { lat, lng } = event.target.getLatLng(); onPick(lat, lng, true) } }} />}
      </MapContainer>
    </div>
    <p className="text-xs text-slate-500" role="status">{status || (point ? `Pinned at ${Number(latitude).toFixed(6)}, ${Number(longitude).toFixed(6)}. Click or drag to move.` : 'Click the map, search an address, or pick a city and barangay to drop a pin.')}</p>
  </div>
}

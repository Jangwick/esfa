import { useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import api from '../../lib/api'
import { fetchChoices } from '../../lib/adminHooks'
import { apiError, fieldClass } from '../../lib/admin'
import { useAuthStore } from '../../store/authStore'
import { Field, FormError, Button } from '../admin/AdminUI'
import { AiBadge, RuleChecks } from '../ui/Ai'

const id = (value) => (value ? String(value) : '')

export default function AddressFields({ value, onChange, prefix = 'address' }) {
  const regions = useQuery({ placeholderData: undefined, queryKey: ['locations', 'regions', 'choices'], queryFn: () => fetchChoices('/locations/regions') })
  const provinces = useQuery({ placeholderData: undefined, queryKey: ['locations', 'provinces', value.region_id], queryFn: () => fetchChoices(`/locations/provinces?region_id=${value.region_id}`), enabled: Boolean(value.region_id) })
  const cities = useQuery({ placeholderData: undefined, queryKey: ['locations', 'cities', value.region_id, value.province_id], queryFn: () => fetchChoices(`/locations/cities?region_id=${value.region_id}${value.province_id ? `&province_id=${value.province_id}` : ''}`), enabled: Boolean(value.region_id) })
  const barangays = useQuery({ placeholderData: undefined, queryKey: ['locations', 'barangays', value.city_id], queryFn: () => fetchChoices(`/locations/barangays?city_id=${value.city_id}`), enabled: Boolean(value.city_id) })
  const errorQuery = [regions, provinces, cities, barangays].find((query) => query.isError)
  // AI address fill: the AI reads the pasted text and SFA matches the location ids; the person checks before saving.
  const canFill = useAuthStore((state) => state.user?.ai_features?.includes('address_fill'))
  const [pasted, setPasted] = useState('')
  const fill = useMutation({
    mutationFn: async (text) => (await api.post('/logistics/address/parse', { text }, { timeout: 120000 })).data,
    onSuccess: (read) => onChange({ ...value, ...(read.region_id ? { region_id: id(read.region_id), province_id: id(read.province_id), city_id: id(read.city_id), barangay_id: id(read.barangay_id) } : {}), line1: read.line1 || value.line1 || '', line2: read.line2 || value.line2 || '', postal_code: read.postal_code || value.postal_code || '', contact_name: value.contact_name || read.contact_name || '', contact_phone: value.contact_phone || read.contact_phone || '' }),
  })
  const unmatched = fill.data?.unmatched || []
  function update(key, next) {
    if (key === 'region_id') onChange({ ...value, region_id: next, province_id: '', city_id: '', barangay_id: '' })
    else if (key === 'province_id') onChange({ ...value, province_id: next, city_id: '', barangay_id: '' })
    else if (key === 'city_id') { const city = cities.data?.find((row) => row.id === Number(next)); onChange({ ...value, city_id: next, province_id: city?.province_id || '', barangay_id: '' }) }
    else onChange({ ...value, [key]: next })
  }
  // A disabled or empty picker says what unlocks it; an empty list after loading means the PSGC data is missing.
  const help = (key, query) => {
    if (key === 'province_id') return value.region_id ? (provinces.data?.length === 0 ? 'This region has no province layer.' : undefined) : 'Choose the region first.'
    if (key === 'city_id' && !value.region_id) return 'Choose the region first.'
    if (key === 'barangay_id' && !value.city_id) return 'Choose the city / municipality first.'
    if (query.isSuccess && !query.data.length) return 'None loaded. An Admin imports the official PSGC list on Logistics › Locations.'
  }
  return <fieldset className="rounded-lg border border-slate-200 p-4"><legend className="px-1 text-xs font-semibold text-slate-700">Address & contact</legend><div className="space-y-4">{canFill && <div className="space-y-2 rounded-lg border border-violet-200 p-3"><Field label="Paste an address (optional)" htmlFor={`${prefix}-pasted`} help="The AI splits it into the fields below for you to check. A phone number in it stays in SFA."><textarea id={`${prefix}-pasted`} rows={2} maxLength={1000} className={fieldClass} value={pasted} onChange={(event) => setPasted(event.target.value)} /></Field><div className="flex flex-wrap items-center gap-2"><Button variant="secondary" icon="fa-wand-magic-sparkles" busy={fill.isPending} disabled={pasted.trim().length < 10} onClick={() => fill.mutate(pasted)}>{fill.isPending ? 'Reading...' : 'Fill with AI'}</Button>{fill.isSuccess && <><AiBadge>Filled</AiBadge><span role="status" className="text-xs text-slate-600">{unmatched.length ? `Not found in SFA's list: ${unmatched.map((part) => `${part} "${fill.data.read[part]}"`).join(', ')}. Pick ${unmatched.length === 1 ? 'it' : 'them'} below.` : 'Check each field before saving.'}</span></>}</div>{fill.isSuccess && <RuleChecks checks={fill.data.checks} book="address" />}{fill.isError && <FormError message={apiError(fill.error, 'Could not read the address.')} />}</div>}{errorQuery && <div className="space-y-2"><FormError message="Address locations could not be loaded." /><Button icon="fa-rotate-right" onClick={() => errorQuery.refetch()}>Try again</Button></div>}<div className="grid grid-cols-1 gap-4 sm:grid-cols-2">{[['region_id', 'Region', regions, true, false], ['province_id', 'Province', provinces, false, !value.region_id], ['city_id', 'City / municipality', cities, true, !value.region_id], ['barangay_id', 'Barangay', barangays, true, !value.city_id]].map(([key, title, query, required, disabled]) => <Field key={key} label={title} htmlFor={`${prefix}-${key}`} help={help(key, query)}><select id={`${prefix}-${key}`} className={fieldClass} required={required} disabled={disabled || query.isFetching} value={value[key] || ''} onChange={(event) => update(key, event.target.value)}><option value="">{query.isFetching ? 'Loading...' : key === 'province_id' && provinces.data?.length === 0 ? 'Not applicable' : `Select ${title.toLowerCase()}`}</option>{query.data?.map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}</select></Field>)}<Field label="Address line 1" htmlFor={`${prefix}-line1`}><input id={`${prefix}-line1`} className={fieldClass} required maxLength={255} value={value.line1 || ''} onChange={(event) => update('line1', event.target.value)} /></Field><Field label="Address line 2 (optional)" htmlFor={`${prefix}-line2`}><input id={`${prefix}-line2`} className={fieldClass} maxLength={255} value={value.line2 || ''} onChange={(event) => update('line2', event.target.value)} /></Field><Field label="Postal code" htmlFor={`${prefix}-postal`}><input id={`${prefix}-postal`} className={fieldClass} required maxLength={20} value={value.postal_code || ''} onChange={(event) => update('postal_code', event.target.value)} /></Field><Field label="Contact name" htmlFor={`${prefix}-contact-name`}><input id={`${prefix}-contact-name`} className={fieldClass} required maxLength={255} value={value.contact_name || ''} onChange={(event) => update('contact_name', event.target.value)} /></Field><Field label="Contact phone" htmlFor={`${prefix}-contact-phone`}><input id={`${prefix}-contact-phone`} type="tel" className={fieldClass} required maxLength={30} value={value.contact_phone || ''} onChange={(event) => update('contact_phone', event.target.value)} /></Field><Field label="Latitude (optional)" htmlFor={`${prefix}-latitude`}><input id={`${prefix}-latitude`} type="number" min="-90" max="90" step="0.0000001" className={fieldClass} value={value.latitude ?? ''} onChange={(event) => update('latitude', event.target.value)} /></Field><Field label="Longitude (optional)" htmlFor={`${prefix}-longitude`}><input id={`${prefix}-longitude`} type="number" min="-180" max="180" step="0.0000001" className={fieldClass} value={value.longitude ?? ''} onChange={(event) => update('longitude', event.target.value)} /></Field></div></div></fieldset>
}

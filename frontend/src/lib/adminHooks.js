import { useCallback, useEffect, useRef, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import api from './api'
import { apiError, businessTimezone, unwrap } from './admin'

export function useNotice() {
  const [notice, setNotice] = useState(null)
  useEffect(() => { if (!notice) return; const timer = setTimeout(() => setNotice(null), 4000); return () => clearTimeout(timer) }, [notice])
  return [notice, (message, error = false) => setNotice({ message, error })]
}
// placeholderData for a list whose query key is [...scope, params]: keep the previous page while paging or
// filtering the same list, but never show another tab's rows (they would render with this tab's columns).
export function keepSameList(scope) {
  const key = JSON.stringify(scope)
  return (previous, previousQuery) => previousQuery && JSON.stringify(previousQuery.queryKey.slice(0, -1)) === key ? previous : undefined
}
// Page state kept in the query string (?tab=refunds&refund=12): tabs and open records become links that survive a
// refresh and can be opened from the sidebar and from notifications. Changing a tab adds a history entry, so Back
// returns to the previous tab; pass { replace: true } for opening or closing a record so Back never reopens a dialog.
export function useUrlState(defaults) {
  const [params] = useSearchParams()
  const navigate = useNavigate()
  const defaultsKey = JSON.stringify(defaults)
  const state = Object.fromEntries(Object.entries(defaults).map(([key, fallback]) => [key, params.get(key) || fallback]))
  const update = useCallback((changes, { replace = false } = {}) => {
    const fallbacks = JSON.parse(defaultsKey)
    // Start from the live URL so two updates in one click merge instead of the second undoing the first.
    const next = new URLSearchParams(window.location.search)
    Object.entries(changes).forEach(([key, value]) => { if (value == null || value === '' || String(value) === String(fallbacks[key])) next.delete(key); else next.set(key, String(value)) })
    const query = next.toString()
    const search = query ? `?${query}` : ''
    if (search !== window.location.search) navigate({ search }, { replace })
  }, [navigate, defaultsKey])
  return [state, update]
}
// resetKey: when it changes (another tab, or Back/Forward to one) the list starts again from clean filters.
// Deep links (/users?status=inactive) seed filters whose keys exist in `initial`, also when the page is already open
// (a notification link). The params are then dropped from the URL so Clear and later changes are not undone on refresh.
export function useFilters(initial, resetKey) {
  const [urlParams] = useSearchParams()
  const navigate = useNavigate()
  const linked = Object.fromEntries(Object.keys(initial).filter((key) => urlParams.get(key)).map((key) => [key, urlParams.get(key)]))
  const linkKey = JSON.stringify(linked)
  const [filters, setFilters] = useState(() => ({ ...initial, ...linked }))
  const [search, setSearch] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')
  const [page, setPage] = useState(1)
  const [listKey, setListKey] = useState(resetKey)
  const [seenLink, setSeenLink] = useState(linkKey)
  if (listKey !== resetKey || seenLink !== linkKey) {
    const reset = listKey !== resetKey || linkKey !== '{}'
    setListKey(resetKey); setSeenLink(linkKey)
    if (reset) { setFilters({ ...initial, ...linked }); setSearch(''); setDebouncedSearch(''); setPage(1) }
  }
  useEffect(() => {
    if (linkKey === '{}') return
    const next = new URLSearchParams(window.location.search)
    Object.keys(JSON.parse(linkKey)).forEach((key) => next.delete(key))
    const query = next.toString() ? `?${next}` : ''
    if (query !== window.location.search) navigate({ search: query }, { replace: true })
  }, [linkKey, navigate])
  useEffect(() => { const timer = setTimeout(() => { setDebouncedSearch(search); setPage(1) }, 350); return () => clearTimeout(timer) }, [search])
  function change(key, value) { setFilters((previous) => ({ ...previous, [key]: value })); setPage(1) }
  function clear() { setFilters(initial); setSearch(''); setDebouncedSearch(''); setPage(1) }
  const params = { ...Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== '')), ...(debouncedSearch ? { search: debouncedSearch } : {}), page, per_page: 15 }
  return { filters, search, setSearch, page, setPage, change, clear, params }
}
// Bulk actions without bulk endpoints: run(rows, fn) calls the existing per-record endpoint for each row, so every row
// keeps its own authorization, 422 checks, row lock and audit entry. Use concurrency 1 for gateway calls. isOk(record, row)
// catches a 200 whose record still failed (e.g. status 'failed'). The selection (whole rows, for totals and references)
// clears when resetKey changes: pass the list params so a page or filter change clears it.
export function useBulkAction(resetKey) {
  const queryClient = useQueryClient()
  const [selected, setSelected] = useState([])
  const [result, setResult] = useState(null)
  const [busy, setBusy] = useState(false)
  const key = JSON.stringify(resetKey)
  const [selectionKey, setSelectionKey] = useState(key)
  if (selectionKey !== key) { setSelectionKey(key); setSelected([]); setResult(null) }
  const isSelected = (row) => selected.some((item) => item.id === row.id)
  function toggle(rows, checked) {
    const list = [].concat(rows)
    const rest = (previous) => previous.filter((item) => !list.some((row) => row.id === item.id))
    setResult(null); setSelected((previous) => checked ? [...rest(previous), ...list] : rest(previous))
  }
  async function run(rows, fn, { concurrency = 4, isOk = () => true } = {}) {
    setBusy(true); setResult(null)
    const outcome = { done: 0, failed: [] }
    try {
      for (let start = 0; start < rows.length; start += concurrency) {
        const batch = rows.slice(start, start + concurrency)
        const settled = await Promise.allSettled(batch.map(async (row) => fn(row)))
        settled.forEach((item, index) => {
          const row = batch[index]
          const reference = row.reference || row.name || `#${row.id}`
          const record = item.status === 'fulfilled' ? unwrap(item.value) : null
          if (item.status === 'rejected') outcome.failed.push({ reference, message: apiError(item.reason) })
          else if (!isOk(record, row)) outcome.failed.push({ reference, message: record?.failure_reason || record?.rejection_reason || `ended as ${record?.status || 'not completed'}` })
          else outcome.done += 1
        })
      }
    } finally {
      setSelected([]); setResult(outcome); setBusy(false)
      queryClient.invalidateQueries()
    }
    return outcome
  }
  return { selected, isSelected, toggle, clear: () => { setSelected([]); setResult(null) }, run, busy, result }
}
export function useExport(onError) {
  const [busy, setBusy] = useState(false)
  const inFlight = useRef(false)
  async function exportCsv(path, params, name) {
    if (inFlight.current) return
    inFlight.current = true; setBusy(true)
    try {
      const response = await api.get(path, { params: { ...params, page: undefined, per_page: undefined, export: 'csv' }, responseType: 'blob' })
      const url = URL.createObjectURL(new Blob([response.data], { type: 'text/csv;charset=utf-8;' }))
      const date = new Intl.DateTimeFormat('en-CA', { timeZone: businessTimezone() }).format(new Date())
      const link = document.createElement('a'); link.href = url; link.download = `${name} ${date}.csv`; document.body.appendChild(link); link.click(); link.remove(); setTimeout(() => URL.revokeObjectURL(url), 1000)
    } catch (error) { if (error.response?.data instanceof Blob) { try { const data = JSON.parse(await error.response.data.text()); onError(data.message || 'Could not export CSV.', true) } catch { onError('Could not export CSV. Please try again.', true) } } else onError(apiError(error, 'Could not export CSV.'), true) }
    finally { inFlight.current = false; setBusy(false) }
  }
  return { busy, exportCsv }
}
export async function fetchChoices(path) {
  let page = 1
  let lastPage = 1
  const rows = []
  do {
    const { data } = await api.get(path, { params: { page, per_page: 100 } })
    rows.push(...(data.data || [])); lastPage = data.last_page || 1; page += 1
  } while (page <= lastPage)
  return rows
}

// One AI rulebook (GET /ai/rules, AiRules on the server) by name: title, decision and rules with their current thresholds.
export function useRulebook(name) {
  const rules = useQuery({ queryKey: ['ai-rules'], queryFn: async () => unwrap(await api.get('/ai/rules')), staleTime: Infinity, enabled: Boolean(name) })
  return rules.data?.books?.find((book) => book.name === name)
}

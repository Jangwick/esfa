import { useId, useState } from 'react'
import Pagination from '../ui/Pagination'
import ConfirmDialog from '../ui/ConfirmDialog'
import { apiError, ghostClass, primaryClass, secondaryClass, statusColors } from '../../lib/admin'
import { nextStep } from '../../lib/guidance'

export function PageHeader({ title, description, children }) {
  return <header className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between"><div><p className="text-xs font-semibold uppercase tracking-[0.18em] text-brand-blue">Administration</p><h1 className="mt-1 text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">{title}</h1><p className="mt-1 text-sm text-slate-500">{description}</p></div>{children && <div className="flex flex-wrap gap-2">{children}</div>}</header>
}
export function Button({ children, icon = 'fa-floppy-disk', busy, variant = 'primary', type = 'button', ...props }) {
  return <button type={type} className={variant === 'secondary' ? secondaryClass : variant === 'ghost' ? ghostClass : primaryClass} {...props} disabled={busy || props.disabled}><i className={`fa-solid ${busy ? 'fa-circle-notch animate-spin' : icon}`} aria-hidden="true" />{children}</button>
}
export function IconButton({ label, icon, ...props }) {
  return <button type="button" className="flex h-8 w-8 items-center justify-center rounded-lg text-slate-500 transition hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-300 disabled:cursor-not-allowed disabled:opacity-40" aria-label={label} title={label} {...props}><i className={`fa-solid ${icon}`} aria-hidden="true" /></button>
}
export function Field({ label, htmlFor, children, help }) { return <div><label htmlFor={htmlFor} className="text-xs font-semibold text-slate-700">{label}</label>{children}{help && <p className="mt-1 text-xs text-slate-500">{help}</p>}</div> }
export function FormError({ message }) { return message ? <div role="alert" className="flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700"><i className="fa-solid fa-circle-exclamation mt-0.5" aria-hidden="true" />{message}</div> : null }
export function Notice({ notice }) {
  if (!notice) return null
  return <div role={notice.error ? 'alert' : 'status'} aria-live="polite" className={`flex items-center gap-2 rounded-lg border p-3 text-sm font-medium ${notice.error ? 'border-red-200 bg-red-50 text-red-700' : 'border-emerald-200 bg-emerald-50 text-emerald-700'}`}><i className={`fa-solid ${notice.error ? 'fa-circle-exclamation' : 'fa-circle-check'}`} aria-hidden="true" />{notice.message}</div>
}
export function SummaryCards({ cards, summary, isLoading }) {
  return <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">{cards.map(({ key, label, icon, tone }) => <article key={key} className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm"><div className="flex items-center justify-between gap-3"><div><p className="text-xs font-semibold uppercase tracking-wider text-slate-500">{label}</p>{isLoading ? <div className="mt-2 h-7 w-12 animate-pulse rounded bg-slate-100" aria-label={`Loading ${label}`} /> : <p className="mt-1 text-2xl font-bold text-slate-900">{summary?.[key] ?? '—'}</p>}</div><span className={`flex h-10 w-10 items-center justify-center rounded-lg ${tone}`}><i className={`fa-solid ${icon}`} aria-hidden="true" /></span></div></article>)}</div>
}
export function StatusPill({ status, children }) { return <span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold capitalize ring-1 ring-inset ${statusColors[status] || statusColors.inactive}`}>{children || status}</span> }
export function Tabs({ tabs, value, onChange }) {
  const id = useId()
  const hint = tabs.find((tab) => tab.key === value)?.hint
  return <div><div className="overflow-x-auto"><div className="flex items-center gap-4 border-b border-slate-200" role="tablist" aria-label="Page sections">{tabs.map((tab, index) => <button key={tab.key} id={`${id}-${tab.key}`} type="button" role="tab" aria-selected={value === tab.key} tabIndex={value === tab.key ? 0 : -1} onClick={() => onChange(tab.key)} onKeyDown={(event) => { if (['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) { event.preventDefault(); const next = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (index + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length; onChange(tabs[next].key); document.getElementById(`${id}-${tabs[next].key}`)?.focus() } }} className={`whitespace-nowrap pb-2 text-sm font-medium transition-colors focus:outline-none focus:ring-2 focus:ring-blue-300 ${value === tab.key ? 'border-b-2 border-brand-blue text-brand-blue' : 'text-slate-500 hover:text-slate-700'}`}>{tab.label}</button>)}</div></div>{hint && <p className="mt-3 flex items-start gap-2 text-sm text-slate-600"><i className="fa-solid fa-circle-info mt-0.5 text-brand-blue" aria-hidden="true" />{hint}</p>}</div>
}
export function Search({ value, onChange, label = 'Search records' }) { return <label className="relative sm:col-span-2"><span className="sr-only">{label}</span><i className="fa-solid fa-magnifying-glass absolute left-3 top-3 text-xs text-slate-400" aria-hidden="true" /><input type="search" value={value} onChange={(event) => onChange(event.target.value)} placeholder={label} className="w-full rounded-lg border border-slate-300 py-2.5 pl-9 pr-3 text-sm outline-none focus:border-brand-blue focus:ring-2 focus:ring-blue-100" /></label> }
// Compact date filter for list toolbars: inline label, same height and border as the filter selects.
export function DateFilter({ label, value, onChange, min, max }) {
  return <label className="flex items-center rounded-lg border border-slate-300 bg-white focus-within:border-brand-blue focus-within:ring-2 focus-within:ring-blue-100"><span className="whitespace-nowrap pl-3 text-xs font-semibold text-slate-500">{label}</span><input type="date" value={value} min={min || undefined} max={max || undefined} onChange={(event) => onChange(event.target.value)} className="w-full min-w-0 rounded-lg border-0 bg-transparent px-2 py-[9px] text-sm text-slate-700 outline-none" /></label>
}
export function ListCard({ title, columns, query, onPageChange, filters, onClear, empty = 'No records found', hint = 'Try adjusting or clearing the current filters.', icon = 'fa-folder-open' }) {
  const id = useId()
  const data = query.data
  const rows = Array.isArray(data?.data) ? data.data : []
  return <section className="rounded-xl border border-slate-200 bg-white shadow-sm" aria-labelledby={id}><div className="border-b border-slate-200 p-4 sm:p-5"><div className="flex flex-wrap items-start justify-between gap-3"><div><h2 id={id} className="text-base font-semibold capitalize text-slate-900">{title}</h2><p className="mt-0.5 text-xs text-slate-500">{query.isPending ? 'Loading records...' : `${data?.total ?? 0} matching ${data?.total === 1 ? 'record' : 'records'}`}</p></div>{onClear && <Button variant="ghost" icon="fa-filter-circle-xmark" onClick={onClear}>Clear filters</Button>}</div>{filters && <div className="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-4">{filters}</div>}</div>{query.isError ? <div className="p-10 text-center" role="alert"><i className="fa-solid fa-triangle-exclamation text-2xl text-red-500" aria-hidden="true" /><p className="mt-3 font-semibold text-slate-900">Could not load {title.toLowerCase()}</p><p className="mt-1 text-sm text-slate-500">{apiError(query.error)}</p><button type="button" onClick={() => query.refetch()} className="mt-4 rounded-lg bg-brand-dark px-4 py-2 text-sm font-semibold text-white focus:outline-none focus:ring-2 focus:ring-slate-400">Try again</button></div> : <div className="overflow-x-auto"><table className="w-full text-left text-sm" style={{ minWidth: Math.max(940, columns.length * 115) }}><thead className="border-b border-slate-200 bg-slate-50 text-[11px] font-semibold uppercase tracking-wider text-slate-500"><tr>{columns.map((column, index) => <th key={column.key} scope="col" className={`${index === 0 ? 'py-3 pl-4 pr-4' : 'py-3 pr-4'} ${column.right ? 'text-right' : ''}`}>{column.label}</th>)}</tr></thead><tbody className="divide-y divide-slate-100">{query.isPending ? Array.from({ length: 6 }, (_, index) => <tr key={index}><td colSpan={columns.length} className="px-4 py-3"><div className="h-10 animate-pulse rounded-lg bg-slate-100" aria-label="Loading record" /></td></tr>) : rows.length ? rows.map((row) => <tr key={row.id} className="transition-colors hover:bg-slate-50">{columns.map((column, index) => <td key={column.key} className={`${index === 0 ? 'py-3.5 pl-4 pr-4' : 'py-3.5 pr-4'} text-slate-600 ${column.right ? 'text-right' : ''}`}>{column.render ? column.render(row) : row[column.key] ?? '—'}</td>)}</tr>) : <tr><td colSpan={columns.length} className="px-4 py-14 text-center"><i className={`fa-solid ${icon} text-2xl text-slate-300`} aria-hidden="true" /><p className="mt-3 font-semibold text-slate-700">{empty}</p><p className="mt-1 text-sm text-slate-500">{hint}</p></td></tr>}</tbody></table></div>}{query.isFetching && !query.isPending && <div className="border-t border-slate-100 px-5 py-2 text-xs font-medium text-slate-400">Refreshing...</div>}{!query.isError && <div className="px-4 pb-4"><Pagination currentPage={data?.current_page || 1} lastPage={data?.last_page || 1} total={data?.total || 0} from={data?.from} to={data?.to} onPageChange={onPageChange} /></div>}</section>
}
export function FormFooter({ onCancel, busy, disabled, label = 'Save changes' }) { return <div className="flex flex-col-reverse gap-3 border-t border-slate-200 pt-4 sm:flex-row sm:justify-end"><Button variant="secondary" icon="fa-xmark" onClick={onCancel} disabled={busy}>Cancel</Button><Button type="submit" busy={busy} disabled={disabled}>{busy ? 'Saving...' : label}</Button></div> }
// `empty` explains a group with nothing to tick, so it never shows as a bare title.
export function CheckboxGroup({ title, options, value, onChange, error, format = (option) => option, empty = 'Nothing to choose from yet.' }) {
  const id = useId()
  return <fieldset className="rounded-lg border border-slate-200 p-4"><legend className="px-1 text-xs font-semibold text-slate-700">{title}</legend>{!options.length && <p className="text-sm text-slate-500">{empty}</p>}<div className="grid grid-cols-1 gap-2 sm:grid-cols-2">{options.map((option) => <label key={option} htmlFor={`${id}-${option}`} className="flex items-center gap-2 text-sm text-slate-700"><input id={`${id}-${option}`} type="checkbox" className="h-4 w-4 rounded border-slate-300 accent-brand-blue focus:ring-2 focus:ring-blue-100" checked={value.includes(option)} onChange={(event) => onChange(event.target.checked ? [...value, option] : value.filter((item) => item !== option))} />{format(option)}</label>)}</div>{error && <p className="mt-2 text-xs text-red-700">{error}</p>}</fieldset>
}
// Row selection for useBulkAction: pass `row` for a row checkbox, or `rows` (the selectable rows on this page) for the
// select-all checkbox in the column label.
export function SelectCell({ bulk, row, rows = [] }) {
  const className = 'h-4 w-4 rounded border-slate-300 accent-brand-blue focus:ring-2 focus:ring-blue-100'
  if (row) return <input type="checkbox" className={className} aria-label={`Select ${row.reference || row.name || `#${row.id}`}`} checked={bulk.isSelected(row)} disabled={bulk.busy} onChange={(event) => bulk.toggle(row, event.target.checked)} />
  const count = rows.filter(bulk.isSelected).length
  return <input type="checkbox" className={className} aria-label="Select all on this page" checked={rows.length > 0 && count === rows.length} ref={(input) => { if (input) input.indeterminate = count > 0 && count < rows.length }} disabled={!rows.length || bulk.busy} onChange={(event) => bulk.toggle(rows, event.target.checked)} />
}
// Bulk bar for useBulkAction: selection count (and total), one button per action, each confirmed with the count and
// total, then "N done, M skipped: …". actions: [{ label, icon, fn(row), concurrency, isOk, variant, disabled }]
// (variant styles the confirm dialog; disabled may be a sentence saying why, shown under the buttons so a greyed-out
// action never looks broken); extra inputs (a status select, a note) go in children.
export function BulkBar({ bulk, noun = 'record', total, actions = [], children }) {
  const [pending, setPending] = useState(null)
  const count = bulk.selected.length
  const result = bulk.result
  const reasons = actions.map((action) => action.disabled).filter((reason) => reason && typeof reason === 'string')
  if (!count && !result && !pending) return null
  const selection = `${count} ${noun}${count === 1 ? '' : 's'}${total ? ` (total ${total})` : ''}`
  const skipped = result?.failed.length ? `, ${result.failed.length} skipped: ${result.failed.map((item) => `${item.reference} (${item.message})`).join('; ')}` : ''
  return <div className="space-y-3">{result && <Notice notice={{ error: Boolean(result.failed.length), message: `${result.done} done${skipped || '.'}` }} />}{count > 0 && <div className="flex flex-wrap items-center gap-2 rounded-lg border border-blue-200 bg-blue-50 p-3 text-sm text-blue-900"><p className="mr-auto font-semibold">{count} selected{total ? ` · ${total}` : ''}</p>{children}{actions.map((action) => <Button key={action.label} icon={action.icon || 'fa-check'} onClick={() => setPending({ ...action, rows: bulk.selected, selection })} disabled={bulk.busy || Boolean(action.disabled)}>{action.label}</Button>)}<Button variant="ghost" icon="fa-xmark" onClick={bulk.clear} disabled={bulk.busy}>Clear selection</Button>{reasons.length > 0 && <p className="w-full text-xs text-slate-500">{reasons.join(' ')}</p>}</div>}<ConfirmDialog isOpen={Boolean(pending)} onClose={() => setPending(null)} onConfirm={() => bulk.run(pending.rows, pending.fn, pending)} isPending={bulk.busy} title={pending?.label} message={`${pending?.label} for ${pending?.selection}? Each record is checked and saved on its own; any that cannot change are skipped and listed.`} confirmLabel={pending?.label} variant={pending?.variant || 'primary'} /></div>
}
export function JsonView({ value }) { return <pre className="max-h-72 overflow-auto whitespace-pre-wrap break-all rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs text-slate-700">{value == null ? '—' : typeof value === 'string' ? value : JSON.stringify(value, null, 2)}</pre> }
// "Next step" callout on a record's detail view: what its current status means and which button moves it on.
export function NextStep({ entity, record }) {
  const text = nextStep(entity, record)
  if (!text) return null
  return <div className="flex items-start gap-3 rounded-lg border border-blue-200 bg-blue-50 p-3 text-sm text-blue-900"><i className="fa-solid fa-circle-info mt-0.5 text-brand-blue" aria-hidden="true" /><div><p className="text-xs font-semibold uppercase tracking-wider text-brand-blue">Next step</p><p className="mt-0.5">{text}</p></div></div>
}

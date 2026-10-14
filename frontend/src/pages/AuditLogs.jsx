import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import api from '../lib/api'
import Modal from '../components/ui/Modal'
import { auditActions, dateTime, filterClass } from '../lib/admin'
import { Button, DateFilter, IconButton, JsonView, ListCard, Notice, PageHeader, Search, SummaryCards } from '../components/admin/AdminUI'
import { fetchChoices, useExport, useFilters, useNotice } from '../lib/adminHooks'
const actionLabel = (action) => action ? (action[0].toUpperCase() + action.slice(1)).replaceAll('_', ' ') : '—'
const cards = [
  { key: 'total', label: 'Audit entries', icon: 'fa-shield-halved', tone: 'bg-slate-100 text-slate-700' },
  { key: 'created', label: 'Created', icon: 'fa-plus', tone: 'bg-emerald-50 text-emerald-700' },
  { key: 'updated', label: 'Updated', icon: 'fa-pen', tone: 'bg-blue-50 text-blue-700' },
  { key: 'deleted', label: 'Deleted', icon: 'fa-trash', tone: 'bg-red-50 text-red-700' },
]
export default function AuditLogs() {
  const filter = useFilters({ user_id: '', action: '', entity_type: '', date_from: '', date_to: '' })
  const [detail, setDetail] = useState(null)
  const [notice, notify] = useNotice()
  const exporter = useExport(notify)
  const query = useQuery({ queryKey: ['audit-logs', filter.params], queryFn: async () => (await api.get('/audit-logs', { params: filter.params })).data })
  const users = useQuery({ queryKey: ['users', 'choices'], queryFn: () => fetchChoices('/users') })
  const columns = [
    { key: 'created_at', label: 'Date & time', render: (row) => <span className="text-xs text-slate-500">{dateTime(row.created_at)}</span> },
    { key: 'user', label: 'Performed by', render: (row) => <div><p className="font-semibold text-slate-900">{row.user?.name || row.user_name || 'System'}</p><p className="text-xs text-slate-500">{row.user?.email || '—'}</p></div> },
    { key: 'action', label: 'Action', render: (row) => <span className="capitalize text-slate-700">{row.action?.replaceAll('_', ' ')}</span> },
    { key: 'entity_type', label: 'Entity', render: (row) => <div><p className="font-semibold text-slate-900">{row.entity_type || '—'}</p><p className="font-mono text-xs font-semibold text-slate-600">{row.entity_id ? `#${row.entity_id}` : '—'}</p></div> },
    { key: 'ip_address', label: 'IP address', render: (row) => <span className="text-xs text-slate-500">{row.ip_address || '—'}</span> },
    { key: 'actions', label: 'Details', right: true, render: (row) => <div className="flex justify-end"><IconButton label={`View audit entry ${row.id}`} icon="fa-eye" onClick={() => setDetail(row)} /></div> },
  ]
  return <div className="space-y-6"><PageHeader title="Audit Logs" description="Review staff activity and the recorded changes to your system."><Button variant="secondary" icon="fa-file-arrow-down" busy={exporter.busy} onClick={() => exporter.exportCsv('/audit-logs', filter.params, 'Audit logs')}>{exporter.busy ? 'Exporting...' : 'Export CSV'}</Button></PageHeader><Notice notice={notice} /><SummaryCards cards={cards} summary={query.data?.summary} isLoading={query.isPending} /><ListCard title="Recorded activity" columns={columns} query={query} onPageChange={filter.setPage} empty="No audit entries found" icon="fa-shield-halved" onClear={filter.clear} filters={<><Search value={filter.search} onChange={filter.setSearch} label="Search recorded activity" /><select aria-label="Filter by staff member" className={filterClass} value={filter.filters.user_id} onChange={(event) => filter.change('user_id', event.target.value)}><option value="">{users.isError ? 'Staff list unavailable' : users.isPending ? 'Loading staff...' : 'All staff members'}</option>{users.data?.map((user) => <option key={user.id} value={user.id}>{user.name}</option>)}</select><select aria-label="Filter by action" className={filterClass} value={filter.filters.action} onChange={(event) => filter.change('action', event.target.value)}><option value="">All actions</option>{auditActions.map((action) => <option key={action} value={action}>{actionLabel(action)}</option>)}</select><input aria-label="Entity type" className={filterClass} value={filter.filters.entity_type} placeholder="Entity type, e.g. User" onChange={(event) => filter.change('entity_type', event.target.value)} /><DateFilter label="From" value={filter.filters.date_from} max={filter.filters.date_to} onChange={(value) => filter.change('date_from', value)} /><DateFilter label="To" value={filter.filters.date_to} min={filter.filters.date_from} onChange={(value) => filter.change('date_to', value)} /></>} /><Modal isOpen={Boolean(detail)} onClose={() => setDetail(null)} title={`Audit entry #${detail?.id || ''}`}><div className="space-y-5"><dl className="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2">{[['Performed by', detail?.user?.name || detail?.user_name || 'System'], ['Action', actionLabel(detail?.action)], ['Entity', `${detail?.entity_type || '—'} ${detail?.entity_id ? `#${detail.entity_id}` : ''}`], ['Date & time', dateTime(detail?.created_at)], ['IP address', detail?.ip_address || '—']].map(([label, value]) => <div key={label}><dt className="text-xs font-semibold text-slate-700">{label}</dt><dd className="mt-1 break-words text-slate-600">{value}</dd></div>)}</dl><div><h3 className="mb-2 text-xs font-semibold text-slate-700">User agent</h3><p className="break-words text-xs text-slate-500">{detail?.user_agent || '—'}</p></div><div><h3 className="mb-2 text-xs font-semibold text-slate-700">Old values</h3><JsonView value={detail?.old_values} /></div><div><h3 className="mb-2 text-xs font-semibold text-slate-700">New values</h3><JsonView value={detail?.new_values} /></div></div></Modal></div>
}

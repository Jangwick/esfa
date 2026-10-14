import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import api from '../lib/api'
import { useAuthStore } from '../store/authStore'
import Modal from '../components/ui/Modal'
import ConfirmDialog from '../components/ui/ConfirmDialog'
import { apiError, dateTime, fieldClass, filterClass, initials, roleNames, unwrap } from '../lib/admin'
import { Button, CheckboxGroup, Field, FormError, FormFooter, IconButton, ListCard, Notice, PageHeader, Search, StatusPill, SummaryCards, Tabs } from '../components/admin/AdminUI'
import { useExport, useFilters, useNotice, useUrlState } from '../lib/adminHooks'
import { moduleTabs } from '../lib/navigation'

const summaryCards = [
  { key: 'total', label: 'Staff accounts', icon: 'fa-users', tone: 'bg-slate-100 text-slate-700' },
  { key: 'active', label: 'Active', icon: 'fa-user-check', tone: 'bg-emerald-50 text-emerald-700' },
  { key: 'inactive', label: 'Inactive', icon: 'fa-user-slash', tone: 'bg-amber-50 text-amber-700' },
  { key: 'locked', label: 'Sign-in locked', icon: 'fa-lock', tone: 'bg-red-50 text-red-700' },
]
const tabs = moduleTabs('/users')
const blankUser = { name: '', email: '', password: '', password_confirmation: '', roles: [], is_active: true, otp_email: '' }

export default function Users() {
  const queryClient = useQueryClient()
  const currentUser = useAuthStore((state) => state.user)
  const setUser = useAuthStore((state) => state.setUser)
  const [url, setUrl] = useUrlState({ tab: 'users' })
  const tab = tabs.some((item) => item.key === url.tab) ? url.tab : 'users'
  const setTab = (next) => setUrl({ tab: next })
  const filter = useFilters({ role: '', status: '' })
  const [notice, notify] = useNotice()
  const exporter = useExport(notify)
  const [editing, setEditing] = useState(null)
  const [form, setForm] = useState(blankUser)
  const [formError, setFormError] = useState('')
  const [confirmation, setConfirmation] = useState(null)
  const [roleDetail, setRoleDetail] = useState(null)
  const query = useQuery({ queryKey: ['users', filter.params], queryFn: async () => (await api.get('/users', { params: filter.params })).data })
  const rolesQuery = useQuery({ queryKey: ['roles'], queryFn: async () => unwrap(await api.get('/roles')) })
  const roles = Array.isArray(rolesQuery.data) ? rolesQuery.data : []
  const save = useMutation({
    mutationFn: (payload) => editing.id ? api.put(`/users/${editing.id}`, payload) : api.post('/users', payload),
    onSuccess: (response) => { if (editing.id === currentUser?.id) setUser(unwrap(response)); notify(editing.id ? 'Staff account updated.' : 'Staff account created.'); setEditing(null); queryClient.invalidateQueries({ queryKey: ['users'] }); queryClient.invalidateQueries({ queryKey: ['profile'] }) },
    onError: (error) => setFormError(apiError(error, 'Could not save this staff account.')),
  })
  const action = useMutation({
    mutationFn: ({ type, row }) => type === 'unlock' ? api.post(`/users/${row.id}/unlock`) : api.put(`/users/${row.id}`, { is_active: !row.is_active }),
    onSuccess: (_, variables) => { setConfirmation(null); notify(variables.type === 'unlock' ? 'Sign-in unlocked.' : variables.row.is_active ? 'Staff account deactivated. Their open tickets are back in the unassigned support queue.' : 'Staff account activated.'); queryClient.invalidateQueries({ queryKey: ['users'] }) },
    // No onError: the confirm dialog stays open and shows the server's reason next to the button that was clicked.
  })
  function openForm(row) {
    setEditing(row || {}); setFormError(''); save.reset()
    setForm(row ? { name: row.name, email: row.email, password: '', password_confirmation: '', roles: roleNames(row.roles), is_active: Boolean(row.is_active), otp_email: row.otp_email || '' } : { ...blankUser, roles: [] })
  }
  function submit(event) {
    event.preventDefault(); setFormError('')
    if (!form.roles.length) { setFormError('Select at least one role for this staff account.'); return }
    if (form.password !== form.password_confirmation) { setFormError('Password and confirmation do not match.'); return }
    const payload = { ...form, otp_email: form.otp_email || null }
    if (editing.id && !payload.password) { delete payload.password; delete payload.password_confirmation }
    save.mutate(payload)
  }
  function update(key, value) { setForm((previous) => ({ ...previous, [key]: value })) }
  const columns = [
    { key: 'name', label: 'Staff member', render: (row) => <div className="flex items-center gap-3"><span className="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg bg-brand-dark text-xs font-bold text-white">{initials(row.name)}</span><div><p className="font-semibold text-slate-900">{row.name}{row.id === currentUser?.id && <span className="ml-2 text-xs font-normal text-slate-500">You</span>}</p><p className="text-xs text-slate-500">{row.email}</p></div></div> },
    { key: 'roles', label: 'Roles', render: (row) => <div className="flex flex-wrap gap-1">{roleNames(row.roles).map((role) => <span key={role} className="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset bg-blue-50 text-blue-700 ring-blue-600/20">{role}</span>)}</div> },
    { key: 'is_active', label: 'Status', render: (row) => <div className="flex flex-wrap gap-1"><StatusPill status={row.is_active ? 'active' : 'inactive'} />{row.locked_until && new Date(row.locked_until) > new Date() && <StatusPill status="locked" />}</div> },
    { key: 'otp_email', label: 'Sign-in code email', render: (row) => <span className="text-xs text-slate-500">{row.otp_email || '—'}</span> },
    { key: 'created_at', label: 'Created', render: (row) => <span className="text-xs text-slate-500">{dateTime(row.created_at)}</span> },
    { key: 'actions', label: 'Actions', right: true, render: (row) => <div className="flex items-center justify-end gap-1"><IconButton label={`Edit ${row.name}`} icon="fa-pen" onClick={() => openForm(row)} disabled={action.isPending} /><IconButton label={row.id === currentUser?.id ? 'You cannot deactivate your own account' : `${row.is_active ? 'Deactivate' : 'Activate'} ${row.name}`} icon={row.is_active ? 'fa-user-slash' : 'fa-user-check'} onClick={() => setConfirmation({ type: 'toggle', row })} disabled={action.isPending || row.id === currentUser?.id} /><IconButton label={`Unlock sign-in for ${row.name}`} icon="fa-unlock-keyhole" onClick={() => setConfirmation({ type: 'unlock', row })} disabled={action.isPending} /></div> },
  ]
  return <div className="space-y-6"><PageHeader title="Users & Roles" description="Manage staff access, sign-in codes, and assigned roles."><Button variant="secondary" icon="fa-file-arrow-down" busy={exporter.busy} onClick={() => exporter.exportCsv('/users', filter.params, 'Users')}>{exporter.busy ? 'Exporting...' : 'Export CSV'}</Button><Button icon="fa-plus" onClick={() => openForm(null)}>Create user</Button></PageHeader><Notice notice={notice} /><SummaryCards cards={summaryCards} summary={query.data?.summary} isLoading={query.isPending} /><Tabs tabs={tabs} value={tab} onChange={setTab} />{tab === 'users' ? <ListCard title="Staff accounts" columns={columns} query={query} onPageChange={filter.setPage} empty="No staff accounts found" icon="fa-users" onClear={filter.clear} filters={<><Search value={filter.search} onChange={filter.setSearch} label="Search name or email" /><select className={filterClass} aria-label="Filter by role" value={filter.filters.role} onChange={(event) => filter.change('role', event.target.value)}><option value="">All roles</option>{roles.map((role) => <option key={role.id} value={role.name}>{role.name}</option>)}</select><select className={filterClass} aria-label="Filter by status" value={filter.filters.status} onChange={(event) => filter.change('status', event.target.value)}><option value="">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option><option value="locked">Sign-in locked</option></select></>} /> : <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="roles-title"><h2 id="roles-title" className="text-base font-semibold text-slate-900">Roles & permissions</h2><p className="mt-0.5 text-xs text-slate-500">Assign these roles when creating or editing a staff account.</p>{rolesQuery.isError ? <div className="mt-4 space-y-3"><FormError message={apiError(rolesQuery.error, 'Could not load roles.')} /><Button icon="fa-rotate-right" onClick={() => rolesQuery.refetch()}>Try again</Button></div> : rolesQuery.isPending ? <div className="mt-4 space-y-3">{Array.from({ length: 5 }, (_, index) => <div key={index} className="h-10 animate-pulse rounded-lg bg-slate-100" />)}</div> : <div className="mt-4 divide-y divide-slate-100">{roles.map((role) => <div key={role.id} className="flex items-center justify-between gap-3 py-4"><div><p className="font-semibold text-slate-900">{role.name}</p><p className="mt-0.5 text-xs text-slate-500">{role.permissions?.length || 0} permissions</p></div><Button variant="secondary" icon="fa-eye" onClick={() => setRoleDetail(role)}>View permissions</Button></div>)}</div>}</section>}
    <Modal isOpen={Boolean(editing)} onClose={() => { if (!save.isPending) setEditing(null) }} title={editing?.id ? 'Edit staff account' : 'Create staff account'}><form className="space-y-5" onSubmit={submit}><div className="grid grid-cols-1 gap-4 sm:grid-cols-2"><Field label="Full name" htmlFor="user-name"><input id="user-name" required maxLength={255} className={fieldClass} value={form.name} onChange={(event) => update('name', event.target.value)} /></Field><Field label="Email" htmlFor="user-email"><input id="user-email" type="email" required maxLength={255} className={fieldClass} value={form.email} onChange={(event) => update('email', event.target.value)} /></Field><Field label={editing?.id ? 'New password (optional)' : 'Password'} htmlFor="user-password" help={editing?.id ? 'Leave blank to keep the current password.' : 'At least 8 characters.'}><input id="user-password" type="password" required={!editing?.id} minLength={8} autoComplete="new-password" className={fieldClass} value={form.password} onChange={(event) => update('password', event.target.value)} /></Field><Field label="Confirm password" htmlFor="user-password-confirmation"><input id="user-password-confirmation" type="password" required={Boolean(form.password)} minLength={8} autoComplete="new-password" className={fieldClass} value={form.password_confirmation} onChange={(event) => update('password_confirmation', event.target.value)} /></Field></div><Field label="Sign-in code email (optional)" htmlFor="user-otp-email" help="Email address used for emailed sign-in codes when code verification is enabled."><input id="user-otp-email" type="email" maxLength={255} className={fieldClass} value={form.otp_email} onChange={(event) => update('otp_email', event.target.value)} /></Field>{rolesQuery.isError ? <div className="space-y-3"><FormError message={apiError(rolesQuery.error, 'Roles could not be loaded.')} /><Button variant="secondary" icon="fa-rotate-right" busy={rolesQuery.isFetching} onClick={() => rolesQuery.refetch()}>Try again</Button></div> : <CheckboxGroup title={rolesQuery.isPending ? 'Assigned roles (loading...)' : 'Assigned roles'} options={roles.map((role) => role.name)} value={form.roles} onChange={(value) => update('roles', value)} />}<label className={`flex items-center gap-3 rounded-lg border border-slate-200 bg-slate-50 p-3 ${editing?.id === currentUser?.id ? 'cursor-not-allowed' : 'cursor-pointer'}`}><input type="checkbox" className="h-4 w-4 accent-brand-blue focus:ring-2 focus:ring-blue-100" checked={form.is_active} disabled={editing?.id === currentUser?.id} onChange={(event) => update('is_active', event.target.checked)} /><span><span className="block text-sm font-semibold text-slate-700">Active account</span><span className="block text-xs text-slate-500">{editing?.id === currentUser?.id ? 'This is your account, so it cannot be deactivated here.' : 'Active staff can sign in and use their assigned permissions.'}</span></span></label>{/* Next to the button: this form is taller than a phone screen, and a repeated identical error would not re-scroll. */}<FormError message={formError} /><FormFooter onCancel={() => setEditing(null)} busy={save.isPending} disabled={rolesQuery.isPending || rolesQuery.isError} label={editing?.id ? 'Update user' : 'Create user'} /></form></Modal>
    <ConfirmDialog isOpen={Boolean(confirmation)} onClose={() => { if (!action.isPending) setConfirmation(null) }} onConfirm={() => { if (confirmation && !action.isPending) return action.mutateAsync(confirmation) }} isPending={action.isPending} title={confirmation?.type === 'unlock' ? 'Unlock sign-in' : confirmation?.row.is_active ? 'Deactivate staff account' : 'Activate staff account'} message={confirmation?.type === 'unlock' ? `Clear the failed sign-in attempts and lockout for ${confirmation?.row.name}?` : `${confirmation?.row.is_active ? 'Deactivate' : 'Activate'} ${confirmation?.row.name}'s account?`} confirmLabel={confirmation?.type === 'unlock' ? 'Unlock sign-in' : confirmation?.row.is_active ? 'Deactivate' : 'Activate'} variant={confirmation?.type === 'unlock' || !confirmation?.row.is_active ? 'primary' : 'danger'} />
    <Modal isOpen={Boolean(roleDetail)} onClose={() => setRoleDetail(null)} title={`${roleDetail?.name || ''} permissions`}><ul className="grid grid-cols-1 gap-2 sm:grid-cols-2">{roleDetail?.permissions?.map((permission) => <li key={typeof permission === 'string' ? permission : permission.name} className="text-sm text-slate-600"><i className="fa-solid fa-check mr-2 text-emerald-600" aria-hidden="true" />{typeof permission === 'string' ? permission : permission.name}</li>)}</ul></Modal>
  </div>
}

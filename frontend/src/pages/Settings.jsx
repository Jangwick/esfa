import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import api from '../lib/api'
import { useAuthStore } from '../store/authStore'
import { AUTOMATIONS, apiError, centavosToPesos, dateTime, fieldClass, pesosToCentavos, roleNames, unwrap } from '../lib/admin'
import { label } from '../lib/payments'
import { Button, Field, FormError, Notice, PageHeader, StatusPill, Tabs } from '../components/admin/AdminUI'
import { useNotice, useUrlState } from '../lib/adminHooks'

const emptyPassword = { current_password: '', password: '', password_confirmation: '' }
const automationSettings = AUTOMATIONS.filter((rule) => rule.setting)
const automationModules = [...new Set(AUTOMATIONS.map((rule) => rule.module))]
const skeleton = (rows) => <div className="mt-5 space-y-4">{Array.from({ length: rows }, (_, index) => <div key={index} className="h-10 animate-pulse rounded-lg bg-slate-100" />)}</div>
export default function Settings() {
  const user = useAuthStore((state) => state.user)
  const setUser = useAuthStore((state) => state.setUser)
  const hasRole = useAuthStore((state) => state.hasRole)
  const isAdmin = hasRole('Admin')
  const queryClient = useQueryClient()
  const tabs = [{ key: 'profile', label: 'Profile' }, { key: 'security', label: 'Security' }, ...(isAdmin ? [{ key: 'system', label: 'System settings' }, { key: 'automation', label: 'Automation' }] : [])]
  const [url, setUrl] = useUrlState({ tab: 'profile' })
  const tab = tabs.some((item) => item.key === url.tab) ? url.tab : 'profile'
  const [profile, setProfile] = useState({ name: user?.name || '', email: user?.email || '' })
  const [password, setPassword] = useState(emptyPassword)
  const [settings, setSettings] = useState(null)
  const [rules, setRules] = useState(null)
  const [runResult, setRunResult] = useState(null)
  const [profileError, setProfileError] = useState('')
  const [passwordError, setPasswordError] = useState('')
  const [settingsError, setSettingsError] = useState('')
  const [rulesError, setRulesError] = useState('')
  const [runError, setRunError] = useState('')
  const [notice, notify] = useNotice()
  const profileQuery = useQuery({ queryKey: ['profile'], queryFn: async () => unwrap(await api.get('/profile')) })
  const settingsQuery = useQuery({ queryKey: ['settings'], queryFn: async () => unwrap(await api.get('/settings')), enabled: isAdmin && (tab === 'system' || tab === 'automation') })
  const automationQuery = useQuery({ queryKey: ['automation'], queryFn: async () => unwrap(await api.get('/automation')), enabled: isAdmin && tab === 'automation' })
  // Choices for 'plan' rules (the free default plan): the same cached options the Subscriptions page loads.
  const plansQuery = useQuery({ queryKey: ['billing', 'options'], queryFn: async () => (await api.get('/billing/options')).data, enabled: isAdmin && tab === 'automation' })
  const automation = automationQuery.data
  const last = automation?.last_result
  useEffect(() => { if (profileQuery.data) setProfile({ name: profileQuery.data.name || '', email: profileQuery.data.email || '' }) }, [profileQuery.data])
  useEffect(() => {
    if (!settingsQuery.data) return
    setSettings({ ...settingsQuery.data, risk_amount_threshold: centavosToPesos(settingsQuery.data.risk_amount_threshold) })
    setRules(Object.fromEntries(automationSettings.map(({ setting, type }) => [setting, type === 'money' ? centavosToPesos(settingsQuery.data[setting]) : settingsQuery.data[setting]])))
  }, [settingsQuery.data])
  const saveProfile = useMutation({
    mutationFn: (payload) => api.put('/profile', payload),
    onSuccess: (response) => { const updated = unwrap(response); setUser(updated.user || updated); setProfileError(''); notify('Your profile has been updated.'); queryClient.invalidateQueries({ queryKey: ['profile'] }) },
    onError: (error) => setProfileError(apiError(error, 'Could not update your profile.')),
  })
  const changePassword = useMutation({
    mutationFn: (payload) => api.post('/change-password', payload),
    onSuccess: () => { setPassword(emptyPassword); setPasswordError(''); notify('Your password has been changed.') },
    onError: (error) => setPasswordError(apiError(error, 'Could not change your password.')),
  })
  const saveSettings = useMutation({
    mutationFn: (payload) => api.put('/settings', payload),
    onSuccess: (response) => { setUser({ ...useAuthStore.getState().user, business_timezone: unwrap(response).business_timezone }); setSettingsError(''); notify('System settings have been updated.'); queryClient.invalidateQueries({ queryKey: ['settings'] }) },
    onError: (error) => setSettingsError(apiError(error, 'Could not save system settings.')),
  })
  const saveRules = useMutation({
    mutationFn: (payload) => api.put('/settings', payload),
    // Confirmed next to the Save button (saveRules.isSuccess): a notice at the top of this long page is out of view.
    onSuccess: () => { setRulesError(''); queryClient.invalidateQueries({ queryKey: ['settings'] }); queryClient.invalidateQueries({ queryKey: ['session'] }) },
    onError: (error) => setRulesError(apiError(error, 'Could not save automation rules.')),
  })
  const runNow = useMutation({
    mutationFn: async () => unwrap(await api.post('/automation/run')),
    onSuccess: (result) => {
      setRunError(''); setRunResult(result.ran ? result : null)
      notify(result.ran ? 'Scheduled work finished.' : 'Scheduled work is already running. Try again in a few minutes.', !result.ran)
      queryClient.invalidateQueries({ queryKey: ['automation'] })
    },
    onError: (error) => setRunError(apiError(error, 'Could not run scheduled work.')),
  })
  function submitPassword(event) {
    event.preventDefault(); setPasswordError('')
    if (password.password !== password.password_confirmation) { setPasswordError('New password and confirmation do not match.'); return }
    changePassword.mutate(password)
  }
  function submitSettings(event) {
    event.preventDefault(); setSettingsError('')
    try {
      // Only this tab's keys: automation rules are saved from their own tab, and SLA hours from Support > SLA policies.
      const { company_name, support_email, business_timezone, settlement_schedule } = settings
      saveSettings.mutate({ company_name, support_email, business_timezone, currency: 'PHP', risk_failed_attempts: Number(settings.risk_failed_attempts), risk_amount_threshold: pesosToCentavos(settings.risk_amount_threshold), settlement_schedule, volumetric_divisor: Number(settings.volumetric_divisor) })
    } catch (error) { setSettingsError(error.message) }
  }
  function updateSettings(key, value) { setSettings((previous) => ({ ...previous, [key]: value })) }
  function submitRules(event) {
    event.preventDefault(); setRulesError('')
    try { saveRules.mutate(Object.fromEntries(automationSettings.map(({ setting, type }) => [setting, type === 'boolean' ? Boolean(rules[setting]) : type === 'money' ? pesosToCentavos(rules[setting]) : type === 'options' ? rules[setting] || [] : type === 'plan' ? (rules[setting] ? Number(rules[setting]) : null) : Number(rules[setting])]))) } catch (error) { setRulesError(error.message) }
  }
  function updateRule(key, value) { saveRules.reset(); setRules((previous) => ({ ...previous, [key]: value })) }
  function ruleControl(rule) {
    if (!rule.setting) return <StatusPill status="active">Always on</StatusPill>
    if (rule.type === 'options') { const chosen = rules[rule.setting] || []; return <fieldset className="space-y-1.5"><legend className="sr-only">{rule.title}</legend>{rule.options.map((option) => <label key={option} className="flex cursor-pointer items-center gap-2 text-sm text-slate-700"><input type="checkbox" className="h-4 w-4 accent-brand-blue focus:ring-2 focus:ring-blue-100" checked={chosen.includes(option)} onChange={(event) => updateRule(rule.setting, event.target.checked ? [...chosen, option] : chosen.filter((item) => item !== option))} />{label(option)}</label>)}</fieldset> }
    if (rule.type === 'plan') {
      // Only plans the server accepts: active, both prices 0 and no commission rate of their own (plus the saved one).
      const plans = (plansQuery.data?.plans || []).filter((plan) => String(plan.id) === String(rules[rule.setting]) || (plan.is_active && !plan.monthly_price_amount && !plan.annual_price_amount && plan.commission_rate_bp === null))
      if (plansQuery.isError) return <div className="space-y-2"><p className="text-xs text-red-700">{apiError(plansQuery.error, 'Could not load plans.')}</p><Button variant="secondary" icon="fa-rotate-right" onClick={() => plansQuery.refetch()}>Try again</Button></div>
      return <Field label={rule.unit} htmlFor={`rule-${rule.key}`} help={plansQuery.isSuccess && !plans.length ? 'No active free plan yet.' : ''}><select id={`rule-${rule.key}`} aria-label={`${rule.title}: ${rule.unit}`} className={fieldClass} disabled={plansQuery.isPending} value={rules[rule.setting] ?? ''} onChange={(event) => updateRule(rule.setting, event.target.value)}><option value="">{plansQuery.isPending ? 'Loading plans...' : 'Off'}</option>{plans.map((plan) => <option key={plan.id} value={plan.id}>{plan.name}</option>)}</select></Field>
    }
    if (rule.type === 'boolean') return <label className="inline-flex cursor-pointer items-center gap-2 text-sm font-semibold text-slate-700"><input type="checkbox" aria-label={rule.title} className="h-4 w-4 accent-brand-blue focus:ring-2 focus:ring-blue-100" checked={Boolean(rules[rule.setting])} onChange={(event) => updateRule(rule.setting, event.target.checked)} />{rules[rule.setting] ? 'On' : 'Off'}</label>
    const unit = rule.unit || (rule.type === 'money' ? 'Amount (PHP)' : 'Value (0 = off)')
    return <Field label={unit} htmlFor={`rule-${rule.key}`}><input id={`rule-${rule.key}`} aria-label={`${rule.title}: ${unit}`} required className={fieldClass} {...(rule.type === 'money' ? { inputMode: 'decimal', pattern: '[0-9]+([.][0-9]{1,2})?' } : { type: 'number', min: '0', step: '1' })} value={rules[rule.setting] ?? ''} onChange={(event) => updateRule(rule.setting, event.target.value)} /></Field>
  }
  // Whether Gemini is set up (the key itself never reaches the browser) and today's use against the daily limit.
  function aiStatus() {
    const ai = automation?.ai
    if (!ai) return null
    const usd = ai.today.estimated_usd.toLocaleString('en-US', { style: 'currency', currency: 'USD', maximumFractionDigits: 4 })
    return <p className="mt-1 text-xs text-slate-500">{ai.configured ? <><StatusPill status="active">Key set</StatusPill> <span className="ml-1">Model {ai.model}{ai.fallback_model ? `, ${ai.fallback_model} when it is busy` : ''}. Today: {ai.today.calls} {ai.today.calls === 1 ? 'request' : 'requests'}, {ai.today.tokens.toLocaleString()} tokens, about {usd} on the paid tier.</span></> : <><StatusPill status="disabled">Not set up</StatusPill> <span className="ml-1">Set SFA_GEMINI_API_KEY on the server (backend/.env, or Vercel for production) to use the AI features.</span></>}</p>
  }
  function lastRun(rule) {
    if (!rule.results) return ''
    return ` · Last run: ${last ? rule.results.map((key) => `${label(key).toLowerCase()} ${last[key] ?? '—'}`).join(', ') : 'not yet'}`
  }
  return <div className="space-y-6"><PageHeader title="Settings" description="Manage your profile, security, and system preferences." /><Notice notice={notice} /><Tabs tabs={tabs} value={tab} onChange={(key) => setUrl({ tab: key })} />
    {tab === 'profile' && <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="profile-heading"><h2 id="profile-heading" className="text-base font-semibold text-slate-900">Your profile</h2><p className="mt-0.5 text-xs text-slate-500">Your name and the email address you use to sign in.</p>{profileQuery.isError ? <div className="mt-5 space-y-3"><FormError message={apiError(profileQuery.error, 'Could not load your profile.')} /><Button icon="fa-rotate-right" onClick={() => profileQuery.refetch()}>Try again</Button></div> : profileQuery.isPending ? <div className="mt-5 space-y-4">{Array.from({ length: 3 }, (_, index) => <div key={index} className="h-10 animate-pulse rounded-lg bg-slate-100" />)}</div> : <form className="mt-5 space-y-5" onSubmit={(event) => { event.preventDefault(); setProfileError(''); saveProfile.mutate(profile) }}><FormError message={profileError} /><div className="grid grid-cols-1 gap-4 sm:grid-cols-2"><Field label="Full name" htmlFor="profile-name"><input id="profile-name" required maxLength={255} autoComplete="name" className={fieldClass} value={profile.name} onChange={(event) => setProfile({ ...profile, name: event.target.value })} /></Field><Field label="Email" htmlFor="profile-email"><input id="profile-email" type="email" required maxLength={255} autoComplete="email" className={fieldClass} value={profile.email} onChange={(event) => setProfile({ ...profile, email: event.target.value })} /></Field></div><div><p className="text-xs font-semibold text-slate-700">Assigned roles</p><p className="mt-1.5 text-sm text-slate-600">{roleNames(profileQuery.data?.roles || user?.roles).join(', ') || '—'}</p><p className="mt-1 text-xs text-slate-500">Contact an administrator to change your access.</p></div><div className="flex justify-end border-t border-slate-200 pt-4"><Button type="submit" busy={saveProfile.isPending}>{saveProfile.isPending ? 'Saving...' : 'Save profile'}</Button></div></form>}</section>}
    {tab === 'security' && <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="security-heading"><h2 id="security-heading" className="text-base font-semibold text-slate-900">Change password</h2><p className="mt-0.5 text-xs text-slate-500">Use a password with at least 8 characters.</p><form className="mt-5 space-y-5" onSubmit={submitPassword}><FormError message={passwordError} /><Field label="Current password" htmlFor="current-password"><input id="current-password" type="password" autoComplete="current-password" required className={fieldClass} value={password.current_password} onChange={(event) => setPassword({ ...password, current_password: event.target.value })} /></Field><div className="grid grid-cols-1 gap-4 sm:grid-cols-2"><Field label="New password" htmlFor="new-password"><input id="new-password" type="password" autoComplete="new-password" required minLength={8} className={fieldClass} value={password.password} onChange={(event) => setPassword({ ...password, password: event.target.value })} /></Field><Field label="Confirm new password" htmlFor="confirm-new-password"><input id="confirm-new-password" type="password" autoComplete="new-password" required minLength={8} className={fieldClass} value={password.password_confirmation} onChange={(event) => setPassword({ ...password, password_confirmation: event.target.value })} /></Field></div><div className="flex justify-end border-t border-slate-200 pt-4"><Button type="submit" icon="fa-key" busy={changePassword.isPending}>{changePassword.isPending ? 'Saving...' : 'Change password'}</Button></div></form></section>}
    {tab === 'system' && isAdmin && <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="settings-heading"><h2 id="settings-heading" className="text-base font-semibold text-slate-900">System settings</h2><p className="mt-0.5 text-xs text-slate-500">Shared business rules for support, finance, and logistics.</p>{settingsQuery.isError ? <div className="mt-5 space-y-3"><FormError message={apiError(settingsQuery.error, 'Could not load system settings.')} /><Button icon="fa-rotate-right" onClick={() => settingsQuery.refetch()}>Try again</Button></div> : !settings || settingsQuery.isPending ? <div className="mt-5 space-y-4">{Array.from({ length: 6 }, (_, index) => <div key={index} className="h-10 animate-pulse rounded-lg bg-slate-100" />)}</div> : <form className="mt-5 space-y-5" onSubmit={submitSettings}><div className="grid grid-cols-1 gap-4 sm:grid-cols-2"><Field label="Company name" htmlFor="company-name"><input id="company-name" required maxLength={255} className={fieldClass} value={settings.company_name || ''} onChange={(event) => updateSettings('company_name', event.target.value)} /></Field><Field label="Support email" htmlFor="support-email"><input id="support-email" type="email" required maxLength={255} className={fieldClass} value={settings.support_email || ''} onChange={(event) => updateSettings('support_email', event.target.value)} /></Field><Field label="Business time zone" htmlFor="business-timezone" help="Used for business days, reports and settlement periods."><select id="business-timezone" required className={fieldClass} value={settings.business_timezone || ''} onChange={(event) => updateSettings('business_timezone', event.target.value)}><option value="">Select a time zone</option>{(settings.timezones || []).map((zone) => <option key={zone} value={zone}>{zone.replaceAll('_', ' ')}</option>)}</select></Field><Field label="Currency" htmlFor="business-currency" help="Fixed: marketplace amounts use Philippine pesos."><input id="business-currency" disabled className={fieldClass} value="PHP" /></Field></div><p className="text-xs text-slate-500">SLA hours are edited in <Link to="/support?tab=policies" className="font-medium text-brand-blue">Support &gt; SLA policies</Link>. Ticket auto-assignment is on the Automation tab.</p><div className="grid grid-cols-1 gap-4 sm:grid-cols-2"><Field label="Failed payment attempts per hour" htmlFor="risk-attempts" help="Payments exceeding this threshold require finance review."><input id="risk-attempts" required type="number" min="1" step="1" className={fieldClass} value={settings.risk_failed_attempts ?? ''} onChange={(event) => updateSettings('risk_failed_attempts', event.target.value)} /></Field><Field label="Payment review threshold (PHP)" htmlFor="risk-amount" help="Enter pesos with up to two decimal places."><input id="risk-amount" required inputMode="decimal" pattern="[0-9]+([.][0-9]{1,2})?" className={fieldClass} value={settings.risk_amount_threshold} onChange={(event) => updateSettings('risk_amount_threshold', event.target.value)} /></Field><Field label="Settlement schedule" htmlFor="settlement-schedule"><select id="settlement-schedule" required className={fieldClass} value={settings.settlement_schedule || ''} onChange={(event) => updateSettings('settlement_schedule', event.target.value)}><option value="weekly">Weekly</option><option value="monthly">Monthly</option></select></Field><Field label="Default volumetric divisor" htmlFor="volumetric-divisor" help="Used to calculate chargeable weight for courier rates."><input id="volumetric-divisor" required type="number" min="1" step="1" className={fieldClass} value={settings.volumetric_divisor ?? ''} onChange={(event) => updateSettings('volumetric_divisor', event.target.value)} /></Field></div><FormError message={settingsError} /><div className="flex justify-end border-t border-slate-200 pt-4"><Button type="submit" busy={saveSettings.isPending}>{saveSettings.isPending ? 'Saving...' : 'Save system settings'}</Button></div></form>}</section>}
    {tab === 'automation' && isAdmin && <>
      <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="scheduler-heading">
        <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"><div><h2 id="scheduler-heading" className="text-base font-semibold text-slate-900">Scheduled work</h2><p className="mt-0.5 text-xs text-slate-500">Scheduled rules run on their own within minutes. Run due work now processes everything that is due immediately.</p></div><Button icon="fa-clock-rotate-left" busy={runNow.isPending} disabled={!automation?.enabled || automation?.running} onClick={() => { setRunError(''); runNow.mutate() }}>{runNow.isPending ? 'Running...' : 'Run due work now'}</Button></div>
        {automationQuery.isError ? <div className="mt-5 space-y-3"><FormError message={apiError(automationQuery.error, 'Could not load the scheduler status.')} /><Button icon="fa-rotate-right" onClick={() => automationQuery.refetch()}>Try again</Button></div> : automationQuery.isPending ? skeleton(2) : <div className="mt-5 space-y-4">
          <dl className="grid grid-cols-1 gap-3 text-sm sm:grid-cols-3"><div><dt className="text-xs font-semibold text-slate-700">Status</dt><dd className="mt-1.5">{automation.running ? <StatusPill status="processing">Running</StatusPill> : automation.enabled ? <StatusPill status="active">Active</StatusPill> : <StatusPill status="disabled">Paused</StatusPill>}</dd></div><div><dt className="text-xs font-semibold text-slate-700">Last run</dt><dd className="mt-1.5 text-slate-600">{automation.last_run_at ? dateTime(last?.finished_at || automation.last_run_at) : 'Not run yet'}</dd></div><div><dt className="text-xs font-semibold text-slate-700">Failed jobs in the last run</dt><dd className={`mt-1.5 ${last?.failed_jobs?.length ? 'font-semibold text-red-700' : 'text-slate-600'}`}>{last?.failed_jobs?.length ? `${last.failed_jobs.map(label).join(', ')} (details are in the error log)` : 'None'}</dd></div></dl>
          {!automation.enabled && <p className="text-xs text-slate-500">Scheduled work is paused by SFA_SCHEDULER_ENABLED=false. Nothing runs until it is turned back on.</p>}
          <FormError message={runError} />
          {runResult && <dl className="grid grid-cols-2 gap-2 text-xs sm:grid-cols-4">{Object.entries(runResult).filter(([, value]) => ['number', 'boolean'].includes(typeof value)).map(([key, value]) => <div key={key} className="rounded-lg bg-slate-50 px-3 py-2"><dt className="text-slate-500">{label(key)}</dt><dd className="font-semibold text-slate-900">{String(value)}</dd></div>)}</dl>}
        </div>}
      </section>
      <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="rules-heading"><h2 id="rules-heading" className="text-base font-semibold text-slate-900">Automation rules</h2><p className="mt-0.5 text-xs text-slate-500">Everything SFA does on its own. Rules with a switch or a number can be changed; integrity jobs are always on.</p>
        {settingsQuery.isError ? <div className="mt-5 space-y-3"><FormError message={apiError(settingsQuery.error, 'Could not load automation rules.')} /><Button icon="fa-rotate-right" onClick={() => settingsQuery.refetch()}>Try again</Button></div> : !rules || settingsQuery.isPending ? skeleton(6) : <form className="mt-5 space-y-5" onSubmit={submitRules}>
          {automationModules.map((module) => <div key={module}><h3 className="text-xs font-semibold uppercase tracking-wider text-slate-500">{module}</h3>{module === 'AI assistant' && aiStatus()}<ul className="mt-1 divide-y divide-slate-100">{AUTOMATIONS.filter((rule) => rule.module === module).map((rule) => <li key={rule.key} className="flex flex-col gap-3 py-3 sm:flex-row sm:items-center sm:justify-between"><div className="min-w-0"><p className="text-sm font-semibold text-slate-700">{rule.title}</p><p className="mt-0.5 text-xs text-slate-500">{rule.description}</p><p className="mt-1 text-xs text-slate-500"><i className="fa-solid fa-bolt mr-1 text-slate-400" aria-hidden="true" />{rule.trigger}{lastRun(rule)}</p></div><div className={`shrink-0 sm:w-44 ${rule.setting && rule.type !== 'boolean' ? '' : 'sm:text-right'}`}>{ruleControl(rule)}</div></li>)}</ul></div>)}
          <FormError message={rulesError} />{saveRules.isSuccess && <Notice notice={{ message: 'Automation rules have been updated.' }} />}
          <div className="flex justify-end border-t border-slate-200 pt-4"><Button type="submit" busy={saveRules.isPending}>{saveRules.isPending ? 'Saving...' : 'Save automation rules'}</Button></div></form>}
      </section>
    </>}
  </div>
}

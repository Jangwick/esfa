import { businessTimezone } from '../../lib/admin'
import { CREDENTIAL_LABELS, useSimulator } from '../../lib/simulator'
import { Button, JsonView, StatusPill } from '../admin/AdminUI'
import { Empty } from './SimUI'

// Every integration call and signed webhook this page sent in this session, newest first. Credentials are never kept.
export function TrafficLog() {
  const { traffic, clearTraffic } = useSimulator()
  const time = (value) => new Intl.DateTimeFormat('en-PH', { timeStyle: 'medium', timeZone: businessTimezone() }).format(new Date(value))
  return <section className="space-y-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="sim-traffic">
    <div className="flex flex-wrap items-start justify-between gap-3"><div><h2 id="sim-traffic" className="text-base font-semibold text-slate-900">Integration traffic</h2><p className="mt-1 text-xs text-slate-500">The real HTTP calls this page made to SFA as the outside systems, newest first (the last 50). The token and the signature value are never shown. Your browser's network tab shows the same calls.</p></div><Button variant="secondary" icon="fa-eraser" disabled={!traffic.length} onClick={clearTraffic}>Clear</Button></div>
    {traffic.length ? <ul className="space-y-2">{traffic.map((entry) => <li key={entry.id}><details className="rounded-lg border border-slate-200 p-3"><summary className="flex cursor-pointer flex-wrap items-center gap-x-3 gap-y-1 text-xs"><span className="tabular-nums text-slate-500">{time(entry.at)}</span><span className="font-semibold text-slate-700">{CREDENTIAL_LABELS[entry.credential]}</span><span className="min-w-0 flex-1 break-all font-mono font-semibold text-slate-900">{entry.method} {entry.path}</span><StatusPill status={entry.status >= 200 && entry.status < 300 ? 'delivered' : 'failed'}>{entry.status ? `HTTP ${entry.status}` : 'No answer'}</StatusPill></summary><div className="mt-3 grid gap-3 lg:grid-cols-2"><div><p className="mb-1 text-xs font-semibold text-slate-600">Request</p><JsonView value={entry.request} /></div><div><p className="mb-1 text-xs font-semibold text-slate-600">Response</p><JsonView value={entry.response} /></div></div></details></li>)}</ul> : <Empty>No calls yet. Send or read something above and it appears here.</Empty>}
  </section>
}

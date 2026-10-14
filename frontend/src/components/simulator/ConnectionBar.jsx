import { apiError, businessTimezone, dateTime, statusColors } from '../../lib/admin'
import { CREDENTIAL_LABELS, PARTNER_COMMAND, SIGNING_UNAVAILABLE, canSign, useSimulator } from '../../lib/simulator'
import { Button, FormError } from '../admin/AdminUI'

function Chip({ ok, warn, icon, children }) {
  return <li className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset ${statusColors[ok ? 'active' : warn ? 'pending' : 'failed']}`}><i className={`fa-solid ${icon}`} aria-hidden="true" />{children}</li>
}

// POST /simulator/connect issues the storefront and finance systems fresh tokens (revoking the previous simulator ones)
// and hands over the signing secrets. The page keeps them in React state; the storefront's receiver gets its copy too.
export function ConnectionBar({ connect, connection, partnerUrl }) {
  const receiver = connection?.receiver
  const { traffic } = useSimulator()
  const time = (value) => new Intl.DateTimeFormat('en-PH', { timeStyle: 'medium', timeZone: businessTimezone() }).format(new Date(value))
  // A call SFA refused as unauthenticated since this page last connected: the chips below no longer hold (another
  // Simulator tab connecting replaces the tokens), so say so here, beside the Reconnect button.
  const rejected = traffic.find((entry) => entry.status === 401 && (!connect.submittedAt || Date.parse(entry.at) > connect.submittedAt))
  return <section className="space-y-3 rounded-xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="sim-connection">
    <div className="flex flex-wrap items-start justify-between gap-3"><div><h2 id="sim-connection" className="text-base font-semibold text-slate-900">Connection to SFA</h2><p className="mt-1 max-w-3xl text-xs text-slate-500">Opening this page or pressing Reconnect gives each system a fresh 12-hour token and loads the signing secrets. The webhook receiver gets the storefront's copy too (saved in tools/partner-simulator/storage). Each connect replaces the previous tokens, so use one Simulator tab at a time: an older tab, or a receiver that was offline when you connected, gets "rejected the credentials" until you press Reconnect.</p></div><Button icon="fa-plug" busy={connect.isPending} onClick={() => connect.mutate()}>{connect.isPending ? 'Connecting...' : connection ? 'Reconnect' : 'Connect'}</Button></div>
    {connect.isError && <FormError message={apiError(connect.error, 'Could not connect the simulated systems to SFA.')} />}
    {!connection ? connect.isPending && <div className="flex flex-wrap gap-2">{[0, 1, 2, 3].map((index) => <div key={index} className="h-7 w-36 animate-pulse rounded-full bg-slate-100" />)}</div>
      : <><ul className="flex flex-wrap gap-2" aria-label="Connection status">
        <Chip ok={Boolean(connection.systems?.storefront?.token)} icon="fa-store">Storefront (Group 2) {connection.systems?.storefront?.token ? 'connected' : 'not connected'}</Chip>
        <Chip ok={Boolean(connection.systems?.finance?.token)} icon="fa-landmark">Finance (Group 10) {connection.systems?.finance?.token ? 'connected, read only' : 'not connected'}</Chip>
        <Chip ok={Boolean(connection.courier)} warn icon="fa-truck">{connection.courier ? `Courier ${connection.courier.code}` : 'No active courier'}</Chip>
        <Chip ok={Boolean(connection.gateway?.secret)} warn icon="fa-building-columns">{connection.gateway?.secret ? 'Gateway signing ready' : 'Set SFA_SANDBOX_WEBHOOK_SECRET in backend/.env'}</Chip>
        <Chip ok={Boolean(receiver?.online)} warn icon="fa-satellite-dish">Webhook receiver {receiver?.online ? 'online' : 'offline'} when connected</Chip>
        {!canSign() && <Chip icon="fa-lock">Signing unavailable</Chip>}
        {/* The connect time (with seconds) changes on every Reconnect, so a press always shows that fresh tokens came back. */}
        {connection.expires_at && <Chip ok icon="fa-clock">{connect.isSuccess ? `Connected ${time(connect.submittedAt)}; tokens` : 'Tokens'} expire {dateTime(connection.expires_at)}</Chip>}
      </ul>
      {rejected && <p role="alert" className="text-xs font-medium text-red-700"><i className="fa-solid fa-circle-exclamation mr-1" aria-hidden="true" />At {time(rejected.at)} SFA rejected the {CREDENTIAL_LABELS[rejected.credential]} credentials, usually because another Simulator tab connected since this page did. Press Reconnect to load fresh ones.</p>}
      {!canSign() && <p className="text-xs text-red-700">{SIGNING_UNAVAILABLE}</p>}
      {!receiver?.online && <p className="text-xs text-slate-600">The storefront's webhook receiver did not answer, so SFA's webhooks to it wait and are retried later, further apart each time (up to 12 hours), and fail after 6 tries. After 20 failures in a row SFA turns the receiver off and stops creating its webhooks. Start it with <code className="break-all rounded bg-slate-100 px-1">{PARTNER_COMMAND}</code> from the project folder, then press Reconnect: SFA then sends every waiting and failed webhook again at once.</p>}
      {partnerUrl && <a href={partnerUrl} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1.5 text-sm font-semibold text-brand-blue hover:text-blue-900">Open the partner app ({partnerUrl}) <i className="fa-solid fa-arrow-up-right-from-square" aria-hidden="true" /></a>}</>}
  </section>
}

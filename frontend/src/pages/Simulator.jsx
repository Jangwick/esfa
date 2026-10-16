import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import api from '../lib/api'
import { apiError } from '../lib/admin'
import { useUrlState } from '../lib/adminHooks'
import { label } from '../lib/payments'
import { GuideKey, HOW_IT_WORKS, SYSTEMS, SimulatorContext, clockOffset, isLocalPartner } from '../lib/simulator'
import { Button, FormError, PageHeader, Tabs } from '../components/admin/AdminUI'
import { ConnectionBar } from '../components/simulator/ConnectionBar'
import { ReceivedEvents } from '../components/simulator/ReceivedEvents'
import { ReceivedWebhooks } from '../components/simulator/ReceivedWebhooks'
import { TrafficLog } from '../components/simulator/TrafficLog'
import { Guide, GuideButton } from '../components/simulator/SimUI'
import { CancelOrderSend, CategorySend, CsatSend, CustomerSend, OrderSend, OrderStatusSend, PaymentSend, QuoteSend, RefundSend, SellerAccountSend, StoreProfileSend, TicketReplySend, TicketSend } from '../components/simulator/StorefrontSends'
import { CourierPickup, CourierTrip, GatewayEvent, SettlementFile } from '../components/simulator/SignedSends'
import { AnalyticsSummary, CourierShipments, KbArticles, PaymentLookup, RefundLookup, SellerPlans, SellerStatements, ShipmentLookup, TicketLookup } from '../components/simulator/Reads'

// Process simulator (Admin, test servers only): one tab per outside system that exchanges data with SFA, one sub-tab per
// part of that system, and in each only what it sends to SFA and receives from it. Sends use the real integration API
// with the system's own token, or real signed webhooks, straight from this browser. The catalog is in lib/simulator.js.
const SENDS = { customer: CustomerSend, sellerAccount: SellerAccountSend, ticket: TicketSend, ticketReply: TicketReplySend, csat: CsatSend, category: CategorySend, storeProfile: StoreProfileSend, order: OrderSend, quote: QuoteSend, payment: PaymentSend, orderStatus: OrderStatusSend, cancelOrder: CancelOrderSend, refund: RefundSend, courierPickup: CourierPickup, courierTrip: CourierTrip, gatewayEvent: GatewayEvent, settlementFile: SettlementFile }
const READS = { ticketLookup: TicketLookup, kbArticles: KbArticles, plans: SellerPlans, statements: SellerStatements, analytics: AnalyticsSummary, paymentLookup: PaymentLookup, shipmentLookup: ShipmentLookup, refundLookup: RefundLookup, courierShipments: CourierShipments }
const card = 'rounded-xl border border-slate-200 bg-white p-5 shadow-sm'

// Scheduler::run() answers with counters (most of them 0), ran/paused flags and failed_jobs (the job names that threw).
function ScheduledResult({ result }) {
  const failed = result.failed_jobs || []
  const counts = Object.entries(result).filter(([, value]) => typeof value === 'number' && value > 0)
  const text = result.paused ? 'Scheduled work is paused on this server (SFA_SCHEDULER_ENABLED=false).'
    : result.ran === false ? 'Scheduled work is already running. Try again in a few minutes.'
      : failed.length ? `Scheduled work finished, but these jobs failed: ${failed.map(label).join(', ')}. The details are in backend/storage/logs/laravel.log.`
        : counts.length ? 'Scheduled work finished. What it did:' : 'Scheduled work finished. Nothing was due.'
  return <>
    <p role="status" className={`text-sm font-medium ${failed.length ? 'text-red-700' : 'text-slate-700'}`}>{text}</p>
    {counts.length > 0 && <dl className="grid grid-cols-2 gap-2 text-xs sm:grid-cols-3 lg:grid-cols-4">{counts.map(([key, value]) => <div key={key} className="rounded-lg bg-slate-50 px-3 py-2"><dt className="text-slate-500">{label(key)}</dt><dd className="font-semibold text-slate-900">{value}</dd></div>)}</dl>}
  </>
}

export default function Simulator() {
  const client = useQueryClient()
  const [url, setUrl] = useUrlState({ system: SYSTEMS[0].key, module: '' })
  const [connection, setConnection] = useState(null)
  const [traffic, setTraffic] = useState([])
  const [howOpen, setHowOpen] = useState(false)
  // The pickers say what SFA accepts right now, so they are never served stale: staff may change a record in another tab
  // (mark a parcel ready, approve a refund) or scheduled work may move it on (expiry, auto-capture).
  const overview = useQuery({ queryKey: ['simulator'], queryFn: async () => (await api.get('/simulator')).data, staleTime: 0, refetchOnWindowFocus: true, retry: (count, error) => error.response?.status !== 404 && count < 1 })
  const connect = useMutation({ mutationFn: async () => (await api.post('/simulator/connect')).data, onSuccess: (data) => { setConnection({ ...data, clock_offset: clockOffset(data.expires_at) }); client.invalidateQueries({ queryKey: ['simulator'] }); client.invalidateQueries({ queryKey: ['simulator-simrx'] }) } })
  // Its result shows only after a run succeeds (runScheduled.data), never beside a run in progress or a failed one.
  const runScheduled = useMutation({ mutationFn: async () => (await api.post('/simulator/run-scheduled')).data, onSuccess: () => client.invalidateQueries() })
  // Connect once when the page opens. The timer lets React's development double effect cancel its first run: two connects
  // would revoke the first tokens, and a mutation started before that re-mount never reports back (Connecting... forever).
  const connectNow = connect.mutate
  useEffect(() => { const timer = setTimeout(connectNow); return () => clearTimeout(timer) }, [connectNow])

  const system = SYSTEMS.find((item) => item.key === url.system) || SYSTEMS[0]
  const module = system.modules.find((item) => item.key === url.module) || system.modules[0]
  const scopes = connection?.systems?.[system.credential]?.scopes
  const sim = {
    connection, pickers: overview.data, traffic,
    log: (entry) => setTraffic((list) => [entry, ...list].slice(0, 50)),
    clearTraffic: () => setTraffic([]),
    // Whatever a system sends shows up in other modules and in the pickers, so every cached list is refreshed.
    refresh: () => client.invalidateQueries(),
    reconnect: connect, connectedAt: connect.isSuccess ? connect.submittedAt : 0,
  }
  const reload = () => { overview.refetch() }

  const header = <PageHeader title="Process Simulator" description="Plays the outside systems that exchange data with SFA, so you can show real API calls and signed webhooks going in and out. Pick a system, then one of its parts: each shows only what that part sends to SFA and what it gets back." />
  if (overview.error?.response?.status === 404) return <div className="space-y-6">{header}<section className={card}><h2 className="text-base font-semibold text-slate-900">The simulator is turned off</h2><p className="mt-2 text-sm text-slate-600">It is off in production. Set SFA_SIMULATOR=true on a test server to use it.</p></section></div>
  // A failed reload keeps the page (and every form's input) when lists were already loaded; only a first load replaces it.
  const loadError = overview.isError && <div className="flex flex-col gap-3 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800 sm:flex-row sm:items-center sm:justify-between" role="alert"><div className="flex items-start gap-3"><i className="fa-solid fa-circle-exclamation mt-0.5" aria-hidden="true" /><div><p className="font-semibold">{overview.data ? 'The lists could not be refreshed; they show what SFA had before' : 'The simulator could not be loaded'}</p><p className="mt-0.5 text-red-700">{apiError(overview.error)}</p></div></div><button type="button" onClick={reload} className="inline-flex items-center justify-center gap-2 rounded-lg border border-red-300 bg-white px-3 py-2 text-xs font-semibold text-red-700 transition hover:bg-red-100"><i className="fa-solid fa-rotate-right" aria-hidden="true" />Try again</button></div>
  if (overview.isError && !overview.data) return <div className="space-y-6">{header}{loadError}</div>

  return <SimulatorContext.Provider value={sim}><div className="space-y-6">{header}
    <div className="flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900"><i className="fa-solid fa-flask mt-0.5" aria-hidden="true" /><p>Test server only. Every call here is real: it goes through SFA's integration API or its signed webhooks and creates real records in this database. The simulator is switched off in production.</p></div>
    <ConnectionBar connect={connect} connection={connection} partnerUrl={overview.data?.partner_url} />
    {/* Hosted demos use SFA's built-in receiver, whose public page shows nothing it received; staff read it here. */}
    {overview.data && !isLocalPartner(overview.data.partner_url) && <ReceivedWebhooks />}
    {loadError}

    <Tabs tabs={SYSTEMS.map((item) => ({ key: item.key, label: item.label, hint: item.hint }))} value={system.key} onChange={(key) => setUrl({ system: key, module: null })} />
    <Tabs tabs={system.modules.map((item) => ({ key: item.key, label: item.label }))} value={module.key} onChange={(key) => setUrl({ module: key })} />

    {overview.isPending ? <div className="grid gap-6 xl:grid-cols-2">{[0, 1].map((index) => <div key={index} className="h-64 animate-pulse rounded-xl bg-slate-100" />)}</div> : <>
      <section className={card} aria-labelledby="sim-exchange"><div className="flex flex-wrap items-center justify-between gap-2"><h2 id="sim-exchange" className="text-base font-semibold text-slate-900">What this exchanges with SFA</h2>{HOW_IT_WORKS[module.key] && <GuideButton open={howOpen} controls="sim-how" onClick={() => setHowOpen(!howOpen)}>How this part works</GuideButton>}</div><p className="mt-1 text-sm text-slate-600">{module.exchange}</p>{HOW_IT_WORKS[module.key] && <Guide id="sim-how" open={howOpen} steps={HOW_IT_WORKS[module.key].map((text) => [null, text])} />}</section>
      <div className="grid gap-6 xl:grid-cols-2">
        <section className={`${card} space-y-4`} aria-labelledby="sim-sends"><div className="flex flex-wrap items-start justify-between gap-3"><div><h2 id="sim-sends" className="text-base font-semibold text-slate-900"><i className="fa-solid fa-arrow-right-to-bracket mr-2 text-brand-blue" aria-hidden="true" />Sends to SFA</h2><p className="mt-1 text-xs text-slate-500">Each list offers only the records SFA accepts for that step now (its newest 50). The lists reload after every send, when you come back to this browser tab, and with Refresh lists.</p></div><Button variant="secondary" icon="fa-rotate-right" busy={overview.isFetching} onClick={reload}>Refresh lists</Button></div>
          {/* Keyed by tab too, so a form never carries its choices or last answer into another system's tab. */}
          {/* GuideKey gives each feature its "What happens?" guide (GUIDES in lib/simulator.js, by the same key). */}
          {module.sends.length ? module.sends.map((key) => { const Feature = SENDS[key]; return <GuideKey.Provider key={`${system.key}:${module.key}:${key}`} value={key}><Feature credential={system.credential} /></GuideKey.Provider> })
            : <p className="rounded-lg border border-blue-200 bg-blue-50 p-3 text-sm text-blue-900">Read only. This system's token holds only read scopes{scopes ? ` (${scopes.join(', ')})` : ''}, so it sends nothing to SFA. It may read the financial data of every storefront.</p>}
        </section>
        <section className={`${card} space-y-4`} aria-labelledby="sim-receives"><div><h2 id="sim-receives" className="text-base font-semibold text-slate-900"><i className="fa-solid fa-arrow-right-from-bracket mr-2 text-brand-blue" aria-hidden="true" />Receives from SFA</h2></div>
          {module.events?.length > 0 && <GuideKey.Provider value="receivedEvents"><ReceivedEvents events={module.events} /></GuideKey.Provider>}
          {module.reads.map((key) => { const Feature = READS[key]; return <GuideKey.Provider key={`${system.key}:${module.key}:${key}`} value={key}><Feature credential={system.credential} /></GuideKey.Provider> })}
          {module.receives && <p className="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-600">{module.receives}</p>}
        </section>
      </div>
    </>}

    <section className={`${card} space-y-4`} aria-labelledby="sim-scheduled"><div><h2 id="sim-scheduled" className="text-base font-semibold text-slate-900">Run scheduled work now</h2><p className="mt-1 text-xs text-slate-500">Runs now what SFA otherwise runs on a timer: payment expiry, auto-capture of authorized payments, SLA alerts, closing resolved tickets, subscription renewals and retries, settlement generation, late and stuck parcel flags, and webhooks that are due. A webhook still waiting out its retry delay is not sent early: start the receiver and press Reconnect for that.</p></div><FormError message={runScheduled.isError ? apiError(runScheduled.error) : ''} /><Button icon="fa-clock-rotate-left" busy={runScheduled.isPending} onClick={() => runScheduled.mutate()}>{runScheduled.isPending ? 'Running...' : 'Run scheduled work'}</Button>
      {runScheduled.isSuccess && <ScheduledResult result={runScheduled.data} />}
    </section>

    <TrafficLog />
  </div></SimulatorContext.Provider>
}

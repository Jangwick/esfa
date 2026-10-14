import { useContext, useId, useState } from 'react'
import { Link } from 'react-router-dom'
import { fieldClass } from '../../lib/admin'
import { GUIDES, GuideKey, useSimulator } from '../../lib/simulator'
import { Button, Field, FormError, JsonView } from '../admin/AdminUI'

// Building blocks for the simulator's feature forms. `call` is a useSend() state: busy, ok, status, data, message, errors.
export function Feature({ title, endpoint, description, onSubmit, children }) {
  return <form onSubmit={onSubmit} className="space-y-3 rounded-lg border border-slate-200 p-4"><FeatureHead title={title} endpoint={endpoint} description={description} />{children}</form>
}

// A feature's title, endpoint and description, with its "What happens?" guide toggled open inside the card.
export function FeatureHead({ title, endpoint, description }) {
  const guide = GUIDES[useContext(GuideKey)]
  const [open, setOpen] = useState(false)
  const id = useId()
  const { before, ...steps } = guide || {}
  return <div>
    <div className="flex flex-wrap items-center justify-between gap-2"><h3 className="text-sm font-semibold text-slate-900">{title}</h3>{guide && <GuideButton open={open} controls={id} onClick={() => setOpen(!open)}>What happens?<span className="sr-only"> with {title}</span></GuideButton>}</div>
    {endpoint && <p className="mt-0.5 break-all font-mono text-[11px] font-semibold text-brand-blue">{endpoint}</p>}
    {description && <p className="mt-1 text-xs text-slate-500">{description}</p>}
    {guide && <Guide id={id} open={open} before={before} steps={Object.entries(steps).map(([key, text]) => [STEP_TITLES[key], text])} />}
  </div>
}
const STEP_TITLES = { sends: 'What it sends.', asks: 'What it asks.', checks: 'What SFA checks.', changes: 'What changes in SFA.', answers: 'What SFA answers.', back: 'What SFA sends back.', see: 'Where to see it.' }
export function GuideButton({ open, controls, onClick, children }) {
  return <Button variant="secondary" icon="fa-circle-question" aria-expanded={open} aria-controls={controls} onClick={onClick}>{children}</Button>
}
// Numbered [title, text] steps; in a text, [words](/path) becomes a link inside SFA.
export function Guide({ id, open, before, steps }) {
  return <div id={id} hidden={!open} className="mt-3 space-y-2 rounded-lg border border-blue-200 bg-blue-50 p-3 text-sm text-blue-900">
    {before && <p><b>Before you start:</b> <Linked text={before} /></p>}
    <ol className="list-decimal space-y-1.5 pl-5">{steps.map(([title, text]) => <li key={text}>{title && <b>{title} </b>}<Linked text={text} /></li>)}</ol>
  </div>
}
const Linked = ({ text }) => text.split(/\[([^\]]+)\]\(([^)\s]+)\)/).map((part, index, parts) => index % 3 === 1 ? <Link key={index} to={parts[index + 1]} className="font-semibold underline">{part}</Link> : index % 3 === 0 ? part : null)
const fieldError = (call, name) => call?.errors?.[name]?.[0]
const ErrorText = ({ text }) => text ? <p className="mt-1 text-xs text-red-700">{text}</p> : null
export function Input({ label, call, name, help, ...props }) {
  const id = useId()
  return <Field label={label} htmlFor={id} help={help}><input id={id} className={fieldClass} aria-invalid={Boolean(fieldError(call, name))} {...props} /><ErrorText text={fieldError(call, name)} /></Field>
}
export function TextArea({ label, call, name, help, ...props }) {
  const id = useId()
  return <Field label={label} htmlFor={id} help={help}><textarea id={id} rows={3} className={fieldClass} aria-invalid={Boolean(fieldError(call, name))} {...props} /><ErrorText text={fieldError(call, name)} /></Field>
}
// options: [[value, text], ...]; `placeholder` adds an empty first option.
export function Select({ label, call, name, help, options, placeholder, ...props }) {
  const id = useId()
  return <Field label={label} htmlFor={id} help={help}><select id={id} className={fieldClass} aria-invalid={Boolean(fieldError(call, name))} {...props}>{placeholder !== undefined && <option value="">{placeholder}</option>}{options.map(([value, text]) => <option key={value} value={value}>{text}</option>)}</select><ErrorText text={fieldError(call, name)} /></Field>
}
export function Send({ call, icon = 'fa-paper-plane', children, disabled }) {
  return <div className="flex justify-end"><Button type="submit" icon={icon} busy={call.busy} disabled={disabled}>{call.busy ? 'Sending...' : children}</Button></div>
}
// The answer to the last call: the error, or what SFA returned with the raw JSON one click away.
export function Outcome({ call, children }) {
  if (call.message) return <div className="space-y-2"><FormError message={call.message} />{call.status === 401 && <Reconnect call={call} />}</div>
  if (!call.ok) return null
  return <div role="status" className="space-y-2 rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900"><p className="flex items-center gap-2 text-xs font-semibold text-emerald-700"><i className="fa-solid fa-circle-check" aria-hidden="true" />SFA answered HTTP {call.status}</p>{children}<Raw label="Response JSON" value={call.data} /></div>
}
// A refused credential is fixed by reconnecting; the button sits beside the error instead of at the top of the page.
function Reconnect({ call }) {
  const { reconnect, connectedAt } = useSimulator()
  if (connectedAt > call.at) return <p role="status" className="text-xs text-slate-600">Fresh credentials are loaded. Send again.</p>
  return <Button variant="secondary" icon="fa-plug" busy={reconnect.isPending} onClick={() => reconnect.mutate()}>{reconnect.isPending ? 'Connecting...' : 'Reconnect'}</Button>
}
export function Raw({ label, value }) {
  return <details className="text-xs"><summary className="cursor-pointer font-semibold text-slate-600 hover:text-slate-900">{label}</summary><div className="mt-2"><JsonView value={value} /></div></details>
}
export function Empty({ children }) { return <p className="rounded-lg border border-dashed border-slate-300 p-4 text-center text-sm text-slate-500">{children}</p> }
export function Rows({ rows }) {
  return <dl className="grid grid-cols-1 gap-x-4 gap-y-1 text-sm sm:grid-cols-2">{rows.filter(([, value]) => value !== undefined).map(([term, value]) => <div key={term} className="flex justify-between gap-3 sm:block"><dt className="text-xs text-slate-500">{term}</dt><dd className="break-words font-semibold text-slate-900">{value ?? '—'}</dd></div>)}</dl>
}

import { useState } from 'react'
import { fieldClass } from '../../lib/admin'
import { Button, Field } from './AdminUI'

// Shows a one-time secret (e.g. a courier webhook signing secret) with a copy button.
export function SecretReveal({ value, label, onClose }) {
  const [copyState, setCopyState] = useState('')
  async function copy() { try { await navigator.clipboard.writeText(value); setCopyState('Copied to clipboard.') } catch { setCopyState('Select and copy the value below.') } }
  return <div className="space-y-5"><div className="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800"><i className="fa-solid fa-key mt-0.5" aria-hidden="true" /><p>Copy and save this {label.toLowerCase()} now. It is displayed once and cannot be retrieved after this dialog closes.</p></div><Field label={label} htmlFor="one-time-secret"><textarea id="one-time-secret" readOnly rows={4} className={`${fieldClass} break-all font-mono`} value={value} onFocus={(event) => event.target.select()} /></Field>{copyState && <p className="text-xs text-slate-500" role="status" aria-live="polite">{copyState}</p>}<div className="flex flex-col gap-3 sm:flex-row sm:justify-end"><Button variant="secondary" icon="fa-copy" onClick={copy}>Copy {label.toLowerCase()}</Button><Button icon="fa-check" onClick={onClose}>I have saved it</Button></div></div>
}

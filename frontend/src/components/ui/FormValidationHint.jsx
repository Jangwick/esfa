import { useEffect, useState } from 'react'

// Every form relies on native `required` checks. On their own, browsers show one short-lived bubble next to the first
// missing field (often scrolled out of view in a long modal) and nothing at all when that field is hidden, so a blocked
// Save looks like a dead button. Mounted once for the whole app: when a submit is blocked it lists every missing field of
// that form until they are filled, marks the form so CSS outlines them (index.css), and each name jumps to its field.
const nameOf = (field) => (field.labels?.[0]?.textContent || field.getAttribute('aria-label') || field.placeholder || field.name || 'A required field').trim()
const missingFields = (form) => [...form.elements].filter((field) => field.willValidate && !field.validity.valid)

export default function FormValidationHint() {
  const [blocked, setBlocked] = useState(null)

  useEffect(() => {
    let collecting = null
    // `invalid` does not bubble, but a capturing listener on document still sees it. One submit fires it once per
    // missing field, so the form is read once after the current batch.
    const onInvalid = (event) => {
      const form = event.target.form
      if (!form || collecting === form) return
      collecting = form
      setTimeout(() => {
        collecting = null
        form.setAttribute('data-attempted', '')
        const fields = missingFields(form)
        setBlocked(fields.length ? { form, fields } : null)
      })
    }
    const onEdit = (event) => {
      const form = event.target.form
      if (!form?.hasAttribute('data-attempted')) return
      setBlocked((current) => {
        if (current?.form !== form) return current
        const fields = missingFields(form)
        return fields.length ? { form, fields } : null
      })
    }
    // `submit` only fires once the form is valid. Forget the blocked attempt, or fields the form clears after saving
    // (status select, reply box) stay outlined in red.
    const onSubmit = (event) => { event.target.removeAttribute('data-attempted'); setBlocked((current) => (current?.form === event.target ? null : current)) }
    document.addEventListener('invalid', onInvalid, true)
    document.addEventListener('input', onEdit, true)
    document.addEventListener('change', onEdit, true)
    document.addEventListener('submit', onSubmit, true)
    return () => {
      document.removeEventListener('submit', onSubmit, true)
      document.removeEventListener('invalid', onInvalid, true)
      document.removeEventListener('input', onEdit, true)
      document.removeEventListener('change', onEdit, true)
    }
  }, [])

  // The form can disappear (modal closed, page changed) without another event; drop the message with it.
  useEffect(() => {
    if (!blocked) return
    const timer = setInterval(() => { if (!blocked.form.isConnected) setBlocked(null) }, 500)
    return () => clearInterval(timer)
  }, [blocked])

  if (!blocked) return null
  const { fields } = blocked
  const hidden = fields.some((field) => !field.getClientRects().length)
  const show = (field) => { field.scrollIntoView({ block: 'center', behavior: 'smooth' }); field.focus({ preventScroll: true }) }
  // At the top: a modal's Save/Cancel row sits at the bottom of the screen, and this must never cover it.
  return <div role="alert" className="fixed inset-x-4 top-4 z-[60] mx-auto max-h-[40vh] max-w-lg overflow-y-auto rounded-xl border border-red-200 bg-white p-4 shadow-lg">
    <div className="flex items-start gap-3"><i className="fa-solid fa-circle-exclamation mt-0.5 text-red-600" aria-hidden="true" />
      <div className="min-w-0 flex-1"><p className="text-sm font-semibold text-slate-900">Not saved yet. {fields.every((field) => field.validity.valueMissing) ? 'Fill in' : 'Fix'} {fields.length === 1 ? 'this field' : `these ${fields.length} fields`} first:</p>
        {/* A filled field can still be blocked (a date past its max, a code with a wrong character); say why. */}
        <ul className="mt-2 flex flex-wrap gap-2">{fields.map((field, index) => <li key={index}><button type="button" onClick={() => show(field)} className="rounded-full bg-red-50 px-2.5 py-1 text-left text-xs font-semibold text-red-700 transition hover:bg-red-100 focus:outline-none focus:ring-2 focus:ring-red-200">{nameOf(field)}{!field.validity.valueMissing && field.validationMessage && `: ${field.validationMessage}`}</button></li>)}</ul>
        {hidden && <p className="mt-2 text-xs text-slate-500">Some of these are in a hidden part of the form. Open that section to fill them in.</p>}
      </div>
      <button type="button" onClick={() => setBlocked(null)} className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-slate-500 transition hover:bg-slate-100" aria-label="Dismiss"><i className="fa-solid fa-xmark" aria-hidden="true" /></button>
    </div>
  </div>
}

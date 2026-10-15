import { useEffect, useId, useRef } from 'react'
import { createPortal } from 'react-dom'

export default function Modal({ isOpen, onClose, title, children }) {
  const closeRef = useRef(null)
  const dialogRef = useRef(null)
  // The backdrop closes the dialog only when the press also started on it: selecting text in a field and releasing
  // outside the dialog fires a click on the backdrop too, which closed the form and threw away what was typed.
  const pressedBackdrop = useRef(false)
  const titleId = useId()
  // Keep the latest onClose in a ref so the effect below only re-runs when
  // isOpen changes — parents pass inline arrows, and re-running the effect
  // on every render steals focus (closeRef.focus()) from whatever the user
  // is typing in.
  const onCloseRef = useRef(onClose)
  onCloseRef.current = onClose

  useEffect(() => {
    if (!isOpen) return

    const previousFocus = document.activeElement
    const previousOverflow = document.body.style.overflow
    const handleKey = (e) => {
      if (e.key === 'Escape') onCloseRef.current()
      if (e.key === 'Tab') {
        const focusable = [...dialogRef.current.querySelectorAll('button:not(:disabled), a[href], input:not(:disabled), textarea:not(:disabled), select:not(:disabled), [tabindex="0"]')]
          .filter((element) => element.getClientRects().length)
        const first = focusable[0]
        const last = focusable.at(-1)
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last?.focus() }
        if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first?.focus() }
      }
    }

    // A save error renders at the top of the form, often scrolled out of view in a long dialog, so a failed Save looked
    // like a dead button. Bring any alert that appears (or changes its message) inside the dialog into view, but only
    // after a button press or submit with no typing since: live checks (shipping quotes) must not pull the field being
    // typed in out of view.
    const dialog = dialogRef.current
    let acted = false
    const onAct = (event) => { if (event.type === 'submit' || event.target.closest?.('button')) acted = true }
    const onType = () => { acted = false }
    const alertIn = ({ target, addedNodes }) => (target.nodeType === 1 ? target : target.parentElement)?.closest('[role="alert"]')
      || [...addedNodes].map((node) => node.nodeType === 1 && (node.matches('[role="alert"]') ? node : node.querySelector('[role="alert"]'))).find(Boolean)
    const observer = new MutationObserver((mutations) => { if (acted) mutations.map(alertIn).find(Boolean)?.scrollIntoView({ block: 'nearest', behavior: 'smooth' }) })
    observer.observe(dialog, { childList: true, subtree: true, characterData: true })
    dialog.addEventListener('click', onAct, true)
    dialog.addEventListener('submit', onAct, true)
    dialog.addEventListener('input', onType, true)

    document.addEventListener('keydown', handleKey)
    closeRef.current?.focus()
    document.body.style.overflow = 'hidden'

    return () => {
      observer.disconnect()
      dialog.removeEventListener('click', onAct, true)
      dialog.removeEventListener('submit', onAct, true)
      dialog.removeEventListener('input', onType, true)
      document.removeEventListener('keydown', handleKey)
      document.body.style.overflow = previousOverflow
      previousFocus?.focus()
    }
  }, [isOpen])

  if (!isOpen) return null

  // Rendered at <body> level so the fixed backdrop always covers the viewport: inside the page tree a transformed or
  // filtered ancestor would shrink it to that ancestor's box instead.
  return createPortal(
    <div
      className="fixed inset-0 z-50 flex items-start sm:items-center justify-center p-4 bg-slate-900/50 backdrop-blur-sm"
      onMouseDown={(e) => { pressedBackdrop.current = e.target === e.currentTarget }}
      onClick={(e) => { if (pressedBackdrop.current && e.target === e.currentTarget) onClose() }}
      role="dialog"
      aria-modal="true"
      aria-labelledby={titleId}
    >
      <div
        ref={dialogRef}
        className="bg-white rounded-xl border border-slate-200 shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-y-auto"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-center justify-between p-5 border-b border-slate-200">
          <h2 id={titleId} className="text-lg font-semibold text-slate-800">
            {title}
          </h2>
          <button
            ref={closeRef}
            type="button"
            onClick={onClose}
            className="w-8 h-8 rounded-full hover:bg-slate-100 flex items-center justify-center text-slate-500 transition-colors"
            aria-label="Close modal"
          >
            <i className="fa-solid fa-xmark" aria-hidden="true"></i>
          </button>
        </div>
        <div className="p-5">{children}</div>
      </div>
    </div>,
    document.body,
  )
}

import Modal from './Modal'
import { apiError } from '../../lib/admin'
import { useEffect, useState } from 'react'

export default function ConfirmDialog({
  isOpen,
  onClose,
  onConfirm,
  title = 'Confirm Action',
  message,
  confirmLabel = 'Confirm',
  cancelLabel = 'Cancel',
  variant = 'danger',
  isPending = false,
}) {
  const [confirming, setConfirming] = useState(false)
  const [error, setError] = useState('')
  useEffect(() => { if (isOpen) setError('') }, [isOpen])
  const busy = isPending || confirming
  const close = () => { if (!busy) { setError(''); onClose() } }
  const confirmBtn = {
    danger: 'bg-red-600 hover:bg-red-700 text-white',
    primary: 'bg-brand-blue hover:bg-blue-700 text-white',
    warning: 'bg-amber-500 hover:bg-amber-600 text-white',
  }[variant] || 'bg-brand-blue hover:bg-blue-700 text-white'

  const icon = {
    danger: 'bi-exclamation-triangle-fill text-red-500',
    primary: 'bi-info-circle-fill text-brand-blue',
    warning: 'bi-exclamation-triangle-fill text-amber-500',
  }[variant] || 'bi-info-circle-fill text-brand-blue'

  return (
    <Modal isOpen={isOpen} onClose={close} title={title}>
      <div className="flex flex-col gap-4">
        <div className="flex items-start gap-3">
          <div className="w-10 h-10 rounded-full bg-slate-100 flex items-center justify-center flex-shrink-0">
            <i className={`bi ${icon} text-lg`} aria-hidden="true"></i>
          </div>
          <p className="text-sm text-slate-600 pt-2">{message}</p>
        </div>
        {error && <p role="alert" className="text-sm text-red-700">{error}</p>}
        <div className="flex justify-end gap-3 pt-2">
          <button
            type="button"
            onClick={close}
            disabled={busy}
            className="px-4 py-2 rounded-lg text-sm font-medium text-slate-600 bg-slate-100 hover:bg-slate-200 transition-colors disabled:cursor-not-allowed disabled:opacity-60"
          >
            {cancelLabel}
          </button>
          <button
            type="button"
            disabled={busy}
            onClick={async () => {
              setConfirming(true)
              setError('')
              try { await onConfirm(); onClose() }
              catch (failure) { setError(apiError(failure, 'This action could not be completed. Please try again.')) }
              finally { setConfirming(false) }
            }}
            className={`px-4 py-2 rounded-lg text-sm font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-60 ${confirmBtn}`}
          >
            {busy ? 'Working...' : confirmLabel}
          </button>
        </div>
      </div>
    </Modal>
  )
}

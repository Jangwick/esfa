import { useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useNotifications } from '../../hooks/useNotifications'

const typeIcons = {
  ticket_assigned: 'fa-headset',
  ticket_sla_at_risk: 'fa-stopwatch',
  ticket_sla_breached: 'fa-stopwatch',
  ticket_customer_replied: 'fa-reply',
  ticket_low_csat: 'fa-star-half-stroke',
  refund_awaiting_approval: 'fa-credit-card',
  refund_completed: 'fa-arrow-rotate-left',
  refund_failed: 'fa-arrow-rotate-left',
  refund_rejected: 'fa-arrow-rotate-left',
  refund_approval_overdue: 'fa-hourglass-half',
  ticket_refund_outcome: 'fa-arrow-rotate-left',
  risk_review_overdue: 'fa-shield-halved',
  payment_disputed: 'fa-gavel',
  subscription_payment_after_cancel: 'fa-file-invoice-dollar',
  risk_review_needed: 'fa-shield-halved',
  cod_risk_hold: 'fa-shield-halved',
  cod_amount_mismatch: 'fa-scale-unbalanced',
  shipment_failed_attempt: 'fa-truck-fast',
  shipment_returned_to_sender: 'fa-truck-fast',
  shipments_attention: 'fa-hourglass-end',
  parcel_returned: 'fa-box-open',
  courier_event_rejected: 'fa-ban',
  subscription_past_due: 'fa-file-invoice-dollar',
  subscription_cancelled_unpaid: 'fa-file-invoice-dollar',
  settlement_ready: 'fa-file-invoice-dollar',
  webhook_endpoint_disabled: 'fa-plug',
  webhook_endpoint_recovered: 'fa-plug-circle-check',
  scheduler_job_failed: 'fa-clock-rotate-left',
  integration_token_expiring: 'fa-key',
  sign_in_locked: 'fa-lock',
  system: 'fa-gear',
}

const typeColors = {
  ticket_assigned: 'text-blue-600 bg-blue-50',
  ticket_sla_at_risk: 'text-amber-600 bg-amber-50',
  ticket_sla_breached: 'text-red-600 bg-red-50',
  ticket_customer_replied: 'text-blue-600 bg-blue-50',
  ticket_low_csat: 'text-amber-600 bg-amber-50',
  refund_awaiting_approval: 'text-amber-600 bg-amber-50',
  refund_completed: 'text-emerald-600 bg-emerald-50',
  refund_failed: 'text-red-600 bg-red-50',
  refund_rejected: 'text-red-600 bg-red-50',
  refund_approval_overdue: 'text-amber-600 bg-amber-50',
  ticket_refund_outcome: 'text-blue-600 bg-blue-50',
  risk_review_overdue: 'text-amber-600 bg-amber-50',
  payment_disputed: 'text-red-600 bg-red-50',
  subscription_payment_after_cancel: 'text-amber-600 bg-amber-50',
  risk_review_needed: 'text-amber-600 bg-amber-50',
  cod_risk_hold: 'text-amber-600 bg-amber-50',
  cod_amount_mismatch: 'text-red-600 bg-red-50',
  shipment_failed_attempt: 'text-red-600 bg-red-50',
  shipment_returned_to_sender: 'text-amber-600 bg-amber-50',
  shipments_attention: 'text-amber-600 bg-amber-50',
  parcel_returned: 'text-amber-600 bg-amber-50',
  courier_event_rejected: 'text-red-600 bg-red-50',
  subscription_past_due: 'text-amber-600 bg-amber-50',
  subscription_cancelled_unpaid: 'text-red-600 bg-red-50',
  settlement_ready: 'text-blue-600 bg-blue-50',
  webhook_endpoint_disabled: 'text-red-600 bg-red-50',
  webhook_endpoint_recovered: 'text-emerald-600 bg-emerald-50',
  scheduler_job_failed: 'text-red-600 bg-red-50',
  integration_token_expiring: 'text-amber-600 bg-amber-50',
  sign_in_locked: 'text-red-600 bg-red-50',
  system: 'text-slate-600 bg-slate-100',
}

// The page and record a notification is about, built from the ids the backend stores in `data`. Null when it has
// none (welcome notes, webhook endpoint and token notices, older notifications): clicking those only marks them read.
function notificationLink({ type, data }) {
  const ids = data || {}
  if (ids.ticket_id) return `/support?ticket=${ids.ticket_id}`
  if (ids.refund_id) return `/payments?tab=refunds&refund=${ids.refund_id}`
  if (ids.payment_id) return `/payments?tab=${['risk_review_needed', 'cod_risk_hold', 'risk_review_overdue'].includes(type) ? 'risk-reviews' : 'payments'}&payment=${ids.payment_id}`
  if (ids.shipment_id) return `/logistics?shipment=${ids.shipment_id}`
  if (ids.settlement_id) return `/subscriptions?tab=settlements&settlement=${ids.settlement_id}`
  if (type === 'settlement_ready') return '/subscriptions?tab=settlements&status=draft'
  if (ids.subscription_id) return `/subscriptions?subscription=${ids.subscription_id}`
  if (type === 'scheduler_job_failed') return '/settings?tab=automation'
  if (type === 'shipments_attention') return ids.overdue ? '/logistics?overdue=1' : '/logistics'
  if (type === 'sign_in_locked') return '/users?status=locked'
  return null
}

function formatTimeAgo(dateString) {
  const date = new Date(dateString)
  const now = new Date()
  const seconds = Math.floor((now - date) / 1000)

  if (seconds < 60) return 'just now'
  const minutes = Math.floor(seconds / 60)
  if (minutes < 60) return `${minutes}m ago`
  const hours = Math.floor(minutes / 60)
  if (hours < 24) return `${hours}h ago`
  const days = Math.floor(hours / 24)
  if (days < 7) return `${days}d ago`
  return date.toLocaleDateString()
}

export default function NotificationBell() {
  const [open, setOpen] = useState(false)
  // Only the newest 10 load at first; without "Show older" the rest of a large unread count could never be reached.
  const [perPage, setPerPage] = useState(10)
  const dropdownRef = useRef(null)
  const navigate = useNavigate()
  const {
    unreadCount,
    notifications,
    total,
    isListLoading,
    isListFetching,
    error,
    actionError,
    markAsRead,
    markAllAsRead,
    remove,
    refetch,
  } = useNotifications(open, perPage)

  useEffect(() => {
    const handleClickOutside = (event) => {
      if (dropdownRef.current && !dropdownRef.current.contains(event.target)) {
        setOpen(false)
      }
    }

    const handleEscape = (event) => {
      if (event.key === 'Escape') setOpen(false)
    }

    if (open) {
      document.addEventListener('mousedown', handleClickOutside)
      document.addEventListener('keydown', handleEscape)
    }

    return () => {
      document.removeEventListener('mousedown', handleClickOutside)
      document.removeEventListener('keydown', handleEscape)
    }
  }, [open])

  const handleToggle = () => setOpen((prev) => !prev)

  const handleMarkAsRead = (event, id) => {
    event.stopPropagation()
    markAsRead.mutate(id)
  }

  const handleMarkAllAsRead = () => {
    markAllAsRead.mutate()
  }

  const handleOpen = (notification) => {
    if (!notification.is_read) markAsRead.mutate(notification.id)
    const link = notificationLink(notification)
    if (link) { setOpen(false); navigate(link) }
  }

  const handleDelete = (event, id) => {
    event.stopPropagation()
    remove.mutate(id)
  }

  return (
    <div ref={dropdownRef} className="relative">
      <button
        type="button"
        onClick={handleToggle}
        className="relative text-slate-400 transition-colors hover:text-slate-600 focus:outline-none focus:ring-2 focus:ring-brand-blue/20 rounded-lg p-1"
        aria-label="Notifications"
        aria-haspopup="true"
        aria-expanded={open}
      >
        <i className="fa-regular fa-bell text-lg" aria-hidden="true" />
        {unreadCount > 0 && (
          <span className="absolute -right-1 -top-1 flex h-4 min-w-4 items-center justify-center rounded-full border-2 border-white bg-red-500 px-1 text-[10px] font-bold text-white">
            {unreadCount > 99 ? '99+' : unreadCount}
          </span>
        )}
      </button>

      {open && (
        <div className="fixed left-4 right-4 z-50 mt-2 rounded-xl border border-slate-200 bg-white py-2 shadow-lg sm:absolute sm:left-auto sm:right-0 sm:w-96">
          <div className="flex items-center justify-between border-b border-slate-100 px-4 py-2.5">
            <h3 className="text-sm font-semibold text-slate-900">Notifications</h3>
            <div className="flex items-center gap-2">
              {unreadCount > 0 && (
                <button
                  type="button"
                  onClick={handleMarkAllAsRead}
                  disabled={markAllAsRead.isPending}
                  className="text-xs font-medium text-brand-blue hover:text-blue-900 disabled:opacity-50"
                >
                  Mark all as read
                </button>
              )}
              <button
                type="button"
                onClick={() => refetch()}
                className="text-slate-400 transition-colors hover:text-slate-600"
                aria-label="Refresh notifications"
                title="Refresh"
              >
                <i className={`fa-solid fa-rotate ${isListFetching ? 'animate-spin' : ''}`} aria-hidden="true" />
              </button>
            </div>
          </div>

          {actionError && <p role="alert" className="px-4 pt-2 text-xs text-red-700">That change could not be saved. Refresh and try again.</p>}
          <div className="max-h-96 overflow-y-auto">
            {error ? (
              <div role="alert" className="px-4 py-6 text-sm text-red-700">Notifications could not load.<button type="button" onClick={refetch} className="ml-2 font-medium text-brand-blue">Try again</button></div>
            ) : isListLoading ? (
              <p className="py-8 text-center text-sm text-slate-500">Loading notifications…</p>
            ) : notifications.length === 0 ? (
              <div className="flex flex-col items-center justify-center py-8 text-slate-500">
                <i className="fa-regular fa-bell-slash text-2xl text-slate-300" aria-hidden="true" />
                <p className="mt-2 text-sm">No notifications yet.</p>
              </div>
            ) : (
              <ul className="divide-y divide-slate-100" role="list">
                {notifications.map((notification) => {
                  const icon = typeIcons[notification.type] || 'fa-circle-info'
                  const colorClass = typeColors[notification.type] || 'text-slate-600 bg-slate-100'
                  const isUnread = !notification.is_read
                  const link = notificationLink(notification)

                  return (
                    <li
                      key={notification.id}
                      className={`relative px-4 py-3 transition-colors ${isUnread ? 'bg-blue-50/40' : 'bg-white'} hover:bg-slate-50`}
                    >
                      <div className="flex items-start gap-3">
                        {/* A read notification without a record has nothing to do on click, so it is not offered as a button. */}
                        <button type="button" onClick={() => handleOpen(notification)} disabled={!link && !isUnread} title={link ? 'Open' : isUnread ? 'Mark as read' : undefined} className="flex min-w-0 flex-1 items-start gap-3 rounded-lg text-left focus:outline-none focus:ring-2 focus:ring-blue-100">
                          <span className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-full ${colorClass}`}>
                            <i className={`fa-solid ${icon}`} aria-hidden="true" />
                          </span>
                          <span className="min-w-0 flex-1">
                            <span className={`block text-sm font-medium ${isUnread ? 'text-slate-900' : 'text-slate-600'}`}>
                              {notification.title}
                            </span>
                            <span className="block text-xs text-slate-500 line-clamp-2">{notification.message}</span>
                            <span className="mt-1 flex items-center gap-2 text-[10px] text-slate-400">{formatTimeAgo(notification.created_at)}{link && <span className="font-semibold text-brand-blue">Open <i className="fa-solid fa-arrow-right" aria-hidden="true" /></span>}</span>
                          </span>
                        </button>
                        <div className="flex shrink-0 flex-col items-end gap-1">
                          {isUnread && (
                            <button
                              type="button"
                              onClick={(e) => handleMarkAsRead(e, notification.id)}
                              disabled={markAsRead.isPending && markAsRead.variables === notification.id}
                              className="text-xs font-medium text-brand-blue hover:text-blue-900 disabled:opacity-50"
                              title="Mark as read"
                            >
                              Read
                            </button>
                          )}
                          <button
                            type="button"
                            onClick={(e) => handleDelete(e, notification.id)}
                            disabled={remove.isPending && remove.variables === notification.id}
                            className="text-xs text-slate-400 hover:text-red-600 disabled:opacity-50"
                            title="Delete"
                            aria-label={`Delete notification: ${notification.title}`}
                          >
                            <i className="fa-regular fa-trash-can" aria-hidden="true" />
                          </button>
                        </div>
                      </div>
                    </li>
                  )
                })}
              </ul>
            )}
            {/* ponytail: the API caps per_page at 100; past the newest 100, older ones show only as newer ones are deleted. */}
            {!error && notifications.length < total && perPage >= 100 && <p className="border-t border-slate-100 px-4 py-2.5 text-center text-xs text-slate-500">Showing the newest 100 of {total}. Delete ones you no longer need to reach older ones.</p>}
            {!error && notifications.length < total && perPage < 100 && (
              <button
                type="button"
                onClick={() => setPerPage((current) => Math.min(current + 20, 100))}
                disabled={isListFetching}
                className="w-full border-t border-slate-100 px-4 py-2.5 text-center text-xs font-semibold text-brand-blue hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60"
              >
                {isListFetching ? 'Loading…' : `Show older notifications (${total - notifications.length} more)`}
              </button>
            )}
          </div>
        </div>
      )}
    </div>
  )
}

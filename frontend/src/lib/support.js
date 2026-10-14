export const ticketStatuses = ['open', 'in_progress', 'waiting_on_customer', 'resolved', 'closed', 'reopened']
export const ticketPriorities = ['low', 'normal', 'high', 'urgent']
export const ticketCategories = ['order', 'payment', 'delivery', 'refund_return', 'account', 'product_inquiry', 'seller_concern', 'other']
export const ticketChannels = ['email', 'chat', 'phone', 'web_form', 'marketplace_app']
export const transitions = { open: ['in_progress'], in_progress: ['waiting_on_customer', 'resolved'], waiting_on_customer: ['in_progress'], resolved: ['closed', 'reopened'], closed: ['reopened'], reopened: ['in_progress'] }
export const label = (value) => String(value || '').replaceAll('_', ' ').replace(/^./, (character) => character.toUpperCase())
export const duration = (minutes) => { const total = Math.abs(minutes), days = Math.floor(total / 1440), hours = Math.floor((total % 1440) / 60), rest = total % 60; return days ? `${days}d ${hours}h` : hours ? `${hours}h ${rest}m` : `${rest} min` }

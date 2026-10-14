import { useEffect } from 'react'
import { Link, useLocation } from 'react-router-dom'
import { useAuthStore } from '../../store/authStore'
import { visibleNavigation } from '../../lib/navigation'

export default function Sidebar({ isOpen, onClose }) {
  const location = useLocation()
  const auth = useAuthStore()

  useEffect(() => {
    if (!isOpen) return
    const handleKey = (event) => { if (event.key === 'Escape') onClose() }
    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    document.addEventListener('keydown', handleKey)
    return () => {
      document.body.style.overflow = previousOverflow
      document.removeEventListener('keydown', handleKey)
    }
  }, [isOpen, onClose])

  return <>
    <aside id="app-sidebar" aria-label="Primary navigation" className={`fixed inset-y-0 left-0 z-40 flex h-full w-72 max-w-[85vw] flex-col bg-sidebar-bg text-white shadow-2xl transform transition-transform duration-200 ease-in-out md:static md:z-auto md:w-64 md:max-w-none md:translate-x-0 md:shadow-none ${isOpen ? 'translate-x-0' : '-translate-x-full'}`}>
      <div className="flex h-16 shrink-0 items-center justify-between gap-3 border-b border-slate-700 px-4 md:px-6">
        <img src="/logo-dark.png" alt="Archon Nell Incorporated" className="h-14 w-auto shrink-0" />
        <button type="button" onClick={onClose} aria-label="Close navigation menu" className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-slate-300 hover:bg-sidebar-hover hover:text-white focus:outline-none focus:ring-2 focus:ring-blue-400 md:hidden">
          <i className="fa-solid fa-xmark text-lg" aria-hidden="true" />
        </button>
      </div>
      <div className="flex-1 overflow-y-auto custom-scrollbar py-4 px-3">
        <ul className="space-y-1 text-sm font-medium">
          {visibleNavigation(auth).map((item) => {
            const active = location.pathname === item.path || location.pathname.startsWith(`${item.path}/`)
            return <li key={item.path}><Link to={item.path} onClick={onClose} aria-current={active ? 'page' : undefined} className={`flex items-center gap-3 px-3 py-2 rounded-lg transition-colors ${active ? 'bg-blue-900/50 text-blue-400' : 'text-slate-300 hover:bg-sidebar-hover hover:text-white'}`}>
              <i className={`fa-solid ${item.icon} w-5 shrink-0 text-center`} aria-hidden="true" /><span>{item.name}</span>
            </Link></li>
          })}
        </ul>
      </div>
      <div className="p-4 border-t border-slate-700" />
    </aside>
    {isOpen && <button type="button" onClick={onClose} className="fixed inset-0 z-30 bg-slate-950/55 backdrop-blur-[1px] md:hidden" aria-label="Close navigation menu" tabIndex={-1} />}
  </>
}

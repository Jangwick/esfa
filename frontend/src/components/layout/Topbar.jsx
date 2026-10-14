import { useEffect, useRef, useState } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import NotificationBell from '../notifications/NotificationBell'
import { useAuthStore } from '../../store/authStore'
import { signOut } from '../../lib/api'
import { moduleTabs, navigation, visibleNavigation } from '../../lib/navigation'

const routeTitles = [...navigation.map((item) => [item.path, item.name]), ['/settings', 'Settings'], ['/help', 'Help Center']]

export default function Topbar({ sidebarOpen, onMenuClick }) {
  const [search, setSearch] = useState('')
  const [isOpen, setIsOpen] = useState(false)
  const dropdownRef = useRef(null)
  const searchRef = useRef(null)
  const { user, roles, hasPermission, hasRole } = useAuthStore()
  const location = useLocation()
  const navigate = useNavigate()
  const pageTitle = routeTitles.find(([path]) => location.pathname.startsWith(path))?.[1] || 'Dashboard'
  // "Find a page" also finds the sections inside each module, e.g. "refund" -> Payments & Transactions › Refunds.
  const term = search.trim().toLowerCase()
  const pages = visibleNavigation({ user, hasPermission, hasRole }).flatMap((item) => [{ key: item.path, name: item.name, to: item.path, icon: item.icon }, ...moduleTabs(item.path, { hasPermission, roles }).map((section, index) => ({ key: `${item.path}-${section.key}`, name: `${item.name} › ${section.label}`, to: index === 0 ? item.path : `${item.path}?tab=${section.key}`, icon: item.icon }))])
  const matches = term ? pages.filter((page) => page.name.toLowerCase().includes(term)).slice(0, 8) : []

  const primaryRole = Array.isArray(roles) && roles.length > 0
    ? (typeof roles[0] === 'string' ? roles[0] : roles[0]?.name)
    : (user?.role || 'Staff')

  useEffect(() => {
    const handleClickOutside = (event) => {
      if (dropdownRef.current && !dropdownRef.current.contains(event.target)) {
        setIsOpen(false)
      }
      // The page-search results otherwise stay open over the page's tabs and buttons after you click elsewhere.
      if (searchRef.current && !searchRef.current.contains(event.target)) setSearch('')
    }
    document.addEventListener('mousedown', handleClickOutside)
    return () => document.removeEventListener('mousedown', handleClickOutside)
  }, [])

  const handleLogout = () => {
    setIsOpen(false)
    signOut()
    navigate('/login', { replace: true })
  }

  const handleProfile = () => {
    setIsOpen(false)
    navigate('/settings')
  }

  return (
    <header className="relative z-20 flex h-16 shrink-0 items-center justify-between gap-2 border-b border-slate-200 bg-white px-3 sm:px-6">
      <div className="flex min-w-0 flex-1 items-center gap-2 sm:gap-4">
        <button
          type="button"
          onClick={onMenuClick}
          className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-500 shadow-sm hover:bg-slate-50 hover:text-slate-700 focus:outline-none focus:ring-2 focus:ring-brand-blue/20 md:hidden"
          aria-label="Open navigation menu"
          aria-controls="app-sidebar"
          aria-expanded={sidebarOpen}
        >
          <i className="fa-solid fa-bars text-lg" aria-hidden="true" />
        </button>
        <p className="min-w-0 flex-1 truncate text-sm font-semibold text-slate-700 sm:hidden" title={pageTitle}>
          {pageTitle}
        </p>
        <div ref={searchRef} className="relative hidden w-full max-w-md sm:block">
          <i className="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-sm text-slate-400" aria-hidden="true" />
          <input
            type="text"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Find a page..."
            aria-label="Find a page"
            onKeyDown={(event) => {
              if (event.key === 'Escape') setSearch('')
              if (event.key === 'Enter') {
                if (matches[0]) { navigate(matches[0].to); setSearch('') }
              }
            }}
            className="w-full rounded-lg border border-slate-200 bg-slate-50 py-2 pl-9 pr-4 text-sm transition-all focus:border-brand-blue/50 focus:outline-none focus:ring-2 focus:ring-brand-blue/20"
          />
          {search.trim() && <div className="absolute left-0 right-0 z-50 mt-2 rounded-xl border border-slate-200 bg-white py-1 shadow-lg">
            {matches.length === 0 && <p className="px-4 py-3 text-sm text-slate-500">No pages match your search.</p>}
            {matches.map((page) => <button type="button" key={page.key} onClick={() => { navigate(page.to); setSearch('') }} className="flex w-full items-center gap-2 px-4 py-2 text-left text-sm text-slate-700 hover:bg-slate-50"><i className={`fa-solid ${page.icon} w-4`} aria-hidden="true" />{page.name}</button>)}
          </div>}
        </div>
      </div>

      <div className="flex shrink-0 items-center gap-3 sm:gap-5">
        <NotificationBell />

        <div ref={dropdownRef} className="relative">
          <button
            type="button"
            onClick={() => setIsOpen((prev) => !prev)}
            className="flex items-center gap-2 rounded-full border border-slate-200 bg-white py-1.5 pl-1.5 pr-3 text-left shadow-sm transition-all hover:border-slate-300 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-brand-blue/20"
            aria-haspopup="true"
            aria-expanded={isOpen}
            aria-label="Open profile menu"
          >
            <img
              alt={user?.name || 'User'}
              className="h-8 w-8 rounded-full"
              src={
                user?.avatar ||
                'https://ui-avatars.com/api/?name=' +
                  encodeURIComponent(user?.name || 'User') +
                  '&background=1e3a8a&color=fff'
              }
            />
            <div className="hidden md:block">
              <p className="text-sm font-semibold leading-tight text-slate-700">
                {user?.name || 'User'}
              </p>
              {primaryRole && primaryRole !== (user?.name || '') && (
                <p className="text-xs leading-tight text-slate-500">{primaryRole}</p>
              )}
            </div>
            <i className={`fa-solid fa-chevron-down text-xs text-slate-400 transition-transform ${isOpen ? 'rotate-180' : ''}`} aria-hidden="true" />
          </button>

          {isOpen && (
            <div className="absolute right-0 z-50 mt-2 w-48 rounded-xl border border-slate-200 bg-white py-1 shadow-lg">
              <div className="border-b border-slate-100 px-4 py-3">
                <p className="text-sm font-semibold text-slate-900">{user?.name || 'User'}</p>
                <p className="text-xs text-slate-500">{user?.email || ''}</p>
              </div>
              <button
                type="button"
                onClick={handleProfile}
                className="flex w-full items-center gap-2 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50"
              >
                <i className="fa-regular fa-user w-4" aria-hidden="true" />
                Profile
              </button>
              <button
                type="button"
                onClick={handleLogout}
                className="flex w-full items-center gap-2 px-4 py-2 text-sm text-red-600 hover:bg-red-50"
              >
                <i className="fa-solid fa-arrow-right-from-bracket w-4" aria-hidden="true" />
                Logout
              </button>
            </div>
          )}
        </div>
      </div>
    </header>
  )
}

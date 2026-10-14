import { useEffect, useRef, useState } from 'react'
import { Link, useLocation } from 'react-router-dom'
import Sidebar from './Sidebar'
import Topbar from './Topbar'

export default function MainLayout({ children }) {
  const [sidebarOpen, setSidebarOpen] = useState(false)
  const mainRef = useRef(null)
  const { pathname, hash } = useLocation()
  // <main> scrolls, not the window, and it outlives the route: a new page opened at the old page's scroll position
  // (often its footer), so a sidebar link looked like it did nothing. A #section link is scrolled by its page instead.
  useEffect(() => { if (!hash) mainRef.current?.scrollTo(0, 0) }, [pathname, hash])

  return (
    <div className="flex h-screen overflow-hidden text-slate-800">
      <Sidebar isOpen={sidebarOpen} onClose={() => setSidebarOpen(false)} />

      <div className="flex min-w-0 flex-1 flex-col overflow-hidden bg-brand-gray">
        <Topbar sidebarOpen={sidebarOpen} onMenuClick={() => setSidebarOpen(true)} />

        <main ref={mainRef} className="custom-scrollbar flex-1 overflow-y-auto overflow-x-hidden p-4 sm:p-6">
          <div className="mx-auto w-full min-w-0 max-w-7xl">
            {children}

            <footer className="mt-8 flex flex-col items-center justify-between gap-4 border-t border-slate-200 pb-2 pt-4 md:flex-row">
              <p className="text-center text-xs text-slate-500 md:text-left">
                &copy; {new Date().getFullYear()} Archon Nell. All rights reserved.
              </p>
              <div className="flex flex-wrap items-center justify-center gap-4 text-xs text-slate-500">
                <Link className="hover:text-brand-blue" to="/help#privacy">Privacy Policy</Link>
                <Link className="hover:text-brand-blue" to="/help#terms">Terms of Service</Link>
                <Link className="hover:text-brand-blue" to="/help">Help Center</Link>
              </div>
            </footer>
          </div>
        </main>
      </div>
    </div>
  )
}

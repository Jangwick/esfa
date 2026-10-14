import { Component, Suspense, lazy } from 'react'
import { BrowserRouter, Navigate, Route, Routes, Link } from 'react-router-dom'
import { useAuthStore } from './store/authStore'
import MainLayout from './components/layout/MainLayout'
import ProtectedRoute from './components/auth/ProtectedRoute'
import SessionTimeout from './components/auth/SessionTimeout'
import PageLoader from './components/ui/PageLoader'

const Login = lazy(() => import('./pages/Login'))
const Dashboard = lazy(() => import('./pages/Dashboard'))
const Support = lazy(() => import('./pages/Support'))
const Payments = lazy(() => import('./pages/Payments'))
const Logistics = lazy(() => import('./pages/Logistics'))
const Subscriptions = lazy(() => import('./pages/Subscriptions'))
const Analytics = lazy(() => import('./pages/Analytics'))
const PaymentReceipt = lazy(() => import('./pages/PaymentReceipt'))
const BillingPrint = lazy(() => import('./pages/BillingPrint'))
const ShipmentLabels = lazy(() => import('./pages/ShipmentLabels'))
const Users = lazy(() => import('./pages/Users'))
const AuditLogs = lazy(() => import('./pages/AuditLogs'))
const Settings = lazy(() => import('./pages/Settings'))
const Help = lazy(() => import('./pages/Help'))
const Simulator = lazy(() => import('./pages/Simulator'))

class AppErrorBoundary extends Component {
  state = { error: false }
  static getDerivedStateFromError() { return { error: true } }
  render() {
    if (this.state.error) return <div role="alert" className="flex min-h-screen flex-col items-center justify-center gap-4 p-6"><h1 className="text-2xl font-bold text-slate-900">This page could not open</h1><p className="text-sm text-slate-500">Refresh the page to reload the application.</p><button className="rounded-lg bg-brand-blue px-4 py-2 text-sm font-medium text-white" onClick={() => window.location.reload()}>Try again</button></div>
    return this.props.children
  }
}

function Gate({ permission, role, children }) {
  const { hasPermission, hasRole } = useAuthStore()
  return (role ? hasRole(role) : hasPermission(permission)) ? children : <Navigate to="/forbidden" replace />
}

function SignedInApp() {
  return <ProtectedRoute><SessionTimeout /><MainLayout><Suspense fallback={<PageLoader />}><Routes>
    <Route path="/" element={<Navigate to="/dashboard" replace />} />
    <Route path="/dashboard" element={<Gate permission="view dashboard"><Dashboard /></Gate>} />
    <Route path="/support" element={<Gate permission="view support"><Support /></Gate>} />
    <Route path="/payments" element={<Gate permission="view payments"><Payments /></Gate>} />
    <Route path="/logistics" element={<Gate permission="view logistics"><Logistics /></Gate>} />
    <Route path="/subscriptions" element={<Gate permission="view subscriptions"><Subscriptions /></Gate>} />
    <Route path="/analytics" element={<Gate permission="view analytics"><Analytics /></Gate>} />
    <Route path="/users" element={<Gate permission="view users"><Users /></Gate>} />
    <Route path="/audit-logs" element={<Gate permission="view audit logs"><AuditLogs /></Gate>} />
    <Route path="/settings" element={<Settings />} />
    <Route path="/help" element={<Help />} />
    <Route path="/simulator" element={<Gate role="Admin"><Simulator /></Gate>} />
    <Route path="/forbidden" element={<div className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm"><h1 className="text-2xl font-bold text-slate-900">Access restricted</h1><p className="mt-2 text-sm text-slate-500">Your assigned role does not have permission to open this page.</p><Link to="/dashboard" className="mt-4 inline-block text-sm font-medium text-brand-blue">Back to dashboard</Link></div>} />
    <Route path="*" element={<div className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm"><h1 className="text-2xl font-bold text-slate-900">Page not found</h1><Link to="/dashboard" className="mt-4 inline-block text-sm font-medium text-brand-blue">Back to dashboard</Link></div>} />
  </Routes></Suspense></MainLayout></ProtectedRoute>
}

export default function App() {
  const { isAuthenticated } = useAuthStore()
  return <AppErrorBoundary><BrowserRouter><Suspense fallback={<PageLoader />}><Routes>
    <Route path="/login" element={isAuthenticated ? <Navigate to="/dashboard" replace /> : <Login />} />
    <Route path="/payments/:id/receipt" element={<ProtectedRoute><Gate permission="view payments"><PaymentReceipt /></Gate></ProtectedRoute>} />
    <Route path="/invoices/:id/print" element={<ProtectedRoute><Gate permission="view invoices"><BillingPrint /></Gate></ProtectedRoute>} />
    <Route path="/logistics/labels" element={<ProtectedRoute><Gate permission="view shipments"><ShipmentLabels /></Gate></ProtectedRoute>} />
    <Route path="/settlements/:id/statement" element={<ProtectedRoute><Gate permission="view settlements"><BillingPrint statement /></Gate></ProtectedRoute>} />
    <Route path="/*" element={<SignedInApp />} />
  </Routes></Suspense></BrowserRouter></AppErrorBoundary>
}

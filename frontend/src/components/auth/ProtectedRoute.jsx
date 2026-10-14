import { useEffect } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Navigate, useLocation } from 'react-router-dom'
import { useAuthStore } from '../../store/authStore'
import { api } from '../../lib/api'
import PageLoader from '../ui/PageLoader'

export default function ProtectedRoute({ children, permission }) {
  const { token, setUser, hasPermission } = useAuthStore()
  const location = useLocation()
  const query = useQuery({ queryKey: ['session', token], queryFn: async () => (await api.get('/user')).data, enabled: Boolean(token), placeholderData: undefined })
  useEffect(() => { if (query.data) setUser(query.data) }, [query.data, setUser])
  if (!token) return <Navigate to="/login" replace state={{ from: location.pathname }} />
  if (query.isPending) return <PageLoader />
  if (query.isError) return <div role="alert" className="flex min-h-screen flex-col items-center justify-center gap-4 p-6 text-center"><p className="text-sm text-red-700">We could not check your session. Check your connection and try again.</p><button onClick={() => query.refetch()} className="rounded-lg bg-brand-blue px-4 py-2 text-sm font-medium text-white">Try again</button></div>
  if (permission && !hasPermission(permission)) return <Navigate to="/forbidden" replace />
  return children
}

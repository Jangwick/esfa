import { useCallback } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '../lib/api'

const countKey = ['notifications', 'unread-count']
const listKey = ['notifications', 'list']

// perPage grows with "Show older notifications" (the API caps it at 100); the previous page stays visible meanwhile.
export function useNotifications(listEnabled = false, perPage = 10) {
  const queryClient = useQueryClient()
  const count = useQuery({ queryKey: countKey, queryFn: async () => (await api.get('/notifications/unread-count')).data.count, refetchInterval: 30000, refetchIntervalInBackground: false })
  const list = useQuery({ queryKey: [...listKey, perPage], queryFn: async () => (await api.get('/notifications', { params: { per_page: perPage } })).data, enabled: listEnabled, staleTime: 5000, placeholderData: (previous) => previous })
  const refresh = useCallback(() => {
    queryClient.invalidateQueries({ queryKey: countKey })
    queryClient.invalidateQueries({ queryKey: listKey })
  }, [queryClient])
  const markAsRead = useMutation({ mutationFn: (id) => api.patch(`/notifications/${id}/read`), onSuccess: refresh })
  const markAllAsRead = useMutation({ mutationFn: () => api.patch('/notifications/mark-all-as-read'), onSuccess: refresh })
  const remove = useMutation({ mutationFn: (id) => api.delete(`/notifications/${id}`), onSuccess: refresh })
  return {
    unreadCount: count.data ?? 0,
    notifications: list.data?.data ?? [],
    total: list.data?.total ?? 0,
    isListLoading: list.isPending,
    isListFetching: list.isFetching,
    // Only a failed list hides the list; a failed Read/Delete or count poll shows a short inline message instead.
    error: list.error,
    actionError: markAsRead.error || markAllAsRead.error || remove.error,
    markAsRead, markAllAsRead, remove,
    refetch: () => { markAsRead.reset(); markAllAsRead.reset(); remove.reset(); refresh() },
  }
}

import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { MutationCache, QueryClient, QueryClientProvider, keepPreviousData } from '@tanstack/react-query'
import '@fortawesome/fontawesome-free/css/all.min.css'
import 'bootstrap-icons/font/bootstrap-icons.css'
import './index.css'
import App from './App.jsx'
import FormValidationHint from './components/ui/FormValidationHint'
import { useAuthStore } from './store/authStore'

const queryClient = new QueryClient({
  mutationCache: new MutationCache({ onSuccess: () => queryClient.invalidateQueries({ refetchType: 'none' }) }),
  defaultOptions: {
    queries: {
      staleTime: 5 * 60 * 1000,
      gcTime: 10 * 60 * 1000,
      placeholderData: keepPreviousData,
      refetchOnWindowFocus: false,
      retry: 1,
    },
  },
})

useAuthStore.subscribe((state, previous) => {
  if (state.token !== previous.token) queryClient.clear()
})

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <QueryClientProvider client={queryClient}>
      <App />
      <FormValidationHint />
    </QueryClientProvider>
  </StrictMode>,
)

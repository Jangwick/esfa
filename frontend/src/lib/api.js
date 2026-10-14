import axios from 'axios'
import { useAuthStore } from '../store/authStore'

export const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || (import.meta.env.PROD ? '/api/v1' : 'http://127.0.0.1:8000/api/v1'),
  headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
  timeout: 20000,
})

export function signOut() {
  const token = useAuthStore.getState().token
  useAuthStore.getState().logout()
  if (token) return api.post('/logout', null, { headers: { Authorization: `Bearer ${token}` } }).catch(() => {})
  return Promise.resolve()
}

api.interceptors.request.use((config) => {
  const token = useAuthStore.getState().token
  if (token && !config.headers.Authorization) config.headers.Authorization = `Bearer ${token}`
  return config
})

api.interceptors.response.use((response) => response, (error) => {
  const isLoginCall = error.config?.url?.startsWith('/login')
  if (error.response?.status === 401 && !isLoginCall && error.config?.url !== '/logout') {
    sessionStorage.setItem('sfa_sign_out_reason', 'Your session ended. Please sign in again.')
    useAuthStore.getState().logout()
  }
  return Promise.reject(error)
})

export default api

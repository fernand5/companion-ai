import axios from 'axios'

// Defaults to the backend on the same host the page was loaded from (port
// 8000) rather than a hardcoded "localhost" — that way it keeps working when
// the frontend is reached over a LAN IP from another device, where
// "localhost" would otherwise resolve to that other device instead of this
// machine. VITE_API_URL can still override this for non-standard setups.
const defaultApiUrl = `${window.location.protocol}//${window.location.hostname}:8000/api`

const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL ?? defaultApiUrl,
  headers: {
    Accept: 'application/json',
  },
})

api.interceptors.request.use((config) => {
  const token = localStorage.getItem('auth_token')

  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }

  return config
})

api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      localStorage.removeItem('auth_token')
      // Dispatched instead of importing the auth store directly, which would
      // create a circular import (api.ts -> store -> services/auth.ts ->
      // api.ts). App.vue listens for this and clears its reactive session
      // state + redirects — without it, the store's `token` ref stays stale
      // and the UI keeps rendering as authenticated while every request
      // keeps silently 401ing.
      window.dispatchEvent(new Event('auth:unauthorized'))
    }

    return Promise.reject(error)
  },
)

export default api

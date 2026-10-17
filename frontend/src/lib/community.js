import { useEffect, useState } from 'react'
import { apiUrl } from './api'
import { apiFetch } from './session'
export async function communityRequest(endpoint, fields, signal) {
  const response = await apiFetch(apiUrl(endpoint), fields ? { method: 'POST', body: new URLSearchParams(fields), signal } : { signal, cache: 'no-store' })
  const data = await response.json()
  if (!response.ok) {
    const error = new Error(data.error || 'Nie udało się pobrać danych.')
    error.status = response.status
    throw error
  }
  return data
}
export function useCommunityResource(endpoint, revision = 0) {
  const [state, setState] = useState({ loading: true, data: null, error: null })
  useEffect(() => {
    const controller = new AbortController()
    setState({ loading: !!endpoint, data: null, error: null })
    if (endpoint) communityRequest(endpoint, null, controller.signal)
      .then(data => { if (!controller.signal.aborted) setState({ loading: false, data, error: null }) })
      .catch(error => { if (!controller.signal.aborted) setState({ loading: false, data: null, error }) })
    return () => controller.abort()
  }, [endpoint, revision])
  return state
}

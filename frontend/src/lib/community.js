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
/** `keep` retains the previous data while the same endpoint refreshes after a revision bump. */
export function useCommunityResource(endpoint, revision = 0, { keep = false } = {}) {
  const [state, setState] = useState({ loading: true, data: null, error: null, endpoint: null })
  useEffect(() => {
    const controller = new AbortController()
    setState(prev => keep && endpoint && prev.endpoint === endpoint && prev.data ? prev : { loading: !!endpoint, data: null, error: null, endpoint })
    if (endpoint) communityRequest(endpoint, null, controller.signal)
      .then(data => { if (!controller.signal.aborted) setState({ loading: false, data, error: null, endpoint }) })
      .catch(error => { if (!controller.signal.aborted) setState({ loading: false, data: null, error, endpoint }) })
    return () => controller.abort()
  }, [endpoint, revision, keep])
  return state
}
const socialCapabilities = new Map()
/** One capabilities request per signed-in account and page load; a disabled module (404) resolves to null. */
export function useSocialCapabilities(user) {
  const steamid = user?.steamid ?? null
  const [result, setResult] = useState({ steamid: null, data: null })
  useEffect(() => {
    if (!steamid) return
    let live = true
    if (!socialCapabilities.has(steamid)) socialCapabilities.set(steamid, communityRequest('social.php?action=capabilities').catch(() => null))
    socialCapabilities.get(steamid).then(data => { if (live) setResult({ steamid, data }) })
    return () => { live = false }
  }, [steamid])
  return steamid && result.steamid === steamid ? result.data : null
}

import { Navigate, useLocation } from 'react-router-dom'

/**
 * ADS-MANAGER-007 — the advanced view's Accounts, Ad groups and Ads pages are retired. An old address lands on the Ads
 * Manager at the same level, with its period and other query kept.
 */
export function LevelRedirect({ level }: { level: 'accounts' | 'ad_sets' | 'ads' }) {
  const { pathname, search } = useLocation()
  const params = new URLSearchParams(search)
  params.delete('view')
  params.set('level', level)
  const base = pathname.replace(/\/(accounts|ad-groups|ads)\/?$/, '/campaigns')
  return <Navigate to={`${base}?${params.toString()}`} replace />
}

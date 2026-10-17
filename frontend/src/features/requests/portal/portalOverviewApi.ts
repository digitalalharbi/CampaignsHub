import { getData } from '@/lib/api/client'

/** CP-DASH-001 / CP-VISIBILITY-001 — the portfolio and the visibility map, as the server answers them. */
export interface PortalOverview {
  space: { slug: string; name: string; mode: string }
  period: { from: string; to: string; days: number }
  currency: string | null
  freshness: { state: string | null; last_sync_at: string | null }
  kpis: Record<string, number | null>
  platforms: Array<{ provider: string | null; impressions: number | null; clicks: number | null; conversions: number | null; spend?: number | null }>
  projects: Array<{ id: string; name: string; status: string | null; spend?: number }>
  campaigns: { total: number; by_status: Record<string, number> } | null
  /** What is waiting on this client. A missing key means «not available to you», never zero. */
  attention?: { requests_waiting?: number; invoices_overdue?: number; invoices_due?: number }
}

export interface PortalSpaceVisibility {
  slug: string
  mode: string
  visibility: Record<string, boolean>
  capabilities: Record<string, boolean>
}

export function fetchPortalOverview(params: { from?: string; to?: string } = {}): Promise<PortalOverview> {
  const query = new URLSearchParams(Object.entries(params).filter(([, v]) => v)).toString()

  return getData<PortalOverview>(`/client/overview${query === '' ? '' : `?${query}`}`)
}

export function fetchPortalVisibility(): Promise<PortalSpaceVisibility[]> {
  return getData<{ spaces: PortalSpaceVisibility[] }>('/client/visibility').then((d) => d.spaces)
}

import { getData } from '@/lib/api/client'

/** CP-DASH-001 / CP-VISIBILITY-001 — the portfolio and the visibility map, as the server answers them. */
export interface PortalOverview {
  space: { slug: string; name: string; mode: string }
  period: { from: string; to: string; days: number }
  currency: string | null
  freshness: { state: string | null; last_sync_at: string | null }
  kpis: Record<string, number | null>
  /**
   * CP-PARITY-001 — each KPI's movement against the window before it, as the server computed it (0.12 = +12%).
   * Absent when the server could not compare (a mixed-currency space, or no previous window); a key absent here
   * is a card with no pill — never a pill drawn from figures the page would have to invent.
   */
  movement?: Record<string, number | null>
  platforms: Array<{ provider: string | null; impressions: number | null; clicks: number | null; conversions: number | null; spend?: number | null }>
  projects: Array<{ id: string; name: string; status: string | null; spend?: number }>
  campaigns: { total: number; by_status: Record<string, number> } | null
  /** What is waiting on this client. A missing key means «not available to you», never zero. */
  attention?: { requests_waiting?: number; invoices_overdue?: number; invoices_due?: number }
  /** One point per day that has data; a day with none is absent (a gap), and an unreported figure is null. */
  trend?: Array<{ date: string; impressions: number | null; clicks: number | null; conversions: number | null }>
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

import { getData } from '@/lib/api/client'

export interface ReportData {
  kpis: {
    total_campaigns: number
    active_campaigns: number
    total_budget: number
    total_impressions: number
    total_clicks: number
    total_spend: number
  }
  chart: Array<{
    date: string
    impressions: number
    clicks: number
    spend: number
  }>
}

export function getProjectReports(projectId: string): Promise<ReportData> {
  return getData<ReportData>(`/projects/${projectId}/reports`)
}

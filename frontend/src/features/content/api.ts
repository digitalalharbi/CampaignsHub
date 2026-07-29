import { getData } from '@/lib/api/client'

/** A creative in the tenant-wide library. Mirrors backend CreativeLibraryController. */
export interface Creative {
  id: string
  name: string | null
  client_display_name: string | null
  provider: string
  format: string
  status: string
  thumbnail_url: string | null
  preview_url: string | null
  destination_url: string | null
  has_preview: boolean
  campaign_id: string | null
  campaign_name: string | null
  project_id: string | null
  is_demo: boolean
  last_synced_at: string | null
  metrics: {
    spend: number
    impressions: number
    clicks: number
    conversions: number
    revenue: number
    ctr: number | null
    roas: number | null
  }
  /** Explainable 30d classification vs the workspace median (null = no data in window). */
  performance: { class: 'top' | 'needs_attention' | 'normal' | string; reason_ar: string; reason_en: string } | null
}

export const listCreatives = () => getData<Creative[]>('/creatives')

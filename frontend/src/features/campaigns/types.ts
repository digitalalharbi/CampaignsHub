export interface UnifiedCampaign {
  id: string
  project_id: string
  name: string
  client_display_name?: string | null
  objective: string
  status: string
  stage?: string | null
  performance_label?: string | null
  priority?: string | null
  needs_attention?: boolean
  total_budget: number | null
  budget_currency: string
  starts_on: string | null
  ends_on: string | null
  primary_conversion_purpose: string | null
  attribution_model: string | null
  attribution_window: string | null
  owner_id: number | null
  target_kpi: Record<string, unknown> | null
  audience: string | null
  regions: string[] | null
  external_campaigns_count?: number
  created_at: string | null
}

export interface ExternalCampaign {
  id: string
  unified_campaign_id: string | null
  external_account_id: string
  provider: string
  external_id: string
  name: string
  status: string
  objective: string | null
  daily_budget: number | null
  lifetime_budget: number | null
  currency: string | null
  is_linked: boolean
  linked_at: string | null
  last_synced_at: string | null
}

export const CAMPAIGN_STATUSES = ['draft', 'active', 'paused', 'completed', 'archived', 'pending', 'unknown'] as const

export const CAMPAIGN_OBJECTIVES = [
  'awareness',
  'traffic',
  'engagement',
  'leads',
  'app_installs',
  'sales',
  'conversions',
  'other',
] as const

/** Sandbox/Demo is not a real platform — it must always be surfaced as demo, never production. */
export const DEMO_PROVIDERS = ['sandbox', 'demo'] as const

export function isDemoProvider(provider: string): boolean {
  return (DEMO_PROVIDERS as readonly string[]).includes(provider)
}

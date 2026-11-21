import { getData } from '@/lib/api/client'

/** META-DIAGNOSIS-001 — what the sync recorded about each Meta connection. Read-only; it calls nothing at Meta. */
export interface MetaRefusal {
  kind: string
  actor: 'customer' | 'operator' | 'product' | 'nobody'
  code: number | null
  subcode: number | null
  trace: string | null
  action_ar: string
  action_en: string
}

export interface MetaDiagnosisAccount {
  external_id: string
  name: string
  status: string
  health: string
  selected: boolean
  access_lost_at: string | null
  last_metrics_at: string | null
  error_category: string | null
  creatives: number
  expired_media: number
  structure: {
    status: string | null
    at: string | null
    refusal: MetaRefusal | null
    media: { asked?: number; resolved?: number; not_asked?: number; stopped_by?: string | null } | null
    media_renewal: { expired: number; asked: number; renewed: number; still_expired: number; stopped_by: string | null } | null
    usage: { max_call_count: number | null; regain_minutes: number } | null
  }
  metrics: { status: string | null; at: string | null; refusal: MetaRefusal | null }
}

export interface MetaDiagnosisConnection {
  id: string
  tenant: string
  name: string
  app: string
  status: string
  state: 'needs_reconnect' | 'disconnected' | 'token_expired' | 'permission_missing' | 'never_synced' | 'stale' | 'syncing'
  token: { state: 'valid' | 'expiring' | 'expired' | 'not_stated'; expires_at: string | null }
  scopes_granted: string[] | null
  scopes_missing: string[] | null
  refusal: MetaRefusal | null
  last_error: string | null
  insights_denied_at: string | null
  discovery_blocked_reason: string | null
  last_discovery_succeeded_at: string | null
  last_sync_at: string | null
  accounts_total: number
  accounts_selected: number
  accounts_synced: number
  expired_media: number
  refusals_24h: Record<string, number>
  accounts: MetaDiagnosisAccount[]
}

export function fetchMetaDiagnosis(): Promise<{ connections: MetaDiagnosisConnection[] }> {
  return getData('/admin/integrations/meta/diagnosis')
}

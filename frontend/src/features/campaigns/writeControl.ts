import { useQuery } from '@tanstack/react-query'
import { getData } from '@/lib/api/client'

/**
 * CAMPAIGN-MGMT-DOMAIN-001 — the write-capability registry, read before any surface draws a control.
 *
 * The Owner's rule: never expose a write action for a provider unless that exact write capability is
 * implemented and permission-gated. The server is the only place «implemented» is declared; the
 * interface never decides it, it asks. `allowed` is the server's answer for THIS reader — implemented
 * (not merely declared) AND permitted — and {@link ProviderWriteGate} draws nothing without it.
 */
export type WriteStatus = 'not_implemented' | 'implemented_not_verified' | 'awaiting_credentials' | 'verified'

export interface WriteCapability {
  provider: string
  capability: string
  status: WriteStatus
  permission: string
  evidence: string | null
  permitted: boolean
  allowed: boolean
}

export interface WriteCapabilities {
  statuses: WriteStatus[]
  rule_ar: string
  rule_en: string
  providers: { provider: string; capabilities: WriteCapability[] }[]
}

export function useWriteCapabilities(projectId: string | null) {
  return useQuery({
    queryKey: ['projects', projectId, 'campaign-management', 'capabilities'],
    queryFn: () => getData<WriteCapabilities>(`/projects/${projectId}/campaign-management/capabilities`),
    enabled: Boolean(projectId),
    staleTime: 5 * 60_000,
  })
}

/** The one question a surface may ask before drawing a provider write. */
export function writeAllowed(caps: WriteCapabilities | undefined, provider: string, capability: string): boolean {
  if (!caps) return false
  const p = caps.providers.find((row) => row.provider === provider)
  return p?.capabilities.find((c) => c.capability === capability)?.allowed === true
}

export const WRITE_STATUS_LABELS: Record<WriteStatus, { ar: string; en: string }> = {
  not_implemented: { ar: 'غير منفَّذ', en: 'Not implemented' },
  implemented_not_verified: { ar: 'منفَّذ — غير موثَّق', en: 'Implemented, not verified' },
  awaiting_credentials: { ar: 'بانتظار بيانات الاعتماد', en: 'Awaiting credentials' },
  verified: { ar: 'موثَّق على الإنتاج', en: 'Verified in Production' },
}

export const CAPABILITY_LABELS: Record<string, { ar: string; en: string }> = {
  create_campaign: { ar: 'إنشاء حملة', en: 'Create campaign' },
  edit_campaign: { ar: 'تعديل حملة', en: 'Edit campaign' },
  pause_resume: { ar: 'إيقاف / استئناف', en: 'Pause / resume' },
  schedule: { ar: 'الجدولة', en: 'Schedule' },
  budget_change: { ar: 'تغيير الميزانية', en: 'Budget change' },
  bid_strategy: { ar: 'استراتيجية المزايدة', en: 'Bid strategy' },
  objective: { ar: 'الهدف', en: 'Objective' },
  ad_set_management: { ar: 'إدارة المجموعات الإعلانية', en: 'Ad set management' },
  ad_creation: { ar: 'إنشاء إعلان', en: 'Ad creation' },
  creative_binding: { ar: 'ربط الإبداع بالإعلان', en: 'Creative binding' },
  targeting: { ar: 'الاستهداف', en: 'Targeting' },
  placements: { ar: 'المواضع', en: 'Placements' },
  publish: { ar: 'النشر', en: 'Publish' },
  duplicate: { ar: 'التكرار', en: 'Duplicate' },
}

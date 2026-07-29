import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { ChevronDown, ChevronLeft, Layers, Megaphone, Target } from 'lucide-react'
import type { UnifiedCampaign } from './types'
import { getData } from '@/lib/api/client'
import { Badge } from '@/components/ui/Badge'
import { EmptyState, Skeleton } from '@/components/ui/States'
import { money } from '@/features/analytics/format'
import { fmtDateTime } from '@/lib/datetime'

/**
 * CAMPDET-010 — the real ad-set / ad hierarchy beneath a campaign.
 *
 * Three genuinely different situations are told apart instead of collapsing into one blank panel: the
 * campaign is linked to no platform campaign; it is linked but the structure was never pulled; or the
 * structure exists. Rows carry their source, so a demo hierarchy is labelled and never passes for a
 * live platform pull.
 */

interface AdRow {
  id: string
  external_id: string
  name: string
  status: string
  review_status: string | null
  destination_url: string | null
  is_demo: boolean
}

interface AdSetRow {
  id: string
  provider: string
  external_id: string
  name: string
  status: string
  optimization_goal: string | null
  bid_strategy: string | null
  daily_budget: number | null
  lifetime_budget: number | null
  currency: string | null
  targeting: Record<string, unknown> | null
  is_demo: boolean
  source_type: string
  last_synced_at: string | null
  ads: AdRow[]
}

interface StructurePayload {
  linked_platform_campaigns: Array<{ id: string; provider: string; external_id: string; name: string }>
  ad_sets: AdSetRow[]
  state: 'not_linked' | 'not_synced' | 'ready'
}

const GOAL: Record<string, string> = {
  conversions: 'التحويلات', link_clicks: 'النقرات', reach: 'الوصول',
  impressions: 'الظهور', video_views: 'مشاهدات الفيديو', leads: 'العملاء المحتملون',
}
const BID: Record<string, string> = { lowest_cost: 'أقل تكلفة', cost_cap: 'سقف تكلفة', bid_cap: 'سقف مزايدة' }
const STATUS: Record<string, { ar: string; tone: 'success' | 'warning' | 'neutral' }> = {
  active: { ar: 'نشطة', tone: 'success' },
  paused: { ar: 'متوقفة', tone: 'warning' },
  archived: { ar: 'مؤرشفة', tone: 'neutral' },
}
const REVIEW: Record<string, { ar: string; tone: 'success' | 'warning' | 'danger' }> = {
  approved: { ar: 'معتمد', tone: 'success' },
  pending: { ar: 'قيد المراجعة', tone: 'warning' },
  rejected: { ar: 'مرفوض', tone: 'danger' },
}

export function CampaignStructureTab({ campaign, projectId }: { campaign: UnifiedCampaign; projectId: string }) {
  const [open, setOpen] = useState<Record<string, boolean>>({})

  const q = useQuery({
    queryKey: ['projects', projectId, 'campaigns', campaign.id, 'structure'],
    queryFn: () => getData<StructurePayload>(`/projects/${projectId}/campaigns/${campaign.id}/structure`),
    enabled: Boolean(projectId && campaign.id),
  })

  if (q.isLoading) return <Skeleton className="h-56" />
  if (q.isError || !q.data) return <EmptyState title="تعذّر تحميل بنية الحملة" description="حاول تحديث الصفحة." />

  const { ad_sets: adSets, linked_platform_campaigns: linked, state } = q.data

  if (state === 'not_linked') {
    return (
      <EmptyState
        title="الحملة غير مرتبطة بأي حملة على منصة إعلانية"
        description="المجموعات الإعلانية والإعلانات تُقرأ من المنصة نفسها. اربط حملة خارجية من تبويب «المنصات» لتظهر بنيتها هنا."
      />
    )
  }

  if (state === 'not_synced') {
    return (
      <EmptyState
        title="لم تُجلب بنية الحملة بعد"
        description={`الحملة مرتبطة بـ ${linked.length} حملة على المنصة، لكن لم تُنفَّذ مزامنة بنية بعد. شغّل المزامنة من «تكاملات المشروع».`}
      />
    )
  }

  const totalAds = adSets.reduce((a, s) => a + s.ads.length, 0)

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-text-secondary">
        <span className="inline-flex items-center gap-1.5"><Layers size={14} /> <span className="tnum font-semibold text-text-primary">{adSets.length}</span> مجموعة إعلانية</span>
        <span className="inline-flex items-center gap-1.5"><Megaphone size={14} /> <span className="tnum font-semibold text-text-primary">{totalAds}</span> إعلانًا</span>
        <span className="text-text-muted">مرتبطة بـ {linked.map((l) => l.provider).join(' · ')}</span>
      </div>

      <ul data-testid="ad-sets" className="space-y-2">
        {adSets.map((s) => {
          const st = STATUS[s.status] ?? { ar: s.status, tone: 'neutral' as const }
          const isOpen = open[s.id] ?? true
          return (
            <li key={s.id} className="overflow-hidden rounded-2xl border border-border bg-surface shadow-[var(--shadow-small)]">
              <button
                onClick={() => setOpen((prev) => ({ ...prev, [s.id]: !isOpen }))}
                className="flex w-full flex-wrap items-center justify-between gap-2 p-3.5 text-start hover:bg-surface-hover"
              >
                <span className="flex min-w-0 items-center gap-2">
                  {isOpen ? <ChevronDown size={15} className="text-text-muted" /> : <ChevronLeft size={15} className="text-text-muted" />}
                  <span className="min-w-0">
                    <span className="flex flex-wrap items-center gap-1.5">
                      <span className="font-bold text-text-primary">{s.name}</span>
                      <Badge tone={st.tone}>{st.ar}</Badge>
                      {s.is_demo && <Badge tone="warning">تجريبية</Badge>}
                    </span>
                    <span className="tnum mt-0.5 block text-[11px] text-text-muted" dir="ltr">{s.external_id}</span>
                  </span>
                </span>
                <span className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-text-secondary">
                  <span className="inline-flex items-center gap-1"><Target size={12} /> {GOAL[s.optimization_goal ?? ''] ?? s.optimization_goal ?? '—'}</span>
                  <span>{BID[s.bid_strategy ?? ''] ?? s.bid_strategy ?? '—'}</span>
                  <span className="tnum">{s.daily_budget !== null ? `${money(s.daily_budget, s.currency ?? 'SAR')} / يوم` : '—'}</span>
                  <span className="tnum text-text-muted">{s.ads.length} إعلان</span>
                </span>
              </button>

              {isOpen && (
                <div className="border-t border-border px-3.5 pb-3.5 pt-3">
                  {s.targeting && Object.keys(s.targeting).length > 0 && (
                    <div className="mb-3 flex flex-wrap gap-1.5">
                      {Object.entries(s.targeting).map(([k, v]) => (
                        <span key={k} className="rounded-full bg-surface-secondary px-2.5 py-1 text-[11px] text-text-secondary">
                          <span className="text-text-muted">{k}:</span> {Array.isArray(v) ? v.join(' · ') : String(v)}
                        </span>
                      ))}
                    </div>
                  )}

                  {s.ads.length === 0 ? (
                    <p className="text-xs text-text-muted">لا توجد إعلانات داخل هذه المجموعة.</p>
                  ) : (
                    <ul className="space-y-1.5">
                      {s.ads.map((a) => {
                        const rv = a.review_status ? REVIEW[a.review_status] : null
                        const ast = STATUS[a.status] ?? { ar: a.status, tone: 'neutral' as const }
                        return (
                          <li key={a.id} data-testid="ad-row" className="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-surface-secondary p-2.5 text-xs">
                            <span className="min-w-0">
                              <span className="block truncate font-semibold text-text-primary">{a.name}</span>
                              <span className="tnum block text-[11px] text-text-muted" dir="ltr">{a.external_id}</span>
                            </span>
                            <span className="flex items-center gap-1.5">
                              <Badge tone={ast.tone}>{ast.ar}</Badge>
                              {rv && <Badge tone={rv.tone}>{rv.ar}</Badge>}
                            </span>
                          </li>
                        )
                      })}
                    </ul>
                  )}

                  {s.last_synced_at && (
                    <p className="mt-2 text-[11px] text-text-muted">
                      آخر مزامنة بنية: <span className="tnum">{fmtDateTime(s.last_synced_at)}</span> · المصدر: {s.source_type === 'demo' ? 'بيانات تجريبية' : 'مزامنة من المنصة'}
                    </p>
                  )}
                </div>
              )}
            </li>
          )
        })}
      </ul>
    </div>
  )
}

import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ChevronDown, ChevronLeft, ImageOff, Layers, Megaphone, RefreshCw, Target } from 'lucide-react'
import type { UnifiedCampaign } from './types'
import { getData, postData } from '@/lib/api/client'
import { Badge } from '@/components/ui/Badge'
import { EmptyState, Skeleton } from '@/components/ui/States'
import { money } from '@/features/analytics/format'
import { fmtDateTime } from '@/lib/datetime'

/**
 * CAMPDET-010 / STRUCT-001 — the real ad-set / ad hierarchy beneath a campaign.
 *
 * Four genuinely different situations are told apart instead of collapsing into one blank panel: the
 * campaign is linked to no platform campaign; the platform holds no credentials on this install, so
 * nothing could ever have been pulled; it is linked but the structure was never pulled; or it exists.
 * Rows carry their source, so a demo hierarchy is labelled and never passes for a live platform pull.
 *
 * Ads with no ad set are rendered too. LinkedIn has no ad-set level, and a panel that only walked the
 * ad sets would show a LinkedIn campaign as empty while its ads sat in the table.
 */

interface CreativeRow {
  id: string
  name: string
  format: string | null
  thumbnail_url: string | null
  preview_url: string | null
}

interface AdRow {
  id: string
  external_id: string
  name: string
  status: string
  review_status: string | null
  destination_url: string | null
  is_demo: boolean
  creative: CreativeRow | null
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
  ads_without_ad_set: AdRow[]
  awaiting_credentials: string[]
  state: 'not_linked' | 'awaiting_credentials' | 'not_synced' | 'ready'
}

const GOAL: Record<string, string> = {
  conversions: 'التحويلات', offsite_conversions: 'التحويلات خارج المنصة', link_clicks: 'النقرات',
  reach: 'الوصول', impressions: 'الظهور', video_views: 'مشاهدات الفيديو', leads: 'العملاء المحتملون',
}
const BID: Record<string, string> = {
  lowest_cost: 'أقل تكلفة', lowest_cost_without_cap: 'أقل تكلفة', cost_cap: 'سقف تكلفة',
  bid_cap: 'سقف مزايدة', target_cpa: 'تكلفة مستهدفة', manual_cpc: 'مزايدة يدوية',
}
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
const FORMAT: Record<string, string> = { image: 'صورة', video: 'فيديو', carousel: 'دوّار', text: 'نص' }
const PROVIDER: Record<string, string> = {
  meta: 'ميتا', google: 'جوجل', google_ads: 'جوجل', tiktok: 'تيك توك',
  snapchat: 'سناب شات', x: 'إكس', linkedin: 'لينكدإن',
}

/** One ad row, with whatever the platform said about its creative and nothing more. */
function Ad({ ad }: { ad: AdRow }) {
  const rv = ad.review_status ? REVIEW[ad.review_status] : null
  const st = STATUS[ad.status] ?? { ar: ad.status, tone: 'neutral' as const }

  return (
    <li data-testid="ad-row" className="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-surface-secondary p-2.5 text-xs">
      <span className="flex min-w-0 items-center gap-2.5">
        {ad.creative?.thumbnail_url ? (
          <img src={ad.creative.thumbnail_url} alt="" className="h-9 w-9 shrink-0 rounded-md object-cover" />
        ) : (
          // Never a placeholder image that could pass for the real creative.
          <span
            data-testid="no-preview"
            title="لا تتوفر معاينة من المنصة"
            className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-surface text-text-muted"
          >
            <ImageOff size={14} />
          </span>
        )}
        <span className="min-w-0">
          <span className="block truncate font-semibold text-text-primary">{ad.name}</span>
          <span className="tnum block text-[11px] text-text-muted" dir="ltr">{ad.external_id}</span>
        </span>
      </span>
      <span className="flex flex-wrap items-center gap-1.5">
        {ad.creative?.format && <Badge tone="neutral">{FORMAT[ad.creative.format] ?? ad.creative.format}</Badge>}
        <Badge tone={st.tone}>{st.ar}</Badge>
        {rv && <Badge tone={rv.tone}>{rv.ar}</Badge>}
      </span>
    </li>
  )
}

export function CampaignStructureTab({ campaign, projectId }: { campaign: UnifiedCampaign; projectId: string }) {
  const [open, setOpen] = useState<Record<string, boolean>>({})
  const queryClient = useQueryClient()
  const key = ['projects', projectId, 'campaigns', campaign.id, 'structure']

  const q = useQuery({
    queryKey: key,
    queryFn: () => getData<StructurePayload>(`/projects/${projectId}/campaigns/${campaign.id}/structure`),
    enabled: Boolean(projectId && campaign.id),
  })

  /*
   * The button QUEUES a discovery; it does not fetch.
   *
   * So the honest confirmation is «أُرسل الطلب» — not «تمت المزامنة», which would claim a platform
   * round trip that has not happened yet and may still fail in the worker.
   */
  const discover = useMutation({
    mutationFn: () => postData<{ queued: number }>(`/projects/${projectId}/campaigns/${campaign.id}/structure/sync`),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: key })
    },
  })

  if (q.isLoading) return <Skeleton className="h-56" />
  if (q.isError || !q.data) return <EmptyState title="تعذّر تحميل بنية الحملة" description="حاول تحديث الصفحة." />

  const {
    ad_sets: adSets,
    ads_without_ad_set: looseAds,
    linked_platform_campaigns: linked,
    awaiting_credentials: awaiting,
    state,
  } = q.data

  const discoverButton = (
    <button
      type="button"
      data-testid="discover-structure"
      onClick={() => discover.mutate()}
      disabled={discover.isPending}
      className="inline-flex items-center gap-1.5 rounded-xl border border-border bg-surface px-3 py-1.5 text-xs font-semibold text-text-primary hover:bg-surface-hover disabled:opacity-60"
    >
      <RefreshCw size={13} className={discover.isPending ? 'animate-spin' : undefined} />
      {discover.isPending ? 'جارٍ الإرسال…' : 'اجلب البنية الآن'}
    </button>
  )

  const queueNotice = discover.isSuccess ? (
    <p data-testid="structure-queued" className="text-xs text-text-secondary">
      أُرسل طلب الجلب إلى المنصة. ستظهر المجموعات والإعلانات هنا بعد اكتمال المزامنة.
    </p>
  ) : discover.isError ? (
    <p data-testid="structure-queue-failed" className="text-xs text-danger">
      تعذّر إرسال الطلب. تحقّق من حالة الربط في صفحة التكاملات.
    </p>
  ) : null

  if (state === 'not_linked') {
    return (
      <EmptyState
        title="الحملة غير مرتبطة بأي حملة على منصة إعلانية"
        description="المجموعات الإعلانية والإعلانات تُقرأ من المنصة نفسها. اربط حملة خارجية من تبويب «المنصات» لتظهر بنيتها هنا."
      />
    )
  }

  /*
   * The state that used to be indistinguishable from «never synced».
   *
   * Offering a discovery button for a platform whose keys nobody has entered sends the reader to press
   * something that cannot work — so this state has no button at all, and names where the missing setup
   * actually lives without naming any key.
   */
  if (state === 'awaiting_credentials') {
    return (
      <EmptyState
        title="المنصة غير مهيّأة على هذا النظام"
        description={`لم تُضبَط بعد إعدادات ${awaiting.map((p) => PROVIDER[p] ?? p).join(' و')} على مستوى النظام، لذلك لا يمكن قراءة المجموعات والإعلانات. يتولّى مدير المنصة ذلك من إعدادات مزوّدي التكامل.`}
      />
    )
  }

  if (state === 'not_synced') {
    return (
      <div data-testid="structure-not-synced" className="space-y-3 rounded-2xl border border-border bg-surface p-6 text-center">
        <p className="font-bold text-text-primary">لم تُجلب بنية الحملة بعد</p>
        <p className="text-sm text-text-secondary">
          الحملة مرتبطة بـ <span className="tnum">{linked.length}</span> حملة على المنصة. تُجلب البنية تلقائيًا كل ست ساعات، أو اطلبها الآن.
        </p>
        <div className="flex justify-center">{discoverButton}</div>
        {queueNotice}
      </div>
    )
  }

  const totalAds = adSets.reduce((a, s) => a + s.ads.length, 0) + looseAds.length

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
        <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-text-secondary">
          <span className="inline-flex items-center gap-1.5"><Layers size={14} /> <span className="tnum font-semibold text-text-primary">{adSets.length}</span> مجموعة إعلانية</span>
          <span className="inline-flex items-center gap-1.5"><Megaphone size={14} /> <span className="tnum font-semibold text-text-primary">{totalAds}</span> إعلانًا</span>
          <span className="text-text-muted">مرتبطة بـ {linked.map((l) => PROVIDER[l.provider] ?? l.provider).join(' · ')}</span>
        </div>
        {discoverButton}
      </div>
      {queueNotice}

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
                      {s.ads.map((a) => <Ad key={a.id} ad={a} />)}
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

      {looseAds.length > 0 && (
        <section data-testid="ads-without-ad-set" className="rounded-2xl border border-border bg-surface p-3.5 shadow-[var(--shadow-small)]">
          <p className="mb-1 font-bold text-text-primary">إعلانات بلا مجموعة إعلانية</p>
          {/* Said plainly, because an empty ad-set list beside a list of ads otherwise reads as a bug. */}
          <p className="mb-3 text-[11px] text-text-muted">
            بعض المنصات — مثل لينكدإن — لا تحتوي على مستوى «مجموعة إعلانية»، فتُعرض إعلاناتها أسفل الحملة مباشرة.
          </p>
          <ul className="space-y-1.5">
            {looseAds.map((a) => <Ad key={a.id} ad={a} />)}
          </ul>
        </section>
      )}
    </div>
  )
}

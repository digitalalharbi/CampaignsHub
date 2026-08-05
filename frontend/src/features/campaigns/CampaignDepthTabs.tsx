import { AlertCircle, CheckCircle2, Clock, Layers, Target, XCircle } from 'lucide-react'
import { useCampaignEvents, useCampaignSyncLog } from './metrics'
import { objectiveLabel } from './labels'
import type { UnifiedCampaign } from './types'
import { Badge } from '@/components/ui/Badge'
import { EmptyState, Skeleton } from '@/components/ui/States'
import type { Range } from '@/features/analytics/api'
import { money, num } from '@/features/analytics/format'
import { fmtDateTime } from '@/lib/datetime'
import { QueryFailure } from '@/components/ui/QueryFailure'
import { useUi } from '@/stores/ui'

/**
 * CAMPDET-010 — the depth sections that were missing from the campaign detail page: who the campaign
 * targets, which conversion events it actually produced, and the real sync history behind its numbers.
 *
 * Each one shows what the system genuinely knows and says plainly when it knows nothing — an empty
 * targeting field reads "not set", an unlinked campaign reads "no sync history", and ad-set/ad level
 * breakdowns are declared as requiring a connected platform rather than being faked.
 */

function Field({ label, value, hint }: { label: string; value: React.ReactNode; hint?: string }) {
  return (
    <div className="rounded-xl border border-border bg-surface p-3">
      <div className="text-[11px] font-semibold uppercase tracking-wide text-text-muted">{label}</div>
      <div className="mt-1 text-sm font-semibold text-text-primary">{value}</div>
      {hint && <div className="mt-0.5 text-[11px] text-text-muted">{hint}</div>}
    </div>
  )
}

const notSet = <span className="font-normal text-text-muted">غير محدد</span>

/** Governance fields are loosely typed (some are jsonb) — render only real scalars, never "[object Object]". */
const str = (v: unknown): string => (typeof v === 'string' || typeof v === 'number' ? String(v) : '')

/** Audience, regions and the attribution settings that decide how results are credited. */
export function CampaignAudienceTab({ campaign, locale }: { campaign: UnifiedCampaign; locale: 'ar' | 'en' }) {
  const regions = Array.isArray(campaign.regions) ? campaign.regions : null
  const audience = typeof campaign.audience === 'string' ? campaign.audience : null

  return (
    <div className="space-y-4">
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <Field label="الهدف" value={objectiveLabel(campaign.objective, locale)} />
        <Field label="الجمهور المستهدف" value={audience?.trim() ? audience : notSet} hint="يُحرَّر من «تعديل الحملة»" />
        <Field
          label="المناطق"
          value={regions && regions.length > 0
            ? <span className="flex flex-wrap gap-1">{regions.map((r) => <Badge key={String(r)} tone="neutral">{String(r)}</Badge>)}</span>
            : notSet}
        />
        <Field label="غرض التحويل الأساسي" value={str(campaign.primary_conversion_purpose) || notSet} />
        <Field label="نموذج الإسناد" value={str(campaign.attribution_model) || notSet} hint="يحدد كيف تُنسب النتيجة للحملة" />
        <Field label="نافذة الإسناد" value={str(campaign.attribution_window) || notSet} />
        <Field label="مؤشر الأداء المستهدف" value={str(campaign.target_kpi) || notSet} />
      </div>

      {/* Honest boundary: ad-set and ad level targeting lives inside the ad platform. */}
      <div className="flex items-start gap-2 rounded-xl border border-border bg-surface-secondary p-3 text-sm">
        <Layers size={16} className="mt-0.5 shrink-0 text-text-muted" />
        <p className="text-text-secondary">
          الاستهداف على مستوى <strong className="text-text-primary">المجموعات الإعلانية والإعلانات</strong> يُعرض في تبويب
          «المجموعات والإعلانات» كما تُرجعه المنصة — الهدف، استراتيجية المزايدة، الميزانية اليومية، شرائح الاستهداف وحالة مراجعة كل إعلان.
          كل صف موسوم بمصدره، ولا يُعرض أي تقدير أو بيانات مُصطنعة.
        </p>
      </div>
    </div>
  )
}

const EVENT_ICON = { high: CheckCircle2, low: AlertCircle }

/** Conversion events actually recorded, with cost per event and a mismatch warning. */
export function CampaignEventsTab({ campaign, projectId, range }: { campaign: UnifiedCampaign; projectId: string; range: Range }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const q = useCampaignEvents(projectId, campaign.id, range)
  const cur = campaign.budget_currency || 'SAR'

  if (q.isLoading) return <Skeleton className="h-48" />
  if (q.isError) {
    return <QueryFailure error={q.error} ar={ar} testId="campaign-events-failure" onRetry={() => void q.refetch()}
      fallbackTitle={ar ? 'تعذّر تحميل الأحداث.' : 'The events could not be loaded.'} />
  }

  const data = q.data
  const events = data?.events ?? []
  const purpose = data?.declared_purpose
  // The campaign declares a conversion purpose but that event never fired in this window.
  const purposeMissing = Boolean(purpose) && !events.some((e) => e.key === purpose)

  return (
    <div className="space-y-3">
      <div className="grid gap-3 sm:grid-cols-3">
        <Field label="غرض التحويل المعلن" value={purpose || notSet} />
        <Field label="نموذج الإسناد" value={data?.attribution_model || notSet} />
        <Field label="نافذة الإسناد" value={data?.attribution_window || notSet} />
      </div>

      {purposeMissing && (
        <div className="flex items-start gap-2 rounded-xl border border-warning/40 bg-warning/10 p-3 text-sm">
          <AlertCircle size={16} className="mt-0.5 shrink-0 text-warning" />
          <p className="text-text-secondary">
            الحملة تعلن غرض تحويل <strong className="text-text-primary">{purpose}</strong> لكن هذا الحدث لم يُسجَّل ولا مرة في الفترة المحددة — تحقق من إعداد التتبع.
          </p>
        </div>
      )}

      {events.length === 0 ? (
        <EmptyState
          title="لم يُسجَّل أي حدث تحويل في هذه الفترة"
          description="لا تُعرض أحداث بقيمة صفر. إذا كنت تتوقع أحداثًا، تحقق من ربط المنصة وإعداد التتبع."
        />
      ) : (
        <div className="overflow-hidden rounded-2xl border border-border bg-surface">
          <table data-testid="events-table" className="w-full text-sm">
            <thead>
              <tr className="border-b border-border text-text-muted">
                <th className="p-3 text-start">الحدث</th>
                <th className="p-3 text-end">العدد</th>
                <th className="p-3 text-end">التكلفة لكل حدث</th>
              </tr>
            </thead>
            <tbody>
              {events.map((e) => {
                const Icon = e.key === purpose ? EVENT_ICON.high : Target
                return (
                  <tr key={e.key} className="border-b border-border last:border-0">
                    <td className="p-3">
                      <span className="flex items-center gap-2">
                        <Icon size={14} className={e.key === purpose ? 'text-success' : 'text-text-muted'} />
                        <span className="font-semibold text-text-primary">{e.label_ar}</span>
                        {e.key === purpose && <Badge tone="success">الغرض المعلن</Badge>}
                      </span>
                    </td>
                    <td className="tnum p-3 text-end font-semibold text-text-primary">{num(e.count)}</td>
                    <td className="tnum p-3 text-end text-text-secondary">{e.cost_per !== null ? money(e.cost_per, cur) : '—'}</td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}

const SYNC_TONE: Record<string, { tone: 'success' | 'danger' | 'warning' | 'neutral'; ar: string; Icon: typeof CheckCircle2 }> = {
  success: { tone: 'success', ar: 'ناجحة', Icon: CheckCircle2 },
  partial: { tone: 'warning', ar: 'جزئية', Icon: AlertCircle },
  failed: { tone: 'danger', ar: 'فاشلة', Icon: XCircle },
  running: { tone: 'neutral', ar: 'قيد التنفيذ', Icon: Clock },
  pending: { tone: 'neutral', ar: 'بالانتظار', Icon: Clock },
}

/** The real sync history for the accounts feeding this campaign — failures included, not hidden. */
export function CampaignSyncLogTab({ campaign, projectId }: { campaign: UnifiedCampaign; projectId: string }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const q = useCampaignSyncLog(projectId, campaign.id)

  if (q.isLoading) return <Skeleton className="h-48" />
  if (q.isError) {
    return <QueryFailure error={q.error} ar={ar} testId="campaign-sync-log-failure" onRetry={() => void q.refetch()}
      fallbackTitle={ar ? 'تعذّر تحميل سجل المزامنة.' : 'The sync log could not be loaded.'} />
  }

  const { linked_accounts: accounts = 0, runs = [] } = q.data ?? {}

  if (accounts === 0) {
    return (
      <EmptyState
        title="لا يوجد سجل مزامنة"
        description="هذه الحملة غير مرتبطة بأي حساب إعلاني، لذلك لا توجد عمليات مزامنة تخصها. اربطها من تبويب «المنصات»."
      />
    )
  }

  return (
    <div className="space-y-3">
      <p className="text-sm text-text-secondary">
        <span className="tnum font-semibold text-text-primary">{accounts}</span> حساب إعلاني يغذّي هذه الحملة —
        آخر <span className="tnum font-semibold text-text-primary">{runs.length}</span> عملية مزامنة:
      </p>

      {runs.length === 0 ? (
        <EmptyState title="لم تُنفَّذ أي مزامنة بعد" description="الحساب مرتبط لكن لم تُسجَّل عملية مزامنة واحدة حتى الآن." />
      ) : (
        <ul data-testid="sync-log" className="space-y-2">
          {runs.map((r) => {
            const meta = SYNC_TONE[r.status] ?? { tone: 'neutral' as const, ar: r.status, Icon: Clock }
            const Icon = meta.Icon
            return (
              <li key={r.id} className="rounded-xl border border-border bg-surface p-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <span className="flex items-center gap-2">
                    <Icon size={15} className={meta.tone === 'danger' ? 'text-danger' : meta.tone === 'success' ? 'text-success' : meta.tone === 'warning' ? 'text-warning' : 'text-text-muted'} />
                    <span className="font-semibold text-text-primary">{r.provider}</span>
                    <Badge tone={meta.tone}>{meta.ar}</Badge>
                  </span>
                  <span className="tnum text-xs text-text-muted">
                    {r.window_start} → {r.window_end}
                  </span>
                </div>
                <div className="mt-1.5 flex flex-wrap gap-x-4 gap-y-1 text-xs text-text-muted">
                  <span>بدأت: <span className="tnum">{r.started_at ? fmtDateTime(r.started_at) : '—'}</span></span>
                  <span>انتهت: <span className="tnum">{r.finished_at ? fmtDateTime(r.finished_at) : '—'}</span></span>
                  <span>قياسات محدَّثة: <span className="tnum">{num(r.metrics_upserted)}</span></span>
                  {r.attempts > 1 && <span>محاولات: <span className="tnum">{r.attempts}</span></span>}
                </div>
                {r.error && (
                  <p className="mt-2 rounded-lg bg-danger/10 p-2 text-xs text-danger" dir="ltr">{r.error}</p>
                )}
              </li>
            )
          })}
        </ul>
      )}
    </div>
  )
}

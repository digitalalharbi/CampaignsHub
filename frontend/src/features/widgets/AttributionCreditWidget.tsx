import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { getData } from '@/lib/api/client'
import { Num } from '@/components/ui/Num'
import { EmptyState, Skeleton } from '@/components/ui/States'
import { ChartCard, PlatformDonutChart, RankingBarChart } from '@/features/analytics/charts'
import { useUi } from '@/stores/ui'
import { AttributionSettingsPanel } from './AttributionSettingsPanel'
import { DataState } from './DataState'

interface CreditChannel {
  channel: string
  kind: 'platform' | 'influencer' | 'other' | 'unattributed'
  models: Record<string, { orders: number; revenue: number }>
}

export interface CreditModels {
  version: string
  evidence_basis: string
  models: string[]
  lookback_days: number
  coverage: {
    orders_credited: number
    orders_with_verified_touchpoints: number
    orders_without_evidence: number
    orders_excluded_fully_refunded: number
    orders_with_withheld_revenue: number
    share_with_verified_touchpoints: number | null
  }
  channels: CreditChannel[]
}

interface AttributionPayload {
  model_comparison?: { credit_models?: CreditModels; currency?: string | null }
}

const MODEL_LABEL: Record<string, { en: string; ar: string }> = {
  first_click: { en: 'First click', ar: 'أول نقرة' },
  last_click: { en: 'Last click', ar: 'آخر نقرة' },
  last_paid_click: { en: 'Last paid click', ar: 'آخر نقرة مدفوعة' },
  linear: { en: 'Linear', ar: 'خطي' },
  position_based: { en: 'Position-based', ar: 'حسب الموضع' },
  time_decay: { en: 'Time decay', ar: 'تناقص زمني' },
  custom_weighted: { en: 'Custom weights', ar: 'أوزان مخصصة' },
}

const CHANNEL_LABEL: Record<string, { en: string; ar: string }> = {
  smart_link: { en: 'Smart links', ar: 'الروابط الذكية' },
  influencer: { en: 'Influencers', ar: 'المؤثرون' },
  unattributed: { en: 'Unattributed', ar: 'غير منسوب' },
}

/**
 * WIDGET-REGISTRY-001 — which channels the orders are credited to, under the models the project chose.
 *
 * Every number here is a share of an order that a recorded, verified touchpoint led to. Orders with no such evidence are
 * drawn as their own unattributed bar and slice, never folded into a channel and never hidden, so a reader sees how
 * much of the revenue the evidence does not explain. Fully refunded orders are not shared out; orders whose revenue is
 * withheld are counted and stated. Until a project chooses its models the widget shows the choice, not a chart.
 */
export function AttributionCreditWidget({ projectId, range }: { projectId: string; range: { from: string; to: string } }) {
  const ar = useUi((u) => u.locale) === 'ar'
  const [model, setModel] = useState<string | null>(null)
  const q = useQuery({
    queryKey: ['attribution-credit', projectId, range.from, range.to],
    queryFn: () => getData<AttributionPayload>(`/projects/${projectId}/metrics/attribution?from=${range.from}&to=${range.to}`),
    retry: false,
  })

  const title = ar ? 'الإسناد حسب القناة' : 'Attribution by channel'

  if (q.isLoading) return <ChartCard title={title}><Skeleton className="h-48" /></ChartCard>
  if (q.isError || !q.data) return <ChartCard title={title}><EmptyState title={ar ? 'تعذّر تحميل الإسناد' : 'Attribution could not be loaded'} /></ChartCard>

  const credit = q.data.model_comparison?.credit_models
  if (!credit) {
    return (
      <ChartCard title={title} subtitle={ar ? 'اختر النماذج لتبدأ' : 'Choose the models to begin'} action={<DataState kind="missing" testId="attribution-credit-state" />}>
        <AttributionSettingsPanel projectId={projectId} />
      </ChartCard>
    )
  }

  const active = model !== null && credit.models.includes(model) ? model : (credit.models[0] ?? 'linear')
  const labelOf = (c: string) => CHANNEL_LABEL[c]?.[ar ? 'ar' : 'en'] ?? c
  const rows = credit.channels.map((c) => ({ label: labelOf(c.channel), orders: Math.round((c.models[active]?.orders ?? 0) * 100) / 100, kind: c.kind })).filter((r) => r.orders > 0)
  const cov = credit.coverage
  const explained = cov.orders_with_verified_touchpoints
  const unexplained = cov.orders_without_evidence
  const hasEvidence = explained > 0

  return (
    <ChartCard
      title={title}
      subtitle={ar ? `طلبات مرتبطة بدليل مُتحقَّق منه فقط — نافذة ${credit.lookback_days} يومًا` : `Only orders tied to verified evidence — ${credit.lookback_days}-day window`}
      action={<DataState kind={hasEvidence ? 'measured' : 'unattributed'} testId="attribution-credit-state" />}
    >
      <div className="space-y-4" data-testid="attribution-credit">
        <div role="group" aria-label={ar ? 'نموذج الإسناد' : 'Attribution model'} className="flex flex-wrap gap-1">
          {credit.models.map((m) => (
            <button
              key={m}
              type="button"
              data-testid={`attribution-model-${m}`}
              aria-pressed={active === m}
              onClick={() => setModel(m)}
              className={`rounded-lg border px-2.5 py-1 text-xs font-bold ${active === m ? 'border-brand-600 bg-brand-600 text-white' : 'border-border text-text-secondary'}`}
            >
              {MODEL_LABEL[m]?.[ar ? 'ar' : 'en'] ?? m}
            </button>
          ))}
        </div>

        <div className="grid gap-4 lg:grid-cols-[2fr_1fr]">
          <div data-testid="attribution-credit-bars">
            {rows.length === 0
              ? <EmptyState title={ar ? 'لا طلبات في هذه الفترة' : 'No orders in this period'} />
              : <RankingBarChart height={220} horizontal data={rows} bars={[{ key: 'orders', name: ar ? 'طلبات' : 'Orders' }]} />}
          </div>
          <div data-testid="attribution-credit-coverage">
            <PlatformDonutChart
              height={220}
              colorBy="series"
              centerValue={String(explained + unexplained)}
              centerLabel={ar ? 'طلب' : 'orders'}
              data={[
                { key: 'verified', name: ar ? 'بدليل مُتحقَّق' : 'Verified evidence', value: explained },
                { key: 'none', name: ar ? 'بلا دليل' : 'No evidence', value: unexplained },
              ].filter((d) => d.value > 0)}
            />
          </div>
        </div>

        <ul className="space-y-1 text-xs text-text-muted" data-testid="attribution-credit-notes">
          {cov.orders_excluded_fully_refunded > 0 && (
            <li>{ar ? `${cov.orders_excluded_fully_refunded} طلبًا مُستردًّا بالكامل لم يُوزَّع.` : `${cov.orders_excluded_fully_refunded} fully refunded order(s) are not shared out.`}</li>
          )}
          {cov.orders_with_withheld_revenue > 0 && (
            <li>{ar ? `إيراد ${cov.orders_with_withheld_revenue} طلبًا محجوب لغياب سعر صرف موثوق.` : `Revenue of ${cov.orders_with_withheld_revenue} order(s) is withheld — no trustworthy exchange rate.`}</li>
          )}
          <li>{ar ? 'النقرة ليست عملية شراء: الدليل رابط مُتتبَّع ثم طلب يحمل رمزه.' : 'A click is not a purchase: the evidence is a tracked link, then an order that carries its token.'} <Num>{`v${credit.version}`}</Num></li>
        </ul>
      </div>
    </ChartCard>
  )
}

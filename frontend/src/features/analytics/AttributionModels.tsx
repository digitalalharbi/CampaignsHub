import { moneyExact, num, percent } from './format'
import type { ModelChannel, ModelComparison as ModelComparisonData, ModelCredit } from './api'
import { MetricTable, type SortValues } from '@/components/ui/MetricTable'
import { providerLabel } from '@/features/campaigns/labels'

/**
 * ATTRIBUTION-MODELS-001 — first touch, last touch, assists and conversion paths, side by side with
 * what each platform claimed and what GA4 measured.
 *
 * Every model gives a whole order to one channel. Nothing is split and nothing unattributed is handed
 * out, so each model's column adds up to the same window orders, and the claim and GA4 columns are
 * the other layers set beside them, never added to them. The basis sentence says what a touch is,
 * because a store sees orders, not the visits between them.
 */
export function AttributionModels({ data, ar }: { data: ModelComparisonData | undefined; ar: boolean }) {
  if (!data) return null

  const locale = ar ? 'ar' : 'en'
  const t = {
    title: ar ? 'مقارنة نماذج الإسناد' : 'Attribution model comparison',
    unavailable: ar ? 'لا يوجد متجر مربوط تُقرأ منه رحلة العميل' : 'No connected store to read customer journeys from',
    channel: ar ? 'القناة' : 'Channel',
    last: ar ? 'آخر لمسة' : 'Last touch',
    first: ar ? 'أول لمسة' : 'First touch',
    assisted: ar ? 'ساعدت في' : 'Assisted',
    claimed: ar ? 'زعم المنصة' : 'Platform claim',
    ga4: ar ? 'مشتريات GA4' : 'GA4 purchases',
    evidence: ar ? 'دليل آخر لمسة' : 'Last-touch evidence',
    orders: ar ? 'طلب' : 'orders',
    paths: ar ? 'مسارات التحويل' : 'Conversion paths',
    multi: ar ? 'طلبات سبقتها قناة أخرى' : 'Orders with an earlier channel',
    coverage: ar ? 'طلبات مرتبطة بعميل معروف' : 'Orders tied to a known customer',
    withheld: ar ? 'طلبات بلا سعر صرف موثوق، إيرادها غير محسوب' : 'Orders with no trustworthy rate, revenue not counted',
    viewThrough: ar ? 'يشمل المشاهدة' : 'includes view-through',
    clickOnly: ar ? 'نقر فقط' : 'click only',
  }

  const channelName = (c: string): string =>
    c === 'influencer' ? (ar ? 'مؤثرون (أكواد خصم)' : 'Creators (discount codes)')
      : c === 'unattributed' ? (ar ? 'غير مُسند' : 'Unattributed')
        : providerLabel(c, locale)

  const credit = (m: ModelCredit) => (
    <span className="inline-flex flex-col items-end">
      <span className="font-semibold text-text-primary">{num(m.orders)}</span>
      {m.revenue !== null && m.orders > 0 && <span className="text-[11px] text-text-muted">{moneyExact(m.revenue, data.currency ?? undefined)}</span>}
    </span>
  )

  const claim = (c: ModelChannel) => {
    if (c.platform_claimed_orders === null) return <span className="text-text-muted">—</span>
    const basis = c.claim_includes_view_through === null ? null : c.claim_includes_view_through ? t.viewThrough : t.clickOnly
    return (
      <span className="inline-flex flex-col items-end">
        <span className="text-text-primary">{num(c.platform_claimed_orders)}</span>
        {basis && <span data-testid={`model-claim-basis-${c.channel}`} className="text-[11px] text-text-muted">{basis}</span>}
      </span>
    )
  }

  const evidence = (c: ModelChannel): string => {
    const parts: string[] = []
    if (c.evidence.utm > 0) parts.push(`UTM ${num(c.evidence.utm)}`)
    if (c.evidence.click_id > 0) parts.push(`${ar ? 'معرّف نقر' : 'click id'} ${num(c.evidence.click_id)}`)
    if (c.evidence.coupon > 0) parts.push(`${ar ? 'كوبون' : 'coupon'} ${num(c.evidence.coupon)}`)
    return parts.length > 0 ? parts.join(' · ') : '—'
  }

  const head = [t.channel, t.last, t.first, t.assisted, t.claimed, t.ga4, t.evidence]
  const rows = data.channels.map((c) => [
    <span key="ch" data-testid={`model-row-${c.channel}`} className="font-semibold text-text-primary">{channelName(c.channel)}</span>,
    <span key="last">{credit(c.last_touch)}</span>,
    <span key="first">{credit(c.first_touch)}</span>,
    <span key="assisted">{credit(c.assisted)}</span>,
    <span key="claim">{claim(c)}</span>,
    <span key="ga4" className="text-text-secondary">{c.ga4_purchases === null ? '—' : num(c.ga4_purchases)}</span>,
    <span key="ev" className="text-text-secondary">{evidence(c)}</span>,
  ])
  const values: SortValues[] = data.channels.map((c) => [
    channelName(c.channel), c.last_touch.orders, c.first_touch.orders, c.assisted.orders,
    c.platform_claimed_orders, c.ga4_purchases, c.evidence.utm + c.evidence.click_id + c.evidence.coupon,
  ])

  return (
    <section data-testid="attribution-models" className="min-w-0 border-t border-border pt-4">
      <h4 className="text-xs font-bold uppercase tracking-wide text-text-muted">{t.title}</h4>
      <p className="mt-1 text-xs text-text-secondary">{ar ? data.basis_ar : data.basis_en}</p>

      {!data.available ? (
        <p data-testid="attribution-models-unavailable" className="mt-2 text-sm font-semibold text-text-primary">{t.unavailable}</p>
      ) : (
        <>
          {data.coverage && (
            <p data-testid="attribution-models-coverage" className="mt-2 text-xs text-text-secondary">
              {t.coverage}: <span className="tnum font-semibold text-text-primary" dir="ltr">{num(data.coverage.orders_with_customer)} / {num(data.coverage.orders)}</span>
              {data.coverage.share_with_customer !== null && <> (<span className="tnum" dir="ltr">{percent(data.coverage.share_with_customer)}</span>)</>}
              {data.coverage.revenue_withheld_orders > 0 && (
                <> · {t.withheld}: <span className="tnum" dir="ltr">{num(data.coverage.revenue_withheld_orders)}</span></>
              )}
            </p>
          )}
          <div className="mt-2 min-w-0">
            <MetricTable head={head} rows={rows} values={values} initialSort={{ column: 1, dir: 'desc' }} />
          </div>

          {data.paths && data.paths.rows.length > 0 && (
            <div data-testid="attribution-paths" className="mt-3 min-w-0">
              <div className="flex flex-wrap items-baseline justify-between gap-2">
                <h5 className="text-xs font-semibold text-text-primary">{t.paths}</h5>
                <span className="text-[11px] text-text-muted">
                  {t.multi}: <span className="tnum" dir="ltr">{num(data.paths.multi_touch_orders)}</span>
                  {data.paths.distinct > data.paths.rows.length && (
                    <> · {ar ? `أكثر ${num(data.paths.rows.length)} من ${num(data.paths.distinct)}` : `top ${num(data.paths.rows.length)} of ${num(data.paths.distinct)}`}</>
                  )}
                </span>
              </div>
              <ol className="mt-1.5 grid grid-cols-1 gap-1.5">
                {data.paths.rows.map((p) => (
                  <li key={(p.truncated ? '…' : '') + p.steps.join('>')} className="flex min-w-0 flex-wrap items-center justify-between gap-x-3 gap-y-1 rounded-lg border border-border bg-surface-secondary px-2.5 py-1.5 text-xs">
                    <span data-testid="attribution-path" className="min-w-0 break-words text-text-primary">
                      {p.truncated && <span className="text-text-muted">… ← </span>}
                      {p.steps.map(channelName).join(ar ? ' ← ' : ' → ')}
                    </span>
                    <span className="tnum text-text-secondary" dir="ltr">
                      {num(p.orders)} {t.orders}
                      {p.revenue !== null && <> · {moneyExact(p.revenue, data.currency ?? undefined)}</>}
                    </span>
                  </li>
                ))}
              </ol>
            </div>
          )}
        </>
      )}
    </section>
  )
}

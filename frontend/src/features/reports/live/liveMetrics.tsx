import { useMemo } from 'react'
import { readMetricValue, type MetricValue } from '@/lib/metricValue'
import { money, moneyExact, moneyFromTotals, ratio, wholeMoney } from '@/features/analytics/format'
import { formatMoneyReading, readCostPer, readRoas, type MoneyTotals } from '@/lib/money/contract'
import type { LivePayload } from '../api'
import { clientKpiKeys } from '../clientKpis'

/**
 * How every figure on a live client link is read — one definition, shared by every mode.
 *
 * Moved out of `LiveSharedReport` when the page became four modes: a summary card and a dashboard
 * card stating «spend» two different ways would be the MONEY-SCOPE-TRUTH-001 defect rebuilt on a new
 * surface. Each metric carries its own formatting, so «spend» is always money through the contract and
 * «ROAS» always a multiplier, wherever it appears.
 */
export type MetricMeta = {
  ar: string
  en: string
  invertGood?: boolean
  format: (
    t: LivePayload['totals'],
    p: LivePayload,
    money: (v: number | null | undefined) => string,
    count: (v: number | null | undefined) => MetricValue,
  ) => MetricValue
}

export function useLiveMetricReader(currency: string, ar: boolean) {
  /*
   * `asMoney`, not `money`: this closure already carries the payload's currency, and a second thing
   * called `money` in a file that also formats money is how a missing currency hides in plain sight.
   *
   * ## Why it delegates rather than formatting its own way — MONEY-SCOPE-TRUTH-001
   *
   * It used `Intl` with `style: 'currency'`, which printed «$7,420» — while every table on the SAME
   * page, drawn by `MetricTable` through the money contract, printed «7.75K USD». One document, two
   * notations for one currency, observed on the owner's live link. A reader comparing the strip to
   * the table below it has to decide whether they are looking at the same units, and that is a
   * question a report must never make them ask.
   *
   * Two further things the symbol form got wrong and this does not: a currency the scope cannot
   * state comes back bare rather than under a guessed symbol, and `$` is the symbol of a dozen
   * currencies — «USD» spelled out is the one form that cannot be misread.
   */
  const asMoney = useMemo(() => (v: number | null | undefined) => money(v, currency || null), [currency])
  /*
   * A cost-per is stated EXACTLY — §58, and found by reading the page rather than the code.
   *
   * Every cost-per here went through `asMoney`, which compacts: a CPC of about one and a half dollars
   * printed «2 USD» on a client's link. On a total the fraction is noise; on a cost-per it IS the
   * figure, and «2» instead of «1.50» is a different decision about the same campaign.
   */
  /*
   * The figure behind an abbreviation, or undefined when the abbreviation IS the figure.
   *
   * `title` takes undefined rather than null, and a tooltip repeating the cell teaches a reader to
   * stop opening them — so it is only set where compacting actually hid something.
   */
  const revealed = useMemo(() => (v: number | null | undefined): string | undefined => {
    if (v === null || v === undefined) return undefined
    const shown = money(v, currency || null)
    const whole = wholeMoney(v, currency || undefined)

    return shown === whole ? undefined : whole
  }, [currency])

  const asExactMoney = useMemo(() => (v: number | null | undefined) => moneyExact(v, currency || null), [currency])
  /*
   * A count, through the product's one value law — §58.
   *
   * This formatted every count in full: the owner's screenshot shows «29,210», «4,127,676» and
   * «1,723,184» beside «5.54K USD» in one row of six cards. Three notations for one idea, on the page
   * a client reads, and the client has to decide for themselves whether four million one hundred
   * twenty-seven thousand is a lot. A figure read for SCALE is read faster compact, and the exact one
   * stays a hover away rather than being lost.
   */
  const count = (v: number | null | undefined) => readMetricValue('number', v)

  /** A reading that is already final — money through the contract, a percentage, a ratio. */
  const plain = (text: string): MetricValue => ({ text, exact: null, value: null })

  /*
   * A money card reveals its exact amount, exactly as the counts beside it already do.
   *
   * NUMBER-PRESENTATION-001. Measured on production: «الظهور 7.08M» revealed 7,077,158 and «النقرات
   * 42.7K» revealed 42,738, while «الإنفاق 10.8K USD» revealed nothing — the one card on the block
   * whose figure is the reader's own money. `plain()` drops whatever the reading knew, and the money
   * reading has carried its own exact figure since the detail table was fixed for the same defect.
   */
  const asReading = (r: { text: string; exact: string | null }): MetricValue =>
    ({ text: r.text, exact: r.exact, value: null })

  /*
   * How each chosen metric is rendered.
   *
   * A table rather than a chain of conditionals, because the SET is chosen by the operator at link
   * time and the page must be able to render any subset in the order they picked. Formatting lives
   * with the metric so «spend» is always money and «ROAS» is always a multiplier, wherever it appears.
   */
  const METRIC_META: Record<string, MetricMeta> = {
    // PARTIAL-WITHHELD-001 — a client link is the one place the reader has no other view, so money
    // goes through the contract: partial/mixed ⇒ «—», withheld ⇒ the original in its own currency,
    // never the coalesced 0 or the converted subset.
    spend: { ar: 'الإنفاق', en: 'Spend', invertGood: true, format: (t, p) => asReading(moneyFromTotals(t as MoneyTotals, 'spend', ar, p.currency)) },
    impressions: { ar: 'الظهور', en: 'Impressions', format: (t, _p, _m, count) => count(t.impressions) },
    clicks: { ar: 'النقرات', en: 'Clicks', format: (t, _p, _m, count) => count(t.clicks) },
    ctr: { ar: 'نسبة النقر', en: 'CTR', format: (t) => plain(t.ctr === null || t.ctr === undefined ? '—' : `${(t.ctr * 100).toFixed(2)}%`) },
    conversions: { ar: 'النتائج', en: 'Results', format: (t, _p, _m, count) => count(t.conversions) },
    // Add-to-cart is a funnel stage rather than a total, so it is read from where it actually lives.
    add_to_cart: { ar: 'الإضافات للسلة', en: 'Add to cart', format: (_t, p, _m, count) => count(p.funnel.find((f) => f.stage === 'add_to_cart')?.count) },
    purchases: { ar: 'المشتريات', en: 'Purchases', format: (t, _p, _m, count) => count(t.purchases) },
    revenue: { ar: 'الإيرادات', en: 'Revenue', format: (t, p) => asReading(moneyFromTotals(t as MoneyTotals, 'revenue', ar, p.currency)) },
    roas: { ar: 'العائد على الإنفاق', en: 'ROAS', format: (t) => { const r = readRoas(t as MoneyTotals, ar); return plain(ratio(r.value)) } },
    cpa: { ar: 'تكلفة النتيجة', en: 'Cost per result', invertGood: true, format: (t, p) => plain(formatMoneyReading(readCostPer(t as MoneyTotals, 'cpa', 'conversions', p.currency, ar), (v) => asExactMoney(v))) },
    /*
     * The rest of what a marketing path is judged on — ANALYTICS-OBJECTIVE-SYSTEM-001.
     *
     * `objective_performance` names these per path (`headline_metrics`), and the client block could
     * render none of them: an awareness report is judged on reach, frequency and CPM, and the block
     * offered impressions and add-to-cart. Every cost-per goes through the money contract, exactly as
     * `cpa` does — a rate whose numerator is partly withheld is «—», never the converted subset.
     */
    reach: { ar: 'الوصول', en: 'Reach', format: (t, _p, _m, count) => count(t.reach) },
    frequency: { ar: 'التكرار', en: 'Frequency', format: (t) => plain(t.frequency === null || t.frequency === undefined ? '—' : ratio(t.frequency)) },
    cpm: { ar: 'تكلفة الألف ظهور', en: 'CPM', invertGood: true, format: (t, p) => plain(formatMoneyReading(readCostPer(t as MoneyTotals, 'cpm', (Number(t.impressions ?? 0)) / 1000, p.currency, ar), (v) => asExactMoney(v))) },
    cpc: { ar: 'تكلفة النقرة', en: 'CPC', invertGood: true, format: (t, p) => plain(formatMoneyReading(readCostPer(t as MoneyTotals, 'cpc', 'clicks', p.currency, ar), (v) => asExactMoney(v))) },
    leads: { ar: 'العملاء المحتملون', en: 'Leads', format: (t, _p, _m, count) => count(t.leads) },
    cpl: { ar: 'تكلفة العميل المحتمل', en: 'CPL', invertGood: true, format: (t, p) => plain(formatMoneyReading(readCostPer(t as MoneyTotals, 'cpl', 'leads', p.currency, ar), (v) => asExactMoney(v))) },
    installs: { ar: 'التثبيتات', en: 'Installs', format: (t, _p, _m, count) => count(t.installs) },
    cpi: { ar: 'تكلفة التثبيت', en: 'CPI', invertGood: true, format: (t, p) => plain(formatMoneyReading(readCostPer(t as MoneyTotals, 'cpi', 'installs', p.currency, ar), (v) => asExactMoney(v))) },
    engagements: { ar: 'التفاعلات', en: 'Engagements', format: (t, _p, _m, count) => count(t.engagements) },
    cpe: { ar: 'تكلفة التفاعل', en: 'CPE', invertGood: true, format: (t, p) => plain(formatMoneyReading(readCostPer(t as MoneyTotals, 'cpe', 'engagements', p.currency, ar), (v) => asExactMoney(v))) },
    landing_page_views: { ar: 'زيارات الصفحة', en: 'Landing page views', format: (t, _p, _m, count) => count(t.landing_page_views) },
    cost_per_lpv: { ar: 'تكلفة الزيارة', en: 'Cost per visit', invertGood: true, format: (t, p) => plain(formatMoneyReading(readCostPer(t as MoneyTotals, 'cost_per_lpv', 'landing_page_views', p.currency, ar), (v) => asExactMoney(v))) },
    conversion_rate: { ar: 'معدل التحويل', en: 'Conversion rate', format: (t) => plain(t.conversion_rate === null || t.conversion_rate === undefined ? '—' : `${(t.conversion_rate * 100).toFixed(2)}%`) },
    /*
     * Average order value is deliberately NOT here yet.
     *
     * Its numerator is REVENUE, and every money reading on this block runs through `readCostPer`,
     * which is built around SPEND: a withheld amount falls back to spend ÷ denominator, so an AOV
     * routed through it would state the average order as a figure derived from what we PAID. The card
     * needs a revenue-numerator reading of its own, and inventing one here — on the page a client
     * cannot cross-check — is the fabrication the money contract exists to prevent.
     */
  }


  return useMemo(() => ({
    meta: METRIC_META,
    asMoney,
    asExactMoney,
    revealed,
    count,
    /* Which cards this report shows — decided by what it is ABOUT, not by a fixed list (`clientKpis.ts`). */
    keys: (payload: LivePayload | null | undefined) => clientKpiKeys(
      { metrics: payload?.metrics, objective_performance: payload?.objective_performance, totals: payload?.totals },
      new Set(Object.keys(METRIC_META)),
    ),
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }), [currency, ar])
}

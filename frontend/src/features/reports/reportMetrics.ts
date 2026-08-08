import type { MetricReading } from '@/components/ui/MetricStrip'
import { SPECS, layoutFor, readMetric } from '@/features/analytics/metricCatalog'
import type { ObjectivePerformance } from './InteractiveReport'

/**
 * The KPI row of a report slide, chosen by what the report's money was buying — §14.6.
 *
 * ## What this replaced
 *
 * Six hard-coded cards: spend, revenue, ROAS, results, CPA, CTR — on every report, whatever it was
 * for. The server has computed an objective-aware `metric_set` since the template engine was
 * written, and nothing on the client had ever read it. So a brand report showed a client «ROAS —»
 * and «CPA —» in the two largest cards on its first page: two dashes where a number should be, in
 * an executive summary, inviting exactly the question the report cannot answer because the money was
 * never spent to answer it.
 *
 * ## Absence is not zero, and there are two kinds of it
 *
 * `reported` says which base metrics any connected platform actually sent; the pivot coalesces to 0,
 * so it is the only way to tell «nobody reports reach» from «nobody was reached». A derived ratio is
 * `no_data` when its denominator was missing. Both render as a state, never as a figure.
 *
 * Older snapshots carry no `reported` map. They fall back to reading the value, which is what they
 * displayed when they were generated — a report already sent to a client must not change its
 * numbers because the renderer improved.
 */

export type ReportMetric = {
  key: string
  label: string
  reading: MetricReading
  delta?: number | null
  invertGood?: boolean
  neutral?: boolean
  hint: string
  /** Which timeseries column draws this card's sparkline, or null when there is nothing to draw. */
  series: string | null
  /**
   * True when the card shows the DIRECT figure in place of the blended one.
   *
   * The comparison table reads this: a Direct value must be compared with the previous period's
   * Direct value, never with the blended total that `previous` happens to hold under the same key.
   */
  substituted: boolean
}

type Input = {
  objective?: string
  kpis: Record<string, number | null>
  delta?: Record<string, number | null>
  reported?: Record<string, boolean>
  objective_performance?: ObjectivePerformance
  /** The same split for the previous window — what a Direct figure is honestly compared against. */
  objective_performance_previous?: ObjectivePerformance
  previous?: Record<string, number | null>
  currency: string
  metric_set?: string[]
}

/**
 * The Direct pair, substituted for the blended one — REPORT-OBJECTIVE-003, carried forward.
 *
 * `kpis.cpa` divides EVERY campaign's spend by the sales campaigns' orders, so on a scope with any
 * non-sales spend it is not the cost of an order. Where the split exists, the Direct figure takes
 * the card and the label says which figure it is; the blended pair keeps its own named section.
 */
const DIRECT_LABEL: Record<string, { ar: string; en: string }> = {
  cpa: { ar: 'CPA (مبيعات مباشرة)', en: 'CPA (direct sales)' },
  roas: { ar: 'ROAS (مبيعات مباشرة)', en: 'ROAS (direct sales)' },
}

/**
 * What the daily trend plots against spend, for this objective.
 *
 * The deck drew «الإنفاق مقابل الإيرادات» on every report, so a brand month plotted a revenue
 * series that was zero on all thirty of its days: a flat line along the axis, which reads as a
 * campaign that earned nothing rather than one that was not selling.
 */
export function trendSeries(objective: string | undefined): { key: string; name: string; title: string; kind: 'money' | 'num' } {
  switch (objective) {
    case 'sales':
      return { key: 'revenue', name: 'الإيرادات', title: 'الإنفاق مقابل الإيرادات', kind: 'money' }
    case 'leads':
    case 'app_installs':
      return { key: 'conversions', name: 'النتائج', title: 'الإنفاق مقابل النتائج', kind: 'num' }
    case 'traffic':
      return { key: 'clicks', name: 'النقرات', title: 'الإنفاق مقابل النقرات', kind: 'num' }
    case 'video':
      return { key: 'video_views', name: 'مشاهدات الفيديو', title: 'الإنفاق مقابل المشاهدات', kind: 'num' }
    case 'awareness':
      return { key: 'impressions', name: 'الظهور', title: 'الإنفاق مقابل الظهور', kind: 'num' }
    // Mixed: clicks happen on every path and mean the same thing on all of them.
    default:
      return { key: 'clicks', name: 'النقرات', title: 'الإنفاق مقابل النقرات', kind: 'num' }
  }
}

export function reportMetrics(data: Input, ar = true): ReportMetric[] {
  const objective = data.objective ?? 'custom'
  /*
   * The snapshot's own `metric_set` wins when it has one.
   *
   * It is what the report was GENERATED with — including an operator's edit to the slide config —
   * and a client link re-rendered a month later must show the report they were sent. `layoutFor` is
   * the fallback for snapshots written before the set was stored.
   */
  const keys = data.metric_set?.length ? data.metric_set : layoutFor(objective, 'all').primary
  const direct = data.objective_performance?.direct

  return keys
    .filter((key) => SPECS[key])
    .map((key) => {
      const spec = SPECS[key]
      const substituted = direct && (key === 'cpa' || key === 'roas')
      const value = substituted ? direct[key as 'cpa' | 'roas'] : data.kpis[key]

      return {
        key,
        label: substituted
          ? (ar ? DIRECT_LABEL[key].ar : DIRECT_LABEL[key].en)
          : (ar ? spec.label.ar : spec.label.en),
        // A substituted figure is a derived ratio of a NARROWER scope than `reported` describes, so
        // it is read straight: `reported` would answer a question about the wrong denominator.
        reading: substituted
          ? (value === null || value === undefined ? { kind: 'no_data' } : { kind: 'value', text: spec.format(value) })
          : readMetric(key, spec, data.kpis, data.reported),
        // The period-over-period change belongs to the blended figure it was computed from. Showing
        // it beside a Direct value would attach one scope's movement to another scope's number.
        delta: substituted ? undefined : (data.delta?.[key] ?? null),
        invertGood: spec.invertGood,
        neutral: spec.neutral,
        hint: ar ? spec.hint.ar : spec.hint.en,
        series: key,
        substituted: Boolean(substituted),
      }
    })
}

/**
 * The previous period's value for a card, read from the SAME scope the current one came from.
 *
 * This is the whole point of the flag. `previous.cpa` is the blended cost per order of the earlier
 * window; setting it beside this period's Direct CPA compares two different sets of campaigns under
 * one heading, and the difference between them is not a change in performance.
 */
export function previousReading(metric: ReportMetric, data: Input): { text: string | null; change: number | null } {
  const spec = SPECS[metric.key]
  const before = metric.substituted
    ? data.objective_performance_previous?.direct?.[metric.key as 'cpa' | 'roas'] ?? null
    : data.previous?.[metric.key] ?? null

  if (metric.reading.kind !== 'value' || before === null || before === undefined) {
    return { text: null, change: null }
  }

  const current = metric.substituted
    ? data.objective_performance?.direct?.[metric.key as 'cpa' | 'roas'] ?? null
    : data.kpis[metric.key]

  return {
    text: spec.format(before),
    // Recomputed for a substituted metric: the snapshot's `delta` belongs to the blended pair.
    change: metric.substituted
      ? (current === null || current === undefined || before === 0 ? null : (current - before) / Math.abs(before))
      : metric.delta ?? null,
  }
}

/**
 * How a piece of content is judged — §14.8.
 *
 * «محتوى الوعي يُقارن بالوصول وCPM والمشاهدة والتفاعل · محتوى الزيارات بـCTR وCPC وLPV · محتوى
 * Leads بعددهم وCPL · محتوى المبيعات بالطلبات وCPA والإيراد وROAS» — the requirement's own list,
 * and the reason the four chips on a creative card cannot be fixed.
 *
 * They were: ROAS, CPA, spend, results, on every report. A brand report therefore ranked its
 * content correctly — `CreativeRankingService` has been objective-aware since it was written — and
 * then labelled every winner with two dashes where its return should be, which reads as a creative
 * that failed rather than one measured on something else entirely.
 */
export function creativeChips(objective: string | undefined): string[] {
  switch (objective) {
    case 'awareness':
      return ['reach', 'cpm', 'impressions', 'engagements']
    case 'video':
      return ['video_views', 'video_completion_rate', 'cpm', 'impressions']
    case 'traffic':
      return ['ctr', 'cpc', 'landing_page_views', 'clicks']
    case 'leads':
    case 'app_installs':
      return ['conversions', 'cpa', 'ctr', 'spend']
    case 'sales':
      return ['roas', 'cpa', 'revenue', 'conversions']
    // Mixed: what every path shares, and no cost-per across objectives.
    default:
      return ['spend', 'clicks', 'ctr', 'impressions']
  }
}

/**
 * One creative's chips as readings — absent stays absent.
 *
 * `reported` narrows to the creative's own platform where the snapshot knows it, for the same
 * reason the platform slide does: a card on an X creative must not print a reach of zero because
 * Meta reports reach.
 */
export function creativeReadings(
  row: Record<string, unknown>,
  objective: string | undefined,
  reported: Record<string, boolean> | undefined,
  ar = true,
): Array<{ key: string; label: string; reading: MetricReading }> {
  return creativeChips(objective)
    .filter((key) => SPECS[key])
    .map((key) => ({
      key,
      label: ar ? SPECS[key].label.ar : SPECS[key].label.en,
      reading: readMetric(key, SPECS[key], row as Record<string, number | null>, reported),
    }))
}

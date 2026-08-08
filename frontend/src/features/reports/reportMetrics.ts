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
}

type Input = {
  objective?: string
  kpis: Record<string, number | null>
  delta?: Record<string, number | null>
  reported?: Record<string, boolean>
  objective_performance?: ObjectivePerformance
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
      }
    })
}

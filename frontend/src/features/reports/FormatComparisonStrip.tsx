import { Num } from '@/components/ui/Num'
import { kindName, metricText, RESULT_WORDS } from '@/features/content/ContentTypesComparison'
import type { FormatComparisonObjective, FormatComparisonPayload } from '@/features/content/api'
import { LOWER_IS_BETTER, present, stripFormats, stripMetrics, type StripMetric } from './formatStrip'

export { stripFormats, stripHasFigures, stripMetrics } from './formatStrip'

/**
 * REPORT-FORMAT-COMPARE-COMPACT-001 (Owner 2026-11-13: «أحتاجها صغيرة، متوازية، واضحة، بلا حشو مربعات كبيرة») — the report's
 * «مقارنة أنواع المحتوى»: ONE block per report, the formats side by side as small equal cards.
 *
 * The server already kept only the families of the report's own objective(s) (`ContentFormatComparison::forReportObjectives`),
 * so a sales report compares formats on sales. Here a figure is drawn only where it exists: a metric no format answered (null,
 * or zero everywhere) has no line on any card, a card with nothing to say is not drawn, and a missing cell is absent — never
 * «—», never a zero. Each line carries a thin bar against the best format on that metric, so the comparison reads at a glance.
 */
const WORDS: Record<Exclude<StripMetric, 'results'>, { ar: string; en: string }> = {
  cost_per_result: { ar: 'تكلفة النتيجة', en: 'Cost/result' },
  ctr: { ar: 'نسبة النقر', en: 'CTR' },
  conversion_rate: { ar: 'التحويل', en: 'CVR' },
  roas: { ar: 'العائد', en: 'ROAS' },
}

function ObjectiveStrip({ objective, ar, hidesMoney, currency, labelled }: { objective: FormatComparisonObjective; ar: boolean; hidesMoney: boolean; currency: string | null; labelled: boolean }) {
  const metrics = stripMetrics(objective, hidesMoney)
  const formats = stripFormats(objective, metrics)
  if (metrics.length === 0 || formats.length === 0) return null
  const resultWord = RESULT_WORDS[objective.result_metric ?? ''] ?? { ar: 'النتائج', en: 'Results' }
  const word = (m: StripMetric) => (m === 'results' ? (ar ? resultWord.ar : resultWord.en) : (ar ? WORDS[m].ar : WORDS[m].en))
  const best: Partial<Record<StripMetric, number>> = {}
  const top: Partial<Record<StripMetric, number>> = {}
  for (const m of metrics) {
    const values = formats.map((f) => f[m]).filter(present)
    top[m] = Math.max(...values)
    if (values.length > 1) best[m] = LOWER_IS_BETTER.includes(m) ? Math.min(...values) : Math.max(...values)
  }
  const winner = objective.winner?.format ?? null

  return (
    <div data-testid={`format-strip-objective-${objective.family}`} className="min-w-0">
      {labelled && <p className="mb-1.5 text-xs font-semibold text-text-secondary">{ar ? objective.label.ar : objective.label.en}</p>}
      <ul className="grid gap-2" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(8.25rem, 1fr))' }}>
        {formats.map((f) => (
          <li
            key={f.format}
            data-testid={`format-strip-card-${f.format}`}
            className={`min-w-0 rounded-xl border px-2.5 py-2 ${f.format === winner ? 'border-success/60 bg-success/5' : 'border-border bg-surface'}`}
          >
            <div className="mb-1 flex min-w-0 items-baseline justify-between gap-1">
              <span className="truncate text-sm font-bold text-text-primary">{kindName(f.format, ar)}</span>
              <span className="shrink-0 text-xs text-text-muted"><Num>{`${f.creatives}`}</Num></span>
            </div>
            {f.format === winner && <span data-testid="format-strip-winner" className="mb-1 inline-block rounded-md bg-success/15 px-1.5 text-xs font-semibold text-success">{ar ? 'الأنسب للهدف' : 'Best fit'}</span>}
            <dl className="flex flex-col gap-1">
              {metrics.filter((m) => present(f[m])).map((m) => {
                const v = f[m] as number
                const width = top[m] ? Math.max(6, Math.round((v / (top[m] as number)) * 100)) : 0
                const leads = best[m] !== undefined && v === best[m]

                return (
                  <div key={m} data-testid={`format-strip-cell-${m}`} className="min-w-0">
                    <div className="flex items-baseline justify-between gap-1">
                      <dt className="truncate text-xs text-text-secondary">{word(m)}</dt>
                      <dd className={`shrink-0 text-xs font-semibold ${leads ? 'text-success' : 'text-text-primary'}`}><Num>{metricText(m, v, currency)}</Num></dd>
                    </div>
                    <div className="mt-0.5 h-1 rounded-full bg-surface-hover" aria-hidden>
                      <div className={`h-1 rounded-full ${leads ? 'bg-success' : 'bg-brand-600/70'}`} style={{ width: `${width}%` }} />
                    </div>
                  </div>
                )
              })}
            </dl>
          </li>
        ))}
      </ul>
    </div>
  )
}

export function FormatComparisonStrip({ data, ar, client, title = true }: { data: FormatComparisonPayload; ar: boolean; client: boolean; title?: boolean }) {
  const hidesMoney = client || data.spend_hidden === true
  // Only the objectives with a figure to draw — and a label only when more than one of THOSE remains.
  const objectives = (Array.isArray(data?.objectives) ? data.objectives : []).filter((o) => {
    const m = stripMetrics(o, hidesMoney)

    return m.length > 0 && stripFormats(o, m).length > 0
  })
  if (objectives.length === 0) return null

  return (
    <section data-testid="report-format-strip" className="flex min-w-0 flex-col gap-2">
      {title && <h3 className="text-sm font-extrabold tracking-tight text-text-primary">{ar ? 'مقارنة أنواع المحتوى' : 'Content types'}</h3>}
      {objectives.map((o) => (
        <ObjectiveStrip key={o.family} objective={o} ar={ar} hidesMoney={hidesMoney} currency={hidesMoney ? null : data.currency} labelled={objectives.length > 1} />
      ))}
    </section>
  )
}

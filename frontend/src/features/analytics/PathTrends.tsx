import { CartesianGrid, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { Panel, SERIES, tooltipProps } from './components'
import { compact } from './format'
import { METRIC_LABEL } from '@/styles/scale'
import type { PathTrend } from './api'
import type { Locale } from '@/stores/ui'

/**
 * OBJECTIVE-ANALYTICS-DEPTH-001 — one chart per path, in the metric that path was buying.
 *
 * ## Why not one chart with a legend
 *
 * A single series over a mixed programme moves for reasons that cancel each other out: awareness
 * spend rising while sales spend falls is one flat line, and the reader watching it concludes the
 * account is doing nothing. Two charts say «brand up, sales down», which is a sentence somebody can
 * act on — and it is the only reading this requirement's hard constraint permits, since the two are
 * not comparable with each other.
 *
 * ## A day nobody reported is a GAP, not a zero
 *
 * The server sends every day in the window and says which ones reported. Recharts draws a line
 * straight through a null unless it is told not to, which turns a pause into a slope — so
 * `connectNulls` is off, deliberately, and the broken line is the honest shape.
 *
 * ## The second line is the cost, and only where the path was buying one
 *
 * An awareness path has no cost per result; drawing an empty axis for it is how a chart teaches its
 * reader that half its numbers are missing.
 */
export function PathTrends({
  paths,
  locale,
  loading,
  error,
}: {
  paths: PathTrend[]
  locale: Locale
  loading?: boolean
  error?: boolean
}) {
  const ar = locale === 'ar'
  const axis = { fill: 'var(--text-muted)', fontSize: 11 }

  return (
    <Panel
      title={ar ? 'اتجاه كل مسار' : 'Each path over time'}
      description={ar
        ? 'كل مسار على حدة — الوعي والمبيعات لا يُجمعان في خط واحد'
        : 'One path at a time — awareness and sales do not belong on one line'}
      loading={loading}
      error={error}
      empty={!loading && paths.length === 0}
    >
      <div data-testid="path-trends" className="flex flex-col gap-4">
        {paths.map((path) => {
          const costly = path.days.some((d) => d.cost_per_result !== null)

          return (
            <div key={path.path} data-testid={`path-trend-${path.path}`} className="rounded-xl border border-border p-3">
              <div className="mb-1 flex flex-wrap items-baseline justify-between gap-2">
                <span className="text-sm font-bold text-text-primary">{ar ? path.label_ar : path.label_en}</span>
                {/*
                  How much of the window this line actually covers. «12 of 30 days reported» is the
                  difference between a trend and four points somebody drew a line between.
                */}
                <span className={`tnum text-text-muted ${METRIC_LABEL}`} dir="ltr">
                  {ar
                    ? `${path.days_reported} من ${path.days_in_window} يومًا فيها بيانات`
                    : `${path.days_reported} of ${path.days_in_window} days reported`}
                </span>
              </div>

              <div className="h-40">
                <ResponsiveContainer width="100%" height="100%">
                  <LineChart data={path.days} margin={{ top: 6, right: 6, left: 6, bottom: 0 }}>
                    <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" vertical={false} />
                    <XAxis dataKey="date" tick={axis} tickFormatter={(v: string) => v.slice(5)} />
                    <YAxis tick={axis} tickFormatter={(v) => compact(Number(v))} width={44} />
                    <Tooltip {...tooltipProps} />
                    {/*
                      `connectNulls` is off on purpose: a day nobody reported is a gap, and a line
                      drawn across it turns a pause into a slope.
                    */}
                    <Line
                      type="monotone"
                      dataKey="spend"
                      name={ar ? 'الإنفاق' : 'Spend'}
                      stroke={SERIES.spend}
                      strokeWidth={2}
                      dot={false}
                      connectNulls={false}
                    />
                    {costly && (
                      <Line
                        type="monotone"
                        dataKey="cost_per_result"
                        name={ar ? 'تكلفة النتيجة' : 'Cost per result'}
                        stroke={SERIES.revenue}
                        strokeWidth={2}
                        dot={false}
                        connectNulls={false}
                      />
                    )}
                  </LineChart>
                </ResponsiveContainer>
              </div>
            </div>
          )
        })}
      </div>
    </Panel>
  )
}

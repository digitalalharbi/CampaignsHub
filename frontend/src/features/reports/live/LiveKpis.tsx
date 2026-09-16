import { KpiCard, TrendPill } from '@/features/analytics/components'
import { Num } from '@/components/ui/Num'
import type { LivePayload } from '../api'
import type { useLiveMetricReader } from './liveMetrics'

type Reader = ReturnType<typeof useLiveMetricReader>

/**
 * Efficiency figures the Owner lists for the KPI header — cost per result, CTR, CPC, CPM — added
 * after the report's own choice only when the operator did NOT choose the set. An operator who picked
 * the metrics picked them; a metric they left out stays absent (LIVEREP-002).
 */
const EFFICIENCY = ['cpa', 'ctr', 'cpc', 'cpm']

export function kpiKeysFor(payload: LivePayload, reader: Reader): string[] {
  const chosen = reader.keys(payload)
  if ((payload.metrics?.length ?? 0) > 0) return chosen

  const totals = payload.totals ?? {}
  const extra = EFFICIENCY.filter((k) => !chosen.includes(k) && reader.meta[k] && totals[k] !== null && totals[k] !== undefined)

  return [...chosen, ...extra]
}

/**
 * The headline: four figures that dominate, then the rest compact. Numbers first; the period
 * comparison is the pill beside each, not a sentence under it.
 */
export function LiveKpiBoard({
  payload,
  reader,
  ar,
  keys,
  heroOnly = false,
}: {
  payload: LivePayload
  reader: Reader
  ar: boolean
  keys: string[]
  heroOnly?: boolean
}) {
  const t = payload.totals
  const d = payload.deltas ?? {}
  const series = (key: string) => payload.timeseries.map((r) => Number(r[key] ?? 0))
  const known = keys.filter((k) => reader.meta[k])
  const hero = known.slice(0, 4)
  const rest = heroOnly ? [] : known.slice(4)

  return (
    <div className="flex flex-col gap-3">
      <div className="grid grid-cols-2 gap-2 sm:gap-3 lg:grid-cols-4" data-testid="live-kpis">
        {hero.map((key) => {
          const meta = reader.meta[key]
          const read = meta.format(t, payload, reader.asMoney, reader.count)

          return (
            <KpiCard
              key={key}
              label={ar ? meta.ar : meta.en}
              value={read.text}
              exact={read.exact ?? undefined}
              delta={d[key]}
              invertGood={meta.invertGood}
              spark={meta.spark ? series(key) : undefined}
            />
          )
        })}
      </div>

      {rest.length > 0 && (
        <dl data-testid="live-kpis-secondary" className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
          {rest.map((key) => {
            const meta = reader.meta[key]
            const read = meta.format(t, payload, reader.asMoney, reader.count)

            return (
              <div key={key} data-testid={`live-kpi-${key}`} className="min-w-0 rounded-xl border border-border bg-surface px-3 py-2">
                <dt className="truncate text-[11px] font-semibold text-text-muted">{ar ? meta.ar : meta.en}</dt>
                <dd className="mt-0.5 flex flex-wrap items-center justify-between gap-1">
                  <span className="tnum text-base font-extrabold text-text-primary" title={read.exact ?? undefined}>
                    <Num>{read.text}</Num>
                  </span>
                  {d[key] !== undefined && d[key] !== null && <TrendPill delta={d[key]} invertGood={meta.invertGood} />}
                </dd>
              </div>
            )
          })}
        </dl>
      )}
    </div>
  )
}

/**
 * What the figures cover, as counts rather than a sentence.
 *
 * The Owner's header listed «active campaigns». A campaign count is campaign-level and the client
 * report rule keeps campaign identity off every client surface, so the header counts what a client
 * reads everywhere else on the page instead: the platforms that carried spend or results, and the
 * pieces of content that ran.
 */
export function LiveScopeCounts({ payload, ar }: { payload: LivePayload; ar: boolean }) {
  const active = payload.platforms.filter((p) => (Number(p.spend ?? 0) > 0) || Number(p.conversions ?? 0) > 0).length
  const content = payload.creatives_in_scope

  const items: Array<{ key: string; label: string; value: string }> = [
    { key: 'platforms', label: ar ? 'منصات نشطة' : 'Active platforms', value: String(active) },
    ...(content === null || content === undefined ? [] : [{ key: 'content', label: ar ? 'محتوى عُرض' : 'Content that ran', value: String(content) }]),
    { key: 'days', label: ar ? 'أيام' : 'Days', value: String(payload.period.days) },
  ]

  return (
    <div data-testid="live-scope-counts" className="flex flex-wrap gap-2">
      {items.map((i) => (
        <span key={i.key} data-testid={`live-count-${i.key}`} className="inline-flex items-baseline gap-1.5 rounded-full border border-border bg-surface px-3 py-1 text-xs text-text-secondary">
          <span className="tnum text-sm font-extrabold text-text-primary"><Num>{i.value}</Num></span>
          {i.label}
        </span>
      ))}
    </div>
  )
}

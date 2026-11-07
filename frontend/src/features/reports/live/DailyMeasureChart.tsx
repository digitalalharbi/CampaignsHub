import { useMemo, useState } from 'react'
import { ProviderName } from '@/components/ProviderMark'
import { Num } from '@/components/ui/Num'
import { ChartCard, PlatformDonutChart, StackedAreaChart } from '@/features/analytics/charts'
import { platformColor } from '@/features/analytics/components'
import { compact, percent } from '@/features/analytics/format'
import { canonicalPlatform } from '@/lib/platforms'
import { providerLabel } from '@/features/campaigns/labels'
import type { LivePayload } from '../api'
import { spendHiddenByLink, type useLiveMetricReader } from './liveMetrics'

type Row = Record<string, unknown>
type Reader = ReturnType<typeof useLiveMetricReader>

/**
 * REPORT-WEBSITE-TAB-001 — «القياس اليومي»: the interactive chart that replaced the long daily values table.
 *
 * A metric switcher (impressions, clicks, spend where the link carries spend, platform conversions, revenue where the
 * link carries revenue), a toggle per platform, a stacked area over the days with a hover tooltip and a keyboard
 * readout, and a donut of the platforms' share of the chosen metric with the total in its centre. A fixed height, so
 * nothing moves when a series is toggled. The rows behind it stay one click away in the page header's CSV / XLSX.
 *
 * What a link hides never reaches the chip row: a metric the payload carries no number for is not offered, and spend is
 * offered only when the link shows spend (the server has already nulled it otherwise).
 */
const METRICS = [
  { key: 'impressions', ar: 'الظهور', en: 'Impressions', money: false },
  { key: 'clicks', ar: 'النقرات', en: 'Clicks', money: false },
  { key: 'spend', ar: 'الإنفاق', en: 'Spend', money: true },
  { key: 'conversions', ar: 'تحويلات المنصات', en: 'Platform conversions', money: false },
  { key: 'revenue', ar: 'الإيراد', en: 'Revenue', money: true },
] as const

type MetricKey = (typeof METRICS)[number]['key']

const hasNumbers = (rows: Row[], key: string) => rows.some((r) => typeof r[key] === 'number')

export function DailyMeasureChart({ payload, reader, ar }: { payload: LivePayload; reader: Reader; ar: boolean }) {
  const days = (payload.timeseries ?? []) as Row[]
  const byPlatform = (payload.platform_series ?? {}) as Record<string, Row[]>
  const providers = useMemo(() => Object.keys(byPlatform).filter((p) => (byPlatform[p] ?? []).length > 0), [byPlatform])
  const spendHidden = spendHiddenByLink(payload)
  const revenueHidden = payload.totals !== undefined && payload.totals !== null && 'revenue' in (payload.totals as Record<string, unknown>) && (payload.totals as Record<string, unknown>).revenue === null

  const offered = useMemo(
    () => METRICS.filter((m) => {
      if (m.key === 'spend' && spendHidden) return false
      if (m.key === 'revenue' && revenueHidden) return false

      return hasNumbers(days, m.key) || providers.some((p) => hasNumbers(byPlatform[p] ?? [], m.key))
    }),
    [days, providers, byPlatform, spendHidden, revenueHidden],
  )
  const [metric, setMetric] = useState<MetricKey | null>(null)
  const [off, setOff] = useState<string[]>([])
  const active = (offered.find((m) => m.key === metric) ?? offered[0]) as (typeof METRICS)[number] | undefined

  if (days.length === 0 || active === undefined) {
    return (
      <ChartCard title={ar ? 'القياس اليومي' : 'Daily measurement'}>
        <p data-testid="daily-chart-empty" className="text-sm text-text-muted">{ar ? 'لا أيام في هذه الفترة.' : 'No days in this period.'}</p>
      </ChartCard>
    )
  }

  const format = (v: number) => (active.money ? reader.asMoney(v) : compact(v))
  const visible = providers.filter((p) => !off.includes(p))
  const split = providers.length > 0
  const dates = days.map((d) => String(d.date ?? ''))

  // One row per day: a column per platform that is switched on, or the link's total when no platform split travels with it.
  const data = dates.map((date, i) => {
    const row: Row = { date }
    if (split) {
      for (const p of visible) {
        const hit = (byPlatform[p] ?? []).find((r) => String(r.date ?? '') === date)
        row[p] = typeof hit?.[active.key] === 'number' ? hit[active.key] : 0
      }
    } else {
      row.total = typeof days[i][active.key] === 'number' ? days[i][active.key] : 0
    }

    return row
  })
  const series = split
    ? visible.map((p) => ({ key: p, name: providerLabel(canonicalPlatform(p), ar ? 'ar' : 'en'), color: platformColor(p) }))
    : [{ key: 'total', name: ar ? active.ar : active.en }]

  const shares = split
    ? visible.map((p) => ({ key: p, name: providerLabel(canonicalPlatform(p), ar ? 'ar' : 'en'), value: (byPlatform[p] ?? []).reduce((a, r) => a + (typeof r[active.key] === 'number' ? (r[active.key] as number) : 0), 0) })).filter((s) => s.value > 0)
    : []
  const total = split ? shares.reduce((a, s) => a + s.value, 0) : days.reduce((a, r) => a + (typeof r[active.key] === 'number' ? (r[active.key] as number) : 0), 0)

  return (
    <ChartCard
      title={ar ? 'القياس اليومي' : 'Daily measurement'}
      action={(
        <div role="group" aria-label={ar ? 'المقياس' : 'Metric'} data-testid="daily-metric-chips" className="flex max-w-full flex-wrap gap-1">
          {offered.map((m) => (
            <button
              key={m.key}
              type="button"
              aria-pressed={m.key === active.key}
              data-testid={`daily-metric-${m.key}`}
              onClick={() => setMetric(m.key)}
              className={`rounded-full px-3 py-1 text-xs font-bold transition-colors ${m.key === active.key ? 'bg-brand-600 text-white' : 'bg-surface-secondary text-text-secondary hover:text-text-primary'}`}
            >
              {ar ? m.ar : m.en}
            </button>
          ))}
        </div>
      )}
    >
      <div data-testid="daily-chart" data-metric={active.key} data-days={days.length} className="flex min-w-0 flex-col gap-3">
        {split && providers.length > 1 && (
          <div role="group" aria-label={ar ? 'المنصات' : 'Platforms'} data-testid="daily-platform-toggles" className="flex flex-wrap gap-1.5">
            {providers.map((p) => {
              const on = !off.includes(p)

              return (
                <button
                  key={p}
                  type="button"
                  aria-pressed={on}
                  data-testid={`daily-platform-${p}`}
                  onClick={() => setOff((list) => (list.includes(p) ? list.filter((x) => x !== p) : [...list, p]))}
                  className={`inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-semibold transition-colors ${on ? 'border-transparent bg-surface-secondary text-text-primary' : 'border-border text-text-muted line-through'}`}
                >
                  <ProviderName provider={p} locale={ar ? 'ar' : 'en'} size={14} />
                </button>
              )
            })}
          </div>
        )}
        <div className="grid min-w-0 grid-cols-1 gap-4 lg:grid-cols-3">
          <div className="min-w-0 lg:col-span-2" data-testid="daily-area">
            <StackedAreaChart data={data} series={series} height={280} formatValue={format} label={`${ar ? 'القياس اليومي' : 'Daily measurement'} — ${ar ? active.ar : active.en}`} />
          </div>
          <div className="min-w-0" data-testid="daily-donut" data-total={total}>
            {shares.length > 0 ? (
              <PlatformDonutChart data={shares} height={280} legend colorBy="platform" centerLabel={ar ? active.ar : active.en} centerValue={format(total)} />
            ) : (
              <div className="grid place-items-center text-xs text-text-muted" style={{ height: 280 }}>{split ? (ar ? 'لا منصة ظاهرة' : 'No platform switched on') : <Num>{format(total)}</Num>}</div>
            )}
            {shares.length > 1 && (
              <p className="mt-1 flex flex-wrap justify-center gap-x-3 text-xs text-text-muted" data-testid="daily-shares">
                {shares.map((s) => <span key={s.key} className="tnum">{s.name} {percent(s.value / (total || 1), 0)}</span>)}
              </p>
            )}
          </div>
        </div>
      </div>
    </ChartCard>
  )
}

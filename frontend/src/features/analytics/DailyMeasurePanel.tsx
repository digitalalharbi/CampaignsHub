import { useMemo, useState } from 'react'
import { ProviderName } from '@/components/ProviderMark'
import { Num } from '@/components/ui/Num'
import { providerLabel } from '@/features/campaigns/labels'
import { canonicalPlatform, isAdPlatform, sortPlatforms } from '@/lib/platforms'
import { usePlatforms, usePlatformTimeseries, type MetricFilters, type TimePoint } from './api'
import { ChartCard, PlatformDonutChart, StackedAreaChart } from './charts'
import { platformColor } from './components'
import { compact, percent } from './format'

type Range = Parameters<typeof usePlatforms>[1]
type Row = Record<string, unknown>

/**
 * ANALYTICS-DAILY-MEASURE-001 — Owner 2026-11-18: the analysis page draws what the report link draws.
 *
 * «القياس اليومي»: a metric switcher, a toggle per platform (the product's order), a stacked area over the days and a donut
 * of each platform's share with the total in its centre. Counts only — impressions, clicks, platform conversions — so no
 * currency is ever summed across platforms; a metric no platform reported is not offered.
 */
const METRICS = [
  { key: 'impressions', ar: 'الظهور', en: 'Impressions' },
  { key: 'clicks', ar: 'النقرات', en: 'Clicks' },
  { key: 'conversions', ar: 'تحويلات المنصات', en: 'Platform conversions' },
] as const

type MetricKey = (typeof METRICS)[number]['key']

const valueOf = (r: Row | undefined, key: string): number | null => (typeof r?.[key] === 'number' && Number.isFinite(r[key]) ? (r[key] as number) : null)

export function DailyMeasurePanel({ projectId, range, filters, ar }: { projectId: string | null; range: Range; filters?: MetricFilters; ar: boolean }) {
  const platforms = usePlatforms(projectId, range, filters)
  const providers = useMemo(
    () => sortPlatforms([...new Set((platforms.data ?? []).map((p) => p.provider).filter(isAdPlatform))]),
    [platforms.data],
  )
  const series = usePlatformTimeseries(projectId, range, providers, filters)
  const [metric, setMetric] = useState<MetricKey | null>(null)
  const [off, setOff] = useState<string[]>([])

  const byPlatform: Record<string, TimePoint[]> = {}
  providers.forEach((p, i) => { byPlatform[p] = (series[i]?.data ?? []) as TimePoint[] })
  const loading = platforms.isLoading || series.some((s) => s.isLoading)

  const offered = METRICS.filter((m) => providers.some((p) => (byPlatform[p] ?? []).some((r) => valueOf(r as unknown as Row, m.key) !== null)))
  const active = offered.find((m) => m.key === metric) ?? offered[0]
  const dates = [...new Set(providers.flatMap((p) => (byPlatform[p] ?? []).map((r) => r.date)))].sort()
  const title = ar ? 'القياس اليومي' : 'Daily measurement'

  if (loading) return <div data-testid="analytics-daily-loading" className="h-80 animate-pulse rounded-2xl bg-surface-secondary" />
  if (active === undefined || dates.length === 0) return null

  const visible = providers.filter((p) => !off.includes(p))
  const name = (p: string) => providerLabel(canonicalPlatform(p), ar ? 'ar' : 'en')
  const data = dates.map((date) => {
    const row: Row = { date }
    for (const p of visible) row[p] = valueOf((byPlatform[p] ?? []).find((r) => r.date === date) as unknown as Row, active.key) ?? 0

    return row
  })
  const shares = visible
    .map((p) => ({ key: p, name: name(p), value: (byPlatform[p] ?? []).reduce((a, r) => a + (valueOf(r as unknown as Row, active.key) ?? 0), 0) }))
    .filter((s) => s.value > 0)
  const total = shares.reduce((a, s) => a + s.value, 0)

  return (
    <ChartCard
      title={title}
      action={(
        <div role="group" aria-label={ar ? 'المقياس' : 'Metric'} data-testid="analytics-daily-metrics" className="flex max-w-full flex-wrap gap-1">
          {offered.map((m) => (
            <button
              key={m.key}
              type="button"
              aria-pressed={m.key === active.key}
              data-testid={`analytics-daily-metric-${m.key}`}
              onClick={() => setMetric(m.key)}
              className={`rounded-full px-3 py-1 text-xs font-bold transition-colors ${m.key === active.key ? 'bg-brand-600 text-white' : 'bg-surface-secondary text-text-secondary hover:text-text-primary'}`}
            >
              {ar ? m.ar : m.en}
            </button>
          ))}
        </div>
      )}
    >
      <div data-testid="analytics-daily" data-metric={active.key} className="flex min-w-0 flex-col gap-3">
        {providers.length > 1 && (
          <div role="group" aria-label={ar ? 'المنصات' : 'Platforms'} className="flex flex-wrap gap-1.5">
            {providers.map((p) => {
              const on = !off.includes(p)

              return (
                <button
                  key={p}
                  type="button"
                  aria-pressed={on}
                  data-testid={`analytics-daily-platform-${p}`}
                  onClick={() => setOff((list) => (list.includes(p) ? list.filter((x) => x !== p) : [...list, p]))}
                  className={`inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-semibold transition-colors ${on ? 'border-transparent bg-surface-secondary text-text-primary' : 'border-border text-text-muted line-through'}`}
                >
                  <ProviderName provider={p} locale={ar ? 'ar' : 'en'} size={16} />
                </button>
              )
            })}
          </div>
        )}
        <div className="grid min-w-0 grid-cols-1 gap-4 lg:grid-cols-3">
          <div className="min-w-0 lg:col-span-2">
            <StackedAreaChart
              data={data}
              series={visible.map((p) => ({ key: p, name: name(p), color: platformColor(p) }))}
              height={280}
              formatValue={compact}
              label={`${title} — ${ar ? active.ar : active.en}`}
            />
          </div>
          <div className="min-w-0" data-testid="analytics-daily-donut" data-total={total}>
            {shares.length > 0 ? (
              <PlatformDonutChart data={shares} height={280} legend colorBy="platform" centerLabel={ar ? active.ar : active.en} centerValue={compact(total)} />
            ) : (
              <div className="grid place-items-center text-xs text-text-muted" style={{ height: 280 }}><Num>{compact(total)}</Num></div>
            )}
            {shares.length > 1 && (
              <p className="mt-1 flex flex-wrap justify-center gap-x-3 text-xs text-text-muted">
                {shares.map((s) => <span key={s.key} className="tnum">{s.name} {percent(s.value / (total || 1), 0)}</span>)}
              </p>
            )}
          </div>
        </div>
      </div>
    </ChartCard>
  )
}

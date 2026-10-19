import { useState } from 'react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { ArrowRight } from 'lucide-react'
import { StatCard, StatGrid } from '@/components/ui/StatCard'
import { Num } from '@/components/ui/Num'
import { PortalTrend } from './PortalTrend'
import { KpiSparkline } from '@/features/analytics/charts'
import { ProviderMark } from '@/components/ProviderMark'
import { providerLabel } from '@/features/campaigns/labels'
import { sortByPlatform } from '@/lib/platforms'
import { days } from '@/lib/counted'
import { useUi } from '@/stores/ui'
import { fetchPortalOverview, type PortalOverview } from './portalOverviewApi'
import { useClientSpacePath } from './clientSpace'
import { usePortalGuard } from './usePortalGuard'

/**
 * CP-DASH-001 — the client's portfolio: a few figures that matter, then compact summaries.
 *
 * ## One hierarchy, not ten equal cards
 *
 * Three large primary figures (REPORT-KPI-HIERARCHY-001) and the rest as a quiet strip beside them. A
 * client with forty campaigns and four platforms reads the picture in one look; the detail is one click
 * down (campaigns, reports), never a wall of cards on the home page.
 *
 * ## What it never does
 *
 * It does not compute. Every figure arrives from `/client/overview`, which is the agency's own
 * `MetricsAggregator` view filtered by what the agency has chosen to show; an absent key means «not
 * shown», and a null means «not available» — neither is drawn as a zero.
 */
const COPY = {
  ar: {
    title: 'أداء حساباتك', period: (d: number) => `آخر ${days(d, 'ar')}`, synced: 'آخر مزامنة', stale: 'قد تكون البيانات متأخرة',
    platforms: 'المنصات', projects: 'المشاريع', campaigns: 'الحملات', all: 'عرض الكل', none: 'لا توجد بيانات لهذه الفترة بعد.',
    total: 'الإجمالي',
    k: { impressions: 'مرات الظهور', clicks: 'النقرات', conversions: 'النتائج', ctr: 'نسبة النقر', spend: 'الإنفاق', cpa: 'تكلفة النتيجة', revenue: 'الإيرادات', roas: 'العائد على الإنفاق' } as Record<string, string>,
    st: { active: 'نشطة', paused: 'متوقفة', draft: 'مسودة', ended: 'منتهية', completed: 'مكتملة', archived: 'مؤرشفة' } as Record<string, string>,
  },
  en: {
    title: 'Your performance', period: (d: number) => `Last ${days(d, 'en')}`, synced: 'Last synced', stale: 'Data may be delayed',
    platforms: 'Platforms', projects: 'Projects', campaigns: 'Campaigns', all: 'View all', none: 'No data for this period yet.',
    total: 'Total',
    k: { impressions: 'Impressions', clicks: 'Clicks', conversions: 'Results', ctr: 'CTR', spend: 'Spend', cpa: 'Cost per result', revenue: 'Revenue', roas: 'ROAS' } as Record<string, string>,
    st: { active: 'Active', paused: 'Paused', draft: 'Draft', ended: 'Ended', completed: 'Completed', archived: 'Archived' } as Record<string, string>,
  },
}

const PRIMARY_WITH_MONEY = ['spend', 'conversions', 'cpa'] as const
const PRIMARY_NO_MONEY = ['impressions', 'clicks', 'conversions'] as const

function format(key: string, v: number | null | undefined, currency: string | null): string {
  if (v === null || v === undefined) return '—'
  if (key === 'ctr') return `${(v * 100).toFixed(2)}%`
  if (key === 'roas') return `${v.toFixed(2)}×`
  const compact = Math.abs(v) >= 100_000 ? new Intl.NumberFormat('en', { notation: 'compact', maximumFractionDigits: 1 }).format(v) : new Intl.NumberFormat('en', { maximumFractionDigits: key === 'cpa' || key === 'spend' || key === 'revenue' ? 2 : 0 }).format(v)

  return ['spend', 'cpa', 'revenue'].includes(key) && currency ? `${compact} ${currency}` : compact
}

export function PortfolioPerformance() {
  const ar = useUi((s) => s.locale) === 'ar'
  const t = ar ? COPY.ar : COPY.en
  const spaceTo = useClientSpacePath()
  /*
   * CP-PARITY-001 — the period choice every other dashboard has. The endpoint always took `from` and
   * `to`; the portal only ever asked for its default thirty days.
   */
  const [range, setRange] = useState<7 | 30 | 90>(30)
  const overview = useQuery({
    queryKey: ['portal', 'overview', range],
    queryFn: () => fetchPortalOverview(range === 30 ? {} : periodOf(range)),
    retry: false,
    placeholderData: keepPreviousData,
  })

  usePortalGuard(overview.isError, overview.error)

  // The agency switched the dashboard off, or the contact must first choose a space: nothing to draw.
  if (overview.isError || overview.isPending) {
    return overview.isPending ? <div data-testid="portfolio-loading" className="mb-6 h-40 animate-pulse rounded-2xl bg-surface-secondary" /> : null
  }

  return <Body data={overview.data} t={t} ar={ar} spaceTo={spaceTo} range={range} onRange={setRange} refreshing={overview.isFetching} />
}

/** The last N days ending today, as the endpoint reads them (local calendar dates). */
function periodOf(n: number): { from: string; to: string } {
  const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
  const to = new Date()
  const from = new Date(to)
  from.setDate(to.getDate() - (n - 1))

  return { from: iso(from), to: iso(to) }
}

/** The series behind a primary figure, when the trend carries it (it carries delivery, never money). */
function sparkOf(data: PortalOverview, key: string): number[] | null {
  if (!['impressions', 'clicks', 'conversions'].includes(key)) return null
  const points = (data.trend ?? []).map((p) => p[key as 'impressions' | 'clicks' | 'conversions']).filter((v): v is number => typeof v === 'number')

  return points.length > 1 ? points : null
}

function Body({ data, t, ar, spaceTo, range, onRange, refreshing }: { data: PortalOverview; t: typeof COPY.ar; ar: boolean; spaceTo: (s: string) => string; range: 7 | 30 | 90; onRange: (r: 7 | 30 | 90) => void; refreshing: boolean }) {
  const money = 'spend' in data.kpis
  const primaryKeys = (money ? PRIMARY_WITH_MONEY : PRIMARY_NO_MONEY).filter((k) => k in data.kpis)
  const secondaryKeys = Object.keys(data.kpis).filter((k) => !(primaryKeys as readonly string[]).includes(k))
  const hasAnything = Object.values(data.kpis).some((v) => v !== null && v !== 0)

  return (
    <section data-testid="portfolio-performance" className={`mb-6 transition-opacity ${refreshing ? 'opacity-60' : ''}`}>
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <h2 className="font-heading text-lg font-extrabold text-text-primary">{t.title}</h2>
        <div className="flex gap-1" role="group" aria-label={ar ? 'الفترة' : 'Period'} data-testid="portfolio-range">
          {([7, 30, 90] as const).map((n) => (
            <button
              key={n}
              type="button"
              aria-pressed={range === n}
              data-testid={`portfolio-range-${n}`}
              onClick={() => onRange(n)}
              className={`rounded-lg px-2.5 py-1 text-xs font-semibold transition-colors ${range === n ? 'bg-brand-600 text-white' : 'border border-border text-text-secondary hover:bg-surface-hover'}`}
            >
              {days(n, ar ? 'ar' : 'en')}
            </button>
          ))}
        </div>
        <p className="w-full text-xs text-text-muted">
          {t.period(data.period.days)}
          {data.freshness.last_sync_at && <> · {t.synced} <Num>{new Date(data.freshness.last_sync_at).toISOString().slice(0, 16).replace('T', ' ')}</Num></>}
          {data.freshness.state && data.freshness.state !== 'fresh' && <> · <span className="text-warning">{t.stale}</span></>}
        </p>
      </div>

      {!hasAnything ? (
        <p className="rounded-2xl border border-border bg-surface p-5 text-sm text-text-muted">{t.none}</p>
      ) : (
        <>
          {/* Three in a row at every width, phone included: the primary figures read as one glance, not a stack. */}
          <StatGrid columns="grid-cols-3">
            {primaryKeys.map((k) => (
              <StatCard
                key={k}
                testid={`portfolio-kpi-${k}`}
                label={t.k[k] ?? k}
                value={<Num>{format(k, data.kpis[k], data.currency)}</Num>}
                spark={sparkOf(data, k) ? <span data-testid={`portfolio-spark-${k}`} className="block"><KpiSparkline points={sparkOf(data, k)!} height={26} /></span> : undefined}
              />
            ))}
          </StatGrid>
          {secondaryKeys.length > 0 && (
            <ul data-testid="portfolio-secondary" className="mt-2 flex flex-wrap gap-x-5 gap-y-1 rounded-xl border border-border bg-surface px-4 py-2 text-xs text-text-secondary">
              {secondaryKeys.map((k) => (
                <li key={k} className="flex items-baseline gap-1.5">
                  <span>{t.k[k] ?? k}</span>
                  <Num className="font-semibold text-text-primary">{format(k, data.kpis[k], data.currency)}</Num>
                </li>
              ))}
            </ul>
          )}
        </>
      )}

      {data.trend !== undefined && data.trend.length > 1 && <PortalTrend trend={data.trend} days={data.period.days} />}

      <div className="mt-4 grid gap-4 md:grid-cols-2">
        {data.platforms.length > 0 && (
          <div data-testid="portfolio-platforms" className="rounded-2xl border border-border bg-surface p-4">
            <h3 className="mb-2 text-sm font-bold text-text-primary">{t.platforms}</h3>
            <ul className="divide-y divide-border">
              {sortByPlatform(data.platforms, (p) => p.provider).map((p) => (
                <li key={p.provider ?? 'unknown'} className="flex items-center justify-between gap-3 py-2 text-sm">
                  <span className="flex min-w-0 items-center gap-2">
                    <ProviderMark provider={p.provider ?? ''} />
                    <span className="truncate text-text-primary">{providerLabel(p.provider ?? '', ar ? 'ar' : 'en')}</span>
                  </span>
                  <span className="flex shrink-0 items-baseline gap-3 text-xs text-text-secondary">
                    {p.spend !== undefined && p.spend !== null && <Num>{format('spend', p.spend, data.currency)}</Num>}
                    <span><span className="text-text-muted">{t.k.conversions} </span><Num className="font-semibold text-text-primary">{format('conversions', p.conversions, null)}</Num></span>
                  </span>
                </li>
              ))}
            </ul>
          </div>
        )}

        <div className="space-y-4">
          {data.campaigns && data.campaigns.total > 0 && (
            <div data-testid="portfolio-campaigns" className="rounded-2xl border border-border bg-surface p-4">
              <div className="mb-2 flex items-center justify-between">
                <h3 className="text-sm font-bold text-text-primary">{t.campaigns}</h3>
                <Link to={spaceTo('/campaigns')} className="flex items-center gap-1 text-xs font-semibold text-brand-600 hover:text-brand-700">{t.all} <ArrowRight size={13} className="rtl:rotate-180" /></Link>
              </div>
              <p className="flex flex-wrap items-center gap-1.5 text-xs">
                <span className="rounded-full bg-surface-secondary px-2.5 py-1 font-semibold text-text-primary">{t.total} <Num>{data.campaigns.total}</Num></span>
                {Object.entries(data.campaigns.by_status).map(([status, n]) => (
                  <span key={status} className="rounded-full bg-surface-secondary px-2.5 py-1 text-text-secondary">{t.st[status] ?? status} <Num>{n}</Num></span>
                ))}
              </p>
            </div>
          )}
          {data.projects.length > 0 && (
            <div data-testid="portfolio-projects" className="rounded-2xl border border-border bg-surface p-4">
              <h3 className="mb-2 text-sm font-bold text-text-primary">{t.projects}</h3>
              <ul className="divide-y divide-border">
                {data.projects.slice(0, 6).map((p) => (
                  <li key={p.id} className="flex items-center justify-between gap-3 py-1.5 text-sm">
                    <span className="truncate text-text-primary">{p.name}</span>
                    {p.spend !== undefined && <Num className="shrink-0 text-xs text-text-secondary">{format('spend', p.spend, data.currency)}</Num>}
                  </li>
                ))}
              </ul>
              {data.projects.length > 6 && <p className="mt-1 text-xs text-text-muted">+<Num>{data.projects.length - 6}</Num></p>}
            </div>
          )}
        </div>
      </div>
    </section>
  )
}

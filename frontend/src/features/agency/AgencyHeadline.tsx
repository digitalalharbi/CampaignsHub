import { useMemo, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { AlertTriangle, ArrowLeft, ArrowRight } from 'lucide-react'
import { fetchAgencyOverview } from './api'
import { listAlertEvents, type AlertEvent } from '@/features/alerts/api'
import { StatCard, StatGrid } from '@/components/ui/StatCard'
import { DataFreshness } from '@/components/ui/PageIntro'
import { PeriodLabel } from '@/components/patterns/Status'
import { FilterChips } from '@/components/ui/FilterBar'
import { useUrlNumber } from '@/features/analytics/filterUrlState'
import { lastNDays } from '@/features/analytics/api'
import { Badge } from '@/components/ui/Badge'
import { Skeleton } from '@/components/ui/States'
import { MetricTable, type SortValues } from '@/components/ui/MetricTable'
import { ChartCard, KpiSparkline, SpendRevenueAreaChart } from '@/features/analytics/charts'
import { sparkFor } from '@/features/analytics/metricCatalog'
import type { TimePoint } from '@/features/analytics/api'
import { money, num, percent, ratio } from '@/features/analytics/format'
import { formatMoneyReading, readMoney, type MoneyTotals } from '@/lib/money/contract'
import { comparableWindows, coverageNote, readCoverage, windowsUnlikeNote } from '@/lib/coverage/contract'
import { providerLabel } from '@/features/campaigns/labels'
import { headline as alertHeadline } from '@/features/recommendations/findingCopy'

/**
 * DASHBOARD-FIRST-SCREEN-001 — the agency dashboard answers «what is happening» before it counts.
 *
 * The first screen was four COUNTS (clients, projects, campaigns, requests) and then the attention
 * block; money, results, platforms, trend and freshness arrived three charts down, if at all. An
 * operator opening the product each morning needs, in one viewport: what was spent, what came back,
 * the objective-correct cost of it, how that moved, which platform carried it, what needs them, and
 * how fresh the figures are — each one a door to the surface that answers in depth.
 *
 * Every rule the figures obey elsewhere travels with them:
 *   - money reads through the contract (`readMoney`, `readCostPer`, `readRoas`): withheld stays in its
 *     own currency, partial or mixed money refuses a total, a reported zero prints 0;
 *   - the currency is the window's basis; null prints bare figures, never a guessed unit;
 *   - a movement is shown only where BOTH windows' coverage allows the comparison, and the note
 *     beside the figures names who stopped short and through when;
 *   - a ratio is derived only where coverage allows one (`allowsDerived`).
 *
 * Three components over one cached read, so the page can seat the attention block beside the alerts
 * directly under the figures and keep the shapes below them.
 */
const STALE_AFTER_HOURS = 36

const SEVERITY_ORDER: Record<AlertEvent['severity'], number> = { critical: 0, warning: 1, info: 2 }

/**
 * The window is part of the ADDRESS (`?days=7|30|90`), as on Analytics, so a reload, Back and a
 * shared link show the same figures; the three components read the same key and share one cached read.
 */
function useOverview() {
  const [days, setDays] = useUrlNumber('days', 30)
  const range = useMemo(() => lastNDays(days), [days])
  const query = useQuery({ queryKey: ['agency', 'overview', days], queryFn: () => fetchAgencyOverview(range), retry: false })

  return { ...query, days, setDays }
}

function alertPath(a: AlertEvent): string {
  if (a.entity_type?.endsWith('UnifiedCampaign') && a.entity_id && a.project_id) return `/agency/campaigns/${a.project_id}/${a.entity_id}`

  return '/agency/alerts'
}

function Door({ to, children }: { to: string; children: ReactNode }) {
  return <Link to={to} className="block transition-colors [&>*]:hover:border-brand-400">{children}</Link>
}

export function AgencyHeadline({ ar }: { ar: boolean }) {
  const overview = useOverview()
  const locale = ar ? 'ar' : 'en'

  const d = overview.data
  const current = (d?.current ?? undefined) as MoneyTotals | undefined
  const previous = (d?.previous ?? undefined) as MoneyTotals | undefined
  const currency = d?.currency ?? null
  const coverage = readCoverage(current)
  const comparable = current !== undefined && previous !== undefined && comparableWindows({ current, previous })
  const providerName = (c: string) => providerLabel(c, locale)
  const note = current === undefined
    ? null
    : (windowsUnlikeNote({ current, previous }, ar, providerName) ?? coverageNote(coverage, ar, providerName))

  if (overview.isLoading) {
    return (
      <div data-testid="agency-headline-loading" className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        {[0, 1, 2, 3].map((i) => <Skeleton key={i} className="h-28" />)}
      </div>
    )
  }

  if (overview.isError || !d) {
    return (
      <p data-testid="agency-headline-failure" className="rounded-xl border border-warning/40 bg-warning/5 px-4 py-3 text-sm text-text-secondary">
        {ar ? 'تعذّرت قراءة أرقام الفترة — العدّادات أدناه لا تتأثر.' : 'The period’s figures could not be read — the counts below are unaffected.'}
      </p>
    )
  }

  const spend = current ? readMoney(current, 'spend', currency, ar) : null
  const prevSpend = previous ? readMoney(previous, 'spend', currency, ar) : null
  const objectives = d.by_objective ?? []
  const salesBlock = objectives.find((o) => o.family === 'sales')
  const roasHint = salesBlock?.roas != null
    ? (ar ? `دخلت ${num(salesBlock.roas_campaigns)} من ${num(salesBlock.sales_campaigns)} حملة مبيعات` : `${num(salesBlock.roas_campaigns)} of ${num(salesBlock.sales_campaigns)} sales campaigns entered it`)
    : (ar ? 'لا حملات مبيعات بإيراد مُبلَّغ' : 'No sales campaign reported revenue')
  const sparkOf = (key: string): ReactNode => {
    const points = sparkFor(key, (d?.timeseries ?? []) as unknown as readonly TimePoint[], undefined)

    return points ? <div data-testid={`agency-kpi-${key}-spark`}><KpiSparkline points={points} height={26} /></div> : undefined
  }
  const deltaOf = (metric: string, cur: number | null | undefined, prev: number | null | undefined) =>
    comparable && cur != null && prev != null ? { metric, current: cur, previous: prev } : undefined

  return (
    <section data-testid="agency-headline" className="space-y-3">
      <div className="flex flex-wrap items-center gap-x-4 gap-y-1.5 text-xs text-text-secondary">
        <FilterChips
          label={ar ? 'الفترة' : 'Period'}
          value={String(overview.days)}
          testid="agency-days"
          options={[
            { value: '7', label: ar ? '7 أيام' : '7 days' },
            { value: '30', label: ar ? '30 يوم' : '30 days' },
            { value: '90', label: ar ? '90 يوم' : '90 days' },
          ]}
          onChange={(v) => overview.setDays(Number(v))}
        />
        <PeriodLabel from={d.period.from} to={d.period.to} testId="agency-period" />
        <DataFreshness lastSyncAt={d.freshness.last_synced_at} ar={ar} staleAfterHours={STALE_AFTER_HOURS} testid="agency-freshness" />
        {/*
          The comparison window, said ONCE. It rode inside every movement pill («+22% since 2026-08-12»),
          which on a half-width phone card in English was wider than the card and scrolled the page
          sideways (responsive-sweep, chromium, ltr). One line here says it for all four.
        */}
        {comparable && (
          <span data-testid="agency-compared-with">
            {ar ? 'مقارنةً بـ ' : 'Compared with '}
            <span dir="ltr">{d.previous_period.from} → {d.previous_period.to}</span>
          </span>
        )}
        {currency === null && current !== undefined && (
          <span data-testid="agency-currency-note">{ar ? 'الأرقام بلا عملة واحدة — لا تُجمع عبر العملات.' : 'No single currency — figures are not summed across currencies.'}</span>
        )}
      </div>

      {current === undefined ? (
        <p data-testid="agency-headline-empty" className="rounded-xl border border-dashed border-border px-4 py-5 text-center text-sm text-text-muted">
          {ar ? 'لا مشاريع ضمن نطاقك بعد — لا أرقام تُقرأ.' : 'No projects within your scope yet — there are no figures to read.'}
        </p>
      ) : (
        <>
          {/*
            UX-COMPACT-CHARTS-001 — the shape of the window under each figure.

            The four cards stated a number and its movement and nothing about the days between: a
            reader comparing «61.2K SAR, +22%» had no way to see whether the rise was steady or one
            spike. Each card now carries the window's daily shape through the product's one sparkline
            rule (`sparkFor`): a day nobody reported is left out, a series more than a third missing or
            flat draws nothing, and a metric the series does not carry (a cost per result the daily
            rows never hold) shows no line rather than a line invented from zeros.
          */}
          <StatGrid columns="grid-cols-2 lg:grid-cols-4" sparks>
            <Door to="/agency/portfolio">
              <StatCard
                testid="agency-kpi-spend"
                spark={sparkOf('spend')}
                tone="brand"
                label={ar ? 'الإنفاق' : 'Spend'}
                value={<span dir="ltr">{formatMoneyReading(spend!, money)}</span>}
                hint={spend!.note ?? undefined}
                delta={spend!.kind === 'converted' && prevSpend?.kind === 'converted' ? deltaOf('spend', spend!.amount, prevSpend.amount) : undefined}
              />
            </Door>
            <Door to="/agency/analytics">
              <StatCard
                testid="agency-kpi-roas"
                tone="neutral"
                label="ROAS"
                /*
                  DASH-KPI-OBJECTIVE-001 — a return on spend exists for SALES campaigns only, and only where
                  revenue was reported. It was revenue ÷ spend over every campaign, so a views campaign's spend
                  sat in the denominator of a number about orders. The card now says how many sales campaigns
                  entered it, and the figure beside it is the sales family's own.
                */
                value={<span dir="ltr">{salesBlock?.roas != null ? ratio(salesBlock.roas) : '—'}</span>}
                hint={roasHint}
              />
            </Door>
          </StatGrid>
          {/*
            DASH-KPI-OBJECTIVE-001 — results by what the money was FOR. Orders, leads, clicks and views are not
            one currency: each family states its result and its OWN cost per result, and there is no cost per
            result across them. A family whose platform sent no figure says so instead of printing 0.
          */}
          {objectives.length > 0 && (
            <div data-testid="agency-objective-results" className="space-y-2">
              <p className="text-xs font-bold text-text-secondary">{ar ? 'النتائج حسب الهدف — لكل هدف تكلفته' : 'Results by objective — each with its own cost'}</p>
              <StatGrid columns="grid-cols-2 lg:grid-cols-4">
                {objectives.map((o) => {
                  const resultName = o.result_label ? (ar ? o.result_label.ar : o.result_label.en) : (ar ? 'النتيجة' : 'Result')
                  const costName = o.cost_per_result_label ? (ar ? o.cost_per_result_label.ar : o.cost_per_result_label.en) : (ar ? 'تكلفة النتيجة' : 'Cost per result')

                  return (
                    <StatCard
                      key={o.family}
                      testid={`agency-kpi-result-${o.family}`}
                      tone="success"
                      label={resultName}
                      value={<span dir="ltr">{o.result == null ? '—' : num(o.result)}</span>}
                      hint={o.result == null
                        ? (o.missing_label ? (ar ? o.missing_label.ar : o.missing_label.en) : undefined)
                        : (o.cost_per_result != null ? `${costName}: ${money(o.cost_per_result, currency)}` : (ar ? `${costName}: غير متوفرة` : `${costName}: not available`))}
                    />
                  )
                })}
              </StatGrid>
            </div>
          )}
          {note && (
            <p data-testid="agency-coverage-note" className="rounded-xl border border-warning/40 bg-warning/5 px-3 py-2 text-sm text-text-secondary">{note}</p>
          )}
        </>
      )}
    </section>
  )
}

/** The daily trend and the platform comparison — the same overview read, drawn as a shape and a table. */
export function AgencyTrendAndPlatforms({ ar }: { ar: boolean }) {
  const overview = useOverview()
  const locale = ar ? 'ar' : 'en'
  const d = overview.data
  const currency = d?.currency ?? null
  const providerName = (c: string) => providerLabel(c, locale)

  const platformRows = useMemo(() => (d?.by_provider ?? []).map((row) => {
    const t = row as MoneyTotals

    return { provider: row.provider, spend: readMoney(t, 'spend', currency, ar) }
  }), [d?.by_provider, currency, ar])
  /*
   * DASH-KPI-OBJECTIVE-001 — the platform rows state SPEND and its share only. A platform's conversions, cost per
   * conversion and ROAS summed every objective it ran (views beside orders), the same blend the headline retired;
   * each platform's results by objective live in Analytics and in the platform's own page.
   */
  const spendTotal = platformRows.reduce((a, r) => a + (r.spend.kind === 'converted' ? (r.spend.amount ?? 0) : 0), 0)

  if (overview.isLoading) return <div className="grid grid-cols-1 gap-4 lg:grid-cols-2"><Skeleton className="h-64" /><Skeleton className="h-64" /></div>
  if (overview.isError || !d) return null

  /*
   * `grid-cols-1` is not decoration. With no base track the single phone column is `auto`, sized to
   * the min-content of what it holds — and the platform table is 640px wide inside its scroller, so
   * the column became 640px and the page scrolled sideways at 375px (responsive-sweep, chromium).
   * `minmax(0, 1fr)` lets the table's own scroller do the scrolling.
   */
  return (
    <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
      <ChartCard
        title={ar ? 'الإنفاق والإيراد يوميًا' : 'Spend and revenue by day'}
        subtitle={ar ? 'عبر كل العملاء ضمن نطاقك — يوم بلا قياس لا يُرسم صفرًا.' : 'Across every client in your scope — a day with no measurement is not drawn as zero.'}
      >
        {d.timeseries.length === 0
          ? <p data-testid="agency-trend-empty" className="flex h-[220px] items-center justify-center text-sm text-text-muted">{ar ? 'لا قياسات في هذه الفترة.' : 'No measurements in this period.'}</p>
          : <div data-testid="agency-trend"><SpendRevenueAreaChart data={d.timeseries} height={220} currency={currency ?? ''} /></div>}
      </ChartCard>

      <ChartCard
        title={ar ? 'مقارنة المنصات' : 'Platform comparison'}
        subtitle={ar ? 'من حمل الإنفاق، وبأي حصة.' : 'Who carried the spend, and in what share.'}
      >
        {platformRows.length === 0
          ? <p data-testid="agency-platforms-empty" className="flex h-[220px] items-center justify-center text-sm text-text-muted">{ar ? 'لا منصة أبلغت في هذه الفترة.' : 'No platform reported in this period.'}</p>
          : (
            <div data-testid="agency-platforms" className="min-w-0">
              <MetricTable
                head={ar ? ['المنصة', 'الإنفاق', 'حصتها من الإنفاق'] : ['Platform', 'Spend', 'Share of spend']}
                rows={platformRows.map((r) => [
                  <Link key="p" to={`/agency/content?providers=${r.provider}`} data-testid={`agency-platform-${r.provider}`} className="font-semibold text-text-primary underline decoration-dotted underline-offset-4 hover:text-brand-600">{providerName(r.provider)}</Link>,
                  <span key="s" dir="ltr">{formatMoneyReading(r.spend, money)}</span>,
                  <span key="c" dir="ltr">{r.spend.kind === 'converted' && spendTotal > 0 ? percent((r.spend.amount ?? 0) / spendTotal, 0) : '—'}</span>,
                ])}
                values={platformRows.map((r): SortValues => [providerName(r.provider), r.spend.amount, r.spend.kind === 'converted' && spendTotal > 0 ? (r.spend.amount ?? 0) / spendTotal : null])}
                initialSort={{ column: 1, dir: 'desc' }}
              />
            </div>
          )}
      </ChartCard>
    </div>
  )
}

/** The open alerts, most severe first, each opening what raised it. */
export function AgencyAlerts({ ar }: { ar: boolean }) {
  const alerts = useQuery({ queryKey: ['agency', 'alerts', 'open'], queryFn: () => listAlertEvents('open'), retry: false })
  const topAlerts = useMemo(() => [...(alerts.data?.events ?? [])]
    .filter((a) => a.status === 'open')
    .sort((a, b) => SEVERITY_ORDER[a.severity] - SEVERITY_ORDER[b.severity] || (b.created_at ?? '').localeCompare(a.created_at ?? ''))
    .slice(0, 5), [alerts.data])

  return (
    <section data-testid="agency-alerts" className="rounded-2xl border border-border bg-surface p-5">
      <div className="flex items-baseline justify-between gap-3">
        <h2 className="font-heading text-lg font-extrabold text-text-primary">{ar ? 'أهم التنبيهات المفتوحة' : 'Top open alerts'}</h2>
        <Link to="/agency/alerts" className="text-xs font-bold text-brand-600 hover:underline">
          {ar ? 'كل التنبيهات' : 'All alerts'} {ar ? <ArrowLeft size={12} className="inline" aria-hidden /> : <ArrowRight size={12} className="inline" aria-hidden />}
        </Link>
      </div>
      {alerts.isLoading ? (
        <Skeleton className="mt-3 h-16" />
      ) : alerts.isError ? (
        <p data-testid="agency-alerts-failure" className="mt-3 text-sm text-text-muted">{ar ? 'تعذّرت قراءة التنبيهات.' : 'Alerts could not be read.'}</p>
      ) : topAlerts.length === 0 ? (
        <p data-testid="agency-alerts-empty" className="mt-3 rounded-xl border border-dashed border-border px-4 py-4 text-center text-sm text-text-muted">
          {ar ? 'لا تنبيهات مفتوحة — لم تُطلق أي قاعدة مفعّلة.' : 'No open alerts — no enabled rule has fired.'}
        </p>
      ) : (
        <ul className="mt-3 space-y-2 text-sm">
          {topAlerts.map((a) => (
            <li key={a.id}>
              <Link to={alertPath(a)} data-testid={`agency-alert-${a.id}`} className="flex items-center justify-between gap-3 rounded-xl border border-border px-3.5 py-2.5 transition-colors hover:border-brand-400">
                <span className="flex min-w-0 items-center gap-2">
                  <AlertTriangle size={15} className={`shrink-0 ${a.severity === 'critical' ? 'text-danger' : a.severity === 'warning' ? 'text-warning' : 'text-info'}`} aria-hidden />
                  <span className="truncate font-semibold text-text-primary">{alertHeadline(a.type, ar)}</span>
                </span>
                <Badge tone={a.severity === 'critical' ? 'danger' : a.severity === 'warning' ? 'warning' : 'info'}>
                  {a.severity === 'critical' ? (ar ? 'حرِج' : 'Critical') : a.severity === 'warning' ? (ar ? 'تحذير' : 'Warning') : (ar ? 'معلومة' : 'Info')}
                </Badge>
              </Link>
            </li>
          ))}
        </ul>
      )}
    </section>
  )
}

import { useMemo, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { AlertTriangle, ArrowLeft, ArrowRight } from 'lucide-react'
import { fetchAgencyOverview } from './api'
import { listAlertEvents, type AlertEvent } from '@/features/alerts/api'
import { StatCard, StatGrid } from '@/components/ui/StatCard'
import { DataFreshness } from '@/components/ui/PageIntro'
import { PeriodLabel } from '@/components/patterns/Status'
import { Badge } from '@/components/ui/Badge'
import { Skeleton } from '@/components/ui/States'
import { MetricTable, type SortValues } from '@/components/ui/MetricTable'
import { ChartCard, SpendRevenueAreaChart } from '@/features/analytics/charts'
import { money, num, ratio } from '@/features/analytics/format'
import { formatMoneyReading, readCostPer, readMoney, readRoas, type MoneyTotals } from '@/lib/money/contract'
import { allowsDerived, comparableWindows, coverageNote, readCoverage, windowsUnlikeNote } from '@/lib/coverage/contract'
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

const useOverview = () => useQuery({ queryKey: ['agency', 'overview'], queryFn: () => fetchAgencyOverview(), retry: false })

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
  const derived = current !== undefined && allowsDerived(coverage)
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
  const cpa = current ? readCostPer(current, 'cpa', 'conversions', currency, ar) : null
  const roas = current ? readRoas(current, ar) : null
  const conversions = current ? ((current as Record<string, unknown>).conversions as number | null | undefined) : undefined
  const prevConversions = previous ? ((previous as Record<string, unknown>).conversions as number | null | undefined) : undefined
  const prevSpend = previous ? readMoney(previous, 'spend', currency, ar) : null
  const prevCpa = previous ? readCostPer(previous, 'cpa', 'conversions', currency, ar) : null
  const prevRoas = previous ? readRoas(previous, ar) : null
  const deltaOf = (metric: string, cur: number | null | undefined, prev: number | null | undefined) =>
    comparable && cur != null && prev != null ? { metric, current: cur, previous: prev, since: d.previous_period.from } : undefined
  const notDerived = ar ? 'لا تُشتق على نافذة ناقصة' : 'Not derived over an incomplete window'

  return (
    <section data-testid="agency-headline" className="space-y-3">
      <div className="flex flex-wrap items-center gap-x-4 gap-y-1.5 text-xs text-text-secondary">
        <PeriodLabel from={d.period.from} to={d.period.to} testId="agency-period" />
        <DataFreshness lastSyncAt={d.freshness.last_synced_at} ar={ar} staleAfterHours={STALE_AFTER_HOURS} testid="agency-freshness" />
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
          <StatGrid columns="grid-cols-2 lg:grid-cols-4">
            <Door to="/agency/portfolio">
              <StatCard
                testid="agency-kpi-spend"
                tone="brand"
                label={ar ? 'الإنفاق' : 'Spend'}
                value={<span dir="ltr">{formatMoneyReading(spend!, money)}</span>}
                hint={spend!.note ?? undefined}
                delta={spend!.kind === 'converted' && prevSpend?.kind === 'converted' ? deltaOf('spend', spend!.amount, prevSpend.amount) : undefined}
              />
            </Door>
            <Door to="/agency/campaigns?view=table&lifecycle=all">
              <StatCard
                testid="agency-kpi-results"
                tone="success"
                label={ar ? 'النتائج المُبلَّغة' : 'Reported results'}
                value={<span dir="ltr">{conversions == null ? '—' : num(conversions)}</span>}
                hint={ar ? 'مجموع ما أبلغت به المنصات' : 'Summed across the platforms that reported'}
                delta={deltaOf('conversions', conversions, prevConversions)}
              />
            </Door>
            <Door to="/agency/analytics">
              <StatCard
                testid="agency-kpi-cpa"
                tone="info"
                label={ar ? 'تكلفة النتيجة' : 'Cost per result'}
                value={<span dir="ltr">{derived ? formatMoneyReading(cpa!, money) : '—'}</span>}
                hint={derived ? (cpa!.note ?? undefined) : notDerived}
                delta={derived && cpa!.kind === 'converted' && prevCpa?.kind === 'converted' ? deltaOf('cpa', cpa!.amount, prevCpa.amount) : undefined}
              />
            </Door>
            <Door to="/agency/analytics">
              <StatCard
                testid="agency-kpi-roas"
                tone="neutral"
                label="ROAS"
                value={<span dir="ltr">{derived && roas!.value !== null ? ratio(roas!.value) : '—'}</span>}
                hint={derived ? (roas!.note ?? undefined) : notDerived}
                delta={derived && roas!.value !== null && prevRoas?.value != null ? deltaOf('roas', roas!.value, prevRoas.value) : undefined}
              />
            </Door>
          </StatGrid>
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
  const current = (d?.current ?? undefined) as MoneyTotals | undefined
  const currency = d?.currency ?? null
  const derived = current !== undefined && allowsDerived(readCoverage(current))
  const providerName = (c: string) => providerLabel(c, locale)

  const platformRows = useMemo(() => (d?.by_provider ?? []).map((row) => {
    const t = row as MoneyTotals
    const conv = (row as Record<string, unknown>).conversions as number | null | undefined

    return { provider: row.provider, spend: readMoney(t, 'spend', currency, ar), conversions: conv ?? null, cpa: readCostPer(t, 'cpa', 'conversions', currency, ar), roas: readRoas(t, ar) }
  }), [d?.by_provider, currency, ar])

  if (overview.isLoading) return <div className="grid gap-4 lg:grid-cols-2"><Skeleton className="h-64" /><Skeleton className="h-64" /></div>
  if (overview.isError || !d) return null

  return (
    <div className="grid gap-4 lg:grid-cols-2">
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
        subtitle={ar ? 'من حمل الإنفاق، وبأي تكلفة نتيجة.' : 'Who carried the spend, and at what cost per result.'}
      >
        {platformRows.length === 0
          ? <p data-testid="agency-platforms-empty" className="flex h-[220px] items-center justify-center text-sm text-text-muted">{ar ? 'لا منصة أبلغت في هذه الفترة.' : 'No platform reported in this period.'}</p>
          : (
            <div data-testid="agency-platforms">
              <MetricTable
                head={ar ? ['المنصة', 'الإنفاق', 'النتائج', 'تكلفة النتيجة', 'ROAS'] : ['Platform', 'Spend', 'Results', 'Cost per result', 'ROAS']}
                rows={platformRows.map((r) => [
                  <Link key="p" to={`/agency/content?providers=${r.provider}`} data-testid={`agency-platform-${r.provider}`} className="font-semibold text-text-primary underline decoration-dotted underline-offset-4 hover:text-brand-600">{providerName(r.provider)}</Link>,
                  <span key="s" dir="ltr">{formatMoneyReading(r.spend, money)}</span>,
                  <span key="c" dir="ltr">{r.conversions === null ? '—' : num(r.conversions)}</span>,
                  <span key="a" dir="ltr">{derived ? formatMoneyReading(r.cpa, money) : '—'}</span>,
                  <span key="r" dir="ltr">{derived && r.roas.value !== null ? ratio(r.roas.value) : '—'}</span>,
                ])}
                values={platformRows.map((r): SortValues => [providerName(r.provider), r.spend.amount, r.conversions, r.cpa.amount, r.roas.value])}
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

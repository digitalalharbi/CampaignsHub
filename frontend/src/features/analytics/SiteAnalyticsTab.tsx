import { Link } from 'react-router-dom'
import { getData } from '@/lib/api/client'
import { MetricTable, type SortValues } from '@/components/ui/MetricTable'
import { QueryFailure } from '@/components/ui/QueryFailure'
import { DataFreshness } from '@/components/ui/PageIntro'
import { Badge } from '@/components/ui/Badge'
import { RefreshNote } from '@/components/ui/RefreshNote'
import { ChartBodySkeleton, KpiRowSkeleton } from '@/components/ui/Skeletons'
import { useSwrQuery } from '@/lib/query/swr'
import { ChartCard } from './charts'
import { money, num, percent } from './format'
import { usePortalBase } from '@/app/portalPath'
import { useUi } from '@/stores/ui'
import { SiteKpis, type SiteKpi } from './SiteKpis'
import { ThreeSourcesPanel } from './ThreeSourcesPanel'
import { TrafficExplorer } from './TrafficExplorer'
import { Ga4Workbench } from './ga4/Ga4Workbench'
import { Num } from '@/components/ui/Num'

/**
 * GA4-ANALYTICS-PRODUCT-001 — what the SITE measured, from the project's own GA4 property.
 *
 * A layer of its own (ATTR-LAYER-GA4-001): sessions, engagement, the shopping funnel, where visits
 * came from, which campaigns and landing pages carried them, devices, countries, new versus
 * returning, events — and how much of it was paid traffic, per platform. Nothing here is added to
 * an ad platform's conversions; the Attribution panel sets GA4's view beside them instead.
 *
 * Counts add up within a breakdown; USERS do not (one person arrives twice), so the only users
 * figure is labelled as a sum of daily users.
 */
interface Row { dimension_1: string; dimension_2: string | null; sessions?: number; engaged_sessions?: number; key_events?: number; add_to_carts?: number; checkouts?: number; purchases?: number; revenue?: number; engagement_rate: number | null; conversion_rate: number | null }
interface Table { rows: Row[]; more: number }
interface SitePayload {
  state: 'not_connected' | 'not_selected' | 'empty' | 'ready'
  period: { from: string; to: string }
  property?: { id: string; name: string | null; timezone: string | null }
  last_synced_at?: string | null
  currency?: string | null
  totals?: Record<string, number | null>
  kpis?: SiteKpi[]
  previous_period?: { from: string; to: string }
  trend?: Array<{ date: string; sessions: number; engaged_sessions: number; purchases: number; revenue: number }>
  funnel?: Array<{ stage: string; count: number | null; step_rate: number | null; exceeds_previous: boolean }>
  paid?: { available: boolean; sessions?: number; purchases?: number; revenue?: number; session_share?: number | null; purchase_share?: number | null; revenue_share?: number | null; platforms?: Array<{ platform: string; sessions: number; purchases: number; revenue: number }> }
  breakdowns?: Partial<Record<'source_medium' | 'campaign' | 'landing_page' | 'campaign_landing' | 'device' | 'country' | 'user_type', Table | null>>
  events?: { rows: Array<{ event: string; event_count: number; key_events: number; is_key_event: boolean }>; more: number } | null
}

const USER_TYPE: Record<string, { ar: string; en: string }> = {
  new: { ar: 'جديد', en: 'New' },
  returning: { ar: 'عائد', en: 'Returning' },
  '(not set)': { ar: 'غير محدد', en: 'Not set' },
}

export function SiteAnalyticsTab({ projectId, range }: { projectId: string | null; range: { from: string; to: string } }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const locale = ar ? 'ar' : 'en'
  const portal = usePortalBase()
  // PERF-INSTANT-DATA-001 — scope = the project; the period is the only thing an old answer may bridge.
  const q = useSwrQuery({
    name: 'site-analytics',
    scope: [projectId],
    window: [range.from, range.to],
    queryFn: () => getData<SitePayload>(`/projects/${projectId}/measurement/analytics?from=${range.from}&to=${range.to}`),
    enabled: Boolean(projectId),
  })

  if (q.isLoading) return <SiteSkeleton ar={ar} />
  if (q.isError || !q.data) {
    return <QueryFailure error={q.error} ar={ar} testId="site-failure" onRetry={() => void q.refetch()} fallbackTitle={ar ? 'تعذّرت قراءة تحليلات الموقع' : 'Site analytics could not be read'} />
  }

  const d = q.data
  const note = (
    <RefreshNote ar={ar} isRefreshing={q.isRefreshing} isShowingPrevious={q.isShowingPrevious} refreshFailed={q.refreshFailed}
      updatedAt={q.updatedAt} shownPeriod={`${d.period.from} → ${d.period.to}`} onRetry={() => void q.refetch()} />
  )
  if (d.state !== 'ready') {
    // A short chip and one action — the Owner's rule: no paragraph where a chip says it.
    const chip = {
      not_connected: ar ? 'GA4 غير مربوط' : 'GA4 not connected',
      not_selected: ar ? 'لا خاصية محددة' : 'No property selected',
      empty: ar ? 'بانتظار المزامنة' : 'Awaiting sync',
    }[d.state]

    return (
      <div data-testid={`site-state-${d.state}`} className="flex flex-wrap items-center gap-3 rounded-2xl border border-dashed border-border bg-surface p-5">
        <Badge tone={d.state === 'empty' ? 'info' : 'warning'}>{chip}</Badge>
        {d.state !== 'empty' && (
          <Link to={`${portal}/integrations`} data-testid="site-connect" className="rounded-[10px] bg-brand-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-brand-700">{ar ? 'ربط' : 'Connect'}</Link>
        )}
        {note}
      </div>
    )
  }

  const cur = d.currency ?? ''
  const label = (map: Record<string, { ar: string; en: string }>, v: string) => map[v]?.[locale] ?? v

  return (
    <div data-testid="site-analytics" aria-busy={q.isShowingPrevious} className={`space-y-4 transition-opacity duration-[var(--motion-panel)] ease-[var(--motion-ease)] ${q.isShowingPrevious ? 'opacity-60' : ''}`}>
      <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-text-secondary">
        <Badge tone="info">GA4</Badge>
        <span className="font-semibold text-text-primary">{d.property?.name} <Num className="font-normal text-text-muted">· {d.property?.id}</Num></span>
        <DataFreshness lastSyncAt={d.last_synced_at ?? null} ar={ar} staleAfterHours={36} testid="site-freshness" />
        <Badge tone="neutral" data-testid="site-not-summed">{ar ? 'مستقل عن المنصات' : 'Separate from platforms'}</Badge>
        {note}
      </div>

      {/* GA4-STORE-ANALYTICS-001 — the store reading: tiles, charts, GA4 vs platforms, fixed-size tables. */}
      {projectId && <Ga4Workbench projectId={projectId} range={range} ar={ar} />}

      {d.kpis && d.previous_period && <SiteKpis kpis={d.kpis} previous={d.previous_period} ar={ar} />}

      {projectId && <TrafficExplorer projectId={projectId} range={range} ar={ar} />}
      {projectId && <ThreeSourcesPanel projectId={projectId} range={range} ar={ar} />}

      <div className="grid grid-cols-1 gap-4">
        <BreakdownCard testId="site-user-type" title={ar ? 'جديد مقابل عائد' : 'New vs returning'} table={d.breakdowns?.user_type ?? null} ar={ar} cur={cur} name={(r) => label(USER_TYPE, r.dimension_1)} />
      </div>

      <ChartCard title={ar ? 'الأحداث' : 'Events'} subtitle={ar ? 'الأحداث الرئيسية معلَّمة.' : 'Key events are marked.'}>
        {d.events == null ? (
          <p data-testid="site-events-absent" className="text-sm text-text-muted">{ar ? 'لم تُرجع الخاصية الأحداث لهذه الفترة.' : 'The property returned no events for this period.'}</p>
        ) : (
          <div data-testid="site-events" className="min-w-0">
            <MetricTable
              head={ar ? ['الحدث', 'العدد', 'كحدث رئيسي'] : ['Event', 'Count', 'As key event']}
              rows={d.events.rows.map((e) => [
                <span key="e" className="font-mono text-xs" dir="ltr">{e.event} {e.is_key_event && <Badge tone="success">{ar ? 'رئيسي' : 'Key'}</Badge>}</span>,
                <span key="c" dir="ltr">{num(e.event_count)}</span>,
                <span key="k" dir="ltr">{e.is_key_event ? num(e.key_events) : '—'}</span>,
              ])}
              values={d.events.rows.map((e): SortValues => [e.event, e.event_count, e.key_events])}
              initialSort={{ column: 1, dir: 'desc' }}
            />
          </div>
        )}
      </ChartCard>
    </div>
  )
}

function BreakdownCard({ testId, title, table, ar, cur, name, sub }: { testId: string; title: string; table: Table | null; ar: boolean; cur: string; name: (r: Row) => string; sub?: (r: Row) => string | null }) {
  return (
    <ChartCard title={title}>
      {table === null ? (
        <p data-testid={`${testId}-absent`} className="text-sm text-text-muted">{ar ? 'لم تُرجع الخاصية هذا التقسيم لهذه الفترة.' : 'The property returned no breakdown of this kind for the period.'}</p>
      ) : (
        <div data-testid={testId} className="min-w-0">
          <MetricTable
            head={ar ? ['', 'الجلسات', 'التفاعل', 'المشتريات', 'التحويل', 'الإيراد'] : ['', 'Sessions', 'Engagement', 'Purchases', 'Conversion', 'Revenue']}
            rows={table.rows.map((r) => [
              <span key="n" className="block max-w-[16rem] truncate font-semibold text-text-primary" dir="auto" title={name(r)}>
                {name(r)}{sub && sub(r) ? <span className="block truncate text-[11px] font-normal text-text-muted" dir="ltr">{sub(r)}</span> : null}
              </span>,
              <span key="s" dir="ltr">{num(r.sessions)}</span>,
              <span key="e" dir="ltr">{r.engagement_rate != null ? percent(r.engagement_rate, 0) : '—'}</span>,
              <span key="p" dir="ltr">{num(r.purchases)}</span>,
              <span key="c" dir="ltr">{r.conversion_rate != null ? percent(r.conversion_rate, 2) : '—'}</span>,
              <span key="r" dir="ltr">{r.revenue != null ? money(r.revenue, cur) : '—'}</span>,
            ])}
            values={table.rows.map((r): SortValues => [name(r), r.sessions ?? null, r.engagement_rate, r.purchases ?? null, r.conversion_rate, r.revenue ?? null])}
            initialSort={{ column: 1, dir: 'desc' }}
          />
          {table.more > 0 && <p className="mt-2 text-[11px] text-text-muted">{ar ? `و${num(table.more)} صفًا آخر أقل.` : `And ${num(table.more)} smaller rows.`}</p>}
        </div>
      )}
    </ChartCard>
  )
}

/**
 * The cold open — the page's own parts at their final size, no spinner and no single slab.
 * The KPI row is the real card; the chart cards keep their titles and only the body waits.
 */
function SiteSkeleton({ ar }: { ar: boolean }) {
  return (
    <div data-testid="site-skeleton" aria-busy="true" aria-label={ar ? 'جارٍ تحميل تحليلات الموقع' : 'Loading site analytics'} className="space-y-4">
      <KpiRowSkeleton idPrefix="site-kpi" columns="grid-cols-2 lg:grid-cols-4" labels={ar ? ['الجلسات', 'معدل التفاعل', 'المشتريات', 'الإيراد'] : ['Sessions', 'Engagement rate', 'Purchases', 'Revenue']} />
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <ChartCard className="lg:col-span-2" title={ar ? 'الجلسات والمشتريات يوميًا' : 'Sessions and purchases by day'}>
          <ChartBodySkeleton height={240} testid="site-trend-skeleton" />
        </ChartCard>
        <ChartCard title={ar ? 'قمع التسوق' : 'Shopping funnel'}>
          <ChartBodySkeleton height={240} testid="site-funnel-skeleton" />
        </ChartCard>
      </div>
    </div>
  )
}

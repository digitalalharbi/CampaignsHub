import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { getData } from '@/lib/api/client'
import { StatCard, StatGrid } from '@/components/ui/StatCard'
import { MetricTable, type SortValues } from '@/components/ui/MetricTable'
import { EmptyState, Skeleton } from '@/components/ui/States'
import { QueryFailure } from '@/components/ui/QueryFailure'
import { DataFreshness } from '@/components/ui/PageIntro'
import { Badge } from '@/components/ui/Badge'
import { ChartCard, ConversionFunnelChart, MetricLineChart, RankingBarChart } from './charts'
import { money, num, percent } from './format'
import { providerLabel } from '@/features/campaigns/labels'
import { usePortalBase } from '@/app/portalPath'
import { useUi } from '@/stores/ui'

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
  trend?: Array<{ date: string; sessions: number; engaged_sessions: number; purchases: number; revenue: number }>
  funnel?: Array<{ stage: string; count: number | null; step_rate: number | null; exceeds_previous: boolean }>
  paid?: { available: boolean; sessions?: number; purchases?: number; revenue?: number; session_share?: number | null; purchase_share?: number | null; revenue_share?: number | null; platforms?: Array<{ platform: string; sessions: number; purchases: number; revenue: number }> }
  breakdowns?: Record<'source_medium' | 'campaign' | 'landing_page' | 'device' | 'country' | 'user_type', Table | null>
  events?: { rows: Array<{ event: string; event_count: number; key_events: number; is_key_event: boolean }>; more: number } | null
}

const FUNNEL: Record<string, { ar: string; en: string }> = {
  sessions: { ar: 'الجلسات', en: 'Sessions' },
  add_to_carts: { ar: 'إضافات للسلة', en: 'Add-to-carts' },
  checkouts: { ar: 'بدء الدفع', en: 'Checkouts' },
  purchases: { ar: 'المشتريات', en: 'Purchases' },
}

const USER_TYPE: Record<string, { ar: string; en: string }> = {
  new: { ar: 'جديد', en: 'New' },
  returning: { ar: 'عائد', en: 'Returning' },
  '(not set)': { ar: 'غير محدد', en: 'Not set' },
}

const DEVICE: Record<string, { ar: string; en: string }> = {
  desktop: { ar: 'حاسوب', en: 'Desktop' },
  mobile: { ar: 'جوال', en: 'Mobile' },
  tablet: { ar: 'جهاز لوحي', en: 'Tablet' },
}

export function SiteAnalyticsTab({ projectId, range }: { projectId: string | null; range: { from: string; to: string } }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const locale = ar ? 'ar' : 'en'
  const portal = usePortalBase()
  const q = useQuery({
    queryKey: ['site-analytics', projectId, range.from, range.to],
    queryFn: () => getData<SitePayload>(`/projects/${projectId}/measurement/analytics?from=${range.from}&to=${range.to}`),
    enabled: Boolean(projectId),
  })

  if (q.isLoading) return <Skeleton className="h-96" />
  if (q.isError || !q.data) {
    return <QueryFailure error={q.error} ar={ar} testId="site-failure" onRetry={() => void q.refetch()} fallbackTitle={ar ? 'تعذّرت قراءة تحليلات الموقع' : 'Site analytics could not be read'} />
  }

  const d = q.data
  if (d.state !== 'ready') {
    const text = {
      not_connected: ar ? ['لم يُربط Google Analytics 4 بعد', 'اربط خاصية GA4 لترى الجلسات والقمع ومصادر الزيارات ومساهمة الإعلانات المدفوعة.'] : ['Google Analytics 4 is not connected', 'Connect a GA4 property to see sessions, the funnel, traffic sources and the paid-traffic contribution.'],
      not_selected: ar ? ['لا خاصية GA4 محددة لهذا المشروع', 'اختر خاصية لهذا المشروع من التكاملات.'] : ['No GA4 property is selected for this project', 'Choose a property for this project in Integrations.'],
      empty: ar ? ['لم تصل أرقام من الخاصية لهذه الفترة', 'الخاصية محددة، لكن لا قياسات للفترة — قد تكون المزامنة لم تُشغَّل بعد.'] : ['No figures from the property for this period', 'The property is selected but nothing is measured for the period — the sync may not have run yet.'],
    }[d.state]

    return (
      <div data-testid={`site-state-${d.state}`}>
        <EmptyState title={text[0]} description={text[1]} />
        {d.state !== 'empty' && (
          <p className="mt-3 text-center text-sm"><Link to={`${portal}/integrations`} className="font-bold text-brand-600 hover:underline">{ar ? 'افتح التكاملات' : 'Open Integrations'}</Link></p>
        )}
      </div>
    )
  }

  const t = d.totals ?? {}
  const cur = d.currency ?? ''
  const paid = d.paid ?? { available: false }
  const label = (map: Record<string, { ar: string; en: string }>, v: string) => map[v]?.[locale] ?? v

  return (
    <div data-testid="site-analytics" className="space-y-4">
      <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-text-secondary">
        <Badge tone="info">{ar ? 'ما قاسه الموقع (GA4)' : 'Site-measured (GA4)'}</Badge>
        <span>{d.property?.name} <span dir="ltr">· {d.property?.id}</span></span>
        <DataFreshness lastSyncAt={d.last_synced_at ?? null} ar={ar} staleAfterHours={36} testid="site-freshness" />
        <span>{ar ? 'لا يُجمع مع أرقام المنصات الإعلانية.' : 'Never added to the ad platforms’ figures.'}</span>
      </div>

      <StatGrid columns="grid-cols-2 lg:grid-cols-4">
        <StatCard testid="site-kpi-sessions" tone="brand" label={ar ? 'الجلسات' : 'Sessions'} value={<span dir="ltr">{num(t.sessions)}</span>} hint={t.users_daily_sum != null ? (ar ? `${num(t.users_daily_sum)} مستخدم (مجموع يومي)` : `${num(t.users_daily_sum)} users (sum of daily)`) : undefined} />
        <StatCard testid="site-kpi-engagement" tone="info" label={ar ? 'معدل التفاعل' : 'Engagement rate'} value={<span dir="ltr">{t.engagement_rate != null ? percent(t.engagement_rate, 1) : '—'}</span>} hint={ar ? `${num(t.engaged_sessions)} جلسة متفاعلة` : `${num(t.engaged_sessions)} engaged sessions`} />
        <StatCard testid="site-kpi-purchases" tone="success" label={ar ? 'المشتريات' : 'Purchases'} value={<span dir="ltr">{num(t.purchases)}</span>} hint={ar ? `${num(t.key_events)} حدث رئيسي` : `${num(t.key_events)} key events`} />
        <StatCard testid="site-kpi-revenue" tone="neutral" label={ar ? 'الإيراد' : 'Revenue'} value={<span dir="ltr">{t.revenue != null ? money(t.revenue, cur) : '—'}</span>} hint={paid.available && paid.revenue_share != null ? (ar ? `${percent(paid.revenue_share, 0)} من زيارات مدفوعة` : `${percent(paid.revenue_share, 0)} from paid visits`) : undefined} />
      </StatGrid>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <ChartCard className="lg:col-span-2" title={ar ? 'الجلسات والمشتريات يوميًا' : 'Sessions and purchases by day'} subtitle={ar ? 'يوم بلا قياس لا يُرسم صفرًا.' : 'A day with no measurement is not drawn as zero.'}>
          <div data-testid="site-trend">
            <MetricLineChart data={d.trend ?? []} height={240} series={[
              { key: 'sessions', name: ar ? 'الجلسات' : 'Sessions', kind: 'num' },
              { key: 'purchases', name: ar ? 'المشتريات' : 'Purchases', kind: 'num' },
            ]} rightAxisFor="purchases" />
          </div>
        </ChartCard>
        <ChartCard title={ar ? 'قمع التسوق' : 'Shopping funnel'} subtitle={ar ? 'الإضافات والدفع أحداث، فقد تتجاوز الخطوة السابقة.' : 'Add-to-carts and checkouts are events, so a step can exceed the one before.'}>
          <div data-testid="site-funnel">
            <ConversionFunnelChart ar={ar} stages={(d.funnel ?? []).map((s) => ({ stage: s.stage, label: label(FUNNEL, s.stage), count: s.count, step_rate: s.step_rate, cost_per: null, exceeds_previous: s.exceeds_previous }))} />
          </div>
        </ChartCard>
      </div>

      {paid.available && (
        <ChartCard title={ar ? 'مساهمة الزيارات المدفوعة' : 'Paid-traffic contribution'} subtitle={ar
          ? `الوسيط مدفوع (cpc، paid_social…). ${percent(paid.session_share ?? 0, 0)} من الجلسات و${percent(paid.purchase_share ?? 0, 0)} من المشتريات.`
          : `Medium is paid (cpc, paid_social…). ${percent(paid.session_share ?? 0, 0)} of sessions and ${percent(paid.purchase_share ?? 0, 0)} of purchases.`}>
          <div data-testid="site-paid" className="grid grid-cols-1 gap-4 lg:grid-cols-2">
            {(paid.platforms ?? []).length > 0 && (
              <RankingBarChart horizontal height={Math.max(160, (paid.platforms ?? []).length * 44)}
                data={(paid.platforms ?? []).map((p) => ({ label: p.platform === 'other_paid' ? (ar ? 'مدفوع آخر' : 'Other paid') : providerLabel(p.platform, locale), sessions: p.sessions }))}
                bars={[{ key: 'sessions', name: ar ? 'الجلسات' : 'Sessions', kind: 'num' }]} />
            )}
            <div className="min-w-0">
              <MetricTable
                head={ar ? ['المنصة', 'الجلسات', 'المشتريات', 'الإيراد'] : ['Platform', 'Sessions', 'Purchases', 'Revenue']}
                rows={(paid.platforms ?? []).map((p) => [
                  <span key="p" className="font-semibold">{p.platform === 'other_paid' ? (ar ? 'مدفوع آخر' : 'Other paid') : providerLabel(p.platform, locale)}</span>,
                  <span key="s" dir="ltr">{num(p.sessions)}</span>,
                  <span key="u" dir="ltr">{num(p.purchases)}</span>,
                  <span key="r" dir="ltr">{money(p.revenue, cur)}</span>,
                ])}
                values={(paid.platforms ?? []).map((p): SortValues => [p.platform, p.sessions, p.purchases, p.revenue])}
                initialSort={{ column: 1, dir: 'desc' }}
              />
              <p className="mt-2 text-[11px] text-text-muted">{ar ? 'هذه رؤية GA4 لزيارات كل منصة — قارنها بما أبلغت به المنصة في تبويب جودة البيانات والإسناد.' : 'GA4’s view of each platform’s visits — compare it with what the platform reported under Data quality & attribution.'}</p>
            </div>
          </div>
        </ChartCard>
      )}

      <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
        <BreakdownCard testId="site-source-medium" title={ar ? 'المصدر / الوسيط' : 'Source / medium'} table={d.breakdowns?.source_medium ?? null} ar={ar} cur={cur} name={(r) => `${r.dimension_1} / ${r.dimension_2 ?? ''}`} />
        <BreakdownCard testId="site-campaign" title={ar ? 'الحملات (UTM)' : 'Campaigns (UTM)'} table={d.breakdowns?.campaign ?? null} ar={ar} cur={cur} name={(r) => r.dimension_1} sub={(r) => r.dimension_2} />
        <BreakdownCard testId="site-landing-page" title={ar ? 'صفحات الهبوط' : 'Landing pages'} table={d.breakdowns?.landing_page ?? null} ar={ar} cur={cur} name={(r) => r.dimension_1} />
        <BreakdownCard testId="site-country" title={ar ? 'الدول' : 'Countries'} table={d.breakdowns?.country ?? null} ar={ar} cur={cur} name={(r) => r.dimension_1} />
        <BreakdownCard testId="site-device" title={ar ? 'الأجهزة' : 'Devices'} table={d.breakdowns?.device ?? null} ar={ar} cur={cur} name={(r) => label(DEVICE, r.dimension_1)} />
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

import { useState } from 'react'
import {
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  Legend,
  Line,
  LineChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'
import {
  useBudget,
  useCampaigns,
  useFreshness,
  useFunnel,
  useLastNDaysRange,
  usePlatforms,
  useSummary,
  useTimeseries,
} from './hooks'
import { DemoBadge, KpiCard, Panel, RangeTabs, SERIES, platformColor, tooltipProps } from './components'
import { compact, money, num, percent, ratio } from './format'
import { useUi } from '@/stores/ui'
import { useProject } from '@/stores/project'
import { LivePerformanceNotice } from '@/features/disclaimers/PerformanceNotice'

const TABS = [
  { id: 'performance', ar: 'نظرة عامة على الأداء', en: 'Performance overview' },
  { id: 'platforms', ar: 'تحليل المنصات', en: 'Platform analysis' },
  { id: 'campaigns', ar: 'تحليل الحملات', en: 'Campaign analysis' },
  { id: 'funnel', ar: 'التحويلات والقمع', en: 'Conversions & funnel' },
  { id: 'budget', ar: 'تحليل الميزانية', en: 'Budget analysis' },
  { id: 'quality', ar: 'جودة البيانات والإسناد', en: 'Data quality & attribution' },
] as const

const axis = { stroke: 'var(--text-muted)', fontSize: 12 }

/** The reader's language. Each tab below is its own component, so each one asks. */
const useAr = () => useUi((u) => u.locale) === 'ar'

export function AnalyticsPage() {
  const ar = useAr()
  const { currentProjectId } = useProject()
  const [days, setDays] = useState(30)
  const [tab, setTab] = useState<(typeof TABS)[number]['id']>('performance')
  const range = useLastNDaysRange(days)

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <div className="flex items-center gap-2">
            <h1 className="text-3xl font-extrabold tracking-tight text-text-primary">{ar ? 'التحليلات' : 'Analytics'}</h1>
            <DemoBadge />
          </div>
          <p className="mt-1 text-sm text-text-secondary">{ar ? 'استكشاف تفصيلي وتفاعلي للأداء عبر المنصات والحملات' : 'A detailed, interactive look at performance across platforms and campaigns'}</p>
        </div>
        <RangeTabs value={days} onChange={setDays} />
      </div>

      <div className="flex flex-wrap gap-1.5 border-b border-border">
        {TABS.map((t) => (
          <button
            key={t.id}
            onClick={() => setTab(t.id)}
            className={`relative rounded-t-lg px-3 py-2 text-sm font-semibold transition-colors ${
              tab === t.id ? 'text-brand-600' : 'text-text-secondary hover:text-text-primary'
            }`}
          >
            {ar ? t.ar : t.en}
            {tab === t.id && <span className="absolute inset-x-2 -bottom-px h-0.5 rounded-full bg-brand-600" />}
          </button>
        ))}
      </div>

      {tab === 'performance' && <PerformanceTab projectId={currentProjectId} range={range} />}
      {tab === 'platforms' && <PlatformsTab projectId={currentProjectId} range={range} />}
      {tab === 'campaigns' && <CampaignsTab projectId={currentProjectId} range={range} />}
      {tab === 'funnel' && <FunnelTab projectId={currentProjectId} range={range} />}
      {tab === 'budget' && <BudgetTab projectId={currentProjectId} range={range} />}
      {tab === 'quality' && <QualityTab projectId={currentProjectId} range={range} />}

      <LivePerformanceNotice variant="compact" />
    </div>
  )
}

type TabProps = { projectId: string | null; range: { from: string; to: string } }

function PerformanceTab({ projectId, range }: TabProps) {
  const ar = useAr()
  const s = useSummary(projectId, range)
  const ts = useTimeseries(projectId, range)
  const cur = s.data?.current
  const d = s.data?.delta ?? {}
  const points = ts.data ?? []
  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
        <KpiCard label={ar ? 'الإنفاق' : 'Spend'} value={money(cur?.spend)} delta={d.spend} invertGood />
        <KpiCard label={ar ? 'الإيرادات' : 'Revenue'} value={money(cur?.revenue)} delta={d.revenue} />
        <KpiCard label="ROAS" value={ratio(cur?.roas ?? null)} delta={d.roas} />
        <KpiCard label={ar ? 'النتائج' : 'Results'} value={num(cur?.conversions)} delta={d.conversions} />
        <KpiCard label="CPA" value={money(cur?.cpa)} delta={d.cpa} invertGood />
        <KpiCard label="CTR" value={percent(cur?.ctr, 2)} delta={d.ctr} />
      </div>
      <Panel title={ar ? 'الإنفاق والنتائج والإيرادات' : 'Spend, results and revenue'} description={ar ? 'مقارنة بالفترة السابقة عبر الاتجاه اليومي' : 'Compared with the previous period, day by day'} loading={ts.isLoading} error={ts.isError} empty={!ts.isLoading && points.length === 0}>
        <div className="h-80">
          <ResponsiveContainer width="100%" height="100%">
            <LineChart data={points} margin={{ top: 8, right: 8, left: 8, bottom: 0 }}>
              <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" vertical={false} />
              <XAxis dataKey="date" tick={axis} tickFormatter={(v) => String(v).slice(5)} minTickGap={24} />
              <YAxis tick={axis} tickFormatter={(v) => compact(Number(v))} width={44} />
              <Tooltip {...tooltipProps} formatter={(v: number) => num(v)} />
              <Legend wrapperStyle={{ fontSize: 13 }} />
              <Line name={ar ? 'الإنفاق' : 'Spend'} type="monotone" dataKey="spend" stroke={SERIES.spend} strokeWidth={2} dot={false} />
              <Line name={ar ? 'الإيرادات' : 'Revenue'} type="monotone" dataKey="revenue" stroke={SERIES.revenue} strokeWidth={2} dot={false} />
              <Line name={ar ? 'النتائج' : 'Results'} type="monotone" dataKey="conversions" stroke={SERIES.conversions} strokeWidth={2} dot={false} />
            </LineChart>
          </ResponsiveContainer>
        </div>
      </Panel>
      <Panel title={ar ? 'اتجاه CPA و ROAS و CTR' : 'CPA, ROAS and CTR over time'} loading={ts.isLoading} error={ts.isError} empty={!ts.isLoading && points.length === 0}>
        <div className="h-64">
          <ResponsiveContainer width="100%" height="100%">
            <LineChart data={points} margin={{ top: 8, right: 8, left: 8, bottom: 0 }}>
              <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" vertical={false} />
              <XAxis dataKey="date" tick={axis} tickFormatter={(v) => String(v).slice(5)} minTickGap={24} />
              <YAxis tick={axis} width={44} />
              <Tooltip {...tooltipProps} />
              <Legend wrapperStyle={{ fontSize: 13 }} />
              <Line name="ROAS" type="monotone" dataKey="roas" stroke={SERIES.revenue} strokeWidth={2} dot={false} />
              <Line name="CPA" type="monotone" dataKey="cpa" stroke={SERIES.conversions} strokeWidth={2} dot={false} />
            </LineChart>
          </ResponsiveContainer>
        </div>
      </Panel>
    </div>
  )
}

function PlatformsTab({ projectId, range }: TabProps) {
  const ar = useAr()
  const p = usePlatforms(projectId, range)
  const rows = p.data ?? []
  return (
    <div className="space-y-4">
      <Panel title={ar ? 'مقارنة المنصات' : 'Platform comparison'} description={ar ? 'الإنفاق مقابل ROAS لكل منصة' : 'Spend against ROAS, per platform'} loading={p.isLoading} error={p.isError} empty={!p.isLoading && rows.length === 0}>
        <div className="h-72">
          <ResponsiveContainer width="100%" height="100%">
            <BarChart data={rows} margin={{ top: 8, right: 8, left: 8, bottom: 0 }}>
              <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" vertical={false} />
              <XAxis dataKey="provider" tick={axis} />
              <YAxis yAxisId="l" tick={axis} tickFormatter={(v) => compact(Number(v))} width={44} />
              <YAxis yAxisId="r" orientation="right" tick={axis} width={32} />
              <Tooltip {...tooltipProps} />
              <Legend wrapperStyle={{ fontSize: 13 }} />
              <Bar yAxisId="l" name={ar ? 'الإنفاق' : 'Spend'} dataKey="spend" radius={[6, 6, 0, 0]}>
                {rows.map((r) => (
                  <Cell key={r.provider} fill={platformColor(r.provider)} />
                ))}
              </Bar>
              <Line yAxisId="r" name="ROAS" type="monotone" dataKey="roas" stroke={SERIES.revenue} strokeWidth={2} dot />
            </BarChart>
          </ResponsiveContainer>
        </div>
      </Panel>
      <Panel title={ar ? 'جدول المنصات' : 'Platforms table'} loading={p.isLoading} error={p.isError} empty={!p.isLoading && rows.length === 0}>
        <MetricTable
          head={ar ? ['المنصة', 'الإنفاق', 'النتائج', 'CPA', 'ROAS', 'CTR', 'CPM', 'المساهمة'] : ['Platform', 'Spend', 'Results', 'CPA', 'ROAS', 'CTR', 'CPM', 'Contribution']}
          rows={rows.map((r) => [
            <PlatformCell key="p" provider={r.provider} />,
            money(r.spend),
            num(r.conversions),
            money(r.cpa),
            ratio(r.roas),
            percent(r.ctr, 2),
            money(r.cpm),
            percent(r.spend_share, 1),
          ])}
        />
      </Panel>
    </div>
  )
}

function CampaignsTab({ projectId, range }: TabProps) {
  const ar = useAr()
  const c = useCampaigns(projectId, range)
  const rows = c.data ?? []
  const best = rows[0]
  const worst = [...rows].filter((r) => r.spend > 0).sort((a, b) => (a.roas ?? 0) - (b.roas ?? 0))[0]
  return (
    <div className="space-y-4">
      <div className="grid gap-3 sm:grid-cols-2">
        <Panel title={ar ? 'أفضل حملة (ROAS)' : 'Best campaign (ROAS)'} loading={c.isLoading} error={c.isError}>
          {best && (
            <div>
              <div className="text-lg font-bold text-text-primary">{best.campaign_name}</div>
              <div className="mt-1 text-sm text-text-secondary">
                ROAS <span className="tnum font-semibold text-success">{ratio(best.roas)}</span> · {ar ? 'إنفاق' : 'spend'} {money(best.spend)}
              </div>
            </div>
          )}
        </Panel>
        <Panel title={ar ? 'تحتاج مراجعة (أدنى ROAS)' : 'Needs a look (lowest ROAS)'} loading={c.isLoading} error={c.isError}>
          {worst && (
            <div>
              <div className="text-lg font-bold text-text-primary">{worst.campaign_name}</div>
              <div className="mt-1 text-sm text-text-secondary">
                ROAS <span className="tnum font-semibold text-danger">{ratio(worst.roas)}</span> · {ar ? 'إنفاق' : 'spend'} {money(worst.spend)}
              </div>
            </div>
          )}
        </Panel>
      </div>
      <Panel title={ar ? 'ترتيب الحملات' : 'Campaign ranking'} description={ar ? 'مرتّبة حسب الإنفاق' : 'Ordered by spend'} loading={c.isLoading} error={c.isError} empty={!c.isLoading && rows.length === 0}>
        <MetricTable
          head={ar ? ['الحملة', 'المنصة', 'الإنفاق', 'الإيرادات', 'النتائج', 'CPA', 'ROAS'] : ['Campaign', 'Platform', 'Spend', 'Revenue', 'Results', 'CPA', 'ROAS']}
          rows={rows.map((r) => [
            <span key="n" className="font-semibold text-text-primary">{r.campaign_name ?? '—'}</span>,
            <PlatformCell key="p" provider={r.provider} />,
            money(r.spend),
            money(r.revenue),
            num(r.conversions),
            money(r.cpa),
            <span key="ro" className="tnum font-semibold">{ratio(r.roas)}</span>,
          ])}
        />
      </Panel>
    </div>
  )
}

function FunnelTab({ projectId, range }: TabProps) {
  const ar = useAr()
  const f = useFunnel(projectId, range)
  const rows = f.data ?? []
  const top = rows[0]?.count || 1
  return (
    <Panel title={ar ? 'قمع التحويل' : 'Conversion funnel'} description="Impression → Click → Landing → Add to Cart → Checkout → Purchase" loading={f.isLoading} error={f.isError} empty={!f.isLoading && rows.length === 0}>
      <div className="space-y-3">
        {rows.map((s, i) => (
          <div key={s.stage} className="flex items-center gap-3">
            <span className="w-32 shrink-0 text-sm font-medium text-text-secondary">{s.label}</span>
            <div className="h-10 flex-1 overflow-hidden rounded-xl bg-surface-secondary">
              <div
                className="flex h-full items-center justify-between rounded-xl px-3 text-sm font-semibold text-white"
                style={{ width: `${Math.max(8, (s.count / top) * 100)}%`, background: `color-mix(in oklab, ${SERIES.spend} ${100 - i * 10}%, var(--brand-700))` }}
              >
                <span className="tnum">{num(s.count)}</span>
              </div>
            </div>
            <div className="w-40 shrink-0 text-end text-xs text-text-muted">
              {s.step_rate !== null && <span>{ar ? 'انتقال' : 'step'} {percent(s.step_rate, 0)}</span>}
              {s.cost_per !== null && <span className="ms-2">{ar ? 'تكلفة' : 'cost'} {money(s.cost_per)}</span>}
            </div>
          </div>
        ))}
      </div>
    </Panel>
  )
}

function BudgetTab({ projectId, range }: TabProps) {
  const ar = useAr()
  const b = useBudget(projectId, range)
  const rows = b.data ?? []
  return (
    <Panel title={ar ? 'تحليل الميزانية' : 'Budget analysis'} description={ar ? 'المخطط مقابل المصروف وسرعة الصرف (Pacing)' : 'Planned against spent, and how fast it is going (pacing)'} loading={b.isLoading} error={b.isError} empty={!b.isLoading && rows.length === 0}>
      <MetricTable
        head={ar ? ['الحملة', 'الميزانية', 'المصروف', 'المتبقي', 'الاستهلاك', 'السرعة', 'المتوقع'] : ['Campaign', 'Budget', 'Spent', 'Remaining', 'Consumed', 'Pace', 'Projected']}
        rows={rows.map((r) => [
          <span key="n" className="font-semibold text-text-primary">{r.campaign_name}</span>,
          money(r.budget),
          money(r.spent),
          money(r.remaining),
          <div key="c" className="flex items-center gap-2">
            <div className="h-1.5 w-16 overflow-hidden rounded-full bg-surface-secondary">
              <div className="h-full rounded-full bg-brand-500" style={{ width: `${Math.min(100, (r.consumed_pct ?? 0) * 100)}%` }} />
            </div>
            <span className="tnum text-xs">{percent(r.consumed_pct, 0)}</span>
          </div>,
          <span key="p" className={`tnum font-semibold ${(r.pace ?? 0) > 1.2 ? 'text-danger' : (r.pace ?? 0) < 0.8 ? 'text-warning' : 'text-success'}`}>
            {ratio(r.pace, '×')}
          </span>,
          money(r.projected_spend),
        ])}
      />
    </Panel>
  )
}

function QualityTab({ projectId, range }: TabProps) {
  const ar = useAr()
  const f = useFreshness(projectId, range)
  const rows = f.data ?? []
  return (
    <Panel title={ar ? 'جودة البيانات والإسناد' : 'Data quality & attribution'} description={ar ? 'آخر مزامنة، حداثة البيانات، والأيام الناقصة لكل منصة' : 'Last sync, how fresh the data is, and the missing days per platform'} loading={f.isLoading} error={f.isError} empty={!f.isLoading && rows.length === 0}>
      <MetricTable
        head={ar ? ['المنصة', 'آخر تاريخ', 'آخر مزامنة', 'أيام ببيانات', 'أيام ناقصة', 'الحالة'] : ['Platform', 'Latest date', 'Last sync', 'Days with data', 'Missing days', 'Status']}
        rows={rows.map((r) => [
          <PlatformCell key="p" provider={r.provider} />,
          r.latest_metric_date ?? '—',
          r.last_sync_at ? new Date(r.last_sync_at).toLocaleString('en-GB') : '—',
          num(r.days_with_data),
          r.missing_days > 0 ? <span key="m" className="font-semibold text-warning">{r.missing_days}</span> : '0',
          <span
            key="s"
            className={`rounded-full px-2 py-0.5 text-xs font-semibold ${
              r.last_sync_status === 'failed'
                ? 'bg-[var(--negative-background)] text-danger'
                : r.last_sync_status === 'partial'
                  ? 'bg-[var(--warning-background)] text-warning'
                  : 'bg-[var(--positive-background)] text-success'
            }`}
          >
            {r.last_sync_status ?? '—'}
          </span>,
        ])}
      />
      <p className="mt-3 text-xs text-text-muted">{ar ? 'لا يتم جمع Reach عبر المنصات كوصول فريد — يُعرض لكل منصة على حدة.' : 'Reach is not summed across platforms as unique reach — it is shown per platform.'}</p>
    </Panel>
  )
}

function PlatformCell({ provider }: { provider: string }) {
  return (
    <span className="inline-flex items-center gap-1.5 font-semibold text-text-primary">
      <span className="h-2.5 w-2.5 rounded-full" style={{ background: platformColor(provider) }} />
      {provider}
    </span>
  )
}

function MetricTable({ head, rows }: { head: string[]; rows: React.ReactNode[][] }) {
  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-[640px] text-sm">
        <thead>
          <tr className="border-b border-border text-text-muted">
            {head.map((h, i) => (
              <th key={i} className={`py-2 font-semibold ${i === 0 ? 'text-start' : 'text-end'}`}>
                {h}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((r, i) => (
            <tr key={i} className="border-b border-border last:border-0 hover:bg-surface-secondary">
              {r.map((cell, j) => (
                <td key={j} className={`py-2.5 ${j === 0 ? 'text-start' : 'tnum text-end'}`}>
                  {cell}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

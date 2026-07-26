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
import { useProject } from '@/stores/project'
import { LivePerformanceNotice } from '@/features/disclaimers/PerformanceNotice'

const TABS = [
  { id: 'performance', label: 'نظرة عامة على الأداء' },
  { id: 'platforms', label: 'تحليل المنصات' },
  { id: 'campaigns', label: 'تحليل الحملات' },
  { id: 'funnel', label: 'التحويلات والقمع' },
  { id: 'budget', label: 'تحليل الميزانية' },
  { id: 'quality', label: 'جودة البيانات والإسناد' },
] as const

const axis = { stroke: 'var(--text-muted)', fontSize: 12 }

export function AnalyticsPage() {
  const { currentProjectId } = useProject()
  const [days, setDays] = useState(30)
  const [tab, setTab] = useState<(typeof TABS)[number]['id']>('performance')
  const range = useLastNDaysRange(days)

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <div className="flex items-center gap-2">
            <h1 className="text-3xl font-extrabold tracking-tight text-text-primary">التحليلات</h1>
            <DemoBadge />
          </div>
          <p className="mt-1 text-sm text-text-secondary">استكشاف تفصيلي وتفاعلي للأداء عبر المنصات والحملات</p>
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
            {t.label}
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
  const s = useSummary(projectId, range)
  const ts = useTimeseries(projectId, range)
  const cur = s.data?.current
  const d = s.data?.delta ?? {}
  const points = ts.data ?? []
  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
        <KpiCard label="الإنفاق" value={money(cur?.spend)} delta={d.spend} invertGood />
        <KpiCard label="الإيرادات" value={money(cur?.revenue)} delta={d.revenue} />
        <KpiCard label="ROAS" value={ratio(cur?.roas ?? null)} delta={d.roas} />
        <KpiCard label="النتائج" value={num(cur?.conversions)} delta={d.conversions} />
        <KpiCard label="CPA" value={money(cur?.cpa)} delta={d.cpa} invertGood />
        <KpiCard label="CTR" value={percent(cur?.ctr, 2)} delta={d.ctr} />
      </div>
      <Panel title="الإنفاق والنتائج والإيرادات" description="مقارنة بالفترة السابقة عبر الاتجاه اليومي" loading={ts.isLoading} error={ts.isError} empty={!ts.isLoading && points.length === 0}>
        <div className="h-80">
          <ResponsiveContainer width="100%" height="100%">
            <LineChart data={points} margin={{ top: 8, right: 8, left: 8, bottom: 0 }}>
              <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" vertical={false} />
              <XAxis dataKey="date" tick={axis} tickFormatter={(v) => String(v).slice(5)} minTickGap={24} />
              <YAxis tick={axis} tickFormatter={(v) => compact(Number(v))} width={44} />
              <Tooltip {...tooltipProps} formatter={(v: number) => num(v)} />
              <Legend wrapperStyle={{ fontSize: 13 }} />
              <Line name="الإنفاق" type="monotone" dataKey="spend" stroke={SERIES.spend} strokeWidth={2} dot={false} />
              <Line name="الإيرادات" type="monotone" dataKey="revenue" stroke={SERIES.revenue} strokeWidth={2} dot={false} />
              <Line name="النتائج" type="monotone" dataKey="conversions" stroke={SERIES.conversions} strokeWidth={2} dot={false} />
            </LineChart>
          </ResponsiveContainer>
        </div>
      </Panel>
      <Panel title="اتجاه CPA و ROAS و CTR" loading={ts.isLoading} error={ts.isError} empty={!ts.isLoading && points.length === 0}>
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
  const p = usePlatforms(projectId, range)
  const rows = p.data ?? []
  return (
    <div className="space-y-4">
      <Panel title="مقارنة المنصات" description="الإنفاق مقابل ROAS لكل منصة" loading={p.isLoading} error={p.isError} empty={!p.isLoading && rows.length === 0}>
        <div className="h-72">
          <ResponsiveContainer width="100%" height="100%">
            <BarChart data={rows} margin={{ top: 8, right: 8, left: 8, bottom: 0 }}>
              <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" vertical={false} />
              <XAxis dataKey="provider" tick={axis} />
              <YAxis yAxisId="l" tick={axis} tickFormatter={(v) => compact(Number(v))} width={44} />
              <YAxis yAxisId="r" orientation="right" tick={axis} width={32} />
              <Tooltip {...tooltipProps} />
              <Legend wrapperStyle={{ fontSize: 13 }} />
              <Bar yAxisId="l" name="الإنفاق" dataKey="spend" radius={[6, 6, 0, 0]}>
                {rows.map((r) => (
                  <Cell key={r.provider} fill={platformColor(r.provider)} />
                ))}
              </Bar>
              <Line yAxisId="r" name="ROAS" type="monotone" dataKey="roas" stroke={SERIES.revenue} strokeWidth={2} dot />
            </BarChart>
          </ResponsiveContainer>
        </div>
      </Panel>
      <Panel title="جدول المنصات" loading={p.isLoading} error={p.isError} empty={!p.isLoading && rows.length === 0}>
        <MetricTable
          head={['المنصة', 'الإنفاق', 'النتائج', 'CPA', 'ROAS', 'CTR', 'CPM', 'المساهمة']}
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
  const c = useCampaigns(projectId, range)
  const rows = c.data ?? []
  const best = rows[0]
  const worst = [...rows].filter((r) => r.spend > 0).sort((a, b) => (a.roas ?? 0) - (b.roas ?? 0))[0]
  return (
    <div className="space-y-4">
      <div className="grid gap-3 sm:grid-cols-2">
        <Panel title="أفضل حملة (ROAS)" loading={c.isLoading} error={c.isError}>
          {best && (
            <div>
              <div className="text-lg font-bold text-text-primary">{best.campaign_name}</div>
              <div className="mt-1 text-sm text-text-secondary">
                ROAS <span className="tnum font-semibold text-success">{ratio(best.roas)}</span> · إنفاق {money(best.spend)}
              </div>
            </div>
          )}
        </Panel>
        <Panel title="تحتاج مراجعة (أدنى ROAS)" loading={c.isLoading} error={c.isError}>
          {worst && (
            <div>
              <div className="text-lg font-bold text-text-primary">{worst.campaign_name}</div>
              <div className="mt-1 text-sm text-text-secondary">
                ROAS <span className="tnum font-semibold text-danger">{ratio(worst.roas)}</span> · إنفاق {money(worst.spend)}
              </div>
            </div>
          )}
        </Panel>
      </div>
      <Panel title="ترتيب الحملات" description="مرتّبة حسب الإنفاق" loading={c.isLoading} error={c.isError} empty={!c.isLoading && rows.length === 0}>
        <MetricTable
          head={['الحملة', 'المنصة', 'الإنفاق', 'الإيرادات', 'النتائج', 'CPA', 'ROAS']}
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
  const f = useFunnel(projectId, range)
  const rows = f.data ?? []
  const top = rows[0]?.count || 1
  return (
    <Panel title="قمع التحويل" description="Impression → Click → Landing → Add to Cart → Checkout → Purchase" loading={f.isLoading} error={f.isError} empty={!f.isLoading && rows.length === 0}>
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
              {s.step_rate !== null && <span>انتقال {percent(s.step_rate, 0)}</span>}
              {s.cost_per !== null && <span className="ms-2">تكلفة {money(s.cost_per)}</span>}
            </div>
          </div>
        ))}
      </div>
    </Panel>
  )
}

function BudgetTab({ projectId, range }: TabProps) {
  const b = useBudget(projectId, range)
  const rows = b.data ?? []
  return (
    <Panel title="تحليل الميزانية" description="المخطط مقابل المصروف وسرعة الصرف (Pacing)" loading={b.isLoading} error={b.isError} empty={!b.isLoading && rows.length === 0}>
      <MetricTable
        head={['الحملة', 'الميزانية', 'المصروف', 'المتبقي', 'الاستهلاك', 'السرعة', 'المتوقع']}
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
  const f = useFreshness(projectId, range)
  const rows = f.data ?? []
  return (
    <Panel title="جودة البيانات والإسناد" description="آخر مزامنة، حداثة البيانات، والأيام الناقصة لكل منصة" loading={f.isLoading} error={f.isError} empty={!f.isLoading && rows.length === 0}>
      <MetricTable
        head={['المنصة', 'آخر تاريخ', 'آخر مزامنة', 'أيام ببيانات', 'أيام ناقصة', 'الحالة']}
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
      <p className="mt-3 text-xs text-text-muted">لا يتم جمع Reach عبر المنصات كوصول فريد — يُعرض لكل منصة على حدة.</p>
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

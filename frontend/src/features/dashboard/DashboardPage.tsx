import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import {
  Area,
  AreaChart,
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  Legend,
  Line,
  Pie,
  PieChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'
import { AlertTriangle, ArrowUpRight, Plug } from 'lucide-react'
import {
  useBudget,
  useCampaigns,
  useFreshness,
  useFunnel,
  useLastNDaysRange,
  usePlatforms,
  useSummary,
  useTimeseries,
} from '../analytics/hooks'
import {
  DemoBadge,
  KpiCard,
  Panel,
  RangeTabs,
  SERIES,
  platformColor,
  tooltipProps,
} from '../analytics/components'
import { compact, money, num, percent, ratio } from '../analytics/format'
import { useProject } from '@/stores/project'
import { LivePerformanceNotice } from '@/features/disclaimers/PerformanceNotice'

const axis = { stroke: 'var(--text-muted)', fontSize: 12 }

export function DashboardPage() {
  const { currentProjectId } = useProject()
  const [days, setDays] = useState(30)
  const range = useLastNDaysRange(days)

  const summary = useSummary(currentProjectId, range)
  const series = useTimeseries(currentProjectId, range)
  const platforms = usePlatforms(currentProjectId, range)
  const campaigns = useCampaigns(currentProjectId, range)
  const funnel = useFunnel(currentProjectId, range)
  const budget = useBudget(currentProjectId, range)
  const freshness = useFreshness(currentProjectId, range)

  const cur = summary.data?.current
  const delta = summary.data?.delta ?? {}
  const points = series.data ?? []
  const spark = (k: 'spend' | 'revenue' | 'conversions' | 'roas' | 'ctr' | 'cpa') =>
    points.map((p) => Number(p[k] ?? 0))

  const alerts = useMemo(() => {
    const out: { kind: 'sync' | 'budget' | 'performance'; text: string }[] = []
    freshness.data?.forEach((f) => {
      if (f.last_sync_status === 'failed') out.push({ kind: 'sync', text: `فشل مزامنة ${f.provider} — يتطلب إعادة ربط` })
    })
    budget.data?.forEach((b) => {
      if ((b.pace ?? 0) > 1.4) out.push({ kind: 'budget', text: `${b.campaign_name}: استهلاك أسرع من المخطط (${ratio(b.pace ?? 0, '×')})` })
    })
    campaigns.data?.forEach((c) => {
      if (c.spend > 3000 && c.conversions < 2) out.push({ kind: 'performance', text: `${c.campaign_name}: إنفاق مرتفع دون تحويلات` })
    })
    return out.slice(0, 4)
  }, [freshness.data, budget.data, campaigns.data])

  const lastSync = freshness.data?.map((f) => f.last_sync_at).filter(Boolean).sort().at(-1)
  const donut = (platforms.data ?? []).map((p) => ({ name: p.provider, value: p.spend }))
  const loadingAny = summary.isLoading

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <div className="flex items-center gap-2">
            <h1 className="text-3xl font-extrabold tracking-tight text-text-primary">لوحة التحكم</h1>
            <DemoBadge />
          </div>
          <p className="mt-1 text-sm text-text-secondary">
            ملخص تنفيذي للأداء · آخر مزامنة {lastSync ? new Date(lastSync).toLocaleString('en-GB') : '—'}
          </p>
        </div>
        <div className="flex items-center gap-2">
          <RangeTabs value={days} onChange={setDays} />
          <Link
            to="/analytics"
            className="inline-flex h-10 items-center gap-1.5 rounded-xl border border-border-strong bg-surface px-3 text-sm font-semibold text-text-primary hover:bg-surface-hover"
          >
            التحليلات <ArrowUpRight size={16} />
          </Link>
        </div>
      </div>

      {/* Alerts */}
      {alerts.length > 0 && (
        <div className="grid gap-2 sm:grid-cols-2">
          {alerts.map((a, i) => (
            <div
              key={i}
              className="flex items-center gap-2 rounded-xl border border-border bg-[var(--warning-background)] px-3 py-2 text-sm text-text-primary"
            >
              <AlertTriangle size={16} className="shrink-0 text-warning" />
              <span className="truncate">{a.text}</span>
            </div>
          ))}
        </div>
      )}

      {/* KPI cards */}
      <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
        <KpiCard label="الإنفاق" value={money(cur?.spend)} delta={delta.spend} invertGood spark={spark('spend')} accent={SERIES.spend} hint="إجمالي الإنفاق المعياري بعملة المشروع" />
        <KpiCard label="الإيرادات" value={money(cur?.revenue)} delta={delta.revenue} spark={spark('revenue')} accent={SERIES.revenue} />
        <KpiCard label="ROAS" value={ratio(cur?.roas ?? null)} delta={delta.roas} spark={spark('roas')} accent={SERIES.revenue} hint="الإيرادات ÷ الإنفاق" />
        <KpiCard label="النتائج" value={num(cur?.conversions)} delta={delta.conversions} spark={spark('conversions')} accent={SERIES.conversions} />
        <KpiCard label="CPA" value={money(cur?.cpa)} delta={delta.cpa} invertGood spark={spark('cpa')} accent={SERIES.conversions} hint="تكلفة النتيجة = الإنفاق ÷ النتائج" />
        <KpiCard label="CTR" value={percent(cur?.ctr, 2)} delta={delta.ctr} spark={spark('ctr')} accent={SERIES.clicks} hint="النقر ÷ الظهور" />
      </div>

      {/* Performance + spend distribution */}
      <div className="grid gap-4 lg:grid-cols-3">
        <Panel title="الإنفاق مقابل الإيرادات" description="الاتجاه اليومي خلال الفترة" className="lg:col-span-2" loading={series.isLoading} error={series.isError} empty={!series.isLoading && points.length === 0}>
          <div className="h-72">
            <ResponsiveContainer width="100%" height="100%">
              <AreaChart data={points} margin={{ top: 8, right: 8, left: 8, bottom: 0 }}>
                <defs>
                  <linearGradient id="gSpend" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stopColor={SERIES.spend} stopOpacity={0.28} />
                    <stop offset="100%" stopColor={SERIES.spend} stopOpacity={0} />
                  </linearGradient>
                  <linearGradient id="gRev" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stopColor={SERIES.revenue} stopOpacity={0.28} />
                    <stop offset="100%" stopColor={SERIES.revenue} stopOpacity={0} />
                  </linearGradient>
                </defs>
                <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" vertical={false} />
                <XAxis dataKey="date" tick={axis} tickFormatter={(d) => String(d).slice(5)} minTickGap={24} />
                <YAxis tick={axis} tickFormatter={(v) => compact(Number(v))} width={44} />
                <Tooltip {...tooltipProps} formatter={(v: number) => num(v)} />
                <Legend wrapperStyle={{ fontSize: 13 }} />
                <Area name="الإنفاق" type="monotone" dataKey="spend" stroke={SERIES.spend} strokeWidth={2} fill="url(#gSpend)" isAnimationActive={false} />
                <Area name="الإيرادات" type="monotone" dataKey="revenue" stroke={SERIES.revenue} strokeWidth={2} fill="url(#gRev)" isAnimationActive={false} />
              </AreaChart>
            </ResponsiveContainer>
          </div>
        </Panel>

        <Panel title="توزيع الإنفاق" description="حسب المنصة" loading={platforms.isLoading} error={platforms.isError} empty={!platforms.isLoading && donut.length === 0}>
          <div className="h-72">
            <ResponsiveContainer width="100%" height="100%">
              <PieChart>
                <Pie data={donut} dataKey="value" nameKey="name" innerRadius={58} outerRadius={92} paddingAngle={2}>
                  {donut.map((d) => (
                    <Cell key={d.name} fill={platformColor(d.name)} stroke="var(--surface)" strokeWidth={2} />
                  ))}
                </Pie>
                <Tooltip {...tooltipProps} formatter={(v: number) => money(v)} />
                <Legend wrapperStyle={{ fontSize: 13 }} />
              </PieChart>
            </ResponsiveContainer>
          </div>
        </Panel>
      </div>

      {/* Platform comparison + funnel */}
      <div className="grid gap-4 lg:grid-cols-2">
        <Panel title="مقارنة المنصات" description="الإنفاق و ROAS" loading={platforms.isLoading} error={platforms.isError} empty={!platforms.isLoading && (platforms.data?.length ?? 0) === 0}>
          <div className="h-64">
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={platforms.data ?? []} margin={{ top: 8, right: 8, left: 8, bottom: 0 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" vertical={false} />
                <XAxis dataKey="provider" tick={axis} />
                <YAxis yAxisId="l" tick={axis} tickFormatter={(v) => compact(Number(v))} width={44} />
                <YAxis yAxisId="r" orientation="right" tick={axis} width={32} />
                <Tooltip {...tooltipProps} />
                <Legend wrapperStyle={{ fontSize: 13 }} />
                <Bar yAxisId="l" name="الإنفاق" dataKey="spend" radius={[6, 6, 0, 0]} fill={SERIES.spend} />
                <Line yAxisId="r" name="ROAS" type="monotone" dataKey="roas" stroke={SERIES.revenue} strokeWidth={2} dot={false} />
              </BarChart>
            </ResponsiveContainer>
          </div>
        </Panel>

        <Panel title="قمع التحويل" description="من الظهور إلى الشراء" loading={funnel.isLoading} error={funnel.isError} empty={!funnel.isLoading && (funnel.data?.length ?? 0) === 0}>
          <div className="space-y-2">
            {(funnel.data ?? []).map((s, i) => {
              const top = funnel.data?.[0]?.count || 1
              const w = Math.max(6, (s.count / top) * 100)
              return (
                <div key={s.stage} className="flex items-center gap-3">
                  <span className="w-28 shrink-0 text-sm text-text-secondary">{s.label}</span>
                  <div className="h-8 flex-1 overflow-hidden rounded-lg bg-surface-secondary">
                    <div
                      className="flex h-full items-center justify-end rounded-lg px-2 text-xs font-semibold text-white"
                      style={{ width: `${w}%`, background: `color-mix(in oklab, ${SERIES.spend} ${100 - i * 12}%, var(--brand-700))` }}
                    >
                      <span className="tnum">{compact(s.count)}</span>
                    </div>
                  </div>
                  <span className="tnum w-12 text-end text-xs text-text-muted">{s.step_rate === null ? '' : percent(s.step_rate, 0)}</span>
                </div>
              )
            })}
          </div>
        </Panel>
      </div>

      {/* Top campaigns + freshness */}
      <div className="grid gap-4 lg:grid-cols-3">
        <Panel title="أفضل الحملات" description="حسب الإيرادات و ROAS" className="lg:col-span-2" loading={campaigns.isLoading} error={campaigns.isError} empty={!campaigns.isLoading && (campaigns.data?.length ?? 0) === 0}>
          <div className="overflow-x-auto">
            <table className="w-full min-w-[520px] text-sm">
              <thead>
                <tr className="border-b border-border text-start text-text-muted">
                  <th className="py-2 text-start font-semibold">الحملة</th>
                  <th className="py-2 text-start font-semibold">المنصة</th>
                  <th className="py-2 text-end font-semibold">الإنفاق</th>
                  <th className="py-2 text-end font-semibold">الإيرادات</th>
                  <th className="py-2 text-end font-semibold">ROAS</th>
                  <th className="py-2 text-end font-semibold">CPA</th>
                </tr>
              </thead>
              <tbody>
                {(campaigns.data ?? []).slice(0, 6).map((c) => (
                  <tr key={c.campaign_id} className="border-b border-border last:border-0">
                    <td className="py-2.5 pe-2 font-semibold text-text-primary">{c.campaign_name ?? '—'}</td>
                    <td className="py-2.5">
                      <span className="inline-flex items-center gap-1.5 text-text-secondary">
                        <span className="h-2 w-2 rounded-full" style={{ background: platformColor(c.provider) }} />
                        {c.provider}
                      </span>
                    </td>
                    <td className="tnum py-2.5 text-end">{money(c.spend)}</td>
                    <td className="tnum py-2.5 text-end">{money(c.revenue)}</td>
                    <td className="tnum py-2.5 text-end font-semibold">{ratio(c.roas)}</td>
                    <td className="tnum py-2.5 text-end">{money(c.cpa)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Panel>

        <Panel title="حالة المصادر" description="آخر مزامنة وحداثة البيانات" loading={freshness.isLoading} error={freshness.isError} empty={!freshness.isLoading && (freshness.data?.length ?? 0) === 0}>
          <div className="space-y-2">
            {(freshness.data ?? []).map((f) => (
              <div key={f.provider} className="flex items-center justify-between rounded-xl border border-border bg-surface-secondary px-3 py-2">
                <span className="flex items-center gap-2 text-sm font-semibold">
                  <Plug size={15} style={{ color: platformColor(f.provider) }} />
                  {f.provider}
                </span>
                <span
                  className={`rounded-full px-2 py-0.5 text-xs font-semibold ${
                    f.last_sync_status === 'failed'
                      ? 'bg-[var(--negative-background)] text-danger'
                      : f.last_sync_status === 'partial'
                        ? 'bg-[var(--warning-background)] text-warning'
                        : 'bg-[var(--positive-background)] text-success'
                  }`}
                >
                  {f.last_sync_status ?? '—'}
                </span>
              </div>
            ))}
            {loadingAny && null}
          </div>
        </Panel>
      </div>

      <LivePerformanceNotice variant="compact" />
    </div>
  )
}

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
  useAttribution,
  useNormalization,
  usePlatforms,
  useSummary,
  useTimeseries,
} from './hooks'
import { DemoBadge, KpiCard, Panel, RangeTabs, SERIES, platformColor, tooltipProps } from './components'
import { compact, money, num, percent, ratio } from './format'
import { funnelStageLabel } from './metricLabels'
import { useUi } from '@/stores/ui'
import { useProject } from '@/stores/project'
import { LivePerformanceNotice } from '@/features/disclaimers/PerformanceNotice'
import { StoreFunnelTab } from './StoreFunnelTab'
import { AttributionPanel } from './AttributionPanel'

const TABS = [
  { id: 'performance', ar: 'نظرة عامة على الأداء', en: 'Performance overview' },
  { id: 'platforms', ar: 'تحليل المنصات', en: 'Platform analysis' },
  { id: 'campaigns', ar: 'تحليل الحملات', en: 'Campaign analysis' },
  { id: 'funnel', ar: 'التحويلات والقمع', en: 'Conversions & funnel' },
  // FUNNEL-001 — the ad funnel and the STORE funnel in one place, with the source of every number.
  { id: 'store', ar: 'الفانل والمتجر', en: 'Funnel & store' },
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
      {tab === 'store' && <StoreFunnelTab projectId={currentProjectId} range={range} />}
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
      {/*
       * REPORT-OBJECTIVE-005 — «النتائج» above is the SUM of what each platform claimed.
       *
       * One sale clicked from two platforms is reported in full by both, and there is no shared key
       * that would prove they are the same sale — so the figure is not a count of unique orders, and
       * it must not be read as one. The sentence comes from the API rather than being written here, so
       * the dashboard, the report and the client's link cannot end up saying different things about
       * the same number. Shown only when more than one platform contributed: a single platform cannot
       * overlap with itself, and a warning about an impossible risk trains readers to ignore warnings.
       */}
      {s.data?.conversions_basis.may_double_count && (
        <p
          data-testid="conversions-basis"
          className="rounded-lg border border-border bg-surface-secondary px-3 py-2 text-xs text-text-secondary"
        >
          <span className="font-semibold text-text-primary">{ar ? '«النتائج»: ' : 'Results: '}</span>
          {ar ? s.data.conversions_basis.note_ar : s.data.conversions_basis.note_en}
        </p>
      )}
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
  /*
   * FUNNEL-NULL-001 — scaled against the largest REPORTED count, not `rows[0].count`.
   *
   * A stage nobody sent has a null count, and `null / top` is 0 in JavaScript: the old line drew the
   * 8% minimum-width bar with «—» written inside it, so «this platform does not count basket adds»
   * and «almost nobody added to a basket» were the same picture. An unreported stage now gets no bar
   * and says so in words instead.
   */
  const reported = rows.map((s) => s.count).filter((c): c is number => c !== null)
  const top = reported.length > 0 ? Math.max(...reported) : 1
  const unreported = rows.filter((s) => !s.reported)
  return (
    <Panel title={ar ? 'قمع التحويل' : 'Conversion funnel'} description={ar ? 'الظهور ← النقرة ← صفحة الهبوط ← السلة ← الدفع ← الشراء' : 'Impression → Click → Landing → Add to cart → Checkout → Purchase'} loading={f.isLoading} error={f.isError} empty={!f.isLoading && rows.length === 0}>
      <div className="space-y-3">
        {rows.map((s, i) => (
          <div key={s.stage} className="flex items-center gap-3" data-testid={`ad-funnel-stage-${s.stage}`}>
            <span className="w-32 shrink-0 text-sm font-medium text-text-secondary">{funnelStageLabel(s.stage, s.label, ar)}</span>
            {s.count !== null ? (
              <div className="h-10 flex-1 overflow-hidden rounded-xl bg-surface-secondary">
                <div
                  className="flex h-full items-center justify-between rounded-xl px-3 text-sm font-semibold text-white"
                  style={{ width: `${Math.max(8, (s.count / top) * 100)}%`, background: `color-mix(in oklab, ${SERIES.spend} ${100 - i * 10}%, var(--brand-700))` }}
                >
                  <span className="tnum">{num(s.count)}</span>
                </div>
              </div>
            ) : (
              <div className="flex h-10 flex-1 items-center rounded-xl border border-dashed border-border px-3 text-xs text-text-muted" data-testid={`ad-funnel-unreported-${s.stage}`}>
                {ar ? 'لم ترسل المنصة هذه المرحلة' : 'This stage was never reported'}
              </div>
            )}
            <div className="w-40 shrink-0 text-end text-xs text-text-muted">
              {s.step_rate !== null && <span>{ar ? 'انتقال' : 'step'} {percent(s.step_rate, 0)}</span>}
              {s.cost_per !== null && <span className="ms-2">{ar ? 'تكلفة' : 'cost'} {money(s.cost_per)}</span>}
            </div>
          </div>
        ))}
      </div>
      {unreported.length > 0 && (
        // Named beneath the chart as well as drawn, because a reader who scans the bars needs to be
        // told once, in a sentence, that the gaps are the platform's silence and not their results.
        <p className="mt-4 text-xs text-text-muted" data-testid="ad-funnel-unreported-note">
          {ar
            ? `لم ترسل أي منصة هذه المراحل في هذه الفترة: ${unreported.map((s) => funnelStageLabel(s.stage, s.label, true)).join('، ')}. الفراغ ليس صفرًا.`
            : `No platform reported these stages in this period: ${unreported.map((s) => funnelStageLabel(s.stage, s.label, false)).join(', ')}. A gap is not a zero.`}
        </p>
      )}
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
    <div>
      <Panel title={ar ? 'جودة البيانات والإسناد' : 'Data quality & attribution'} description={ar ? 'آخر مزامنة، حداثة البيانات، والأيام الناقصة لكل منصة' : 'Last sync, how fresh the data is, and the missing days per platform'} loading={f.isLoading} error={f.isError} empty={!f.isLoading && rows.length === 0}>
      <MetricTable
        head={ar ? ['المنصة', 'آخر تاريخ', 'آخر مزامنة', 'أيام ببيانات', 'أيام ناقصة', 'الحالة'] : ['Platform', 'Latest date', 'Last sync', 'Days with data', 'Missing days', 'Status']}
        rows={rows.map((r) => [
          <PlatformCell key="p" provider={r.provider} />,
          r.latest_metric_date ?? '—',
          r.last_sync_at ? new Date(r.last_sync_at).toLocaleString('en-GB') : '—',
          num(r.days_with_data),
          r.missing_days === null ? '—'
            : r.missing_days > 0 ? <span key="m" className="font-semibold text-warning">{r.missing_days}</span> : '0',
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
      <NormalizationPanel projectId={projectId} range={range} />
      {/*
       * REPORT-OBJECTIVE-005 — on this tab because it is literally «جودة البيانات والإسناد», and
       * because a reader who has just been told which currency and window produced a figure is the
       * reader who needs to be told which SYSTEM produced it.
       */}
      <AttributionSection projectId={projectId} range={range} />
    </div>
  )
}

function AttributionSection({ projectId, range }: TabProps) {
  const locale = useUi((u) => u.locale)
  const a = useAttribution(projectId, range)

  return (
    <AttributionPanel
      data={a.data}
      loading={a.isLoading}
      error={a.isError}
      locale={locale}
      className="mt-4"
    />
  )
}

/**
 * NORM-001 — the basis of every figure on this page.
 *
 * The normalisation layer has always existed: each `daily_metrics` row records the currency it arrived
 * in, the one it was converted to and the rate used, the platform's timezone and the project's, the
 * attribution window that counted its conversions, and whether it came from an API or from demo data.
 * None of it was ever shown. Spend appeared converted with nothing saying a conversion had happened,
 * and the API announced «SAR» as a constant.
 *
 * The point of this panel is the difference between a figure and a figure's basis. Two campaigns whose
 * conversions were counted under different attribution windows are not comparable, and a dashboard
 * that puts them side by side without saying so is not wrong in its arithmetic — it is wrong in what
 * the reader will conclude. So each row states what is ACTUALLY in the range, and the awkward cases
 * (a second currency, a second attribution window, demo rows among real ones) are called out rather
 * than resolved quietly.
 */
function NormalizationPanel({ projectId, range }: TabProps) {
  const ar = useAr()
  const n = useNormalization(projectId, range)
  const d = n.data

  const converted = (d?.currencies ?? []).filter((c) => c.converted)
  const shifted = (d?.timezones ?? []).filter((t) => t.shifted)
  const windows = d?.attribution_windows ?? []
  const demoRows = (d?.sources ?? []).filter((s) => s.is_demo).reduce((sum, s) => sum + s.rows, 0)
  const objectives = d?.objectives

  return (
    <Panel
      title={ar ? 'أساس الأرقام' : 'How these numbers were produced'}
      description={ar
        ? 'العملة والمنطقة الزمنية ونافذة الإسناد ومصدر كل رقم قبل عرضه'
        : 'The currency, timezone, attribution window and source behind every figure above'}
      loading={n.isLoading}
      error={n.isError}
      className="mt-4"
    >
      <div data-testid="normalization" className="grid gap-3 text-sm">
        {/* Currency. Silence here is a claim: a converted figure that says nothing reads as native. */}
        <Basis label={ar ? 'العملة' : 'Currency'}>
          {converted.length > 0
            ? converted.map((c) => (
                <span key={`${c.from}-${c.to}`} className="block">
                  {ar
                    ? `حُوّل ${num(c.rows)} صفًا من ${c.from} إلى ${c.to}${c.rate_min !== null ? ` بسعر ${c.rate_min === c.rate_max ? c.rate_min : `${c.rate_min}–${c.rate_max}`}` : ''}`
                    : `${num(c.rows)} rows converted from ${c.from} to ${c.to}${c.rate_min !== null ? ` at ${c.rate_min === c.rate_max ? c.rate_min : `${c.rate_min}–${c.rate_max}`}` : ''}`}
                </span>
              ))
            : d?.project_currency
              ? (ar ? `كل المبالغ بعملة ${d.project_currency} أصلًا — لم يُجرَ أي تحويل.` : `Every amount was already in ${d.project_currency}. Nothing was converted.`)
              : (ar ? 'لا توجد مبالغ مالية في هذه الفترة.' : 'There are no money figures in this period.')}
          {(d?.project_currencies.length ?? 0) > 1 && (
            <span className="mt-1 block font-semibold text-warning">
              {ar
                ? `هذه الفترة تحتوي أكثر من عملة عرض (${d?.project_currencies.join(' · ')}) — لا تُجمع المبالغ كرقم واحد.`
                : `This period holds more than one display currency (${d?.project_currencies.join(' · ')}) — the amounts are not one total.`}
            </span>
          )}
        </Basis>

        {/* Timezone — what "a day" means. A row dated by the platform's midnight is not the project's. */}
        <Basis label={ar ? 'حدود اليوم' : 'Where a day starts'}>
          {shifted.length > 0
            ? shifted.map((t) => (
                <span key={`${t.from}-${t.to}`} className="block">
                  {ar
                    ? `تُجمع الأيام بتوقيت ${t.to}، والمنصة تُبلّغ بتوقيت ${t.from}.`
                    : `Days are counted in ${t.to}; the platform reports in ${t.from}.`}
                </span>
              ))
            : (ar ? 'المنصة والمشروع على التوقيت نفسه.' : 'The platform and the project keep the same clock.')}
        </Basis>

        {/* Attribution — more than one window in a range is a comparability defect, not a detail. */}
        <Basis label={ar ? 'نافذة الإسناد' : 'Attribution window'}>
          {windows.length === 0
            ? (ar ? 'لا توجد بيانات في هذه الفترة.' : 'There is no data in this period.')
            : windows.map((w) => (
                <span key={w.window} className="block">
                  <code className="rounded bg-surface-secondary px-1.5 py-0.5 text-[12px]">{w.window}</code>
                  <span className="ms-2 text-text-muted">{ar ? `${num(w.rows)} صف` : `${num(w.rows)} rows`}</span>
                </span>
              ))}
          {windows.length > 1 && (
            <span className="mt-1 block font-semibold text-warning">
              {ar
                ? 'أكثر من نافذة إسناد في الفترة نفسها — التحويلات هنا لا تُقارن مباشرة.'
                : 'More than one attribution window in the same period — these conversions are not directly comparable.'}
            </span>
          )}
        </Basis>

        {/* Objective comparability: name the metrics that survive, rather than allow or refuse silently. */}
        {objectives && objectives.present.length > 0 && (
          <Basis label={ar ? 'المقارنة بين الأهداف' : 'Comparing across objectives'}>
            {objectives.mixed ? (
              <>
                <span className="block">
                  {ar
                    ? `الفترة تضم ${num(objectives.present.length)} أهداف مختلفة. ما يقارن بينها: ${objectives.comparable_metrics.join('، ')}.`
                    : `This period spans ${num(objectives.present.length)} different objectives. Comparable across all of them: ${objectives.comparable_metrics.join(', ')}.`}
                </span>
                <span className="mt-1 block text-text-muted">
                  {ar
                    ? 'أما التحويلات وتكلفتها فتعني حدثًا مختلفًا في كل هدف، فلا تُجمع ولا تُقارن.'
                    : 'Conversions and their costs count a different event under each objective, so they are neither summed nor compared.'}
                </span>
              </>
            ) : (
              <span>
                {ar
                  ? `كل الحملات في هذه الفترة لهدف واحد (${objectives.present[0]?.objective}) — كل المؤشرات قابلة للمقارنة.`
                  : `Every campaign in this period shares one objective (${objectives.present[0]?.objective}), so every metric compares.`}
              </span>
            )}
          </Basis>
        )}

        {/* Demo rows are labelled here too, not only by a badge in the corner of the page. */}
        <Basis label={ar ? 'المصدر' : 'Source'}>
          {(d?.sources ?? []).length === 0
            ? (ar ? 'لا توجد بيانات في هذه الفترة.' : 'There is no data in this period.')
            : (d?.sources ?? []).map((s) => (
                <span key={`${s.source_type}-${String(s.is_demo)}`} className="block">
                  {ar
                    ? `${num(s.rows)} صف — ${s.is_demo ? 'بيانات تجريبية' : sourceLabel(s.source_type, true)}`
                    : `${num(s.rows)} rows — ${s.is_demo ? 'demo data' : sourceLabel(s.source_type, false)}`}
                </span>
              ))}
          {demoRows > 0 && (
            <span className="mt-1 block font-semibold text-warning">
              {ar
                ? `${num(demoRows)} صفًا من هذه الأرقام بيانات تجريبية، لا نتائج حقيقية.`
                : `${num(demoRows)} of these rows are demo data, not real results.`}
            </span>
          )}
        </Basis>

        {/* Stored but read by nothing. An empty answer is stated, never left as an empty space. */}
        <Basis label={ar ? 'مقاييس غير محسوبة' : 'Metrics nothing reads'}>
          {(d?.unread_metric_keys.length ?? 0) === 0
            ? (ar ? 'كل مقياس في بياناتك يدخل في مؤشر واحد على الأقل.' : 'Every metric key in your data feeds at least one KPI.')
            : (ar
                ? `مخزّنة ولا يقرؤها أي مؤشر: ${d?.unread_metric_keys.join('، ')}.`
                : `Stored but read by no KPI: ${d?.unread_metric_keys.join(', ')}.`)}
        </Basis>

        {/* The catalogue: what a metric means and whether it may be summed at all. */}
        {d?.catalogue.available && (
          <details className="rounded-xl border border-border bg-surface-secondary px-4 py-3">
            <summary className="cursor-pointer text-sm font-semibold text-text-primary">
              {ar ? `تعريفات المقاييس (${num(d.catalogue.metrics.length)})` : `Metric definitions (${num(d.catalogue.metrics.length)})`}
            </summary>
            <p className="mt-2 text-xs text-text-muted">
              {ar
                ? 'المقاييس القابلة للجمع تُجمع عبر الأيام والحملات؛ أما النسب فتُحسب من مجاميعها في كل مرة ولا تُجمع أبدًا — مجموع تكلفة النقرة عبر ثلاثين يومًا ليس تكلفة النقرة للشهر.'
                : 'Additive metrics are summed across days and campaigns; ratios are recomputed from their base sums every time and never summed — adding up thirty daily CPCs does not give you the month’s CPC.'}
            </p>
            <div className="mt-3 grid gap-1.5 sm:grid-cols-2">
              {d.catalogue.metrics.map((m) => (
                <div key={m.key} className="flex items-baseline justify-between gap-2 text-xs">
                  <span className="font-semibold text-text-primary">{m.name}</span>
                  <span className={m.is_additive ? 'text-text-muted' : 'font-semibold text-brand-600'}>
                    {m.is_additive ? (ar ? 'قابل للجمع' : 'additive') : (ar ? 'يُعاد حسابه' : 'recomputed')}
                  </span>
                </div>
              ))}
            </div>
          </details>
        )}
      </div>
    </Panel>
  )
}

/** `api | manual | estimated | modeled` — column values, given words. */
function sourceLabel(source: string, ar: boolean): string {
  const table: Record<string, { ar: string; en: string }> = {
    api: { ar: 'مسحوبة من المنصة', en: 'pulled from the platform' },
    manual: { ar: 'مُدخلة يدويًا', en: 'entered by hand' },
    estimated: { ar: 'مقدَّرة', en: 'estimated' },
    modeled: { ar: 'محسوبة بنموذج', en: 'modelled' },
  }

  return table[source] ? (ar ? table[source].ar : table[source].en) : source
}

function Basis({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="grid gap-0.5 border-b border-border pb-3 last:border-0 last:pb-0 sm:grid-cols-[180px_1fr] sm:gap-4">
      <span className="text-xs font-bold uppercase tracking-wide text-text-muted">{label}</span>
      <div className="text-text-secondary">{children}</div>
    </div>
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

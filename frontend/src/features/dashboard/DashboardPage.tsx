import { useEffect, useMemo, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import {
  Area,
  AreaChart,
  CartesianGrid,
  Legend,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'
import { ArrowUpRight } from 'lucide-react'
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
import { DemoBadge, Panel, RangeTabs, SERIES, tooltipProps } from '../analytics/components'
import { compact, money, num, percent, ratio } from '../analytics/format'
import { UnifiedCampaignOverview, providerColor, providerName, type OverviewKpi, type OverviewVM } from '@/features/campaigns/overview/UnifiedCampaignOverview'
import type { MetricTotals } from '../analytics/api'
import { SavedViewsBar } from './SavedViewsBar'
import { useSavedViews, type SavedView } from './savedViews'

/** The six paid platforms CampaignsHub unifies — the dashboard platform filter. */
const PLATFORM_KEYS = ['meta', 'google_ads', 'tiktok', 'snapchat', 'x', 'linkedin']

/** DASH-010-D: campaign objectives (keys match the CampaignObjective enum) for the objective filter/KPIs. */
const OBJECTIVES: { key: string; label: string }[] = [
  { key: 'all', label: 'كل الأهداف' },
  { key: 'awareness', label: 'الوعي' },
  { key: 'traffic', label: 'الزيارات' },
  { key: 'leads', label: 'العملاء المحتملون' },
  { key: 'sales', label: 'المبيعات' },
  { key: 'app_installs', label: 'التطبيقات' },
  { key: 'engagement', label: 'التفاعل' },
]

/**
 * The KPI set shown for the selected objective — the RIGHT metrics per objective (never ROAS for everything).
 * "all" (mixed objectives) shows a correct shared set. Values come from the expanded, normalized summary.
 */
function objectiveKpis(objective: string, cur: MetricTotals | undefined, activeCount: number): OverviewKpi[] {
  const active: OverviewKpi = { key: 'active', label: 'حملات نشطة', value: num(activeCount) }
  const spend: OverviewKpi = { key: 'spend', label: 'الإنفاق', value: money(cur?.spend) }
  switch (objective) {
    case 'awareness':
      return [{ key: 'reach', label: 'الوصول', value: num(cur?.reach) }, { key: 'impr', label: 'الظهور', value: num(cur?.impressions) }, { key: 'freq', label: 'التكرار', value: ratio(cur?.frequency ?? null) }, { key: 'cpm', label: 'CPM', value: money(cur?.cpm) }, { key: 'vv', label: 'مشاهدات الفيديو', value: num(cur?.video_views) }, spend]
    case 'traffic':
      return [{ key: 'clicks', label: 'النقرات', value: num(cur?.clicks) }, { key: 'lpv', label: 'مشاهدات الصفحة', value: num(cur?.landing_page_views) }, { key: 'ctr', label: 'CTR', value: percent(cur?.ctr, 2) }, { key: 'cpc', label: 'CPC', value: money(cur?.cpc) }, spend, active]
    case 'leads':
      return [{ key: 'leads', label: 'العملاء المحتملون', value: num(cur?.leads) }, { key: 'ql', label: 'المؤهلون', value: num(cur?.qualified_leads) }, { key: 'cpl', label: 'CPL', value: money(cur?.cpl) }, { key: 'cvr', label: 'معدل التحويل', value: percent(cur?.conversion_rate, 2) }, spend, active]
    case 'sales':
      return [{ key: 'purch', label: 'المشتريات', value: num(cur?.purchases) }, { key: 'rev', label: 'الإيرادات', value: money(cur?.revenue) }, { key: 'cpa', label: 'تكلفة النتيجة', value: money(cur?.cpa) }, { key: 'roas', label: 'ROAS', value: ratio(cur?.roas ?? null), tone: 'good' }, { key: 'aov', label: 'متوسط قيمة الطلب', value: money(cur?.aov) }, spend]
    case 'app_installs':
      return [{ key: 'inst', label: 'التثبيتات', value: num(cur?.installs) }, { key: 'cpi', label: 'CPI', value: money(cur?.cpi) }, { key: 'reg', label: 'التسجيلات', value: num(cur?.registrations) }, { key: 'iae', label: 'أحداث داخل التطبيق', value: num(cur?.in_app_events) }, spend, active]
    case 'engagement':
      return [{ key: 'eng', label: 'التفاعلات', value: num(cur?.engagements) }, { key: 'er', label: 'معدل التفاعل', value: percent(cur?.engagement_rate, 2) }, { key: 'cpe', label: 'CPE', value: money(cur?.cpe) }, { key: 'vv', label: 'مشاهدات الفيديو', value: num(cur?.video_views) }, spend, active]
    default:
      // "all"/mixed objectives → operational, objective-NEUTRAL metrics only. NEVER a blended ROAS/CPA/CPL
      // across incompatible objectives (that would be misleading — different objectives, different success).
      return [{ key: 'spend', label: 'إجمالي الإنفاق', value: money(cur?.spend), hint: 'إجمالي الإنفاق عبر كل الأهداف' }, { key: 'campaigns', label: 'عدد الحملات', value: num(activeCount) }, { key: 'impr', label: 'الظهور', value: num(cur?.impressions) }, { key: 'clicks', label: 'النقرات', value: num(cur?.clicks) }]
  }
}
import { useProject } from '@/stores/project'
import { LivePerformanceNotice } from '@/features/disclaimers/PerformanceNotice'

const axis = { stroke: 'var(--text-muted)', fontSize: 13 }

export function DashboardPage() {
  const { currentProjectId } = useProject()
  const [days, setDays] = useState(30)
  const range = useLastNDaysRange(days)
  const [providers, setProviders] = useState<string[]>([])
  // Default objective = Awareness (never "all" with blended KPIs); a saved default view can override it.
  const [objective, setObjective] = useState('awareness')
  const filters = useMemo(() => ({ provider: providers, objective: objective === 'all' ? [] : [objective] }), [providers, objective])
  const toggleProvider = (key: string) =>
    setProviders((prev) => (prev.includes(key) ? prev.filter((p) => p !== key) : [...prev, key]))

  // Saved views (DASH-010-E-FE): apply restores objective + platforms + date range; a default applies once on load.
  const savedViews = useSavedViews()
  const applyView = (v: SavedView) => {
    if (v.filters?.objective) setObjective(v.filters.objective)
    if (v.filters?.provider) setProviders(v.filters.provider)
    if (v.date_range?.days) setDays(v.date_range.days)
  }
  const appliedDefault = useRef(false)
  useEffect(() => {
    if (appliedDefault.current) return
    const def = savedViews.data?.find((v) => v.is_default)
    if (def) {
      appliedDefault.current = true
      applyView(def)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [savedViews.data])

  const summary = useSummary(currentProjectId, range, filters)
  const series = useTimeseries(currentProjectId, range, filters)
  const platforms = usePlatforms(currentProjectId, range, filters)
  const campaigns = useCampaigns(currentProjectId, range, filters)
  const funnel = useFunnel(currentProjectId, range, filters)
  const budget = useBudget(currentProjectId, range, filters)
  const freshness = useFreshness(currentProjectId, range, filters)

  const cur = summary.data?.current
  const points = series.data ?? []

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

  // Map the real analytics data to the shared command-center view-model — the SAME component the marketing
  // homepage preview uses (there fed labeled demo data). Data is currently seeded/demo → dataStatus 'demo'.
  const vm: OverviewVM = useMemo(
    () => ({
      currency: 'SAR',
      dataStatus: 'demo',
      lastSyncAt: lastSync ?? null,
      kpis: objectiveKpis(objective, cur, campaigns.data?.length ?? 0),
      platforms: (platforms.data ?? []).map((p) => ({
        key: p.provider,
        name: p.provider,
        spend: p.spend,
        results: 0,
        roas: p.roas ?? null,
      })),
      spend: (platforms.data ?? []).map((p) => ({ name: p.provider, value: p.spend })),
      topCampaigns: (campaigns.data ?? []).slice(0, 6).map((c) => ({
        id: String(c.campaign_id),
        name: c.campaign_name ?? '—',
        provider: c.provider,
        spend: c.spend,
        results: c.conversions,
        cpa: c.cpa ?? null,
        roas: c.roas ?? null,
      })),
      needsAttention: (campaigns.data ?? [])
        .filter((c) => c.spend > 3000 && c.conversions < 2)
        .slice(0, 4)
        .map((c) => ({ id: String(c.campaign_id), name: c.campaign_name ?? '—', reason: 'إنفاق مرتفع دون تحويلات' })),
      alerts: alerts.map((a) => ({ severity: a.kind === 'budget' ? ('medium' as const) : ('high' as const), text: a.text })),
    }),
    [cur, campaigns.data, platforms.data, alerts, lastSync, objective],
  )

  const objLabel = OBJECTIVES.find((o) => o.key === objective)?.label ?? ''
  const pageTitle = objective === 'all' ? 'لوحة التحكم — نظرة تشغيلية' : `لوحة أداء حملات ${objLabel}`

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <div className="flex items-center gap-2">
            <h1 className="text-[32px] font-extrabold leading-tight tracking-tight text-text-primary sm:text-[34px]">{pageTitle}</h1>
            <DemoBadge />
          </div>
          <p className="mt-1.5 text-base text-text-secondary">مركز قيادة موحّد لكل حملاتك الإعلانية المدفوعة عبر المنصات.</p>
        </div>
        <div className="flex items-center gap-2">
          <RangeTabs value={days} onChange={setDays} />
          <Link
            to="/campaigns"
            className="inline-flex h-10 items-center gap-1.5 rounded-xl border border-border-strong bg-surface px-3 text-sm font-semibold text-text-primary hover:bg-surface-hover"
          >
            الحملات <ArrowUpRight size={16} />
          </Link>
        </div>
      </div>

      {/* Saved views — server-persisted (DASH-010-E); save/apply/rename/default/delete the current filters. */}
      <SavedViewsBar current={{ objective, providers, days }} onApply={applyView} />

      {/* Objective filter — switches the KPI set AND filters all tiles by campaign objective (backend-supported). */}
      <div className="flex flex-wrap items-center gap-2">
        <span className="text-sm font-semibold text-text-muted">الهدف:</span>
        {OBJECTIVES.map((o) => {
          const on = objective === o.key
          return (
            <button
              key={o.key}
              type="button"
              onClick={() => setObjective(o.key)}
              aria-pressed={on}
              className={`rounded-full border px-2.5 py-1 text-sm font-semibold transition-colors ${on ? 'border-primary bg-primary/10 text-primary' : 'border-border bg-surface text-text-secondary hover:bg-surface-hover'}`}
            >
              {o.label}
            </button>
          )
        })}
      </div>

      {objective === 'all' && (
        <div className="rounded-xl border border-border bg-[var(--warning-background)] px-3 py-2 text-[13px] text-text-secondary">
          تعرض هذه النظرة مؤشرات تشغيلية مشتركة فقط؛ اختر هدفًا محددًا لعرض مؤشرات الأداء المتخصصة.
        </div>
      )}

      {/* Platform filter — backend-supported (?provider=…); affects every KPI, chart, table below. */}
      <div className="flex flex-wrap items-center gap-2">
        <span className="text-sm font-semibold text-text-muted">المنصات:</span>
        {PLATFORM_KEYS.map((key) => {
          const on = providers.includes(key)
          return (
            <button
              key={key}
              type="button"
              onClick={() => toggleProvider(key)}
              aria-pressed={on}
              className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-sm font-semibold transition-colors ${on ? 'border-primary bg-primary/10 text-primary' : 'border-border bg-surface text-text-secondary hover:bg-surface-hover'}`}
            >
              <span className="h-2 w-2 rounded-full" style={{ background: providerColor(key) }} />
              {providerName(key)}
            </button>
          )
        })}
        {providers.length > 0 && (
          <button type="button" onClick={() => setProviders([])} className="text-sm font-semibold text-text-muted underline underline-offset-2 hover:text-text-primary">
            إعادة ضبط
          </button>
        )}
      </div>

      {/* Unified command center (shared with the marketing preview) */}
      <UnifiedCampaignOverview
        vm={vm}
        headerRight={
          <Link to="/analytics" className="inline-flex items-center gap-1 font-semibold text-text-secondary hover:text-text-primary">
            التحليلات <ArrowUpRight size={14} />
          </Link>
        }
      />

      {/* Deeper detail: daily trend + conversion funnel */}
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
                      className="flex h-full items-center justify-end rounded-lg px-2 text-sm font-semibold text-white"
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

      <LivePerformanceNotice variant="compact" />
    </div>
  )
}

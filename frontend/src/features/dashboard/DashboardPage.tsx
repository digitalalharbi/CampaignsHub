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
import { ArrowUpRight, SlidersHorizontal } from 'lucide-react'
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
import { OBJECTIVE_KEYS, dash, metricLabel, objectiveLabel } from '@/features/analytics/metricLabels'
import { Modal } from '@/components/ui/Modal'
import { useUi } from '@/stores/ui'
import { sortPlatforms } from '@/lib/platforms'

/**
 * The six paid platforms CampaignsHub unifies — the dashboard platform filter.
 *
 * Ordered by the product's own order rather than by this file's opinion (PLATFORM-ORDER-001). The
 * keys keep their local spelling (`google_ads`) because that is what the API filter expects;
 * `sortPlatforms` canonicalises before it compares.
 */
const PLATFORM_KEYS = sortPlatforms(['meta', 'google_ads', 'tiktok', 'snapchat', 'x', 'linkedin'])

/*
 * DASH-010-D: campaign objectives (keys match the CampaignObjective enum) for the filter and KPIs.
 *
 * The labels come from the shared bilingual vocabulary (APP-100). They used to be Arabic string
 * literals here, which is why switching the product to English flipped `dir` to `ltr` and left the
 * whole dashboard in Arabic — an interface that changes direction while its content does not reads
 * as broken rather than as unfinished.
 */
const objectives = (ar: boolean) => OBJECTIVE_KEYS.map((key) => ({ key, label: objectiveLabel(key, ar) }))

/**
 * The KPI set shown for the selected objective — the RIGHT metrics per objective (never ROAS for everything).
 * "all" (mixed objectives) shows a correct shared set. Values come from the expanded, normalized summary.
 */
function objectiveKpis(objective: string, cur: MetricTotals | undefined, activeCount: number, ar: boolean): OverviewKpi[] {
  const m = (key: string) => metricLabel(key, ar)
  const active: OverviewKpi = { key: 'active', label: m('active'), value: num(activeCount) }
  const spend: OverviewKpi = { key: 'spend', label: m('spend'), value: money(cur?.spend) }
  switch (objective) {
    case 'awareness':
      return [{ key: 'reach', label: m('reach'), value: num(cur?.reach) }, { key: 'impr', label: m('impressions'), value: num(cur?.impressions) }, { key: 'freq', label: m('frequency'), value: ratio(cur?.frequency ?? null) }, { key: 'cpm', label: 'CPM', value: money(cur?.cpm) }, { key: 'vv', label: m('video_views'), value: num(cur?.video_views) }, spend]
    case 'traffic':
      return [{ key: 'clicks', label: m('clicks'), value: num(cur?.clicks) }, { key: 'lpv', label: m('landing_page_views'), value: num(cur?.landing_page_views) }, { key: 'ctr', label: 'CTR', value: percent(cur?.ctr, 2) }, { key: 'cpc', label: 'CPC', value: money(cur?.cpc) }, spend, active]
    case 'leads':
      return [{ key: 'leads', label: m('leads'), value: num(cur?.leads) }, { key: 'ql', label: m('qualified_leads'), value: num(cur?.qualified_leads) }, { key: 'cpl', label: 'CPL', value: money(cur?.cpl) }, { key: 'cvr', label: m('conversion_rate'), value: percent(cur?.conversion_rate, 2) }, spend, active]
    case 'sales':
      return [{ key: 'purch', label: m('purchases'), value: num(cur?.purchases) }, { key: 'rev', label: m('revenue'), value: money(cur?.revenue) }, { key: 'cpa', label: m('cost_per_result'), value: money(cur?.cpa) }, { key: 'roas', label: 'ROAS', value: ratio(cur?.roas ?? null), tone: 'good' }, { key: 'aov', label: m('aov'), value: money(cur?.aov) }, spend]
    case 'app_installs':
      return [{ key: 'inst', label: m('installs'), value: num(cur?.installs) }, { key: 'cpi', label: 'CPI', value: money(cur?.cpi) }, { key: 'reg', label: m('registrations'), value: num(cur?.registrations) }, { key: 'iae', label: m('in_app_events'), value: num(cur?.in_app_events) }, spend, active]
    case 'engagement':
      return [{ key: 'eng', label: m('engagements'), value: num(cur?.engagements) }, { key: 'er', label: m('engagement_rate'), value: percent(cur?.engagement_rate, 2) }, { key: 'cpe', label: 'CPE', value: money(cur?.cpe) }, { key: 'vv', label: m('video_views'), value: num(cur?.video_views) }, spend, active]
    default:
      // "all"/mixed objectives → operational, objective-NEUTRAL metrics only. NEVER a blended ROAS/CPA/CPL
      // across incompatible objectives (that would be misleading — different objectives, different success).
      return [{ key: 'spend', label: m('spend_total'), value: money(cur?.spend), hint: dash('totalSpendHint', ar) }, { key: 'campaigns', label: m('campaigns'), value: num(activeCount) }, { key: 'impr', label: m('impressions'), value: num(cur?.impressions) }, { key: 'clicks', label: m('clicks'), value: num(cur?.clicks) }]
  }
}
import { useProject } from '@/stores/project'
import { LivePerformanceNotice } from '@/features/disclaimers/PerformanceNotice'

const axis = { stroke: 'var(--text-muted)', fontSize: 12 }

export function DashboardPage() {
  // The reader's language, threaded through every label on the page (APP-100). Without it the
  // dashboard rendered Arabic under `dir="ltr"` — the direction changed and the content did not.
  const ar = useUi((s) => s.locale) === 'ar'
  const { currentProjectId } = useProject()
  const [days, setDays] = useState(30)
  const range = useLastNDaysRange(days)
  const [providers, setProviders] = useState<string[]>([])
  // Default objective = Awareness (never "all" with blended KPIs); a saved default view can override it.
  const [objective, setObjective] = useState('awareness')
  // The filters live in a dialog now; the page leads with answers. See the note by the button.
  const [customising, setCustomising] = useState(false)
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
      if (f.last_sync_status === 'failed') out.push({ kind: 'sync', text: ar ? `فشل مزامنة ${f.provider} — يتطلب إعادة ربط` : `${f.provider} sync failed — needs reconnecting` })
    })
    budget.data?.forEach((b) => {
      if ((b.pace ?? 0) > 1.4) out.push({ kind: 'budget', text: `${b.campaign_name}: ${dash('pacingAhead', ar)} (${ratio(b.pace ?? 0, '×')})` })
    })
    campaigns.data?.forEach((c) => {
      if (c.spend > 3000 && c.conversions < 2) out.push({ kind: 'performance', text: `${c.campaign_name}: ${dash('spendNoConversions', ar)}` })
    })
    return out.slice(0, 4)
  }, [freshness.data, budget.data, campaigns.data, ar])

  const lastSync = freshness.data?.map((f) => f.last_sync_at).filter(Boolean).sort().at(-1)

  // Map the real analytics data to the shared command-center view-model — the SAME component the marketing
  // homepage preview uses (there fed labeled demo data). Data is currently seeded/demo → dataStatus 'demo'.
  const vm: OverviewVM = useMemo(
    () => ({
      currency: 'SAR',
      dataStatus: 'demo',
      lastSyncAt: lastSync ?? null,
      kpis: objectiveKpis(objective, cur, campaigns.data?.length ?? 0, ar),
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
        .map((c) => ({ id: String(c.campaign_id), name: c.campaign_name ?? '—', reason: dash('spendNoConversions', ar) })),
      alerts: alerts.map((a) => ({ severity: a.kind === 'budget' ? ('medium' as const) : ('high' as const), text: a.text })),
    }),
    // `ar` belongs here: the KPI labels inside are language-dependent, and leaving it out left
    // them frozen in whichever language the page first rendered in.
    [cur, campaigns.data, platforms.data, alerts, lastSync, objective, ar],
  )

  const objLabel = objectiveLabel(objective, ar)
  const pageTitle = objective === 'all'
    ? dash('operationalView', ar)
    : `${dash('objectiveViewPrefix', ar)} ${objLabel}`

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <div className="flex items-center gap-2">
            <h1 className="text-3xl font-extrabold tracking-tight text-text-primary">{pageTitle}</h1>
            <DemoBadge />
          </div>
          <p className="mt-1 text-sm text-text-secondary">{ar ? 'مركز قيادة موحّد لكل حملاتك الإعلانية المدفوعة عبر المنصات.' : 'One command centre for every paid campaign you run, across every platform.'}</p>
        </div>
        {/*
          `flex-wrap` is load-bearing. Adding the Customise button made three controls sit in one
          non-wrapping row; at 375px in ENGLISH — where «Customise» and «Campaigns» are wider than
          their Arabic labels — the header pushed the page sideways, and content reachable only by
          dragging is content a phone user will not know is there. Caught by the responsive sweep.
        */}
        <div className="flex flex-wrap items-center gap-2">
          <RangeTabs value={days} onChange={setDays} />
          {/*
            One control, not three rows of them.
            The page used to open with a saved-views bar, an objective row and a platform row — three
            bands of configuration between the reader and any answer. Somebody who has never used this
            product met the settings before the numbers. They are all still here, unchanged and
            server-backed; they are now behind one button, and the summary beside it says what is
            currently applied so nothing is hidden, only folded.
          */}
          <button
            type="button"
            data-testid="dashboard-customise"
            onClick={() => setCustomising(true)}
            className="inline-flex h-10 items-center gap-1.5 rounded-xl border border-border-strong bg-surface px-3 text-sm font-semibold text-text-primary hover:bg-surface-hover"
          >
            <SlidersHorizontal size={16} /> {ar ? 'تخصيص العرض' : 'Customise'}
          </button>
          <Link
            to="/app/campaigns"
            className="inline-flex h-10 items-center gap-1.5 rounded-xl border border-border-strong bg-surface px-3 text-sm font-semibold text-text-primary hover:bg-surface-hover"
          >
            {ar ? 'الحملات' : 'Campaigns'} <ArrowUpRight size={16} />
          </Link>
        </div>
      </div>

      {/* What is currently applied, in words — so folding the controls hides no state. */}
      <p data-testid="dashboard-applied" className="-mt-2 text-[13px] text-text-secondary">
        {ar ? 'المعروض: ' : 'Showing: '}
        <span className="font-semibold text-text-primary">{objectives(ar).find((o) => o.key === objective)?.label ?? objective}</span>
        {' · '}
        {providers.length === 0
          ? (ar ? 'كل المنصات' : 'every platform')
          : providers.map((k) => providerName(k)).join('، ')}
      </p>

      <Modal
        open={customising}
        onClose={() => setCustomising(false)}
        title={ar ? 'تخصيص العرض' : 'Customise this view'}
        size="lg"
      >
        <div className="grid gap-5">
      {/* Saved views — server-persisted (DASH-010-E); save/apply/rename/default/delete the current filters. */}
          <SavedViewsBar current={{ objective, providers, days }} onApply={applyView} />

          {/* Objective filter — switches the KPI set AND filters all tiles by campaign objective (backend-supported). */}
          <div className="flex flex-wrap items-center gap-2">
            <span className="text-sm font-semibold text-text-muted">{ar ? 'الهدف:' : 'Objective:'}</span>
            {objectives(ar).map((o) => {
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
              {ar ? 'تعرض هذه النظرة مؤشرات تشغيلية مشتركة فقط؛ اختر هدفًا محددًا لعرض مؤشرات الأداء المتخصصة.' : 'This view shows shared operational figures only. Pick one objective to see the metrics that belong to it.'}
            </div>
          )}

          {/* Platform filter — backend-supported (?provider=…); affects every KPI, chart, table below. */}
          <div className="flex flex-wrap items-center gap-2">
            <span className="text-sm font-semibold text-text-muted">{ar ? 'المنصات:' : 'Platforms:'}</span>
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
                {ar ? 'إعادة ضبط' : 'Reset'}
              </button>
            )}
          </div>

        </div>
      </Modal>

      {/* Unified command center (shared with the marketing preview) */}
      <UnifiedCampaignOverview
        vm={vm}
        lang={ar ? 'ar' : 'en'}
        headerRight={
          <Link to="/app/analytics" className="inline-flex items-center gap-1 font-semibold text-text-secondary hover:text-text-primary">
            {ar ? 'التحليلات' : 'Analytics'} <ArrowUpRight size={14} />
          </Link>
        }
      />

      {/* Deeper detail: daily trend + conversion funnel */}
      <div className="grid gap-4 lg:grid-cols-3">
        <Panel title={ar ? 'الإنفاق مقابل الإيرادات' : 'Spend vs revenue'} description={ar ? 'الاتجاه اليومي خلال الفترة' : 'Day by day over the period'} className="lg:col-span-2" loading={series.isLoading} error={series.isError} empty={!series.isLoading && points.length === 0}>
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
                <Area name={ar ? 'الإنفاق' : 'Spend'} type="monotone" dataKey="spend" stroke={SERIES.spend} strokeWidth={2} fill="url(#gSpend)" isAnimationActive={false} />
                <Area name={ar ? 'الإيرادات' : 'Revenue'} type="monotone" dataKey="revenue" stroke={SERIES.revenue} strokeWidth={2} fill="url(#gRev)" isAnimationActive={false} />
              </AreaChart>
            </ResponsiveContainer>
          </div>
        </Panel>

        <Panel title={ar ? 'قمع التحويل' : 'Conversion funnel'} description={ar ? 'من الظهور إلى الشراء' : 'From impression to purchase'} loading={funnel.isLoading} error={funnel.isError} empty={!funnel.isLoading && (funnel.data?.length ?? 0) === 0}>
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

import { useMemo, useState } from 'react'
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
import { UnifiedCampaignOverview, providerColor, providerName, type OverviewVM } from '@/features/campaigns/overview/UnifiedCampaignOverview'

/** The six paid platforms CampaignsHub unifies — the dashboard platform filter. */
const PLATFORM_KEYS = ['meta', 'google_ads', 'tiktok', 'snapchat', 'x', 'linkedin']
import { useProject } from '@/stores/project'
import { LivePerformanceNotice } from '@/features/disclaimers/PerformanceNotice'

const axis = { stroke: 'var(--text-muted)', fontSize: 12 }

export function DashboardPage() {
  const { currentProjectId } = useProject()
  const [days, setDays] = useState(30)
  const range = useLastNDaysRange(days)
  const [providers, setProviders] = useState<string[]>([])
  const filters = useMemo(() => ({ provider: providers }), [providers])
  const toggleProvider = (key: string) =>
    setProviders((prev) => (prev.includes(key) ? prev.filter((p) => p !== key) : [...prev, key]))

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
      kpis: [
        { key: 'spend', label: 'الإنفاق', value: money(cur?.spend), hint: 'إجمالي الإنفاق المعياري بعملة المشروع' },
        { key: 'results', label: 'النتائج', value: num(cur?.conversions) },
        { key: 'roas', label: 'ROAS', value: ratio(cur?.roas ?? null), hint: 'الإيرادات ÷ الإنفاق' },
        { key: 'cpa', label: 'تكلفة النتيجة', value: money(cur?.cpa), hint: 'الإنفاق ÷ النتائج' },
        { key: 'revenue', label: 'الإيرادات', value: money(cur?.revenue) },
        { key: 'active', label: 'حملات نشطة', value: num(campaigns.data?.length ?? 0) },
      ],
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
    [cur, campaigns.data, platforms.data, alerts, lastSync],
  )

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <div className="flex items-center gap-2">
            <h1 className="text-3xl font-extrabold tracking-tight text-text-primary">لوحة التحكم</h1>
            <DemoBadge />
          </div>
          <p className="mt-1 text-sm text-text-secondary">مركز قيادة موحّد لكل حملاتك الإعلانية المدفوعة عبر المنصات.</p>
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

      {/* Platform filter — backend-supported (?provider=…); affects every KPI, chart, table below. */}
      <div className="flex flex-wrap items-center gap-2">
        <span className="text-xs font-semibold text-text-muted">المنصات:</span>
        {PLATFORM_KEYS.map((key) => {
          const on = providers.includes(key)
          return (
            <button
              key={key}
              type="button"
              onClick={() => toggleProvider(key)}
              aria-pressed={on}
              className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-semibold transition-colors ${on ? 'border-primary bg-primary/10 text-primary' : 'border-border bg-surface text-text-secondary hover:bg-surface-hover'}`}
            >
              <span className="h-2 w-2 rounded-full" style={{ background: providerColor(key) }} />
              {providerName(key)}
            </button>
          )
        })}
        {providers.length > 0 && (
          <button type="button" onClick={() => setProviders([])} className="text-xs font-semibold text-text-muted underline underline-offset-2 hover:text-text-primary">
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

      <LivePerformanceNotice variant="compact" />
    </div>
  )
}

import { useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { BarChart3, GitCompare, LayoutGrid, Plus, Rows, Search, TriangleAlert } from 'lucide-react'
import { listCampaigns } from './api'
import { CampaignFormModal } from './CampaignFormModal'
import { CampaignComparison } from './CampaignComparison'
import { attentionFlags, attentionRank, type AttentionMetrics } from './campaignInsights'
import { campaignStatusLabel, campaignStatusTone, objectiveLabel } from './labels'
import { CAMPAIGN_OBJECTIVES, CAMPAIGN_STATUSES, type UnifiedCampaign } from './types'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Select } from '@/components/ui/Select'
import { EmptyState, Skeleton } from '@/components/ui/States'
import { ChartCard, PlatformDonutChart, ProgressRing, RankingBarChart, SpendRevenueAreaChart } from '@/features/analytics/charts'
import { useBudget, useCampaigns, usePlatforms, useSummary, useTimeseries } from '@/features/analytics/api'
import { useLastNDaysRange } from '@/features/analytics/hooks'
import { DemoBadge, RangeTabs, TrendPill } from '@/features/analytics/components'
import { compact, money, num, ratio } from '@/features/analytics/format'
import { useAuth } from '@/stores/auth'
import { useProject } from '@/stores/project'
import { useUi } from '@/stores/ui'
import { useT } from '@/lib/i18n'

const STATUS_COLORS: Record<string, string> = {
  active: 'var(--success)', paused: 'var(--warning)', completed: 'var(--info)',
  draft: 'var(--text-muted)', scheduled: 'var(--purple)', archived: 'var(--border-strong)',
}

/** The five ways to look at a project's campaigns (CAMPAIGN-010). */
type ViewMode = 'overview' | 'cards' | 'table' | 'compare' | 'attention'

const VIEWS: Array<{ id: ViewMode; ar: string; icon: typeof LayoutGrid }> = [
  { id: 'overview', ar: 'نظرة عامة', icon: BarChart3 },
  { id: 'cards', ar: 'بطاقات', icon: LayoutGrid },
  { id: 'table', ar: 'جدول', icon: Rows },
  { id: 'compare', ar: 'مقارنة', icon: GitCompare },
  { id: 'attention', ar: 'تحتاج تدخلًا', icon: TriangleAlert },
]

export function CampaignsPage() {
  const t = useT()
  const locale = useUi((s) => s.locale)
  const ar = locale === 'ar'
  const navigate = useNavigate()
  const canCreate = useAuth((s) => s.hasPermission('campaigns.create'))
  const { currentProjectId: projectId } = useProject()

  const [days, setDays] = useState(30)
  // PERF-CAMPAIGNS-001: the page opens on the CARD LIST, not the chart-heavy overview. Four charts plus
  // five metric queries on first paint made the page slow to become interactive on Firefox under load —
  // and a page called "campaigns" should show campaigns first anyway. Overview is one click away.
  const [view, setView] = useState<ViewMode>('cards')
  const [compareIds, setCompareIds] = useState<string[]>([])
  const [status, setStatus] = useState('')
  const [objective, setObjective] = useState('')
  const [search, setSearch] = useState('')
  const [modalOpen, setModalOpen] = useState(false)
  const range = useLastNDaysRange(days)

  // Everything below is PROJECT-SCOPED — cache keys + endpoints carry projectId; disabled without one.
  const campaignsQuery = useQuery({
    queryKey: ['project', projectId, 'campaigns', { status, objective, search }],
    queryFn: () => listCampaigns(projectId!, { status: status || undefined, objective: objective || undefined, search: search || undefined }),
    enabled: Boolean(projectId),
  })
  const summary = useSummary(projectId, range)
  const timeseries = useTimeseries(view === 'overview' ? projectId : null, range)
  const platforms = usePlatforms(view === 'overview' ? projectId : null, range)
  const budget = useBudget(projectId, range)
  const metricCampaigns = useCampaigns(projectId, range)

  const campaigns = campaignsQuery.data ?? []
  const counts = useMemo(() => {
    const c: Record<string, number> = { total: campaigns.length }
    for (const s of CAMPAIGN_STATUSES) c[s] = campaigns.filter((x) => x.status === s).length
    return c
  }, [campaigns])

  const statusDonut = useMemo(
    () => CAMPAIGN_STATUSES.map((s) => ({ name: campaignStatusLabel(s, locale), value: counts[s] ?? 0 })).filter((d) => d.value > 0),
    [counts, locale],
  )
  const budgetTotals = useMemo(() => {
    const b = budget.data ?? []
    const total = b.reduce((a, r) => a + Number(r.budget ?? 0), 0)
    const spent = b.reduce((a, r) => a + Number(r.spent ?? 0), 0)
    return { total, spent, remaining: total - spent, consumed: total > 0 ? spent / total : 0 }
  }, [budget.data])
  const topCampaigns = useMemo(
    () => (metricCampaigns.data ?? []).slice(0, 6).map((c) => ({ label: String(c.campaign_name ?? '—'), spend: Number(c.spend ?? 0), platform: String(c.provider ?? '') })),
    [metricCampaigns.data],
  )
  const platformDonut = (platforms.data ?? []).map((p) => ({ name: String(p.provider), value: Number(p.spend ?? 0) }))

  // Per-campaign metric slice, keyed by campaign id — the needs-attention rules read from this and
  // report "no data" rather than assuming a campaign without metrics is healthy.
  const metricsByCampaign = useMemo(() => {
    const map = new Map<string, AttentionMetrics>()
    for (const r of metricCampaigns.data ?? []) {
      const id = String((r as unknown as Record<string, unknown>).campaign_id ?? '')
      if (id) map.set(id, r as unknown as AttentionMetrics)
    }
    return map
  }, [metricCampaigns.data])

  const attention = useMemo(
    () => campaigns
      .map((c) => ({ c, flags: attentionFlags(c, metricsByCampaign.get(c.id)) }))
      .filter((x) => x.flags.length > 0)
      .sort((a, b) => attentionRank(b.flags) - attentionRank(a.flags)),
    [campaigns, metricsByCampaign],
  )

  const toggleCompare = (id: string) =>
    setCompareIds((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : prev.length >= 5 ? prev : [...prev, id]))

  const k = summary.data?.current
  const d = summary.data?.delta ?? {}

  /*
   * No project chosen yet — a CHOICE, not a broken page (AGENCY-006).
   *
   * Reached differently in each portal, which is why the copy names the control rather than the
   * portal: an advertiser picks a project, an agency picks a client and then a project. What both
   * must never see is the previous selection's campaigns still on screen, so this renders instead of
   * the list rather than alongside an empty one.
   */
  if (!projectId) {
    return (
      <div className="space-y-6">
        <h1 className="text-3xl font-extrabold tracking-tight text-text-primary">
          {ar ? 'الحملات' : 'Campaigns'}
        </h1>
        <EmptyState
          title={ar ? 'اختر مشروعًا' : 'Select a project'}
          description={ar
            ? 'حملات كل مشروع مستقلة — اختر مشروعًا من المبدّل لعرض حملاته.'
            : 'Each project has its own campaigns — pick one from the switcher to see them.'}
        />
      </div>
    )
  }

  return (
    <div className="space-y-6">
      {/* Header — project context */}
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <div className="flex items-center gap-2">
            <h1 className="text-3xl font-extrabold tracking-tight text-text-primary">الحملات</h1>
            <DemoBadge />
          </div>
          <p className="mt-1 text-sm text-text-secondary">
            <span className="tnum font-semibold text-text-primary">{counts.total}</span> حملة في المشروع الحالي — كل مشروع معزول عن غيره.
          </p>
        </div>
        <div className="flex items-center gap-2">
          <RangeTabs value={days} onChange={setDays} />
          {canCreate && <Button onClick={() => setModalOpen(true)}><Plus size={16} /> {t('new_campaign')}</Button>}
        </div>
      </div>

      {/* Summary cards — CURRENT PROJECT only */}
      <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
        <StatCard label="نشطة" value={String(counts.active ?? 0)} sub={`${counts.total} إجمالًا`} tone="success" />
        <StatCard label="متوقفة" value={String(counts.paused ?? 0)} sub="تحتاج مراجعة" tone="warning" />
        <StatCard label="الميزانية" value={compact(budgetTotals.total)} sub={`مصروف ${compact(budgetTotals.spent)}`} />
        <StatCard label="النتائج" value={num(k?.conversions)} delta={d.conversions} />
        <StatCard label="CPA" value={money(k?.cpa ?? null)} delta={d.cpa} invert />
        <StatCard label="ROAS" value={ratio(k?.roas ?? null)} delta={d.roas} />
      </div>

      {/* View switcher — the five modes of CAMPAIGN-010. */}
      <div className="flex flex-wrap items-center gap-1 rounded-xl border border-border bg-surface-secondary p-1">
        {VIEWS.map((v) => {
          const Icon = v.icon
          const on = view === v.id
          const count = v.id === 'attention' ? attention.length : v.id === 'compare' ? compareIds.length : 0
          return (
            <button
              key={v.id}
              data-testid={`view-${v.id}`}
              aria-pressed={on}
              onClick={() => setView(v.id)}
              className={`inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-semibold transition-colors ${
                on ? 'bg-surface text-text-primary shadow-[var(--shadow-small)]' : 'text-text-secondary hover:text-text-primary'
              }`}
            >
              <Icon size={15} className={v.id === 'attention' && attention.length > 0 ? 'text-warning' : undefined} />
              {v.ar}
              {count > 0 && <span className="tnum rounded-full bg-surface-secondary px-1.5 text-[11px] text-text-secondary">{count}</span>}
            </button>
          )
        })}
      </div>

      {view === 'overview' ? (
        <>
          {/* Charts — all from the project-scoped metrics API. */}
          <div className="grid gap-4 lg:grid-cols-3">
            <ChartCard title="الإنفاق مقابل الإيرادات" subtitle="اتجاه المشروع" className="lg:col-span-2">
              {timeseries.isLoading ? <Skeleton className="h-[200px]" /> : <SpendRevenueAreaChart data={(timeseries.data ?? []) as unknown as Array<Record<string, unknown>>} height={200} />}
            </ChartCard>
            <ChartCard title="توزيع الإنفاق" subtitle="حسب المنصة">
              {platforms.isLoading ? <Skeleton className="h-[200px]" /> : <PlatformDonutChart data={platformDonut} centerLabel="الإجمالي" centerValue={compact(platformDonut.reduce((a, b) => a + b.value, 0))} height={200} />}
            </ChartCard>
          </div>
          <div className="grid gap-4 lg:grid-cols-3">
            <ChartCard title="حالات الحملات" subtitle="توزيع الحالة">
              {statusDonut.length ? <PlatformDonutChart data={statusDonut} colorBy="series" centerLabel="الحملات" centerValue={String(counts.total)} height={190} /> : <EmptyState title="لا حملات" />}
            </ChartCard>
            <ChartCard title="أفضل الحملات" subtitle="حسب الإنفاق" className="lg:col-span-2">
              {topCampaigns.length >= 2 ? <RankingBarChart data={topCampaigns} bars={[{ key: 'spend', name: 'الإنفاق', kind: 'money' }]} horizontal height={190} colorByPlatform /> : <div className="flex h-[190px] items-center justify-center"><ProgressRing value={budgetTotals.consumed} sublabel={`${compact(budgetTotals.spent)} / ${compact(budgetTotals.total)}`} size={140} tone={budgetTotals.consumed > 0.95 ? 'danger' : 'brand'} /></div>}
            </ChartCard>
          </div>
          {attention.length > 0 && (
            <button onClick={() => setView('attention')} className="flex w-full items-center gap-2 rounded-xl border border-warning/40 bg-warning/10 p-3 text-start text-sm text-text-primary hover:bg-warning/15">
              <TriangleAlert size={16} className="shrink-0 text-warning" />
              <span><span className="tnum font-bold">{attention.length}</span> حملة تحتاج تدخلًا — اعرض التفاصيل والأسباب.</span>
            </button>
          )}
        </>
      ) : view === 'compare' ? (
        <CampaignComparison
          projectId={projectId}
          campaigns={campaigns}
          selected={compareIds}
          onToggle={toggleCompare}
          onClear={() => setCompareIds([])}
          range={range}
          locale={locale}
        />
      ) : (
        <>
          {/* Filters — search + taxonomy chips for status and objective. */}
          <div className="space-y-2">
            <div className="flex flex-wrap items-center gap-2">
              <div className="relative flex-1">
                <Search size={15} className="pointer-events-none absolute inset-y-0 start-3 my-auto text-text-muted" />
                <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="ابحث في حملات المشروع…" className="h-10 w-full rounded-xl border border-border bg-surface ps-9 pe-3 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20" />
              </div>
              <Select value={status} onChange={(e) => setStatus(e.target.value)} options={[{ value: '', label: 'كل الحالات' }, ...CAMPAIGN_STATUSES.map((s) => ({ value: s, label: campaignStatusLabel(s, locale) }))]} />
              <Select value={objective} onChange={(e) => setObjective(e.target.value)} options={[{ value: '', label: 'كل الأهداف' }, ...CAMPAIGN_OBJECTIVES.map((o) => ({ value: o, label: objectiveLabel(o, locale) }))]} />
            </div>
            {/* Taxonomy chips — the same taxonomy the selects use, one tap away, with live counts. */}
            <div className="flex flex-wrap gap-1.5">
              <Chip active={status === '' && objective === ''} onClick={() => { setStatus(''); setObjective('') }}>الكل <span className="tnum">{counts.total}</span></Chip>
              {CAMPAIGN_STATUSES.filter((s) => (counts[s] ?? 0) > 0).map((s) => (
                <Chip key={s} active={status === s} onClick={() => setStatus(status === s ? '' : s)}>
                  {campaignStatusLabel(s, locale)} <span className="tnum">{counts[s]}</span>
                </Chip>
              ))}
              {CAMPAIGN_OBJECTIVES.filter((o) => campaigns.some((c) => c.objective === o)).map((o) => (
                <Chip key={o} active={objective === o} onClick={() => setObjective(objective === o ? '' : o)}>
                  {objectiveLabel(o, locale)}
                </Chip>
              ))}
            </div>
          </div>

          {/* Campaign list */}
          {campaignsQuery.isLoading ? (
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">{[0, 1, 2].map((i) => <Skeleton key={i} className="h-40" />)}</div>
          ) : campaigns.length === 0 ? (
            <EmptyState title={t('no_campaigns')} description={t('no_campaigns_hint')} />
          ) : view === 'attention' ? (
            attention.length === 0 ? (
              <EmptyState title="لا توجد حملات تحتاج تدخلًا" description="كل حملات المشروع مرتبطة بمنصاتها وتنفق ضمن ميزانياتها وتحقق نتائج في الفترة المحددة." />
            ) : (
              <div className="space-y-2">
                {attention.map(({ c, flags }) => (
                  <button
                    key={c.id}
                    data-testid="attention-row"
                    onClick={() => navigate(`/campaigns/${projectId}/${c.id}`)}
                    className="flex w-full flex-col gap-2 rounded-2xl border border-border bg-surface p-4 text-start shadow-[var(--shadow-small)] transition-colors hover:border-brand-300 hover:bg-surface-hover"
                  >
                    <div className="flex flex-wrap items-center gap-2">
                      <span className="font-bold text-text-primary">{c.name}</span>
                      <Badge tone={campaignStatusTone(c.status)}>{campaignStatusLabel(c.status, locale)}</Badge>
                      <Badge tone="neutral">{objectiveLabel(c.objective, locale)}</Badge>
                    </div>
                    <ul className="space-y-1">
                      {flags.map((f) => (
                        <li key={f.code} className={`flex items-start gap-1.5 text-sm ${f.severity === 'high' ? 'text-danger' : 'text-warning'}`}>
                          <TriangleAlert size={14} className="mt-0.5 shrink-0" />
                          <span className="text-text-secondary">{f.ar}</span>
                        </li>
                      ))}
                    </ul>
                  </button>
                ))}
              </div>
            )
          ) : view === 'cards' ? (
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
              {campaigns.map((c) => <CampaignCard key={c.id} c={c} locale={locale} onOpen={() => navigate(`/campaigns/${projectId}/${c.id}`)} />)}
            </div>
          ) : (
            <div className="overflow-hidden rounded-2xl border border-border bg-surface shadow-[var(--shadow-small)]">
              <div className="overflow-x-auto"><table className="w-full min-w-[720px] text-sm">
                <thead><tr className="border-b border-border text-text-muted"><th className="p-3 text-start">الحملة</th><th className="p-3 text-start">الهدف</th><th className="p-3 text-start">الحالة</th><th className="p-3 text-end">الميزانية</th><th className="p-3 text-end">مرتبطة</th></tr></thead>
                <tbody>
                  {campaigns.map((c) => (
                    <tr key={c.id} data-testid="campaign-row" className="cursor-pointer border-b border-border last:border-0 hover:bg-surface-hover" onClick={() => navigate(`/campaigns/${projectId}/${c.id}`)}>
                      <td className="p-3 font-semibold text-text-primary">{c.name}</td>
                      <td className="p-3 text-text-secondary">{objectiveLabel(c.objective, locale)}</td>
                      <td className="p-3"><Badge tone={campaignStatusTone(c.status)}>{campaignStatusLabel(c.status, locale)}</Badge></td>
                      <td className="tnum p-3 text-end">{money(c.total_budget, c.budget_currency)}</td>
                      <td className="tnum p-3 text-end">{c.external_campaigns_count ?? 0}</td>
                    </tr>
                  ))}
                </tbody>
              </table></div>
            </div>
          )}
        </>
      )}

      <CampaignFormModal open={modalOpen} onClose={() => setModalOpen(false)} projectId={projectId} />
    </div>
  )
}

function Chip({ active, onClick, children }: { active: boolean; onClick: () => void; children: React.ReactNode }) {
  return (
    <button
      data-testid="taxonomy-chip"
      aria-pressed={active}
      onClick={onClick}
      className={`inline-flex items-center gap-1 rounded-full border px-3 py-1 text-xs font-semibold transition-colors ${
        active ? 'border-brand-500 bg-brand-primary-soft text-brand-700' : 'border-border text-text-secondary hover:border-brand-300 hover:bg-surface-hover'
      }`}
    >
      {children}
    </button>
  )
}

function StatCard({ label, value, sub, delta, invert, tone }: { label: string; value: string; sub?: string; delta?: number | null; invert?: boolean; tone?: 'success' | 'warning' }) {
  return (
    <div className="rounded-2xl border border-border bg-surface p-3.5 shadow-[var(--shadow-small)]">
      <div className="flex items-center justify-between">
        <span className="text-sm text-text-secondary">{label}</span>
        {delta !== undefined && <TrendPill delta={delta} invertGood={invert} />}
      </div>
      <div className={`tnum mt-1 text-2xl font-extrabold ${tone === 'success' ? 'text-success' : tone === 'warning' ? 'text-warning' : 'text-text-primary'}`}>{value}</div>
      {sub && <div className="mt-0.5 text-xs text-text-muted">{sub}</div>}
    </div>
  )
}

function CampaignCard({ c, locale, onOpen }: { c: UnifiedCampaign; locale: 'ar' | 'en'; onOpen: () => void }) {
  const unlinked = (c.external_campaigns_count ?? 0) === 0
  return (
    <button onClick={onOpen} data-testid="campaign-card" className="flex flex-col gap-2.5 rounded-2xl border border-border bg-surface p-4 text-start shadow-[var(--shadow-small)] transition-colors hover:border-brand-300 hover:bg-surface-hover">
      <div className="flex items-start justify-between gap-2">
        <span className="line-clamp-2 font-bold text-text-primary">{c.name}</span>
        <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ background: STATUS_COLORS[c.status] ?? 'var(--text-muted)' }} title={campaignStatusLabel(c.status, locale)} />
      </div>
      <div className="flex flex-wrap gap-1.5">
        <Badge tone={campaignStatusTone(c.status)}>{campaignStatusLabel(c.status, locale)}</Badge>
        <Badge tone="neutral">{objectiveLabel(c.objective, locale)}</Badge>
      </div>
      <div className="grid grid-cols-2 gap-1.5 text-xs">
        <span className="rounded-lg bg-surface-secondary px-2 py-1.5">الميزانية <b className="tnum block text-text-primary">{money(c.total_budget, c.budget_currency)}</b></span>
        <span className="rounded-lg bg-surface-secondary px-2 py-1.5">مرتبطة <b className="tnum block text-text-primary">{c.external_campaigns_count ?? 0}</b></span>
      </div>
      {unlinked && <div className="inline-flex items-center gap-1 text-[11px] text-warning"><TriangleAlert size={12} /> بلا حملات خارجية مرتبطة</div>}
    </button>
  )
}

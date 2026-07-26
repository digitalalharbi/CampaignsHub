import { useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { LayoutGrid, Plus, Rows, Search, TriangleAlert } from 'lucide-react'
import { listCampaigns } from './api'
import { CampaignFormModal } from './CampaignFormModal'
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

const STATUS_COLORS: Record<string, string> = {
  active: 'var(--success)', paused: 'var(--warning)', completed: 'var(--info)',
  draft: 'var(--text-muted)', scheduled: 'var(--purple)', archived: 'var(--border-strong)',
}

export function CampaignsPage() {
  const locale = useUi((s) => s.locale)
  const navigate = useNavigate()
  const canCreate = useAuth((s) => s.hasPermission('campaigns.create'))
  const { currentProjectId: projectId } = useProject()

  const [days, setDays] = useState(30)
  const [view, setView] = useState<'cards' | 'table'>('cards')
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
  const timeseries = useTimeseries(projectId, range)
  const platforms = usePlatforms(projectId, range)
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

  const k = summary.data?.current
  const d = summary.data?.delta ?? {}

  if (!projectId) {
    return (
      <div className="space-y-6">
        <h1 className="text-3xl font-extrabold tracking-tight text-text-primary">الحملات</h1>
        <EmptyState title="اختر مشروعًا" description="حملات كل مشروع مستقلة — اختر مشروعًا من المبدّل لعرض حملاته." />
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
          {canCreate && <Button onClick={() => setModalOpen(true)}><Plus size={16} /> حملة جديدة</Button>}
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

      {/* Charts — all from project-scoped metrics API */}
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

      {/* Filters + view toggle */}
      <div className="flex flex-wrap items-center gap-2">
        <div className="relative flex-1">
          <Search size={15} className="pointer-events-none absolute inset-y-0 start-3 my-auto text-text-muted" />
          <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="ابحث في حملات المشروع…" className="h-10 w-full rounded-xl border border-border bg-surface ps-9 pe-3 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20" />
        </div>
        <Select value={status} onChange={(e) => setStatus(e.target.value)} options={[{ value: '', label: 'كل الحالات' }, ...CAMPAIGN_STATUSES.map((s) => ({ value: s, label: campaignStatusLabel(s, locale) }))]} />
        <Select value={objective} onChange={(e) => setObjective(e.target.value)} options={[{ value: '', label: 'كل الأهداف' }, ...CAMPAIGN_OBJECTIVES.map((o) => ({ value: o, label: objectiveLabel(o, locale) }))]} />
        <div className="inline-flex rounded-xl border border-border bg-surface-secondary p-0.5">
          <button aria-label="Cards view" onClick={() => setView('cards')} className={`flex h-9 w-9 items-center justify-center rounded-lg ${view === 'cards' ? 'bg-surface shadow-[var(--shadow-small)]' : 'text-text-secondary'}`}><LayoutGrid size={16} /></button>
          <button aria-label="Table view" onClick={() => setView('table')} className={`flex h-9 w-9 items-center justify-center rounded-lg ${view === 'table' ? 'bg-surface shadow-[var(--shadow-small)]' : 'text-text-secondary'}`}><Rows size={16} /></button>
        </div>
      </div>

      {/* Campaign list */}
      {campaignsQuery.isLoading ? (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">{[0, 1, 2].map((i) => <Skeleton key={i} className="h-40" />)}</div>
      ) : campaigns.length === 0 ? (
        <EmptyState title="لا حملات في هذا المشروع" description="أنشئ أول حملة لهذا المشروع." />
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
                <tr key={c.id} className="cursor-pointer border-b border-border last:border-0 hover:bg-surface-hover" onClick={() => navigate(`/campaigns/${projectId}/${c.id}`)}>
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

      <CampaignFormModal open={modalOpen} onClose={() => setModalOpen(false)} projectId={projectId} />
    </div>
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
    <button onClick={onOpen} className="flex flex-col gap-2.5 rounded-2xl border border-border bg-surface p-4 text-start shadow-[var(--shadow-small)] transition-colors hover:border-brand-300 hover:bg-surface-hover">
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

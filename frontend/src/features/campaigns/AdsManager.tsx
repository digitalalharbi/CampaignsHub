import { useMemo, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Building2, ChevronDown, ChevronUp, Columns3, Image as ImageIcon, Layers, Megaphone, Plus, Search, SlidersHorizontal } from 'lucide-react'
import { usePortalPath } from '@/app/portalPath'
import { ProviderMark } from '@/components/ProviderMark'
import { Button } from '@/components/ui/Button'
import { DateRangePicker } from '@/components/ui/DateRangePicker'
import { Modal } from '@/components/ui/Modal'
import { MetricTable } from '@/components/ui/MetricTable'
import { Num } from '@/components/ui/Num'
import { PageIntro } from '@/components/ui/PageIntro'
import { Skeleton } from '@/components/ui/States'
import { MetricLineChart } from '@/features/analytics/charts'
import { compact, money, moneyFromTotals, num, percent, ratio } from '@/features/analytics/format'
import {
  useAccountPulse, useAccounts, useCampaigns, useEntities, useSummary, useTimeseries,
  type AccountState, type MetricFilters, type MetricTotals,
} from '@/features/analytics/api'
import { ProjectChooser } from '@/features/projects/ProjectChooser'
import { usePeriod } from '@/lib/period/usePeriod'
import { useAuth } from '@/stores/auth'
import { useProject } from '@/stores/project'
import { useUi } from '@/stores/ui'
import { runBulk } from './BulkCampaignActions'
import { campaignResult, resultCostText, resultLabel } from './campaignResult'
import { relevanceOf, type CampaignRelevance } from './campaignRelevance'
import { PRESET_ORDER, PRESETS, type ColumnDef, type ColumnKind, type PresetKey } from './adsManagerColumns'
import { CampaignControlPanel } from './CampaignControlPanel'
import { getCampaign } from './api'
import { AccountCampaigns, AccountCovers, AccountSpark, useAccountCovers } from './AdsManagerAccounts'
import { objectiveLabel, providerLabel } from './labels'

/**
 * ADS-MANAGER-001 (Owner 2026-11-23) — campaigns, ad groups, ads and accounts from one simple page, the way Snapchat's
 * Ads Manager reads: the period's five figures as tabs over one chart (a second figure for comparison), a delivery filter
 * and one search, a strip of four levels, and one table per level with a switch, the delivery state and the totals row.
 *
 * Every figure comes from the same metric endpoints the analysis pages read; every switch goes through the provider write
 * path the bulk actions use (`runBulk`), after a confirmation, and changes nothing locally before the platform answers.
 * The full toolset (cards, comparison, causes, change history, saved views) stays one click away in «عرض متقدم».
 */
type Level = 'accounts' | 'campaigns' | 'ad_sets' | 'ads'
type Delivery = 'all' | 'serving' | 'off'
type MetricKey = string

interface Row {
  id: string
  name: string
  provider: string | null
  sub: string | null
  delivery: CampaignRelevance | 'error'
  objective: string | null
  totals: Record<string, unknown>
  /** The unified campaign a switch acts on — campaigns only. */
  campaignId: string | null
  running: boolean
  /** The unified campaign this row belongs to (its own id for a campaign) — what the control panel manages. */
  unifiedId: string | null
  /** The platform's id of this row, so the control panel opens focused on it. */
  externalId: string | null
  /** The parents, for «show its ad sets / ads». */
  adSetId: string | null
}

/**
 * Owner 2026-11-24 — the figures over the chart are chosen by the reader, slot by slot, the way Snapchat's are: each tab
 * names its figure with a menu, and the choice is remembered.
 */
const METRICS: Array<{ key: MetricKey; ar: string; en: string; kind: ColumnKind }> = [
  { key: 'spend', ar: 'الإنفاق', en: 'Amount spent', kind: 'money' },
  { key: 'impressions', ar: 'الظهور', en: 'Impressions', kind: 'count' },
  { key: 'reach', ar: 'الوصول', en: 'Reach', kind: 'count' },
  { key: 'clicks', ar: 'النقرات', en: 'Clicks', kind: 'count' },
  { key: 'ctr', ar: 'معدل النقر', en: 'Click rate', kind: 'percent' },
  { key: 'cpc', ar: 'تكلفة النقرة', en: 'CPC', kind: 'money' },
  { key: 'cpm', ar: 'تكلفة الألف ظهور', en: 'CPM', kind: 'money' },
  { key: 'video_views', ar: 'مشاهدات الفيديو', en: 'Video views', kind: 'count' },
  { key: 'landing_page_views', ar: 'زيارات الصفحة', en: 'Landing page views', kind: 'count' },
  { key: 'engagements', ar: 'التفاعلات', en: 'Engagements', kind: 'count' },
  { key: 'leads', ar: 'العملاء المحتملون', en: 'Leads', kind: 'count' },
  { key: 'cpl', ar: 'تكلفة العميل', en: 'Cost per lead', kind: 'money' },
  { key: 'purchases', ar: 'المشتريات', en: 'Purchases', kind: 'count' },
  { key: 'revenue', ar: 'المبيعات', en: 'Revenue', kind: 'money' },
  { key: 'roas', ar: 'العائد', en: 'ROAS', kind: 'ratio' },
  { key: 'cpa', ar: 'تكلفة الطلب', en: 'Cost per purchase', kind: 'money' },
  { key: 'installs', ar: 'التحميلات', en: 'Installs', kind: 'count' },
]
const DEFAULT_SLOTS: MetricKey[] = ['spend', 'impressions', 'clicks', 'ctr', 'video_views']
const SLOTS_KEY = 'ch-ads-manager-kpis'
const PRESET_KEY = 'ch-ads-manager-preset'
function readSlots(): MetricKey[] {
  try {
    const v: unknown = JSON.parse(localStorage.getItem(SLOTS_KEY) ?? 'null')
    if (Array.isArray(v) && v.length === 5 && v.every((k) => typeof k === 'string' && METRICS.some((m) => m.key === k))) return v as MetricKey[]
  } catch { /* fall through */ }
  return DEFAULT_SLOTS
}
function readPreset(): PresetKey {
  try { const v = localStorage.getItem(PRESET_KEY); return v && v in PRESETS ? (v as PresetKey) : 'performance' } catch { return 'performance' }
}

/** Owner 2026-11-24 — the hierarchy in its own order: accounts, campaigns, ad sets, ads. */
const LEVELS: Array<{ key: Level; ar: string; en: string; Icon: typeof Megaphone }> = [
  { key: 'accounts', ar: 'الحسابات', en: 'Accounts', Icon: Building2 },
  { key: 'campaigns', ar: 'الحملات', en: 'Campaigns', Icon: Megaphone },
  { key: 'ad_sets', ar: 'المجموعات', en: 'Ad sets', Icon: Layers },
  { key: 'ads', ar: 'الإعلانات', en: 'Ads', Icon: ImageIcon },
]

const DELIVERY_TEXT: Record<Row['delivery'], { ar: string; en: string; dot: string }> = {
  serving: { ar: 'قيد التسليم', en: 'Delivering', dot: 'bg-success' },
  idle: { ar: 'نشطة بلا إنفاق', en: 'Active, not spending', dot: 'bg-warning' },
  stopped: { ar: 'متوقفة', en: 'Paused', dot: 'bg-text-muted' },
  error: { ar: 'يحتاج إعادة ربط', en: 'Needs reconnecting', dot: 'bg-danger' },
}

const ACCOUNT_DELIVERY: Record<AccountState, Row['delivery']> = { running: 'serving', stopped: 'stopped', error: 'error', disabled: 'error' }

export function AdsManager({ onAdvanced }: { onAdvanced: () => void }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const lang = ar ? 'ar' : 'en'
  const portal = usePortalPath()
  const navigate = useNavigate()
  const qc = useQueryClient()
  const { currentProjectId: projectId } = useProject()
  const canCreate = useAuth((s) => s.hasPermission('campaigns.create'))
  const canPause = useAuth((s) => s.hasPermission('campaigns.pause'))
  const { range } = usePeriod()

  const [level, setLevel] = useState<Level>('campaigns')
  const [delivery, setDelivery] = useState<Delivery>('all')
  const [search, setSearch] = useState('')
  const [platform, setPlatform] = useState<string>('all')
  const [slots, setSlots] = useState<MetricKey[]>(readSlots)
  const [metric, setMetric] = useState<MetricKey>(() => readSlots()[0]!)
  const setSlot = (i: number, k: MetricKey) => {
    const next = slots.map((x, j) => (j === i ? k : x))
    setSlots(next)
    if (slots[i] === metric) setMetric(k)
    try { localStorage.setItem(SLOTS_KEY, JSON.stringify(next)) } catch { /* private window */ }
  }
  const [compareTo, setCompareTo] = useState<MetricKey | null>('impressions')
  const [chartOpen, setChartOpen] = useState(true)
  const [toggling, setToggling] = useState<Row | null>(null)
  const [busy, setBusy] = useState(false)
  const [outcome, setOutcome] = useState<string | null>(null)
  const [preset, setPreset] = useState<PresetKey>(readPreset)
  const [details, setDetails] = useState<{ row: Row; level: Level } | null>(null)
  /** «show its ad sets / its ads» — the parent the lower level is narrowed to. */
  const [parent, setParent] = useState<{ kind: 'campaign' | 'ad_set'; id: string; name: string } | null>(null)
  const choosePreset = (k: PresetKey) => { setPreset(k); try { localStorage.setItem(PRESET_KEY, k) } catch { /* private window */ } }

  const filters: MetricFilters = platform === 'all' ? {} : { provider: [platform] }
  const summary = useSummary(projectId, range, filters)
  const series = useTimeseries(projectId, range, filters)
  const campaigns = useCampaigns(projectId, range, filters)
  const adSets = useEntities(projectId, range, 'ad_set', null, filters)
  const ads = useEntities(projectId, range, 'ad', null, filters)
  const accounts = useAccounts(projectId, range, filters)
  const pulse = useAccountPulse(projectId, range)
  const currency = summary.data?.currency ?? null

  const rowsByLevel = useMemo<Record<Level, Row[]>>(() => {
    const end = range.to
    const pulseOf = new Map((pulse.data ?? []).map((p) => [`${p.provider}:${p.account_id}`, p]))
    return {
      campaigns: (campaigns.data ?? []).map((c) => {
        const d = relevanceOf({ status: c.status, last_active_on: c.last_active_on }, end)
        return { id: c.campaign_id, name: c.campaign_name ?? '—', provider: c.provider, sub: c.objective ? objectiveLabel(c.objective, lang) : null, delivery: d, objective: c.objective, totals: c as unknown as Record<string, unknown>, campaignId: c.campaign_id, running: d !== 'stopped', unifiedId: c.campaign_id, externalId: null, adSetId: null }
      }),
      ad_sets: (adSets.data?.entities ?? []).map((e) => {
        const d = relevanceOf({ status: e.status, last_active_on: e.last_active_on }, end)
        return { id: e.entity_id, name: e.name ?? '—', provider: e.provider ?? null, sub: e.objective ? objectiveLabel(e.objective, lang) : null, delivery: d, objective: e.objective, totals: e, campaignId: null, running: d !== 'stopped', unifiedId: e.unified_campaign_id ?? null, externalId: e.external_id, adSetId: e.entity_id }
      }),
      ads: (ads.data?.entities ?? []).map((e) => {
        const d = relevanceOf({ status: e.status, last_active_on: e.last_active_on }, end)
        return { id: e.entity_id, name: e.name ?? '—', provider: e.provider ?? null, sub: e.objective ? objectiveLabel(e.objective, lang) : null, delivery: d, objective: e.objective, totals: e, campaignId: null, running: d !== 'stopped', unifiedId: e.unified_campaign_id ?? null, externalId: e.external_id, adSetId: e.ad_set_id }
      }),
      accounts: (accounts.data ?? []).map((a) => {
        const p = pulseOf.get(`${a.provider}:${a.account_id}`)
        const d = p ? ACCOUNT_DELIVERY[p.state] : 'stopped'
        const n = p ? p.active_campaigns : null
        return {
          id: `${a.provider}:${a.account_id ?? '—'}`, name: a.account_name ?? providerLabel(a.provider, lang), provider: a.provider,
          sub: n === null ? null : (ar ? `حملات نشطة: ${n}` : `Active campaigns: ${n}`), delivery: d, objective: null,
          totals: a as unknown as Record<string, unknown>, campaignId: null, running: d === 'serving', unifiedId: null, externalId: a.account_id, adSetId: null,
        }
      }),
    }
  }, [campaigns.data, adSets.data, ads.data, accounts.data, pulse.data, range.to, lang, ar])

  const pending = { campaigns: campaigns.isPending, ad_sets: adSets.isPending, ads: ads.isPending, accounts: accounts.isPending }[level]
  const platforms = useMemo(() => [...new Set((campaigns.data ?? []).map((c) => c.provider))].sort(), [campaigns.data])

  const rows = useMemo(() => {
    const q = search.trim().toLowerCase()
    return rowsByLevel[level].filter((r) => {
      if (parent && level !== 'accounts' && level !== 'campaigns') {
        if (parent.kind === 'campaign' && r.unifiedId !== parent.id) return false
        if (parent.kind === 'ad_set' && (level !== 'ads' || r.adSetId !== parent.id)) return false
      }
      if (delivery === 'serving' && r.delivery !== 'serving') return false
      if (delivery === 'off' && r.delivery === 'serving') return false
      return q === '' || r.name.toLowerCase().includes(q)
    })
  }, [rowsByLevel, level, delivery, search, parent])

  const totals = useMemo(() => rows.reduce((t, r) => {
    for (const c of PRESETS[preset].columns) {
      const v = c.additive ? c.get(r.totals) : null
      if (v !== null) t[c.key] = (t[c.key] ?? 0) + v
    }
    for (const k of ['impressions', 'clicks']) t[`__${k}`] = (t[`__${k}`] ?? 0) + (Number(r.totals[k]) || 0)
    return t
  }, {} as Record<string, number>), [rows, preset])

  const isAccounts = level === 'accounts'
  const covers = useAccountCovers(projectId, range, isAccounts)
  if (!projectId) return <ProjectChooser purpose="analytics" />

  const meta = (m: MetricKey) => METRICS.find((x) => x.key === m) ?? METRICS[0]!
  const label = (m: MetricKey) => meta(m)[ar ? 'ar' : 'en']
  const fmtKind = (kind: ColumnKind, v: number | null | undefined): string =>
    v === null || v === undefined ? '—' : kind === 'money' ? money(v, currency) : kind === 'percent' ? percent(v, 2) : kind === 'ratio' ? ratio(v) : compact(v)
  const valueOf = (t: MetricTotals | undefined, m: MetricKey): string => {
    if (!t) return '—'
    if (m === 'spend') return moneyFromTotals(t, 'spend', ar, currency).text
    const v = (t as unknown as Record<string, unknown>)[m]
    return fmtKind(meta(m).kind, typeof v === 'number' ? v : null)
  }
  const chartKind = (m: MetricKey) => { const k = meta(m).kind; return k === 'money' ? ('money' as const) : k === 'percent' ? ('percent' as const) : k === 'ratio' ? ('ratio' as const) : ('num' as const) }
  const chartData = (series.data ?? []).map((p) => {
    const row = p as unknown as Record<string, unknown>
    return { date: p.date, [metric]: row[metric] ?? null, ...(compareTo && compareTo !== metric ? { [compareTo]: row[compareTo] ?? null } : {}) }
  })
  const chartSeries = [
    { key: metric, name: label(metric), color: 'var(--warning)', kind: chartKind(metric) },
    ...(compareTo && compareTo !== metric ? [{ key: compareTo, name: label(compareTo), color: 'var(--success)', kind: chartKind(compareTo) }] : []),
  ]
  const showResult = level !== 'accounts' && preset === 'performance'
  const pulseById = new Map((pulse.data ?? []).map((p) => [`${p.provider}:${p.account_id}`, p]))
  const openPlatform = (provider: string | null) => { setPlatform(provider ?? 'all'); setParent(null); setLevel('campaigns') }
  const columns = PRESETS[preset].columns
  const cell = (c: ColumnDef, t: Record<string, unknown>): string =>
    c.key === 'spend' ? moneyFromTotals(t as unknown as MetricTotals, 'spend', ar, currency).text : fmtKind(c.kind, c.get(t))

  const confirmToggle = async () => {
    if (!toggling?.campaignId) return
    setBusy(true)
    const out = await runBulk(projectId, [{ id: toggling.campaignId, name: toggling.name }], toggling.running ? 'pause' : 'resume')
    setBusy(false)
    setToggling(null)
    const o = out[0]
    setOutcome(o?.result === 'done' ? (ar ? `أكّدت المنصة: ${o.name}` : `Platform confirmed: ${o.name}`) : (ar ? `لم يُنفّذ: ${o?.name ?? ''}${o?.message ? ` — ${o.message}` : ''}` : `Not done: ${o?.name ?? ''}${o?.message ? ` — ${o.message}` : ''}`))
    void qc.invalidateQueries({ queryKey: ['metrics'] })
    void qc.invalidateQueries({ queryKey: ['project', projectId, 'campaigns'] })
  }

  const counts: Record<Level, number> = { campaigns: rowsByLevel.campaigns.length, ad_sets: rowsByLevel.ad_sets.length, ads: rowsByLevel.ads.length, accounts: rowsByLevel.accounts.length }

  return (
    <div className="space-y-4" data-testid="ads-manager">
      {/* The head: what, which period, and the two ways out. */}
      <PageIntro
        testid="ads-manager-intro"
        title={ar ? 'إدارة الإعلانات' : 'Manage ads'}
        actions={<>
          <DateRangePicker projectId={projectId} testId="ads-manager-period" />
          <Button variant="secondary" onClick={onAdvanced} data-testid="ads-manager-advanced"><SlidersHorizontal size={15} aria-hidden /> {ar ? 'عرض متقدم' : 'Advanced view'}</Button>
        </>}
      />

      {/* The period's figures as tabs over one chart. */}
      <section className="overflow-hidden rounded-2xl border border-border bg-surface" data-testid="ads-manager-kpis">
        <div role="tablist" className="grid grid-cols-2 border-b border-border sm:grid-cols-3 lg:grid-cols-5">
          {slots.map((k, i) => (
            <div key={i} role="tab" aria-selected={metric === k} tabIndex={0} data-testid={`ads-manager-kpi-${i}`} data-metric={k}
              onClick={() => setMetric(k)} onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); setMetric(k) } }}
              className={`min-w-0 cursor-pointer border-b-2 px-4 py-3 text-start transition-colors ${metric === k ? 'border-text-primary bg-surface' : 'border-transparent bg-surface-secondary/60 hover:bg-surface-secondary'}`}>
              <select value={k} onClick={(e) => e.stopPropagation()} onChange={(e) => setSlot(i, e.target.value)} aria-label={ar ? 'اختر المؤشر' : 'Choose the figure'} data-testid={`ads-manager-kpi-pick-${i}`}
                className="-ms-1 max-w-full cursor-pointer truncate rounded bg-transparent text-xs text-text-secondary underline decoration-dotted underline-offset-4 hover:text-text-primary">
                {METRICS.map((m) => <option key={m.key} value={m.key}>{ar ? m.ar : m.en}</option>)}
              </select>
              <span className="block text-lg font-bold text-text-primary">{summary.isPending ? <Skeleton className="h-6 w-20" /> : <Num>{valueOf(summary.data?.current, k)}</Num>}</span>
            </div>
          ))}
        </div>
        {chartOpen && (
          <div className="p-4">
            <div className="mb-3 flex flex-wrap items-center gap-2 text-sm">
              <span className="inline-flex items-center gap-1.5 rounded-lg border border-border px-3 py-1.5"><span className="size-2.5 rounded-sm bg-warning" />{label(metric)}</span>
              {compareTo !== null && (
                <>
                  <span className="text-text-muted">{ar ? 'مقابل' : 'vs.'}</span>
                  <select aria-label={ar ? 'المؤشر المقارن' : 'Compared metric'} value={compareTo} onChange={(e) => setCompareTo(e.target.value as MetricKey)} data-testid="ads-manager-compare"
                    className="h-9 rounded-lg border border-border bg-surface px-2 text-sm">
                    {METRICS.filter((m) => m.key !== metric).map((m) => <option key={m.key} value={m.key}>{ar ? m.ar : m.en}</option>)}
                  </select>
                </>
              )}
              <button type="button" className="ms-auto text-xs font-semibold text-text-secondary underline" onClick={() => setCompareTo(compareTo === null ? (metric === 'impressions' ? 'spend' : 'impressions') : null)} data-testid="ads-manager-compare-toggle">
                {compareTo === null ? (ar ? 'إظهار المقارنة' : 'Show comparison') : (ar ? 'إخفاء المقارنة' : 'Hide comparison')}
              </button>
            </div>
            {series.isPending ? <Skeleton className="h-56" /> : <MetricLineChart data={chartData} series={chartSeries} height={240} currency={currency ?? ''} rightAxisFor={chartSeries[1]?.key} />}
          </div>
        )}
        <button type="button" onClick={() => setChartOpen((o) => !o)} aria-expanded={chartOpen} aria-label={chartOpen ? (ar ? 'طي الرسم' : 'Collapse chart') : (ar ? 'فتح الرسم' : 'Expand chart')}
          className="flex w-full justify-center border-t border-border py-1 text-text-muted hover:bg-surface-secondary" data-testid="ads-manager-chart-toggle">
          {chartOpen ? <ChevronUp size={16} aria-hidden /> : <ChevronDown size={16} aria-hidden />}
        </button>
      </section>

      {/* Delivery, the metric set, platform and one search — one row. */}
      <div className="flex flex-wrap items-center gap-2">
        <div role="group" aria-label={ar ? 'التسليم' : 'Delivery'} className="flex rounded-xl bg-surface-secondary p-1">
          {([['all', ar ? 'الكل' : 'All'], ['serving', ar ? 'قيد التسليم' : 'Delivering'], ['off', ar ? 'متوقفة' : 'Not delivering']] as const).map(([k, t]) => (
            <button key={k} type="button" aria-pressed={delivery === k} onClick={() => setDelivery(k)} data-testid={`ads-manager-delivery-${k}`}
              className={`rounded-lg px-3 py-1.5 text-sm font-semibold ${delivery === k ? 'bg-surface text-text-primary shadow-[var(--shadow-small)]' : 'text-text-secondary hover:text-text-primary'}`}>{t}</button>
          ))}
        </div>
        <label className="flex h-10 items-center gap-2 rounded-xl border border-border bg-surface ps-3 text-sm font-semibold text-text-secondary">
          <Columns3 size={16} className="shrink-0" aria-hidden /> {ar ? 'المؤشرات' : 'Metrics'}
          <select value={preset} onChange={(e) => choosePreset(e.target.value as PresetKey)} data-testid="ads-manager-preset"
            className="h-full cursor-pointer rounded-e-xl bg-transparent pe-2 text-sm font-bold text-text-primary outline-none">
            {PRESET_ORDER.map((k) => <option key={k} value={k}>{ar ? PRESETS[k].ar : PRESETS[k].en}</option>)}
          </select>
        </label>
        {platforms.length > 1 && (
          <div role="group" aria-label={ar ? 'المنصة' : 'Platform'} className="flex items-center gap-1" data-testid="ads-manager-platforms">
            <button type="button" aria-pressed={platform === 'all'} onClick={() => setPlatform('all')} className={`h-9 rounded-lg px-2.5 text-xs font-semibold ${platform === 'all' ? 'bg-text-primary text-surface' : 'border border-border text-text-secondary'}`}>{ar ? 'كل المنصات' : 'All platforms'}</button>
            {platforms.map((p) => (
              <button key={p} type="button" aria-pressed={platform === p} title={providerLabel(p, lang)} aria-label={providerLabel(p, lang)} onClick={() => setPlatform(platform === p ? 'all' : p)}
                className={`flex size-9 items-center justify-center rounded-lg border ${platform === p ? 'border-text-primary bg-surface-secondary' : 'border-border opacity-80 hover:opacity-100'}`}>
                <ProviderMark provider={p} size={18} />
              </button>
            ))}
          </div>
        )}
        <label className="flex h-10 min-w-48 flex-1 items-center gap-2 rounded-xl border border-border bg-surface px-3">
          <Search size={16} className="shrink-0 text-text-muted" aria-hidden />
          <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder={ar ? 'ابحث بالاسم' : 'Search by name'} aria-label={ar ? 'بحث' : 'Search'} data-testid="ads-manager-search" className="min-w-0 flex-1 bg-transparent text-sm outline-none" />
        </label>
      </div>

      {/* The levels and the table. */}
      <section className="overflow-hidden rounded-2xl border border-border bg-surface">
        <div className="flex flex-wrap items-center gap-1 border-b border-border px-2">
          {LEVELS.map(({ key, ar: tAr, en, Icon }) => (
            <button key={key} type="button" role="tab" aria-selected={level === key} onClick={() => setLevel(key)} data-testid={`ads-manager-level-${key}`}
              className={`inline-flex items-center gap-1.5 border-b-2 px-4 py-3 text-sm font-bold ${level === key ? 'border-text-primary text-text-primary' : 'border-transparent text-text-secondary hover:text-text-primary'}`}>
              <Icon size={15} aria-hidden /> {ar ? tAr : en}
              <span className="rounded-full bg-surface-secondary px-1.5 text-xs font-semibold text-text-muted"><Num>{counts[key]}</Num></span>
            </button>
          ))}
          {canCreate && (
            <Button size="sm" className="my-2 ms-auto" onClick={() => navigate(portal('/launch'))} data-testid="ads-manager-new"><Plus size={15} aria-hidden /> {ar ? 'حملة جديدة' : 'Create campaign'}</Button>
          )}
        </div>

        {outcome && (
          <p className="flex items-center justify-between gap-2 border-b border-border bg-surface-secondary/60 px-4 py-2 text-sm" data-testid="ads-manager-outcome">
            {outcome}<button type="button" className="text-xs text-text-muted hover:underline" onClick={() => setOutcome(null)}>{ar ? 'إخفاء' : 'Dismiss'}</button>
          </p>
        )}

        {parent && level !== 'accounts' && level !== 'campaigns' && (
        <div className="flex flex-wrap items-center gap-2 border-b border-border px-4 py-2">
          {(
            <span className="inline-flex items-center gap-1.5 rounded-full bg-brand-500/10 px-3 py-1 text-xs font-semibold text-brand-600" data-testid="ads-manager-parent">
              {parent.kind === 'campaign' ? (ar ? 'الحملة: ' : 'Campaign: ') : (ar ? 'المجموعة: ' : 'Ad set: ')}{parent.name}
              <button type="button" onClick={() => setParent(null)} aria-label={ar ? 'إلغاء التصفية' : 'Clear'} className="ms-1 font-bold hover:underline">×</button>
            </span>
          )}
        </div>
        )}

        <div className="p-2">
          {pending ? <Skeleton className="h-24" /> : rows.length === 0 ? (
            <p className="px-4 py-10 text-center text-sm text-text-muted" data-testid="ads-manager-empty">{ar ? 'لا شيء في هذه الفترة يطابق الاختيار.' : 'Nothing in this period matches.'}</p>
          ) : (
            <MetricTable
              testId={`ads-manager-table-${level}`}
              nameMinWidth={240}
              head={[
                ar ? 'الاسم' : 'Name',
                ...(level === 'campaigns' ? [ar ? 'الحالة' : 'Status'] : []),
                ar ? 'التسليم' : 'Delivery',
                ...(isAccounts ? [ar ? 'الإنفاق اليومي' : 'Daily spend', ar ? 'الحملات' : 'Campaigns', ar ? 'أعلى المحتوى' : 'Top content'] : []),
                ...(showResult ? [ar ? 'النتائج' : 'Result', ar ? 'تكلفة النتيجة' : 'Cost per result'] : []),
                ...columns.map((c) => (ar ? c.ar : c.en)),
              ]}
              values={rows.map((r) => {
                const result = showResult ? campaignResult(r.objective, r.totals) : null
                const spend = Number(r.totals.spend) || 0
                return [
                  r.name, ...(level === 'campaigns' ? [r.running ? 1 : 0] : []), r.delivery,
                  ...(isAccounts ? [null, pulseById.get(r.id)?.active_campaigns ?? null, null] : []),
                  ...(result ? [result.value, result.value ? spend / result.value : null] : []),
                  ...columns.map((c) => c.get(r.totals)),
                ]
              })}
              rows={rows.map((r) => {
                const result = showResult ? campaignResult(r.objective, r.totals) : null
                const d = DELIVERY_TEXT[r.delivery]
                return [
                  <button key="n" type="button" onClick={() => setDetails({ row: r, level })} className="flex w-full items-center gap-2.5 text-start" data-testid="ads-manager-row">
                    {r.provider && <ProviderMark provider={r.provider} size={20} className="shrink-0" />}
                    <span className="min-w-0">
                      <span className="block truncate font-semibold text-text-primary hover:underline">{r.name}</span>
                      {r.sub && <span className="block truncate text-xs text-text-muted">{r.sub}</span>}
                    </span>
                  </button>,
                  ...(level === 'campaigns' ? [
                    <button key="s" type="button" role="switch" aria-checked={r.running} disabled={!canPause || r.campaignId === null}
                      title={canPause ? undefined : (ar ? 'يحتاج صلاحية إيقاف الحملات' : 'Needs the pause permission')}
                      aria-label={r.running ? (ar ? `إيقاف ${r.name}` : `Pause ${r.name}`) : (ar ? `تشغيل ${r.name}` : `Resume ${r.name}`)}
                      onClick={() => setToggling(r)} data-testid="ads-manager-switch"
                      className={`relative inline-block h-6 w-11 shrink-0 rounded-full transition-colors disabled:opacity-50 ${r.running ? 'bg-success' : 'bg-border-strong'}`}>
                      <span className={`absolute top-0.5 size-5 rounded-full bg-white shadow transition-all ${r.running ? 'end-0.5' : 'start-0.5'}`} />
                    </button>,
                  ] : []),
                  <span key="d" className="inline-flex items-center gap-1.5 whitespace-nowrap text-text-primary"><span className={`size-2 rounded-full ${d.dot}`} aria-hidden />{ar ? d.ar : d.en}</span>,
                  ...(isAccounts ? [
                    <AccountSpark key="sp" points={(pulseById.get(r.id)?.daily ?? []).map((x) => x.spend)} />,
                    <AccountCampaigns key="ca" pulse={pulseById.get(r.id)} ar={ar} onOpen={() => openPlatform(r.provider)} />,
                    <AccountCovers key="co" covers={r.externalId ? covers.byAccount.get(r.externalId) ?? [] : []} pending={covers.pending} ar={ar} />,
                  ] : []),
                  ...(result ? [
                    <span key="r" className="flex flex-col"><span>{result.value === null ? '—' : num(result.value)}</span><span className="text-xs text-text-muted">{resultLabel(result, ar)}</span></span>,
                    resultCostText(result, r.totals, currency),
                  ] : []),
                  ...columns.map((c) => cell(c, r.totals)),
                ]
              })}
              footer={[
                <span key="t" className="font-semibold" data-testid="ads-manager-totals">{ar ? `الإجمالي · ${rows.length}` : `Total · ${rows.length}`}</span>,
                ...(level === 'campaigns' ? ['—'] : []),
                '—',
                ...(isAccounts ? ['—', '—', '—'] : []),
                ...(showResult ? ['—', '—'] : []),
                ...columns.map((c) => (c.key === 'ctr' ? (totals.__impressions ? percent((totals.__clicks ?? 0) / totals.__impressions, 2) : '—') : c.additive && totals[c.key] !== undefined ? fmtKind(c.kind, totals[c.key]) : '—')),
              ]}
            />
          )}
        </div>
      </section>

      {details && (
        <EntityDetails
          details={details} ar={ar} projectId={projectId} columns={PRESETS[preset].columns.length ? PRESETS[preset].columns : PRESETS.performance.columns}
          fmt={(c, t) => cell(c, t)}
          onClose={() => setDetails(null)}
          onDrill={(kind, target) => {
            const r = details.row
            setDetails(null)
            if (kind === 'campaigns') { setPlatform(r.provider ?? 'all'); setParent(null); setLevel('campaigns'); return }
            setParent(details.level === 'campaigns' ? { kind: 'campaign', id: r.unifiedId ?? r.id, name: r.name } : { kind: 'ad_set', id: r.adSetId ?? r.id, name: r.name })
            setLevel(target)
          }}
        />
      )}

      {toggling && (
        <Modal open onClose={() => { if (!busy) setToggling(null) }} size="md"
          title={toggling.running ? (ar ? 'إيقاف الحملة على المنصة' : 'Pause the campaign on the platform') : (ar ? 'تشغيل الحملة على المنصة' : 'Resume the campaign on the platform')}
          footer={<><Button variant="secondary" disabled={busy} onClick={() => setToggling(null)}>{ar ? 'إلغاء' : 'Cancel'}</Button><Button loading={busy} onClick={() => void confirmToggle()} data-testid="ads-manager-switch-confirm">{toggling.running ? (ar ? 'نعم، أوقف' : 'Yes, pause') : (ar ? 'نعم، شغّل' : 'Yes, resume')}</Button></>}>
          <div className="space-y-2 text-sm" data-testid="ads-manager-switch-dialog">
            <p className="flex items-center gap-2 font-semibold text-text-primary">{toggling.provider && <ProviderMark provider={toggling.provider} size={16} />}{toggling.name}</p>
            <p className="text-text-secondary">{ar ? 'يُرسل الطلب إلى المنصة، ولا يتغير شيء هنا قبل تأكيدها.' : 'The request goes to the platform; nothing changes here before it confirms.'}</p>
            {!toggling.running && <p className="font-semibold text-warning">{ar ? 'تشغيل الحملة يبدأ الإنفاق عليها.' : 'Resuming the campaign starts its spend.'}</p>}
          </div>
        </Modal>
      )}
    </div>
  )
}

/**
 * ADS-MANAGER-002 — a row opens its own part: the figures of the chosen metric set, the way down to what is beneath it, and,
 * for a campaign, an ad set or an ad, the provider control panel that edits and manages it (the same panel the campaign
 * page uses — nothing is written from here directly).
 */
function EntityDetails({
  details, ar, projectId, columns, fmt, onClose, onDrill,
}: {
  details: { row: Row; level: Level }
  ar: boolean
  projectId: string | null
  columns: ColumnDef[]
  fmt: (c: ColumnDef, t: Record<string, unknown>) => string
  onClose: () => void
  onDrill: (kind: 'campaigns' | 'down', target: Level) => void
}) {
  const portal = usePortalPath()
  const { row, level } = details
  const manageable = level !== 'accounts' && row.unifiedId !== null && projectId !== null
  const campaign = useQuery({
    queryKey: ['ads-manager', 'campaign', projectId, row.unifiedId],
    queryFn: () => getCampaign(projectId!, row.unifiedId!),
    enabled: manageable,
  })
  const d = DELIVERY_TEXT[row.delivery]
  const result = level !== 'accounts' ? campaignResult(row.objective, row.totals) : null
  const LEVEL_NAME: Record<Level, { ar: string; en: string }> = {
    accounts: { ar: 'حساب إعلاني', en: 'Ad account' }, campaigns: { ar: 'حملة', en: 'Campaign' },
    ad_sets: { ar: 'مجموعة', en: 'Ad set' }, ads: { ar: 'إعلان', en: 'Ad' },
  }
  return (
    <Modal open onClose={onClose} size="lg" title={row.name}>
      <div className="space-y-4" data-testid="ads-manager-details">
        <div className="flex flex-wrap items-center gap-2 text-sm">
          {row.provider && <ProviderMark provider={row.provider} size={20} />}
          <span className="rounded-full bg-surface-secondary px-2.5 py-0.5 text-xs font-semibold text-text-secondary">{ar ? LEVEL_NAME[level].ar : LEVEL_NAME[level].en}</span>
          <span className="inline-flex items-center gap-1.5 text-text-primary"><span className={`size-2 rounded-full ${d.dot}`} aria-hidden />{ar ? d.ar : d.en}</span>
          {row.sub && <span className="text-text-muted">· {row.sub}</span>}
        </div>

        <dl className="grid grid-cols-2 gap-2 sm:grid-cols-4">
          {result && (
            <div className="rounded-xl bg-surface-secondary px-3 py-2">
              <dt className="text-xs text-text-secondary">{resultLabel(result, ar)}</dt>
              <dd className="text-base font-bold text-text-primary"><Num>{result.value === null ? '—' : num(result.value)}</Num></dd>
            </div>
          )}
          {columns.map((c) => (
            <div key={c.key} className="rounded-xl bg-surface-secondary px-3 py-2">
              <dt className="text-xs text-text-secondary">{ar ? c.ar : c.en}</dt>
              <dd className="text-base font-bold text-text-primary"><Num>{fmt(c, row.totals)}</Num></dd>
            </div>
          ))}
        </dl>

        <div className="flex flex-wrap gap-2">
          {level === 'accounts' && (
            <>
              <Button size="sm" variant="secondary" onClick={() => onDrill('campaigns', 'campaigns')} data-testid="ads-manager-drill-campaigns"><Megaphone size={14} aria-hidden /> {ar ? 'حملات هذه المنصة' : 'This platform\'s campaigns'}</Button>
              <Link to={portal('/integrations')} className="inline-flex h-8 items-center gap-1.5 rounded-lg border border-border px-3 text-xs font-bold text-text-primary hover:bg-surface-secondary">{ar ? 'إدارة الربط' : 'Manage the connection'}</Link>
            </>
          )}
          {level === 'campaigns' && <Button size="sm" variant="secondary" onClick={() => onDrill('down', 'ad_sets')} data-testid="ads-manager-drill-ad_sets"><Layers size={14} aria-hidden /> {ar ? 'مجموعاتها' : 'Its ad sets'}</Button>}
          {(level === 'campaigns' || level === 'ad_sets') && <Button size="sm" variant="secondary" onClick={() => onDrill('down', 'ads')} data-testid="ads-manager-drill-ads"><ImageIcon size={14} aria-hidden /> {ar ? 'إعلاناتها' : 'Its ads'}</Button>}
          {row.unifiedId && <Link to={portal(`/campaigns/${row.unifiedId}`)} className="inline-flex h-8 items-center gap-1.5 rounded-lg border border-border px-3 text-xs font-bold text-text-primary hover:bg-surface-secondary" data-testid="ads-manager-open-campaign">{ar ? 'صفحة الحملة' : 'Campaign page'}</Link>}
        </div>

        {manageable && (
          <section className="rounded-xl border border-border p-3" data-testid="ads-manager-manage">
            <h3 className="mb-2 flex items-center gap-1.5 text-sm font-bold text-text-primary"><SlidersHorizontal size={15} aria-hidden /> {ar ? 'التعديل والإدارة' : 'Edit and manage'}</h3>
            {campaign.isPending ? <Skeleton className="h-24" /> : campaign.data ? (
              <CampaignControlPanel campaign={campaign.data} projectId={projectId!} focusExternalId={level === 'campaigns' ? undefined : row.externalId ?? undefined} />
            ) : (
              <p className="text-xs text-text-muted">{ar ? 'تعذّر تحميل خيارات الإدارة لهذه الحملة.' : 'Could not load the management options for this campaign.'}</p>
            )}
          </section>
        )}
      </div>
    </Modal>
  )
}

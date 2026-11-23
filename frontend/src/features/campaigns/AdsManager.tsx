import { useMemo, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useQueryClient } from '@tanstack/react-query'
import { Building2, ChevronDown, ChevronUp, Image as ImageIcon, Layers, Megaphone, Plus, Search, SlidersHorizontal } from 'lucide-react'
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
import { compact, money, moneyFromTotals, num, percent } from '@/features/analytics/format'
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
type MetricKey = 'spend' | 'impressions' | 'clicks' | 'ctr' | 'video_views'

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
}

const METRICS: Array<{ key: MetricKey; ar: string; en: string }> = [
  { key: 'spend', ar: 'الإنفاق', en: 'Amount spent' },
  { key: 'impressions', ar: 'الظهور', en: 'Impressions' },
  { key: 'clicks', ar: 'النقرات', en: 'Clicks' },
  { key: 'ctr', ar: 'معدل النقر', en: 'Click rate' },
  { key: 'video_views', ar: 'مشاهدات الفيديو', en: 'Video views' },
]

const LEVELS: Array<{ key: Level; ar: string; en: string; Icon: typeof Megaphone }> = [
  { key: 'campaigns', ar: 'الحملات', en: 'Campaigns', Icon: Megaphone },
  { key: 'ad_sets', ar: 'المجموعات', en: 'Ad sets', Icon: Layers },
  { key: 'ads', ar: 'الإعلانات', en: 'Ads', Icon: ImageIcon },
  { key: 'accounts', ar: 'الحسابات', en: 'Accounts', Icon: Building2 },
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
  const [metric, setMetric] = useState<MetricKey>('spend')
  const [compareTo, setCompareTo] = useState<MetricKey | null>('impressions')
  const [chartOpen, setChartOpen] = useState(true)
  const [toggling, setToggling] = useState<Row | null>(null)
  const [busy, setBusy] = useState(false)
  const [outcome, setOutcome] = useState<string | null>(null)

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
        return { id: c.campaign_id, name: c.campaign_name ?? '—', provider: c.provider, sub: c.objective ? objectiveLabel(c.objective, lang) : null, delivery: d, objective: c.objective, totals: c as unknown as Record<string, unknown>, campaignId: c.campaign_id, running: d !== 'stopped' }
      }),
      ad_sets: (adSets.data?.entities ?? []).map((e) => {
        const d = relevanceOf({ status: e.status, last_active_on: e.last_active_on }, end)
        return { id: e.entity_id, name: e.name ?? '—', provider: e.provider ?? null, sub: e.objective ? objectiveLabel(e.objective, lang) : null, delivery: d, objective: e.objective, totals: e, campaignId: null, running: d !== 'stopped' }
      }),
      ads: (ads.data?.entities ?? []).map((e) => {
        const d = relevanceOf({ status: e.status, last_active_on: e.last_active_on }, end)
        return { id: e.entity_id, name: e.name ?? '—', provider: e.provider ?? null, sub: e.objective ? objectiveLabel(e.objective, lang) : null, delivery: d, objective: e.objective, totals: e, campaignId: null, running: d !== 'stopped' }
      }),
      accounts: (accounts.data ?? []).map((a) => {
        const p = pulseOf.get(`${a.provider}:${a.account_id}`)
        const d = p ? ACCOUNT_DELIVERY[p.state] : 'stopped'
        const n = p ? p.active_campaigns : null
        return {
          id: `${a.provider}:${a.account_id ?? '—'}`, name: a.account_name ?? providerLabel(a.provider, lang), provider: a.provider,
          sub: n === null ? null : (ar ? `حملات نشطة: ${n}` : `Active campaigns: ${n}`), delivery: d, objective: null,
          totals: a as unknown as Record<string, unknown>, campaignId: null, running: d === 'serving',
        }
      }),
    }
  }, [campaigns.data, adSets.data, ads.data, accounts.data, pulse.data, range.to, lang, ar])

  const pending = { campaigns: campaigns.isPending, ad_sets: adSets.isPending, ads: ads.isPending, accounts: accounts.isPending }[level]
  const platforms = useMemo(() => [...new Set((campaigns.data ?? []).map((c) => c.provider))].sort(), [campaigns.data])

  const rows = useMemo(() => {
    const q = search.trim().toLowerCase()
    return rowsByLevel[level].filter((r) => {
      if (delivery === 'serving' && r.delivery !== 'serving') return false
      if (delivery === 'off' && r.delivery === 'serving') return false
      return q === '' || r.name.toLowerCase().includes(q)
    })
  }, [rowsByLevel, level, delivery, search])

  const totals = useMemo(() => rows.reduce((t, r) => {
    for (const k of ['spend', 'impressions', 'clicks']) t[k] = (t[k] ?? 0) + (Number(r.totals[k]) || 0)
    return t
  }, {} as Record<string, number>), [rows])

  if (!projectId) return <ProjectChooser purpose="analytics" />

  const label = (m: MetricKey) => METRICS.find((x) => x.key === m)![ar ? 'ar' : 'en']
  const valueOf = (t: MetricTotals | undefined, m: MetricKey): string => {
    if (!t) return '—'
    if (m === 'spend') return moneyFromTotals(t, 'spend', ar, currency).text
    if (m === 'ctr') return percent(t.ctr, 2)
    return compact(t[m] as number)
  }
  const chartData = (series.data ?? []).map((p) => ({ date: p.date, [metric]: p[metric], ...(compareTo && compareTo !== metric ? { [compareTo]: p[compareTo] } : {}) }))
  const chartSeries = [
    { key: metric, name: label(metric), color: 'var(--warning)', kind: metric === 'spend' ? ('money' as const) : metric === 'ctr' ? ('percent' as const) : ('num' as const) },
    ...(compareTo && compareTo !== metric ? [{ key: compareTo, name: label(compareTo), color: 'var(--success)', kind: compareTo === 'spend' ? ('money' as const) : compareTo === 'ctr' ? ('percent' as const) : ('num' as const) }] : []),
  ]

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
          {METRICS.map((m) => (
            <button key={m.key} type="button" role="tab" aria-selected={metric === m.key} data-testid={`ads-manager-kpi-${m.key}`}
              onClick={() => setMetric(m.key)}
              className={`min-w-0 border-b-2 px-4 py-3 text-start transition-colors ${metric === m.key ? 'border-text-primary bg-surface' : 'border-transparent bg-surface-secondary/60 hover:bg-surface-secondary'}`}>
              <span className="block truncate text-xs text-text-secondary">{ar ? m.ar : m.en}</span>
              <span className="block text-lg font-bold text-text-primary">{summary.isPending ? <Skeleton className="h-6 w-20" /> : <Num>{valueOf(summary.data?.current, m.key)}</Num>}</span>
            </button>
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

      {/* Delivery, platform and one search. */}
      <div className="flex flex-wrap items-center gap-2">
        <div role="group" aria-label={ar ? 'التسليم' : 'Delivery'} className="flex rounded-xl bg-surface-secondary p-1">
          {([['all', ar ? 'الكل' : 'All'], ['serving', ar ? 'قيد التسليم' : 'Delivering'], ['off', ar ? 'متوقفة' : 'Not delivering']] as const).map(([k, t]) => (
            <button key={k} type="button" aria-pressed={delivery === k} onClick={() => setDelivery(k)} data-testid={`ads-manager-delivery-${k}`}
              className={`rounded-lg px-3 py-1.5 text-sm font-semibold ${delivery === k ? 'bg-surface text-text-primary shadow-[var(--shadow-small)]' : 'text-text-secondary hover:text-text-primary'}`}>{t}</button>
          ))}
        </div>
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
                ar ? 'الإنفاق' : 'Amount spent',
                ...(level !== 'accounts' ? [ar ? 'النتائج' : 'Result', ar ? 'تكلفة النتيجة' : 'Cost per result'] : []),
                ar ? 'الظهور' : 'Impressions',
                ar ? 'النقرات' : 'Clicks',
                ar ? 'معدل النقر' : 'Click rate',
              ]}
              values={rows.map((r) => {
                const t = r.totals as unknown as MetricTotals
                const result = level === 'accounts' ? null : campaignResult(r.objective, r.totals)
                return [
                  r.name, ...(level === 'campaigns' ? [r.running ? 1 : 0] : []), r.delivery, t.spend ?? null,
                  ...(result ? [result.value, result.value ? (t.spend ?? 0) / result.value : null] : []),
                  t.impressions ?? null, t.clicks ?? null, t.ctr ?? null,
                ]
              })}
              rows={rows.map((r) => {
                const result = level === 'accounts' ? null : campaignResult(r.objective, r.totals)
                const d = DELIVERY_TEXT[r.delivery]
                const t = r.totals as unknown as MetricTotals
                return [
                  <div key="n" className="flex items-center gap-2.5" data-testid="ads-manager-row">
                    {r.provider && <ProviderMark provider={r.provider} size={20} className="shrink-0" />}
                    <span className="min-w-0">
                      {level === 'campaigns' && r.campaignId
                        ? <Link to={portal(`/campaigns/${projectId}/${r.campaignId}`)} className="block truncate font-semibold text-text-primary hover:underline">{r.name}</Link>
                        : <span className="block truncate font-semibold text-text-primary">{r.name}</span>}
                      {r.sub && <span className="block truncate text-xs text-text-muted">{r.sub}</span>}
                    </span>
                  </div>,
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
                  <span key="sp" className="font-semibold">{moneyFromTotals(t, 'spend', ar, currency).text}</span>,
                  ...(result ? [
                    <span key="r" className="flex flex-col"><span>{result.value === null ? '—' : num(result.value)}</span><span className="text-xs text-text-muted">{resultLabel(result, ar)}</span></span>,
                    resultCostText(result, r.totals, currency),
                  ] : []),
                  num(t.impressions),
                  num(t.clicks),
                  percent(t.ctr, 2),
                ]
              })}
              footer={[
                <span key="t" className="font-semibold" data-testid="ads-manager-totals">{ar ? `الإجمالي · ${rows.length}` : `Total · ${rows.length}`}</span>,
                ...(level === 'campaigns' ? ['—'] : []),
                '—',
                money(totals.spend ?? 0, currency),
                ...(level !== 'accounts' ? ['—', '—'] : []),
                num(totals.impressions),
                num(totals.clicks),
                totals.impressions ? percent((totals.clicks ?? 0) / totals.impressions, 2) : '—',
              ]}
            />
          )}
        </div>
      </section>

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

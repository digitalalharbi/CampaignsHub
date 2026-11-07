import { useEffect, useMemo, useState } from 'react'
import { ChevronLeft, ChevronRight, Home, X } from 'lucide-react'
import { getData } from '@/lib/api/client'
import { Badge } from '@/components/ui/Badge'
import { FixedPageTable, type FixedColumn } from '@/components/ui/FixedPageTable'
import { Num } from '@/components/ui/Num'
import { QueryFailure } from '@/components/ui/QueryFailure'
import { ChartBodySkeleton } from '@/components/ui/Skeletons'
import { useSwrQuery } from '@/lib/query/swr'
import { providerLabel } from '@/features/campaigns/labels'
import { useDeleteView, useSaveView, useSavedViews } from '@/features/dashboard/savedViews'
import { ChartCard } from '../charts'
import { money, num, percent } from '../format'
import { Ga4StoreSection } from './Ga4StoreSection'
import { Ga4VisibilityControl } from './Ga4VisibilityBar'
import { AttributionTable, Buckets, GapReasonsBlock, MatchBars, campaignRows, platformRows, type MatchRow } from './Ga4MatchBlock'
import { CLASSIFY_GROUPS, type ClassificationPayload, type ClassificationRow, type ClassifyGroup, type GapReason, type GapReasonsPayload, type MatchPayload } from './matchTypes'
import { hideParam } from './visibility'

/**
 * GA4-ATTRIBUTION-MATCH-001 — the Analytics GA4 tab as one workbench: a visibility control, a filter bar of chips,
 * the store reading, the claimed-vs-measured match with its drill-down, the reasons of the gap, and a
 * classification of the traffic by one chosen axis. Marketing and store sales are analysed in ONE place; what
 * conflicts with campaign management is one preset away from hidden, and the server — not the page — does the cutting.
 *
 * Every drawn table is a FixedPageTable: its box is the same at every page and every drill level.
 */
type Filters = { platform: string | null; channel: string | null; compare: boolean; group: ClassifyGroup }
const NO_FILTERS: Filters = { platform: null, channel: null, compare: false, group: 'channel' }
const MODULE = 'ga4_site'

const GROUP_LABEL: Record<ClassifyGroup, { ar: string; en: string }> = {
  channel: { ar: 'قناة', en: 'Channel' }, source_medium: { ar: 'مصدر', en: 'Source' }, platform: { ar: 'منصة', en: 'Platform' }, campaign: { ar: 'حملة', en: 'Campaign' },
  landing_page: { ar: 'صفحة', en: 'Page' }, product: { ar: 'منتج', en: 'Product' }, product_category: { ar: 'فئة', en: 'Category' }, device: { ar: 'جهاز', en: 'Device' },
  country: { ar: 'دولة', en: 'Country' }, user_type: { ar: 'عميل', en: 'Visitor' },
}

function qs(range: { from: string; to: string }, f: Partial<Filters>, hidden: string[], extra = ''): string {
  const p = [`from=${range.from}`, `to=${range.to}`]
  if (f.platform) p.push(`platform=${encodeURIComponent(f.platform)}`)
  if (f.channel) p.push(`channel=${encodeURIComponent(f.channel)}`)
  if (f.compare) p.push('compare=1')

  return `?${p.join('&')}${hideParam(hidden)}${extra}`
}

export function Ga4Workbench({ projectId, range, ar }: { projectId: string; range: { from: string; to: string }; ar: boolean }) {
  const [hidden, setHidden] = useState<string[]>([])
  const [f, setF] = useState<Filters>(NO_FILTERS)
  const [drill, setDrill] = useState<{ platform: string; campaign: string | null } | null>(null)
  const hideKey = [...hidden].sort().join(',')

  const match = useSwrQuery({
    name: 'ga4-match', scope: [projectId, f.platform, f.channel, f.compare, hideKey], window: [range.from, range.to],
    queryFn: () => getData<MatchPayload>(`/projects/${projectId}/measurement/attribution${qs(range, f, hidden)}`),
  })
  const gap = useSwrQuery({
    name: 'ga4-gap', scope: [projectId, hideKey], window: [range.from, range.to],
    queryFn: () => getData<GapReasonsPayload>(`/projects/${projectId}/measurement/gap${qs(range, {}, hidden)}`),
  })

  const platforms = Array.isArray(match.data?.platforms) ? match.data!.platforms! : []
  const platformChips = useMemo(() => platforms.map((p) => p.platform), [platforms])

  return (
    <div data-testid="ga4-workbench" className="space-y-4">
      <ViewsRow projectId={projectId} range={range} ar={ar} hidden={hidden} filters={f} onApply={(h, next) => { setHidden(h); setF(next); setDrill(null) }} />
      <div className="rounded-2xl border border-border bg-surface p-3">
        <Ga4VisibilityControl hidden={hidden} onChange={setHidden} ar={ar} />
      </div>

      <FilterBar ar={ar} range={range} f={f} setF={(n) => { setF(n); setDrill(null) }} platforms={platformChips} />

      <Ga4StoreSection projectId={projectId} range={range} ar={ar} hide={hidden} />

      <MatchSection q={match} ar={ar} drill={drill} setDrill={setDrill} projectId={projectId} range={range} hidden={hidden} compare={f.compare} />

      {gap.data?.state === 'ready' && Array.isArray(gap.data.reasons) ? <GapPanel data={gap.data} ar={ar} /> : null}

      <ClassifySection projectId={projectId} range={range} ar={ar} f={f} setF={setF} hidden={hidden} drillCampaign={drill?.campaign ?? null} />
    </div>
  )
}

function FilterBar({ ar, range, f, setF, platforms }: { ar: boolean; range: { from: string; to: string }; f: Filters; setF: (f: Filters) => void; platforms: string[] }) {
  const chip = (active: boolean) => `rounded-full px-3 py-1 text-xs font-bold transition-colors ${active ? 'bg-brand-600 text-white' : 'bg-surface-secondary text-text-secondary hover:text-text-primary'}`
  const channels = ['Paid Social', 'Paid Search', 'Organic Search', 'Direct', 'Referral', 'Email']
  const chLabel: Record<string, { ar: string; en: string }> = { 'Paid Social': { ar: 'اجتماعي مدفوع', en: 'Paid social' }, 'Paid Search': { ar: 'بحث مدفوع', en: 'Paid search' }, 'Organic Search': { ar: 'بحث مجاني', en: 'Organic' }, Direct: { ar: 'مباشر', en: 'Direct' }, Referral: { ar: 'إحالة', en: 'Referral' }, Email: { ar: 'بريد', en: 'Email' } }

  return (
    <div data-testid="ga4-filter-bar" className="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-2xl border border-border bg-surface p-3">
      <Badge tone="neutral" data-testid="ga4-filter-period"><Num>{range.from} → {range.to}</Num></Badge>
      <button type="button" aria-pressed={f.compare} data-testid="ga4-filter-compare" className={chip(f.compare)} onClick={() => setF({ ...f, compare: !f.compare })}>{ar ? 'مقارنة بالسابق' : 'vs previous'}</button>
      <span className="flex flex-wrap items-center gap-1" role="group" aria-label={ar ? 'المنصة' : 'Platform'}>
        <button type="button" aria-pressed={f.platform === null} className={chip(f.platform === null)} data-testid="ga4-filter-platform-all" onClick={() => setF({ ...f, platform: null })}>{ar ? 'كل المنصات' : 'All platforms'}</button>
        {platforms.map((p) => <button key={p} type="button" aria-pressed={f.platform === p} data-testid={`ga4-filter-platform-${p}`} className={chip(f.platform === p)} onClick={() => setF({ ...f, platform: f.platform === p ? null : p })}>{providerLabel(p, ar ? 'ar' : 'en')}</button>)}
      </span>
      <span className="flex flex-wrap items-center gap-1" role="group" aria-label={ar ? 'القناة' : 'Channel'}>
        {channels.map((c) => <button key={c} type="button" aria-pressed={f.channel === c} data-testid={`ga4-filter-channel-${c.replace(' ', '_')}`} className={chip(f.channel === c)} onClick={() => setF({ ...f, channel: f.channel === c ? null : c })}>{ar ? chLabel[c].ar : chLabel[c].en}</button>)}
      </span>
      {(f.platform || f.channel) && <button type="button" data-testid="ga4-filter-clear" className="inline-flex items-center gap-1 text-xs font-bold text-text-muted hover:text-text-primary" onClick={() => setF({ ...f, platform: null, channel: null })}><X size={12} />{ar ? 'مسح' : 'Clear'}</button>}
    </div>
  )
}

function MatchSection({ q, ar, drill, setDrill, projectId, range, hidden, compare }: { q: ReturnType<typeof useSwrQuery<MatchPayload>>; ar: boolean; drill: { platform: string; campaign: string | null } | null; setDrill: (d: { platform: string; campaign: string | null } | null) => void; projectId: string; range: { from: string; to: string }; hidden: string[]; compare: boolean }) {
  if (q.isLoading) return <ChartCard title={ar ? 'مطابقة الإسناد' : 'Attribution match'}><ChartBodySkeleton height={260} testid="ga4-match-skeleton" /></ChartCard>
  if (q.isError || !q.data) return <QueryFailure error={q.error} ar={ar} testId="ga4-match-failure" onRetry={() => void q.refetch()} fallbackTitle={ar ? 'تعذّرت القراءة' : 'Could not be read'} />
  const d = q.data
  if (d.state !== 'ready') return d.state === 'hidden' ? null : null
  const platforms = Array.isArray(d.platforms) ? d.platforms : []
  const current = drill ? platforms.find((p) => p.platform === drill.platform) : undefined
  const rows: MatchRow[] = current ? campaignRows(current) : platformRows(platforms)
  const onDrill = (r: MatchRow) => {
    if (r.kind === 'platform') setDrill({ platform: r.platform, campaign: null })
    else if (r.kind === 'campaign' && r.name) setDrill({ platform: r.platform, campaign: r.name })
  }

  return (
    <ChartCard title={ar ? 'مطابقة الإسناد' : 'Attribution match'} action={<span className="inline-flex items-center gap-1.5"><Badge tone="info" data-testid="match-basis">{ar ? 'الموقع' : 'Website'}</Badge>{compare && d.previous && <Badge tone="neutral" data-testid="match-previous"><Num>{d.previous.period.from} → {d.previous.period.to}</Num></Badge>}</span>}>
      <div data-testid="ga4-match" className="flex min-w-0 flex-col gap-3">
        <Breadcrumb ar={ar} drill={drill} onBack={() => setDrill(drill?.campaign ? { platform: drill.platform, campaign: null } : null)} onHome={() => setDrill(null)} />
        {drill?.campaign ? (
          <LandingPages projectId={projectId} range={range} campaign={drill.campaign} ar={ar} hidden={hidden} />
        ) : (
          <>
            <MatchBars rows={rows} ar={ar} />
            <AttributionTable rows={rows} ar={ar} internal={platforms.some((p) => p.campaigns !== undefined)} onDrill={onDrill} testKey={drill?.platform ?? 'platforms'} />
            <Buckets data={d} ar={ar} />
          </>
        )}
      </div>
    </ChartCard>
  )
}

function Breadcrumb({ ar, drill, onBack, onHome }: { ar: boolean; drill: { platform: string; campaign: string | null } | null; onBack: () => void; onHome: () => void }) {
  const Chev = ar ? ChevronLeft : ChevronRight

  return (
    <nav data-testid="match-breadcrumb" aria-label={ar ? 'المسار' : 'Path'} className="flex min-h-8 flex-wrap items-center gap-1.5">
      <button type="button" data-testid="match-crumb-root" onClick={onHome} disabled={!drill} className="inline-flex items-center gap-1 rounded-full bg-surface-secondary px-2.5 py-1 text-xs font-bold text-text-secondary enabled:hover:text-text-primary"><Home size={12} />{ar ? 'المنصات' : 'Platforms'}</button>
      {drill && <><Chev size={12} aria-hidden /><button type="button" data-testid="match-crumb-platform" onClick={() => onBack()} disabled={!drill.campaign} className="rounded-full bg-surface-secondary px-2.5 py-1 text-xs font-bold">{providerLabel(drill.platform, ar ? 'ar' : 'en')}</button></>}
      {drill?.campaign && <><Chev size={12} aria-hidden /><span data-testid="match-crumb-campaign" dir="auto" className="rounded-full bg-brand-600 px-2.5 py-1 text-xs font-bold text-white">{drill.campaign}</span></>}
      {drill && <button type="button" data-testid="match-back" onClick={onBack} className="ms-auto rounded-full border border-border px-2.5 py-1 text-xs font-bold text-text-secondary hover:text-text-primary">{ar ? 'رجوع' : 'Back'}</button>}
    </nav>
  )
}

function classColumns(ar: boolean, first: string, items: boolean): FixedColumn<ClassificationRow>[] {
  const c = (v: number | null | undefined, f: (x: number) => string = num) => (v === null || v === undefined ? '—' : f(v))
  if (items) {
    return [
      { key: 'label', header: first, width: '46%', value: (r) => r.label, title: (r) => r.label, render: (r) => <span dir="auto" className="font-semibold">{r.label}</span> },
      { key: 'items_purchased', header: ar ? 'المباع' : 'Sold', width: '24%', align: 'end', value: (r) => r.items_purchased ?? null, render: (r) => <Num>{c(r.items_purchased)}</Num> },
      { key: 'revenue', header: ar ? 'المبيعات' : 'Sales', width: '30%', align: 'end', value: (r) => r.revenue_usd ?? r.item_revenue ?? null, render: (r) => <Num>{c(r.revenue_usd, (x) => money(x, 'USD'))}</Num> },
    ]
  }

  return [
    { key: 'label', header: first, width: '32%', value: (r) => r.label, title: (r) => r.label, render: (r) => <span dir="auto" className="font-semibold">{r.label}</span> },
    { key: 'sessions', header: ar ? 'الزيارات' : 'Sessions', width: '15%', align: 'end', value: (r) => r.sessions ?? null, render: (r) => <Num>{c(r.sessions)}</Num> },
    { key: 'share', header: ar ? 'الحصة' : 'Share', width: '12%', align: 'end', value: (r) => r.share ?? null, render: (r) => <Num>{c(r.share, (x) => percent(x, 0))}</Num> },
    { key: 'purchases', header: ar ? 'الشراء' : 'Orders', width: '13%', align: 'end', value: (r) => r.purchases ?? null, render: (r) => <Num>{c(r.purchases)}</Num> },
    { key: 'conversion_rate', header: ar ? 'التحويل' : 'CVR', width: '12%', align: 'end', value: (r) => r.conversion_rate ?? null, render: (r) => <Num>{c(r.conversion_rate, (x) => percent(x, 2))}</Num> },
    { key: 'revenue', header: ar ? 'المبيعات' : 'Sales', width: '16%', align: 'end', value: (r) => r.revenue_usd ?? null, render: (r) => <Num>{c(r.revenue_usd, (x) => money(x, 'USD'))}</Num> },
  ]
}

function LandingPages({ projectId, range, campaign, ar, hidden }: { projectId: string; range: { from: string; to: string }; campaign: string; ar: boolean; hidden: string[] }) {
  const q = useSwrQuery({
    name: 'ga4-landing-of-campaign', scope: [projectId, campaign, [...hidden].sort().join(',')], window: [range.from, range.to],
    queryFn: () => getData<ClassificationPayload>(`/projects/${projectId}/measurement/classification?group=landing_page&campaign=${encodeURIComponent(campaign)}&per_page=50&from=${range.from}&to=${range.to}${hideParam(hidden)}`),
  })
  if (q.isLoading) return <ChartBodySkeleton height={260} testid="ga4-landing-skeleton" />

  return <FixedPageTable testId="match-landing-table" ar={ar} pageSize={8} rows={Array.isArray(q.data?.rows) ? q.data!.rows : []} rowKey={(r) => r.label} columns={classColumns(ar, ar ? 'الصفحة' : 'Page', false)} initialSort={{ key: 'sessions', dir: 'desc' }} />
}

function GapPanel({ data, ar }: { data: GapReasonsPayload; ar: boolean }) {
  const [open, setOpen] = useState<GapReason | null>(null)

  return (
    <div className="space-y-2" data-testid="ga4-gap-panel">
      <GapReasonsBlock data={data} ar={ar} onReason={(r) => setOpen(open?.key === r.key ? null : r)} />
      {open && (
        <div data-testid="gap-reason-drill" className="rounded-2xl border border-border bg-surface p-3">
          <div className="mb-2 flex items-center gap-2"><Badge tone="info">{ar ? open.label_ar : open.label_en}</Badge><button type="button" onClick={() => setOpen(null)} className="text-xs font-bold text-text-muted">{ar ? 'إغلاق' : 'Close'}</button></div>
          <FixedPageTable testId="gap-reason-table" ar={ar} pageSize={6} rows={open.rows} rowKey={(r) => `${r.platform ?? r.source}|${r.campaign ?? ''}`}
            columns={[
              { key: 'who', header: ar ? 'المصدر' : 'Source', width: '46%', value: (r) => r.campaign ?? r.platform ?? r.source ?? '', title: (r) => r.campaign ?? r.platform ?? r.source ?? '', render: (r) => <span dir="auto" className="font-semibold">{r.campaign ?? (r.platform ? providerLabel(r.platform, ar ? 'ar' : 'en') : r.source)}</span> },
              { key: 'claimed', header: ar ? 'المنصة' : 'Claimed', width: '27%', align: 'end', value: (r) => r.claimed ?? null, render: (r) => <Num>{r.claimed == null ? '—' : num(r.claimed)}</Num> },
              { key: 'ga4', header: ar ? 'الموقع' : 'Website', width: '27%', align: 'end', value: (r) => r.ga4 ?? null, render: (r) => <Num>{r.ga4 == null ? '—' : num(r.ga4)}</Num> },
            ]} />
        </div>
      )}
    </div>
  )
}

function ClassifySection({ projectId, range, ar, f, setF, hidden, drillCampaign }: { projectId: string; range: { from: string; to: string }; ar: boolean; f: Filters; setF: (f: Filters) => void; hidden: string[]; drillCampaign: string | null }) {
  const hideKey = [...hidden].sort().join(',')
  const q = useSwrQuery({
    name: 'ga4-classify', scope: [projectId, f.group, f.platform, f.channel, drillCampaign, hideKey], window: [range.from, range.to],
    queryFn: () => getData<ClassificationPayload>(`/projects/${projectId}/measurement/classification?group=${f.group}&per_page=50${f.platform ? `&platform=${encodeURIComponent(f.platform)}` : ''}${f.channel ? `&channel=${encodeURIComponent(f.channel)}` : ''}${drillCampaign ? `&campaign=${encodeURIComponent(drillCampaign)}` : ''}&from=${range.from}&to=${range.to}${hideParam(hidden)}`),
  })
  const items = f.group === 'product' || f.group === 'product_category'
  const d = q.data

  return (
    <ChartCard title={ar ? 'تصنيف' : 'Classify'} action={<Badge tone="info">{ar ? 'الموقع' : 'Website'}</Badge>}>
      <div data-testid="ga4-classify" className="flex min-w-0 flex-col gap-3">
        <div role="radiogroup" aria-label={ar ? 'التصنيف' : 'Group by'} className="flex flex-wrap gap-1">
          {CLASSIFY_GROUPS.map((g) => (
            <button key={g} type="button" role="radio" aria-checked={f.group === g} data-testid={`ga4-group-${g}`} onClick={() => setF({ ...f, group: g })}
              className={`rounded-full px-3 py-1 text-xs font-bold transition-colors ${f.group === g ? 'bg-brand-600 text-white' : 'bg-surface-secondary text-text-secondary hover:text-text-primary'}`}>{ar ? GROUP_LABEL[g].ar : GROUP_LABEL[g].en}</button>
          ))}
        </div>
        {d && (d.unsupported?.length ?? 0) > 0 && <div data-testid="ga4-classify-unsupported" className="flex flex-wrap gap-1.5">{d.unsupported!.map((u) => <Badge key={u} tone="neutral">{u} · {ar ? 'لا ينطبق' : 'n/a'}</Badge>)}</div>}
        {q.isLoading ? <ChartBodySkeleton height={260} testid="ga4-classify-skeleton" /> : d?.state === 'hidden' ? <Badge tone="neutral">{ar ? 'مخفي' : 'Hidden'}</Badge> : (
          <FixedPageTable key={f.group} testId="ga4-classify-table" ar={ar} pageSize={8} rows={Array.isArray(d?.rows) ? d!.rows : []} rowKey={(r) => r.label} columns={classColumns(ar, ar ? GROUP_LABEL[f.group].ar : GROUP_LABEL[f.group].en, items)} initialSort={{ key: items ? 'revenue' : 'sessions', dir: 'desc' }} />
        )}
      </div>
    </ChartCard>
  )
}

function ViewsRow({ projectId, range, ar, hidden, filters, onApply }: { projectId: string; range: { from: string; to: string }; ar: boolean; hidden: string[]; filters: Filters; onApply: (hidden: string[], f: Filters) => void }) {
  const views = useSavedViews(true, MODULE)
  const save = useSaveView(MODULE)
  const del = useDeleteView(MODULE)
  const [applied, setApplied] = useState(false)
  const list = Array.isArray(views.data) ? views.data : []

  // The user's default view is applied once, when the list first arrives.
  useEffect(() => {
    if (applied || !Array.isArray(views.data)) return
    setApplied(true)
    const d = views.data.find((v) => v.is_default)
    if (d) onApply(d.filters?.hidden ?? [], { ...NO_FILTERS, ...((d.filters as { ga4?: Partial<Filters> } | null)?.ga4 ?? {}) })
  }, [applied, views.data, onApply])

  const days = Math.max(1, Math.round((Date.parse(range.to) - Date.parse(range.from)) / 86_400_000) + 1)

  return (
    <div data-testid="ga4-saved-views" data-project={projectId} className="flex flex-wrap items-center gap-2">
      <span className="text-xs font-bold text-text-muted">{ar ? 'العروض' : 'Views'}</span>
      {list.map((v) => (
        <span key={v.id} className="inline-flex items-center gap-1 rounded-full border border-border bg-surface px-2 py-0.5 text-xs">
          <button type="button" data-testid={`ga4-view-${v.id}`} className="font-bold text-text-secondary hover:text-text-primary" onClick={() => onApply(v.filters?.hidden ?? [], { ...NO_FILTERS, ...((v.filters as { ga4?: Partial<Filters> } | null)?.ga4 ?? {}) })}>{v.name}</button>
          <button type="button" aria-label={ar ? 'حذف' : 'Delete'} className="text-text-muted hover:text-danger" onClick={() => del.mutate(v.id)}><X size={11} /></button>
        </span>
      ))}
      <button type="button" data-testid="ga4-view-save" disabled={save.isPending} className="rounded-full border border-brand-600 px-2.5 py-0.5 text-xs font-bold text-brand-600 hover:bg-brand-600/10 disabled:opacity-60"
        onClick={() => { const name = window.prompt(ar ? 'اسم العرض' : 'View name')?.trim(); if (name) save.mutate({ name, filters: { hidden, ga4: filters } as never, date_range: { days } }) }}>{ar ? 'حفظ' : 'Save'}</button>
    </div>
  )
}

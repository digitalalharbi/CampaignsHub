import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { ExternalLink, Image as ImageIcon, LayoutGrid, Play, Rows3, Search, Images, X } from 'lucide-react'
import { FilterGroup, ViewCustomiser } from '@/components/ui/ViewCustomiser'
import { useUi } from '@/stores/ui'
import { fmtDate } from '@/lib/datetime'
import { listCreatives, type Creative } from './api'

const COPY = {
  ar: {
    title: 'المحتويات', subtitle: 'مكتبة الإعلانات المزامَنة عبر منصاتك — استعرضها وحلّل أداءها في مكان واحد.',
    search_ph: 'ابحث بالاسم أو الحملة…', all: 'الكل',
    sum_total: 'إجمالي المحتويات', sum_active: 'نشِطة', sum_paused: 'متوقفة', sum_spend: 'الإنفاق (30 يومًا)',
    none: 'لا توجد محتويات بعد — تظهر هنا بعد مزامنة الحملات.', no_match: 'لا محتويات تطابق البحث أو الفلاتر.',
    loading: 'جارٍ التحميل…', error: 'تعذّر تحميل المحتويات.',
    provider: 'المنصة', format: 'النوع', status: 'الحالة', campaign: 'الحملة', spend: 'الإنفاق', impressions: 'الظهور',
    details: 'تفاصيل المحتوى', no_preview: 'لا تتوفر معاينة من المنصة', open_dest: 'فتح الوجهة', last_sync: 'آخر مزامنة', close: 'إغلاق',
    view_grid: 'شبكة', view_table: 'جدول', demo: 'تجريبي',
    perf_all: 'الكل', perf_top: 'أفضل المحتويات', perf_attention: 'تحتاج تدخلًا', perf_reason: 'السبب',
    clicks: 'النقرات', conversions: 'التحويلات', ctr: 'نسبة النقر', roas: 'العائد',
  },
  en: {
    title: 'Content', subtitle: 'The ad library synced across your platforms — browse and analyze it in one place.',
    search_ph: 'Search by name or campaign…', all: 'All',
    sum_total: 'Total content', sum_active: 'Active', sum_paused: 'Paused', sum_spend: 'Spend (30d)',
    none: 'No content yet — creatives appear here after campaigns sync.', no_match: 'No content matches your search or filters.',
    loading: 'Loading…', error: 'Could not load content.',
    provider: 'Platform', format: 'Format', status: 'Status', campaign: 'Campaign', spend: 'Spend', impressions: 'Impressions',
    details: 'Creative details', no_preview: 'No preview provided by the platform', open_dest: 'Open destination', last_sync: 'Last sync', close: 'Close',
    view_grid: 'Grid', view_table: 'Table', demo: 'Demo',
    perf_all: 'All', perf_top: 'Top content', perf_attention: 'Needs attention', perf_reason: 'Reason',
    clicks: 'Clicks', conversions: 'Conversions', ctr: 'CTR', roas: 'ROAS',
  },
}
type Copy = (typeof COPY)['ar']

const PROVIDER: Record<string, { label: string; color: string }> = {
  meta: { label: 'Meta', color: '#1877F2' },
  google: { label: 'Google Ads', color: '#4285F4' },
  snapchat: { label: 'Snapchat', color: '#F5B800' },
  tiktok: { label: 'TikTok', color: '#111111' },
  x: { label: 'X', color: '#111111' },
  linkedin: { label: 'LinkedIn', color: '#0A66C2' },
}
const providerLabel = (p: string) => PROVIDER[p]?.label ?? p
const providerColor = (p: string) => PROVIDER[p]?.color ?? '#64748b'

const FORMAT_LABEL: Record<string, { ar: string; en: string }> = {
  video: { ar: 'فيديو', en: 'Video' },
  image: { ar: 'صورة', en: 'Image' },
  carousel: { ar: 'دوّار', en: 'Carousel' },
}
const formatLabel = (f: string, ar: boolean) => (FORMAT_LABEL[f] ? (ar ? FORMAT_LABEL[f].ar : FORMAT_LABEL[f].en) : f)

const STATUS_LABEL: Record<string, { ar: string; en: string; tone: string }> = {
  active: { ar: 'نشِط', en: 'Active', tone: 'bg-success/15 text-success' },
  paused: { ar: 'متوقف', en: 'Paused', tone: 'bg-warning/15 text-warning' },
}
const statusMeta = (s: string) => STATUS_LABEL[s] ?? { ar: s, en: s, tone: 'bg-surface-hover text-text-secondary' }

const money = (n: number) => `${n.toLocaleString('en-US', { maximumFractionDigits: 0 })} SAR`
const compact = (n: number) => n.toLocaleString('en-US', { notation: 'compact', maximumFractionDigits: 1 })

/** Render a creative's objective-group KPI (ROAS/CPA/CPM/CTR) in its natural unit. */
function kpiDisplay(x: Creative): string {
  const v = x.kpi?.value
  if (v == null) return '—'
  switch (x.kpi.name) {
    case 'roas': return `ROAS ${v.toFixed(2)}x`
    case 'cpa': return `CPA ${v.toLocaleString('en-US')}`
    case 'cpm': return `CPM ${v.toLocaleString('en-US')}`
    default: return `CTR ${(v * 100).toFixed(2)}%`
  }
}

export function CreativesPage() {
  const locale = useUi((s) => s.locale)
  const ar = locale === 'ar'
  const c = COPY[locale]

  const [term, setTerm] = useState('')
  const [provider, setProvider] = useState<'all' | string>('all')
  const [format, setFormat] = useState<'all' | string>('all')
  const [status, setStatus] = useState<'all' | string>('all')
  const [perf, setPerf] = useState<'all' | 'top' | 'needs_attention'>('all')
  const [view, setView] = useState<'grid' | 'table'>('grid')
  const [selected, setSelected] = useState<Creative | null>(null)

  const q = useQuery({ queryKey: ['creatives', 'all'], queryFn: listCreatives })
  const all = q.data ?? []

  const providers = [...new Set(all.map((x) => x.provider))]
  const formats = [...new Set(all.map((x) => x.format))]
  const statuses = [...new Set(all.map((x) => x.status))]

  const summary = {
    total: all.length,
    active: all.filter((x) => x.status === 'active').length,
    paused: all.filter((x) => x.status === 'paused').length,
    spend: all.reduce((s, x) => s + (x.metrics?.spend ?? 0), 0),
  }

  const topCount = all.filter((x) => x.performance?.class === 'top').length
  const attentionCount = all.filter((x) => x.performance?.class === 'needs_attention').length

  const needle = term.trim().toLowerCase()
  const items = all
    .filter((x) => {
      if (perf !== 'all' && x.performance?.class !== perf) return false
      if (provider !== 'all' && x.provider !== provider) return false
      if (format !== 'all' && x.format !== format) return false
      if (status !== 'all' && x.status !== status) return false
      if (needle && !`${x.name ?? ''} ${x.client_display_name ?? ''} ${x.campaign_name ?? ''}`.toLowerCase().includes(needle)) return false
      return true
    })
    // Ranked tabs sort by what earned the rank: top by ROAS→CTR desc, needs-attention by wasted spend desc.
    .sort((a, b) => {
      if (perf === 'top') return (b.metrics.roas ?? b.metrics.ctr ?? 0) - (a.metrics.roas ?? a.metrics.ctr ?? 0)
      if (perf === 'needs_attention') return b.metrics.spend - a.metrics.spend
      return 0
    })

  return (
    <div className="flex w-full flex-col gap-4">
      <header className="flex flex-col gap-1">
        <h1 className="text-3xl font-extrabold tracking-tight text-text-primary">{c.title}</h1>
        <p className="text-sm text-text-secondary">{c.subtitle}</p>
      </header>

      {/* Summary */}
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <SummaryCard label={c.sum_total} value={String(summary.total)} tone="brand" />
        <SummaryCard label={c.sum_active} value={String(summary.active)} tone="success" />
        <SummaryCard label={c.sum_paused} value={String(summary.paused)} tone="warning" />
        <SummaryCard label={c.sum_spend} value={money(summary.spend)} tone="muted" />
      </div>

      {/* Performance tabs — the workspace's own 30d baseline decides top / needs-attention. */}
      <div className="flex flex-wrap gap-1 border-b border-border">
        {([['all', `${c.perf_all} (${all.length})`], ['top', `${c.perf_top} (${topCount})`], ['needs_attention', `${c.perf_attention} (${attentionCount})`]] as const).map(([k, label]) => (
          <button key={k} onClick={() => setPerf(k)}
            className={`rounded-t-lg px-3 py-2 text-sm font-semibold transition-colors ${
              perf === k ? 'border-b-2 border-brand-600 text-brand-600' : 'text-text-secondary hover:text-text-primary'
            }`}>
            {label}
          </button>
        ))}
      </div>

      {/* Toolbar */}
      <div className="flex flex-col gap-3 rounded-2xl border border-border bg-surface p-3">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <label className="relative flex w-full items-center sm:max-w-xs">
            <Search size={15} className="pointer-events-none absolute start-3 text-text-muted" aria-hidden />
            <input value={term} onChange={(e) => setTerm(e.target.value)} placeholder={c.search_ph}
              className="w-full rounded-xl border border-border bg-surface-secondary py-2 pe-3 ps-9 text-sm text-text-primary placeholder:text-text-muted focus:border-brand-500 focus:outline-none" />
          </label>
          <div className="flex overflow-hidden rounded-lg border border-border self-start">
            <button onClick={() => setView('grid')} aria-label={c.view_grid} title={c.view_grid}
              className={`flex items-center px-2.5 py-1.5 ${view === 'grid' ? 'bg-brand-500 text-white' : 'text-text-secondary hover:bg-surface-hover'}`}><LayoutGrid size={14} /></button>
            <button onClick={() => setView('table')} aria-label={c.view_table} title={c.view_table}
              className={`flex items-center px-2.5 py-1.5 ${view === 'table' ? 'bg-brand-500 text-white' : 'text-text-secondary hover:bg-surface-hover'}`}><Rows3 size={14} /></button>
          </div>
        </div>
        {/*
          Three chip rows — platform, format, status — folded (SIMPLIFY-002). A creative library is
          browsed by looking; it opened with more ways to narrow it than thumbnails on the first row.
          Search and the grid/table switcher stay out: those are how it is looked AT.
        */}
        <ViewCustomiser
          id="content"
          ar={ar}
          active={provider !== 'all' || format !== 'all' || status !== 'all'}
          summary={
            [
              provider === 'all' ? null : providerLabel(provider),
              format === 'all' ? null : formatLabel(format, ar),
              status === 'all' ? null : statusMeta(status)[ar ? 'ar' : 'en'],
            ].filter(Boolean).join(' · ')
            || (ar ? 'كل المحتوى' : 'All content')
          }
          onClear={() => { setProvider('all'); setFormat('all'); setStatus('all') }}
        >
          <FilterGroup label={c.provider}>
            <Chip active={provider === 'all'} onClick={() => setProvider('all')}>{c.all}</Chip>
            {providers.map((p) => <Chip key={p} active={provider === p} onClick={() => setProvider(p)}>{providerLabel(p)}</Chip>)}
          </FilterGroup>
          <FilterGroup label={ar ? 'الشكل' : 'Format'}>
            <Chip tone="dark" active={format === 'all'} onClick={() => setFormat('all')}>{c.all}</Chip>
            {formats.map((f) => <Chip key={f} tone="dark" active={format === f} onClick={() => setFormat(format === f ? 'all' : f)}>{formatLabel(f, ar)}</Chip>)}
          </FilterGroup>
          <FilterGroup label={ar ? 'الحالة' : 'Status'}>
            <Chip tone="dark" active={status === 'all'} onClick={() => setStatus('all')}>{c.all}</Chip>
            {statuses.map((s) => <Chip key={s} tone="dark" active={status === s} onClick={() => setStatus(status === s ? 'all' : s)}>{statusMeta(s)[ar ? 'ar' : 'en']}</Chip>)}
          </FilterGroup>
        </ViewCustomiser>
      </div>

      {/* Body */}
      {q.isLoading ? (
        <StateBox>{c.loading}</StateBox>
      ) : q.isError ? (
        <StateBox tone="danger">{c.error}</StateBox>
      ) : items.length === 0 ? (
        <StateBox>{all.length === 0 ? c.none : c.no_match}</StateBox>
      ) : view === 'grid' ? (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
          {items.map((x) => <CreativeCard key={x.id} creative={x} ar={ar} onClick={() => setSelected(x)} />)}
        </div>
      ) : (
        <div className="overflow-x-auto rounded-2xl border border-border">
          <table className="w-full min-w-[720px] text-sm">
            <thead className="bg-surface-hover text-xs text-text-secondary">
              <tr>
                <th className="p-3 text-start font-semibold">{c.title}</th>
                <th className="p-3 text-start font-semibold">{c.provider}</th>
                <th className="p-3 text-start font-semibold">{c.format}</th>
                <th className="p-3 text-start font-semibold">{c.status}</th>
                <th className="p-3 text-start font-semibold">{c.campaign}</th>
                <th className="p-3 text-end font-semibold">{c.spend}</th>
                <th className="p-3 text-end font-semibold">{c.impressions}</th>
              </tr>
            </thead>
            <tbody>
              {items.map((x) => {
                const st = statusMeta(x.status)
                return (
                  <tr key={x.id} className="cursor-pointer border-t border-border hover:bg-surface-hover" onClick={() => setSelected(x)}>
                    <td className="p-3 font-semibold text-text-primary">{x.name ?? '—'}</td>
                    <td className="p-3"><ProviderDot provider={x.provider} /></td>
                    <td className="p-3 text-text-secondary">{formatLabel(x.format, ar)}</td>
                    <td className="p-3"><span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${st.tone}`}>{ar ? st.ar : st.en}</span></td>
                    <td className="p-3 text-text-secondary">{x.campaign_name ?? '—'}</td>
                    <td className="p-3 tnum text-end text-text-primary" dir="ltr">{money(x.metrics.spend)}</td>
                    <td className="p-3 tnum text-end text-text-secondary" dir="ltr">{compact(x.metrics.impressions)}</td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}

      {selected ? <CreativeDrawer creative={selected} c={c} ar={ar} onClose={() => setSelected(null)} /> : null}
    </div>
  )
}

function StateBox({ children, tone }: { children: React.ReactNode; tone?: 'danger' }) {
  return <p className={`rounded-xl border border-dashed p-10 text-center text-sm ${tone === 'danger' ? 'border-danger/30 bg-danger/5 text-danger' : 'border-border text-text-secondary'}`}>{children}</p>
}

function Chip({ active, onClick, children, tone }: { active: boolean; onClick: () => void; children: React.ReactNode; tone?: 'dark' }) {
  const on = tone === 'dark' ? 'bg-text-primary text-surface' : 'bg-brand-500 text-white'
  return <button onClick={onClick} className={`rounded-full px-3 py-1 text-xs font-semibold ${active ? on : 'bg-surface-hover text-text-secondary hover:text-text-primary'}`}>{children}</button>
}

function SummaryCard({ label, value, tone }: { label: string; value: string; tone: 'brand' | 'success' | 'warning' | 'muted' }) {
  const dot: Record<typeof tone, string> = { brand: 'bg-brand-500', success: 'bg-success', warning: 'bg-warning', muted: 'bg-text-muted' }
  return (
    <div className="flex flex-col gap-1 rounded-2xl border border-border bg-surface p-4">
      <div className="flex items-center gap-1.5">
        <span className={`h-2 w-2 rounded-full ${dot[tone]}`} aria-hidden />
        <span className="text-xs font-semibold text-text-secondary">{label}</span>
      </div>
      <span className="text-2xl font-extrabold tnum text-text-primary" dir="ltr">{value}</span>
    </div>
  )
}

function ProviderDot({ provider }: { provider: string }) {
  return (
    <span className="inline-flex items-center gap-1.5 text-xs font-semibold text-text-secondary">
      <span className="h-2.5 w-2.5 rounded-full" style={{ backgroundColor: providerColor(provider) }} aria-hidden />
      {providerLabel(provider)}
    </span>
  )
}

/** Explainable performance badge (⭐ top / ⚠ needs attention) with the reason on hover. */
function PerfBadge({ creative, ar }: { creative: Creative; ar: boolean }) {
  const p = creative.performance
  if (!p || p.class === 'normal') return null
  const reason = ar ? p.reason_ar : p.reason_en
  return p.class === 'top' ? (
    <span title={reason} className="inline-flex w-fit items-center gap-1 rounded-md bg-success/15 px-1.5 py-0.5 text-[10px] font-bold text-success">★ {reason}</span>
  ) : (
    <span title={reason} className="inline-flex w-fit items-center gap-1 rounded-md bg-danger/10 px-1.5 py-0.5 text-[10px] font-bold text-danger">⚠ {reason}</span>
  )
}

/** A thumbnail (only when the platform provided one) or an honest format placeholder — never fabricated. */
function Thumb({ creative, className }: { creative: Creative; className?: string }) {
  const src = creative.thumbnail_url ?? creative.preview_url
  if (src) return <img src={src} alt={creative.name ?? ''} loading="lazy" className={`object-cover ${className ?? ''}`} />
  const Icon = creative.format === 'video' ? Play : creative.format === 'carousel' ? Images : ImageIcon
  return (
    <div className={`flex items-center justify-center bg-surface-hover text-text-muted ${className ?? ''}`}>
      <Icon size={22} />
    </div>
  )
}

function CreativeCard({ creative, ar, onClick }: { creative: Creative; ar: boolean; onClick: () => void }) {
  const st = statusMeta(creative.status)
  return (
    <button onClick={onClick} className="flex flex-col overflow-hidden rounded-2xl border border-border bg-surface text-start transition-colors hover:border-brand-400">
      <div className="relative aspect-video w-full">
        <Thumb creative={creative} className="h-full w-full" />
        <span className="absolute start-2 top-2 rounded-md px-1.5 py-0.5 text-[10px] font-bold text-white" style={{ backgroundColor: providerColor(creative.provider) }}>{providerLabel(creative.provider)}</span>
        <span className={`absolute end-2 top-2 rounded-full px-2 py-0.5 text-[10px] font-semibold ${st.tone}`}>{ar ? st.ar : st.en}</span>
      </div>
      <div className="flex flex-col gap-1 p-3">
        <span className="line-clamp-1 text-sm font-semibold text-text-primary">{creative.name ?? '—'}</span>
        <PerfBadge creative={creative} ar={ar} />
        <span className="line-clamp-1 text-[11px] text-text-tertiary">{creative.campaign_name ?? '—'} · {formatLabel(creative.format, ar)}</span>
        <div className="mt-1 flex items-center justify-between text-[11px] text-text-secondary">
          <span className="tnum" dir="ltr">{money(creative.metrics.spend)}</span>
          {/* The creative's OWN group KPI — awareness shows CPM, sales shows ROAS, etc. */}
          <span className="tnum font-semibold" dir="ltr">{kpiDisplay(creative)}</span>
        </div>
      </div>
    </button>
  )
}

function CreativeDrawer({ creative, c, ar, onClose }: { creative: Creative; c: Copy; ar: boolean; onClose: () => void }) {
  const st = statusMeta(creative.status)
  return (
    <div className="fixed inset-0 z-40 flex justify-end bg-black/30" onClick={onClose}>
      <div className="flex h-full w-full max-w-md flex-col gap-4 overflow-y-auto bg-surface p-5 shadow-xl" onClick={(e) => e.stopPropagation()}>
        <div className="flex items-start justify-between gap-3">
          <h2 className="text-lg font-extrabold text-text-primary">{c.details}</h2>
          <button onClick={onClose} aria-label={c.close} className="rounded-lg p-1.5 text-text-secondary hover:bg-surface-hover"><X size={18} /></button>
        </div>

        <div className="overflow-hidden rounded-xl border border-border">
          <Thumb creative={creative} className="aspect-video w-full" />
        </div>
        {!creative.has_preview ? <p className="text-center text-[11px] text-text-tertiary">{c.no_preview}</p> : null}

        <div className="flex flex-col gap-1">
          <span className="text-base font-bold text-text-primary">{creative.name ?? '—'}</span>
          {creative.client_display_name ? <span className="text-xs text-text-secondary">{creative.client_display_name}</span> : null}
        </div>

        <dl className="flex flex-col gap-2 rounded-2xl border border-border p-4 text-sm">
          <Row label={c.provider}><ProviderDot provider={creative.provider} /></Row>
          <Row label={c.format}>{formatLabel(creative.format, ar)}</Row>
          <Row label={c.status}><span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${st.tone}`}>{ar ? st.ar : st.en}</span></Row>
          <Row label={c.campaign}>{creative.campaign_name ?? '—'}</Row>
          <div className="my-1 border-t border-border" />
          <Row label={c.spend}><span className="tnum" dir="ltr">{money(creative.metrics.spend)}</span></Row>
          <Row label={c.impressions}><span className="tnum" dir="ltr">{creative.metrics.impressions.toLocaleString('en-US')}</span></Row>
          <Row label={c.clicks}><span className="tnum" dir="ltr">{creative.metrics.clicks.toLocaleString('en-US')}</span></Row>
          <Row label={c.conversions}><span className="tnum" dir="ltr">{creative.metrics.conversions.toLocaleString('en-US')}</span></Row>
          <Row label={c.ctr}><span className="tnum" dir="ltr">{creative.metrics.ctr !== null ? `${(creative.metrics.ctr * 100).toFixed(2)}%` : '—'}</span></Row>
          <Row label={c.roas}><span className="tnum" dir="ltr">{creative.metrics.roas !== null ? `${creative.metrics.roas.toFixed(2)}x` : '—'}</span></Row>
          <Row label={c.last_sync}><span className="tnum" dir="ltr">{fmtDate(creative.last_synced_at)}</span></Row>
        </dl>

        {creative.performance && creative.performance.class !== 'normal' ? (
          <div className="flex items-center justify-between rounded-xl bg-surface-hover px-3 py-2 text-sm">
            <span className="font-semibold text-text-secondary">{c.perf_reason}</span>
            <PerfBadge creative={creative} ar={ar} />
          </div>
        ) : null}

        {creative.destination_url ? (
          <a href={creative.destination_url} target="_blank" rel="noopener noreferrer"
            className="flex items-center justify-center gap-2 rounded-lg border border-border px-3 py-2 text-sm font-semibold text-text-secondary hover:border-brand-500 hover:text-brand-600">
            <ExternalLink size={15} /> {c.open_dest}
          </a>
        ) : null}
      </div>
    </div>
  )
}

function Row({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex items-center justify-between gap-3">
      <dt className="text-text-secondary">{label}</dt>
      <dd className="font-semibold text-text-primary">{children}</dd>
    </div>
  )
}

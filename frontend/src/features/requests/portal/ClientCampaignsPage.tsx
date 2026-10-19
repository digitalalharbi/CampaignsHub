import { useEffect, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { ChevronLeft, ChevronRight, Megaphone, Search } from 'lucide-react'
import { formatDate, formatNumber, listClientCampaigns, type PortalCampaign } from './portalAccountApi'
import { PortalShell } from './PortalShell'
import { Num } from '@/components/ui/Num'
import { QueryFailure } from '@/components/ui/QueryFailure'
import { usePortalGuard } from './usePortalGuard'
import { campaignStatusLabel, objectiveLabel } from '@/features/campaigns/labels'
import { useUi, type Locale } from '@/stores/ui'

/**
 * CP-SCALE-001, CP-DRILL-001 — a client's campaigns as a searchable, filterable, PAGED list.
 *
 * A client can run thirty to a hundred campaigns, so the page never receives them whole and never filters
 * them itself: the search box, the three filters and the pager all go to the server, which counts the
 * filters' facets over the whole visible set. One compact row per campaign — name and status, objective and
 * period, three delivery figures — instead of a card each, so a page of twenty-five is a screen, not a wall.
 */
const COPY = {
  ar: {
    title: 'الحملات', subtitle: 'متابعة مباشرة لأداء حملاتك — المؤشرات الرئيسية.',
    none: 'لا توجد حملات بعد.', noMatch: 'لا توجد حملات تطابق هذا البحث.', error: 'تعذّر تحميل الحملات.',
    search: 'ابحث باسم الحملة', allStatus: 'كل الحالات', allObjective: 'كل الأهداف', allProjects: 'كل المشاريع',
    period: 'الفترة', to: '←', impressions: 'الظهور', clicks: 'النقرات', conversions: 'النتائج', ctr: 'نسبة النقر',
    unnamed: 'حملة', prev: 'السابق', next: 'التالي', of: 'من', showing: 'تعرض',
  },
  en: {
    title: 'Campaigns', subtitle: 'A live view of your campaign delivery — the headline metrics.',
    none: 'No campaigns yet.', noMatch: 'No campaigns match this search.', error: 'Could not load campaigns.',
    search: 'Search by campaign name', allStatus: 'All statuses', allObjective: 'All objectives', allProjects: 'All projects',
    period: 'Period', to: '→', impressions: 'Impressions', clicks: 'Clicks', conversions: 'Results', ctr: 'CTR',
    unnamed: 'Campaign', prev: 'Previous', next: 'Next', of: 'of', showing: 'Showing',
  },
}

const STATUS_TONE: Record<string, string> = {
  active: 'bg-success/15 text-success',
  running: 'bg-success/15 text-success',
  paused: 'bg-warning/15 text-warning',
  scheduled: 'bg-info/15 text-info',
}

const PER_PAGE = 25

export function ClientCampaignsPage() {
  const locale = useUi((s) => s.locale)
  const ar = locale === 'ar'
  const t = ar ? COPY.ar : COPY.en
  const [search, setSearch] = useState('')
  const [q, setQ] = useState('')
  const [status, setStatus] = useState('')
  const [objective, setObjective] = useState('')
  const [project, setProject] = useState('')
  const [page, setPage] = useState(1)

  // The search waits for a pause in typing: one request per word, not per keystroke.
  useEffect(() => {
    const id = setTimeout(() => { setQ(search.trim()); setPage(1) }, 300)

    return () => clearTimeout(id)
  }, [search])

  const query = useQuery({
    queryKey: ['client', 'campaigns', { q, status, objective, project, page }],
    queryFn: () => listClientCampaigns({ q, status, objective, project, page, per_page: PER_PAGE }),
    retry: false,
    placeholderData: (previous) => previous,
  })
  usePortalGuard(query.isError, query.error)

  const data = query.data
  const rows = data?.campaigns ?? []
  const filtering = q !== '' || status !== '' || objective !== '' || project !== ''
  const reset = <T,>(set: (v: T) => void) => (v: T) => { set(v); setPage(1) }

  return (
    <PortalShell title={t.title} nav showLogout>
      <div className="mb-4">
        <h1 className="font-heading text-2xl font-extrabold text-text-primary">{t.title}</h1>
        <p className="mt-1 text-sm text-text-secondary">{t.subtitle}</p>
      </div>

      {data && (data.total > 0 || filtering) && (
        <div data-testid="campaign-filters" className="mb-4 grid gap-2 sm:grid-cols-[1fr_auto_auto_auto]">
          {data.names_searchable && (
            <label className="relative">
              <Search size={15} className="pointer-events-none absolute start-3 top-1/2 -translate-y-1/2 text-text-muted" />
              <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder={t.search} aria-label={t.search} className="h-10 w-full rounded-lg border border-border bg-surface ps-9 pe-3 text-sm" />
            </label>
          )}
          <select value={status} onChange={(e) => reset(setStatus)(e.target.value)} aria-label={t.allStatus} className="h-10 rounded-lg border border-border bg-surface px-2 text-sm">
            <option value="">{t.allStatus}</option>
            {Object.entries(data.facets.status).map(([k, n]) => <option key={k} value={k}>{campaignStatusLabel(k, locale)} ({formatNumber(n)})</option>)}
          </select>
          <select value={objective} onChange={(e) => reset(setObjective)(e.target.value)} aria-label={t.allObjective} className="h-10 rounded-lg border border-border bg-surface px-2 text-sm">
            <option value="">{t.allObjective}</option>
            {Object.entries(data.facets.objective).filter(([k]) => k !== '').map(([k, n]) => <option key={k} value={k}>{objectiveLabel(k, locale)} ({formatNumber(n)})</option>)}
          </select>
          {data.facets.projects.length > 1 && (
            <select value={project} onChange={(e) => reset(setProject)(e.target.value)} aria-label={t.allProjects} className="h-10 rounded-lg border border-border bg-surface px-2 text-sm">
              <option value="">{t.allProjects}</option>
              {data.facets.projects.map((p) => <option key={p.id} value={p.id}>{p.name ?? p.id} ({formatNumber(p.count)})</option>)}
            </select>
          )}
        </div>
      )}

      {query.isLoading ? (
        <div className="space-y-2">{[0, 1, 2, 3].map((i) => <div key={i} className="h-16 animate-pulse rounded-xl bg-surface-secondary" />)}</div>
      ) : query.isError ? (
        <QueryFailure error={query.error} ar={ar} onRetry={() => void query.refetch()} fallbackTitle={t.error} testId="portal-failure" />
      ) : rows.length === 0 ? (
        <div className="flex flex-col items-center gap-2 rounded-2xl border border-border bg-surface p-12 text-center text-text-muted"><Megaphone size={26} /><span>{filtering ? t.noMatch : t.none}</span></div>
      ) : (
        <ul data-testid="campaign-list" className="divide-y divide-border overflow-hidden rounded-2xl border border-border bg-surface">
          {rows.map((c) => <CampaignRow key={c.id} campaign={c} t={t} locale={locale} />)}
        </ul>
      )}

      {data && data.last_page > 1 && (
        <nav data-testid="campaign-pager" aria-label="pages" className="mt-4 flex items-center justify-between text-sm text-text-secondary">
          <button type="button" disabled={page <= 1} onClick={() => setPage((p) => p - 1)} className="flex items-center gap-1 rounded-lg border border-border px-3 py-2 disabled:opacity-40"><ChevronLeft size={14} className="rtl:rotate-180" /> {t.prev}</button>
          <span className="tnum" dir="ltr">{page} / {data.last_page} · {formatNumber(data.total)}</span>
          <button type="button" disabled={page >= data.last_page} onClick={() => setPage((p) => p + 1)} className="flex items-center gap-1 rounded-lg border border-border px-3 py-2 disabled:opacity-40">{t.next} <ChevronRight size={14} className="rtl:rotate-180" /></button>
        </nav>
      )}
    </PortalShell>
  )
}

function CampaignRow({ campaign, t, locale }: { campaign: PortalCampaign; t: typeof COPY.ar; locale: Locale }) {
  const m = campaign.metrics
  const ctr = m.ctr === null ? '—' : `${(m.ctr * 100).toLocaleString('en-US', { maximumFractionDigits: 2 })}%`
  const hasPeriod = campaign.starts_on || campaign.ends_on

  return (
    <li className="px-4 py-3">
      <div className="flex items-start justify-between gap-2">
        <span className="min-w-0 truncate font-semibold text-text-primary">{campaign.name ?? (campaign.objective ? objectiveLabel(campaign.objective, locale) : t.unnamed)}</span>
        <span className={`whitespace-nowrap rounded-full px-2.5 py-0.5 text-[11px] font-semibold ${STATUS_TONE[campaign.status] ?? 'bg-surface-secondary text-text-muted'}`}>{campaignStatusLabel(campaign.status, locale)}</span>
      </div>
      <div className="mt-0.5 flex flex-wrap items-center gap-x-3 text-xs text-text-secondary">
        {campaign.name !== null && campaign.objective && <span>{objectiveLabel(campaign.objective, locale)}</span>}
        {hasPeriod && <span className="tnum">{formatDate(campaign.starts_on)} {t.to} {formatDate(campaign.ends_on)}</span>}
      </div>
      <dl className="mt-1.5 flex flex-wrap gap-x-5 gap-y-0.5 text-xs">
        <Metric label={t.impressions} value={formatNumber(m.impressions)} />
        <Metric label={t.clicks} value={formatNumber(m.clicks)} />
        <Metric label={t.conversions} value={formatNumber(m.conversions)} />
        <Metric label={t.ctr} value={ctr} />
      </dl>
    </li>
  )
}

function Metric({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-baseline gap-1.5">
      <dt className="text-text-muted">{label}</dt>
      <dd className="tnum font-bold text-text-primary"><Num>{value}</Num></dd>
    </div>
  )
}

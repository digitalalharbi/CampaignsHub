import { FxTotalAmount } from '@/components/money/FxAmount'
import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { AlertTriangle, Building2, CircleCheck, FolderKanban, Inbox, LayoutGrid, Megaphone, PanelRightOpen, Plus, RefreshCw, Table2, Users } from 'lucide-react'
import { NewClientDialog } from './NewClientDialog'
import { Button } from '@/components/ui/Button'
import { getTaxonomy, listClients, type ClientCard, type ClientFilters } from './api'
import { CLIENT_STATUS_LABELS, INDUSTRY_LABELS, labelOf } from './labels'
import { SearchableSelect } from '@/components/forms'
import { useTaxonomyOptions } from '@/features/taxonomy/taxonomyApi'
import { optionLabel, type Option } from '@/components/forms/types'
import { useT } from '@/lib/i18n'
import { FilterBar, FilterSearch, FilterSelect, type AppliedFilter } from '@/components/ui/FilterBar'
import { useUi } from '@/stores/ui'
import { usePortalPath } from '@/app/portalPath'
import { withMark } from '@/lib/money/mark'
import { fmtDate } from '@/lib/datetime'
import { FitPage } from '@/components/fit/FitPage'
import { KpiMini, KpiStrip } from '@/components/fit/KpiMini'
import { Pager } from '@/components/fit/Pager'
import { ResponsiveList, type ListColumn } from '@/components/fit/ResponsiveList'
import { DetailPanel } from '@/components/fit/DetailPanel'
import { useFitRows, useIsTablet } from '@/components/fit/useFitRows'
import { ProviderMark } from '@/components/ProviderMark'
import { ClientAvatar } from '@/features/agency/ClientScopeChip'
import { listClientWorkspaces } from '@/features/projects/api'
import { useBudgets, type BudgetRow } from '@/features/funding/fundingApi'
import { useAuth } from '@/stores/auth'
import { Num } from '@/components/ui/Num'
import { money } from '@/features/analytics/format'

function statusTone(s: string | null): string {
  switch (s) {
    case 'active': return 'bg-success/15 text-success'
    case 'needs_attention': return 'bg-warning/15 text-warning'
    case 'paused': case 'archived': case 'completed': return 'bg-surface-secondary text-text-muted'
    case 'prospect': return 'bg-info/15 text-info'
    default: return 'bg-brand-primary-soft text-brand-700'
  }
}

function spendCell(c: ClientCard, mixedLabel: string): string {
  if (c.spend_currency_mode === 'mixed') return mixedLabel
  if (c.spend === null) return '—'
  return withMark(c.spend.toLocaleString('en-US'), c.currency)
}

/** Spend cell: the engine's converted total when the server sent one, else the legacy per-currency text. */
function SpendValue({ c, t }: { c: ClientCard; t: ReturnType<typeof useT> }) {
  const lang = useUi((s) => s.locale)
  if (c.spend_fx) return <FxTotalAmount t={c.spend_fx} ar={lang === 'ar'} testId="client-spend-fx" />
  return <>{spendCell(c, t('cc_currency_mixed'))}</>
}

/** A card in the grid is this tall (px) and the grid's gap is 10 — read by `useFitRows`, so the count cannot drift from the CSS. */
const CARD_HEIGHT = 136
const GRID_GAP = 10

/** Three across from `lg`, two from `md`, one below — the same breakpoints the grid classes below use. */
function useGridColumns(): number {
  const read = () => {
    if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return 3
    if (window.matchMedia('(min-width: 1024px)').matches) return 3

    return window.matchMedia('(min-width: 768px)').matches ? 2 : 1
  }
  const [cols, setCols] = useState(read)
  useEffect(() => {
    const on = () => setCols(read())
    const queries = [window.matchMedia?.('(min-width: 1024px)'), window.matchMedia?.('(min-width: 768px)')].filter(Boolean) as MediaQueryList[]
    queries.forEach((q) => q.addEventListener('change', on))

    return () => queries.forEach((q) => q.removeEventListener('change', on))
  }, [])

  return cols
}

export function ClientsPortfolioPage() {
  const t = useT()
  const lang = useUi((s) => s.locale)
  const portalTo = usePortalPath()
  const tablet = useIsTablet()
  const [newOpen, setNewOpen] = useState(false)
  const [opened, setOpened] = useState<ClientCard | null>(null)
  const [view, setView] = useState<'cards' | 'table'>(() => (localStorage.getItem('clients_view') as 'cards' | 'table') || 'cards')
  /*
   * DASHBOARD-DRILLDOWN-001 — a link may name the first filter. The agency dashboard's «clients
   * needing attention» opens this page with `?status=needs_attention`; without reading it here the
   * row would land on every client and the count it carried would mean nothing on arrival.
   */
  const [params] = useSearchParams()
  const [filters, setFilters] = useState<ClientFilters>(() => ({ page: 1, ...(params.get('status') ? { status: params.get('status') as string } : {}) }))
  const [search, setSearch] = useState('')

  useEffect(() => { localStorage.setItem('clients_view', view) }, [view])

  /*
   * NOV-FIT-001 — the page asks the server for exactly as many clients as the screen holds.
   *
   * The body's height is the screen's remainder (the frame is locked to the viewport), so a row count is a
   * measurement, not a guess: rows × columns for the cards, rows for the table. The rest is a page away.
   * On a phone the page flows, the cards are one column, and the count is a fixed twelve.
   */
  const cols = useGridColumns()
  const asCards = view === 'cards' || !tablet
  const fit = useFitRows(asCards
    ? { rowHeight: CARD_HEIGHT + GRID_GAP, reserve: 44, min: 1, max: 12, fallback: 8 }
    : { min: 3, max: 30, fallback: 10 })
  const perPage = asCards ? Math.max(3, fit.rows * cols) : fit.rows

  const taxonomy = useQuery({ queryKey: ['app', 'clients', 'taxonomy'], queryFn: getTaxonomy, staleTime: 300_000 })
  /*
   * Owner 2026-11-21: the client cards carry the same picture as the project choice — the client's own logo and, for a
   * reader who holds the money key, its approved budget across its projects (spent of approved, %, state).
   */
  const workspaces = useQuery({ queryKey: ['agency-scope', 'clients'], queryFn: listClientWorkspaces })
  const canMoney = useAuth((st) => st.hasPermission('funding.view'))
  const budgets = useBudgets(false, null, canMoney)
  const extraOf = (id: string): CardExtra => {
    const logo = (workspaces.data ?? []).find((w) => w.id === id)?.logo_url ?? null
    const own = (budgets.data?.rows ?? []).filter((b) => b.client_workspace_id === id && !['void', 'no_budget', 'unset'].includes(b.status) && b.payment_mode !== 'credit')
    const approved = own.reduce((a, b) => a + b.approved, 0)
    const spent = own.reduce((a, b) => a + b.spent, 0)
    return { logo, budget: own.length > 0 && approved > 0 ? { approved, spent, ratio: spent / approved, currency: own[0].currency, worst: worstOf(own) } : null }
  }
  const statusTax = useTaxonomyOptions('client.status')
  const serviceLevelTax = useTaxonomyOptions('client.service_level')
  const industryTax = useTaxonomyOptions('client.industry')
  const query = useQuery({
    queryKey: ['app', 'clients', filters, perPage],
    queryFn: () => listClients({ ...filters, per_page: perPage }),
    placeholderData: keepPreviousData,
  })
  const rows = query.data?.data ?? []
  /*
   * The figures describe the WHOLE filtered roster, not the page on screen.
   *
   * The page now holds only what fits (a handful), so counting «active» over it would say 4 of 6 on a phone and
   * 9 of 12 on a desktop. One more request, for up to a hundred rows with the same filters, is what the three
   * counts read; the total is still the server's own number.
   */
  const { page: _page, ...scope } = filters
  const roster = useQuery({
    queryKey: ['app', 'clients', 'figures', scope],
    queryFn: () => listClients({ ...scope, page: 1, per_page: 100 }),
    placeholderData: keepPreviousData,
    staleTime: 30_000,
  })
  const figures = roster.data?.data ?? rows
  const ownerOptions = (taxonomy.data?.assignable_users ?? []).map((u) => ({ value: String(u.id), label: u.name }))

  /*
   * What the reader is looking at, in words (SIMPLIFY-002).
   *
   * Built from the same `filters` the query uses, so it can never drift from what the list actually
   * shows. Labels come from the taxonomy options rather than the stored keys — «عميل مميز» is what
   * somebody chose, `premium` is what the database calls it.
   */
  /*
   * DASHBOARD-DRILLDOWN-001 — the chip reads the option the product's way. Taxonomy options carry
   * `label_ar` / `label_en`, not `label`, so this printed the raw key («needs_attention») beside a
   * select that said «يحتاج انتباه» — visible the moment a dashboard row arrived with the filter set.
   */
  const labelOf = (opts: readonly Option[] | undefined, v: string | null | undefined) => {
    if (v == null) return null
    const opt = opts?.find((o) => o.value === v)
    return opt ? optionLabel(opt, lang) : v
  }

  const patchOne = (p: Partial<ClientFilters>) => () => setFilters((f) => ({ ...f, ...p, page: 1 }))

  /*
   * One chip per applied filter, each undoing exactly its own value.
   *
   * The summary this replaced was a joined sentence: it could say «عميل مميز · يحتاج متابعة» and
   * offer no way to drop one of the two, so the only route back was clearing everything.
   */
  const appliedChips: AppliedFilter[] = ([
    ['status', t('cc_filter_status'), labelOf(statusTax.options, filters.status), patchOne({ status: undefined })],
    ['service_level', t('cc_filter_service'), labelOf(serviceLevelTax.options, filters.service_level), patchOne({ service_level: undefined })],
    ['industry', t('cc_filter_industry'), labelOf(industryTax.options, filters.industry), patchOne({ industry: undefined })],
    ['owner', t('cc_filter_owner'), filters.owner_id != null ? labelOf(ownerOptions, String(filters.owner_id)) : null, patchOne({ owner_id: undefined })],
    ['needs_attention', t('cc_needs_attention'), filters.needs_attention ? t('cc_needs_attention') : null, patchOne({ needs_attention: undefined })],
    ['has_open_requests', t('cc_has_open_requests'), filters.has_open_requests ? t('cc_has_open_requests') : null, patchOne({ has_open_requests: undefined })],
    ['has_active_campaigns', t('cc_has_active_campaigns'), filters.has_active_campaigns ? t('cc_has_active_campaigns') : null, patchOne({ has_active_campaigns: undefined })],
    ['include_archived', t('cc_include_archived'), filters.include_archived ? t('cc_include_archived') : null, patchOne({ include_archived: undefined })],
  ] as Array<[string, string, string | null, () => void]>)
    .filter(([, , label]) => label !== null)
    .map(([key, axis, label, onRemove]) => ({ key, axis, label: label as string, onRemove }))


  const patch = (p: Partial<ClientFilters>) => setFilters((f) => ({ ...f, ...p, page: 1 }))

  const summary = {
    total: query.data?.meta.total ?? rows.length,
    active: figures.filter((c) => c.client_status === 'active').length,
    attention: figures.filter((c) => c.client_status === 'needs_attention' || c.alerts > 0).length,
    openRequests: figures.reduce((s, c) => s + c.open_requests, 0),
  }
  const ar = lang === 'ar'
  const lastPage = query.data?.meta.last_page ?? 1
  // Fewer rows fit after a resize: a page that no longer exists steps back to the last one that does.
  useEffect(() => { if ((filters.page ?? 1) > lastPage) setFilters((f) => ({ ...f, page: lastPage })) }, [lastPage, filters.page])

  const options = (opts: readonly Option[] | undefined) => [
    { value: '', label: t('cc_filter_all') },
    ...(opts ?? []).map((o) => ({ value: o.value, label: optionLabel(o, lang) })),
  ]

  const columns: ListColumn<ClientCard>[] = [
    {
      key: 'client',
      header: t('cc_col_client'),
      cell: (c) => (
        <div className="flex min-w-0 items-center gap-2.5">
          <ClientAvatar name={c.name} logo={extraOf(c.id).logo} size={32} />
          <Link to={portalTo(`/clients/${c.id}`)} title={c.name} className="min-w-0 truncate font-semibold text-brand-600 hover:underline">{c.name}</Link>
        </div>
      ),
    },
    { key: 'status', header: t('cc_filter_status'), width: '15%', cell: (c) => <StatusChip c={c} lang={lang} /> },
    { key: 'projects', header: t('col_projects'), width: '9%', align: 'center', cell: (c) => <span className="tnum">{c.projects}</span> },
    { key: 'campaigns', header: t('col_active_campaigns'), width: '11%', align: 'center', cell: (c) => <span className="tnum">{c.active_campaigns}</span> },
    { key: 'requests', header: t('col_open_requests'), width: '11%', align: 'center', cell: (c) => <span className="tnum">{c.open_requests}</span> },
    { key: 'spend', header: t('cc_col_spend'), width: '17%', align: 'end', cell: (c) => <span className="tnum" dir="ltr"><SpendValue c={c} t={t} /></span> },
    {
      key: 'more',
      header: '',
      width: '8%',
      align: 'end',
      cell: (c) => (
        <button
          type="button"
          onClick={(e) => { e.stopPropagation(); setOpened(c) }}
          aria-label={`${ar ? 'التفاصيل' : 'Details'}: ${c.name}`}
          title={ar ? 'التفاصيل' : 'Details'}
          className="inline-flex h-9 w-9 items-center justify-center rounded-lg text-text-muted hover:bg-surface-hover hover:text-text-primary"
        >
          <PanelRightOpen size={17} aria-hidden />
        </button>
      ),
    },
  ]

  return (
    <FitPage
      testid="clients-page"
      introTestid="clients-intro"
      title={t('clients_portfolio')}
      info={t('clients_subtitle')}
      actions={
        <>
          <div className="hidden items-center gap-1 rounded-lg border border-border bg-surface p-1 md:flex">
            <button onClick={() => setView('cards')} aria-label={t('cc_view_cards')} title={t('cc_view_cards')} aria-pressed={view === 'cards'}
              className={`flex h-9 w-9 items-center justify-center rounded-md ${view === 'cards' ? 'bg-brand-primary-soft text-brand-700' : 'text-text-secondary'}`}><LayoutGrid size={16} aria-hidden /></button>
            <button onClick={() => setView('table')} aria-label={t('cc_view_table')} title={t('cc_view_table')} aria-pressed={view === 'table'}
              className={`flex h-9 w-9 items-center justify-center rounded-md ${view === 'table' ? 'bg-brand-primary-soft text-brand-700' : 'text-text-secondary'}`}><Table2 size={16} aria-hidden /></button>
          </div>
          <Button data-testid="new-client" size="sm" onClick={() => setNewOpen(true)}><Plus size={14} /> {ar ? 'عميل جديد' : 'New client'}</Button>
        </>
      }
      kpis={
        /* The total is the SERVER's count; the other three are counted over the whole filtered roster (up to 100). */
        <KpiStrip testid="clients-intro-kpis">
          <KpiMini label={ar ? 'إجمالي العملاء' : 'Total clients'} value={summary.total} tone="brand" icon={Building2} />
          <KpiMini label={ar ? 'نشطون' : 'Active'} value={summary.active} tone="success" icon={CircleCheck} />
          <KpiMini label={ar ? 'يحتاجون متابعة' : 'Needs attention'} value={summary.attention} tone="warning" icon={AlertTriangle} />
          <KpiMini label={ar ? 'طلبات مفتوحة' : 'Open requests'} value={summary.openRequests} tone="info" icon={Inbox} />
        </KpiStrip>
      }
      toolbar={
        /*
          The roster's own questions, on the page — UX-SWEEP-001.

          Which clients are ACTIVE, at what service level, and whose they are, is what a portfolio page is for.
          Those three sit beside search; industry and the four flags fold under «More filters». Dense, so the
          whole bar is one row on a desktop (NOV-FIT-001).
        */
        <FilterBar
          id="clients"
          ar={ar}
          density="dense"
          applied={appliedChips}
          onReset={() => patch({
            status: undefined, service_level: undefined, industry: undefined, owner_id: undefined,
            needs_attention: undefined, has_open_requests: undefined, has_active_campaigns: undefined,
            include_archived: undefined,
          })}
          advancedActive={Boolean(filters.industry || filters.needs_attention || filters.has_open_requests || filters.has_active_campaigns || filters.include_archived)}
          advanced={
            <div className="grid gap-4">
              <div className="w-full max-w-sm">
                <SearchableSelect
                  value={filters.industry ?? null}
                  onChange={(v) => patch({ industry: v ?? undefined })}
                  options={industryTax.options}
                  loading={industryTax.isPending}
                  optionsError={industryTax.error}
                  onRetry={() => industryTax.refetch()}
                  placeholder={`${t('cc_filter_industry')}: ${t('cc_filter_all')}`}
                />
              </div>
              <div className="grid gap-2">
                <label className="flex items-center gap-1.5 text-sm text-text-secondary"><input type="checkbox" checked={Boolean(filters.needs_attention)} onChange={(e) => patch({ needs_attention: e.target.checked || undefined })} /> {t('cc_needs_attention')}</label>
                <label className="flex items-center gap-1.5 text-sm text-text-secondary"><input type="checkbox" checked={Boolean(filters.has_open_requests)} onChange={(e) => patch({ has_open_requests: e.target.checked || undefined })} /> {t('cc_has_open_requests')}</label>
                <label className="flex items-center gap-1.5 text-sm text-text-secondary"><input type="checkbox" checked={Boolean(filters.has_active_campaigns)} onChange={(e) => patch({ has_active_campaigns: e.target.checked || undefined })} /> {t('cc_has_active_campaigns')}</label>
                <label className="flex items-center gap-1.5 text-sm text-text-secondary"><input type="checkbox" checked={Boolean(filters.include_archived)} onChange={(e) => patch({ include_archived: e.target.checked || undefined })} /> {t('cc_include_archived')}</label>
              </div>
            </div>
          }
        >
          <FilterSearch
            value={search}
            placeholder={t('search')}
            testid="clients-search"
            onChange={(v) => { setSearch(v); patch({ q: v || undefined }) }}
          />
          <FilterSelect label={t('cc_filter_status')} value={filters.status ?? ''} options={options(statusTax.options)} onChange={(v) => patch({ status: v || undefined })} testid="clients-filter-status" />
          <FilterSelect label={t('cc_filter_service')} value={filters.service_level ?? ''} options={options(serviceLevelTax.options)} onChange={(v) => patch({ service_level: v || undefined })} testid="clients-filter-service" />
          <FilterSelect
            label={t('cc_filter_owner')}
            value={filters.owner_id != null ? String(filters.owner_id) : ''}
            options={[{ value: '', label: t('cc_filter_all') }, ...ownerOptions]}
            onChange={(v) => patch({ owner_id: v ? Number(v) : undefined })}
            testid="clients-filter-owner"
          />
        </FilterBar>
      }
    >
      <div ref={fit.ref} className="flex min-h-0 flex-1 flex-col">
        <div className="min-h-0 flex-1">
          {query.isLoading ? (
            <div className="grid gap-2.5 md:grid-cols-2 lg:grid-cols-3">{[0, 1, 2].map((i) => <div key={i} style={{ height: CARD_HEIGHT }} className="animate-pulse rounded-xl bg-surface-secondary" />)}</div>
          ) : rows.length === 0 ? (
            <div className="flex flex-col items-center gap-2 rounded-xl border border-border bg-surface p-10 text-center text-text-muted"><Users size={26} /> <span className="text-sm">{t('clients_empty')}</span></div>
          ) : asCards ? (
            <div data-testid="clients-cards" className="grid gap-2.5 md:grid-cols-2 lg:grid-cols-3">
              {rows.map((c) => <ClientCardView key={c.id} c={c} t={t} lang={lang} extra={extraOf(c.id)} />)}
            </div>
          ) : (
            <ResponsiveList
              testid="clients-list"
              rows={rows}
              rowKey={(c) => c.id}
              columns={columns}
              onOpen={setOpened}
              renderCard={(c) => <ClientCardView c={c} t={t} lang={lang} extra={extraOf(c.id)} />}
            />
          )}
        </div>
        <Pager
          testid="clients-pager"
          ar={ar}
          page={filters.page ?? 1}
          pages={lastPage}
          total={query.data?.meta.total}
          onPage={(page) => setFilters((f) => ({ ...f, page }))}
        />
      </div>

      <DetailPanel
        open={opened !== null}
        onClose={() => setOpened(null)}
        title={opened?.name ?? ''}
        closeLabel={ar ? 'إغلاق' : 'Close'}
        testid="client-detail"
        footer={opened && <Link to={portalTo(`/clients/${opened.id}`)}><Button size="sm">{ar ? 'فتح العميل' : 'Open client'}</Button></Link>}
      >
        {opened && <ClientDetail c={opened} t={t} lang={lang} />}
      </DetailPanel>
      <NewClientDialog open={newOpen} onClose={() => setNewOpen(false)} />
    </FitPage>
  )
}

function StatusChip({ c, lang }: { c: ClientCard; lang: 'ar' | 'en' }) {
  return <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold ${statusTone(c.client_status)}`}>{labelOf(CLIENT_STATUS_LABELS, c.client_status, lang)}</span>
}

/** A figure with its icon, and its name as the tooltip and the screen-reader text — the label no longer takes a line. */
function Stat({ icon: Icon, value, label }: { icon: typeof FolderKanban; value: number; label: string }) {
  return (
    <span title={label} className="inline-flex items-center gap-1 text-text-secondary">
      <Icon size={15} aria-hidden />
      <span className="tnum text-base font-bold text-text-primary">{value}</span>
      <span className="sr-only">{label}</span>
    </span>
  )
}

/** Everything the one-line row does not carry. */
function ClientDetail({ c, t, lang }: { c: ClientCard; t: ReturnType<typeof useT>; lang: 'ar' | 'en' }) {
  const rowsOf: Array<[string, React.ReactNode]> = [
    [t('cc_filter_status'), <StatusChip key="s" c={c} lang={lang} />],
    [t('col_projects'), <span key="p" className="tnum">{c.projects}</span>],
    [t('col_active_campaigns'), <span key="c" className="tnum">{c.active_campaigns}</span>],
    [t('col_open_requests'), <span key="r" className="tnum">{c.open_requests}</span>],
    [t('cc_col_alerts'), <span key="a" className="tnum">{c.alerts > 0 ? c.alerts : '—'}</span>],
    [t('cc_col_spend'), <span key="sp" className="tnum" dir="ltr"><SpendValue c={c} t={t} /></span>],
    [t('cc_col_last_sync'), <span key="l" className="tnum" dir="ltr">{fmtDate(c.last_sync_at)}</span>],
  ]

  return (
    <div data-testid="client-detail-body" className="grid gap-3">
      {c.industry && <p className="text-text-muted">{labelOf(INDUSTRY_LABELS, c.industry, lang)}</p>}
      <dl className="grid gap-2">
        {rowsOf.map(([k, v]) => (
          <div key={k} className="flex items-center justify-between gap-3 border-b border-border/60 pb-2 last:border-0">
            <dt className="text-text-muted">{k}</dt>
            <dd className="font-semibold text-text-primary">{v}</dd>
          </div>
        ))}
      </dl>
      {c.data_sources.length > 0 && (
        <div className="flex flex-wrap items-center gap-1.5">
          {c.data_sources.map((s) => <span key={s} title={s} className="inline-flex items-center gap-1.5 rounded-lg bg-surface-secondary px-2 py-1 text-xs text-text-secondary"><ProviderMark provider={s} size={18} />{s}</span>)}
        </div>
      )}
    </div>
  )
}

type CardExtra = { logo: string | null; budget: { approved: number; spent: number; ratio: number; currency: string; worst: 'danger' | 'warning' | 'success' } | null }

function worstOf(rows: BudgetRow[]): 'danger' | 'warning' | 'success' {
  if (rows.some((b) => (b.remaining !== null && b.remaining <= 0) || (b.used_ratio ?? 0) >= 1)) return 'danger'
  if (rows.some((b) => (b.used_ratio ?? 0) >= 0.8)) return 'warning'
  return 'success'
}

const BUDGET_TONE = {
  danger: { bar: 'bg-danger', text: 'text-danger', ar: 'تجاوز/انتهى', en: 'Over / spent' },
  warning: { bar: 'bg-warning', text: 'text-warning', ar: 'قربت تخلص', en: 'Running low' },
  success: { bar: 'bg-success', text: 'text-success', ar: 'ضمن الميزانية', en: 'Within budget' },
} as const

function ClientCardView({ c, t, lang, extra }: { c: ClientCard; t: ReturnType<typeof useT>; lang: 'ar' | 'en'; extra?: CardExtra }) {
  const bud = extra?.budget ?? null
  const tone = bud ? BUDGET_TONE[bud.worst] : null
  const portalTo = usePortalPath()
  return (
    /*
     * `min-w-0` down the whole chain, or one long client name widens the page.
     *
     * A grid item's `min-width` is `auto`, so it refuses to shrink below its content — and a company
     * name with no spaces in it («Conversion Co firefox-1785679135282») has no place to wrap. The name is
     * `truncate`d now (the whole of it stays in `title`), which is the fixed-height card's answer to the same
     * problem: it can neither grow the card nor the page.
     */
    <Link
      to={portalTo(`/clients/${c.id}`)}
      data-testid="client-card"
      style={{ minHeight: CARD_HEIGHT }}
      className="flex min-w-0 flex-col justify-between gap-1.5 rounded-xl border border-border bg-surface px-3 py-2.5 hover:border-brand-400"
    >
      <div className="flex items-center justify-between gap-2">
        <div className="flex min-w-0 items-center gap-2">
          {extra ? <ClientAvatar name={c.name} logo={extra.logo} size={32} /> : <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand-primary-soft text-brand-600"><Building2 size={16} aria-hidden /></span>}
          <div className="min-w-0">
            <div title={c.name} className="truncate font-bold text-text-primary">{c.name}</div>
            {c.industry && <div className="truncate text-xs text-text-muted">{labelOf(INDUSTRY_LABELS, c.industry, lang)}</div>}
          </div>
        </div>
        <div className="flex shrink-0 items-center gap-1.5">
          {c.alerts > 0 && <span title={t('cc_col_alerts')} className="inline-flex items-center gap-0.5 text-xs font-semibold text-warning"><AlertTriangle size={13} aria-hidden /> {c.alerts}</span>}
          <StatusChip c={c} lang={lang} />
        </div>
      </div>
      <div className="flex items-center justify-between gap-2">
        <div className="flex items-center gap-3">
          <Stat icon={FolderKanban} value={c.projects} label={t('col_projects')} />
          <Stat icon={Megaphone} value={c.active_campaigns} label={t('col_active_campaigns')} />
          <Stat icon={Inbox} value={c.open_requests} label={t('col_open_requests')} />
        </div>
        <span title={t('cc_col_spend')} className="tnum truncate text-sm font-semibold text-text-secondary" dir="ltr">
          <SpendValue c={c} t={t} />
        </span>
      </div>
      {bud && tone && (
        <div data-testid="client-card-budget" className="flex items-center gap-2 text-xs">
          <span className="min-w-0 flex-1">
            <span className="block h-1 overflow-hidden rounded-full bg-surface-secondary" aria-hidden>
              <span className={`block h-full rounded-full ${tone.bar}`} style={{ width: `${Math.min(100, Math.round(bud.ratio * 100))}%` }} />
            </span>
          </span>
          <span className="shrink-0 text-text-secondary"><Num>{money(bud.spent, bud.currency)}</Num> / <Num>{money(bud.approved, bud.currency)}</Num></span>
          <span className={`shrink-0 font-bold ${tone.text}`} title={lang === 'ar' ? tone.ar : tone.en}><Num>{`${Math.round(bud.ratio * 100)}%`}</Num></span>
        </div>
      )}
      <div className="flex items-center justify-between gap-2 text-xs text-text-muted">
        <span className="flex min-w-0 items-center gap-1">
          {c.data_sources.slice(0, 5).map((s) => <span key={s} title={s}><ProviderMark provider={s} size={18} /></span>)}
        </span>
        <span title={t('cc_col_last_sync')} className="tnum inline-flex shrink-0 items-center gap-1" dir="ltr"><RefreshCw size={12} aria-hidden />{fmtDate(c.last_sync_at)}</span>
      </div>
    </Link>
  )
}

import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { AlertTriangle, Building2, LayoutGrid, Search, Table2, Users } from 'lucide-react'
import { getTaxonomy, listClients, type ClientCard, type ClientFilters } from './api'
import { CLIENT_STATUS_LABELS, INDUSTRY_LABELS, labelOf } from './labels'
import { SearchableSelect } from '@/components/forms'
import { useTaxonomyOptions } from '@/features/taxonomy/taxonomyApi'
import { useT } from '@/lib/i18n'
import { useUi } from '@/stores/ui'

function statusTone(s: string | null): string {
  switch (s) {
    case 'active': return 'bg-success/15 text-success'
    case 'needs_attention': return 'bg-warning/15 text-warning'
    case 'paused': case 'archived': case 'completed': return 'bg-surface-secondary text-text-muted'
    case 'prospect': return 'bg-info/15 text-info'
    default: return 'bg-brand-primary-soft text-brand-700'
  }
}

function fmtDate(iso: string | null): string {
  if (!iso) return '—'
  return new Date(iso).toLocaleDateString('en-CA') // Latin digits, YYYY-MM-DD
}

function spendCell(c: ClientCard, mixedLabel: string): string {
  if (c.spend_currency_mode === 'mixed') return mixedLabel
  if (c.spend === null) return '—'
  return `${c.spend.toLocaleString('en-US')}${c.currency ? ` ${c.currency}` : ''}`
}

export function ClientsPortfolioPage() {
  const t = useT()
  const lang = useUi((s) => s.locale)
  const [view, setView] = useState<'cards' | 'table'>(() => (localStorage.getItem('clients_view') as 'cards' | 'table') || 'cards')
  const [filters, setFilters] = useState<ClientFilters>({ page: 1 })
  const [search, setSearch] = useState('')

  useEffect(() => { localStorage.setItem('clients_view', view) }, [view])

  const taxonomy = useQuery({ queryKey: ['app', 'clients', 'taxonomy'], queryFn: getTaxonomy, staleTime: 300_000 })
  const statusTax = useTaxonomyOptions('client.status')
  const serviceLevelTax = useTaxonomyOptions('client.service_level')
  const industryTax = useTaxonomyOptions('client.industry')
  const query = useQuery({ queryKey: ['app', 'clients', filters], queryFn: () => listClients(filters) })
  const rows = query.data?.data ?? []
  const ownerOptions = (taxonomy.data?.assignable_users ?? []).map((u) => ({ value: String(u.id), label: u.name }))

  const patch = (p: Partial<ClientFilters>) => setFilters((f) => ({ ...f, ...p, page: 1 }))

  const summary = {
    total: query.data?.meta.total ?? rows.length,
    active: rows.filter((c) => c.client_status === 'active').length,
    attention: rows.filter((c) => c.client_status === 'needs_attention' || c.alerts > 0).length,
    openRequests: rows.reduce((s, c) => s + c.open_requests, 0),
  }
  const ar = lang === 'ar'

  return (
    <div className="w-full">
      <header className="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="font-heading text-3xl font-extrabold tracking-tight text-text-primary">{t('clients_portfolio')}</h1>
          <p className="mt-1 text-sm text-text-secondary">{t('clients_subtitle')}</p>
        </div>
        <div className="flex items-center gap-1 rounded-lg border border-border bg-surface p-1">
          <button onClick={() => setView('cards')} aria-label={t('cc_view_cards')} aria-pressed={view === 'cards'}
            className={`flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-xs font-semibold ${view === 'cards' ? 'bg-brand-primary-soft text-brand-700' : 'text-text-secondary'}`}><LayoutGrid size={14} /> {t('cc_view_cards')}</button>
          <button onClick={() => setView('table')} aria-label={t('cc_view_table')} aria-pressed={view === 'table'}
            className={`flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-xs font-semibold ${view === 'table' ? 'bg-brand-primary-soft text-brand-700' : 'text-text-secondary'}`}><Table2 size={14} /> {t('cc_view_table')}</button>
        </div>
      </header>

      {/* Summary — the portfolio at a glance (total is the server count; the rest reflect the loaded set). */}
      <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <ClientSummaryCard label={ar ? 'إجمالي العملاء' : 'Total clients'} value={summary.total} tone="brand" />
        <ClientSummaryCard label={ar ? 'نشطون' : 'Active'} value={summary.active} tone="success" />
        <ClientSummaryCard label={ar ? 'يحتاجون متابعة' : 'Needs attention'} value={summary.attention} tone="warning" />
        <ClientSummaryCard label={ar ? 'طلبات مفتوحة' : 'Open requests'} value={summary.openRequests} tone="info" />
      </div>

      <div className="mb-4 flex flex-wrap items-center gap-2.5">
        <form className="relative" onSubmit={(e) => { e.preventDefault(); patch({ q: search || undefined }) }}>
          <Search size={16} className="pointer-events-none absolute inset-y-0 my-auto ms-3 text-text-muted" />
          <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder={t('search')} className="h-10 w-56 rounded-lg border border-border bg-surface ps-9 pe-3 text-sm outline-none focus:border-brand-500" />
        </form>
        <div className="w-52">
          <SearchableSelect
            value={filters.status ?? null}
            onChange={(v) => patch({ status: v ?? undefined })}
            options={statusTax.options}
            loading={statusTax.isPending}
            optionsError={statusTax.isError ? t('error_generic') : null}
            onRetry={() => statusTax.refetch()}
            placeholder={`${t('cc_filter_status')}: ${t('cc_filter_all')}`}
          />
        </div>
        <div className="w-52">
          <SearchableSelect
            value={filters.service_level ?? null}
            onChange={(v) => patch({ service_level: v ?? undefined })}
            options={serviceLevelTax.options}
            loading={serviceLevelTax.isPending}
            optionsError={serviceLevelTax.isError ? t('error_generic') : null}
            onRetry={() => serviceLevelTax.refetch()}
            placeholder={`${t('cc_filter_service')}: ${t('cc_filter_all')}`}
          />
        </div>
        <div className="w-52">
          <SearchableSelect
            value={filters.industry ?? null}
            onChange={(v) => patch({ industry: v ?? undefined })}
            options={industryTax.options}
            loading={industryTax.isPending}
            optionsError={industryTax.isError ? t('error_generic') : null}
            onRetry={() => industryTax.refetch()}
            placeholder={`${t('cc_filter_industry')}: ${t('cc_filter_all')}`}
          />
        </div>
        <div className="w-52">
          <SearchableSelect
            value={filters.owner_id != null ? String(filters.owner_id) : null}
            onChange={(v) => patch({ owner_id: v ? Number(v) : undefined })}
            options={ownerOptions}
            loading={taxonomy.isPending}
            placeholder={`${t('cc_filter_owner')}: ${t('cc_filter_all')}`}
          />
        </div>
        <label className="flex items-center gap-1.5 text-xs text-text-secondary"><input type="checkbox" onChange={(e) => patch({ needs_attention: e.target.checked || undefined })} /> {t('cc_needs_attention')}</label>
        <label className="flex items-center gap-1.5 text-xs text-text-secondary"><input type="checkbox" onChange={(e) => patch({ has_open_requests: e.target.checked || undefined })} /> {t('cc_has_open_requests')}</label>
        <label className="flex items-center gap-1.5 text-xs text-text-secondary"><input type="checkbox" onChange={(e) => patch({ has_active_campaigns: e.target.checked || undefined })} /> {t('cc_has_active_campaigns')}</label>
        <label className="flex items-center gap-1.5 text-xs text-text-secondary"><input type="checkbox" onChange={(e) => patch({ include_archived: e.target.checked || undefined })} /> {t('cc_include_archived')}</label>
      </div>

      {query.isLoading ? (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">{[0, 1, 2].map((i) => <div key={i} className="h-36 animate-pulse rounded-2xl bg-surface-secondary" />)}</div>
      ) : rows.length === 0 ? (
        <div className="flex flex-col items-center gap-2 rounded-2xl border border-border bg-surface p-12 text-center text-text-muted"><Users size={26} /> <span className="text-sm">{t('clients_empty')}</span></div>
      ) : view === 'cards' ? (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {rows.map((c) => <ClientCardView key={c.id} c={c} t={t} lang={lang} />)}
        </div>
      ) : (
        <ClientTableView rows={rows} t={t} lang={lang} />
      )}
    </div>
  )
}

function ClientSummaryCard({ label, value, tone }: { label: string; value: number; tone: 'brand' | 'success' | 'warning' | 'info' }) {
  const dot: Record<typeof tone, string> = { brand: 'bg-brand-500', success: 'bg-success', warning: 'bg-warning', info: 'bg-info' }
  return (
    <div className="flex flex-col gap-1 rounded-2xl border border-border bg-surface p-4">
      <div className="flex items-center gap-1.5">
        <span className={`h-2 w-2 rounded-full ${dot[tone]}`} aria-hidden />
        <span className="text-xs font-semibold text-text-secondary">{label}</span>
      </div>
      <span className="tnum text-2xl font-extrabold text-text-primary" dir="ltr">{value}</span>
    </div>
  )
}

function ClientCardView({ c, t, lang }: { c: ClientCard; t: ReturnType<typeof useT>; lang: 'ar' | 'en' }) {
  return (
    <Link to={`/app/clients/${c.id}`} className="flex flex-col gap-3 rounded-2xl border border-border bg-surface p-5 hover:border-brand-400">
      <div className="flex items-start justify-between">
        <div className="flex items-center gap-2.5">
          <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-brand-primary-soft text-brand-600"><Building2 size={18} /></span>
          <div>
            <div className="font-bold text-text-primary">{c.name}</div>
            {c.industry && <div className="text-[11px] text-text-muted">{labelOf(INDUSTRY_LABELS, c.industry, lang)}</div>}
          </div>
        </div>
        <div className="flex flex-col items-end gap-1">
          <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${statusTone(c.client_status)}`}>{labelOf(CLIENT_STATUS_LABELS, c.client_status, lang)}</span>
          {c.alerts > 0 && <span className="flex items-center gap-1 text-[11px] font-semibold text-warning"><AlertTriangle size={12} /> {c.alerts}</span>}
        </div>
      </div>
      <div className="grid grid-cols-3 gap-2 text-center text-sm">
        <div><div className="tnum text-lg font-bold text-text-primary">{c.projects}</div><div className="text-[11px] text-text-muted">{t('col_projects')}</div></div>
        <div><div className="tnum text-lg font-bold text-text-primary">{c.active_campaigns}</div><div className="text-[11px] text-text-muted">{t('col_active_campaigns')}</div></div>
        <div><div className="tnum text-lg font-bold text-text-primary">{c.open_requests}</div><div className="text-[11px] text-text-muted">{t('col_open_requests')}</div></div>
      </div>
      <div className="flex flex-wrap items-center justify-between gap-2 border-t border-border pt-2 text-[11px] text-text-muted">
        <span>{t('cc_col_spend')}: <span className="tnum font-semibold text-text-secondary">{spendCell(c, t('cc_currency_mixed'))}</span></span>
        <span>{t('cc_col_last_sync')}: {fmtDate(c.last_sync_at)}</span>
      </div>
      {c.data_sources.length > 0 && (
        <div className="flex flex-wrap gap-1">
          {c.data_sources.map((s) => <span key={s} className="rounded bg-surface-secondary px-1.5 py-0.5 text-[10px] text-text-secondary">{s}</span>)}
        </div>
      )}
    </Link>
  )
}

function ClientTableView({ rows, t, lang }: { rows: ClientCard[]; t: ReturnType<typeof useT>; lang: 'ar' | 'en' }) {
  return (
    <div className="overflow-x-auto rounded-2xl border border-border bg-surface">
      <table className="w-full min-w-[720px] text-start text-sm">
        <thead className="border-b border-border text-[11px] uppercase tracking-wide text-text-muted">
          <tr>
            <th className="p-3 text-start font-semibold">{t('cc_col_client')}</th>
            <th className="p-3 text-start font-semibold">{t('cc_filter_status')}</th>
            <th className="p-3 text-center font-semibold">{t('col_projects')}</th>
            <th className="p-3 text-center font-semibold">{t('col_active_campaigns')}</th>
            <th className="p-3 text-center font-semibold">{t('col_open_requests')}</th>
            <th className="p-3 text-center font-semibold">{t('cc_col_alerts')}</th>
            <th className="p-3 text-end font-semibold">{t('cc_col_spend')}</th>
            <th className="p-3 text-start font-semibold">{t('cc_col_last_sync')}</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((c) => (
            <tr key={c.id} className="border-b border-border/60 last:border-0 hover:bg-surface-secondary/50">
              <td className="p-3"><Link to={`/app/clients/${c.id}`} className="font-semibold text-brand-600 hover:underline">{c.name}</Link>
                {c.industry && <div className="text-[11px] text-text-muted">{labelOf(INDUSTRY_LABELS, c.industry, lang)}</div>}</td>
              <td className="p-3"><span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${statusTone(c.client_status)}`}>{labelOf(CLIENT_STATUS_LABELS, c.client_status, lang)}</span></td>
              <td className="p-3 text-center tnum">{c.projects}</td>
              <td className="p-3 text-center tnum">{c.active_campaigns}</td>
              <td className="p-3 text-center tnum">{c.open_requests}</td>
              <td className="p-3 text-center tnum">{c.alerts > 0 ? <span className="font-semibold text-warning">{c.alerts}</span> : '—'}</td>
              <td className="p-3 text-end tnum">{spendCell(c, t('cc_currency_mixed'))}</td>
              <td className="p-3">{fmtDate(c.last_sync_at)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

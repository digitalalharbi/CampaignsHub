import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Building2, Search, Users } from 'lucide-react'
import { listClients } from './api'
import { useT } from '@/lib/i18n'

const CLIENT_STATUSES = ['', 'prospect', 'onboarding', 'active', 'needs_attention', 'paused', 'completed', 'archived']

function statusTone(s: string | null): string {
  switch (s) {
    case 'active': return 'bg-success/15 text-success'
    case 'needs_attention': return 'bg-warning/15 text-warning'
    case 'paused': case 'archived': return 'bg-surface-secondary text-text-muted'
    case 'prospect': return 'bg-info/15 text-info'
    default: return 'bg-brand-primary-soft text-brand-700'
  }
}

export function ClientsPortfolioPage() {
  const t = useT()
  const [filters, setFilters] = useState<{ status?: string; q?: string; page?: number }>({ page: 1 })
  const [search, setSearch] = useState('')
  const query = useQuery({ queryKey: ['app', 'clients', filters], queryFn: () => listClients(filters) })
  const rows = query.data?.data ?? []

  return (
    <div className="mx-auto w-full max-w-6xl">
      <header className="mb-6">
        <h1 className="font-heading text-2xl font-extrabold text-text-primary">{t('clients_portfolio')}</h1>
        <p className="mt-1 text-sm text-text-secondary">{t('clients_subtitle')}</p>
      </header>

      <div className="mb-4 flex flex-wrap items-center gap-2.5">
        <form className="relative" onSubmit={(e) => { e.preventDefault(); setFilters((f) => ({ ...f, q: search || undefined, page: 1 })) }}>
          <Search size={16} className="pointer-events-none absolute inset-y-0 my-auto ms-3 text-text-muted" />
          <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder={t('search')} className="h-10 w-56 rounded-lg border border-border bg-surface ps-9 pe-3 text-sm outline-none focus:border-brand-500" />
        </form>
        <select onChange={(e) => setFilters((f) => ({ ...f, status: e.target.value || undefined, page: 1 }))} className="h-10 rounded-lg border border-border bg-surface px-3 text-sm">
          {CLIENT_STATUSES.map((s) => <option key={s} value={s}>{s || t('all_statuses')}</option>)}
        </select>
      </div>

      {query.isLoading ? (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">{[0, 1, 2].map((i) => <div key={i} className="h-32 animate-pulse rounded-2xl bg-surface-secondary" />)}</div>
      ) : rows.length === 0 ? (
        <div className="flex flex-col items-center gap-2 rounded-2xl border border-border bg-surface p-12 text-center text-text-muted"><Users size={26} /> <span className="text-sm">{t('clients_empty')}</span></div>
      ) : (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {rows.map((c) => (
            <Link key={c.id} to={`/app/clients/${c.id}`} className="flex flex-col gap-3 rounded-2xl border border-border bg-surface p-5 hover:border-brand-400">
              <div className="flex items-center justify-between">
                <div className="flex items-center gap-2.5">
                  <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-brand-primary-soft text-brand-600"><Building2 size={18} /></span>
                  <span className="font-bold text-text-primary">{c.name}</span>
                </div>
                <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${statusTone(c.client_status)}`}>{c.client_status ?? '—'}</span>
              </div>
              <div className="grid grid-cols-3 gap-2 text-center text-sm">
                <div><div className="tnum text-lg font-bold text-text-primary">{c.projects}</div><div className="text-[11px] text-text-muted">{t('col_projects')}</div></div>
                <div><div className="tnum text-lg font-bold text-text-primary">{c.active_campaigns}</div><div className="text-[11px] text-text-muted">{t('col_active_campaigns')}</div></div>
                <div><div className="tnum text-lg font-bold text-text-primary">{c.open_requests}</div><div className="text-[11px] text-text-muted">{t('col_open_requests')}</div></div>
              </div>
              {c.service_level && <div className="text-xs text-text-secondary">{t('service_level')}: {c.service_level}</div>}
            </Link>
          ))}
        </div>
      )}
    </div>
  )
}

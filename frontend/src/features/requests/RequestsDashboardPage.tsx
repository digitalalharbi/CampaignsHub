import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { AlertTriangle, Inbox, Search } from 'lucide-react'
import { listRequests, type RequestFilters } from './internalApi'
import { STATUS_LABELS, priorityTone, statusTone } from './labels'
import { useT } from '@/lib/i18n'

const STATUSES = ['', 'new', 'under_review', 'waiting_client', 'qualified', 'approved', 'in_progress', 'completed', 'archived']
const PRIORITIES = ['', 'critical', 'high', 'medium', 'low']

export function RequestsDashboardPage() {
  const t = useT()
  const [filters, setFilters] = useState<RequestFilters>({ page: 1 })
  const [search, setSearch] = useState('')
  const query = useQuery({ queryKey: ['app', 'requests', filters], queryFn: () => listRequests(filters) })

  const set = (patch: Partial<RequestFilters>) => setFilters((f) => ({ ...f, ...patch, page: 1 }))

  return (
    <div className="mx-auto w-full max-w-6xl">
      <header className="mb-6">
        <h1 className="font-heading text-2xl font-extrabold text-text-primary">{t('requests_inbox')}</h1>
        <p className="mt-1 text-sm text-text-secondary">{t('requests_inbox_subtitle')}</p>
      </header>

      {/* Filters */}
      <div className="mb-4 flex flex-wrap items-center gap-2.5">
        <form className="relative" onSubmit={(e) => { e.preventDefault(); set({ q: search || undefined }) }}>
          <Search size={16} className="pointer-events-none absolute inset-y-0 my-auto ms-3 text-text-muted" />
          <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder={t('search')} className="h-10 w-56 rounded-lg border border-border bg-surface ps-9 pe-3 text-sm outline-none focus:border-brand-500" />
        </form>
        <select value={filters.status ?? ''} onChange={(e) => set({ status: e.target.value || undefined })} className="h-10 rounded-lg border border-border bg-surface px-3 text-sm">
          {STATUSES.map((s) => <option key={s} value={s}>{s ? STATUS_LABELS[s] ?? s : t('all_statuses')}</option>)}
        </select>
        <select value={filters.priority ?? ''} onChange={(e) => set({ priority: e.target.value || undefined })} className="h-10 rounded-lg border border-border bg-surface px-3 text-sm">
          {PRIORITIES.map((p) => <option key={p} value={p}>{p || t('all_priorities')}</option>)}
        </select>
      </div>

      <div className="overflow-hidden rounded-2xl border border-border bg-surface">
        {query.isLoading ? (
          <div className="space-y-2 p-4">{[0, 1, 2, 3, 4].map((i) => <div key={i} className="h-11 animate-pulse rounded-lg bg-surface-secondary" />)}</div>
        ) : query.isError ? (
          <div className="flex flex-col items-center gap-2 p-12 text-center text-sm text-danger"><AlertTriangle size={22} /> {t('error_generic')}</div>
        ) : query.data && query.data.data.length === 0 ? (
          <div className="flex flex-col items-center gap-2 p-12 text-center text-text-muted"><Inbox size={26} /> <span className="text-sm">{t('requests_empty')}</span></div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-border text-start text-xs font-semibold text-text-muted">
                  <th className="px-4 py-3 text-start">{t('col_reference')}</th>
                  <th className="px-4 py-3 text-start">{t('col_service')}</th>
                  <th className="px-4 py-3 text-start">{t('col_contact')}</th>
                  <th className="px-4 py-3 text-start">{t('col_status')}</th>
                  <th className="px-4 py-3 text-start">{t('col_priority')}</th>
                  <th className="px-4 py-3 text-start">{t('col_assignee')}</th>
                  <th className="px-4 py-3 text-start">{t('col_sla')}</th>
                </tr>
              </thead>
              <tbody>
                {query.data?.data.map((r) => (
                  <tr key={r.id} className="border-b border-border last:border-0 hover:bg-surface-hover">
                    <td className="px-4 py-3"><Link to={`/app/requests/${r.id}`} className="font-mono font-semibold text-brand-600 hover:underline" dir="ltr">{r.reference}</Link></td>
                    <td className="px-4 py-3 text-text-secondary">{r.service_ar}</td>
                    <td className="px-4 py-3 text-text-primary">{r.contact}</td>
                    <td className="px-4 py-3"><span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${statusTone(r.status)}`}>{r.status_label}</span></td>
                    <td className="px-4 py-3"><span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${priorityTone(r.priority)}`}>{r.priority}</span></td>
                    <td className="px-4 py-3 text-text-secondary">{r.assignee ?? '—'}</td>
                    <td className="px-4 py-3">{r.sla_breached ? <span className="text-xs font-semibold text-danger">{t('sla_overdue')}</span> : <span className="tnum text-xs text-text-muted" dir="ltr">{r.sla_due_at?.slice(0, 10) ?? '—'}</span>}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* Pagination */}
      {query.data && query.data.meta.last_page > 1 && (
        <div className="mt-4 flex items-center justify-between text-sm text-text-secondary">
          <span>{t('page')} {query.data.meta.current_page} / {query.data.meta.last_page}</span>
          <div className="flex gap-2">
            <button disabled={query.data.meta.current_page <= 1} onClick={() => setFilters((f) => ({ ...f, page: (f.page ?? 1) - 1 }))} className="rounded-lg border border-border px-3 py-1.5 disabled:opacity-50">{t('previous')}</button>
            <button disabled={query.data.meta.current_page >= query.data.meta.last_page} onClick={() => setFilters((f) => ({ ...f, page: (f.page ?? 1) + 1 }))} className="rounded-lg border border-border px-3 py-1.5 disabled:opacity-50">{t('next')}</button>
          </div>
        </div>
      )}
    </div>
  )
}

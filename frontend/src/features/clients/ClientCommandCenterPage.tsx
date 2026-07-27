import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { ArrowLeft } from 'lucide-react'
import { getClient } from './api'
import { useT } from '@/lib/i18n'

type Tab = 'overview' | 'projects' | 'campaigns' | 'requests'

export function ClientCommandCenterPage() {
  const t = useT()
  const { clientId = '' } = useParams()
  const [tab, setTab] = useState<Tab>('overview')
  const query = useQuery({ queryKey: ['app', 'client', clientId], queryFn: () => getClient(clientId) })

  if (query.isLoading) return <div className="mx-auto max-w-5xl"><div className="h-64 animate-pulse rounded-2xl bg-surface-secondary" /></div>
  if (query.isError) return <div className="mx-auto max-w-5xl rounded-2xl border border-danger/30 bg-[var(--negative-background)] p-6 text-center text-sm text-danger">{t('error_generic')}</div>
  const d = query.data!

  const TABS: { key: Tab; label: string }[] = [
    { key: 'overview', label: t('tab_overview') }, { key: 'projects', label: t('tab_projects') },
    { key: 'campaigns', label: t('tab_campaigns') }, { key: 'requests', label: t('tab_requests') },
  ]

  return (
    <div className="mx-auto w-full max-w-5xl">
      <Link to="/app/clients" className="mb-4 inline-flex items-center gap-1.5 text-sm font-semibold text-text-secondary hover:text-text-primary"><ArrowLeft size={15} className="rtl:rotate-180" /> {t('clients_portfolio')}</Link>

      <div className="rounded-2xl border border-border bg-surface p-5">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <h1 className="font-heading text-2xl font-extrabold text-text-primary">{d.name}</h1>
          <div className="flex items-center gap-2 text-xs">
            <span className="rounded-full bg-brand-primary-soft px-2.5 py-1 font-semibold text-brand-700">{d.client_status ?? '—'}</span>
            {d.service_level && <span className="rounded-full bg-surface-secondary px-2.5 py-1 font-semibold text-text-secondary">{d.service_level}</span>}
          </div>
        </div>
        <div className="mt-4 flex gap-1 border-b border-border">
          {TABS.map((tb) => (
            <button key={tb.key} onClick={() => setTab(tb.key)} className={`-mb-px border-b-2 px-3 py-2 text-sm font-semibold ${tab === tb.key ? 'border-brand-500 text-brand-700' : 'border-transparent text-text-secondary hover:text-text-primary'}`}>{tb.label}</button>
          ))}
        </div>

        <div className="mt-5">
          {tab === 'overview' && (
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
              {[[t('col_projects'), d.overview.projects], [t('col_active_campaigns'), d.overview.active_campaigns], ['Draft', d.overview.draft_campaigns], [t('col_open_requests'), d.overview.open_requests]].map(([label, val]) => (
                <div key={String(label)} className="rounded-xl border border-border bg-surface-secondary p-4 text-center">
                  <div className="tnum text-2xl font-extrabold text-text-primary">{val as number}</div>
                  <div className="mt-1 text-xs text-text-muted">{label as string}</div>
                </div>
              ))}
            </div>
          )}

          {tab === 'projects' && (
            <ul className="space-y-2">
              {d.projects.length === 0 && <li className="text-sm text-text-muted">—</li>}
              {d.projects.map((p) => (
                <li key={p.id} className="flex items-center justify-between rounded-lg border border-border px-4 py-3 text-sm">
                  <span className="font-medium text-text-primary">{p.name}</span>
                  <span className="rounded-full bg-surface-secondary px-2 py-0.5 text-xs text-text-secondary">{p.status}</span>
                </li>
              ))}
            </ul>
          )}

          {tab === 'campaigns' && (
            <ul className="space-y-2">
              {d.campaigns.length === 0 && <li className="text-sm text-text-muted">—</li>}
              {d.campaigns.map((c) => (
                <li key={c.id} className="flex items-center justify-between rounded-lg border border-border px-4 py-3 text-sm">
                  <Link to={`/campaigns/${c.project_id}/${c.id}`} className="font-medium text-brand-600 hover:underline">{c.name}</Link>
                  <span className="flex items-center gap-2">
                    <span className="text-xs text-text-secondary">{c.objective}</span>
                    <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${c.status === 'draft' ? 'bg-surface-secondary text-text-muted' : 'bg-success/15 text-success'}`}>{c.status}</span>
                  </span>
                </li>
              ))}
            </ul>
          )}

          {tab === 'requests' && (
            <ul className="space-y-2">
              {d.requests.length === 0 && <li className="text-sm text-text-muted">—</li>}
              {d.requests.map((r) => (
                <li key={r.id} className="flex items-center justify-between rounded-lg border border-border px-4 py-3 text-sm">
                  <Link to={`/app/requests/${r.id}`} className="font-mono font-semibold text-brand-600 hover:underline" dir="ltr">{r.reference}</Link>
                  <span className="flex items-center gap-2 text-xs text-text-secondary">{r.service}<span className="rounded-full bg-surface-secondary px-2 py-0.5">{r.status}</span></span>
                </li>
              ))}
            </ul>
          )}
        </div>
      </div>
    </div>
  )
}

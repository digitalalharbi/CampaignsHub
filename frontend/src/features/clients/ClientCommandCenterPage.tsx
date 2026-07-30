import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { ArrowLeft, Pencil } from 'lucide-react'
import { getClient } from './api'
import { CLIENT_STATUS_LABELS, INDUSTRY_LABELS, labelOf, PRIORITY_LABELS, SERVICE_LEVEL_LABELS } from './labels'
import { ClassificationEditor } from './ClassificationEditor'
import { TabBilling } from './TabBilling'
import { TabMessages } from './TabMessages'
import { TabActivity } from './TabActivity'
import { TabAnalytics } from './TabAnalytics'
import { TabFiles } from './TabFiles'
import { TabReports } from './TabReports'
import { TabSettings } from './TabSettings'
import { TabTeam } from './TabTeam'
import { useT } from '@/lib/i18n'
import { useUi } from '@/stores/ui'
import { usePortalPath } from '@/app/portalPath'
import { toApiError } from '@/lib/api/client'

type Tab = 'overview' | 'projects' | 'campaigns' | 'analytics' | 'reports' | 'requests' | 'billing' | 'messages' | 'team' | 'files' | 'activity' | 'settings'

export function ClientCommandCenterPage() {
  const portalTo = usePortalPath()
  const t = useT()
  const lang = useUi((s) => s.locale)
  const { clientId = '' } = useParams()
  const [tab, setTab] = useState<Tab>('overview')
  const [editing, setEditing] = useState(false)
  const query = useQuery({ queryKey: ['app', 'client', clientId], queryFn: () => getClient(clientId) })

  if (query.isPending) return <div className="mx-auto max-w-5xl"><div className="h-64 animate-pulse rounded-2xl bg-surface-secondary" /></div>

  // A client outside this membership's scope answers 403, and that is the common case now that the
  // same page serves the agency portal. It must READ as a boundary, not as a broken page — and the
  // `!query.data` arm matters as much as `isError`: between retries neither pending nor error is
  // true, and dereferencing the missing payload took the whole route down.
  if (query.isError || !query.data) {
    const status = toApiError(query.error).status
    const denied = status === 403 || status === 404

    return (
      <div className="mx-auto max-w-5xl">
        <Link to={portalTo('/clients')} className="mb-4 inline-flex items-center gap-1.5 text-sm font-semibold text-text-secondary hover:text-text-primary">
          <ArrowLeft size={15} className="rtl:rotate-180" /> {t('clients_portfolio')}
        </Link>
        {denied ? (
          <div data-testid="client-out-of-scope" className="rounded-2xl border border-border bg-surface p-8 text-center">
            <p className="font-heading text-lg font-bold text-text-primary">
              {lang === 'ar' ? 'هذا العميل خارج نطاقك' : 'This client is outside your access'}
            </p>
            <p className="mt-1.5 text-sm text-text-secondary">
              {lang === 'ar'
                ? 'عضويتك تشمل عملاء بعينهم. اطلب الوصول ممن يملك صلاحية أوسع.'
                : 'Your membership covers specific clients. Ask someone with wider access to grant it.'}
            </p>
          </div>
        ) : (
          <div className="rounded-2xl border border-danger/30 bg-[var(--negative-background)] p-6 text-center text-sm text-danger">
            {t('error_generic')}
          </div>
        )}
      </div>
    )
  }

  const d = query.data

  // Only tabs whose backend is live AND the user is permitted to see are shown — never a placeholder tab.
  const TABS: { key: Tab; label: string }[] = [
    { key: 'overview', label: t('tab_overview') }, { key: 'projects', label: t('tab_projects') },
    { key: 'campaigns', label: t('tab_campaigns') },
    ...(d.can.view_analytics ? [{ key: 'analytics' as Tab, label: t('tab_analytics') }] : []),
    ...(d.can.view_reports ? [{ key: 'reports' as Tab, label: t('tab_reports') }] : []),
    { key: 'requests', label: t('tab_requests') },
    { key: 'billing' as Tab, label: lang === 'ar' ? 'الاشتراكات' : 'Billing' },
    { key: 'messages' as Tab, label: lang === 'ar' ? 'المحادثات' : 'Conversations' },
    ...(d.can.manage_team ? [{ key: 'team' as Tab, label: t('tab_team') }] : []),
    ...(d.can.manage_files ? [{ key: 'files' as Tab, label: t('tab_files') }] : []),
    { key: 'activity', label: t('tab_activity') },
    { key: 'settings', label: t('tab_settings') },
  ]

  return (
    <div className="mx-auto w-full max-w-5xl">
      <Link to={portalTo('/clients')} className="mb-4 inline-flex items-center gap-1.5 text-sm font-semibold text-text-secondary hover:text-text-primary"><ArrowLeft size={15} className="rtl:rotate-180" /> {t('clients_portfolio')}</Link>

      {d.is_archived && (
        <div className="mb-3 rounded-xl border border-warning/40 bg-warning/10 px-4 py-2.5 text-sm text-warning">{t('cc_archived_banner')}</div>
      )}

      <div className="rounded-2xl border border-border bg-surface p-5">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h1 className="font-heading text-2xl font-extrabold text-text-primary">{d.name}</h1>
            <div className="mt-2 flex flex-wrap items-center gap-2 text-xs">
              <span className="rounded-full bg-brand-primary-soft px-2.5 py-1 font-semibold text-brand-700">{labelOf(CLIENT_STATUS_LABELS, d.classification.client_status, lang)}</span>
              {d.classification.service_level && <span className="rounded-full bg-surface-secondary px-2.5 py-1 font-semibold text-text-secondary">{labelOf(SERVICE_LEVEL_LABELS, d.classification.service_level, lang)}</span>}
              {d.classification.industry && <span className="rounded-full bg-surface-secondary px-2.5 py-1 font-semibold text-text-secondary">{labelOf(INDUSTRY_LABELS, d.classification.industry, lang)}</span>}
              {d.classification.priority && d.classification.priority !== 'normal' && <span className="rounded-full bg-warning/15 px-2.5 py-1 font-semibold text-warning">{t('cc_priority')}: {labelOf(PRIORITY_LABELS, d.classification.priority, lang)}</span>}
              {d.classification.owner_name && <span className="rounded-full bg-surface-secondary px-2.5 py-1 font-semibold text-text-secondary">{t('cc_owner')}: {d.classification.owner_name}</span>}
            </div>
          </div>
          {d.can.update && (
            <button onClick={() => setEditing((v) => !v)} className="flex items-center gap-1.5 rounded-lg border border-border px-3 py-2 text-sm font-semibold text-text-secondary hover:text-text-primary"><Pencil size={14} /> {t('cc_edit_classification')}</button>
          )}
        </div>

        {editing && d.can.update && <ClassificationEditor clientId={d.id} current={d.classification} onClose={() => setEditing(false)} />}

        <div className="mt-4 flex flex-wrap gap-1 border-b border-border">
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
                  <Link to={portalTo(`/requests/${r.id}`)} className="font-mono font-semibold text-brand-600 hover:underline" dir="ltr">{r.reference}</Link>
                  <span className="flex items-center gap-2 text-xs text-text-secondary">{r.service}<span className="rounded-full bg-surface-secondary px-2 py-0.5">{r.status}</span></span>
                </li>
              ))}
            </ul>
          )}

          {tab === 'analytics' && d.can.view_analytics && <TabAnalytics clientId={d.id} />}

          {tab === 'reports' && d.can.view_reports && <TabReports d={d} />}

          {tab === 'billing' && <TabBilling clientId={d.id} />}
          {tab === 'messages' && <TabMessages clientId={d.id} />}
          {tab === 'team' && d.can.manage_team && <TabTeam d={d} />}

          {tab === 'files' && d.can.manage_files && <TabFiles clientId={d.id} />}

          {tab === 'activity' && <TabActivity clientId={d.id} />}

          {tab === 'settings' && <TabSettings d={d} />}
        </div>
      </div>
    </div>
  )
}

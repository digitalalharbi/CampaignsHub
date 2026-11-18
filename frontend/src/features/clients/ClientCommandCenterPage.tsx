import { useState } from 'react'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import { useMutation, useQuery } from '@tanstack/react-query'
import { ArrowLeft, Pencil, Plus } from 'lucide-react'
import { createProject } from '@/features/projects/api'
import { promptDialog } from '@/stores/dialogs'
import { getClient } from './api'
import { CLIENT_STATUS_LABELS, INDUSTRY_LABELS, labelOf, PRIORITY_LABELS, SERVICE_LEVEL_LABELS } from './labels'
import { ClassificationEditor } from './ClassificationEditor'
/*
 * The canonical labels, not a fourth copy of them.
 *
 * These three tabs printed `p.status`, `c.objective` and `c.status` verbatim, so an Arabic command
 * centre listed «active», «sales», «paused» — the column values. Every one of them already has a
 * written name elsewhere in the product: the portfolio card calls `projectStatusLabel`, and the
 * campaigns feature owns `campaignStatusLabel` and `objectiveLabel`. Importing them is what keeps
 * the two surfaces from drifting into different words for the same state.
 */
import { campaignStatusLabel, objectiveLabel } from '@/features/campaigns/labels'
import { TabBilling } from './TabBilling'
import { ClientBudgetCard } from './ClientBudgetCard'
import { TabDelivery } from './delivery/TabDelivery'
import { TabPortal } from './portal/TabPortal'
import { TabContentApprovals } from './portal/TabContentApprovals'
import { useAuth } from '@/stores/auth'
import { TabMessages } from './TabMessages'
import { TabActivity } from './TabActivity'
import { TabAnalytics } from './TabAnalytics'
import { TabFiles } from './TabFiles'
import { TabReports } from './TabReports'
import { TabSettings } from './TabSettings'
import { TabTeam } from './TabTeam'
import { ClientProjectsTab } from './ClientProjectsTab'
import { useT } from '@/lib/i18n'
import { useUi } from '@/stores/ui'
import { usePortalPath } from '@/app/portalPath'
import { toApiError } from '@/lib/api/client'
import { FitPage } from '@/components/fit/FitPage'
import { KpiMini, KpiStrip } from '@/components/fit/KpiMini'
import { DetailPanel } from '@/components/fit/DetailPanel'
import { Building2, FolderKanban, Inbox, Megaphone } from 'lucide-react'

type Tab = 'overview' | 'projects' | 'campaigns' | 'analytics' | 'reports' | 'requests' | 'billing' | 'delivery' | 'portal' | 'approvals' | 'messages' | 'team' | 'files' | 'activity' | 'settings'

export function ClientCommandCenterPage() {
  const portalTo = usePortalPath()
  const t = useT()
  const lang = useUi((s) => s.locale)
  const ar = lang === 'ar'
  const locale = lang
  const { clientId = '' } = useParams()
  /* DELIVERY-TASK-001 — a weekly-delivery task links straight to this client's delivery tab. Only that tab is
     addressable: the others are a reader's own navigation, not something another page links to. */
  const [params] = useSearchParams()
  const [tab, setTab] = useState<Tab>(params.get('tab') === 'delivery' ? 'delivery' : 'overview')
  const [editing, setEditing] = useState(false)
  // CLIENT-CONTENT-APPROVAL-001 — read before any early return: hooks run in the same order every render.
  const canContent = useAuth((s) => s.hasPermission('content.view'))
  const query = useQuery({ queryKey: ['app', 'client', clientId], queryFn: () => getClient(clientId) })
  /* CLIENT-UNIT-001 — a client's second project is what brings the «المشاريع» switch into view. */
  const addProject = useMutation({ mutationFn: (name: string) => createProject({ client_workspace_id: clientId, name }) })

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
    { key: 'overview', label: t('tab_overview') },
    // CLIENT-UNIT-001 — the project is hidden until the client has a second one; one project IS the client.
    // PROJECT-LIFECYCLE-001 §8 — shown whenever there is more than one project OR an archived one to find.
    ...(d.projects.length > 1 || d.projects.some((p) => p.status === 'archived') ? [{ key: 'projects' as Tab, label: t('tab_projects') }] : []),
    { key: 'campaigns', label: t('tab_campaigns') },
    ...(d.can.view_analytics ? [{ key: 'analytics' as Tab, label: t('tab_analytics') }] : []),
    ...(d.can.view_reports ? [{ key: 'reports' as Tab, label: t('tab_reports') }] : []),
    { key: 'requests', label: t('tab_requests') },
    ...(d.can.view_reports ? [{ key: 'delivery' as Tab, label: lang === 'ar' ? 'الجدولة الدورية' : 'Periodic delivery' }] : []),
    { key: 'billing' as Tab, label: lang === 'ar' ? 'الاشتراكات' : 'Billing' },
    { key: 'messages' as Tab, label: lang === 'ar' ? 'المحادثات' : 'Conversations' },
    ...(canContent ? [{ key: 'approvals' as Tab, label: lang === 'ar' ? 'للاعتماد' : 'Approvals' }] : []),
    ...(d.can.manage_settings || d.can.manage_team ? [{ key: 'portal' as Tab, label: lang === 'ar' ? 'بوابة العميل' : 'Client portal' }] : []),
    ...(d.can.manage_team ? [{ key: 'team' as Tab, label: t('tab_team') }] : []),
    ...(d.can.manage_files ? [{ key: 'files' as Tab, label: t('tab_files') }] : []),
    { key: 'activity', label: t('tab_activity') },
    { key: 'settings', label: t('tab_settings') },
  ]

  /*
   * AGENCY-FIT-001 — the client's main screen is one screen.
   *
   * The name, the status chips and the tab strip are three short rows; the overview is the four counts, the budget and
   * the project actions. «تعديل التصنيف» opens its editor in a layer instead of growing the page, and the back link and
   * the edit button ride in the title row. The other tabs are documents and lists of their own (campaigns, billing,
   * conversations, the files…) and let the page flow, as the team page's access center does.
   */
  const bar = (
    <div className="flex min-w-0 flex-col gap-2">
      {d.is_archived && (
        <div className="rounded-xl border border-warning/40 bg-warning/10 px-4 py-2 text-sm text-warning">{t('cc_archived_banner')}</div>
      )}
      <div className="flex flex-wrap items-center gap-2 text-xs" data-testid="client-chips">
        <span className="rounded-full bg-brand-primary-soft px-2.5 py-1 font-semibold text-brand-700">{labelOf(CLIENT_STATUS_LABELS, d.classification.client_status, lang)}</span>
        {d.classification.service_level && <span className="rounded-full bg-surface-secondary px-2.5 py-1 font-semibold text-text-secondary">{labelOf(SERVICE_LEVEL_LABELS, d.classification.service_level, lang)}</span>}
        {d.classification.industry && <span className="rounded-full bg-surface-secondary px-2.5 py-1 font-semibold text-text-secondary">{labelOf(INDUSTRY_LABELS, d.classification.industry, lang)}</span>}
        {d.classification.priority && d.classification.priority !== 'normal' && <span className="rounded-full bg-warning/15 px-2.5 py-1 font-semibold text-warning">{t('cc_priority')}: {labelOf(PRIORITY_LABELS, d.classification.priority, lang)}</span>}
        {d.classification.owner_name && <span className="rounded-full bg-surface-secondary px-2.5 py-1 font-semibold text-text-secondary">{t('cc_owner')}: {d.classification.owner_name}</span>}
      </div>
      {/* CLIENT-TABS-001 — a steady segmented bar (the levels strip's look): the active tab is a filled pill, nothing slides. */}
      <nav aria-label={ar ? 'أقسام العميل' : 'Client sections'} className="-mx-0.5 flex min-w-0 max-w-full flex-nowrap gap-1 overflow-x-auto rounded-2xl border border-border bg-surface-secondary p-1 [scrollbar-width:none]" data-testid="client-tabs">
      {TABS.map((tb) => (
      <button key={tb.key} type="button" aria-pressed={tab === tb.key} data-testid={`client-tab-${tb.key}`} onClick={() => setTab(tb.key)} className={`inline-flex min-h-9 shrink-0 items-center whitespace-nowrap rounded-xl px-3 text-sm font-bold transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-600 ${tab === tb.key ? 'bg-brand-600 text-white shadow-[var(--shadow-small)]' : 'text-text-secondary hover:bg-surface hover:text-text-primary'}`}>{tb.label}</button>
      ))}
      </nav>
    </div>
  )

  return (
    <FitPage
      testid="client-command-center"
      introTestid="client-intro"
      fit={tab === 'overview'}
      title={d.name}
      actions={
        <>
          <Link to={portalTo('/clients')} className="inline-flex items-center gap-1.5 text-sm font-semibold text-text-secondary hover:text-text-primary"><ArrowLeft size={15} className="rtl:rotate-180" /> {t('clients_portfolio')}</Link>
          {d.can.update && (
            <button onClick={() => setEditing((v) => !v)} className="flex items-center gap-1.5 rounded-lg border border-border px-3 py-1.5 text-sm font-semibold text-text-secondary hover:text-text-primary"><Pencil size={14} /> {t('cc_edit_classification')}</button>
          )}
        </>
      }
      toolbar={bar}
    >
      <DetailPanel open={editing && d.can.update} onClose={() => setEditing(false)} title={t('cc_edit_classification')} closeLabel={ar ? 'إغلاق' : 'Close'} testid="client-classification-panel">
        {d.can.update && <ClassificationEditor clientId={d.id} current={d.classification} onClose={() => setEditing(false)} />}
      </DetailPanel>

      <div className="flex min-h-0 flex-1 flex-col gap-2.5">
          {tab === 'overview' && (
            /*
              UX-KPI-PRESENTATION-001 — the shared card, and the same grid every other row uses; AGENCY-FIT-001 — the compact one.
            */
            <KpiStrip testid="client-counts">
              {[[t('col_projects'), d.overview.projects, FolderKanban, 'brand'], [t('col_active_campaigns'), d.overview.active_campaigns, Megaphone, 'success'], [t('col_draft_campaigns'), d.overview.draft_campaigns, Building2, 'neutral'], [t('col_open_requests'), d.overview.open_requests, Inbox, 'warning']].map(([label, val, icon, tone]) => (
                <KpiMini key={String(label)} label={label as string} value={String(val)} icon={icon as typeof Building2} tone={tone as 'brand'} />
              ))}
            </KpiStrip>
          )}

          {tab === 'overview' && d.budget && <ClientBudgetCard budget={d.budget} ar={ar} clientName={d.name} limit={3} />}

          {tab === 'overview' && (
            <div className="flex flex-wrap items-center gap-2" data-testid="client-project-actions">
              {d.projects.length === 1 && (
                <Link to={portalTo(`/projects/${d.projects[0].id}/integrations`)} data-testid="client-sources-link" className="inline-flex items-center gap-1.5 rounded-lg border border-border px-3 py-2 text-sm font-semibold text-text-secondary hover:text-text-primary">
                  {ar ? 'المنصات المربوطة' : 'Connected platforms'}
                </Link>
              )}
              {d.can.update && (
                <button
                  type="button"
                  data-testid="client-add-project"
                  disabled={addProject.isPending}
                  onClick={async () => {
                    const name = await promptDialog({ title: ar ? 'مشروع جديد' : 'New project', label: ar ? 'اسم المشروع' : 'Project name' })
                    if (name) addProject.mutate(name)
                  }}
                  className="inline-flex items-center gap-1.5 rounded-lg border border-border px-3 py-2 text-sm font-semibold text-text-secondary hover:text-text-primary"
                >
                  <Plus size={14} /> {ar ? 'مشروع جديد' : 'New project'}
                </button>
              )}
            </div>
          )}

          {tab === 'projects' && <ClientProjectsTab projects={d.projects} ar={ar} portalTo={portalTo} />}

          {tab === 'campaigns' && (
            <ul className="space-y-2">
              {d.campaigns.length === 0 && <li className="text-sm text-text-muted">—</li>}
              {d.campaigns.map((c) => (
                <li key={c.id} className="flex items-center justify-between rounded-lg border border-border px-4 py-3 text-sm">
                  <Link to={`/campaigns/${c.project_id}/${c.id}`} className="font-medium text-brand-600 hover:underline">{c.name}</Link>
                  <span className="flex items-center gap-2">
                    <span className="text-xs text-text-secondary">{objectiveLabel(c.objective, locale)}</span>
                    <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${c.status === 'draft' ? 'bg-surface-secondary text-text-muted' : 'bg-success/15 text-success'}`}>{campaignStatusLabel(c.status, locale)}</span>
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
                  <span className="flex items-center gap-2 text-xs text-text-secondary">{ar ? r.service : r.service_en}<span className="rounded-full bg-surface-secondary px-2 py-0.5">{ar ? r.status_label : r.status_label_en}</span></span>
                </li>
              ))}
            </ul>
          )}

          {tab === 'analytics' && d.can.view_analytics && <TabAnalytics clientId={d.id} />}

          {tab === 'reports' && d.can.view_reports && <TabReports d={d} />}

          {tab === 'billing' && <TabBilling clientId={d.id} canManage={d.can.manage_settings} />}
          {tab === 'delivery' && d.can.view_reports && <TabDelivery clientId={d.id} projects={d.projects} />}
          {tab === 'approvals' && canContent && <TabContentApprovals d={d} />}
          {tab === 'portal' && <TabPortal d={d} />}
          {tab === 'messages' && <TabMessages clientId={d.id} />}
          {tab === 'team' && d.can.manage_team && <TabTeam d={d} />}

          {tab === 'files' && d.can.manage_files && <TabFiles clientId={d.id} />}

          {tab === 'activity' && <TabActivity clientId={d.id} />}

          {tab === 'settings' && <TabSettings d={d} />}
      </div>
    </FitPage>
  )
}

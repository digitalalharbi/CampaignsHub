import { useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plug, RefreshCw, Unplug } from 'lucide-react'
import {
  detachBinding,
  listProjectBindings,
  listProjects,
  listProjectTasks,
  syncBinding,
} from './api'
import { PlatformIntegrationsPanel } from './PlatformIntegrationsPanel'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card, CardTitle } from '@/components/ui/Card'
import { EmptyState, Skeleton } from '@/components/ui/States'
import { toApiError } from '@/lib/api/client'
import { listExternalCampaigns } from '@/features/campaigns/api'
import { fmtClock, fmtDateTime } from '@/lib/datetime'
import { useT } from '@/lib/i18n'
import { useUi } from '@/stores/ui'

export function ProjectIntegrationsPage() {
  const t = useT()
  const lang = useUi((s) => s.locale)
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { projectId = '' } = useParams()

  /*
   * This page is mounted in both portals, so the source wizard it sends people to is the one in the
   * portal they are already standing in — an agency reader sent to `/app/integrations` would land
   * on a surface their session does not own.
   */
  const integrationsPath = window.location.pathname.startsWith('/agency')
    ? '/agency/integrations'
    : '/app/integrations'

  const projects = useQuery({ queryKey: ['projects'], queryFn: () => listProjects() })

  // Query key is namespaced by projectId → switching projects yields a fresh, isolated cache.
  const bindings = useQuery({
    queryKey: ['project', projectId, 'integrations'],
    queryFn: () => listProjectBindings(projectId),
    enabled: Boolean(projectId),
  })

  // Project-scoped tasks — also namespaced by projectId, proving multi-domain switch isolation.
  const tasks = useQuery({
    queryKey: ['project', projectId, 'tasks'],
    queryFn: () => listProjectTasks(projectId),
    enabled: Boolean(projectId),
  })

  // Campaigns discovered in this project (synced from bound accounts).
  const campaigns = useQuery({
    queryKey: ['project', projectId, 'campaigns-count'],
    queryFn: () => listExternalCampaigns(projectId),
    enabled: Boolean(projectId),
  })

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['project', projectId, 'integrations'] })

  const syncMutation = useMutation({
    mutationFn: (bindingId: string) => syncBinding(projectId, bindingId),
    onSuccess: invalidate,
  })
  const detachMutation = useMutation({
    mutationFn: (bindingId: string) => detachBinding(projectId, bindingId),
    onSuccess: invalidate,
  })

  const bindError = detachMutation.isError ? toApiError(detachMutation.error) : null

  const rows = bindings.data ?? []
  const providers = [...new Set((campaigns.data ?? []).map((c) => c.provider).filter(Boolean))]
  const lastSync = rows.map((b) => b.account?.last_synced_at).filter(Boolean).sort().at(-1) ?? null
  const discoveredCampaigns = campaigns.data?.length ?? 0

  return (
    <section className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="font-[var(--font-heading)] text-3xl font-extrabold tracking-tight">{t('project_integrations')}</h1>
          <p className="mt-1 text-sm text-text-secondary">{t('project_switch_hint')}</p>
        </div>
        {/* Project selector — switching reloads project-scoped data with no leakage. */}
        <select
          value={projectId}
          onChange={(e) => navigate(`/projects/${e.target.value}/integrations`)}
          className="rounded-[9px] border border-border bg-surface-secondary px-3 py-2 text-sm"
          aria-label={t('projects')}
        >
          {projects.data?.map((p, i) => (
            <option key={p.id} value={p.id}>
              {p.name} #{i + 1}
            </option>
          ))}
        </select>
      </div>

      {bindError && <Alert severity={bindError.status === 409 ? 'warning' : 'danger'} title={bindError.message} />}

      {/* PROJINT-001: the six real ad platforms first — status, accounts, discovery, sync and errors. */}
      <PlatformIntegrationsPanel projectId={projectId} />

      <h2 className="pt-2 text-lg font-bold text-text-primary">
        {lang === 'ar' ? 'الربط التقني للحسابات' : 'Account bindings'}
      </h2>

      {/* Data status — the project's integration surface at a glance. */}
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        {[
          [t('bound_accounts'), String(rows.length)],
          [lang === 'ar' ? 'المنصات' : 'Platforms', String(providers.length)],
          [t('campaigns'), String(discoveredCampaigns)],
          [t('last_updated'), lastSync ? fmtDateTime(lastSync) : '—'],
        ].map(([l, v]) => (
          <div key={String(l)} className="flex flex-col gap-1 rounded-2xl border border-border bg-surface p-4">
            <span className="text-xs font-semibold text-text-secondary">{l as string}</span>
            <span className="tnum text-xl font-extrabold text-text-primary" dir="ltr">{v as string}</span>
          </div>
        ))}
      </div>

      {/*
        INTEGRATION-DATASOURCE-WIZARD-001 §1 §11 — connecting a source happens in ONE place, and this
        is not it.

        What stood here was a «Connect Sandbox» button and, after it, every account the authorisation
        had discovered, each with its own «Bind» — a raw inventory on a page about one project. On the
        live Snapchat estate that is 309 rows a project user has no reason to read, and the sandbox
        button offered a demo connection on a customer's own project page.

        The action that belongs here is the one that takes them to the wizard, with this project in
        hand, where the same accounts are searched, paginated and chosen against the plan quota.
      */}
      <div className="flex flex-wrap gap-2">
        <Button
          variant="secondary"
          data-testid="project-manage-sources"
          onClick={() => navigate(integrationsPath)}
        >
          <Plug size={15} /> {lang === 'ar' ? 'إدارة مصادر البيانات' : 'Manage data sources'}
        </Button>
      </div>

      {/* Bound accounts for THIS project */}
      <Card>
        <CardTitle>{t('bound_accounts')}</CardTitle>
        {bindings.isLoading ? (
          <div className="mt-3 space-y-2">
            <Skeleton className="h-10 w-full" />
            <Skeleton className="h-10 w-full" />
          </div>
        ) : (bindings.data?.length ?? 0) === 0 ? (
          <div className="mt-3">
            <EmptyState
              title={t('no_bound_accounts')}
              description={lang === 'ar'
                ? 'اختر الحسابات من «إدارة مصادر البيانات» أعلاه — تُربط بهذا المشروع وتبدأ المزامنة.'
                : 'Choose accounts from «Manage data sources» above — they bind to this project and start syncing.'}
            />
          </div>
        ) : (
          <div className="mt-3 space-y-2">
            {bindings.data?.map((b) => (
              <div key={b.id} className="flex items-center justify-between rounded-[9px] border border-border p-3">
                <div>
                  <div className="flex items-center gap-2">
                    <span className="text-sm font-bold">{b.account?.name ?? '—'}</span>
                    <Badge tone="info">{b.purpose}</Badge>
                    {b.is_primary && <Badge tone="success">{t('primary')}</Badge>}
                    {!b.is_active && <Badge tone="danger">{t('disabled')}</Badge>}
                  </div>
                  <span className="text-xs text-text-muted">
                    {b.account?.account_type} · <span className="tnum">{b.account?.external_id}</span>
                    {b.account?.last_synced_at && (
                      <>
                        {' '}
                        · {t('last_updated')}:{' '}
                        <span className="tnum">{fmtClock(b.account.last_synced_at)}</span>
                      </>
                    )}
                  </span>
                </div>
                <div className="flex gap-2">
                  <Button
                    variant="secondary"
                    loading={syncMutation.isPending && syncMutation.variables === b.id}
                    onClick={() => syncMutation.mutate(b.id)}
                  >
                    <RefreshCw size={14} /> {t('sync')}
                  </Button>
                  <Button
                    variant="ghost"
                    loading={detachMutation.isPending && detachMutation.variables === b.id}
                    onClick={() => detachMutation.mutate(b.id)}
                  >
                    <Unplug size={14} /> {t('detach')}
                  </Button>
                </div>
              </div>
            ))}
          </div>
        )}
      </Card>

      {/* Project-scoped tasks — these change when the active project changes (no leakage). */}
      <Card>
        <CardTitle>{t('project_tasks')}</CardTitle>
        {tasks.isLoading ? (
          <div className="mt-3 space-y-2">
            <Skeleton className="h-8 w-full" />
          </div>
        ) : (tasks.data?.length ?? 0) === 0 ? (
          <div className="mt-3">
            <EmptyState title={t('no_project_tasks')} />
          </div>
        ) : (
          <div className="mt-3 space-y-2">
            {tasks.data?.map((task) => (
              <div key={task.id} className="flex items-center justify-between rounded-[9px] border border-border p-2.5">
                <span className="text-sm font-semibold">{task.title}</span>
                <div className="flex items-center gap-2">
                  <Badge tone={task.priority === 'high' || task.priority === 'urgent' ? 'warning' : 'neutral'}>
                    {task.priority}
                  </Badge>
                  <Badge tone={task.is_overdue ? 'danger' : 'info'}>{task.status}</Badge>
                </div>
              </div>
            ))}
          </div>
        )}
      </Card>
    </section>
  )
}

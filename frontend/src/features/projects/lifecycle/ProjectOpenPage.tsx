import { useState } from 'react'
import { Navigate, useLocation, useNavigate, useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Archive, History, Plug, RotateCcw, Trash2 } from 'lucide-react'
import { getData } from '@/lib/api/client'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { PageIntro } from '@/components/ui/PageIntro'
import { Skeleton } from '@/components/ui/States'
import { QueryFailure } from '@/components/ui/QueryFailure'
import { useInAdvertiserPortal, usePortalPath } from '@/app/portalPath'
import { useUi } from '@/stores/ui'
import { listClientWorkspaces, listProjectBindings, type Project } from '@/features/projects/api'
import { getProjectLifecycle, lifecycleLabel, lifecycleTone, lt } from '@/lib/projectLifecycle'
import { fmtDate } from '@/lib/datetime'
import { ProjectLifecycleDialog, useLifecyclePermissions, type LifecycleDialogState } from './ProjectLifecycleDialogs'

/**
 * PROJECT-LIFECYCLE-001 §8 (builder N2) — «فتح» on a project.
 *
 * An ARCHIVED project opens its own page in VIEW-ONLY mode: a banner states the state and offers «استعادة» / «حذف»
 * (by permission), and nothing on the page writes — no sync, no edit, no action. It is never a redirect to the client
 * page. A live project keeps the existing behaviour: the agency opens it inside its client, the advertiser portal opens
 * its dashboard scoped to it. Same component for every workspace type.
 */
export function ProjectOpenPage() {
  const { projectId = '' } = useParams()
  const { search, hash } = useLocation()
  const inAdvertiser = useInAdvertiserPortal()
  const project = useQuery({ queryKey: ['projects', 'open', projectId], queryFn: () => getData<Project>(`/projects/${projectId}`), retry: false })

  if (project.isPending) return <div className="space-y-3"><Skeleton className="h-16" /><Skeleton className="h-48" /></div>
  if (project.isError || !project.data) {
    return <Navigate to={inAdvertiser ? `/app/projects${search}` : `/agency/clients${search}${hash}`} replace />
  }
  if (project.data.lifecycle_status === 'archived') return <ArchivedProjectView project={project.data} />

  return inAdvertiser
    ? <Navigate to={`/app/dashboard?project=${project.data.id}`} replace />
    : <Navigate to={`/agency/clients/${project.data.client_workspace_id}${search}${hash}`} replace />
}

function ArchivedProjectView({ project }: { project: Project }) {
  const ar = useUi((u) => u.locale) === 'ar'
  const navigate = useNavigate()
  const portalPath = usePortalPath()
  const inAdvertiser = useInAdvertiserPortal()
  const can = useLifecyclePermissions()
  const [dialog, setDialog] = useState<LifecycleDialogState>(null)
  const history = useQuery({ queryKey: ['project-lifecycle', 'history', project.id], queryFn: () => getProjectLifecycle(project.id) })
  const bindings = useQuery({ queryKey: ['project-lifecycle', 'bindings', project.id], queryFn: () => listProjectBindings(project.id), retry: false })
  const clients = useQuery({ queryKey: ['client-workspaces'], queryFn: listClientWorkspaces, enabled: !inAdvertiser })
  const clientName = clients.data?.find((c) => c.id === project.client_workspace_id)?.name ?? null
  const target = { id: project.id, name: project.name, client_workspace_id: project.client_workspace_id, status: 'archived' }

  return (
    <section className="space-y-4" data-testid="archived-project-page" data-view-only="true">
      <div role="status" data-testid="archived-project-banner" className="flex flex-col gap-3 rounded-2xl border border-warning bg-warning-soft p-4 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex min-w-0 items-start gap-2">
          <Archive size={18} className="mt-0.5 shrink-0 text-warning" aria-hidden />
          <div className="min-w-0">
            <p className="flex flex-wrap items-center gap-2 text-sm font-bold text-text-primary">
              <Badge tone={lifecycleTone('archived')}>{lifecycleLabel('archived', ar)}</Badge>
              <span>{lt('projects.lifecycle.banner.archived', ar)}</span>
            </p>
            {project.archived_at && (
              <p className="mt-1 text-xs text-text-secondary">{lt('projects.lifecycle.banner.archived_at', ar)}: <span className="tnum">{fmtDate(project.archived_at)}</span></p>
            )}
          </div>
        </div>
        <div className="flex shrink-0 flex-wrap gap-2">
          {can.restore && (
            <Button data-testid="archived-project-restore" onClick={() => setDialog({ kind: 'restore', project: target })}>
              <RotateCcw size={15} /> {lt('projects.lifecycle.action.restore', ar)}
            </Button>
          )}
          {can.delete && (
            <Button variant="secondary" data-testid="archived-project-delete" onClick={() => setDialog({ kind: 'delete', project: target })}>
              <Trash2 size={15} /> {lt('projects.lifecycle.action.delete', ar)}
            </Button>
          )}
        </div>
      </div>

      <PageIntro
        testid="archived-project-intro"
        title={project.name}
        purpose={clientName ? `${lt('projects.lifecycle.col.client', ar)}: ${clientName}` : undefined}
        badges={<Badge tone={lifecycleTone('archived')}>{lifecycleLabel('archived', ar)}</Badge>}
      />

      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <h2 className="mb-2 flex items-center gap-2 text-sm font-bold"><Plug size={15} aria-hidden /> {ar ? 'الحسابات' : 'Accounts'}</h2>
          {bindings.isLoading ? <Skeleton className="h-16" /> : bindings.isError ? (
            <QueryFailure error={bindings.error} ar={ar} testId="archived-project-bindings-failure" fallbackTitle={ar ? 'تعذّر تحميل الحسابات.' : 'The accounts could not be loaded.'} />
          ) : (bindings.data ?? []).length === 0 ? (
            <p className="text-xs text-text-muted">{ar ? 'لا حسابات مرتبطة.' : 'No linked accounts.'}</p>
          ) : (
            <ul className="grid gap-1.5" data-testid="archived-project-bindings">
              {(bindings.data ?? []).map((b) => (
                <li key={b.id} className="flex min-w-0 items-center justify-between gap-2 text-sm">
                  <span className="truncate">{b.account?.name ?? b.provider}</span>
                  <Badge tone={b.is_active ? 'neutral' : 'warning'}>{b.is_active ? (ar ? 'مرتبط — المزامنة متوقفة' : 'Linked — sync stopped') : (ar ? 'مفكوك الربط' : 'Unlinked')}</Badge>
                </li>
              ))}
            </ul>
          )}
        </Card>

        <Card>
          <h2 className="mb-2 flex items-center gap-2 text-sm font-bold"><History size={15} aria-hidden /> {lt('projects.lifecycle.history', ar)}</h2>
          {history.isLoading ? <Skeleton className="h-16" /> : (
            <ol className="grid gap-1.5" data-testid="archived-project-history">
              {(history.data?.transitions ?? []).slice(0, 8).map((t) => (
                <li key={t.id} className="flex min-w-0 flex-wrap items-center gap-1.5 text-xs text-text-secondary">
                  <span className="tnum">{fmtDate(t.at)}</span>
                  <Badge tone={lifecycleTone(t.from)}>{lifecycleLabel(t.from, ar)}</Badge>
                  <span aria-hidden>{ar ? '←' : '→'}</span>
                  <Badge tone={lifecycleTone(t.to)}>{lifecycleLabel(t.to, ar)}</Badge>
                  {t.actor_name && <span className="truncate">{t.actor_name}</span>}
                </li>
              ))}
            </ol>
          )}
        </Card>
      </div>

      <ProjectLifecycleDialog
        state={dialog}
        ar={ar}
        onClose={() => setDialog(null)}
        onDone={() => {
          setDialog(null)
          navigate(portalPath('/settings/workspace?tab=projects'))
        }}
      />
    </section>
  )
}

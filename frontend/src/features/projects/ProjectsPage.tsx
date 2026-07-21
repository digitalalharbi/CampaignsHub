import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { FolderKanban } from 'lucide-react'
import { listClientWorkspaces, listProjects, type ClientWorkspace } from './api'
import { Badge } from '@/components/ui/Badge'
import { Card } from '@/components/ui/Card'
import { EmptyState, Skeleton } from '@/components/ui/States'
import { useT } from '@/lib/i18n'

export function ProjectsPage() {
  const t = useT()
  const projects = useQuery({ queryKey: ['projects'], queryFn: listProjects })
  const workspaces = useQuery({ queryKey: ['client-workspaces'], queryFn: listClientWorkspaces })

  const wsById = new Map<string, ClientWorkspace>((workspaces.data ?? []).map((w) => [w.id, w]))

  return (
    <section className="space-y-5">
      <div>
        <h1 className="font-[var(--font-heading)] text-xl font-extrabold">{t('projects')}</h1>
        <p className="mt-1 text-[13px] text-text-secondary">{t('data_source')}: CampaignsHub API</p>
      </div>

      {projects.isLoading ? (
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
          {Array.from({ length: 3 }).map((_, i) => (
            <Skeleton key={i} className="h-28 w-full" />
          ))}
        </div>
      ) : (projects.data?.length ?? 0) === 0 ? (
        <EmptyState title={t('no_projects')} />
      ) : (
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
          {projects.data?.map((p) => {
            const ws = wsById.get(p.client_workspace_id)
            return (
              <Card key={p.id}>
                <div className="flex items-start justify-between">
                  <div className="flex items-center gap-2">
                    <FolderKanban size={18} className="text-brand-600" />
                    <span className="text-sm font-bold">{p.name}</span>
                  </div>
                  <Badge tone={p.status === 'active' ? 'success' : 'neutral'}>{p.status}</Badge>
                </div>
                <p className="mt-2 text-[12px] text-text-secondary">{ws?.name ?? '—'}</p>
                <div className="mt-3 flex items-center justify-between">
                  <span className="text-[11px] text-text-muted">
                    {t('setup')}: <span className="tnum">{p.setup_completion}%</span>
                  </span>
                  <Link
                    to={`/projects/${p.id}/integrations`}
                    className="text-[12px] font-bold text-brand-600 hover:underline"
                  >
                    {t('integrations')} →
                  </Link>
                </div>
              </Card>
            )
          })}
        </div>
      )}
    </section>
  )
}

import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { Archive, FolderKanban, Plus } from 'lucide-react'
import {
  archiveProject,
  createProject,
  listClientWorkspaces,
  listProjects,
  type ClientWorkspace,
} from './api'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Modal } from '@/components/ui/Modal'
import { Select } from '@/components/ui/Select'
import { EmptyState, Skeleton } from '@/components/ui/States'
import { useT } from '@/lib/i18n'

export function ProjectsPage() {
  const t = useT()
  const queryClient = useQueryClient()
  const [modalOpen, setModalOpen] = useState(false)
  const [name, setName] = useState('')
  const [workspaceId, setWorkspaceId] = useState('')

  const projects = useQuery({ queryKey: ['projects'], queryFn: listProjects })
  const workspaces = useQuery({ queryKey: ['client-workspaces'], queryFn: listClientWorkspaces })
  const wsById = new Map<string, ClientWorkspace>((workspaces.data ?? []).map((w) => [w.id, w]))

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['projects'] })
  const createMutation = useMutation({
    mutationFn: createProject,
    onSuccess: () => {
      setModalOpen(false)
      setName('')
      invalidate()
    },
  })
  const archiveMutation = useMutation({ mutationFn: archiveProject, onSuccess: invalidate })

  return (
    <section className="space-y-5">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="font-[var(--font-heading)] text-xl font-extrabold">{t('projects')}</h1>
          <p className="mt-1 text-[13px] text-text-secondary">{t('data_source')}: CampaignsHub API</p>
        </div>
        <Button onClick={() => setModalOpen(true)}>
          <Plus size={15} /> {t('new_project')}
        </Button>
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
                  <div className="flex items-center gap-3">
                    <button
                      type="button"
                      onClick={() => archiveMutation.mutate(p.id)}
                      className="inline-flex items-center gap-1 text-[12px] text-text-muted hover:text-danger"
                      aria-label={t('archive')}
                    >
                      <Archive size={13} /> {t('archive')}
                    </button>
                    <Link
                      to={`/projects/${p.id}/integrations`}
                      className="text-[12px] font-bold text-brand-600 hover:underline"
                    >
                      {t('integrations')} →
                    </Link>
                  </div>
                </div>
              </Card>
            )
          })}
        </div>
      )}

      <Modal
        open={modalOpen}
        onClose={() => setModalOpen(false)}
        title={t('new_project')}
        footer={
          <>
            <Button variant="secondary" onClick={() => setModalOpen(false)}>
              {t('cancel')}
            </Button>
            <Button
              loading={createMutation.isPending}
              disabled={!name || !workspaceId}
              onClick={() => createMutation.mutate({ client_workspace_id: workspaceId, name })}
            >
              {t('save')}
            </Button>
          </>
        }
      >
        <div className="space-y-3">
          <Field label={t('client_workspace')} required>
            <Select
              value={workspaceId}
              onChange={(e) => setWorkspaceId(e.target.value)}
              placeholder="—"
              options={(workspaces.data ?? []).map((w) => ({ value: w.id, label: w.name }))}
            />
          </Field>
          <Field label={t('name')} required>
            <Input value={name} onChange={(e) => setName(e.target.value)} data-autofocus />
          </Field>
        </div>
      </Modal>
    </section>
  )
}

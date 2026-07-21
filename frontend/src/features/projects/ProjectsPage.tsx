import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { Copy, FolderKanban, Pause, Pencil, Play, Plus, RotateCcw, Users } from 'lucide-react'
import {
  archiveProject,
  createProject,
  listClientWorkspaces,
  listProjects,
  projectAction,
  updateProject,
  type ClientWorkspace,
  type Project,
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

const STATUSES = ['draft', 'onboarding', 'active', 'paused', 'completed', 'archived']

export function ProjectsPage() {
  const t = useT()
  const queryClient = useQueryClient()
  const [creating, setCreating] = useState(false)
  const [editing, setEditing] = useState<Project | null>(null)
  const [name, setName] = useState('')
  const [status, setStatus] = useState('active')
  const [workspaceId, setWorkspaceId] = useState('')
  const [showArchived, setShowArchived] = useState(false)

  const projects = useQuery({
    queryKey: ['projects', { showArchived }],
    queryFn: () => listProjects(showArchived),
  })
  const workspaces = useQuery({ queryKey: ['client-workspaces'], queryFn: listClientWorkspaces })
  const wsById = new Map<string, ClientWorkspace>((workspaces.data ?? []).map((w) => [w.id, w]))

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['projects'] })

  const createMutation = useMutation({
    mutationFn: createProject,
    onSuccess: () => {
      setCreating(false)
      setName('')
      invalidate()
    },
  })
  const updateMutation = useMutation({
    mutationFn: ({ id, ...input }: { id: string; name: string; status: string }) => updateProject(id, input),
    onSuccess: () => {
      setEditing(null)
      invalidate()
    },
  })
  const actionMutation = useMutation({
    mutationFn: ({ id, action }: { id: string; action: 'clone' | 'restore' | 'pause' | 'resume' }) =>
      projectAction(id, action),
    onSuccess: invalidate,
  })
  const archiveMutation = useMutation({ mutationFn: archiveProject, onSuccess: invalidate })

  const openEdit = (p: Project) => {
    setEditing(p)
    setName(p.name)
    setStatus(p.status)
  }

  return (
    <section className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="font-[var(--font-heading)] text-xl font-extrabold">{t('projects')}</h1>
          <p className="mt-1 text-[13px] text-text-secondary">{t('data_source')}: CampaignsHub API</p>
        </div>
        <div className="flex items-center gap-2">
          <Button variant={showArchived ? 'secondary' : 'ghost'} onClick={() => setShowArchived((v) => !v)}>
            {showArchived ? t('hide_archived') : t('show_archived')}
          </Button>
          <Button
            onClick={() => {
              setCreating(true)
              setName('')
              setWorkspaceId('')
            }}
          >
            <Plus size={15} /> {t('new_project')}
          </Button>
        </div>
      </div>

      {projects.isLoading ? (
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
          {Array.from({ length: 3 }).map((_, i) => (
            <Skeleton key={i} className="h-36 w-full" />
          ))}
        </div>
      ) : (projects.data?.length ?? 0) === 0 ? (
        <EmptyState title={t('no_projects')} />
      ) : (
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
          {projects.data?.map((p) => {
            const ws = wsById.get(p.client_workspace_id)
            const archived = p.status === 'archived'
            const paused = p.status === 'paused'
            return (
              <Card key={p.id}>
                <div className="flex items-start justify-between">
                  <div className="flex items-center gap-2">
                    <FolderKanban size={18} className="text-brand-600" />
                    <span className="text-sm font-bold">{p.name}</span>
                  </div>
                  <Badge tone={p.status === 'active' ? 'success' : archived ? 'neutral' : 'warning'}>{p.status}</Badge>
                </div>
                <p className="mt-2 text-[12px] text-text-secondary">{ws?.name ?? '—'}</p>
                <span className="mt-1 block text-[11px] text-text-muted">
                  {t('setup')}: <span className="tnum">{p.setup_completion}%</span>
                </span>

                <div className="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1.5 border-t border-border pt-3 text-[12px]">
                  <button type="button" onClick={() => openEdit(p)} className="inline-flex items-center gap-1 text-text-secondary hover:text-text-primary">
                    <Pencil size={13} /> {t('edit')}
                  </button>
                  <button type="button" onClick={() => actionMutation.mutate({ id: p.id, action: 'clone' })} className="inline-flex items-center gap-1 text-text-secondary hover:text-text-primary">
                    <Copy size={13} /> {t('clone')}
                  </button>
                  {archived ? (
                    <button type="button" onClick={() => actionMutation.mutate({ id: p.id, action: 'restore' })} className="inline-flex items-center gap-1 text-success hover:opacity-80">
                      <RotateCcw size={13} /> {t('restore')}
                    </button>
                  ) : paused ? (
                    <button type="button" onClick={() => actionMutation.mutate({ id: p.id, action: 'resume' })} className="inline-flex items-center gap-1 text-success hover:opacity-80">
                      <Play size={13} /> {t('resume')}
                    </button>
                  ) : (
                    <button type="button" onClick={() => actionMutation.mutate({ id: p.id, action: 'pause' })} className="inline-flex items-center gap-1 text-warning hover:opacity-80">
                      <Pause size={13} /> {t('pause')}
                    </button>
                  )}
                  {!archived && (
                    <button type="button" onClick={() => archiveMutation.mutate(p.id)} className="inline-flex items-center gap-1 text-text-muted hover:text-danger">
                      {t('archive')}
                    </button>
                  )}
                  <Link to={`/projects/${p.id}/team`} className="inline-flex items-center gap-1 text-text-secondary hover:text-text-primary">
                    <Users size={13} /> {t('team')}
                  </Link>
                  <Link to={`/projects/${p.id}/integrations`} className="ms-auto font-bold text-brand-600 hover:underline">
                    {t('integrations')} →
                  </Link>
                </div>
              </Card>
            )
          })}
        </div>
      )}

      {/* Create */}
      <Modal
        open={creating}
        onClose={() => setCreating(false)}
        title={t('new_project')}
        footer={
          <>
            <Button variant="secondary" onClick={() => setCreating(false)}>{t('cancel')}</Button>
            <Button loading={createMutation.isPending} disabled={!name || !workspaceId} onClick={() => createMutation.mutate({ client_workspace_id: workspaceId, name })}>
              {t('save')}
            </Button>
          </>
        }
      >
        <div className="space-y-3">
          <Field label={t('client_workspace')} required>
            <Select value={workspaceId} onChange={(e) => setWorkspaceId(e.target.value)} placeholder="—" options={(workspaces.data ?? []).map((w) => ({ value: w.id, label: w.name }))} />
          </Field>
          <Field label={t('name')} required>
            <Input value={name} onChange={(e) => setName(e.target.value)} data-autofocus />
          </Field>
        </div>
      </Modal>

      {/* Edit */}
      <Modal
        open={editing !== null}
        onClose={() => setEditing(null)}
        title={t('edit_project')}
        footer={
          <>
            <Button variant="secondary" onClick={() => setEditing(null)}>{t('cancel')}</Button>
            <Button loading={updateMutation.isPending} onClick={() => editing && updateMutation.mutate({ id: editing.id, name, status })}>
              {t('save')}
            </Button>
          </>
        }
      >
        <div className="space-y-3">
          <Field label={t('name')} required>
            <Input value={name} onChange={(e) => setName(e.target.value)} data-autofocus />
          </Field>
          <Field label={t('status_label')}>
            <Select value={status} onChange={(e) => setStatus(e.target.value)} options={STATUSES.map((s) => ({ value: s, label: s }))} />
          </Field>
        </div>
      </Modal>
    </section>
  )
}

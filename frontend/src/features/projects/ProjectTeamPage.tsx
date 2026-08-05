import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Trash2, UserPlus } from 'lucide-react'
import { addProjectMember, listProjectTeam, listUsers, removeProjectMember } from './api'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card, CardTitle } from '@/components/ui/Card'
import { Field } from '@/components/ui/Field'
import { Select } from '@/components/ui/Select'
import { EmptyState, Skeleton } from '@/components/ui/States'
import { toApiError } from '@/lib/api/client'
import { usePortalPath } from '@/app/portalPath'
import { useT } from '@/lib/i18n'

const ROLES = [
  'account_manager',
  'media_buyer',
  'analyst',
  'content',
  'finance',
  'client_admin',
  'client_approver',
  'client_viewer',
  'viewer',
]

export function ProjectTeamPage() {
  const t = useT()
  const portalPath = usePortalPath()
  const queryClient = useQueryClient()
  const { projectId = '' } = useParams()
  const [userId, setUserId] = useState('')
  const [role, setRole] = useState('account_manager')

  const team = useQuery({
    queryKey: ['project', projectId, 'team'],
    queryFn: () => listProjectTeam(projectId),
    enabled: Boolean(projectId),
  })
  const users = useQuery({ queryKey: ['users'], queryFn: listUsers })

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['project', projectId, 'team'] })
  const addMutation = useMutation({
    mutationFn: () => addProjectMember(projectId, { user_id: Number(userId), role }),
    onSuccess: () => {
      setUserId('')
      invalidate()
    },
  })
  const removeMutation = useMutation({
    mutationFn: (membershipId: string) => removeProjectMember(projectId, membershipId),
    onSuccess: invalidate,
  })

  const addError = addMutation.isError ? toApiError(addMutation.error) : null
  const removeError = removeMutation.isError ? toApiError(removeMutation.error) : null

  return (
    <section className="space-y-5">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="font-[var(--font-heading)] text-xl font-extrabold">{t('project_team')}</h1>
          <p className="mt-1 text-sm text-text-secondary">{t('project_team_hint')}</p>
        </div>
        <Link to={portalPath(`/projects/${projectId}/integrations`)} className="text-xs font-bold text-brand-600 hover:underline">
          {t('integrations')} →
        </Link>
      </div>

      {removeError && <Alert severity="warning" title={removeError.message} />}

      <Card>
        <CardTitle>{t('add_member')}</CardTitle>
        <div className="mt-3 flex flex-wrap items-end gap-3">
          <Field label={t('name')}>
            <Select
              value={userId}
              onChange={(e) => setUserId(e.target.value)}
              placeholder="—"
              options={(users.data ?? []).map((u) => ({ value: String(u.id), label: `${u.name} · ${u.email}` }))}
            />
          </Field>
          <Field label={t('role')}>
            <Select value={role} onChange={(e) => setRole(e.target.value)} options={ROLES.map((r) => ({ value: r, label: r }))} />
          </Field>
          <Button loading={addMutation.isPending} disabled={!userId} onClick={() => addMutation.mutate()}>
            <UserPlus size={15} /> {t('add')}
          </Button>
        </div>
        {addError && !addError.errors && <p className="mt-2 text-xs text-danger">{addError.message}</p>}
      </Card>

      <Card>
        <CardTitle>{t('members')}</CardTitle>
        {team.isLoading ? (
          <div className="mt-3 space-y-2">
            <Skeleton className="h-10 w-full" />
          </div>
        ) : (team.data?.length ?? 0) === 0 ? (
          <div className="mt-3">
            <EmptyState title={t('no_members')} />
          </div>
        ) : (
          <div className="mt-3 space-y-2">
            {team.data?.map((m) => (
              <div key={m.id} className="flex items-center justify-between rounded-[9px] border border-border p-3">
                <div>
                  <span className="text-sm font-bold">{m.name}</span>
                  <span className="ms-2 text-xs text-text-muted">{m.email}</span>
                </div>
                <div className="flex items-center gap-2">
                  <Badge tone="info">{m.role}</Badge>
                  <button
                    type="button"
                    onClick={() => removeMutation.mutate(m.id)}
                    className="rounded p-1 text-text-muted hover:text-danger"
                    aria-label={t('remove')}
                  >
                    <Trash2 size={15} />
                  </button>
                </div>
              </div>
            ))}
          </div>
        )}
      </Card>
    </section>
  )
}

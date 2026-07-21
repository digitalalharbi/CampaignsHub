import { useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plug, RefreshCw, Unplug } from 'lucide-react'
import {
  bindAccount,
  connectSandbox,
  detachBinding,
  listProjectBindings,
  listProjects,
  syncBinding,
  type ExternalAccount,
} from './api'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card, CardTitle } from '@/components/ui/Card'
import { EmptyState, Skeleton } from '@/components/ui/States'
import { toApiError } from '@/lib/api/client'
import { useT } from '@/lib/i18n'

export function ProjectIntegrationsPage() {
  const t = useT()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { projectId = '' } = useParams()
  const [discovered, setDiscovered] = useState<ExternalAccount[] | null>(null)

  const projects = useQuery({ queryKey: ['projects'], queryFn: listProjects })

  // Query key is namespaced by projectId → switching projects yields a fresh, isolated cache.
  const bindings = useQuery({
    queryKey: ['project', projectId, 'integrations'],
    queryFn: () => listProjectBindings(projectId),
    enabled: Boolean(projectId),
  })

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['project', projectId, 'integrations'] })

  const connectMutation = useMutation({
    mutationFn: () => connectSandbox(projectId),
    onSuccess: (res) => setDiscovered(res.accounts),
  })
  const bindMutation = useMutation({
    mutationFn: (accountId: string) =>
      bindAccount(projectId, { external_account_id: accountId, purpose: 'advertising', confirm: true }),
    onSuccess: () => {
      setDiscovered(null)
      invalidate()
    },
  })
  const syncMutation = useMutation({
    mutationFn: (bindingId: string) => syncBinding(projectId, bindingId),
    onSuccess: invalidate,
  })
  const detachMutation = useMutation({
    mutationFn: (bindingId: string) => detachBinding(projectId, bindingId),
    onSuccess: invalidate,
  })

  const bindError = bindMutation.isError ? toApiError(bindMutation.error) : null

  return (
    <section className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="font-[var(--font-heading)] text-xl font-extrabold">{t('project_integrations')}</h1>
          <p className="mt-1 text-[13px] text-text-secondary">{t('project_switch_hint')}</p>
        </div>
        {/* Project selector — switching reloads project-scoped data with no leakage. */}
        <select
          value={projectId}
          onChange={(e) => navigate(`/projects/${e.target.value}/integrations`)}
          className="rounded-[9px] border border-border bg-surface-secondary px-3 py-2 text-[13px]"
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

      <div className="flex gap-2">
        <Button loading={connectMutation.isPending} onClick={() => connectMutation.mutate()}>
          <Plug size={15} /> {t('connect_sandbox')}
        </Button>
      </div>

      {/* Wizard step: discovered accounts to bind */}
      {discovered && (
        <Card>
          <CardTitle>{t('discovered_accounts')}</CardTitle>
          <div className="mt-3 space-y-2">
            {discovered.map((a) => (
              <div key={a.id} className="flex items-center justify-between rounded-[9px] border border-border p-2.5">
                <div>
                  <span className="text-[13px] font-semibold">{a.name}</span>
                  <span className="ms-2 text-[11px] text-text-muted">
                    {a.account_type} · <span className="tnum">{a.external_id}</span>
                  </span>
                </div>
                <Button
                  variant="secondary"
                  loading={bindMutation.isPending && bindMutation.variables === a.id}
                  onClick={() => bindMutation.mutate(a.id)}
                >
                  {t('bind')}
                </Button>
              </div>
            ))}
          </div>
        </Card>
      )}

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
            <EmptyState title={t('no_bound_accounts')} description={t('no_bound_accounts_hint')} />
          </div>
        ) : (
          <div className="mt-3 space-y-2">
            {bindings.data?.map((b) => (
              <div key={b.id} className="flex items-center justify-between rounded-[9px] border border-border p-3">
                <div>
                  <div className="flex items-center gap-2">
                    <span className="text-[13px] font-bold">{b.account?.name ?? '—'}</span>
                    <Badge tone="info">{b.purpose}</Badge>
                    {b.is_primary && <Badge tone="success">{t('primary')}</Badge>}
                    {!b.is_active && <Badge tone="danger">{t('disabled')}</Badge>}
                  </div>
                  <span className="text-[11px] text-text-muted">
                    {b.account?.account_type} · <span className="tnum">{b.account?.external_id}</span>
                    {b.account?.last_synced_at && (
                      <>
                        {' '}
                        · {t('last_updated')}:{' '}
                        <span className="tnum">{new Date(b.account.last_synced_at).toLocaleTimeString()}</span>
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
    </section>
  )
}

import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plug, RefreshCw, Unlink, Server, Check } from 'lucide-react'
import {
  listProjectBindings,
  connectSandbox,
  bindAccount,
  syncBinding,
  detachBinding,
  type Binding,
  type ExternalAccount,
} from '@/features/projects/api'
import { useProject } from '@/stores/project'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card, CardDescription, CardTitle } from '@/components/ui/Card'
import { Modal } from '@/components/ui/Modal'
import { ErrorState, Skeleton } from '@/components/ui/States'
import { toApiError } from '@/lib/api/client'
import { useT } from '@/lib/i18n'

const ALL_PROVIDERS = [
  { id: 'sandbox', name: 'Sandbox', icon: Server, color: 'text-gray-500' },
  // Future providers
  // { id: 'meta', name: 'Meta Ads', icon: Facebook, color: 'text-blue-600' },
  // { id: 'google_ads', name: 'Google Ads', icon: Search, color: 'text-red-500' },
  // { id: 'snapchat', name: 'Snapchat', icon: Ghost, color: 'text-yellow-400' },
  // { id: 'tiktok', name: 'TikTok', icon: Music, color: 'text-black' },
]

export function IntegrationsPage() {
  const t = useT()
  const queryClient = useQueryClient()
  const { currentProjectId } = useProject()

  const [sandboxAccounts, setSandboxAccounts] = useState<ExternalAccount[] | null>(null)
  const [isSandboxModalOpen, setSandboxModalOpen] = useState(false)

  const query = useQuery({
    queryKey: ['projects', currentProjectId, 'bindings'],
    queryFn: () => listProjectBindings(currentProjectId!),
    enabled: !!currentProjectId,
  })

  // Mutations
  const connectSandboxMutation = useMutation({
    mutationFn: connectSandbox,
    onSuccess: (data) => {
      setSandboxAccounts(data.accounts)
      setSandboxModalOpen(true)
    },
  })

  const bindAccountMutation = useMutation({
    mutationFn: (accountId: string) =>
      bindAccount(currentProjectId!, {
        external_account_id: accountId,
        purpose: 'advertising',
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['projects', currentProjectId, 'bindings'] })
      setSandboxModalOpen(false)
      setSandboxAccounts(null)
    },
  })

  const syncMutation = useMutation({
    mutationFn: (bindingId: string) => syncBinding(currentProjectId!, bindingId),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['projects', currentProjectId, 'bindings'] }),
  })

  const detachMutation = useMutation({
    mutationFn: (bindingId: string) => detachBinding(currentProjectId!, bindingId),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['projects', currentProjectId, 'bindings'] }),
  })

  const handleConnectProvider = (providerId: string) => {
    if (providerId === 'sandbox') {
      connectSandboxMutation.mutate(currentProjectId!)
    }
  }

  if (!currentProjectId) {
    return (
      <div className="flex h-64 items-center justify-center rounded-lg border border-dashed border-border p-8 text-center text-text-muted">
        {t('no_projects')}
      </div>
    )
  }

  const connectError = connectSandboxMutation.isError ? toApiError(connectSandboxMutation.error) : null

  return (
    <section className="space-y-8">
      <div>
        <h1 className="font-[var(--font-heading)] text-xl font-extrabold">{t('integrations')}</h1>
        <p className="mt-1 text-[13px] text-text-secondary">Manage advertising and analytics connections for this project.</p>
      </div>

      {connectError && (
        <div className="rounded-[12px] bg-[var(--warning-background)] px-4 py-3 text-[13px] text-warning">
          {connectError.message}
        </div>
      )}

      {/* Bound Accounts Section */}
      <div className="space-y-4">
        <h2 className="font-[var(--font-heading)] text-lg font-bold">Active Connections</h2>
        
        {query.isLoading ? (
          <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
            {Array.from({ length: 3 }).map((_, i) => (
              <Skeleton key={i} className="h-32 w-full" />
            ))}
          </div>
        ) : query.isError ? (
          <ErrorState title={t('error')} onRetry={() => query.refetch()} />
        ) : query.data?.length === 0 ? (
          <div className="flex h-32 flex-col items-center justify-center rounded-[12px] border border-dashed border-border text-[13px] text-text-muted">
            <Plug className="mb-2 opacity-50" size={24} />
            No active connections for this project.
          </div>
        ) : (
          <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
            {query.data?.map((b: Binding) => (
              <Card key={b.id}>
                <div className="flex items-start justify-between p-4">
                  <div>
                    <CardTitle>
                      <div className="flex items-center gap-2">
                        <span className="capitalize">{b.provider}</span>
                        {b.is_active && <Badge tone="success">Active</Badge>}
                      </div>
                    </CardTitle>
                    <CardDescription>
                      <div className="mt-1 flex flex-col gap-1">
                        <span className="font-semibold text-text-primary">{b.account?.name}</span>
                        <span className="tnum text-[11px] text-text-muted">ID: {b.account?.external_id}</span>
                        <span className="text-[11px] text-text-muted">
                          Last Sync: {b.account?.last_synced_at ? new Date(b.account.last_synced_at).toLocaleString() : 'Never'}
                        </span>
                      </div>
                    </CardDescription>
                  </div>
                </div>
                <div className="flex border-t border-border bg-surface-secondary px-4 py-3">
                  <div className="flex w-full gap-2">
                    <Button
                      variant="primary"
                      className="flex-1"
                      loading={syncMutation.isPending && syncMutation.variables === b.id}
                      onClick={() => syncMutation.mutate(b.id)}
                    >
                      <RefreshCw size={14} /> Sync
                    </Button>
                    <Button
                      variant="ghost"
                      className="text-danger hover:bg-negative-background hover:text-danger"
                      loading={detachMutation.isPending && detachMutation.variables === b.id}
                      onClick={() => {
                        if (confirm('Are you sure you want to detach this account?')) {
                          detachMutation.mutate(b.id)
                        }
                      }}
                    >
                      <Unlink size={14} />
                    </Button>
                  </div>
                </div>
              </Card>
            ))}
          </div>
        )}
      </div>

      {/* Available Providers Section */}
      <div className="space-y-4">
        <h2 className="font-[var(--font-heading)] text-lg font-bold">Available Providers</h2>
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
          {ALL_PROVIDERS.map((provider) => (
            <div
              key={provider.id}
              className="group flex cursor-pointer items-center justify-between rounded-[12px] border border-border bg-surface p-4 transition-all hover:border-brand-500 hover:shadow-sm"
              onClick={() => handleConnectProvider(provider.id)}
            >
              <div className="flex items-center gap-3">
                <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-surface-secondary transition-colors group-hover:bg-brand-50">
                  <provider.icon className={provider.color} size={20} />
                </div>
                <span className="font-semibold">{provider.name}</span>
              </div>
              <Button
                variant="secondary"
                loading={connectSandboxMutation.isPending && connectSandboxMutation.variables === currentProjectId && provider.id === 'sandbox'}
              >
                Connect
              </Button>
            </div>
          ))}
        </div>
      </div>

      {/* Sandbox Accounts Selection Modal */}
      <Modal
        open={isSandboxModalOpen}
        onClose={() => setSandboxModalOpen(false)}
        title="Select Account"
      >
        <p className="text-sm text-text-secondary">Choose an advertising account to bind to this project.</p>
        <div className="mt-4 space-y-2">
          {sandboxAccounts?.map((account) => (
            <div
              key={account.id}
              className="flex items-center justify-between rounded-[12px] border border-border p-3 transition-colors hover:border-brand-500 hover:bg-brand-50"
            >
              <div className="flex flex-col">
                <span className="font-semibold text-text-primary">{account.name}</span>
                <span className="tnum text-[12px] text-text-secondary">{account.external_id}</span>
              </div>
              <Button
                variant="primary"
                loading={bindAccountMutation.isPending && bindAccountMutation.variables === account.id}
                onClick={() => bindAccountMutation.mutate(account.id)}
              >
                <Check size={14} /> Bind
              </Button>
            </div>
          ))}
        </div>
      </Modal>
    </section>
  )
}

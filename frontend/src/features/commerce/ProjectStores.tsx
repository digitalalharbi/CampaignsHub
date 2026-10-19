import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Store } from 'lucide-react'
import { assignStore, listStoreProviders } from './api'
import { Button } from '@/components/ui/Button'
import { Card, CardTitle } from '@/components/ui/Card'
import { useUi } from '@/stores/ui'

/**
 * COMMERCE-ASSIGN-001 — the connected stores, on the page that manages a project's data sources.
 *
 * Ad accounts are chosen on this page's «Manage data sources»; a store was reachable only from the
 * integrations board, so a project page could not say that its shop was connected, or that a connected
 * shop belonged to nobody. This lists the stores that feed THIS project, and the ones nobody has
 * assigned yet with a one-press «use for this project».
 */
export function ProjectStores({ projectId }: { projectId: string }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const queryClient = useQueryClient()
  const stores = useQuery({ queryKey: ['commerce-stores'], queryFn: listStoreProviders, retry: false })
  const assign = useMutation({
    mutationFn: ({ storeId, project }: { storeId: string; project: string | null }) => assignStore(storeId, project),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['commerce-stores'] }),
  })

  const all = (stores.data ?? []).flatMap((p) => p.stores.map((s) => ({ ...s, provider: p })))
  const mine = all.filter((s) => s.project_id === projectId)
  const free = all.filter((s) => s.project_id === null)

  if (all.length === 0) return null

  return (
    <Card data-testid="project-stores">
      <CardTitle>
        <span className="inline-flex items-center gap-2"><Store size={15} /> {ar ? 'المتاجر المتصلة' : 'Connected stores'}</span>
      </CardTitle>

      {mine.length === 0 ? (
        <p className="mt-2 text-xs text-text-muted" data-testid="project-stores-none">
          {ar ? 'لا متجر يغذّي هذا المشروع بعد.' : 'No store feeds this project yet.'}
        </p>
      ) : (
        <ul className="mt-2 space-y-2">
          {mine.map((s) => (
            <li key={s.id} data-testid={`project-store-${s.id}`} className="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-border p-2 text-sm">
              <span className="min-w-0">
                <span className="block truncate font-semibold">{s.name}</span>
                <span className="tnum text-[11px] text-text-muted">
                  {ar ? 'المنتجات' : 'Products'} {s.counts.products} · {ar ? 'الطلبات' : 'Orders'} {s.counts.orders}
                  {s.last_run?.status === 'failed' && s.last_run.error ? ` · ${s.last_run.error}` : ''}
                </span>
              </span>
              <Button size="sm" variant="secondary" disabled={assign.isPending} onClick={() => assign.mutate({ storeId: s.id, project: null })}>
                {ar ? 'فصل' : 'Detach'}
              </Button>
            </li>
          ))}
        </ul>
      )}

      {free.length > 0 && (
        <div className="mt-3 space-y-2" data-testid="project-stores-free">
          <p className="text-xs font-semibold text-text-muted">{ar ? 'متاجر بلا مشروع' : 'Stores without a project'}</p>
          {free.map((s) => (
            <div key={s.id} className="flex flex-wrap items-center justify-between gap-2 text-sm">
              <span className="truncate">{s.name}</span>
              <Button size="sm" data-testid={`project-store-use-${s.id}`} disabled={assign.isPending} onClick={() => assign.mutate({ storeId: s.id, project: projectId })}>
                {ar ? 'استخدمه لهذا المشروع' : 'Use for this project'}
              </Button>
            </div>
          ))}
        </div>
      )}

      <p className="mt-3 text-[11px] text-text-muted">
        <Link to="/app/integrations" className="font-semibold text-brand-700 hover:underline">
          {ar ? 'إدارة المتاجر' : 'Manage stores'}
        </Link>
      </p>
    </Card>
  )
}

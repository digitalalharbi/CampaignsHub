import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Loader2, RefreshCw } from 'lucide-react'
import { listStoreProviders, syncStore, type StoreProvider, type StoreRow } from './api'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { ProviderMark } from '@/components/ProviderMark'
import { ProviderErrorNote } from '@/features/integrations/ProviderErrorNote'
import { QueryFailure } from '@/components/ui/QueryFailure'
import { Skeleton } from '@/components/ui/States'
import { toApiError } from '@/lib/api/client'
import { platformLabel } from '@/lib/platforms'
import { useUi } from '@/stores/ui'

/**
 * COMMERCE-001 / INTEG-PROJECT-LINK-STORES-GA4-001 — the stores THIS project reads.
 *
 * ## What this used to be, and why it is not any more
 *
 * It was the place a store was CONNECTED: a «belongs to client» dropdown, a connect button per platform
 * and, on every store, a «project» dropdown. Three places to decide what the project had already decided.
 * A store is linked from the project now — «المنصات» → سلة / زد → «ربط» → the platform's own page → the
 * store → «تم» — so this panel only shows what that journey produced: the project's own stores, their
 * figures, and «زامن الآن». With no store it draws nothing; the platform row above carries «ربط».
 *
 * It is asked for ONE project and renders only what the server returns for it. A store feeding another
 * project is not in the answer at all.
 */
function stamp(iso: string | null, ar: boolean): string {
  if (!iso) return ar ? 'لم تصل بيانات بعد' : 'No data yet'

  /*
   * `-u-nu-latn` because bare `ar-SA` yields Arabic-Indic digits, and every figure in this product is
   * Latin by a permanent requirement — see `lib/utils.ts`, which states the same rule.
   */
  return new Date(iso).toLocaleString(ar ? 'ar-SA-u-nu-latn' : 'en-GB', {
    year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit',
  })
}

function StoreCard({ store, provider, ar, onSync, syncing }: {
  store: StoreRow
  provider: StoreProvider
  ar: boolean
  onSync: () => void
  syncing: boolean
}) {
  const run = store.last_run?.status
  const inFlight = run === 'queued' || run === 'running'

  return (
    <li data-testid={`store-${store.id}`} data-platform={provider.key} className="min-w-0 rounded-2xl border border-border bg-surface p-3">
      <div className="flex min-w-0 items-center gap-3">
        <ProviderMark provider={provider.key} size={26} />
        <div className="min-w-0 flex-1">
          <p className="truncate text-sm font-bold text-text-primary">{store.name}</p>
          <p className="truncate text-[11px] text-text-muted">
            {platformLabel(provider.key, ar)}
            {store.domain && <> · <span dir="ltr">{store.domain}</span></>}
          </p>
        </div>
        {/* The store's own currency, or a stated absence: its money is withheld until the platform says. */}
        <span data-testid={`store-currency-${store.id}`} title={store.currency === null ? (ar ? 'العملة غير معروفة: المبالغ محجوبة' : 'Currency unknown: money withheld') : undefined}>
          <Badge tone={store.currency === null ? 'warning' : 'neutral'}><span className="tnum" dir="ltr">{store.currency ?? '?'}</span></Badge>
        </span>
        <Button size="sm" variant="secondary" onClick={onSync} disabled={syncing || inFlight} data-testid={`store-sync-${store.id}`}>
          {syncing || inFlight ? <Loader2 size={13} className="animate-spin" /> : <RefreshCw size={13} />}
          {ar ? 'زامن' : 'Sync'}
        </Button>
      </div>

      <dl className="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-xs text-text-secondary">
        <div><dt className="inline text-text-muted">{ar ? 'المنتجات' : 'Products'}: </dt><dd className="tnum inline font-semibold">{store.counts.products.toLocaleString('en-US')}</dd></div>
        <div><dt className="inline text-text-muted">{ar ? 'الطلبات' : 'Orders'}: </dt><dd className="tnum inline font-semibold">{store.counts.orders.toLocaleString('en-US')}</dd></div>
        <div>
          <dt className="inline text-text-muted">{ar ? 'السلات المتروكة' : 'Abandoned carts'}: </dt>
          <dd className="inline font-semibold">
            {/* Zid publishes no abandoned carts: a stated absence, never a zero that reads as a perfect checkout. */}
            {provider.supports_abandoned_carts
              ? <span className="tnum">{store.counts.abandoned_carts.toLocaleString('en-US')}</span>
              : <span data-testid="carts-unsupported" className="text-text-muted">—</span>}
          </dd>
        </div>
        <div><dt className="inline text-text-muted">{ar ? 'آخر مزامنة' : 'Last sync'}: </dt><dd className="tnum inline">{stamp(store.last_synced_at, ar)}</dd></div>
      </dl>

      {store.last_run?.status === 'no_data' && (
        <p data-testid="store-no-data" className="mt-1 text-[11px] text-text-muted">{ar ? 'لا بيانات للفترة.' : 'No data for the period.'}</p>
      )}
      {store.last_run?.status === 'partial_mapping' && store.last_run.error && (
        <p data-testid="store-partial" className="mt-1 text-[11px] text-warning">{store.last_run.error}</p>
      )}
      {store.last_run?.status === 'failed' && store.last_run.error && (
        <p data-testid="store-failed" className="mt-1 text-[11px] text-danger">{store.last_run.error}</p>
      )}
    </li>
  )
}

export function StoresPanel({ projectId }: { projectId: string }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const queryClient = useQueryClient()

  const query = useQuery({
    queryKey: ['commerce-stores', projectId],
    queryFn: () => listStoreProviders(projectId),
    // While a store is being read the list is asked again, so the counts arrive without a reload.
    refetchInterval: (q) =>
      q.state.data?.some((p) => p.state === 'syncing' || p.stores.some((s) => s.last_run?.status === 'queued' || s.last_run?.status === 'running'))
        ? 5000
        : false,
  })

  const sync = useMutation({
    mutationFn: syncStore,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['commerce-stores'] }),
  })

  if (query.isLoading) return <Skeleton className="h-24" />
  if (query.isError) {
    return (
      <QueryFailure error={query.error} ar={ar} testId="stores-failure" onRetry={() => void query.refetch()}
        fallbackTitle={ar ? 'تعذّر تحميل المتاجر.' : 'The stores could not be loaded.'} />
    )
  }

  const providers = (query.data ?? []).filter((p) => p.stores.length > 0)
  // Nothing linked: nothing to show. The platform rows above carry «ربط».
  if (providers.length === 0) return null

  const actionError = sync.isError ? toApiError(sync.error) : null

  return (
    <section className="flex min-w-0 flex-col gap-2" data-testid="stores-panel">
      <h2 className="text-sm font-bold text-text-primary" data-testid="stores-heading">{ar ? 'المتاجر' : 'Stores'}</h2>

      {actionError && (
        <p data-testid="store-action-error" role="alert" className="rounded-[12px] bg-[var(--warning-background)] px-4 py-3 text-sm text-warning">
          {actionError.message}
        </p>
      )}

      {providers.map((provider) => provider.state === 'error' && provider.connection_error ? (
        <div key={`error-${provider.key}`} className="text-sm">
          <ProviderErrorNote error={provider.connection_error} locale={ar ? 'ar' : 'en'} testId={`store-error-${provider.key}`} />
        </div>
      ) : null)}

      <ul className="grid grid-cols-1 gap-2 md:grid-cols-2">
        {providers.flatMap((provider) => provider.stores.map((store) => (
          <StoreCard
            key={store.id}
            store={store}
            provider={provider}
            ar={ar}
            syncing={sync.isPending && sync.variables === store.id}
            onSync={() => sync.mutate(store.id)}
          />
        )))}
      </ul>
    </section>
  )
}

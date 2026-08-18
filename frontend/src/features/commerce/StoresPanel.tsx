import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Loader2, RefreshCw, Store } from 'lucide-react'
import { listStoreProviders, startStoreOAuth, syncStore, type StoreProvider, type StoreRow, type StoreState } from './api'
import { listClientWorkspaces } from '@/features/projects/api'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card, CardDescription, CardTitle } from '@/components/ui/Card'
import { Skeleton } from '@/components/ui/States'
import { QueryFailure } from '@/components/ui/QueryFailure'
import { toApiError } from '@/lib/api/client'
import { useUi } from '@/stores/ui'

/**
 * COMMERCE-001 — the merchant's own Salla and Zid stores, on the page they already use.
 *
 * It mirrors the ad-platform panel deliberately. The six situations a store connection can be in are
 * the same six an ad account can be in, and a merchant who has learned that «بانتظار بيانات
 * الاعتماد» means «the platform operator has not finished» should not have to learn a second phrase
 * for the same fact one section further down.
 *
 * Two things are said here that the ad panel has no equivalent for, because they are true only of
 * stores:
 *
 * - **Zid publishes no abandoned carts.** The card says so instead of showing a zero, because «0 سلة
 *   متروكة» reads as a perfect checkout and would be a claim rather than a measurement.
 * - **Discovered and synced are different facts.** A store appears the moment the merchant authorises
 *   us; its orders appear after the first sync. A card that showed one date for both would report a
 *   brand-new connection as having no data «since» a time it had never been asked.
 */

const STATE_META: Record<StoreState, { tone: 'success' | 'warning' | 'danger' | 'neutral' | 'info'; ar: string; en: string }> = {
  connected: { tone: 'success', ar: 'متصل', en: 'Connected' },
  syncing: { tone: 'info', ar: 'جارٍ المزامنة', en: 'Syncing' },
  error: { tone: 'danger', ar: 'خطأ', en: 'Error' },
  awaiting_credentials: { tone: 'warning', ar: 'بانتظار بيانات الاعتماد', en: 'Awaiting credentials' },
  unavailable: { tone: 'neutral', ar: 'غير متاح حاليًا', en: 'Currently unavailable' },
  disconnected: { tone: 'neutral', ar: 'غير مربوط', en: 'Not connected' },
}

/** The two states a merchant cannot act on, and the same honest sentence for both. */
const NEEDS_OPERATOR: readonly StoreState[] = ['awaiting_credentials', 'unavailable']

const LABEL_AR: Record<string, string> = { salla: 'سلة', zid: 'زد' }

/** Latin digits in both languages, per the product's number rule. */
function stamp(iso: string | null, ar: boolean): string {
  if (!iso) return ar ? 'لم تصل بيانات بعد' : 'No data yet'

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
  return (
    <li data-testid={`store-${store.id}`} className="rounded-xl border border-border bg-surface-secondary p-3">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div className="min-w-0">
          <p className="truncate font-semibold text-text-primary">{store.name}</p>
          {store.domain && <p className="truncate text-[11px] text-text-muted" dir="ltr">{store.domain}</p>}
        </div>
        <Button size="sm" variant="secondary" onClick={onSync} disabled={syncing}>
          {syncing ? <Loader2 size={13} className="animate-spin" /> : <RefreshCw size={13} />}
          {ar ? 'زامن الآن' : 'Sync now'}
        </Button>
      </div>

      <dl className="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-xs text-text-secondary">
        <div><dt className="inline text-text-muted">{ar ? 'المنتجات' : 'Products'}: </dt><dd className="tnum inline font-semibold">{store.counts.products}</dd></div>
        <div><dt className="inline text-text-muted">{ar ? 'الطلبات' : 'Orders'}: </dt><dd className="tnum inline font-semibold">{store.counts.orders}</dd></div>
        <div>
          <dt className="inline text-text-muted">{ar ? 'السلات المتروكة' : 'Abandoned carts'}: </dt>
          <dd className="inline font-semibold">
            {provider.supports_abandoned_carts
              ? <span className="tnum">{store.counts.abandoned_carts}</span>
              /* Never a zero: this platform does not report them at all. */
              : <span data-testid="carts-unsupported" className="text-text-muted">{ar ? 'لا توفّرها المنصة' : 'Not offered by the platform'}</span>}
          </dd>
        </div>
      </dl>

      <p className="mt-2 text-[11px] text-text-muted">
        {ar ? 'آخر مزامنة' : 'Last sync'}: <span className="tnum">{stamp(store.last_synced_at, ar)}</span>
        {store.last_run?.status === 'no_data' && (
          /* Not an error and not amber: the shop simply had nothing in the window we asked about. */
          <span data-testid="store-no-data" className="block text-text-muted">
            {ar ? 'لا توجد بيانات للفترة المطلوبة.' : 'No data for the requested period.'}
          </span>
        )}
        {store.last_run?.status === 'partial_mapping' && store.last_run.error && (
          <span data-testid="store-partial" className="block text-warning">{store.last_run.error}</span>
        )}
        {store.last_run?.status === 'failed' && store.last_run.error && (
          <span data-testid="store-failed" className="block text-danger">{store.last_run.error}</span>
        )}
      </p>
    </li>
  )
}

export function StoresPanel() {
  const ar = useUi((s) => s.locale) === 'ar'
  const queryClient = useQueryClient()
  const [clientWorkspaceId, setClientWorkspaceId] = useState('')

  const query = useQuery({
    queryKey: ['commerce-stores'],
    queryFn: listStoreProviders,
    // A sync is a queued job, so the page that started it has no way of hearing that it finished.
    refetchInterval: (q) => (q.state.data?.some((p) => p.state === 'syncing') ? 5000 : false),
  })

  const clients = useQuery({ queryKey: ['client-workspaces'], queryFn: listClientWorkspaces })
  const clientChoices = clients.data ?? []

  const authorize = useMutation({
    mutationFn: (provider: string) => startStoreOAuth(provider, clientWorkspaceId || null),
    // A full navigation, not a router push: the destination is the platform's own consent screen.
    onSuccess: ({ authorization_url }) => { window.location.assign(authorization_url) },
  })

  const sync = useMutation({
    mutationFn: syncStore,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['commerce-stores'] }),
  })

  if (query.isLoading) return <Skeleton className="h-40" />
  if (query.isError) {
    return (
      <QueryFailure error={query.error} ar={ar} testId="stores-failure" onRetry={() => void query.refetch()}
        fallbackTitle={ar ? 'تعذّر تحميل المتاجر.' : 'The stores could not be loaded.'} />
    )
  }

  const providers = query.data ?? []
  const actionError = authorize.isError ? toApiError(authorize.error) : null

  return (
    <section className="space-y-4" data-testid="stores-panel">
      <div>
        <h2 className="font-[var(--font-heading)] text-lg font-extrabold">
          {ar ? 'المتاجر' : 'Stores'}
        </h2>
        <p className="mt-1 text-sm text-text-secondary">
          {ar
            ? 'اربط متجرك ليصل الطلبات والمنتجات والسلات المتروكة إلى الفانل والتقارير.'
            : 'Connect your store so orders, products and abandoned carts reach the funnel and reports.'}
        </p>
      </div>

      {actionError && (
        <p data-testid="store-action-error" role="alert" className="rounded-[12px] bg-[var(--warning-background)] px-4 py-3 text-sm text-warning">
          {actionError.message}
        </p>
      )}

      {clientChoices.length > 0 && (
        <label className="block text-sm">
          <span className="text-text-secondary">{ar ? 'المتجر يخصّ العميل' : 'This store belongs to'}</span>
          <select
            data-testid="store-client-workspace"
            value={clientWorkspaceId}
            onChange={(e) => setClientWorkspaceId(e.target.value)}
            className="mt-1 block w-full max-w-sm rounded-xl border border-border bg-surface px-3 py-2"
          >
            {/* A house store is a real answer, not a missing one. */}
            <option value="">{ar ? 'الوكالة نفسها' : 'The agency itself'}</option>
            {clientChoices.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </select>
        </label>
      )}

      <div className="grid gap-3 md:grid-cols-2">
        {providers.map((provider) => {
          const meta = STATE_META[provider.state]
          const needsOperator = NEEDS_OPERATOR.includes(provider.state)

          return (
            <Card key={provider.key} className="space-y-3">
              <div data-testid={`store-provider-${provider.key}`} className="flex items-start justify-between gap-2">
                <div className="flex items-center gap-2">
                  <Store size={16} className="text-text-muted" />
                  <CardTitle>{ar ? (LABEL_AR[provider.key] ?? provider.label) : provider.label}</CardTitle>
                </div>
                <Badge tone={meta.tone}>{ar ? meta.ar : meta.en}</Badge>
              </div>

              {needsOperator ? (
                /*
                 * One honest sentence, and no button. Which system key is missing is deliberately not
                 * said: a merchant cannot obtain a Client Secret for our app, and the shape of our
                 * registration is not theirs to be told.
                 */
                <p
                  data-testid={`store-needs-operator-${provider.key}`}
                  className="mt-1 text-sm leading-relaxed text-text-secondary"
                >
                  {ar
                    ? 'يتولّى مدير المنصة تهيئة هذا التكامل على مستوى النظام. لا يلزمك أي إجراء الآن.'
                    : 'The platform operator sets this integration up at system level. Nothing is needed from you yet.'}
                </p>
              ) : (
                <>
                  {provider.state === 'error' && provider.connection_error && (
                    <p data-testid={`store-error-${provider.key}`} className="text-sm text-danger">{provider.connection_error}</p>
                  )}

                  {provider.stores.length === 0 ? (
                    <>
                      <CardDescription>
                        {ar
                          ? 'اضغط «ربط المتجر» لتفويضنا من صفحة المنصة الرسمية.'
                          : 'Press connect to authorise us from the platform’s own consent screen.'}
                      </CardDescription>
                      <Button
                        data-testid={`connect-store-${provider.key}`}
                        onClick={() => authorize.mutate(provider.key)}
                        disabled={authorize.isPending}
                      >
                        {authorize.isPending ? <Loader2 size={14} className="animate-spin" /> : null}
                        {ar ? 'ربط المتجر' : 'Connect store'}
                      </Button>
                    </>
                  ) : (
                    <ul className="space-y-2">
                      {provider.stores.map((store) => (
                        <StoreCard
                          key={store.id}
                          store={store}
                          provider={provider}
                          ar={ar}
                          syncing={sync.isPending}
                          onSync={() => sync.mutate(store.id)}
                        />
                      ))}
                    </ul>
                  )}

                  {sync.isSuccess && (
                    <p data-testid="store-sync-queued" className="text-xs text-text-secondary">
                      {ar
                        ? 'أُرسل طلب المزامنة. ستتحدّث الأرقام بعد اكتمال القراءة من المتجر.'
                        : 'Sync requested. The figures update once the store has been read.'}
                    </p>
                  )}
                </>
              )}
            </Card>
          )
        })}
      </div>
    </section>
  )
}

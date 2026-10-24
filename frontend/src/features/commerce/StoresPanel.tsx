import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { History, Loader2, PlugZap, RefreshCw, Store, Unplug } from 'lucide-react'
import { assignStore, disconnectStore, listStoreProviders, startStoreOAuth, storeRuns, syncStore, type StoreProvider, type StoreRow, type StoreState } from './api'
import { listClientWorkspaces, listProjects, type Project } from '@/features/projects/api'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { ProviderErrorNote } from '@/features/integrations/ProviderErrorNote'
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

/** STORE-LIFECYCLE-001 — the store's own sync history, opened on demand (the last 20 runs, newest first). */
function StoreHistory({ storeId, ar }: { storeId: string; ar: boolean }) {
  const [open, setOpen] = useState(false)
  const runs = useQuery({ queryKey: ['commerce-store-runs', storeId], queryFn: () => storeRuns(storeId), enabled: open })

  return (
    <div className="mt-2">
      <button type="button" data-testid={`store-history-toggle-${storeId}`} aria-expanded={open} onClick={() => setOpen((v) => !v)} className="inline-flex items-center gap-1 text-xs font-semibold text-brand-600 hover:underline">
        <History size={12} /> {ar ? 'سجل المزامنة' : 'Sync history'}
      </button>
      {open && (
        <div data-testid={`store-history-${storeId}`} className="mt-1.5 text-[11px] text-text-secondary">
          {runs.isLoading && <Loader2 size={12} className="animate-spin" />}
          {runs.isError && <span className="text-danger">{toApiError(runs.error).message}</span>}
          {runs.data && runs.data.length === 0 && <span>{ar ? 'لا توجد عمليات مزامنة بعد.' : 'No sync runs yet.'}</span>}
          <ul className="space-y-1">
            {(runs.data ?? []).map((r) => (
              <li key={r.id} className="flex flex-wrap items-center gap-x-3 rounded-md bg-surface px-2 py-1">
                <span className="font-semibold">{r.status}</span>
                <span className="tnum">{stamp(r.finished_at ?? r.started_at, ar)}</span>
                <span className="tnum">{r.records} {ar ? 'سجل' : 'records'}</span>
                {r.error && <span className="text-danger">{r.error}</span>}
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  )
}

function StoreCard({ store, provider, ar, onSync, syncing, projects, onAssign, assigning }: {
  store: StoreRow
  provider: StoreProvider
  ar: boolean
  onSync: () => void
  syncing: boolean
  projects: Project[]
  onAssign: (projectId: string | null) => void
  assigning: boolean
}) {
  const run = store.last_run?.status
  const inFlight = run === 'queued' || run === 'running'

  return (
    <li data-testid={`store-${store.id}`} className="rounded-xl border border-border bg-surface-secondary p-3">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div className="min-w-0">
          <p className="truncate font-semibold text-text-primary">{store.name}</p>
          {store.domain && <p className="truncate text-[11px] text-text-muted" dir="ltr">{store.domain}</p>}
        </div>
        <Button size="sm" variant="secondary" onClick={onSync} disabled={syncing || store.project_id === null} title={store.project_id === null ? (ar ? 'اختر مشروعًا أولًا' : 'Choose a project first') : undefined}>
          {syncing ? <Loader2 size={13} className="animate-spin" /> : <RefreshCw size={13} />}
          {ar ? 'زامن الآن' : 'Sync now'}
        </Button>
      </div>

      {/*
        COMMERCE-ASSIGN-001 — WHICH PROJECT this store feeds, chosen here.

        A store with no project is read by nobody: «sync» answers 409 and nothing arrives. The card
        therefore asks, in words, rather than leaving a button that can only fail.
      */}
      <label className="mt-2 flex flex-wrap items-center gap-2 text-xs text-text-secondary">
        <span className="font-semibold text-text-muted">{ar ? 'المشروع' : 'Project'}:</span>
        <select
          data-testid={`store-project-${store.id}`}
          aria-label={ar ? 'مشروع المتجر' : 'Store project'}
          value={store.project_id ?? ''}
          disabled={assigning}
          onChange={(e) => onAssign(e.target.value === '' ? null : e.target.value)}
          className="min-w-0 max-w-full rounded-lg border border-border bg-surface px-2 py-1.5 text-xs text-text-primary"
        >
          <option value="">{ar ? 'اختر مشروعًا' : 'Choose a project'}</option>
          {projects.map((p) => (
            <option key={p.id} value={p.id}>{p.name}</option>
          ))}
        </select>
        {store.project_id === null && (
          <span data-testid={`store-needs-project-${store.id}`} className="text-warning">
            {ar ? 'لن تُقرأ بيانات المتجر قبل اختيار مشروع.' : 'No data is read until a project is chosen.'}
          </span>
        )}
        {inFlight && (
          <span data-testid={`store-reading-${store.id}`} className="inline-flex items-center gap-1 text-info">
            <Loader2 size={12} className="animate-spin" /> {ar ? 'تجري قراءة المتجر…' : 'Reading the store…'}
          </span>
        )}
      </label>

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

      {store.disconnected && (
        <p data-testid={`store-disconnected-${store.id}`} className="mt-2 rounded-lg bg-surface px-2 py-1.5 text-[11px] text-text-secondary">
          {ar ? 'المتجر مفصول: البيانات المقروءة محفوظة ولا تتم مزامنة جديدة حتى تعيد الربط.' : 'Disconnected: the data already read is kept and nothing new syncs until you reconnect.'}
        </p>
      )}
      <StoreHistory storeId={store.id} ar={ar} />

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
    refetchInterval: (q) =>
      q.state.data?.some((p) => p.state === 'syncing' || p.stores.some((s) => s.last_run?.status === 'queued' || s.last_run?.status === 'running'))
        ? 5000
        : false,
  })

  const projects = useQuery({ queryKey: ['projects'], queryFn: () => listProjects() })
  const projectChoices = projects.data ?? []
  const assign = useMutation({
    mutationFn: ({ storeId, projectId }: { storeId: string; projectId: string | null }) => assignStore(storeId, projectId),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['commerce-stores'] }),
  })

  const clients = useQuery({ queryKey: ['client-workspaces'], queryFn: listClientWorkspaces })
  const clientChoices = clients.data ?? []

  const authorize = useMutation({
    mutationFn: (provider: string) => startStoreOAuth(provider, clientWorkspaceId || null),
    // A full navigation, not a router push: the destination is the platform's own consent screen.
    onSuccess: ({ authorization_url }) => { window.location.assign(authorization_url) },
  })

  const disconnect = useMutation({
    mutationFn: (connectionId: string) => disconnectStore(connectionId),
    onSuccess: () => { setConfirming(null); void queryClient.invalidateQueries({ queryKey: ['commerce-stores'] }) },
  })
  const [confirming, setConfirming] = useState<string | null>(null)

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
  const failed = authorize.isError ? authorize.error : assign.isError ? assign.error : sync.isError ? sync.error : disconnect.isError ? disconnect.error : null
  const actionError = failed ? toApiError(failed) : null

  return (
    <section className="space-y-4" data-testid="stores-panel">
      <div>
        <h2 className="font-[var(--font-heading)] text-lg font-extrabold" data-testid="stores-heading">
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
            /*
              `store-card` and `data-platform` name this as THE card for this store — the one the
              integrations page used to draw twice. `integrations.spec.ts` counts them to hold
              INTEG-STORES-001: the two stores complete the eight sources, in their own section.
            */
            <Card key={provider.key} className="space-y-3" data-testid="store-card" data-platform={provider.key}>
              <div data-testid={`store-provider-${provider.key}`} className="flex items-start justify-between gap-2">
                <div className="flex min-w-0 items-center gap-2">
                  <Store size={16} className="shrink-0 text-text-muted" />
                  <CardTitle>{ar ? (LABEL_AR[provider.key] ?? provider.label) : provider.label}</CardTitle>
                </div>
                <Badge
                  tone={meta.tone}
                  data-testid={`store-state-${provider.key}`}
                  className="shrink-0 self-start whitespace-nowrap"
                >
                  {ar ? meta.ar : meta.en}
                </Badge>
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
                    <div className="text-sm">
                      <ProviderErrorNote
                        error={provider.connection_error}
                        locale={ar ? 'ar' : 'en'}
                        testId={`store-error-${provider.key}`}
                      />
                    </div>
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
                          projects={projectChoices}
                          assigning={assign.isPending}
                          onAssign={(projectId) => assign.mutate({ storeId: store.id, projectId })}
                        />
                      ))}
                    </ul>
                  )}

                  {/*
                    STORE-LIFECYCLE-001 — what can be done to the CONNECTION, not just to a store: reconnect (a fresh consent, which
                    also replaces the store when another one is chosen on the platform's screen) and disconnect (stop reading, erase
                    the token, keep everything already read).
                  */}
                  {provider.stores.length > 0 && provider.connection_id && (
                    <div data-testid={`store-actions-${provider.key}`} className="flex flex-wrap items-center gap-2 border-t border-border pt-3">
                      <Button size="sm" variant="secondary" data-testid={`reconnect-store-${provider.key}`} onClick={() => authorize.mutate(provider.key)} disabled={authorize.isPending}>
                        {authorize.isPending ? <Loader2 size={13} className="animate-spin" /> : <PlugZap size={13} />}
                        {provider.connection_status === 'revoked' ? (ar ? 'إعادة الربط' : 'Reconnect') : (ar ? 'إعادة التفويض / تغيير المتجر' : 'Reauthorise / change store')}
                      </Button>
                      {provider.connection_status !== 'revoked' && confirming !== provider.connection_id && (
                        <Button size="sm" variant="ghost" data-testid={`disconnect-store-${provider.key}`} onClick={() => setConfirming(provider.connection_id ?? null)}>
                          <Unplug size={13} /> {ar ? 'فصل الاتصال' : 'Disconnect'}
                        </Button>
                      )}
                      {confirming === provider.connection_id && (
                        <div role="alert" data-testid={`disconnect-confirm-${provider.key}`} className="w-full rounded-lg border border-warning/40 bg-[var(--warning-background)] p-3 text-xs text-text-primary">
                          <p className="mb-2">
                            {ar
                              ? 'سيتوقف قراءة هذا المتجر وتُمسح بيانات التفويض المخزّنة. تبقى الطلبات والمنتجات المقروءة وربط المشروع محفوظة، ويمكنك إعادة الربط لاحقًا. لإلغاء الصلاحية من جهة المنصة أزل التطبيق من لوحة تحكم متجرك أيضًا.'
                              : 'Reading this store stops and the stored authorisation is erased. Orders and products already read, and the project link, are kept; you can reconnect later. To withdraw access on the platform’s side, also remove the app in your store’s dashboard.'}
                          </p>
                          <div className="flex gap-2">
                            <Button size="sm" data-testid={`disconnect-yes-${provider.key}`} onClick={() => disconnect.mutate(provider.connection_id as string)} disabled={disconnect.isPending}>
                              {disconnect.isPending ? <Loader2 size={13} className="animate-spin" /> : null} {ar ? 'نعم، افصل' : 'Yes, disconnect'}
                            </Button>
                            <Button size="sm" variant="ghost" onClick={() => setConfirming(null)}>{ar ? 'إلغاء' : 'Cancel'}</Button>
                          </div>
                        </div>
                      )}
                    </div>
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

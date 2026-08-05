import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import { KeyRound, Loader2, Plug, RefreshCw } from 'lucide-react'
import {
  connectConnector, listConnectors, startPlatformOAuth, syncConnector,
  type Connector, type PlatformState,
} from './api'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card, CardDescription, CardTitle } from '@/components/ui/Card'
import { ErrorState, Skeleton } from '@/components/ui/States'
import { toApiError } from '@/lib/api/client'
import { useT } from '@/lib/i18n'
import { sortByPlatform } from '@/lib/platforms'
import { useUi } from '@/stores/ui'

/**
 * INTEG-UI-001 — the integrations page says which of four things is true, and what to do about it.
 *
 * The page used to carry one status and one button. That could not tell «لا يوجد تطبيق مسجّل لدى
 * المنصة» apart from «لم يربط أحد حسابه بعد» — the first needs an operator with keys and the second
 * needs the customer to press connect — so one of the two audiences was always given the wrong
 * instruction.
 *
 * Each state therefore answers a different question, and each carries only the action that state
 * actually admits:
 *
 * | State | What is true | What is offered |
 * | --- | --- | --- |
 * | Awaiting credentials | no app registered for this deployment | nothing to press; the missing keys are NAMED |
 * | Disconnected | configured, nobody has authorised | Connect → the platform's own consent screen |
 * | Syncing | a run is open right now | nothing; pressing again would do nothing twice |
 * | Connected | authorised, with accounts and a last-sync time | Sync now, Reconnect |
 * | Error | the platform stopped accepting us | Reconnect, with the platform's reason shown |
 */

/** Only these carry a state; the sandbox and analytics connectors keep the simpler status shape. */
const STATE_META: Record<PlatformState, { tone: 'success' | 'warning' | 'danger' | 'neutral' | 'info'; ar: string; en: string }> = {
  connected: { tone: 'success', ar: 'متصل', en: 'Connected' },
  syncing: { tone: 'info', ar: 'جارٍ المزامنة', en: 'Syncing' },
  error: { tone: 'danger', ar: 'خطأ', en: 'Error' },
  awaiting_credentials: { tone: 'warning', ar: 'بانتظار بيانات الاعتماد', en: 'Awaiting credentials' },
  disconnected: { tone: 'neutral', ar: 'غير مربوط', en: 'Not connected' },
}

const LEGACY_META: Record<Connector['status'], { tone: 'success' | 'warning' | 'danger' | 'neutral'; ar: string; en: string }> = {
  connected: { tone: 'success', ar: 'متصل', en: 'Connected' },
  awaiting_credentials: { tone: 'warning', ar: 'بانتظار المفاتيح', en: 'Awaiting credentials' },
  error: { tone: 'danger', ar: 'خطأ', en: 'Error' },
  disconnected: { tone: 'neutral', ar: 'غير متصل', en: 'Disconnected' },
}

/** Latin digits in both languages, per the product's number rule. */
function whenSynced(iso: string | null | undefined, ar: boolean): string {
  if (!iso) return ar ? 'لم تصل بيانات بعد' : 'No data yet'

  const date = new Date(iso)
  const stamp = date.toLocaleString(ar ? 'ar-SA-u-nu-latn' : 'en-GB', {
    year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit',
  })

  return `${ar ? 'آخر مزامنة' : 'Last sync'}: ${stamp}`
}

/** What the OAuth callback redirected back with — a human is reading this, so it is said in words. */
function outcomeMessage(outcome: string, reason: string | null, accounts: string | null, ar: boolean): { tone: 'ok' | 'bad'; text: string } {
  switch (outcome) {
    case 'connected':
      return {
        tone: 'ok',
        text: ar
          ? `تم الربط بنجاح. تم اكتشاف ${accounts ?? '0'} حساب إعلاني.`
          : `Connected. ${accounts ?? '0'} ad account(s) discovered.`,
      }
    case 'denied':
      return { tone: 'bad', text: ar ? 'أُلغي الربط من صفحة المنصة.' : 'The authorisation was cancelled on the platform.' }
    case 'invalid_state':
      return {
        tone: 'bad',
        text: ar ? 'انتهت صلاحية رابط الربط أو استُخدم من قبل. ابدأ من جديد.' : 'That authorisation link expired or was already used. Start again.',
      }
    default:
      return { tone: 'bad', text: reason ?? (ar ? 'تعذّر إكمال الربط.' : 'The connection could not be completed.') }
  }
}

/**
 * The six ad platforms, mounted where the customer already is.
 *
 * Exported as a PANEL rather than a page because `/app/integrations` is canonically the Connection
 * Centre (`ConnectionCenterPage`) — this component's own page was never routed, so everything it drew
 * was unreachable. Connecting an ad platform is tenant-level, not project-level, so the panel sits
 * above the project-scoped centre and is visible even before a project is chosen.
 */
export function AdPlatformsPanel() {
  const t = useT()
  const locale = useUi((s) => s.locale)
  const ar = locale === 'ar'
  const queryClient = useQueryClient()
  const [params, setParams] = useSearchParams()

  const query = useQuery({
    queryKey: ['connectors'],
    queryFn: listConnectors,
    /*
     * Poll while anything is mid-sync.
     *
     * A sync is a queued job, so the page that started it has no way of hearing that it finished. The
     * poll stops the moment nothing is running rather than ticking for ever behind an idle tab.
     */
    refetchInterval: (q) => (q.state.data?.some((c) => c.state === 'syncing') ? 5000 : false),
  })

  const connectMutation = useMutation({
    mutationFn: connectConnector,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['connectors'] }),
  })
  const syncMutation = useMutation({
    mutationFn: syncConnector,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['connectors'] }),
  })
  const authorizeMutation = useMutation({
    mutationFn: startPlatformOAuth,
    // A full navigation, not a router push: the destination is the platform's own consent screen.
    onSuccess: ({ authorization_url }) => { window.location.assign(authorization_url) },
  })

  const actionError = connectMutation.isError
    ? toApiError(connectMutation.error)
    : authorizeMutation.isError ? toApiError(authorizeMutation.error) : null

  const outcome = params.get('outcome')
  const banner = outcome
    ? outcomeMessage(outcome, params.get('reason'), params.get('accounts'), ar)
    : null

  /** The six come first and in the products order; everything else keeps its place behind them. */
  const connectors = sortByPlatform(query.data ?? [], (c) => c.key)

  return (
    <section className="space-y-4" data-testid="ad-platforms-panel">
      <div>
        <h2 className="font-[var(--font-heading)] text-lg font-extrabold">
          {ar ? 'المنصات الإعلانية' : 'Ad platforms'}
        </h2>
        <p className="mt-1 text-sm text-text-secondary">
          {ar
            ? 'اربط حساباتك الإعلانية لتصل الأرقام تلقائيًا إلى اللوحة والتقارير.'
            : 'Connect your ad accounts so figures reach the dashboard and reports on their own.'}
        </p>
      </div>

      {banner && (
        <div
          data-testid="integration-outcome"
          role="status"
          className={`flex items-start justify-between gap-3 rounded-[12px] px-4 py-3 text-sm ${
            banner.tone === 'ok'
              ? 'bg-[var(--positive-background)] text-success'
              : 'bg-[var(--warning-background)] text-warning'
          }`}
        >
          <span>{banner.text}</span>
          <button
            type="button"
            className="shrink-0 font-semibold underline"
            onClick={() => setParams({}, { replace: true })}
          >
            {ar ? 'إغلاق' : 'Dismiss'}
          </button>
        </div>
      )}

      {actionError && (
        <div role="alert" className="rounded-[12px] bg-[var(--warning-background)] px-4 py-3 text-sm text-warning">
          {actionError.message}
        </div>
      )}

      {query.isLoading ? (
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
          {Array.from({ length: 6 }).map((_, i) => (
            <Skeleton key={i} className="h-32 w-full" />
          ))}
        </div>
      ) : query.isError ? (
        <ErrorState title={t('error')} onRetry={() => query.refetch()} />
      ) : (
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
          {connectors.map((c) => (
            <ConnectorCard
              key={c.key}
              connector={c}
              ar={ar}
              t={t}
              onAuthorize={() => authorizeMutation.mutate(c.key)}
              onConnect={() => connectMutation.mutate(c.key)}
              onSync={() => syncMutation.mutate(c.key)}
              authorizing={authorizeMutation.isPending && authorizeMutation.variables === c.key}
              connecting={connectMutation.isPending && connectMutation.variables === c.key}
              syncing={syncMutation.isPending && syncMutation.variables === c.key}
            />
          ))}
        </div>
      )}
    </section>
  )
}

function ConnectorCard({
  connector: c, ar, t, onAuthorize, onConnect, onSync, authorizing, connecting, syncing,
}: {
  connector: Connector
  ar: boolean
  t: (key: string) => string
  onAuthorize: () => void
  onConnect: () => void
  onSync: () => void
  authorizing: boolean
  connecting: boolean
  syncing: boolean
}) {
  const state = c.state
  const meta = state ? STATE_META[state] : LEGACY_META[c.status]

  return (
    <Card>
      <div className="flex items-start justify-between gap-2">
        <CardTitle>{c.label}</CardTitle>
        <Badge tone={meta.tone} data-testid={`connector-state-${c.key}`}>
          {ar ? meta.ar : meta.en}
        </Badge>
      </div>

      <CardDescription>
        {state === 'awaiting_credentials' ? (
          /*
           * Naming what is missing is the whole difference between a status and an instruction.
           * Google Ads is the case that proves it: an OAuth client with no developer token
           * authenticates cleanly and is then refused by every call.
           */
          <span data-testid={`connector-missing-${c.key}`}>
            {ar ? 'ينقص: ' : 'Missing: '}
            <span className="font-mono text-xs">{(c.missing ?? []).join(', ')}</span>
          </span>
        ) : state === 'error' ? (
          <span className="text-danger" data-testid={`connector-error-${c.key}`}>{c.connection_error}</span>
        ) : state === 'connected' || state === 'syncing' ? (
          <span className="tnum" data-testid={`connector-synced-${c.key}`}>
            {ar ? `${c.accounts ?? 0} حساب إعلاني` : `${c.accounts ?? 0} ad account(s)`}
            {' · '}
            {whenSynced(c.data_last_synced_at, ar)}
          </span>
        ) : (
          <span>{ar ? 'جاهز للربط — لم يربط أحد حسابه بعد.' : 'Ready to connect — nobody has authorised it yet.'}</span>
        )}
      </CardDescription>

      <div className="mt-3 flex flex-wrap gap-2">
        {state === 'awaiting_credentials' ? (
          /* Nothing to press: no button here can produce a connection, so none is offered. */
          <span className="inline-flex items-center gap-1.5 text-xs text-text-muted">
            <KeyRound size={13} /> {ar ? 'يحتاج إعدادًا من مشغّل المنصة' : 'Needs setup by the platform operator'}
          </span>
        ) : state === 'syncing' ? (
          <span className="inline-flex items-center gap-1.5 text-xs text-text-muted">
            <Loader2 size={13} className="animate-spin" /> {ar ? 'المزامنة جارية الآن' : 'A sync is running now'}
          </span>
        ) : state === 'connected' ? (
          <>
            <Button variant="secondary" loading={syncing} onClick={onSync} data-testid={`connector-sync-${c.key}`}>
              <RefreshCw size={14} /> {t('sync')}
            </Button>
            <Button variant="ghost" loading={authorizing} onClick={onAuthorize}>
              {ar ? 'إعادة الربط' : 'Reconnect'}
            </Button>
          </>
        ) : state ? (
          <Button variant="secondary" loading={authorizing} onClick={onAuthorize} data-testid={`connector-connect-${c.key}`}>
            <Plug size={14} /> {state === 'error' ? (ar ? 'إعادة الربط' : 'Reconnect') : t('connect')}
          </Button>
        ) : c.status === 'connected' ? (
          <Button variant="secondary" loading={syncing} onClick={onSync}>
            <RefreshCw size={14} /> {t('sync')}
          </Button>
        ) : (
          <Button variant="secondary" loading={connecting} onClick={onConnect}>
            <Plug size={14} /> {t('connect')}
          </Button>
        )}
      </div>
    </Card>
  )
}

/**
 * The standalone page kept for the tests that drive this panel in isolation, and for any surface that
 * wants only the platforms. It renders exactly what the Connection Centre mounts — one implementation,
 * not two that drift.
 */
export function IntegrationsPage() {
  return <AdPlatformsPanel />
}

import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import { KeyRound, Loader2, Plug, RefreshCw } from 'lucide-react'
import {
  connectConnector, fetchResumableConnections, listConnectors, revokeConnection, startPlatformOAuth,
  syncConnector,
  type Connector, type PlatformState, type ResumableConnection,
} from './api'
import { ConnectionWizard } from './ConnectionWizard'
import { listClientWorkspaces } from '@/features/projects/api'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card, CardDescription, CardTitle } from '@/components/ui/Card'
import { ErrorState, Skeleton } from '@/components/ui/States'
import { toApiError } from '@/lib/api/client'
import { useT, type TranslationKey } from '@/lib/i18n'
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
 * | Unavailable | the platform operator took this provider out of service | nothing to press |
 * | Awaiting credentials | no app registered for this deployment | nothing to press |
 * | Disconnected | configured, nobody has authorised | Connect → the platform's own consent screen |
 * | Syncing | a run is open right now | nothing; pressing again would do nothing twice |
 * | Connected | authorised, with accounts and a last-sync time | Sync now, Reconnect |
 * | Error | the platform stopped accepting us | Reconnect, with the platform's reason shown |
 *
 * ## The two states this page must NOT explain (PROVCFG-001)
 *
 * `awaiting_credentials` and `unavailable` are both facts about the SYSTEM's configuration, and this
 * page used to name the missing credential — «ينقص: developer_token». That is an instruction for the
 * console at `/admin` addressed to the wrong reader: a customer cannot obtain a developer token for
 * our OAuth app, and the shape of our provider registration is not theirs to be told. Both states now
 * say the same true and sufficient thing — this needs the platform operator — and the named detail
 * lives on the one screen whose reader can act on it.
 */

/** Only these carry a state; the sandbox and analytics connectors keep the simpler status shape. */
const STATE_META: Record<PlatformState, { tone: 'success' | 'warning' | 'danger' | 'neutral' | 'info'; ar: string; en: string }> = {
  connected: { tone: 'success', ar: 'متصل', en: 'Connected' },
  syncing: { tone: 'info', ar: 'جارٍ المزامنة', en: 'Syncing' },
  error: { tone: 'danger', ar: 'خطأ', en: 'Error' },
  awaiting_credentials: { tone: 'warning', ar: 'بانتظار بيانات الاعتماد', en: 'Awaiting credentials' },
  unavailable: { tone: 'neutral', ar: 'غير متاح حاليًا', en: 'Currently unavailable' },
  disconnected: { tone: 'neutral', ar: 'غير مربوط', en: 'Not connected' },
}

/** The two states a customer cannot act on, and the same honest sentence for both. */
const NEEDS_OPERATOR: readonly PlatformState[] = ['awaiting_credentials', 'unavailable']

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
  /*
   * COMMAND-CENTER §26 — ending the authorisation.
   *
   * Three query keys, because a revoke changes three different screens at once: the connector cards,
   * the resumable-connection states behind them, and the account inventory, where every account
   * this connection discovered has just stopped being reachable. Invalidating only `connectors`
   * would leave the inventory showing «مرتبط بمشروع» over a source nothing can read.
   */
  const revokeMutation = useMutation({
    mutationFn: revokeConnection,
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['connectors'] }),
        queryClient.invalidateQueries({ queryKey: ['resumable-connections'] }),
        queryClient.invalidateQueries({ queryKey: ['integration-accounts'] }),
      ])
    },
  })
  /*
   * Which client the accounts about to be discovered belong to (CONNECT-001).
   *
   * Only asked when the workspace HAS clients, which is what distinguishes an agency from an
   * advertiser: an advertiser connecting its own accounts has exactly one answer and being asked for
   * it is friction. An agency has five, and connecting "to nothing in particular" is how an ad
   * account ends up attributed to whichever client somebody happened to be looking at.
   *
   * The empty string means «الوكالة نفسها» — a house account, which is a real answer and not a
   * missing one.
   */
  const clients = useQuery({ queryKey: ['client-workspaces'], queryFn: listClientWorkspaces })
  const [clientWorkspaceId, setClientWorkspaceId] = useState('')
  const clientChoices = clients.data ?? []

  const authorizeMutation = useMutation({
    mutationFn: (provider: string) => startPlatformOAuth(provider, clientWorkspaceId || null),
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

  /*
   * ORCH-100 §39 §41 — where each authorisation has actually got to.
   *
   * Derived server-side from the record, so an authorisation left mid-way days ago still knows it is
   * mid-way. This is what lets the page offer «أكمل اختيار الحسابات» instead of the connect button,
   * which would have asked for a second consent to an authorisation that never lapsed.
   */
  const wizardStates = useQuery({ queryKey: ['resumable-connections'], queryFn: fetchResumableConnections })
  const wizardByProvider = new Map<string, ResumableConnection>(
    (wizardStates.data?.connections ?? []).map((w) => [w.connection.provider, w]),
  )
  const unfinished = wizardStates.data?.resumable ?? []
  const [wizardConnectionId, setWizardConnectionId] = useState<string | null>(null)

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

      {unfinished.length > 0 && !wizardConnectionId && (
        /*
         * ORCH-100 §39 — somebody authorised and then closed the tab. The token is still valid and
         * the inventory is still there; asking them to authorise again would be a second consent for
         * an authorisation that never lapsed.
         */
        <div
          data-testid="unfinished-connection"
          role="status"
          className="flex flex-wrap items-center justify-between gap-3 rounded-[12px] bg-[var(--warning-background)] px-4 py-3 text-sm text-warning"
        >
          <span>
            {ar
              ? `لديك ربط غير مكتمل: ${unfinished[0].discovered} حسابًا متاحًا ولم يُربط أي حساب بمشروع بعد.`
              : `You have an unfinished connection: ${unfinished[0].discovered} accounts available, none connected to a project yet.`}
          </span>
          <Button size="sm" onClick={() => setWizardConnectionId(unfinished[0].connection.id)} data-testid="resume-connection">
            {ar ? 'أكمل اختيار الحسابات' : 'Finish selecting accounts'}
          </Button>
        </div>
      )}

      {actionError && (
        <div role="alert" className="rounded-[12px] bg-[var(--warning-background)] px-4 py-3 text-sm text-warning">
          {actionError.message}
        </div>
      )}

      {clientChoices.length > 0 && (
        <label
          data-testid="connect-client-workspace"
          className="flex flex-col gap-1.5 rounded-[12px] border border-border bg-surface p-3 sm:flex-row sm:items-center sm:gap-3"
        >
          <span className="text-sm font-bold text-text-primary">
            {ar ? 'الحسابات التي ستُكتشف تخص' : 'The accounts discovered belong to'}
          </span>
          <select
            value={clientWorkspaceId}
            onChange={(e) => setClientWorkspaceId(e.target.value)}
            className="rounded-xl border border-border bg-surface-secondary px-3 py-2 text-sm text-text-primary sm:max-w-xs"
          >
            <option value="">{ar ? 'مساحة العمل نفسها' : 'This workspace itself'}</option>
            {clientChoices.map((w) => (
              <option key={w.id} value={w.id}>{w.name}</option>
            ))}
          </select>
          <span className="text-xs text-text-muted sm:ms-auto">
            {ar
              ? 'يُحدَّد قبل الربط ويُحفَظ مع الموافقة — لا يمكن تغييره من صفحة العودة.'
              : 'Chosen before connecting and carried with the consent — the return page cannot change it.'}
          </span>
        </label>
      )}

      {query.isLoading ? (
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
          {Array.from({ length: 6 }).map((_, i) => (
            <Skeleton key={i} className="h-32 w-full" />
          ))}
        </div>
      ) : query.isError ? (
        <ErrorState error={query.error} title={t('error')} onRetry={() => query.refetch()} />
      ) : wizardConnectionId ? (
        <ConnectionWizard
          connectionId={wizardConnectionId}
          onClose={() => { setWizardConnectionId(null); void wizardStates.refetch(); void query.refetch() }}
        />
      ) : (
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
          {connectors.map((c) => (
            <ConnectorCard
              key={c.key}
              connector={c}
              wizard={wizardByProvider.get(c.key) ?? null}
              onOpenWizard={setWizardConnectionId}
              ar={ar}
              t={t}
              onAuthorize={() => authorizeMutation.mutate(c.key)}
              onConnect={() => connectMutation.mutate(c.key)}
              onSync={() => syncMutation.mutate(c.key)}
              authorizing={authorizeMutation.isPending && authorizeMutation.variables === c.key}
              connecting={connectMutation.isPending && connectMutation.variables === c.key}
              syncing={syncMutation.isPending && syncMutation.variables === c.key}
              onDisconnect={(connectionId) => revokeMutation.mutate(connectionId)}
              disconnecting={revokeMutation.isPending}
            />
          ))}
        </div>
      )}
    </section>
  )
}

function ConnectorCard({
  connector: c, wizard, onOpenWizard, ar, t, onAuthorize, onConnect, onSync, authorizing, connecting, syncing,
  onDisconnect, disconnecting,
}: {
  connector: Connector
  /*
   * ORCH-100 §41 — where this provider's authorisation has actually got to.
   *
   * `connected` used to be the end of the story, so an integration that had done nothing but
   * authorise showed «متصل · آخر مزامنة الآن». For the live Snapchat connection that was 309
   * discovered accounts, none of them chosen, and a sync button that would have synced nothing.
   */
  wizard: ResumableConnection | null
  onOpenWizard: (connectionId: string) => void
  ar: boolean
  t: (key: TranslationKey) => string
  onAuthorize: () => void
  onConnect: () => void
  onSync: () => void
  authorizing: boolean
  connecting: boolean
  syncing: boolean
  /*
   * COMMAND-CENTER §26 — ending the authorisation, which is NOT undoing a setting.
   *
   * Passed in rather than owned here so the whole panel invalidates its queries once, in one place,
   * after a revoke changes the state of every card that shares the connection.
   */
  onDisconnect: (connectionId: string) => void
  disconnecting: boolean
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
        {state && NEEDS_OPERATOR.includes(state) ? (
          /*
           * The same sentence for both, because from here they are the same fact: this platform is
           * not open yet and nothing on this page will open it. Which system credential is absent —
           * or why the operator suspended it — is deliberately not said; see the file's doc block.
           */
          <span data-testid={`connector-needs-operator-${c.key}`}>
            {ar
              ? 'هذه المنصة غير متاحة للربط حاليًا. يتولّى مشغّل المنصة تجهيزها.'
              : 'This platform is not open for connecting yet. The platform operator is setting it up.'}
          </span>
        ) : state === 'error' ? (
          <span className="text-danger" data-testid={`connector-error-${c.key}`}>{c.connection_error}</span>
        ) : wizard?.state === 'needs_selection' ? (
          /* Authorised, with an inventory nobody has chosen from yet. Available and connected are
           * different numbers and are shown as different numbers. */
          <span className="tnum" data-testid={`connector-needs-selection-${c.key}`}>
            {ar
              ? `تمت المصادقة · ${wizard.discovered} حسابًا متاحًا · لم يُربط أي حساب بمشروع بعد`
              : `Authorised · ${wizard.discovered} accounts available · none connected to a project yet`}
          </span>
        ) : wizard?.state === 'first_sync_pending' ? (
          <span className="tnum" data-testid={`connector-first-sync-${c.key}`}>
            {ar
              ? `${wizard.assigned} حسابًا مربوطًا · بانتظار أول مزامنة`
              : `${wizard.assigned} connected · first sync pending`}
          </span>
        ) : wizard?.health && wizard.health.connected > 0 ? (
          /*
           * RUNTIME-100 §31 — a SUMMARY of this connection's accounts, not one badge for all of them.
           *
           * Ten accounts behind one authorisation, nine syncing and one whose access was withdrawn,
           * rendered as a single green «متصل» — and that one account is the only fact on this card
           * anybody needed. The attention count is stated separately, and only when it is not zero,
           * so «everything is fine» stays a short sentence.
           */
          <span className="tnum" data-testid={`connector-health-${c.key}`}>
            {ar
              ? `${wizard.health.connected} مربوطًا · ${wizard.health.healthy} تعمل`
              : `${wizard.health.connected} connected · ${wizard.health.healthy} healthy`}
            {wizard.health.needs_attention > 0 && (
              <span className="text-warning">
                {ar
                  ? ` · ${wizard.health.needs_attention} يحتاج انتباه`
                  : ` · ${wizard.health.needs_attention} need attention`}
              </span>
            )}
            {' · '}
            {whenSynced(c.data_last_synced_at, ar)}
          </span>
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
        {state && NEEDS_OPERATOR.includes(state) ? (
          /* Nothing to press: no button here can produce a connection, so none is offered. */
          <span className="inline-flex items-center gap-1.5 text-xs text-text-muted">
            <KeyRound size={13} /> {ar ? 'يحتاج إعدادًا من مشغّل المنصة' : 'Needs setup by the platform operator'}
          </span>
        ) : state === 'syncing' ? (
          <span className="inline-flex items-center gap-1.5 text-xs text-text-muted">
            <Loader2 size={13} className="animate-spin" /> {ar ? 'المزامنة جارية الآن' : 'A sync is running now'}
          </span>
        ) : wizard?.state === 'needs_selection' ? (
          /* The one action this state admits. A sync button here would sync nothing, because no
           * account has been assigned to a project (ORCH-100 §14). */
          <Button onClick={() => onOpenWizard(wizard.connection.id)} data-testid={`connector-select-${c.key}`}>
            <Plug size={14} /> {ar ? 'اختيار الحسابات' : 'Select accounts'}
          </Button>
        ) : state === 'connected' ? (
          <>
            <Button variant="secondary" loading={syncing} onClick={onSync} data-testid={`connector-sync-${c.key}`}>
              <RefreshCw size={14} /> {t('sync')}
            </Button>
            <Button variant="ghost" loading={authorizing} onClick={onAuthorize}>
              {ar ? 'إعادة الربط' : 'Reconnect'}
            </Button>
            <DisconnectButton
              connectionId={wizard?.connection.id ?? null}
              accounts={wizard?.health?.connected ?? c.accounts ?? 0}
              ar={ar}
              busy={disconnecting}
              onConfirm={onDisconnect}
              testId={`connector-disconnect-${c.key}`}
            />
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

/**
 * COMMAND-CENTER §26 — «قطع الاتصال» sounds like undoing a setting. It is not.
 *
 * Revoking ends the authorisation AND disables every project binding that used any of this
 * connection's accounts, in every project — because leaving them active would leave projects
 * pointing at a source nothing can read, and a stale number reported as a current one is worse than
 * a missing one.
 *
 * So the confirmation states the count rather than asking «هل أنت متأكد؟». A confirmation that does
 * not say what is about to happen is a speed bump, not a safeguard — the customer clicks through it
 * having learnt nothing, which is exactly the case this guards.
 *
 * Two presses, no modal: the second press is the confirmation, it is labelled with the consequence,
 * and it reverts on blur so a stray click cannot leave the page armed.
 */
function DisconnectButton({
  connectionId, accounts, ar, busy, onConfirm, testId,
}: {
  connectionId: string | null
  accounts: number
  ar: boolean
  busy: boolean
  onConfirm: (connectionId: string) => void
  testId: string
}) {
  const [armed, setArmed] = useState(false)

  // No connection id means there is nothing to revoke — a legacy row that predates the wizard. No
  // button is offered rather than one that would fail.
  if (connectionId === null) return null

  return (
    <Button
      variant="ghost"
      loading={busy}
      onBlur={() => setArmed(false)}
      onClick={() => (armed ? onConfirm(connectionId) : setArmed(true))}
      data-testid={testId}
      className={armed ? 'text-danger' : undefined}
    >
      {armed
        ? (ar
            ? `تأكيد — سيتوقف ${accounts} حسابًا عن المزامنة`
            : `Confirm — ${accounts} account(s) stop syncing`)
        : (ar ? 'قطع الاتصال' : 'Disconnect')}
    </Button>
  )
}

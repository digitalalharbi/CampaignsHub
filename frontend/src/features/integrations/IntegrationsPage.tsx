import { useEffect, useRef, useState, type ReactNode } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import { KeyRound, Loader2, Plug, RefreshCw } from 'lucide-react'
import {
  connectConnector, fetchResumableConnections, listConnectors, revokeConnection, startPlatformOAuth,
  syncConnector,
  fetchConnectedEstate,
  type Connector, type PlatformState, type ResumableConnection,
} from './api'
import { ConnectionWizard } from './ConnectionWizard'
import { ConnectedEstate } from './ConnectedEstate'
import { ProviderErrorNote } from './ProviderErrorNote'
import { AccountsPanel } from './AccountsPanel'
import { StoresPanel } from '@/features/commerce/StoresPanel'
import { listClientWorkspaces } from '@/features/projects/api'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card, CardDescription, CardTitle } from '@/components/ui/Card'
import { ErrorState, Skeleton } from '@/components/ui/States'
import { toApiError } from '@/lib/api/client'
import { useT, type TranslationKey } from '@/lib/i18n'
import { accounts as accountsCounted, adAccounts, connectedAccounts, minutes as countedMinutes } from '@/lib/counted'
import { canonicalPlatform, sortByPlatform } from '@/lib/platforms'
import { platformColor } from '@/features/analytics/components'
import { useUi } from '@/stores/ui'
import { useProject } from '@/stores/project'

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
/**
 * INTEGRATION-DATASOURCE-WIZARD-001 §1 §14 — the connection's own word for what it is doing.
 *
 * `PlatformState` describes the PLATFORM — is there an app registered, is it out of service — and a
 * card needs both: «متصل» for a provider whose one account lost access is the sentence that stopped
 * anybody looking. Where the server has said what the connection is doing, that wins.
 */
const USER_STATE_META: Record<string, { tone: 'success' | 'warning' | 'danger' | 'neutral' | 'info'; ar: string; en: string }> = {
  ACCOUNT_SELECTION_REQUIRED: { tone: 'warning', ar: 'يحتاج اختيار حسابات', en: 'Needs account selection' },
  SYNCING: { tone: 'info', ar: 'جارٍ أول مزامنة', en: 'First sync running' },
  HEALTHY: { tone: 'success', ar: 'يعمل', en: 'Healthy' },
  /* Every selected account answered with nothing: not working, not broken. */
  NO_DATA: { tone: 'neutral', ar: 'لا توجد بيانات', en: 'No data' },
  ATTENTION_REQUIRED: { tone: 'warning', ar: 'يحتاج انتباه', en: 'Needs attention' },
  REAUTH_REQUIRED: { tone: 'danger', ar: 'يحتاج إعادة مصادقة', en: 'Needs reconnecting' },
  AUTH_REQUIRED: { tone: 'warning', ar: 'يحتاج مصادقة', en: 'Needs authentication' },
  NOT_CONNECTED: { tone: 'neutral', ar: 'غير مربوط', en: 'Not connected' },
}

const STATE_META: Record<PlatformState, { tone: 'success' | 'warning' | 'danger' | 'neutral' | 'info'; ar: string; en: string }> = {
  connected: { tone: 'success', ar: 'متصل', en: 'Connected' },
  syncing: { tone: 'info', ar: 'جارٍ المزامنة', en: 'Syncing' },
  error: { tone: 'danger', ar: 'خطأ', en: 'Error' },
  awaiting_credentials: { tone: 'warning', ar: 'بانتظار بيانات الاعتماد', en: 'Awaiting credentials' },
  unavailable: { tone: 'neutral', ar: 'غير متاح حاليًا', en: 'Currently unavailable' },
  disconnected: { tone: 'neutral', ar: 'غير مربوط', en: 'Not connected' },
  revoked: { tone: 'danger', ar: 'الربط ملغى', en: 'Authorisation revoked' },
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
/**
 * INTEGRATION-SYNC-VISIBILITY-001 — «and it will update itself again at…».
 *
 * Paired with `whenSynced` deliberately. On its own «آخر مزامنة» leaves the reader to guess whether
 * a quiet integration is broken or merely between runs, and the only way they had to find out was
 * «Sync now» — a real provider call made for reassurance.
 *
 * Returns null rather than a placeholder when the server states no next run. That is not «unknown»:
 * the connection is not going to sync, and the card already carries a sentence saying why. Printing
 * a time there would be the most confident kind of wrong.
 */
function whenNextSync(iso: string | null | undefined, ar: boolean): string | null {
  if (!iso) return null

  const at = new Date(iso)
  if (Number.isNaN(at.getTime())) return null

  const minutes = Math.round((at.getTime() - Date.now()) / 60_000)

  // Past-due happens between the boundary and the worker picking the job up. «Due now» is the truth
  // there; a negative countdown reads as a fault.
  if (minutes <= 0) return ar ? 'المزامنة التالية: الآن' : 'Next sync: due now'

  return ar
    ? `المزامنة التالية: بعد ${countedMinutes(minutes, 'ar')}`
    : `Next sync: in ${countedMinutes(minutes, 'en')}`
}

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
          ? `تم الربط بنجاح. تم اكتشاف ${adAccounts(Number(accounts ?? 0), 'ar')}.`
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
   * INTEG-STORES-001 · INTEGRATION-DATASOURCE-WIZARD-001 §1 — the two kinds are rendered apart, for
   * the reason the API returns them apart.
   *
   * A store carries no `ad_account_id` and none of the five ad-platform states. Rendered as an
   * ad-platform card those fields come out empty, and an empty ad-platform card reads as a platform
   * that tried to connect and failed — a worse statement than the stores being missing from this page,
   * which is the defect INTEG-STORES-001 exists to fix.
   *
   * The stores' own section is `StoresPanel`, below. It used to be BOTH: a read-only catalogue card
   * here that could only show a chip, and the panel underneath that could actually connect the same
   * store — so this page printed «المتاجر · Salla» twice, once as something a customer could act on
   * and once as something they could not, and the first one they reached was the one that did
   * nothing. That is the same duplication §1 removed from the ad platforms, and it is removed here
   * the same way: one card per source, and it is the card that can act.
   *
   * A row with no `kind` is treated as advertising: that is every row this endpoint returned before
   * stores were added, and defaulting the other way would empty the page against an older backend.
   */
  const advertising = connectors.filter((c) => (c.kind ?? 'advertising') === 'advertising')

  /*
   * ORCH-100 §39 §41 — where each authorisation has actually got to.
   *
   * Derived server-side from the record, so an authorisation left mid-way days ago still knows it is
   * mid-way. This is what lets the page offer «أكمل اختيار الحسابات» instead of the connect button,
   * which would have asked for a second consent to an authorisation that never lapsed.
   */
  const wizardStates = useQuery({ queryKey: ['resumable-connections'], queryFn: fetchResumableConnections })
  const wizardByProvider = new Map<string, ResumableConnection>(
    // Canonical on both sides: the Google card is keyed `google_ads` and its connection says `google`.
    (wizardStates.data?.connections ?? []).map((w) => [canonicalPlatform(w.connection.provider), w]),
  )
  const unfinished = wizardStates.data?.resumable ?? []

  /*
   * INTEGRATION-DATASOURCE-WIZARD-001 §2 — coming back from OAuth resumes the SAME wizard.
   *
   * The callback lands here with `?provider=…&outcome=connected`, and until now that produced a
   * green banner, a nudge, and a «Resume» button: three pieces of interface telling somebody who
   * had just authorised a provider that there was one more thing to do, without doing it. The
   * consent screen is the middle of a journey, not the end of one.
   *
   * Opened once per return — the ref, not the params — so dismissing the wizard does not reopen it
   * on the next render while the query string is still in the address bar.
   */
  const resumedFromCallback = useRef(false)

  useEffect(() => {
    if (resumedFromCallback.current) return
    if (outcome !== 'connected') return

    const provider = params.get('provider')
    const match = unfinished.find((u) => u.connection.provider === provider)
    if (!match) return

    resumedFromCallback.current = true
    setManagingProjectId(null)
    setWizardConnectionId(match.connection.id)
  }, [outcome, params, unfinished])
  const [wizardConnectionId, setWizardConnectionId] = useState<string | null>(null)
  /*
   * INTEGRATION-DATASOURCE-WIZARD-001 §8 — the wizard opens in one of two modes.
   *
   * Null is «connect»: choose accounts, choose a project, confirm. A project id is «manage»: the
   * same picker, opened on what this project already holds, saving a desired set the server diffs.
   * The mode is a property of how it was opened, not a step the reader chooses.
   */
  const [managingProjectId, setManagingProjectId] = useState<string | null>(null)
  const currentProjectId = useProject((s) => s.currentProjectId)

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
         *
         * ONE ROW PER UNFINISHED AUTHORISATION, each naming its platform.
         *
         * This rendered `unfinished[0]` alone and named no platform, so a workspace with four
         * authorisations waiting for a selection — the demo tenant's own state: Meta, Google,
         * Snapchat and TikTok — was told about one account, and «أكمل اختيار الحسابات» opened a
         * wizard for whichever provider happened to sort first. Seen on a local install against the
         * seeded data, beside a project page listing a bound account.
         *
         * The second clause was the worse half. «ولم يُربط أي حساب بمشروع بعد» is true of the
         * connection it describes and reads as a statement about the workspace, which had an account
         * bound and syncing. Per row it can say what it actually knows.
         */
        <div
          data-testid="unfinished-connection"
          role="status"
          className="space-y-2 rounded-[12px] bg-[var(--warning-background)] px-4 py-3 text-sm text-warning"
        >
          {unfinished.map((u) => {
            const platform = canonicalPlatform(u.connection.provider)
            /*
             * The connection's own name — «سناب شات», not «Snapchat Marketing API». The endpoint
             * sends both languages for exactly this, and the API label belongs on a card an operator
             * is configuring, not in a sentence.
             */
            const named = (ar ? u.connection.label_ar : u.connection.label) || u.connection.provider

            return (
              <div key={u.connection.id} className="flex flex-wrap items-center justify-between gap-3">
                <span>
                  {ar
                    ? `${named}: تمت المصادقة و${accountsCounted(u.discovered, 'ar')} متاح، ولم تختر أي حساب لهذا الربط بعد.`
                    : `${named}: authorised, ${accountsCounted(u.discovered, 'en')} available, and no account chosen for it yet.`}
                </span>
                <Button size="sm" onClick={() => setWizardConnectionId(u.connection.id)} data-testid={`resume-connection-${platform}`}>
                  {/* Short, and beside its own sentence: the platform is named there, once. */}
                  {ar ? 'أكمل اختيار الحسابات' : 'Finish selecting accounts'}
                </Button>
              </div>
            )
          })}
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
          /*
            WRAPS rather than compresses. Three items on one row with no wrap means the browser meets
            a width it cannot satisfy by taking every one of them down to min-content — at 768 this
            row read as a vertical stack of single words: «الحسابات / التي / ستُكتشف / تخص», with the
            hint beside it doing the same. Wrapping puts the hint on its own line instead.
          */
          className="flex flex-col gap-1.5 rounded-[12px] border border-border bg-surface p-3 sm:flex-row sm:flex-wrap sm:items-center sm:gap-3"
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
          manageProjectId={managingProjectId}
          onClose={() => {
            setWizardConnectionId(null)
            setManagingProjectId(null)
            void wizardStates.refetch()
            void query.refetch()
          }}
        />
      ) : (
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
          {advertising.map((c) => (
            <ConnectorCard
              key={c.key}
              connector={c}
              wizard={wizardByProvider.get(canonicalPlatform(c.key)) ?? null}
              onOpenWizard={(id) => { setManagingProjectId(null); setWizardConnectionId(id) }}
              onManageAccounts={currentProjectId === null ? undefined : (id) => {
                setManagingProjectId(currentProjectId)
                setWizardConnectionId(id)
              }}
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
  connector: c, wizard, onOpenWizard, onManageAccounts, ar, t, onAuthorize, onConnect, onSync, authorizing, connecting, syncing,
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
  /**
   * Reopen the picker on what THIS project holds — absent when no project is chosen.
   *
   * A binding belongs to a project, so «manage accounts» is meaningless from a tenant-wide view with
   * no project in hand; the button is not drawn rather than drawn and refusing.
   */
  onManageAccounts?: (connectionId: string) => void
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
  /*
   * The connection's state outranks the platform's, where there is one.
   *
   * A platform that is «connected» tells a reader the app is registered and somebody authorised it.
   * Whether the accounts behind that authorisation are syncing, one of them has lost access, or none
   * has been chosen yet is a different question, and it is the one somebody opens this page to ask.
   */
  const userMeta = wizard?.user_state ? USER_STATE_META[wizard.user_state] : undefined
  /*
   * A CARD MUST SAY ONE THING, and this one said two.
   *
   * `userMeta` is the wizard's view — «needs account selection», «needs authentication» — and it
   * used to win outright. `state` is the PLATFORM's: whether this install can connect the provider
   * at all. When the platform is one a customer cannot act on, the two disagree, and the card
   * rendered both: a chip reading «يحتاج اختيار حسابات» above a paragraph reading «هذه المنصة غير
   * متاحة للربط حاليًا».
   *
   * Seen on the real page, on four of the six ad platforms at once. The chip is the part a reader
   * scans, so the card was telling a customer to go and choose accounts on a platform that will not
   * open until an operator does something they cannot see or trigger.
   *
   * The platform's own state wins where it is one of those two. It is the more fundamental fact:
   * no amount of account-selecting reaches a provider whose credentials this install does not hold.
   */
  const platformBlocks = state !== undefined && state !== null && NEEDS_OPERATOR.includes(state)
  const meta = platformBlocks && state
    ? STATE_META[state]
    : userMeta ?? (state ? STATE_META[state] : LEGACY_META[c.status])

  /* The one runtime state in which re-authorising is the only thing that can succeed. */
  const needsReauth = wizard?.user_state === 'REAUTH_REQUIRED'

  return (
    /*
     * Named so a test can ask for PLATFORM CARDS rather than for every box on the page — see
     * `integrations.spec.ts`, which asserts the order of the six ad platforms and would otherwise
     * be reading the store panel and the account rows as well.
     */
    <Card data-testid="platform-card" data-platform={c.key} className="flex h-full flex-col">
      {/*
        INTEGRATION-DATASOURCE-WIZARD-001 §7 · TYPOGRAPHY-PRODUCT-POLISH-001 — the head of the card
        is «which source, and what is it doing», and both have to survive a long name on a phone.

        The name is allowed to wrap to two lines and no further; the chip never wraps and never
        shrinks, because a state chip that has been squeezed into two lines by «Snapchat Marketing
        API» stops reading as a chip. The coloured rail is the same identifier the project panel
        uses for the same platform — the card is recognised before it is read.
      */}
      <div className="flex items-start gap-3">
        <span
          aria-hidden
          className="mt-0.5 h-9 w-1.5 shrink-0 rounded-full"
          style={{ background: platformColor(canonicalPlatform(c.key)) }}
        />
        <div className="flex min-w-0 flex-1 flex-col gap-1.5 sm:flex-row sm:flex-wrap sm:items-start sm:justify-between sm:gap-2">
          {/*
            The name keeps its min-content width and the ROW wraps — it does not get crushed.

            This was `min-w-0`, so at the widths where the grid is multi-column but each column is
            narrow the unshrinkable chip took the row and the name collapsed to fourteen pixels and
            spilled out of the card: «…eta Marketing API» over three lines, unreadable. Measured in a
            browser at 768 and 1024 in Arabic with the navigation expanded — h3 clientWidth 14
            against scrollWidth 77, and 164/164 after — and correct at 390 and 1440, which are
            exactly the two widths every sweep in this suite checks. A tablet, or a window at half a
            screen, got the unreadable one.

            NOT covered by a test, deliberately rather than by omission. The gate's own integrations
            page does not reproduce it: probed at 640, 700, 768, 860, 960, 1024, 1100 and 1280 with
            the unfixed code in place, zero names were crushed, its cards being wider than this
            install's. A guard there would have passed against the defect — it did, on the first
            attempt — and a test that cannot fail is worse than none, because the next reader trusts
            it. The measurement above is the evidence.
          */}
          <div className="break-words">
            <CardTitle>{c.label}</CardTitle>
          </div>
          {/*
            `whitespace-nowrap` because a state chip broken over two lines stops reading as a chip,
            and `self-start` so that on a phone — where it sits under the name — it hugs its own text
            instead of stretching the width of the card.
          */}
          <Badge tone={meta.tone} data-testid={`connector-state-${c.key}`} className="shrink-0 self-start whitespace-nowrap">
            {ar ? meta.ar : meta.en}
          </Badge>
        </div>
      </div>

      <div className="mb-3 flex-1">
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
        ) : wizard?.reauth_reason === 'insights_not_authorised' ? (
          /*
           * META-INSIGHTS-GRANT-001 — «connected» is not «allowed to read insights», and this is the
           * card that kept saying otherwise.
           *
           * OAuth completes, the catalogue fills, and every sync is refused with «(#200) Ad account
           * owner has NOT grant ads_management or ads_read permission» — a grant the ad account's
           * owner makes in Business Manager, after consent. The page showed a green connection over
           * a sync that could never succeed, and offered «choose your accounts» as the next step.
           *
           * ABOVE the error branch, because `last_error` will be carrying that same refusal and the
           * raw provider sentence is the less useful of the two: this one names the scope and the
           * action, and the technical text stays available under «التفاصيل التقنية» either way.
           *
           * `ads_read` and no more: it is sufficient for Insights, `ads_management` is the write
           * scope over the same surface, and `business_management` is a different capability
           * altogether. Asking for more than the product needs is its own kind of wrong.
           */
          <span data-testid={`connector-insights-denied-${c.key}`}>
            {ar
              ? 'الحساب مرتبط، لكن صلاحية ads_read غير ممنوحة — أعد الربط.'
              : 'The account is connected, but ads_read has not been granted — reconnect.'}
          </span>
        ) : state === 'error' ? (
          <ProviderErrorNote error={c.connection_error} locale={ar ? 'ar' : 'en'} testId={`connector-error-${c.key}`} />
        ) : wizard?.state === 'needs_selection' ? (
          /* Authorised, with an inventory nobody has chosen from yet. Available and connected are
           * different numbers and are shown as different numbers. */
          <span className="tnum" data-testid={`connector-needs-selection-${c.key}`}>
            {ar
              ? `تمت المصادقة · ${accountsCounted(wizard.discovered, 'ar')} متاح · لم يُربط أي حساب بمشروع بعد`
              : `Authorised · ${accountsCounted(wizard.discovered, 'en')} available · none connected to a project yet`}
          </span>
        ) : wizard?.state === 'first_sync_pending' ? (
          <span className="tnum" data-testid={`connector-first-sync-${c.key}`}>
            {ar
              ? `${connectedAccounts(wizard.assigned, 'ar')} · بانتظار أول مزامنة`
              : `${connectedAccounts(wizard.assigned, 'en')} · first sync pending`}
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
              ? `${connectedAccounts(wizard.health.connected, 'ar')} · ${wizard.health.healthy} تعمل`
              : `${connectedAccounts(wizard.health.connected, 'en')} · ${wizard.health.healthy} healthy`}
            {/*
              CONNECTION HEALTH ≠ DATA HEALTH — «متصل · 1 تعمل» was true of Meta and useless.
              The authorisation worked, the sweep ran, and it stored nothing; the card said «تعمل»
              and the owner had no way to learn that from the screen. Stated in its own words and
              in a neutral tone, because nothing is broken: an ad account that genuinely has no
              campaigns is in exactly this state and there is nothing to repair.
            */}
            {(wizard.health.no_data ?? 0) > 0 && (
              <span className="text-text-secondary" data-testid={`connector-no-data-${c.key}`}>
                {ar
                  ? ` · ${wizard.health.no_data} متصل بلا بيانات`
                  : ` · ${wizard.health.no_data} connected, no data`}
              </span>
            )}
            {wizard.health.needs_attention > 0 && (
              <span className="text-warning">
                {ar
                  ? ` · ${wizard.health.needs_attention} يحتاج انتباه`
                  : ` · ${wizard.health.needs_attention} need attention`}
              </span>
            )}
            {' · '}
            {whenSynced(c.data_last_synced_at, ar)}
            {whenNextSync(c.next_sync_at, ar) !== null && (
              <span data-testid={`connector-next-sync-${c.key}`}>{' · '}{whenNextSync(c.next_sync_at, ar)}</span>
            )}
          </span>
        ) : state === 'connected' || state === 'syncing' ? (
          <span className="tnum" data-testid={`connector-synced-${c.key}`}>
            {adAccounts(c.accounts ?? 0, ar ? 'ar' : 'en')}
            {' · '}
            {whenSynced(c.data_last_synced_at, ar)}
            {whenNextSync(c.next_sync_at, ar) !== null && (
              <span data-testid={`connector-next-sync-${c.key}`}>{' · '}{whenNextSync(c.next_sync_at, ar)}</span>
            )}
          </span>
        ) : state === 'revoked' ? (
          <span data-testid={`connector-revoked-${c.key}`}>
            {ar ? 'أُلغي الربط — أعد الربط لاستئناف المزامنة.' : 'The authorisation was revoked — reconnect to resume syncing.'}
          </span>
        ) : (
          <span>{ar ? 'جاهز للربط — لم يربط أحد حسابه بعد.' : 'Ready to connect — nobody has authorised it yet.'}</span>
        )}
      </CardDescription>
      </div>

      {/*
        Actions sit on their own band, below a rule and pushed to the bottom of the card, so a row of
        six cards has ONE line of buttons across it however many lines of state each card carries.
        Before this the buttons floated wherever the description ended and the row read as ragged.
      */}
      <div className="mt-auto flex flex-wrap items-center gap-2 border-t border-border pt-3">
        {state && NEEDS_OPERATOR.includes(state) ? (
          /*
           * Nothing to press: no button here can produce a connection, so none is offered — and
           * nothing is SAID either, because the description above already says it.
           *
           * The card used to make this point three times: a chip, a paragraph, and this line. Six
           * platforms in that state put the same two sentences on the screen twelve times, which is
           * the «text-heavy» the grid is meant to be the cure for. The key icon carries the meaning
           * on its own beside a paragraph that has just explained it, and its accessible name is
           * where the words belong for a reader who cannot see it.
           */
          <span
            className="inline-flex items-center gap-1.5 text-xs text-text-muted"
            title={ar ? 'يحتاج إعدادًا من مشغّل المنصة' : 'Needs setup by the platform operator'}
          >
            <KeyRound size={13} aria-hidden />
            <span className="sr-only">{ar ? 'يحتاج إعدادًا من مشغّل المنصة' : 'Needs setup by the platform operator'}</span>
          </span>
        ) : needsReauth ? (
          /*
           * RE-AUTHORISATION OUTRANKS «a sync is running» — and it has to, or the card deadlocks.
           *
           * Seen on Production: the Meta card showed the chip «يحتاج إعادة مصادقة» and the sentence
           * «الحساب مرتبط، لكن صلاحية ads_read غير ممنوحة — أعد الربط» above an action area that
           * said «المزامنة جارية الآن» and offered NO button. The page told the reader to reconnect
           * and then hid the only control that could — while the sync it was reporting could never
           * succeed, because the authorisation it would use is the one being refused.
           *
           * `syncing` came first in this chain, so it won. It is the weaker claim of the two: a run
           * being open says what the pipeline is doing, and this says the pipeline cannot do it.
           * «Sync now» and «Manage accounts» stay hidden for the reason the branch below already
           * gives — both call the platform with the token being refused — so reconnecting is the
           * whole menu, with disconnect left as the way out.
           */
          <>
            <ReconnectButton
              ar={ar}
              busy={authorizing}
              urgent
              onConfirm={onAuthorize}
              testId={`connector-reconnect-${c.key}`}
            />
            <span className="ms-auto" />
            <DisconnectButton
              connectionId={wizard?.connection.id ?? null}
              accounts={wizard?.health?.connected ?? c.accounts ?? 0}
              ar={ar}
              busy={disconnecting}
              onConfirm={onDisconnect}
              testId={`connector-disconnect-${c.key}`}
            />
          </>
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
            {/*
              INTEGRATION-DATASOURCE-WIZARD-001 §9 — an authorisation that has lapsed admits ONE
              action, and it is not this page's other three.

              «Manage accounts» reads the catalogue with the stored token, «Sync now» calls the
              platform with it. Offering either against a token the platform has stopped accepting
              gives the reader two buttons that fail and one that works, and nothing saying which is
              which. So while the connection needs re-authorising, reconnecting is the whole menu.
            */}
            {!needsReauth && onManageAccounts && wizard && (
              <Button
                variant="secondary"
                onClick={() => onManageAccounts(wizard.connection.id)}
                data-testid={`connector-manage-${c.key}`}
              >
                <Plug size={14} /> {ar ? 'إدارة الحسابات' : 'Manage accounts'}
              </Button>
            )}
            {!needsReauth && (
              <Button variant="secondary" loading={syncing} onClick={onSync} data-testid={`connector-sync-${c.key}`}>
                <RefreshCw size={14} /> {t('sync')}
              </Button>
            )}
            <ReconnectButton
              ar={ar}
              busy={authorizing}
              urgent={needsReauth}
              onConfirm={onAuthorize}
              testId={`connector-reconnect-${c.key}`}
            />
            <span className="ms-auto" />
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
            <Plug size={14} /> {state === 'error' || state === 'revoked' ? (ar ? 'إعادة الربط' : 'Reconnect') : t('connect')}
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
 * `/integrations` — the ONE place sources are managed (INTEG-RUNTIME §3).
 *
 * ## What this replaced
 *
 * There were two pages and two runtimes. `ConnectionCenterPage` drew a grid of sixteen «connectors»
 * from `config/connectors.php`, in which every real platform was a `NullConnector` that could not
 * authorise, could not sync and existed only to be listed — and then mounted the REAL panels
 * underneath it. So the customer saw Meta twice: once as a card that could do nothing, once as a
 * platform they could actually connect. Six of those sixteen were providers this product does not
 * integrate with at all.
 *
 * §1 allows one runtime and §2 allows eight providers, so the grid, its config, its service, its
 * controller and its routes are gone, and what is left is what was always doing the work:
 *
 *  1. the six advertising platforms — connect, reconnect, sync, disconnect;
 *  2. the two commerce platforms, in their own shape;
 *  3. every account those connections reach, and which project each one feeds.
 *
 * Tenant-level, deliberately: an authorisation belongs to the tenant and is LENT to projects through
 * a binding, so this page is reachable before any project is chosen.
 */
export function IntegrationsPage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const queryClient = useQueryClient()

  /*
   * INTEGRATION-DATASOURCE-WIZARD-001 §17 — which lens opens, and why it is not a fixed answer.
   *
   * Both views are real and both are kept. «بالمنصة» answers «is our Snapchat authorisation
   * healthy» — an operator's question, and a good one. «بالعميل» answers «is هذا العميل complete»,
   * which is what the product is used for all day, so once anything IS connected that is the one
   * that opens.
   *
   * Before anything is connected it would open on an empty list under a heading about clients, with
   * the six things somebody actually came to press one click away and no sign of it. A page whose
   * first screen cannot be acted on is the failure this redesign started from, so with an empty
   * estate the provider grid opens instead — and the switch is not offered, because there is
   * nothing on the other side of it yet.
   */
  const estate = useQuery({ queryKey: ['connected-estate'], queryFn: fetchConnectedEstate, retry: false })
  const connected = (estate.data?.projects.length ?? 0) > 0
  const [chosenView, setChosenView] = useState<'clients' | 'platforms' | null>(null)
  const view = chosenView ?? (connected ? 'clients' : 'platforms')
  const setView = setChosenView
  const [manage, setManage] = useState<{ connectionId: string; projectId: string } | null>(null)
  const [syncing, setSyncing] = useState<string | null>(null)

  const authorize = useMutation({
    mutationFn: (provider: string) => startPlatformOAuth(provider),
    onSuccess: ({ authorization_url: url }) => { window.location.assign(url) },
  })

  const sync = useMutation({
    mutationFn: (key: string) => syncConnector(key),
    onSettled: () => {
      setSyncing(null)
      void queryClient.invalidateQueries({ queryKey: ['connected-estate'] })
      void queryClient.invalidateQueries({ queryKey: ['connectors'] })
    },
  })
  /*
   * INTEGRATION-DATASOURCE-WIZARD-001 §11 — the inventory is not the page.
   *
   * Every account every connection reaches was rendered underneath the source cards, permanently.
   * On the live Snapchat estate that is three hundred rows below the six cards somebody came for,
   * and the page's own question — «what is connected, and does anything need me?» — was answered
   * somewhere above a list nobody had asked for.
   *
   * It is still one click away, and it is the same panel: what changes is that a reader now asks
   * for it. Closed by default, and the control says how to get back to it.
   */
  const [inventoryOpen, setInventoryOpen] = useState(false)

  return (
    <div className="flex flex-col gap-4">
      {/*
        A two-way switch rather than tabs with their own URL: it is a lens on the same estate, not a
        different place, and a reader who lands here from a notification should not have to notice
        which one they are in before the page means anything.
      */}
      {connected && (
        <div className="flex flex-wrap items-center gap-1 self-start rounded-xl border border-border bg-surface p-1">
          <ViewButton active={view === 'clients'} onClick={() => setView('clients')} testId="estate-view-clients">
            {ar ? 'بالعميل' : 'By client'}
          </ViewButton>
          <ViewButton active={view === 'platforms'} onClick={() => setView('platforms')} testId="estate-view-platforms">
            {ar ? 'بالمنصة' : 'By platform'}
          </ViewButton>
        </div>
      )}

      {view === 'clients' ? (
        <ConnectedEstate
          onConnect={() => setView('platforms')}
          onManage={(provider, project) => {
            if (provider.connection_id !== null) {
              setManage({ connectionId: provider.connection_id, projectId: project.id })
            }
          }}
          onReauthorise={(provider) => authorize.mutate(provider.key)}
          onSync={(provider) => { setSyncing(provider.key); sync.mutate(provider.key) }}
          busySync={syncing}
        />
      ) : (
        <AdPlatformsPanel />
      )}

      {/*
        INTEG-STORES-001 — Salla and Zid keep their own journey. They are commerce connectors with a
        store, an order stream and no ad accounts at all; pushing them through an ad-account picker
        would ask a question they have no answer to.
      */}
      <StoresPanel />

      {manage !== null && (
        <ConnectionWizard
          connectionId={manage.connectionId}
          manageProjectId={manage.projectId}
          onClose={() => {
            setManage(null)
            void queryClient.invalidateQueries({ queryKey: ['connected-estate'] })
          }}
        />
      )}

      <section className="flex flex-col gap-3">
        <button
          type="button"
          data-testid="toggle-account-inventory"
          aria-expanded={inventoryOpen}
          onClick={() => setInventoryOpen((open) => !open)}
          className="inline-flex w-fit items-center gap-1.5 rounded-xl border border-border bg-surface px-3 py-2 text-sm font-semibold text-text-secondary transition-colors hover:bg-surface-hover hover:text-text-primary"
        >
          {inventoryOpen
            ? (ar ? 'إخفاء جميع الحسابات' : 'Hide all accounts')
            : (ar ? 'عرض جميع الحسابات المكتشفة' : 'Show every discovered account')}
        </button>

        {inventoryOpen && <AccountsPanel />}
      </section>
    </div>
  )
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
/**
 * INTEGRATION-DATASOURCE-WIZARD-001 §9 — reconnecting is a DIFFERENT question from choosing accounts.
 *
 * ## What readers were doing instead
 *
 * The three verbs on a connected card — manage, sync, reconnect — all sound like «bring this up to
 * date», and only one of them sends somebody to a provider consent screen. Support transcripts for
 * this product are full of one move: an account was created on the platform last week, it is not in
 * CampaignsHub, so the customer presses the button that says «reconnect», authorises again, and waits
 * for a discovery that would have taken one tick in the account picker.
 *
 * It costs them a round trip through the provider, and it costs the ones who are not the original
 * authoriser rather more — they cannot complete it at all, and now believe the product is broken.
 *
 * ## So the button says what it is for before it does it
 *
 * One press states the distinction — this refreshes the AUTHORISATION, the selected accounts are
 * untouched — and the second press goes. It is not a destructive-action confirm; nothing here is
 * lost either way. It is the sentence that belongs next to the verb, shown at the moment somebody is
 * about to act on the verb rather than in help text nobody opens.
 *
 * When the authorisation has actually lapsed there is nothing to disambiguate: it is the only action
 * the card offers, it is styled as the action to take, and it goes on the first press.
 */
function ReconnectButton({
  ar, busy, urgent, onConfirm, testId,
}: {
  ar: boolean
  busy: boolean
  /** The authorisation has lapsed: this is the only thing that can succeed, so no arming step. */
  urgent: boolean
  onConfirm: () => void
  testId: string
}) {
  const [armed, setArmed] = useState(false)

  if (urgent) {
    return (
      <Button variant="secondary" loading={busy} onClick={onConfirm} data-testid={testId}>
        <Plug size={14} /> {ar ? 'إعادة المصادقة' : 'Reconnect'}
      </Button>
    )
  }

  return (
    <Button
      variant="ghost"
      loading={busy}
      onBlur={() => setArmed(false)}
      onClick={() => (armed ? onConfirm() : setArmed(true))}
      data-testid={testId}
    >
      {armed
        ? (ar
            ? 'تأكيد — تجديد المصادقة فقط، ولن تتغيّر الحسابات المختارة'
            : 'Confirm — renews the authorisation only, your selected accounts stay')
        : (ar ? 'إعادة الربط' : 'Reconnect')}
    </Button>
  )
}

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
            ? `تأكيد — سيتوقف ${accountsCounted(accounts, 'ar')} عن المزامنة`
            : `Confirm — ${accounts} account(s) stop syncing`)
        : (ar ? 'قطع الاتصال' : 'Disconnect')}
    </Button>
  )
}

/** One lens of the estate. A pressed state, not a link: nothing about the address changes. */
function ViewButton({ active, onClick, testId, children }: {
  active: boolean
  onClick: () => void
  testId: string
  children: ReactNode
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-pressed={active}
      data-testid={testId}
      className={`rounded-lg px-3 py-1.5 text-sm font-semibold transition-colors ${
        active ? 'bg-surface-secondary text-text-primary' : 'text-text-secondary hover:text-text-primary'
      }`}
    >
      {children}
    </button>
  )
}

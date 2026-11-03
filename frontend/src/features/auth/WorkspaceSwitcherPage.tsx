import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, ArrowRight, Building2, LayoutDashboard, Loader2, Sparkles, Users } from 'lucide-react'
import { CampaignsHubLogo } from '@/components/brand/CampaignsHubLogo'
import { PORTAL_LABELS, fetchMemberships, switchMembership, type Membership, type PortalKey } from './memberships'
import { EmptyState, Skeleton } from '@/components/ui/States'
import { AccessRecovery } from './AccessRecovery'
import { clearStaleWorkspaceSelection } from './signOut'
import { SESSION_ENDED_PATH } from './postAuthDestination'
import { NoPermissionPanel, RetryingPanel, SessionEndedPanel, UnavailablePanel } from './WorkspaceFailure'
import { completeWorkspaceSwitch } from './workspaceSwitch'
import { failureKind } from '@/components/ui/QueryFailure'
import { toApiError } from '@/lib/api/client'
import { useUi } from '@/stores/ui'
import { clients as countedClients } from '@/lib/counted'
import { features } from '@/lib/features'

/**
 * `/switch` — the portal and workspace switcher (ADR 0002).
 *
 * Shown when a user belongs to more than one portal or workspace, which the previous single-tenant
 * model could not represent at all. The list comes from the server and contains only the caller's own
 * memberships, so this page cannot be used to discover anyone else's workspaces.
 *
 * Choosing one asks the server to switch; the server refuses anything the caller does not hold, and
 * the destination it returns is the one we navigate to — the browser never decides where a portal
 * lives.
 */

const PORTAL_ICON: Record<PortalKey, typeof LayoutDashboard> = {
  app: LayoutDashboard,
  agency: Users,
  influencers: Sparkles,
  portal: Building2,
}

/*
 * WORKSPACE-SWITCH-001 — bounded, because on a saturated host a request can sit for a minute before a proxy gives up.
 * The longest wait is therefore known: four attempts of 20 s with 2, 5 and 10 s between them. After that the page stops
 * asking and says so; a session failure and a refusal are not asked again at all, beyond one second look at a 401.
 */
const ATTEMPT_TIMEOUT_MS = 20_000
const RETRY_DELAYS_MS = [2_000, 5_000, 10_000]
const TOTAL_ATTEMPTS = RETRY_DELAYS_MS.length + 1

export function shouldRetryMembershipLoad(failureCount: number, error: unknown): boolean {
  const kind = failureKind(error)
  if (kind === 'permission') return false
  if (kind === 'session') return failureCount < 1

  return failureCount < RETRY_DELAYS_MS.length
}

export function WorkspaceSwitcherPage() {
  const navigate = useNavigate()
  const { locale } = useUi()
  const ar = locale === 'ar'
  const Arrow = ar ? ArrowLeft : ArrowRight

  /*
   * Set the moment this page is on its way out. Clearing the cache under a mounted query makes it fetch again, so without
   * this a page leaving on a dead session asks the dead session again, and again, for as long as the navigation takes.
   */
  const [leaving, setLeaving] = useState(false)

  const state = useQuery({
    enabled: !leaving,
    queryKey: ['memberships'],
    queryFn: () => fetchMemberships(undefined, { timeoutMs: ATTEMPT_TIMEOUT_MS }),
    retry: shouldRetryMembershipLoad,
    retryDelay: (attempt) => RETRY_DELAYS_MS[Math.min(attempt, RETRY_DELAYS_MS.length - 1)],
  })

  const queryClient = useQueryClient()

  const pick = useMutation({
    mutationFn: switchMembership,
    /*
     * SECURITY CLUSTER 5 — nothing the previous workspace left in the browser may be shown under the new one.
     *
     * The shared QueryClient holds tenant-level lists whose keys carry no tenant (projects, stores, settings), and the persisted
     * project id belongs to the workspace just left. An in-app navigation kept both, so the first render under the new workspace drew
     * the old one's cached lists and project data. Cleared, the persisted selection dropped, and the page reloaded so the in-memory
     * stores start empty too — the same full reload the invitation flow does after it switches membership.
     */
    onSuccess: (result) => {
      setLeaving(true)
      completeWorkspaceSwitch(queryClient, result.destination)
    },
    // A workspace just refused is one the list on screen was wrong about: read the list again rather than leave it there.
    onError: (failure) => {
      if (failureKind(failure) === 'permission') void state.refetch()
    },
  })

  /*
   * Memberships for a portal that is not being offered are not shown (INFL-OFF-001).
   *
   * The row is still real and still returned by the server — nothing has been revoked — but this
   * page's whole job is to offer a choice, and offering one that leads to a closed portal produces
   * a card that signs you into a redirect. Filtered HERE rather than in the counts below, because
   * "how many can I choose between?" and "how many do I hold?" are different questions: an operator
   * holding one advertiser membership and one influencer membership has exactly one choice, and
   * must be sent straight to it rather than shown a list with a single card in it.
   */
  const offered = (state.data?.memberships ?? []).filter(
    (m: Membership) => m.portal !== 'influencers' || features.influencersUgc,
  )

  /*
   * What stopped the load, once the page has finished asking. A failed background refresh beside a list that already
   * loaded does not take the list away — but a dead session or a refusal does: those names are no longer the reader's.
   */
  const loadFailure = state.isError ? failureKind(state.error) : null
  const sessionFailed = loadFailure === 'session' || (pick.isError && toApiError(pick.error).status === 401)
  // Latched: the cache is cleared on the way out, and that must not read as «the failure has gone away».
  const [sessionLeft, setSessionLeft] = useState(false)
  const sessionEnded = sessionFailed || sessionLeft
  const refused = loadFailure === 'permission'
  const data = sessionEnded || refused ? undefined : state.data
  const retrying = state.isLoading && state.failureCount > 0 && failureKind(state.failureReason) !== 'session'

  /*
   * Sign-in, with the way back kept. A hard navigation on purpose: the next person to sign in on this machine must not
   * inherit this cache, and the reloaded page asks the server who is here instead of trusting the store that sent us.
   */
  useEffect(() => {
    if (!sessionFailed || sessionLeft) return
    setSessionLeft(true)
    setLeaving(true)
    queryClient.clear()
    clearStaleWorkspaceSelection()
    window.location.assign(SESSION_ENDED_PATH)
  }, [sessionFailed, sessionLeft, queryClient])

  // Nothing to choose between: send them straight through rather than showing a list of one.
  useEffect(() => {
    if (!data) return
    if (offered.length === 1) {
      navigate(offered[0].landing_path, { replace: true })

      return
    }
    /*
     * No workspace, and the server knows where this person belongs instead: the owner's console, or their OWN
     * application's status. `/onboarding` and `/switch` are not such a place: the empty state below offers the first,
     * and the second would be this page again. Status and console are both outside this route, so nothing loops.
     */
    const elsewhere = data.destination
    if (offered.length === 0 && elsewhere && elsewhere !== '/onboarding' && elsewhere !== '/switch') {
      navigate(elsewhere, { replace: true })
    }
  }, [data, offered, navigate])

  const pickMessage = !pick.isError || sessionEnded ? null : (() => {
    const kind = failureKind(pick.error)
    if (kind === 'permission') return ar ? 'لم تعد هذه المساحة متاحة لحسابك. حدّثنا القائمة، فاختر من المساحات المعروضة.' : 'That workspace is no longer available to your account. The list has been refreshed; choose from what is shown.'
    if (toApiError(pick.error).status === 419) return ar ? 'انتهت صلاحية هذه الصفحة ولم يتغيّر شيء. حدّث الصفحة ثم اختر مساحتك.' : 'This page has expired and nothing changed. Refresh it, then choose your workspace.'
    if (toApiError(pick.error).kind === 'offline') return ar ? 'تعذّر الوصول إلى الخادم فلم يتغيّر شيء. تحقّق من اتصالك وحاول مرة أخرى.' : 'The server could not be reached, so nothing changed. Check your connection and try again.'

    return ar ? 'لم يكتمل الانتقال ولم يتغيّر شيء. حاول مرة أخرى بعد قليل.' : 'The switch did not complete and nothing changed. Please try again shortly.'
  })()

  return (
    <div className="min-h-screen bg-background px-5 py-10">
      <div className="mx-auto w-full max-w-2xl">
        <div className="flex items-center gap-2.5">
          {/* BRAND-CANONICAL-001 — the identity, not a megaphone in a gradient tile. */}
          <CampaignsHubLogo locale={ar ? 'ar' : 'en'} variant="compact" size="md" />
        </div>

        <h1 className="mt-6 font-heading text-[26px] font-extrabold leading-tight text-text-primary">
          {data && offered.length > 1
            ? (ar ? 'اختر مساحة العمل' : 'Choose a workspace')
            : (ar ? 'مساحات العمل' : 'Workspaces')}
        </h1>
        {/* Only once the server has said so: before that, «you belong to several» is a claim about data nobody has read. */}
        {data && offered.length > 1 && (
          <p data-testid="workspace-intro" className="mt-1.5 text-[14.5px] text-text-secondary">
            {ar
              ? 'أنت عضو في أكثر من مساحة. اختر التي تريد العمل فيها الآن، ويمكنك التبديل في أي وقت.'
              : 'You belong to more than one space. Pick the one to work in now; you can switch at any time.'}
          </p>
        )}

        {sessionEnded && <SessionEndedPanel signInPath={SESSION_ENDED_PATH} />}

        {!sessionEnded && state.isLoading && !retrying && (
          <div className="mt-6 grid gap-3" aria-busy="true">{[0, 1, 2].map((i) => <Skeleton key={i} className="h-20" />)}</div>
        )}

        {!sessionEnded && retrying && <RetryingPanel attempt={state.failureCount + 1} total={TOTAL_ATTEMPTS} />}

        {refused && (
          <>
            <NoPermissionPanel status={toApiError(state.error).status} />
            <AccessRecovery />
          </>
        )}

        {loadFailure !== null && !sessionEnded && !refused && !data && (
          <>
            <UnavailablePanel error={state.error} onRetry={() => void state.refetch()} retrying={state.isFetching} />
            {/* Retry is not an exit: if the failure persists, the person still needs a way off this page. */}
            <AccessRecovery />
          </>
        )}

        {/* A refresh failed beside a list that loaded. Choosing still asks the server, which is the only check that counts. */}
        {loadFailure !== null && !sessionEnded && !refused && data && (
          <p data-testid="workspace-stale-note" className="mt-4 rounded-xl border border-border bg-surface px-4 py-3 text-sm text-text-secondary">
            {ar ? 'تعذّر تحديث القائمة الآن، وهذه آخر نسخة وصلتنا.' : 'The list could not be refreshed just now; this is the last copy we received.'}
          </p>
        )}

        {pickMessage && (
          <p role="alert" data-testid="workspace-switch-error" className="mt-4 rounded-xl border border-border bg-surface px-4 py-3 text-sm text-text-primary">
            {pickMessage}
          </p>
        )}

        {data && offered.length === 0 && (data.destination === undefined || data.destination === '/onboarding' || data.destination === '/switch') && (
          <div className="mt-6" data-testid="no-workspace">
            <EmptyState
              title={ar ? 'لا توجد مساحة عمل بعد' : 'No workspace yet'}
              description={ar
                ? 'حسابك ليس عضوًا في أي مساحة. أكمل الإعداد أو اطلب دعوة من مسؤول المساحة.'
                : 'Your account is not a member of any space. Finish setting up, or ask a workspace admin to invite you.'}
            />
            {/*
              ACCESS-EXIT-001 — this was THE dead end.
              An empty state with no actions, reached by the one button a refusal offered. The session
              stayed valid, so returning to the site landed here again; the only escape was clearing
              site data by hand.
            */}
            <AccessRecovery memberships={[]} onboarding />
          </div>
        )}

        {data && offered.length > 1 && (
          <ul data-testid="workspace-switcher" className="mt-6 grid gap-3">
            {offered.map((m: Membership) => {
              const Icon = PORTAL_ICON[m.portal] ?? LayoutDashboard
              const label = PORTAL_LABELS[m.portal]
              return (
                <li key={m.id}>
                  <button
                    type="button"
                    data-testid={`switch-to-${m.portal}`}
                    disabled={pick.isPending}
                    onClick={() => pick.mutate(m.id)}
                    className={`flex w-full items-center gap-3 rounded-2xl border p-4 text-start transition-colors disabled:opacity-60 ${
                      m.is_active ? 'border-brand-500 bg-brand-primary-soft' : 'border-border bg-surface hover:border-brand-300 hover:bg-surface-hover'
                    }`}
                  >
                    <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-primary-soft text-brand-600">
                      <Icon size={19} />
                    </span>
                    <span className="min-w-0 flex-1">
                      <span className="block text-[15px] font-bold text-text-primary">
                        {m.tenant.name ?? (ar ? 'مساحة عمل' : 'Workspace')}
                      </span>
                      <span className="mt-0.5 block text-[12.5px] text-text-muted">
                        {ar ? label.ar : label.en} · {m.role}
                        {/* A membership confined to named clients says so here, so the row is never
                            mistaken for access to the whole workspace. */}
                        {m.client_scope_ids.length > 0 && (
                          <> · {ar
                            ? `${countedClients(m.client_scope_ids.length, 'ar')} محدّد`
                            : `${m.client_scope_ids.length} selected client${m.client_scope_ids.length === 1 ? '' : 's'}`}</>
                        )}
                      </span>
                    </span>
                    {pick.isPending && pick.variables === m.id ? <Loader2 size={16} className="animate-spin text-text-muted" /> : <Arrow size={16} className="shrink-0 text-text-muted" />}
                  </button>
                </li>
              )
            })}
          </ul>
        )}
      </div>
    </div>
  )
}

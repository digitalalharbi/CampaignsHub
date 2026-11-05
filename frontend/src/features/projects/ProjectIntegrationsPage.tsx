import { useEffect, useState } from 'react'
import { useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plug, RefreshCw, Unplug } from 'lucide-react'
import {
  detachBinding,
  setPrimaryAccount,
  listProjectBindings,
  listProjects,
  listProjectTasks,
  syncBinding,
} from './api'
import { PlatformIntegrationsPanel } from './PlatformIntegrationsPanel'
import { ProjectLinkFlow, type LinkConnection } from '@/features/integrations/ProjectLinkFlow'
import { fetchProjectLink } from '@/features/integrations/api'
import { useAuth } from '@/stores/auth'
import { ProjectStores } from '@/features/commerce/ProjectStores'
import { ProjectSyncHistory } from './ProjectSyncHistory'
import { PRIORITY_META, STATUS_META, priorityLabel, statusLabel } from '@/features/tasks/labels'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card, CardTitle } from '@/components/ui/Card'
import { EmptyState, Skeleton } from '@/components/ui/States'
import { PageIntro, DataFreshness, STALE_AFTER_HOURS } from '@/components/ui/PageIntro'
import { StatCard } from '@/components/ui/StatCard'
import { toApiError } from '@/lib/api/client'
import { listExternalCampaigns } from '@/features/campaigns/api'
import { fmtClock, fmtDateTime } from '@/lib/datetime'
import { useT } from '@/lib/i18n'
import { useUi } from '@/stores/ui'

/**
 * What a connection's state means to somebody reading a project's account list.
 *
 * An unrecognised state shows as ITSELF rather than as a guess or a blank — a state the product
 * does not know is worth seeing, which is the choice `projectRoleLabel` makes for the same reason.
 */
const CONNECTION_LABELS: Record<string, { ar: string; en: string }> = {
  error: { ar: 'خطأ في الاتصال', en: 'Connection error' },
  revoked: { ar: 'أُلغي الإذن', en: 'Access revoked' },
  expired: { ar: 'انتهت صلاحية الإذن', en: 'Authorisation expired' },
  disabled: { ar: 'الاتصال معطّل', en: 'Connection disabled' },
}

/**
 * States this page knows and deliberately does not restate — INTEGRATION-DATASOURCE-WIZARD-001 §12.
 *
 * `awaiting_credentials` is a fact about what keys this INSTALL holds. It is the platform operator's
 * number, nothing on a project page can change it, and on a customer's own project it reads as «none
 * of your platforms work» — which is why the platform panel above stopped saying it, and why
 * `integrations.spec.ts` asserts those words never reach this page. The project-level consequence is
 * already stated there: this platform is not feeding this project yet.
 *
 * Listed rather than simply absent, because the fallback shows an unknown state as itself: left out,
 * the row would print the raw `awaiting_credentials`, which is worse than both.
 */
const CONNECTION_UNSAID = new Set(['connected', 'awaiting_credentials'])

const CONNECTION_TONE: Record<string, 'danger' | 'warning'> = {
  error: 'danger',
  revoked: 'danger',
  expired: 'danger',
  disabled: 'warning',
}

function connectionLabel(status: string, ar: boolean): string {
  const label = CONNECTION_LABELS[status]

  return label ? (ar ? label.ar : label.en) : status
}

export function ProjectIntegrationsPage() {
  const t = useT()
  const lang = useUi((s) => s.locale)
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { projectId = '' } = useParams()

  /*
   * This page is mounted in both portals, so the source wizard it sends people to is the one in the
   * portal they are already standing in — an agency reader sent to `/app/integrations` would land
   * on a surface their session does not own.
   */
  const integrationsPath = window.location.pathname.startsWith('/agency')
    ? '/agency/integrations'
    : '/app/integrations'

  const projects = useQuery({ queryKey: ['projects'], queryFn: () => listProjects() })

  /*
   * INTEG-PROJECT-LINK-001 — the link journey starts HERE and ends here.
   *
   * «ربط مصدر» opens the one flow with this project fixed. The provider's consent screen returns to this
   * same page carrying only an outcome and which connection came back (`link`); whether that connection
   * was opened for THIS project by THIS person is the server's answer (`pending`), never the address's.
   */
  const mayLink = useAuth((s) => s.hasPermission)('integrations.connect')
  const [params, setParams] = useSearchParams()
  const [linking, setLinking] = useState<{ resume: LinkConnection | null } | null>(null)
  const [returned, setReturned] = useState<{ outcome: string; link: string | null } | null>(null)

  useEffect(() => {
    const outcome = params.get('outcome')
    if (outcome === null) return
    setReturned({ outcome, link: params.get('link') })
    // Read once, then clean the address: a reload must not replay a return.
    setParams((current) => {
      const next = new URLSearchParams(current)
      for (const key of ['outcome', 'link', 'provider', 'accounts', 'reason']) next.delete(key)
      return next
    }, { replace: true })
  }, [params, setParams])

  const resumed = useQuery({
    queryKey: ['project-link', projectId, returned?.link],
    queryFn: () => fetchProjectLink(projectId, returned!.link!),
    enabled: Boolean(projectId) && returned?.outcome === 'connected' && returned.link !== null,
    retry: false,
  })

  useEffect(() => {
    if (resumed.data === undefined || !resumed.data.pending) return
    const { connection } = resumed.data
    setLinking({ resume: { id: connection.id, provider: connection.provider, has_parent: connection.has_parent, projects: [] } })
    setReturned(null)
  }, [resumed.data])

  /** What the return says, in six words or fewer, with one way forward. */
  const returnNotice: { ar: string; en: string } | null = returned === null ? null
    : returned.outcome === 'denied' ? { ar: 'أُلغي التفويض. لم يُربط شيء.', en: 'Sign-in cancelled. Nothing linked.' }
    : returned.outcome === 'failed' ? { ar: 'تعذّر التفويض. لم يُربط شيء.', en: 'Sign-in failed. Nothing linked.' }
    : returned.outcome === 'invalid_state' ? { ar: 'انتهت صلاحية الرابط.', en: 'The link expired.' }
    : returned.outcome === 'connected' && (resumed.isError || resumed.data?.pending === false)
      ? { ar: 'هذا الربط ليس لهذا المشروع.', en: 'This link is not for this project.' }
      : null

  // Query key is namespaced by projectId → switching projects yields a fresh, isolated cache.
  const bindings = useQuery({
    queryKey: ['project', projectId, 'integrations'],
    queryFn: () => listProjectBindings(projectId),
    enabled: Boolean(projectId),
  })

  // Project-scoped tasks — also namespaced by projectId, proving multi-domain switch isolation.
  const tasks = useQuery({
    queryKey: ['project', projectId, 'tasks'],
    queryFn: () => listProjectTasks(projectId),
    enabled: Boolean(projectId),
  })

  // Campaigns discovered in this project (synced from bound accounts).
  const campaigns = useQuery({
    queryKey: ['project', projectId, 'campaigns-count'],
    queryFn: () => listExternalCampaigns(projectId),
    enabled: Boolean(projectId),
  })

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['project', projectId, 'integrations'] })

  const syncMutation = useMutation({
    mutationFn: (bindingId: string) => syncBinding(projectId, bindingId),
    onSuccess: invalidate,
  })
  /*
   * INTEGRATION-PRIMARY-ACCOUNT-001 — which project owns this account.
   *
   * The badge said «primary» and nothing could set it. An account bound to more than one project
   * files its rows under whichever binding the server ranks first — `is_primary` DESC, then oldest —
   * so without this an operator who confirmed the wrong one had to unbind and rebind, throwing the
   * history away to change a flag.
   */
  const primaryMutation = useMutation({
    mutationFn: (externalAccountId: string) => setPrimaryAccount(projectId, externalAccountId),
    onSuccess: () => {
      // This page's OWN key — `['project', projectId, 'integrations']` — via the helper, so the badge
      // moves without a reload. Getting this wrong is precisely the defect #519 was about.
      invalidate()
      // The wizard's bindings list and the tenant-wide inventory both print the owning project.
      void queryClient.invalidateQueries({ queryKey: ['project-bindings', projectId] })
      void queryClient.invalidateQueries({ queryKey: ['accounts'] })
    },
  })

  const detachMutation = useMutation({
    mutationFn: (bindingId: string) => detachBinding(projectId, bindingId),
    onSuccess: invalidate,
  })

  const bindError = detachMutation.isError ? toApiError(detachMutation.error) : null

  /*
   * A project that is not there gets ONE answer, not two.
   *
   * Opening `/projects/<gone>/integrations` drew «العنصر المطلوب غير موجود» from the platform panel
   * and then carried on rendering the whole working surface underneath it — «الحسابات المرتبطة بهذا
   * المشروع 0», «الحملات 0», an «إدارة مصادر البيانات» button — so the page said the item does not
   * exist and, more prominently, that it exists and has nothing bound to it. Those are different
   * claims and only one of them is true. Reproduced against a UUID no row can hold.
   *
   * The bindings query is the one to read: it is this page's own scope, and it answers 404 for a
   * project this workspace cannot reach — which is the same answer for «deleted» and «another
   * tenant's», deliberately, because telling a reader which would confirm the row exists.
   */
  const missing = bindings.isError && toApiError(bindings.error).status === 404
  /*
   * WHICH project, named in the head.
   *
   * The page decides where a client's money is read from and said only «تكاملات المشروع», leaving
   * the answer in a select box beside the title. Read from the list the selector already loads, so
   * no request is added for it; null until that lands, and the eyebrow is simply absent then rather
   * than flashing a placeholder that looks like a project called «—».
   */
  const projectName = projects.data?.find((p) => p.id === projectId)?.name ?? null

  const rows = bindings.data ?? []
  /*
   * The platforms BOUND to this project, which is what the counter beneath claims to count.
   *
   * It read them off the discovered CAMPAIGNS instead, so a project with an account bound and
   * nothing synced yet drew «المنصات 0» directly above a list naming that very platform — on the
   * one panel whose title is «الحسابات المرتبطة بهذا المشروع». Zero is not a smaller version of
   * one here; it is a different claim, and the false one is the one that looks like data.
   *
   * Bindings are the right source for the same reason the account count beside it uses them: both
   * answer «what is attached to this project», and only «الحملات» answers «what has arrived».
   */
  const providers = [...new Set(rows.map((b) => b.provider).filter(Boolean))]
  const lastSync = rows.map((b) => b.account?.last_synced_at).filter(Boolean).sort().at(-1) ?? null
  const discoveredCampaigns = campaigns.data?.length ?? 0

  if (missing) {
    return (
      <section className="space-y-4">
        <h1 className="font-[var(--font-heading)] text-3xl font-extrabold tracking-tight">{t('project_integrations')}</h1>
        <EmptyState
          title={lang === 'ar' ? 'هذا المشروع غير موجود' : 'This project does not exist'}
          description={lang === 'ar'
            ? 'ربما حُذف، أو أنه خارج مساحة العمل الحالية. اختر مشروعًا من القائمة الجانبية.'
            : 'It may have been deleted, or it belongs to another workspace. Pick a project from the menu.'}
        />
      </section>
    )
  }

  return (
    <section className="space-y-4">
      {/*
        PRODUCT-VISUAL-001 §4 §11 — the same head as every other surface, with the project named.
        
        A 3xl heading saying «تكاملات المشروع» answered «what page is this» and left «WHICH project»
        to a select box beside it. On a surface that decides where a client's money is read from,
        the project is the first fact, so it is the eyebrow — and the four counts that were a row of
        cards below the fold are the header's own KPIs.
      */}
      <PageIntro
        testid="project-integrations-intro"
        eyebrow={projectName ?? ''}
        title={t('project_integrations')}
        purpose={t('project_switch_hint')}
        meta={<DataFreshness lastSyncAt={lastSync} ar={lang === 'ar'} staleAfterHours={STALE_AFTER_HOURS} testid="project-integrations-freshness" />}
        actions={
          /* Project selector — switching reloads project-scoped data with no leakage. */
          <select
            value={projectId}
            onChange={(e) => navigate(`/projects/${e.target.value}/integrations`)}
            className="rounded-[9px] border border-border bg-surface-secondary px-3 py-2 text-sm"
            aria-label={t('projects')}
          >
            {projects.data?.map((p, i) => (
              <option key={p.id} value={p.id}>
                {p.name} #{i + 1}
              </option>
            ))}
          </select>
        }
        kpis={
          <>
            <StatCard label={t('bound_accounts')} value={rows.length.toLocaleString('en-US')} tone="brand" dot testid="project-kpi-accounts" />
            <StatCard label={lang === 'ar' ? 'المنصات' : 'Platforms'} value={providers.length.toLocaleString('en-US')} tone="info" dot testid="project-kpi-platforms" />
            <StatCard label={t('campaigns')} value={discoveredCampaigns.toLocaleString('en-US')} tone="neutral" dot testid="project-kpi-campaigns" />
            <StatCard
              label={t('last_updated')}
              value={lastSync ? fmtDateTime(lastSync) : '—'}
              tone={lastSync ? 'neutral' : 'warning'}
              dot
              testid="project-kpi-last-sync"
            />
          </>
        }
      />

      {bindError && <Alert severity={bindError.status === 409 ? 'warning' : 'danger'} title={bindError.message} />}

      {/* PROJINT-001: the six real ad platforms first — status, accounts, discovery, sync and errors. */}
      <PlatformIntegrationsPanel projectId={projectId} />

      <h2 className="pt-2 text-lg font-bold text-text-primary">
        {lang === 'ar' ? 'الربط التقني للحسابات' : 'Account bindings'}
      </h2>

      {/*
        INTEGRATION-DATASOURCE-WIZARD-001 §1 §11 — connecting a source happens in ONE place, and this
        is not it.

        What stood here was a «Connect Sandbox» button and, after it, every account the authorisation
        had discovered, each with its own «Bind» — a raw inventory on a page about one project. On the
        live Snapchat estate that is 309 rows a project user has no reason to read, and the sandbox
        button offered a demo connection on a customer's own project page.

        The action that belongs here is the one that takes them to the wizard, with this project in
        hand, where the same accounts are searched, paginated and chosen against the plan quota.
      */}
      {returnNotice !== null && (
        <div className="flex flex-wrap items-center justify-between gap-2 rounded-xl bg-[var(--warning-background)] px-4 py-3" role="status" data-testid="project-link-notice" data-outcome={returned?.outcome}>
          <p className="text-sm font-semibold text-text-primary">{lang === 'ar' ? returnNotice.ar : returnNotice.en}</p>
          {mayLink && (
            <Button size="sm" onClick={() => { setReturned(null); setLinking({ resume: null }) }} data-testid="project-link-retry">
              {lang === 'ar' ? 'إعادة المحاولة' : 'Try again'}
            </Button>
          )}
        </div>
      )}

      <div className="flex flex-wrap gap-2">
        {/* No permission, no entry point: the server refuses the same person with a 403. */}
        {mayLink && (
          <Button data-testid="project-link-source" onClick={() => setLinking({ resume: null })}>
            <Plug size={15} /> {lang === 'ar' ? 'ربط مصدر' : 'Link a source'}
          </Button>
        )}
        <Button
          variant="secondary"
          data-testid="project-manage-sources"
          onClick={() => navigate(integrationsPath)}
        >
          <Plug size={15} /> {lang === 'ar' ? 'إدارة مصادر البيانات' : 'Manage data sources'}
        </Button>
      </div>

      {linking !== null && projectId && (
        <ProjectLinkFlow
          projectId={projectId}
          resume={linking.resume}
          onClose={() => { setLinking(null); void invalidate() }}
          // The journey ends where it began: this page, with the new source in its list.
          onReturn={() => {
            setLinking(null)
            void invalidate()
            void queryClient.invalidateQueries({ queryKey: ['connection-hub'] })
            window.scrollTo?.({ top: 0 })
          }}
        />
      )}

      {/* COMMERCE-ASSIGN-001 — the stores that feed this project, and the ones nobody has assigned. */}
      {projectId && <ProjectStores projectId={projectId} />}

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
            <EmptyState
              title={t('no_bound_accounts')}
              description={lang === 'ar'
                ? 'اختر الحسابات من «إدارة مصادر البيانات» أعلاه — تُربط بهذا المشروع وتبدأ المزامنة.'
                : 'Choose accounts from «Manage data sources» above — they bind to this project and start syncing.'}
            />
          </div>
        ) : (
          <div className="mt-3 space-y-2">
            {/*
              No silent caps: this card asks for five, and a card that quietly stops at five is
              indistinguishable from a project that has five tasks.
            */}
            {(tasks.data?.total ?? 0) > (tasks.data?.tasks.length ?? 0) && (
              <p className="text-xs text-text-muted" data-testid="project-tasks-count">
                {`${tasks.data?.tasks.length} / ${tasks.data?.total}`}
              </p>
            )}
            {bindings.data?.map((b) => (
              <div key={b.id} className="flex flex-wrap items-center justify-between gap-3 rounded-[9px] border border-border p-3">
                <div className="min-w-0">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="min-w-0 break-words text-sm font-bold">{b.account?.name ?? '—'}</span>
                    <Badge tone="info">{b.purpose}</Badge>
                    {b.is_primary && <Badge tone="success">{t('primary')}</Badge>}
                    {!b.is_active && <Badge tone="danger">{t('disabled')}</Badge>}
                    {/*
                      The CONNECTION's health, which this row has always been sent and never shown.
                      `connection_status` arrives on every account — `ProjectIntegrationController`
                      reads it off the connection — and appeared nowhere in the frontend outside the
                      type declaration. So an account whose authorisation had been revoked looked
                      exactly like a working one: same name, same type, same id, and «آخر تحديث»
                      simply stopped moving. This is the page an operator opens to ask why the
                      numbers stopped, and it was the one page that could answer and did not.

                      Silent while healthy, like the «disabled» badge beside it: a list where every
                      row carries a green tick teaches a reader to stop reading the badges.
                    */}
                    {b.account?.connection_status != null && !CONNECTION_UNSAID.has(b.account.connection_status) && (
                      <Badge tone={CONNECTION_TONE[b.account.connection_status] ?? 'warning'}>
                        {connectionLabel(b.account.connection_status, lang === 'ar')}
                      </Badge>
                    )}
                  </div>
                  <span className="break-words text-xs text-text-muted">
                    {b.account?.account_type} · <span className="tnum">{b.account?.external_id}</span>
                    {b.account?.last_synced_at && (
                      <>
                        {' '}
                        · {t('last_updated')}:{' '}
                        <span className="tnum">{fmtClock(b.account.last_synced_at)}</span>
                      </>
                    )}
                  </span>
                </div>
                <div className="flex flex-wrap gap-2">
                  {/*
                    Offered only where it would change something: an account already primary here has
                    nothing to set, and an inactive binding is not a claim on anything.
                  */}
                  {!b.is_primary && b.is_active && b.account?.id && (
                    <Button
                      variant="ghost"
                      data-testid={`make-primary-${b.id}`}
                      loading={primaryMutation.isPending && primaryMutation.variables === b.account.id}
                      onClick={() => primaryMutation.mutate(b.account!.id)}
                    >
                      {lang === 'ar' ? 'اجعله الأساسي' : 'Make primary'}
                    </Button>
                  )}
                  <Button
                    variant="secondary"
                    loading={syncMutation.isPending && syncMutation.variables === b.id}
                    onClick={() => syncMutation.mutate(b.id)}
                  >
                    <RefreshCw size={14} /> {t('sync')}
                  </Button>
                  {/*
                    Detaching is reversible and the control says so, because nothing else on the page
                    does. It stops this account feeding this project and keeps the binding row, so
                    the months of figures it has already attributed stay this project's and
                    re-selecting the account from «إدارة مصادر البيانات» brings it back to the same
                    row — the same thing deselecting has always done, which is the point.
                  */}
                  <Button
                    variant="ghost"
                    title={lang === 'ar'
                      ? 'يتوقف هذا الحساب عن تغذية المشروع. الأرقام السابقة تبقى، ويمكن اختياره مجددًا من إدارة مصادر البيانات.'
                      : 'This account stops feeding the project. Its past figures stay, and it can be selected again from Manage data sources.'}
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

      <ProjectSyncHistory projectId={projectId} />

      {/* Project-scoped tasks — these change when the active project changes (no leakage). */}
      <Card>
        <CardTitle>{t('project_tasks')}</CardTitle>
        {tasks.isLoading ? (
          <div className="mt-3 space-y-2">
            <Skeleton className="h-8 w-full" />
          </div>
        ) : (tasks.data?.tasks.length ?? 0) === 0 ? (
          <div className="mt-3">
            <EmptyState title={t('no_project_tasks')} />
          </div>
        ) : (
          <div className="mt-3 space-y-2">
            {tasks.data?.tasks.map((task) => (
              <div key={task.id} className="flex items-center justify-between rounded-[9px] border border-border p-2.5">
                <span className="text-sm font-semibold">{task.title}</span>
                {/*
                  The task's state and urgency by NAME. This printed the columns — «in_progress» and
                  «high» in the middle of an Arabic page — while the tasks page itself has named both
                  all along. Importing its map rather than writing a second one is what keeps the two
                  surfaces calling `waiting_client` the same thing.
                */}
                <div className="flex items-center gap-2">
                  <span className={`whitespace-nowrap text-[11px] font-bold ${PRIORITY_META[task.priority]?.tone ?? 'text-text-secondary'}`}>
                    ● {priorityLabel(task.priority, lang === 'ar')}
                  </span>
                  <span className={`whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-semibold ${
                    task.is_overdue ? 'bg-danger/15 text-danger' : STATUS_META[task.status]?.tone ?? 'bg-surface-hover text-text-secondary'
                  }`}>
                    {statusLabel(task.status, lang === 'ar')}
                  </span>
                </div>
              </div>
            ))}
          </div>
        )}
      </Card>
    </section>
  )
}

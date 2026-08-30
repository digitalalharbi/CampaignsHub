import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, ArrowLeft, ArrowRight, Check, Loader2, Search } from 'lucide-react'
import {
  applyAccountSelection, confirmAccountSelection, fetchConnectionHierarchy, fetchDiscoveredAccounts,
  fetchPlanUsage, listProjectBindings, refreshDiscoveredAccounts,
  type DiscoveredAccount, type ProjectBinding,
} from './api'
import { createProject, listClientWorkspaces, listProjects } from '@/features/projects/api'
import { Button } from '@/components/ui/Button'
import { ErrorState, Skeleton } from '@/components/ui/States'
import { toApiError } from '@/lib/api/client'
import { Refusal } from '@/lib/api/errors'
import { useUi } from '@/stores/ui'
import { accounts as countedAccounts } from '@/lib/counted'

/**
 * ORCH-100 §3 §5 §6 — choosing what to connect, after the provider has said what exists.
 *
 * ## The step this product did not have
 *
 * A customer authorised Snapchat and 309 ad accounts arrived. The interface showed a green card and
 * a sync button; there was no way to say which of the 309 should feed which project, and nothing
 * asked. Everything below exists to make that choice possible, and to make it explicit — no account
 * is connected because it was discovered.
 *
 * ## Why the organisation step is real and not decoration
 *
 * 309 accounts in one list is not a list, it is a wall. Snapchat groups them under organisations and
 * an agency already thinks in those terms, so the wizard asks for the organisation first and then
 * shows only its accounts. Providers whose adapters return no parent skip this step entirely rather
 * than showing an invented one — `hasParent` comes from the capability model, not from the layout.
 *
 * ## Nothing is written until Confirm
 *
 * Selections live in this component; bindings are created by the confirm, one transactional call per
 * account, and the review step shows the plan impact BEFORE any of them is made. A customer should
 * not discover their cap by hitting it.
 */

type Step = 'parent' | 'accounts' | 'project' | 'review' | 'done'

/**
 * An account's state in one phrase — RUNTIME-100 §31.
 *
 * Each of these names a different next action, which is the only reason to tell them apart. «مربوط
 * بمشروع» said all of them at once, so an account whose access had been withdrawn and one syncing
 * happily read identically.
 */
function accountHealthLabel(health: DiscoveredAccount['health'], ar: boolean): string {
  switch (health) {
    case 'healthy': return ar ? 'تعمل' : 'Healthy'
    case 'pending_first_sync': return ar ? 'بانتظار أول مزامنة' : 'First sync pending'
    case 'delayed': return ar ? 'متأخرة' : 'Delayed'
    case 'failed': return ar ? 'فشلت آخر محاولة' : 'Last attempt failed'
    case 'access_lost': return ar ? 'تعذّر الوصول' : 'Access lost'
    case 'revoked': return ar ? 'الربط ملغى' : 'Connection revoked'
    // Assigned but the server did not say — an older response, not a state worth inventing a word for.
    default: return ar ? 'مربوط بمشروع' : 'Connected'
  }
}

/** Colour carries the same distinction the words do, so the state survives a glance. */
function accountHealthTone(health: DiscoveredAccount['health']): string {
  switch (health) {
    case 'healthy': return 'text-success'
    case 'delayed': return 'text-warning'
    case 'failed':
    case 'access_lost':
    case 'revoked': return 'text-danger'
    default: return 'text-text-muted'
  }
}

interface Props {
  connectionId: string
  onClose: () => void
  /**
   * INTEGRATION-DATASOURCE-WIZARD-001 §8 — «Manage accounts» opens the SAME wizard.
   *
   * With a project id the wizard is managing an existing connection rather than making one: it skips
   * the project step, opens with the currently bound accounts ticked, and saves the DESIRED SET so
   * the server can derive the diff. It asks for no new authorisation — the token that discovered
   * these accounts is the token that binds them, and re-consenting to an authorisation that is still
   * valid is exactly the cost this removes.
   */
  manageProjectId?: string | null
}

export function ConnectionWizard({ connectionId, onClose, manageProjectId = null }: Props) {
  const managing = manageProjectId !== null
  const ar = useUi((s) => s.locale) === 'ar'
  const queryClient = useQueryClient()

  const hierarchy = useQuery({
    queryKey: ['connection-hierarchy', connectionId],
    queryFn: () => fetchConnectionHierarchy(connectionId),
  })

  const hasParent = hierarchy.data?.has_parent ?? false

  const [parent, setParent] = useState<string | null>(null)
  const [selected, setSelected] = useState<Set<string>>(new Set())
  const [projectId, setProjectId] = useState<string | null>(null)
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [step, setStep] = useState<Step | null>(null)

  // The opening step is the connection's own state, so a wizard reopened days later resumes where it
  // stopped instead of starting again (ORCH-100 §39).
  const current: Step = step ?? (hasParent ? 'parent' : 'accounts')

  /*
   * What this project holds right now — read ONCE, before the catalogue's first page.
   *
   * A selection seeded from whatever page happens to be on screen would silently unbind every
   * account the reader never scrolled to, because the save sends the desired SET.
   */
  const bindings = useQuery({
    queryKey: ['project-bindings', manageProjectId],
    queryFn: () => listProjectBindings(manageProjectId!),
    enabled: managing,
  })

  const boundIds = useMemo(
    () => (bindings.data ?? [])
      .filter((b: ProjectBinding) => b.is_active && b.account !== null)
      .map((b: ProjectBinding) => b.account!.id),
    [bindings.data],
  )

  const [seeded, setSeeded] = useState(false)

  useEffect(() => {
    if (!managing || seeded || bindings.data === undefined) return
    setSelected(new Set(boundIds))
    setSeeded(true)
  }, [managing, seeded, bindings.data, boundIds])

  const accounts = useQuery({
    queryKey: ['discovered-accounts', connectionId, parent, search, page],
    queryFn: () => fetchDiscoveredAccounts(connectionId, { parent, q: search || null, page, perPage: 25 }),
    enabled: current === 'accounts' || current === 'review',
  })

  const usage = useQuery({ queryKey: ['plan-usage'], queryFn: fetchPlanUsage })
  const projects = useQuery({ queryKey: ['projects'], queryFn: () => listProjects() })
  const workspaces = useQuery({ queryKey: ['client-workspaces'], queryFn: listClientWorkspaces })

  const adAccounts = usage.data?.ad_accounts
  const afterConfirm = (adAccounts?.used ?? 0) + selected.size
  const overLimit = adAccounts?.limit != null && afterConfirm > adAccounts.limit

  /*
   * PROJECT-CREATE-WORKSPACE-001 — a real project, named by the person creating it.
   *
   * What was here instead:
   *
   * ```ts
   * const workspaceId = workspaces.data?.[0]?.id
   * if (!workspaceId) throw new Error('لا توجد مساحة عميل.')
   * createProject({ client_workspace_id: workspaceId, name: 'المشروع الأول' })
   * ```
   *
   * Three defects in four lines. `[0]` is nothing for an advertiser — which is what production hit —
   * and the WRONG client for an agency. «المشروع الأول» is a name the customer never chose, on the
   * container everything they connect will be filed under. And the thrown `Error` reached the screen
   * as «حدث خطأ غير متوقع.», because a locally thrown error has no envelope for `toApiError` to read.
   *
   * The client workspace is now omitted and RESOLVED server-side; when the answer is genuinely the
   * customer's — an agency choosing between clients — the server answers 422 naming the field and
   * this asks, which is why `needsWorkspace` is driven by that response rather than guessed at here.
   */
  const [diff, setDiff] = useState<{ added: string[]; unchanged: string[]; removed: string[] } | null>(null)

  const save = useMutation({
    mutationFn: () => applyAccountSelection({
      projectId: manageProjectId!,
      connectionId,
      externalAccountIds: [...selected],
    }),
    onSuccess: (diff) => {
      setDiff(diff)
      queryClient.invalidateQueries({ queryKey: ['project-bindings', manageProjectId] })
      queryClient.invalidateQueries({ queryKey: ['discovered-accounts', connectionId] })
      queryClient.invalidateQueries({ queryKey: ['resumable-connections'] })
      queryClient.invalidateQueries({ queryKey: ['connectors'] })
    },
  })

  const [projectName, setProjectName] = useState('')
  const [workspaceId, setWorkspaceId] = useState<string | null>(null)
  const [needsWorkspace, setNeedsWorkspace] = useState(false)

  const createAndUse = useMutation({
    mutationFn: async () => {
      const name = projectName.trim()
      if (name === '') {
        throw new Refusal(ar ? 'اكتب اسم المشروع.' : 'Enter a project name.', 'name')
      }
      if (needsWorkspace && !workspaceId) {
        throw new Refusal(ar ? 'اختر العميل.' : 'Choose a client.', 'client_workspace_id')
      }
      return createProject({ name, ...(workspaceId ? { client_workspace_id: workspaceId } : {}) })
    },
    onSuccess: async (project) => {
      // Straight back into the same wizard with the connection, the organisation and every account
      // selection intact — and no second OAuth (ORCH-100 §5).
      setProjectId(project.id)
      await queryClient.invalidateQueries({ queryKey: ['projects'] })
      await queryClient.invalidateQueries({ queryKey: ['plan-usage'] })
      setStep('review')
    },
    onError: (error) => {
      // The server asking which client is a QUESTION, not a failure. Reveal the choice rather than
      // showing a validation message about a field the form does not have.
      if (toApiError(error).errors?.client_workspace_id) setNeedsWorkspace(true)
    },
  })

  const confirm = useMutation({
    mutationFn: async () => {
      if (!projectId) {
        throw new Refusal(ar ? 'اختر مشروعًا أولًا.' : 'Choose a project first.', 'project')
      }
      /*
       * RUNTIME-100 §10 — ONE call for the whole selection.
       *
       * This was a `for` loop of single binds. Each request was individually correct and the
       * sequence was not a decision anybody made: ten accounts against a plan with room for eight
       * left eight connected and two refused, with nothing to undo. The server now applies the
       * selection in one transaction and starts the first sync once it commits.
       */
      return confirmAccountSelection({
        projectId,
        connectionId,
        externalAccountIds: [...selected],
      })
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['connection-hierarchy', connectionId] })
      await queryClient.invalidateQueries({ queryKey: ['discovered-accounts', connectionId] })
      await queryClient.invalidateQueries({ queryKey: ['plan-usage'] })
      await queryClient.invalidateQueries({ queryKey: ['connectors'] })
      await queryClient.invalidateQueries({ queryKey: ['connection-states'] })
      setStep('done')
    },
  })

  /* RUNTIME-100 §5 — fix the names with the token we already hold, no second consent. */
  const refresh = useMutation({
    mutationFn: () => refreshDiscoveredAccounts(connectionId),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['connection-hierarchy', connectionId] })
      await queryClient.invalidateQueries({ queryKey: ['discovered-accounts', connectionId] })
    },
  })

  if (hierarchy.isLoading) return <Skeleton className="h-64" />
  if (hierarchy.isError || !hierarchy.data) {
    return (
      <ErrorState
        title={ar ? 'تعذّر قراءة حسابات هذا الاتصال.' : 'Could not read this connection.'}
        error={hierarchy.error}
        onRetry={() => void hierarchy.refetch()}
        ar={ar}
      />
    )
  }

  const h = hierarchy.data
  const providerLabel = ar ? h.connection.label_ar : h.connection.label
  // Named rather than inferred from the rendering, so the prompt to refresh is a statement about the
  // DATA — «12 organisations have no name» — and not a side effect of a fallback in the markup.
  const missingParentNames = h.parents.filter((p) => !p.name).length

  return (
    <div data-testid="connection-wizard" className="flex flex-col gap-4">
      <header className="flex flex-wrap items-baseline justify-between gap-2">
        <h2 className="text-lg font-semibold">
          {ar ? `ربط ${providerLabel}` : `Connect ${providerLabel}`}
        </h2>
        {/* Available and connected are different numbers and are shown as different numbers. */}
        <p className="text-sm text-text-muted" data-testid="wizard-inventory">
          {ar
            ? `${countedAccounts(h.discovered_count, 'ar')} متاح · ${h.assigned_count} مربوط`
            : `${h.discovered_count} available · ${h.assigned_count} connected`}
        </p>
      </header>

      {current === 'parent' && (
        <section className="flex flex-col gap-3" data-testid="wizard-step-parent">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <p className="text-sm text-text-muted">
              {ar
                ? `اختر ${h.parent_label?.labelAr ?? 'المؤسسة'} لعرض حساباتها.`
                : `Choose an ${h.parent_label?.label ?? 'organization'} to see its accounts.`}
            </p>
            {/*
              RUNTIME-100 §5 — the way out of a list of identifiers.

              The live Snapchat connection was catalogued before the product recorded organisation
              names, so its parents have ids and nothing else. This re-asks the provider with the
              token already held: no consent screen, because the authorisation never lapsed.
            */}
            <Button
              variant="secondary" size="sm"
              onClick={() => refresh.mutate()}
              disabled={refresh.isPending}
              data-testid="wizard-refresh-accounts"
            >
              {refresh.isPending && <Loader2 className="h-4 w-4 animate-spin" />}
              {ar ? 'تحديث الأسماء من المنصة' : 'Refresh names from the provider'}
            </Button>
          </div>

          {missingParentNames > 0 && (
            <p className="flex items-start gap-2 rounded-lg bg-warning-soft p-3 text-sm text-warning" data-testid="wizard-missing-names">
              <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" aria-hidden />
              {ar
                ? `${missingParentNames} من المؤسسات وصلت بلا اسم من المنصة. حدّث الأسماء لعرضها.`
                : `${missingParentNames} organizations arrived without a name. Refresh to fetch them.`}
            </p>
          )}

          {refresh.isError && (
            <p className="text-sm text-danger" data-testid="wizard-refresh-error">
              {toApiError(refresh.error).message}
            </p>
          )}

          <ul className="flex flex-col gap-2">
            {h.parents.map((p) => (
              <li key={p.external_id}>
                <button
                  type="button"
                  onClick={() => { setParent(p.external_id); setPage(1); setStep('accounts') }}
                  className="flex w-full items-center justify-between gap-3 rounded-lg border border-border bg-surface px-4 py-3 text-start hover:border-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary"
                  style={{ minHeight: 44 }}
                >
                  <span className="flex min-w-0 flex-col">
                    {/*
                      The NAME is the label. An id shown as a name claims the provider called it that;
                      saying the name is unavailable is both true and a prompt to refresh.
                    */}
                    <span className={`truncate font-medium ${p.name ? '' : 'text-text-muted'}`}>
                      {p.name ?? (ar ? 'الاسم غير متاح' : 'Name unavailable')}
                    </span>
                    <span className="truncate text-xs text-text-muted" dir="ltr">{p.external_id}</span>
                  </span>
                  <span className="shrink-0 text-sm text-text-muted">
                    {countedAccounts(p.account_count, ar ? 'ar' : 'en')}
                  </span>
                </button>
              </li>
            ))}
          </ul>
        </section>
      )}

      {current === 'accounts' && (
        <section className="flex flex-col gap-3" data-testid="wizard-step-accounts">
          <label className="relative flex items-center">
            <Search className="pointer-events-none absolute mx-3 h-4 w-4 text-text-muted" aria-hidden />
            <input
              type="search"
              value={search}
              onChange={(e) => { setSearch(e.target.value); setPage(1) }}
              placeholder={ar ? 'ابحث بالاسم أو المعرّف' : 'Search by name or id'}
              aria-label={ar ? 'ابحث في الحسابات' : 'Search accounts'}
              className="w-full rounded-lg border border-border bg-surface py-2 ps-9 pe-3 text-sm"
              style={{ minHeight: 44 }}
            />
          </label>

          {accounts.isLoading ? <Skeleton className="h-40" /> : (
            <>
              <ul className="flex flex-col gap-1" data-testid="wizard-account-list">
                {(accounts.data?.accounts ?? []).map((a: DiscoveredAccount) => {
                  const checked = selected.has(a.id)
                  /*
                   * Assigned ELSEWHERE is what disables a row — not assigned at all.
                   *
                   * «Manage accounts» opens on the accounts this project already holds, and every
                   * one of them is assigned by definition. Disabling on `assigned` alone made the
                   * bound rows the only ones a reader could not untick, which is the entire purpose
                   * of the screen. An account feeding ANOTHER project stays disabled, because the
                   * server refuses it with a 409 and a control that cannot succeed should not invite
                   * the click.
                   */
                  const boundHere = managing && a.assigned_project_id === manageProjectId
                  const lockedElsewhere = a.assigned && !boundHere

                  return (
                    <li key={a.id}>
                      <label
                        className="flex cursor-pointer items-center gap-3 rounded-lg border border-border bg-surface px-3 py-2"
                        style={{ minHeight: 44 }}
                      >
                        <input
                          type="checkbox"
                          checked={checked}
                          disabled={lockedElsewhere}
                          onChange={() => setSelected((prev) => {
                            const next = new Set(prev)
                            if (next.has(a.id)) next.delete(a.id); else next.add(a.id)
                            return next
                          })}
                          className="h-5 w-5"
                        />
                        <span className="flex min-w-0 flex-1 flex-col">
                          <span className="truncate font-medium">{a.name}</span>
                          <span className="truncate text-xs text-text-muted" dir="ltr">
                            {a.external_id}{a.parent_name ? ` · ${a.parent_name}` : ''}{a.currency ? ` · ${a.currency}` : ''}{a.timezone ? ` · ${a.timezone}` : ''}
                          </span>
                        </span>
                        {/*
                          RUNTIME-100 §31 — an account's own state, where the account is.

                          Only shown once it HAS one: an unassigned account is inventory, and labelling
                          it would be inventing a fault out of a decision nobody has made yet.
                        */}
                        {a.assigned && (
                          <span className={`shrink-0 text-xs ${accountHealthTone(a.health)}`}>
                            {lockedElsewhere
                              ? (ar ? 'مربوط بمشروع آخر' : 'Connected to another project')
                              : accountHealthLabel(a.health, ar)}
                          </span>
                        )}
                      </label>
                    </li>
                  )
                })}
              </ul>

              {/*
                Select-all applies to the PAGE, and says so.
                A control that ticked all three hundred accounts behind one press would commit a
                decision nobody could review — and on a plan with room for five it would fail at the
                confirm step having looked like it worked.
              */}
              <div className="flex flex-wrap items-center gap-2 text-sm">
                <Button
                  variant="secondary" size="sm"
                  data-testid="wizard-select-page"
                  onClick={() => setSelected((prev) => {
                    const next = new Set(prev)
                    for (const a of accounts.data?.accounts ?? []) {
                      if (!(a.assigned && !(managing && a.assigned_project_id === manageProjectId))) next.add(a.id)
                    }
                    return next
                  })}
                >
                  {ar ? 'تحديد هذه الصفحة' : 'Select this page'}
                </Button>
                <Button
                  variant="ghost" size="sm"
                  data-testid="wizard-clear-page"
                  onClick={() => setSelected((prev) => {
                    const next = new Set(prev)
                    for (const a of accounts.data?.accounts ?? []) next.delete(a.id)
                    return next
                  })}
                >
                  {ar ? 'إلغاء تحديد الصفحة' : 'Clear this page'}
                </Button>
              </div>

              <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
                <span data-testid="wizard-selected-count">
                  {ar
                    ? `تم اختيار ${selected.size} من ${accounts.data?.meta.total ?? 0}`
                    : `${selected.size} of ${accounts.data?.meta.total ?? 0} selected`}
                </span>
                {(accounts.data?.meta.last_page ?? 1) > 1 && (
                  <span className="flex items-center gap-2">
                    <Button variant="secondary" size="sm" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
                      {ar ? 'السابق' : 'Previous'}
                    </Button>
                    <span className="text-text-muted">
                      {page} / {accounts.data?.meta.last_page}
                    </span>
                    <Button
                      variant="secondary" size="sm"
                      disabled={page >= (accounts.data?.meta.last_page ?? 1)}
                      onClick={() => setPage((p) => p + 1)}
                    >
                      {ar ? 'التالي' : 'Next'}
                    </Button>
                  </span>
                )}
              </div>
            </>
          )}
        </section>
      )}

      {current === 'project' && (
        <section className="flex flex-col gap-4" data-testid="wizard-step-project">
          {(projects.data ?? []).length > 0 && (
            <ul className="flex flex-col gap-2">
              {(projects.data ?? []).map((p) => (
                <li key={p.id}>
                  <button
                    type="button"
                    onClick={() => { setProjectId(p.id); setStep('review') }}
                    className={`flex w-full items-center justify-between rounded-lg border px-4 py-3 text-start ${projectId === p.id ? 'border-primary' : 'border-border'} bg-surface`}
                    style={{ minHeight: 44 }}
                  >
                    <span className="font-medium">{p.name}</span>
                    {projectId === p.id && <Check className="h-4 w-4 text-primary" aria-hidden />}
                  </button>
                </li>
              ))}
            </ul>
          )}

          {/*
            A real create form, always available — not only when the list is empty.
            Somebody connecting a new client's accounts usually wants a new project for them, and
            making that reachable only from a zero state is how people end up filing a second client
            into the first client's project.
          */}
          <form
            className="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4"
            onSubmit={(e) => { e.preventDefault(); createAndUse.mutate() }}
            /*
             * `noValidate`, and every field checked in the mutation instead.
             *
             * Native constraint validation shows the BROWSER'S bubble, in the browser's language and
             * the browser's wording — which on an Arabic-first product means an English «Please fill
             * out this field» over an RTL form. The refusals below are ours, translated, and appear
             * in the same place as every other error this form can produce.
             */
            noValidate
            data-testid="wizard-create-project-form"
          >
            <p className="text-sm text-text-muted">
              {(projects.data ?? []).length === 0
                ? (ar
                  ? 'لا توجد مشاريع بعد. أنشئ مشروعًا لمتابعة الربط — لن تحتاج إلى إعادة المصادقة.'
                  : 'No projects yet. Create one to continue — you will not need to authorise again.')
                : (ar ? 'أو أنشئ مشروعًا جديدًا لهذه الحسابات.' : 'Or create a new project for these accounts.')}
            </p>

            <label className="flex flex-col gap-1 text-sm">
              <span className="font-medium">{ar ? 'اسم المشروع' : 'Project name'}</span>
              <input
                type="text"
                value={projectName}
                onChange={(e) => setProjectName(e.target.value)}
                placeholder={ar ? 'مثال: حملات الربع الثالث' : 'For example: Q3 campaigns'}
                aria-label={ar ? 'اسم المشروع' : 'Project name'}
                className="rounded-lg border border-border bg-bg px-3 py-2"
                style={{ minHeight: 44 }}
                data-testid="wizard-project-name"
              />
            </label>

            {/* Asked only when the server says the choice is the customer's (an agency's client). */}
            {needsWorkspace && (
              <label className="flex flex-col gap-1 text-sm">
                <span className="font-medium">{ar ? 'العميل' : 'Client'}</span>
                <select
                  value={workspaceId ?? ''}
                  onChange={(e) => setWorkspaceId(e.target.value || null)}
                  aria-label={ar ? 'العميل' : 'Client'}
                  className="rounded-lg border border-border bg-bg px-3 py-2"
                  style={{ minHeight: 44 }}
                  data-testid="wizard-project-workspace"
                >
                  <option value="">{ar ? 'اختر العميل…' : 'Choose a client…'}</option>
                  {(workspaces.data ?? []).map((w) => (
                    <option key={w.id} value={w.id}>{w.name}</option>
                  ))}
                </select>
              </label>
            )}

            <Button type="submit" disabled={createAndUse.isPending} data-testid="wizard-create-project">
              {createAndUse.isPending && <Loader2 className="h-4 w-4 animate-spin" />}
              {ar ? 'إنشاء مشروع ومتابعة الربط' : 'Create a project and continue'}
            </Button>

            {createAndUse.isError && (
              <p className="text-sm text-danger" data-testid="wizard-create-project-error">
                {toApiError(createAndUse.error).message}
              </p>
            )}
          </form>
        </section>
      )}

      {current === 'review' && (
        <section className="flex flex-col gap-3" data-testid="wizard-step-review">
          <dl className="grid gap-2 rounded-lg border border-border bg-surface p-4 text-sm">
            <div className="flex justify-between gap-3">
              <dt className="text-text-muted">{ar ? 'المنصة' : 'Provider'}</dt>
              <dd className="font-medium">{providerLabel}</dd>
            </div>
            {parent && (
              <div className="flex justify-between gap-3">
                <dt className="text-text-muted">{ar ? h.parent_label?.labelAr : h.parent_label?.label}</dt>
                <dd className="font-medium">
                  {h.parents.find((p) => p.external_id === parent)?.name ?? parent}
                </dd>
              </div>
            )}
            <div className="flex justify-between gap-3">
              <dt className="text-text-muted">{ar ? 'الحسابات المختارة' : 'Accounts'}</dt>
              <dd className="font-medium">{selected.size}</dd>
            </div>
            <div className="flex justify-between gap-3">
              <dt className="text-text-muted">{ar ? 'المشروع' : 'Project'}</dt>
              <dd className="font-medium">
                {(projects.data ?? []).find((p) => p.id === projectId)?.name ?? '—'}
              </dd>
            </div>
            {/* The plan impact, stated before anything is written. */}
            <div className="flex justify-between gap-3 border-t border-border pt-2">
              <dt className="text-text-muted">
                {ar ? 'الحسابات المربوطة بعد التأكيد' : 'Connected accounts after confirming'}
              </dt>
              <dd className="font-medium" data-testid="wizard-plan-impact">
                {afterConfirm}{adAccounts?.limit != null ? ` / ${adAccounts.limit}` : ''}
              </dd>
            </div>
          </dl>

          {overLimit && (
            <p className="flex items-start gap-2 rounded-lg bg-warning-soft p-3 text-sm text-warning" data-testid="wizard-over-limit">
              <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" aria-hidden />
              {ar
                ? `اخترت ${countedAccounts(selected.size, 'ar')}، والمتاح في خطتك ${Math.max(0, (adAccounts?.limit ?? 0) - (adAccounts?.used ?? 0))}. عدّل الاختيار أو ارقِ الخطة.`
                : `You selected ${selected.size}; your plan has ${Math.max(0, (adAccounts?.limit ?? 0) - (adAccounts?.used ?? 0))} remaining. Adjust the selection or upgrade.`}
            </p>
          )}

          {confirm.isError && (
            <p className="text-sm text-danger" data-testid="wizard-confirm-error">
              {toApiError(confirm.error).message}
            </p>
          )}
        </section>
      )}

      {/*
        What the save actually did — the three groups, named.
        «Saved» alone leaves a reader to work out whether the account they unticked is gone; the diff
        says it in the same breath, and says «nothing changed» when nothing did.
      */}
      {diff !== null && (
        <section data-testid="wizard-selection-diff" className="rounded-lg border border-success bg-success-soft p-3 text-sm">
          {diff.added.length === 0 && diff.removed.length === 0 ? (
            <span>{ar ? 'لا تغيير — الحسابات المربوطة كما هي.' : 'No change — the bound accounts are as they were.'}</span>
          ) : (
            <span className="tnum">
              {ar
                ? `أُضيف ${diff.added.length} · أُزيل ${diff.removed.length} · بقي ${diff.unchanged.length}`
                : `${diff.added.length} added · ${diff.removed.length} removed · ${diff.unchanged.length} unchanged`}
            </span>
          )}
        </section>
      )}

      {current === 'done' && (
        <section className="flex flex-col gap-2 rounded-lg border border-success bg-success-soft p-4" data-testid="wizard-step-done">
          <p className="font-medium text-success">
            {ar ? 'تم الربط. بدأت أول مزامنة.' : 'Connected. The first sync has started.'}
          </p>
          <p className="text-sm text-text-muted">
            {ar
              ? 'ستظهر الحملات والمقاييس داخل المشروع بعد اكتمال المزامنة الأولى.'
              : 'Campaigns and metrics appear in the project once the first sync completes.'}
          </p>
        </section>
      )}

      <footer className="flex flex-wrap items-center justify-between gap-2 border-t border-border pt-3">
        <Button variant="ghost" onClick={onClose}>{ar ? 'إغلاق' : 'Close'}</Button>

        <span className="flex items-center gap-2">
          {current !== 'done' && current !== (hasParent ? 'parent' : 'accounts') && (
            <Button
              variant="secondary"
              onClick={() => setStep(
                current === 'review' ? 'project' : current === 'project' ? 'accounts' : 'parent',
              )}
            >
              {ar ? <ArrowRight className="h-4 w-4" /> : <ArrowLeft className="h-4 w-4" />}
              {ar ? 'رجوع' : 'Back'}
            </Button>
          )}

          {current === 'accounts' && !managing && (
            <Button disabled={selected.size === 0} onClick={() => setStep('project')}>
              {ar ? 'متابعة' : 'Continue'}
            </Button>
          )}

          {/*
            Managing saves from here: there is no project to choose and no plan review to pass, and
            an empty selection is a legitimate answer — «this project keeps none of them».
          */}
          {current === 'accounts' && managing && (
            <Button
              onClick={() => save.mutate()}
              disabled={save.isPending || !bindings.isSuccess}
              data-testid="wizard-save-selection"
            >
              {save.isPending && <Loader2 className="h-4 w-4 animate-spin" />}
              {ar ? 'حفظ الحسابات' : 'Save accounts'}
            </Button>
          )}

          {current === 'review' && (
            <Button
              onClick={() => confirm.mutate()}
              disabled={confirm.isPending || overLimit || selected.size === 0 || !projectId}
              data-testid="wizard-confirm"
            >
              {confirm.isPending && <Loader2 className="h-4 w-4 animate-spin" />}
              {ar ? 'تأكيد الربط وبدء المزامنة' : 'Confirm and start syncing'}
            </Button>
          )}
        </span>
      </footer>
    </div>
  )
}

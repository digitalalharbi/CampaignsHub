import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, ArrowLeft, ArrowRight, Check, Loader2, Search } from 'lucide-react'
import {
  bindAccountToProject, fetchConnectionHierarchy, fetchDiscoveredAccounts, fetchPlanUsage,
  type DiscoveredAccount,
} from './api'
import { createProject, listClientWorkspaces, listProjects } from '@/features/projects/api'
import { Button } from '@/components/ui/Button'
import { ErrorState, Skeleton } from '@/components/ui/States'
import { toApiError } from '@/lib/api/client'
import { useUi } from '@/stores/ui'

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

interface Props {
  connectionId: string
  onClose: () => void
}

export function ConnectionWizard({ connectionId, onClose }: Props) {
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

  const createAndUse = useMutation({
    mutationFn: async (name: string) => {
      const workspaceId = workspaces.data?.[0]?.id
      if (!workspaceId) throw new Error(ar ? 'لا توجد مساحة عميل.' : 'No client workspace.')
      return createProject({ client_workspace_id: workspaceId, name })
    },
    onSuccess: async (project) => {
      // Straight back into the same wizard with the connection, the organisation and every account
      // selection intact — and no second OAuth (ORCH-100 §5).
      setProjectId(project.id)
      await queryClient.invalidateQueries({ queryKey: ['projects'] })
      await queryClient.invalidateQueries({ queryKey: ['plan-usage'] })
      setStep('review')
    },
  })

  const confirm = useMutation({
    mutationFn: async () => {
      if (!projectId) throw new Error(ar ? 'اختر مشروعًا أولًا.' : 'Choose a project first.')
      // One call per account. The server counts the quota under a lock, so a refusal part-way is
      // authoritative and the ones already connected stay connected.
      for (const accountId of selected) {
        await bindAccountToProject(projectId, accountId)
      }
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['connection-hierarchy', connectionId] })
      await queryClient.invalidateQueries({ queryKey: ['plan-usage'] })
      await queryClient.invalidateQueries({ queryKey: ['connectors'] })
      setStep('done')
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

  return (
    <div data-testid="connection-wizard" className="flex flex-col gap-4">
      <header className="flex flex-wrap items-baseline justify-between gap-2">
        <h2 className="text-lg font-semibold">
          {ar ? `ربط ${providerLabel}` : `Connect ${providerLabel}`}
        </h2>
        {/* Available and connected are different numbers and are shown as different numbers. */}
        <p className="text-sm text-text-muted" data-testid="wizard-inventory">
          {ar
            ? `${h.discovered_count} حسابًا متاحًا · ${h.assigned_count} مربوطًا`
            : `${h.discovered_count} available · ${h.assigned_count} connected`}
        </p>
      </header>

      {current === 'parent' && (
        <section className="flex flex-col gap-3" data-testid="wizard-step-parent">
          <p className="text-sm text-text-muted">
            {ar
              ? `اختر ${h.parent_label?.labelAr ?? 'المؤسسة'} لعرض حساباتها.`
              : `Choose an ${h.parent_label?.label ?? 'organization'} to see its accounts.`}
          </p>
          <ul className="flex flex-col gap-2">
            {h.parents.map((p) => (
              <li key={p.external_id}>
                <button
                  type="button"
                  onClick={() => { setParent(p.external_id); setPage(1); setStep('accounts') }}
                  className="flex w-full items-center justify-between gap-3 rounded-lg border border-border bg-surface px-4 py-3 text-start hover:border-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary"
                  style={{ minHeight: 44 }}
                >
                  <span className="flex flex-col">
                    <span className="font-medium">{p.name}</span>
                    <span className="text-xs text-text-muted" dir="ltr">{p.external_id}</span>
                  </span>
                  <span className="text-sm text-text-muted">
                    {ar ? `${p.account_count} حساب` : `${p.account_count} accounts`}
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
                  return (
                    <li key={a.id}>
                      <label
                        className="flex cursor-pointer items-center gap-3 rounded-lg border border-border bg-surface px-3 py-2"
                        style={{ minHeight: 44 }}
                      >
                        <input
                          type="checkbox"
                          checked={checked}
                          disabled={a.assigned}
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
                            {a.external_id}{a.currency ? ` · ${a.currency}` : ''}{a.timezone ? ` · ${a.timezone}` : ''}
                          </span>
                        </span>
                        {a.assigned && (
                          <span className="text-xs text-text-muted">
                            {ar ? 'مربوط بمشروع' : 'Already connected'}
                          </span>
                        )}
                      </label>
                    </li>
                  )
                })}
              </ul>

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
        <section className="flex flex-col gap-3" data-testid="wizard-step-project">
          {(projects.data ?? []).length === 0 ? (
            /* Zero projects is not a dead end — it is the next step, named (ORCH-100 §5). */
            <div className="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4">
              <p className="text-sm">
                {ar
                  ? 'لا توجد مشاريع بعد. أنشئ مشروعًا لمتابعة الربط — لن تحتاج إلى إعادة المصادقة.'
                  : 'No projects yet. Create one to continue — you will not need to authorise again.'}
              </p>
              <Button
                onClick={() => createAndUse.mutate(ar ? 'المشروع الأول' : 'First project')}
                disabled={createAndUse.isPending}
                data-testid="wizard-create-project"
              >
                {createAndUse.isPending && <Loader2 className="h-4 w-4 animate-spin" />}
                {ar ? 'إنشاء مشروع ومتابعة الربط' : 'Create a project and continue'}
              </Button>
              {createAndUse.isError && (
                <p className="text-sm text-danger">{toApiError(createAndUse.error).message}</p>
              )}
            </div>
          ) : (
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
                ? `اخترت ${selected.size} حسابًا، والمتاح في خطتك ${Math.max(0, (adAccounts?.limit ?? 0) - (adAccounts?.used ?? 0))}. عدّل الاختيار أو ارقِ الخطة.`
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

          {current === 'accounts' && (
            <Button disabled={selected.size === 0} onClick={() => setStep('project')}>
              {ar ? 'متابعة' : 'Continue'}
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

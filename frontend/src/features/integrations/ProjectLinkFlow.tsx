import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { ArrowLeft, ArrowRight } from 'lucide-react'
import { fetchConnectionHub, type ConnectableProvider, type HubConnection } from './api'
import { ConnectionFlow } from './ConnectionFlow'
import { listProjects } from '@/features/projects/api'
import { Button } from '@/components/ui/Button'
import { ErrorState, Skeleton } from '@/components/ui/States'
import { platformColor } from '@/features/analytics/components'
import { platformLabel, sortByPlatform } from '@/lib/platforms'
import { useUi } from '@/stores/ui'

/** A connection the flow can continue on: only what it reads. */
export type LinkConnection = Pick<HubConnection, 'id' | 'provider' | 'has_parent' | 'projects'>

/**
 * Which existing authorisations a project may reuse for a platform.
 *
 * Working, not removed, and filed either under nobody (tenant-shared) or under THIS project's own client:
 * a sign-in filed under another client can only ever offer that client's accounts, so it is not offered.
 */
export function reusableAuthorisations(connections: HubConnection[], providerKey: string, clientWorkspaceId: string | null): HubConnection[] {
  return connections.filter((c) => c.provider === providerKey
    && c.connection_state === 'CONNECTED'
    && (c.removed_at ?? null) === null
    && ((c.client_workspace_id ?? null) === null || c.client_workspace_id === clientWorkspaceId))
}

/**
 * INTEG-PROJECT-LINK-001 (Owner 2026-11-06) — «الأساس ربط المصدر بالمشروع».
 *
 * ONE journey, started from a project and ended on it:
 *
 *     project (fixed) → platform → authorisation → organisation → account → «تم الربط» → the project
 *
 * This component owns only the two questions that come before the centre's existing flow — WHICH platform,
 * and WHOSE authorisation — and then hands over to {@link ConnectionFlow} with the project fixed. It is not
 * a second wizard: the sign-in, the organisation and account picker, the confirmation and the first sync are
 * the same components and the same endpoints the centre has always used.
 *
 * ## One question per step, and no question with one answer
 *
 * A platform handed in by the caller is not asked for again. An authorisation is asked about only when one
 * already exists that this project may use — and then the choice names the PLATFORM account it belongs to,
 * because «use the existing one» is meaningless without saying whose it is. With none, the flow goes
 * straight to the platform's sign-in.
 *
 * ## The project never changes
 *
 * There is no project picker anywhere in here. The project is stated at the top of every step and is the
 * only place the journey can end.
 */
export function ProjectLinkFlow({ projectId, provider = null, resume = null, onClose, onReturn }: {
  projectId: string
  /** Already chosen by the caller (a platform pressed on a project row): the platform step is skipped. */
  provider?: ConnectableProvider | null
  /** The connection a provider's consent screen came back with, proven pending for this project by the server. */
  resume?: LinkConnection | null
  onClose: () => void
  /** The journey is over: go back to the project's page. */
  onReturn: () => void
}) {
  const ar = useUi((s) => s.locale) === 'ar'
  const hub = useQuery({ queryKey: ['connection-hub'], queryFn: fetchConnectionHub, retry: false })
  const projects = useQuery({ queryKey: ['projects'], queryFn: () => listProjects(), retry: false })

  const [chosen, setChosen] = useState<ConnectableProvider | null>(provider)
  const [marked, setMarked] = useState<string | null>(provider?.key ?? null)
  /** `undefined` = not asked yet; `null` = a new sign-in; a connection = reuse it. */
  const [authorisation, setAuthorisation] = useState<HubConnection | null | undefined>(undefined)
  const [markedAuth, setMarkedAuth] = useState<string>('')

  const project = projects.data?.find((p) => p.id === projectId) ?? null
  const projectName = project?.name ?? null
  const Arrow = ar ? ArrowLeft : ArrowRight

  if (resume !== null) {
    return <ConnectionFlow mode="connect" provider={null} connection={resume} projectId={projectId} onClose={onClose} onDone={onReturn} />
  }

  const reusable = chosen === null ? [] : reusableAuthorisations(hub.data?.connections ?? [], chosen.key, project?.client_workspace_id ?? null)

  // Nothing to reuse is not a question: straight to the platform's own sign-in (or its key entry).
  if (chosen !== null && hub.isSuccess && !projects.isLoading && (reusable.length === 0 || authorisation !== undefined)) {
    return (
      <ConnectionFlow
        mode="connect"
        provider={chosen}
        connection={authorisation ?? null}
        projectId={projectId}
        onClose={onClose}
        onDone={onReturn}
      />
    )
  }

  const providers = sortByPlatform((hub.data?.connectable ?? []).filter((p) => p.kind === 'advertising'), (p) => p.key)
  const step: 'platform' | 'authorisation' = chosen === null ? 'platform' : 'authorisation'
  const next = () => {
    if (step === 'platform') {
      const pick = providers.find((p) => p.key === marked)
      if (pick !== undefined) setChosen(pick)
      return
    }
    if (markedAuth === '') return
    setAuthorisation(markedAuth === 'new' ? null : (reusable.find((c) => c.id === markedAuth) ?? null))
  }
  const canAdvance = step === 'platform' ? marked !== null : markedAuth !== ''

  return (
    <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4 sm:p-8" role="dialog" aria-modal="true" aria-labelledby="project-link-title">
      <form
        className="flex w-full max-w-lg flex-col gap-4 rounded-2xl border border-border bg-surface p-5 shadow-2xl"
        data-testid="project-link-flow"
        // Enter advances: one primary action per step.
        onSubmit={(e) => { e.preventDefault(); if (canAdvance) next() }}
      >
        <header className="flex items-start justify-between gap-3">
          <div className="min-w-0">
            <h2 id="project-link-title" className="text-base font-bold text-text-primary">{ar ? 'ربط مصدر' : 'Link a source'}</h2>
            <p className="truncate text-xs text-text-secondary" data-testid="link-project">
              {ar ? 'المشروع' : 'Project'}: <span className="font-semibold text-text-primary">{projectName ?? '…'}</span>
            </p>
          </div>
          <Button type="button" variant="ghost" onClick={onClose} data-testid="project-link-close">{ar ? 'إغلاق' : 'Close'}</Button>
        </header>

        {/* Where the reader is. The steps after these two are the centre's own flow, which draws its own. */}
        <ol className="flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] font-semibold" data-testid="link-steps">
          {[
            { key: 'platform', ar: 'المنصة', en: 'Platform' },
            { key: 'authorisation', ar: 'التفويض', en: 'Authorisation' },
            { key: 'organisation', ar: 'المنظمة', en: 'Organisation' },
            { key: 'account', ar: 'الحساب', en: 'Account' },
            { key: 'done', ar: 'تم', en: 'Done' },
          ].map((s, index) => (
            <li
              key={s.key}
              data-testid={`link-step-${s.key}`}
              aria-current={s.key === step ? 'step' : undefined}
              className={`flex items-center gap-1 rounded-full px-2 py-0.5 ${s.key === step ? 'bg-brand-600 text-white' : 'bg-surface-secondary text-text-secondary'}`}
            >
              <span className="tnum">{(index + 1).toLocaleString('en-US')}</span> {ar ? s.ar : s.en}
            </li>
          ))}
        </ol>

        {hub.isLoading || projects.isLoading ? (
          <Skeleton className="h-40 w-full" />
        ) : hub.isError ? (
          <ErrorState title={ar ? 'تعذّر تحميل المنصات المتاحة.' : 'Could not load the available platforms.'} onRetry={() => void hub.refetch()} />
        ) : step === 'platform' ? (
          <fieldset className="flex flex-col gap-1.5" data-testid="link-step-platform-body">
            <legend className="mb-1 text-sm font-bold text-text-primary">{ar ? 'اختر المنصة' : 'Choose the platform'}</legend>
            {providers.length === 0 ? (
              <p className="rounded-xl border border-dashed border-border p-4 text-center text-xs text-text-secondary" data-testid="link-no-platform">
                {ar
                  ? 'لا توجد منصة مهيّأة للربط بعد. يتولّى مشغّل المنصة تجهيزها.'
                  : 'No platform is set up for connecting yet. The platform operator is preparing them.'}
              </p>
            ) : providers.map((p) => (
              <label
                key={p.key}
                className={`flex cursor-pointer items-center gap-3 rounded-xl border px-3 py-2.5 transition-colors ${marked === p.key ? 'border-brand-500 bg-surface-hover' : 'border-border hover:bg-surface-hover'}`}
              >
                <input
                  type="radio"
                  name="link-platform"
                  className="h-4 w-4 accent-brand-600"
                  checked={marked === p.key}
                  onChange={() => setMarked(p.key)}
                  data-testid={`link-platform-${p.key}`}
                />
                <span className="h-7 w-1 shrink-0 rounded-full" style={{ background: platformColor(p.key) }} aria-hidden />
                <span className="flex min-w-0 flex-col">
                  <span className="truncate text-sm font-semibold text-text-primary">{platformLabel(p.key, ar)}</span>
                  <span className="truncate text-xs text-text-secondary">
                    {p.auth === 'api_key'
                      ? (ar ? 'بمفتاح واجهة تملكه' : 'With an API key you hold')
                      : (ar ? 'بتسجيل الدخول لدى المنصة' : 'By signing in at the platform')}
                  </span>
                </span>
              </label>
            ))}
          </fieldset>
        ) : (
          <fieldset className="flex flex-col gap-1.5" data-testid="link-step-authorisation-body">
            <legend className="mb-1 text-sm font-bold text-text-primary">
              {ar ? `أي حساب على ${platformLabel(chosen!.key, ar)} تريد استخدامه؟` : `Which ${platformLabel(chosen!.key, ar)} account do you want to use?`}
            </legend>
            {reusable.map((c) => {
              const account = c.provider_account ?? null
              const identity = account === null ? null : (account.email ?? account.label)
              return (
                <label
                  key={c.id}
                  className={`flex cursor-pointer items-center gap-3 rounded-xl border px-3 py-2.5 transition-colors ${markedAuth === c.id ? 'border-brand-500 bg-surface-hover' : 'border-border hover:bg-surface-hover'}`}
                >
                  <input
                    type="radio"
                    name="link-authorisation"
                    className="h-4 w-4 accent-brand-600"
                    checked={markedAuth === c.id}
                    onChange={() => setMarkedAuth(c.id)}
                    data-testid={`link-reuse-${c.id}`}
                  />
                  <span className="flex min-w-0 flex-col">
                    <span className="truncate text-sm font-semibold text-text-primary">{ar ? 'استخدام التفويض القائم' : 'Use the existing authorisation'}</span>
                    <span className="truncate text-xs text-text-secondary">
                      {identity === null
                        ? (ar ? 'لم يُسجَّل حساب المنصة لهذا التفويض' : 'The platform account of this authorisation was not recorded')
                        : `${ar ? 'حساب المنصة' : 'Platform account'}: ${identity}`}
                    </span>
                  </span>
                </label>
              )
            })}
            <label className={`flex cursor-pointer items-center gap-3 rounded-xl border px-3 py-2.5 transition-colors ${markedAuth === 'new' ? 'border-brand-500 bg-surface-hover' : 'border-border hover:bg-surface-hover'}`}>
              <input
                type="radio"
                name="link-authorisation"
                className="h-4 w-4 accent-brand-600"
                checked={markedAuth === 'new'}
                onChange={() => setMarkedAuth('new')}
                data-testid="link-new-authorisation"
              />
              <span className="text-sm font-semibold text-text-primary">{ar ? 'تسجيل الدخول بحساب آخر' : 'Sign in with another account'}</span>
            </label>
          </fieldset>
        )}

        <footer className="flex items-center justify-between gap-2">
          {step === 'authorisation' && provider === null ? (
            // Going back keeps what was chosen: the platform stays marked.
            <Button type="button" variant="ghost" onClick={() => { setChosen(null); setAuthorisation(undefined) }} data-testid="link-back">
              {ar ? 'السابق' : 'Back'}
            </Button>
          ) : <span />}
          <Button type="submit" disabled={!canAdvance} data-testid="link-next">
            {ar ? 'التالي' : 'Next'} <Arrow size={15} />
          </Button>
        </footer>
      </form>
    </div>
  )
}

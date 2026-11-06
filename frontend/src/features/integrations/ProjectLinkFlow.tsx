import { useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { ArrowLeft, ArrowRight } from 'lucide-react'
import { fetchConnectionHub, type ConnectableProvider, type HubConnection } from './api'
import { ConnectionFlow } from './ConnectionFlow'
import { ProjectAccountPicker } from './ProjectAccountPicker'
import { ProjectSourcePicker } from './ProjectSourcePicker'
import { listStoreProviders, startStoreOAuth } from '@/features/commerce/api'
import { listMeasurementProviders, startMeasurementOAuth } from '@/features/measurement/api'
import { listProjects } from '@/features/projects/api'
import { Button } from '@/components/ui/Button'
import { ErrorState, Skeleton } from '@/components/ui/States'
import { ProviderMark } from '@/components/ProviderMark'
import { toApiError } from '@/lib/api/client'
import { platformLabel, sortByPlatform } from '@/lib/platforms'
import { useUi } from '@/stores/ui'

/** A connection the flow can continue on: only what it reads. */
export type LinkConnection = Pick<HubConnection, 'id' | 'provider' | 'has_parent' | 'projects'> & {
  /** INTEG-PROJECT-LINK-STORES-GA4-001 — which journey resumes; absent is an ad platform's. */
  kind?: 'advertising' | 'commerce' | 'measurement' | null
}

/** A store platform or a measurement provider this install can start: enabled, and with its system keys present. */
const STARTABLE = (state: string): boolean => state !== 'awaiting_credentials' && state !== 'unavailable'

/**
 * INTEG-PROJECT-LINK-001 (Owner 2026-11-06) — «المشاريع > ربط المصادر > تسجيل الدخول > اختيار المنظمة >
 * الحساب الإعلاني > تم > الرجوع للمشروع … وكل مشروع بنفس العملية نفسها».
 *
 * ONE journey, the same for every project, started from the project and ended on it:
 *
 *     project (fixed) → platform → sign-in → organisation → account → «تم» → the project
 *
 * This component owns only the first question — WHICH platform — and then hands over to
 * {@link ConnectionFlow} with the project fixed. It is not a second wizard: the sign-in, the organisation
 * and account picker, the confirmation and the first sync are the same components and endpoints the
 * centre has always used.
 *
 * ## Every link signs in
 *
 * An authorisation that already exists is never offered for reuse here. Each project's link goes through
 * the platform's own sign-in (or its key entry) FOR THAT PROJECT, so a link is always a deliberate,
 * project-scoped act and never a selection out of somebody else's sign-in.
 *
 * ## Stores and GA4 take the same journey
 *
 * INTEG-PROJECT-LINK-STORES-GA4-001 — سلة, زد and GA4 are chosen from the same list. They have no
 * organisation and no ad account, so their journey is shorter — منصة ← متجر ← تم, منصة ← خاصية ← تم — and
 * «التالي» leaves straight for the platform's own page, for this project.
 *
 * ## The project never changes
 *
 * There is no project picker anywhere in here. The project is stated at the top and is the only place the
 * journey can end. A platform handed in by the caller is not asked for again.
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
  // The platforms on offer are read through THIS project's hub: the journey fetches nothing of another project.
  const hub = useQuery({ queryKey: ['connection-hub', projectId, 'live'], queryFn: () => fetchConnectionHub(projectId), retry: false })
  const projects = useQuery({ queryKey: ['projects'], queryFn: () => listProjects(), retry: false })

  const [chosen, setChosen] = useState<ConnectableProvider | null>(provider)
  const [marked, setMarked] = useState<string | null>(provider?.key ?? null)
  const project = projects.data?.find((p) => p.id === projectId) ?? null
  const projectName = project?.name ?? null
  const Arrow = ar ? ArrowLeft : ArrowRight

  /*
   * The stores and the measurement provider, read through THIS project's own lists. Which of them this
   * install can start is the server's answer; nothing here names a system key.
   */
  const stores = useQuery({ queryKey: ['commerce-stores', projectId], queryFn: () => listStoreProviders(projectId), retry: false, enabled: resume === null })
  const measurement = useQuery({ queryKey: ['measurement-properties', projectId], queryFn: () => listMeasurementProviders(projectId), retry: false, enabled: resume === null })

  const leave = useMutation({
    mutationFn: (pick: ConnectableProvider) => {
      const journey = { projectId, returnPortal: window.location.pathname.startsWith('/agency') ? 'agency' as const : 'app' as const }
      const workspace = projects.data?.find((p) => p.id === projectId)?.client_workspace_id ?? null
      return pick.kind === 'commerce' ? startStoreOAuth(pick.key, workspace, journey) : startMeasurementOAuth(pick.key, workspace, journey)
    },
    onSuccess: ({ authorization_url: url }) => { window.location.assign(url) },
  })

  // Back from a store's or Google's own page: the store / the property → done.
  if (resume !== null && (resume.kind === 'commerce' || resume.kind === 'measurement')) {
    return <ProjectSourcePicker projectId={projectId} projectName={projectName} connection={{ id: resume.id, provider: resume.provider, kind: resume.kind }} onClose={onClose} onReturn={onReturn} />
  }

  // Back from the platform's sign-in: organisation → account → done, asked on demand for this project's own connection.
  if (resume !== null) {
    return <ProjectAccountPicker projectId={projectId} projectName={projectName} connection={resume} onClose={onClose} onReturn={onReturn} />
  }

  // The platform is known: straight to its own sign-in (or key entry), for this project. Never a reuse.
  if (chosen !== null && chosen.kind === 'advertising') {
    return <ConnectionFlow mode="connect" provider={chosen} connection={null} projectId={projectId} onClose={onClose} onDone={onReturn} />
  }

  const sources: ConnectableProvider[] = [
    ...(stores.data ?? []).filter((p) => STARTABLE(p.state)).map((p) => ({ key: p.key, label: p.label, label_ar: platformLabel(p.key, true), kind: 'commerce' as const, has_parent: false, auth: 'oauth' as const })),
    ...(measurement.data ?? []).filter((p) => STARTABLE(p.state)).map((p) => ({ key: p.key, label: p.label, label_ar: p.label_ar, kind: 'measurement' as const, has_parent: false, auth: 'oauth' as const })),
  ]
  // Ad platforms in the product's order, then the stores, then measurement.
  const providers = [...sortByPlatform((hub.data?.connectable ?? []).filter((p) => p.kind === 'advertising'), (p) => p.key), ...sources]
  const step: LinkStep = 'platform'
  const pick = providers.find((p) => p.key === marked) ?? (chosen !== null && chosen.key === marked ? chosen : undefined)
  const next = () => {
    if (pick === undefined) return
    // A store or a property has no step of ours before the platform's own page: «التالي» leaves for it.
    if (pick.kind === 'advertising') setChosen(pick)
    else leave.mutate(pick)
  }
  const canAdvance = pick !== undefined && !leave.isPending
  const steps: LinkStep[] = pick?.kind === 'commerce' ? ['platform', 'store', 'done']
    : pick?.kind === 'measurement' ? ['platform', 'property', 'done']
      : ['platform', 'organisation', 'account', 'done']
  const refusal = leave.isError ? toApiError(leave.error) : null

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

        <LinkSteps steps={steps} current={step} ar={ar} />

        {hub.isLoading || projects.isLoading || stores.isLoading || measurement.isLoading ? (
          <Skeleton className="h-40 w-full" />
        ) : hub.isError ? (
          <ErrorState title={ar ? 'تعذّر تحميل المنصات المتاحة.' : 'Could not load the available platforms.'} onRetry={() => void hub.refetch()} />
        ) : (
          <fieldset className="flex flex-col gap-1.5" data-testid="link-step-platform-body">
            <legend className="mb-1 text-sm font-bold text-text-primary">{ar ? 'المنصة' : 'Platform'}</legend>
            {providers.length === 0 ? (
              <p className="rounded-xl border border-dashed border-border p-4 text-center text-xs text-text-secondary" data-testid="link-no-platform">
                {ar ? 'لا منصة مهيّأة بعد.' : 'No platform set up yet.'}
              </p>
            ) : providers.map((p) => (
              <label
                key={p.key}
                className={`flex cursor-pointer items-center gap-3 rounded-xl border px-3 py-2.5 transition-colors ${marked === p.key ? 'border-brand-500 bg-surface-hover' : 'border-border hover:bg-surface-hover'}`}
                data-testid={`link-platform-row-${p.key}`}
              >
                <input
                  type="radio"
                  name="link-platform"
                  className="h-4 w-4 accent-brand-600"
                  checked={marked === p.key}
                  onChange={() => setMarked(p.key)}
                  data-testid={`link-platform-${p.key}`}
                />
                <ProviderMark provider={p.key} size={22} />
                <span className="flex min-w-0 flex-col">
                  <span className="truncate text-sm font-semibold text-text-primary">{platformLabel(p.key, ar)}</span>
                  <span className="truncate text-xs text-text-secondary" data-kind={p.kind}>
                    {p.kind === 'commerce' ? (ar ? 'متجر' : 'Store')
                      : p.kind === 'measurement' ? (ar ? 'قياس' : 'Measurement')
                        : p.auth === 'api_key' ? (ar ? 'مفتاح API' : 'API key') : (ar ? 'تسجيل دخول' : 'Sign-in')}
                  </span>
                </span>
              </label>
            ))}
          </fieldset>
        )}

        {refusal !== null && (
          <p role="alert" className="text-xs font-semibold text-danger" data-testid="link-start-refused">{refusal.message}</p>
        )}

        <footer className="flex items-center justify-between gap-2">
          <span />
          <Button type="submit" disabled={!canAdvance} loading={leave.isPending} data-testid="link-next">
            {ar ? 'التالي' : 'Next'} <Arrow size={15} />
          </Button>
        </footer>
      </form>
    </div>
  )
}

export type LinkStep = 'platform' | 'organisation' | 'account' | 'store' | 'property' | 'done'

const STEP_WORDS: Record<LinkStep, { ar: string; en: string }> = {
  platform: { ar: 'منصة', en: 'Platform' },
  organisation: { ar: 'منظمة', en: 'Organisation' },
  account: { ar: 'حساب', en: 'Account' },
  store: { ar: 'متجر', en: 'Store' },
  property: { ar: 'خاصية', en: 'Property' },
  done: { ar: 'تم', en: 'Done' },
}

/** Where the reader is: one word per step — منصة ← منظمة ← حساب ← تم. The sign-in is the platform's own page, not a step drawn here. */
export function LinkSteps({ steps, current, ar }: { steps: LinkStep[]; current: LinkStep; ar: boolean }) {
  return (
    <ol className="flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] font-semibold" data-testid="link-steps">
      {steps.map((key, index) => (
        <li
          key={key}
          data-testid={`link-step-${key}`}
          aria-current={key === current ? 'step' : undefined}
          className={`flex items-center gap-1 rounded-full px-2 py-0.5 ${key === current ? 'bg-brand-600 text-white' : 'bg-surface-secondary text-text-secondary'}`}
        >
          <span className="tnum">{(index + 1).toLocaleString('en-US')}</span> {ar ? STEP_WORDS[key].ar : STEP_WORDS[key].en}
        </li>
      ))}
    </ol>
  )
}

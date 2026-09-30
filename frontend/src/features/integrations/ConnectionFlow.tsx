import { useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { ArrowLeft, ArrowRight, Check, ShieldCheck } from 'lucide-react'
import {
  startPlatformOAuth, type ConnectableProvider, type HubConnection,
} from './api'
import { ConnectionWizard } from './ConnectionWizard'
import { listProjects } from '@/features/projects/api'
import { Button } from '@/components/ui/Button'
import { Num } from '@/components/ui/Num'
import { Skeleton } from '@/components/ui/States'
import { toApiError } from '@/lib/api/client'
import { platformColor } from '@/features/analytics/components'
import { useUi } from '@/stores/ui'

/** The three stages a reader is asked to move through, and nothing else. */
export type Stage = 'login' | 'parent' | 'accounts'

/**
 * INTEGRATION-DATASOURCE-WIZARD-001 §3 — one connection experience, three stages, one receipt.
 *
 * ## Why three and not five
 *
 * The journey used to be: press connect on a card, leave for a consent screen, come back to a page
 * with a banner, find «resume», choose a business, choose accounts, choose a project, review, and
 * land back on a list with no statement of what had happened. Three of those are the provider's
 * actual questions — who are you, which of your businesses, which of its accounts — and the rest is
 * this product asking a reader to navigate it.
 *
 * So the destination is settled BEFORE the provider is ever opened, rides the OAuth state across,
 * and comes back on the callback. «Which client is this for» is asked once, on the way in, where it
 * costs nothing, instead of after the fact where it is a fourth stage between somebody and the thing
 * they came to do.
 *
 * ## The organisation stage is the provider's, or it does not exist
 *
 * Meta has business portfolios, TikTok has business centres, Snapchat has organisations, Google has
 * manager accounts. A provider with no such layer does not get an invented one — the stage is
 * removed from the stepper rather than shown empty, because an empty step is a dead end and not a
 * question.
 *
 * ## Success is a receipt, not a fourth stage
 *
 * The stepper ends at the accounts. What follows states what was done and offers the way back, and
 * it does not pretend a completed journey is still in progress.
 */
export function ConnectionFlow({ mode, provider, connection, projectId, onClose, onDone }: {
  /** `connect` runs all three stages; `manage` reopens the selector on a connection that exists. */
  mode: 'connect' | 'manage'
  provider: ConnectableProvider | null
  connection: HubConnection | null
  /** Fixed for the whole flow once chosen — through OAuth and back. */
  projectId: string | null
  onClose: () => void
  onDone: () => void
}) {
  const ar = useUi((s) => s.locale) === 'ar'

  const [destination, setDestination] = useState<string | null>(projectId)
  const [stage, setStage] = useState<Stage>(
    mode === 'manage' || connection !== null ? (provider?.has_parent ?? connection?.has_parent ? 'parent' : 'accounts') : 'login',
  )
  const [finished, setFinished] = useState(false)

  const hasParent = connection?.has_parent ?? provider?.has_parent ?? false
  const stages: Stage[] = mode === 'manage'
    ? (hasParent ? ['parent', 'accounts'] : ['accounts'])
    : (hasParent ? ['login', 'parent', 'accounts'] : ['login', 'accounts'])

  const label = connection !== null
    ? (ar ? connection.label_ar : connection.label)
    : provider !== null ? (ar ? provider.label_ar : provider.label) : ''

  const providerKey = connection?.provider ?? provider?.key ?? ''

  return (
    <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4 sm:p-8" role="dialog" aria-modal="true">
      <div className="w-full max-w-3xl rounded-2xl border border-border bg-surface shadow-2xl" data-testid="connection-flow">
        <header className="flex flex-col gap-4 border-b border-border p-5">
          <div className="flex items-center gap-3">
            <span className="h-8 w-1 shrink-0 rounded-full" style={{ background: platformColor(providerKey) }} aria-hidden />
            <h2 className="min-w-0 flex-1 truncate text-base font-bold text-text-primary">
              {finished
                ? (ar ? `تم ربط ${label}` : `${label} connected`)
                : mode === 'manage'
                  ? (ar ? `إدارة الحسابات — ${label}` : `Manage accounts — ${label}`)
                  : (ar ? `ربط ${label}` : `Connect ${label}`)}
            </h2>
            <Button variant="ghost" onClick={onClose} data-testid="flow-close">{ar ? 'إغلاق' : 'Close'}</Button>
          </div>

          {/* The map disappears once the journey is over: a receipt is not a step. */}
          {!finished && <Stepper stages={stages} current={stage} ar={ar} />}
        </header>

        <div className="p-5">
          {finished ? (
            <Receipt ar={ar} label={label} onDone={onDone} />
          ) : mode === 'manage' && destination === null ? (
            /*
             * Managing needs a client, and nothing decided one.
             *
             * Guessing was tried and was wrong: an authorisation is lent to several clients, and
             * picking whichever sorted first pointed a reader at a project they could not manage and
             * answered 403. A question with more than one honest answer is asked.
             */
            <ChooseClient ar={ar} onChoose={setDestination} />
          ) : stage === 'login' ? (
            <Login
              ar={ar}
              label={label}
              providerKey={providerKey}
              destination={destination}
              onDestination={setDestination}
            />
          ) : connection === null ? (
            <Skeleton className="h-48 w-full" />
          ) : (
            <ConnectionWizard
              connectionId={connection.id}
              chrome={false}
              manageProjectId={mode === 'manage' ? destination : null}
              destinationProjectId={mode === 'manage' ? null : destination}
              onStepChange={(step) => {
                if (step === 'done') setFinished(true)
                else if (step === 'parent') setStage('parent')
                else setStage('accounts')
              }}
              onClose={onClose}
            />
          )}
        </div>
      </div>
    </div>
  )
}

/** Which client's selection is being edited, when nothing upstream could say. */
function ChooseClient({ ar, onChoose }: { ar: boolean; onChoose: (id: string) => void }) {
  const projects = useQuery({ queryKey: ['projects'], queryFn: () => listProjects() })

  return (
    <div className="flex flex-col gap-3" data-testid="flow-choose-client">
      <h3 className="text-sm font-bold text-text-primary">
        {ar ? 'حسابات أي عميل تريد إدارتها؟' : 'Whose accounts are you managing?'}
      </h3>
      <p className="text-xs text-text-secondary">
        {ar
          ? 'هذه المصادقة تغذّي أكثر من عميل، والاختيار هنا يحدّد الحسابات التي ستُحفظ لأيّهم.'
          : 'This authorisation feeds more than one client, and the selection you save belongs to one of them.'}
      </p>

      {projects.isLoading ? (
        <Skeleton className="h-10 w-full" />
      ) : (
        <select
          defaultValue=""
          onChange={(e) => { if (e.target.value !== '') onChoose(e.target.value) }}
          aria-label={ar ? 'العميل' : 'Client'}
          data-testid="flow-manage-destination"
          className="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-text-primary focus:border-brand-500 focus:outline-none"
        >
          <option value="">{ar ? 'اختر العميل' : 'Choose a client'}</option>
          {(projects.data ?? []).map((p) => (
            <option key={p.id} value={p.id}>{p.name}</option>
          ))}
        </select>
      )}
    </div>
  )
}

/**
 * Stage one: who you are at the provider, and what this product will read with it.
 *
 * The consent screen itself says what is being granted in the provider's own words, which nobody
 * reads because they have already decided. This says it in ours, before they leave, and it names the
 * client the accounts will belong to — the one decision that is expensive to change afterwards.
 */
function Login({ ar, label, providerKey, destination, onDestination }: {
  ar: boolean
  label: string
  providerKey: string
  destination: string | null
  onDestination: (id: string) => void
}) {
  const projects = useQuery({ queryKey: ['projects'], queryFn: () => listProjects() })

  const start = useMutation({
    mutationFn: () => startPlatformOAuth(providerKey, null, destination),
    onSuccess: ({ authorization_url: url }) => { window.location.assign(url) },
  })

  return (
    <div className="flex flex-col gap-5" data-testid="flow-step-login">
      <section className="flex flex-col gap-2">
        <h3 className="text-sm font-bold text-text-primary">
          {ar ? 'لأي عميل هذه الحسابات؟' : 'Which client are these accounts for?'}
        </h3>
        <p className="text-xs text-text-secondary">
          {/* Asked here because it is free here and expensive afterwards. */}
          {ar
            ? 'يُحدَّد الآن ويبقى ثابتًا خلال الربط كله، فلا يُسأل عنه بعد العودة من المنصة.'
            : 'Settled now and fixed for the whole flow, so nothing asks again after you return.'}
        </p>

        {projects.isLoading ? (
          <Skeleton className="h-10 w-full" />
        ) : (
          <select
            value={destination ?? ''}
            onChange={(e) => onDestination(e.target.value)}
            aria-label={ar ? 'العميل' : 'Client'}
            data-testid="flow-destination"
            className="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-text-primary focus:border-brand-500 focus:outline-none"
          >
            <option value="">{ar ? 'اختر العميل' : 'Choose a client'}</option>
            {(projects.data ?? []).map((p) => (
              <option key={p.id} value={p.id}>{p.name}</option>
            ))}
          </select>
        )}
      </section>

      <section className="flex flex-col gap-2 rounded-xl bg-surface-secondary p-4">
        <h3 className="flex items-center gap-2 text-sm font-bold text-text-primary">
          <ShieldCheck size={15} aria-hidden /> {ar ? `ما سيقرأه كامبينز هب من ${label}` : `What CampaignsHub will read from ${label}`}
        </h3>
        <ul className="flex flex-col gap-1 text-xs text-text-secondary">
          <li>· {ar ? 'قائمة حساباتك الإعلانية وأسماؤها وعملاتها.' : 'Your ad accounts, their names and their currencies.'}</li>
          <li>· {ar ? 'حملاتك وأرقام أدائها للفترات التي تختارها.' : 'Your campaigns and their performance figures.'}</li>
          <li>
            {/* The negative matters more than the positives: it is the fear that stops people. */}
            · {ar
              ? 'قراءة فقط — لا يُنشئ كامبينز هب حملة ولا يعدّل ميزانية ولا ينفق شيئًا.'
              : 'Read only — CampaignsHub never creates a campaign, changes a budget or spends anything.'}
          </li>
          <li>
            · {ar
              ? 'لن يُزامَن أي حساب لم تختره في الخطوة الأخيرة.'
              : 'No account is ever synced unless you choose it in the last stage.'}
          </li>
        </ul>
      </section>

      {start.isError && (
        <p className="text-sm text-danger" data-testid="flow-login-error">{toApiError(start.error).message}</p>
      )}

      <div className="flex justify-end">
        <Button
          disabled={destination === null || destination === ''}
          loading={start.isPending}
          onClick={() => start.mutate()}
          data-testid="flow-authorise"
        >
          {ar ? `المتابعة إلى ${label}` : `Continue to ${label}`}
          {ar ? <ArrowLeft size={15} /> : <ArrowRight size={15} />}
        </Button>
      </div>
    </div>
  )
}

/**
 * The completion receipt.
 *
 * The wizard above has already stated the facts — provider, accounts, project, and the first sync's
 * live state — so this does not restate them. What it adds is the one thing the old ending did not
 * have: an obvious way back, labelled with where it goes.
 */
function Receipt({ ar, label, onDone }: { ar: boolean; label: string; onDone: () => void }) {
  return (
    <div className="flex flex-col items-center gap-4 py-2 text-center" data-testid="flow-receipt">
      <span className="flex h-12 w-12 items-center justify-center rounded-full bg-[var(--positive-background)] text-success" aria-hidden>
        <Check size={24} />
      </span>

      <div>
        <p className="text-base font-bold text-text-primary">
          {ar ? `تم ربط ${label} بنجاح` : `${label} is connected`}
        </p>
        <p className="mt-1 text-xs text-text-secondary">
          {ar
            ? 'بدأت المزامنة الأولى للحسابات التي اخترتها. يمكنك متابعة حالتها من مركز التكاملات.'
            : 'The first sync has started for the accounts you chose. You can follow it from the Connection Hub.'}
        </p>
      </div>

      <Button onClick={onDone} data-testid="flow-back-to-hub">
        {ar ? 'العودة إلى مركز التكاملات' : 'Back to the Connection Hub'}
      </Button>
    </div>
  )
}

function Stepper({ stages, current, ar }: { stages: Stage[]; current: Stage; ar: boolean }) {
  const at = stages.indexOf(current)
  if (stages.length < 2) return null

  return (
    <ol className="flex flex-wrap items-center gap-x-2 gap-y-1.5" data-testid="flow-stepper">
      {stages.map((stage, index) => {
        const done = index < at
        const now = index === at

        return (
          <li key={stage} className="flex items-center gap-2">
            <span
              data-testid={`flow-stepper-${stage}`}
              data-state={done ? 'done' : now ? 'current' : 'todo'}
              aria-current={now ? 'step' : undefined}
              className={`inline-flex items-center gap-1.5 rounded-[var(--radius-pill)] px-2.5 py-1 text-xs font-semibold ${
                now
                  ? 'bg-primary text-on-primary'
                  : done
                    ? 'bg-surface-secondary text-text-primary'
                    : 'bg-surface-secondary text-text-muted'
              }`}
            >
              <span className="inline-flex h-4 w-4 items-center justify-center" aria-hidden>
                {done ? <Check size={12} /> : <Num>{index + 1}</Num>}
              </span>
              {ar ? STAGE_LABELS[stage].ar : STAGE_LABELS[stage].en}
            </span>

            {/* A dot, not an arrow: an arrow points one way and this renders in both. */}
            {index < stages.length - 1 && <span className="text-text-muted" aria-hidden>·</span>}
          </li>
        )
      })}
    </ol>
  )
}

const STAGE_LABELS: Record<Stage, { ar: string; en: string }> = {
  login: { ar: 'تسجيل الدخول', en: 'Sign in' },
  parent: { ar: 'المنظمة', en: 'Organisation' },
  accounts: { ar: 'الحسابات الإعلانية', en: 'Ad accounts' },
}

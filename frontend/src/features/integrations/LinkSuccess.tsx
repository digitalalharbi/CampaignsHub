import { useEffect, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Check } from 'lucide-react'
import { fetchProjectLink, type LinkMoneyState, type ProjectLinkAccount } from './api'
import { accountHealthLabel } from './accountHealth'
import { Button } from '@/components/ui/Button'
import { ErrorState, Skeleton } from '@/components/ui/States'
import { useUi } from '@/stores/ui'

/** Seconds before the journey returns to the project by itself, unless the reader stays. */
export const AUTO_RETURN_SECONDS = 8

/**
 * What happens to an account's money, in one sentence the reader can act on.
 *
 * The rule is the pipeline's and is not decided here (row currency → account currency → ask the provider
 * once → else withhold; reporting currency USD). The server says which case the account is in; this says it.
 */
export function moneySentence(account: ProjectLinkAccount, reporting: string, ar: boolean): string {
  const currency = account.currency ?? ''
  const sentences: Record<LinkMoneyState, { ar: string; en: string }> = {
    reporting: {
      ar: `العملة ${currency} — تُعرض المبالغ كما هي.`,
      en: `Billed in ${currency} — amounts are shown as they are.`,
    },
    converted: {
      ar: `العملة ${currency} — تُحوَّل المبالغ إلى ${reporting} ويُحفظ المبلغ الأصلي.`,
      en: `Billed in ${currency} — amounts are converted to ${reporting} and the original is kept.`,
    },
    withheld_no_rate: {
      ar: `العملة ${currency} — لا يوجد سعر صرف مسجَّل إلى ${reporting} بعد، فتُحجب المبالغ حتى يُسجَّل السعر. بقية الأرقام تظهر.`,
      en: `Billed in ${currency} — no exchange rate to ${reporting} is on file yet, so money is withheld until one is. Other figures appear.`,
    },
    withheld_unknown: {
      ar: 'لم تذكر المنصة عملة هذا الحساب بعد. تُسأل عنها عند المزامنة، وتُحجب المبالغ حتى تُعرف — لا تُفترض عملة.',
      en: 'The platform has not stated this account’s currency yet. It is asked at sync, and money is withheld until it is known — no currency is assumed.',
    },
  }

  return ar ? sentences[account.money.state].ar : sentences[account.money.state].en
}

/**
 * INTEG-PROJECT-LINK-001 — «تم الربط»: the end of the journey, and the way back to where it began.
 *
 * It states what was done in the reader's terms — which project, which platform, whose platform account,
 * which organisation and account, what happens to its money, and where the first sync stands — and then
 * returns to the project: one primary button, and by itself after a few seconds unless the reader stays.
 * Everything on it is read from the project's own link endpoint, so it lists THIS project's accounts on
 * this connection and nothing else.
 */
export function LinkSuccess({ projectId, connectionId, onReturn }: {
  projectId: string
  connectionId: string
  onReturn: () => void
}) {
  const ar = useUi((s) => s.locale) === 'ar'
  const [staying, setStaying] = useState(false)
  const [left, setLeft] = useState(AUTO_RETURN_SECONDS)

  const link = useQuery({
    queryKey: ['project-link', projectId, connectionId],
    queryFn: () => fetchProjectLink(projectId, connectionId),
    retry: false,
    // The first sync is still running for a moment after the link; ask again while it is.
    refetchInterval: (query) => (query.state.data?.accounts.some((a) => a.health === 'pending_first_sync') ? 5000 : false),
  })

  const ready = link.isSuccess
  useEffect(() => {
    if (!ready || staying) return
    if (left <= 0) { onReturn(); return }
    const timer = window.setTimeout(() => setLeft((n) => n - 1), 1000)
    return () => window.clearTimeout(timer)
  }, [ready, staying, left, onReturn])

  if (link.isLoading) return <Skeleton className="h-48 w-full" />
  if (link.isError || link.data === undefined) {
    return (
      <div className="flex flex-col gap-3" data-testid="link-success-error">
        <ErrorState
          title={ar ? 'تم الربط، لكن تعذّر عرض ملخصه.' : 'The link was made, but its summary could not be shown.'}
          onRetry={() => void link.refetch()}
        />
        <Button onClick={onReturn} data-testid="link-return">{ar ? 'العودة إلى المشروع' : 'Back to the project'}</Button>
      </div>
    )
  }

  const { project, connection, accounts, reporting_currency: reporting } = link.data
  const platformAccount = connection.platform_account === null ? null : (connection.platform_account.email ?? connection.platform_account.label)
  const organisations = [...new Set(accounts.map((a) => a.organisation).filter((name): name is string => name !== null && name !== ''))]

  return (
    <div className="flex min-w-0 flex-col gap-4" data-testid="link-success" onPointerDown={() => setStaying(true)} onKeyDown={() => setStaying(true)}>
      <div className="flex items-center gap-3">
        <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-[var(--positive-background)] text-success" aria-hidden>
          <Check size={22} />
        </span>
        <div className="min-w-0">
          <h3 className="text-base font-bold text-text-primary" data-testid="link-success-title">{ar ? 'شكرًا، تم الربط' : 'Thank you, the link is made'}</h3>
          <p className="truncate text-xs text-text-secondary">
            {ar ? `${connection.label_ar} ← ${project.name}` : `${connection.label} → ${project.name}`}
          </p>
        </div>
      </div>

      <dl className="grid min-w-0 grid-cols-1 gap-x-4 gap-y-2 rounded-xl bg-surface-secondary p-3 text-sm sm:grid-cols-2">
        <Fact label={ar ? 'المشروع' : 'Project'} value={project.name} testId="link-fact-project" />
        <Fact label={ar ? 'المنصة' : 'Platform'} value={ar ? connection.label_ar : connection.label} testId="link-fact-platform" />
        <Fact
          label={ar ? 'حساب المنصة' : 'Platform account'}
          value={platformAccount ?? (ar ? 'لم يُسجَّل' : 'Not recorded')}
          testId="link-fact-platform-account"
        />
        {organisations.length > 0 && (
          <Fact label={ar ? 'المنظمة' : 'Organisation'} value={organisations.join('، ')} testId="link-fact-organisation" />
        )}
      </dl>

      {accounts.length === 0 ? (
        <p className="rounded-lg border border-dashed border-border p-3 text-center text-xs text-text-secondary" data-testid="link-success-none">
          {ar ? 'لم يُربط أي حساب بهذا المشروع من هذا التفويض.' : 'No account of this authorisation is linked to this project.'}
        </p>
      ) : (
        <ul className="flex min-w-0 flex-col gap-2" data-testid="link-success-accounts">
          {accounts.map((account) => (
            <li key={account.id} className="min-w-0 rounded-xl border border-border p-3" data-testid={`link-account-${account.id}`}>
              <p className="flex min-w-0 flex-wrap items-center justify-between gap-2">
                <span className="min-w-0 truncate text-sm font-bold text-text-primary">{account.name}</span>
                <span className="shrink-0 text-xs text-text-secondary" data-testid={`link-account-sync-${account.id}`}>
                  {accountHealthLabel(account.health ?? 'pending_first_sync', ar)}
                </span>
              </p>
              <p className="mt-1 text-xs text-text-secondary" data-testid={`link-account-money-${account.id}`} data-money={account.money.state}>
                {moneySentence(account, reporting, ar)}
              </p>
            </li>
          ))}
        </ul>
      )}

      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-[11px] text-text-muted" data-testid="link-auto-return" aria-live="polite">
          {staying
            ? (ar ? 'باقٍ هنا. اضغط الزر للعودة إلى المشروع.' : 'Staying here. Press the button to go back to the project.')
            : (ar
                ? <>العودة إلى المشروع تلقائيًا خلال <span className="tnum">{Math.max(left, 0).toLocaleString('en-US')}</span> ث</>
                : <>Returning to the project in <span className="tnum">{Math.max(left, 0).toLocaleString('en-US')}</span> s</>)}
        </p>
        <Button onClick={onReturn} data-testid="link-return" autoFocus>
          {ar ? 'العودة إلى المشروع' : 'Back to the project'}
        </Button>
      </div>
    </div>
  )
}

function Fact({ label, value, testId }: { label: string; value: string; testId: string }) {
  return (
    <div className="min-w-0">
      <dt className="text-[11px] text-text-muted">{label}</dt>
      <dd className="truncate font-semibold text-text-primary" data-testid={testId}>{value}</dd>
    </div>
  )
}

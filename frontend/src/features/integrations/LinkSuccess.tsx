import { useEffect, useState, type ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import { ArrowLeft, ArrowRight, Building2, Check, FolderKanban, Wallet } from 'lucide-react'
import { fetchProjectLink, type LinkMoneyState, type ProjectLinkAccount } from './api'
import { accountHealthBadgeTone, accountHealthLabel } from './accountHealth'
import { ProviderMark } from '@/components/ProviderMark'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { ErrorState, Skeleton } from '@/components/ui/States'
import { useUi } from '@/stores/ui'

/** Seconds before the journey returns to the project by itself, unless the reader stays. */
export const AUTO_RETURN_SECONDS = 8

/**
 * What happens to an account's money, as a MARK rather than a sentence (Owner 2026-11-06: shortcuts and
 * form over text). The rule is the pipeline's and is not decided here; the server says which case the
 * account is in. The full sentence lives in the chip's title and aria-label, never in the layout.
 */
export const MONEY_MARK: Record<LinkMoneyState, { tone: 'success' | 'info' | 'warning'; ar: string; en: string }> = {
  reporting: { tone: 'success', ar: 'تُعرض المبالغ كما هي', en: 'Amounts shown as billed' },
  converted: { tone: 'info', ar: 'تُحوَّل المبالغ ويُحفظ الأصل', en: 'Converted, original kept' },
  withheld_no_rate: { tone: 'warning', ar: 'لا سعر صرف: المبالغ محجوبة', en: 'No rate: money withheld' },
  withheld_unknown: { tone: 'warning', ar: 'العملة غير معروفة: المبالغ محجوبة', en: 'Currency unknown: money withheld' },
}

/** «SAR → USD», «USD», «EUR ✕ USD» or «؟» — the whole money answer in a code chip. */
export function moneyChipText(account: ProjectLinkAccount, reporting: string): string {
  const currency = account.currency ?? ''
  switch (account.money.state) {
    case 'reporting': return currency
    case 'converted': return `${currency} → ${reporting}`
    case 'withheld_no_rate': return `${currency} ✕ ${reporting}`
    default: return '?'
  }
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
          title={ar ? 'تم الربط. تعذّر عرض الملخص.' : 'Linked. Summary unavailable.'}
          onRetry={() => void link.refetch()}
        />
        <Button onClick={onReturn} data-testid="link-return">{ar ? 'العودة إلى المشروع' : 'Back to the project'}</Button>
      </div>
    )
  }

  const { project, connection, accounts, reporting_currency: reporting } = link.data
  const platformAccount = connection.platform_account === null ? null : (connection.platform_account.email ?? connection.platform_account.label)
  const organisations = [...new Set(accounts.map((a) => a.organisation).filter((name): name is string => name !== null && name !== ''))]
  const Arrow = ar ? ArrowLeft : ArrowRight
  // INTEG-PROJECT-LINK-STORES-GA4-001 — the same ending for a store and a property, named as what they are.
  const kind = connection.kind ?? 'advertising'
  const linkedWord = kind === 'commerce' ? { ar: 'المتاجر', en: 'Stores' } : kind === 'measurement' ? { ar: 'الخصائص', en: 'Properties' } : { ar: 'الحسابات', en: 'Accounts' }

  return (
    <div className="flex min-w-0 flex-col gap-4" data-testid="link-success" onPointerDown={() => setStaying(true)} onKeyDown={() => setStaying(true)}>
      <div className="flex flex-col items-center gap-2 text-center">
        <span className="link-check flex h-12 w-12 items-center justify-center rounded-full bg-[var(--positive-background)] text-success motion-safe:animate-[pulse_1.2s_ease-out_1]" aria-hidden>
          <Check size={24} />
        </span>
        <h3 className="text-base font-bold text-text-primary" data-testid="link-success-title">{ar ? 'تم الربط' : 'Linked'}</h3>
      </div>

      {/*
        The link, DRAWN: project ⟶ platform ⟶ organisation ⟶ account, as connected nodes. A reader
        sees what was joined to what without reading a sentence; the names are the only words.
      */}
      <ol className="flex min-w-0 flex-wrap items-center justify-center gap-x-1.5 gap-y-2" data-testid="link-chain" aria-label={ar ? 'سلسلة الربط' : 'Link chain'}>
        <Node testId="link-fact-project" icon={<FolderKanban size={15} aria-hidden />} label={ar ? 'المشروع' : 'Project'} value={project.name} />
        <li aria-hidden className="text-text-muted"><Arrow size={14} /></li>
        <Node
          testId="link-fact-platform"
          icon={<ProviderMark provider={connection.provider} size={16} />}
          label={ar ? 'المنصة' : 'Platform'}
          value={ar ? connection.label_ar : connection.label}
          // A store's authorisation and a Google sign-in record no platform account here: nothing is said about one.
          sub={platformAccount ?? (kind === 'advertising' ? (ar ? 'حساب المنصة غير مسجَّل' : 'Platform account not recorded') : undefined)}
          subTestId="link-fact-platform-account"
        />
        {organisations.length > 0 && (
          <>
            <li aria-hidden className="text-text-muted"><Arrow size={14} /></li>
            <Node testId="link-fact-organisation" icon={<Building2 size={15} aria-hidden />} label={ar ? 'المنظمة' : 'Organisation'} value={organisations.join('، ')} />
          </>
        )}
        <li aria-hidden className="text-text-muted"><Arrow size={14} /></li>
        <Node
          testId="link-fact-accounts"
          icon={<Wallet size={15} aria-hidden />}
          label={ar ? linkedWord.ar : linkedWord.en}
          value={accounts.length.toLocaleString('en-US')}
        />
      </ol>

      {accounts.length === 0 ? (
        <p className="rounded-lg border border-dashed border-border p-3 text-center text-xs text-text-secondary" data-testid="link-success-none">
          {ar ? 'لم يُربط حساب.' : 'No account linked.'}
        </p>
      ) : (
        <ul className="flex min-w-0 flex-col gap-1.5" data-testid="link-success-accounts">
          {accounts.map((account) => {
            const mark = MONEY_MARK[account.money.state]
            const health = account.health ?? 'pending_first_sync'
            return (
              <li key={account.id} className="flex min-w-0 flex-wrap items-center gap-2 rounded-xl border border-border px-3 py-2" data-testid={`link-account-${account.id}`}>
                <span className="min-w-0 flex-1 truncate text-sm font-bold text-text-primary">{account.name}</span>
                {/* A property states its currency on its first read, not at the link: no money mark is drawn for one. */}
                {kind !== 'measurement' && (
                  <span
                    title={ar ? mark.ar : mark.en}
                    aria-label={`${moneyChipText(account, reporting)} — ${ar ? mark.ar : mark.en}`}
                    data-testid={`link-account-money-${account.id}`}
                    data-money={account.money.state}
                  >
                    <Badge tone={mark.tone}><span className="tnum" dir="ltr">{moneyChipText(account, reporting)}</span></Badge>
                  </span>
                )}
                <span data-testid={`link-account-sync-${account.id}`}>
                  <Badge tone={accountHealthBadgeTone(health)}>{accountHealthLabel(health, ar)}</Badge>
                </span>
              </li>
            )
          })}
        </ul>
      )}

      <div className="flex flex-col items-center gap-1.5">
        <Button onClick={onReturn} data-testid="link-return" autoFocus>
          {ar ? 'العودة إلى المشروع' : 'Back to the project'}
        </Button>
        {/* The countdown is a number, not a sentence; staying is any press or key. */}
        {!staying && (
          <p className="text-[11px] text-text-muted" data-testid="link-auto-return" aria-live="polite" aria-label={ar ? 'العودة التلقائية بالثواني' : 'Automatic return, seconds'}>
            <span className="tnum">{Math.max(left, 0).toLocaleString('en-US')}</span> {ar ? 'ث' : 's'}
          </p>
        )}
      </div>
    </div>
  )
}

/** One node of the drawn link: an icon, a one-word role for assistive technology, and the name. */
function Node({ icon, label, value, testId, sub, subTestId }: {
  icon: ReactNode
  label: string
  value: string
  testId: string
  sub?: string
  subTestId?: string
}) {
  return (
    <li className="flex min-w-0 max-w-full items-center gap-2 rounded-xl border border-border bg-surface-secondary px-2.5 py-1.5" aria-label={`${label}: ${value}`}>
      <span className="shrink-0 text-text-secondary">{icon}</span>
      <span className="flex min-w-0 flex-col">
        <span className="truncate text-xs font-bold text-text-primary" data-testid={testId}>{value}</span>
        {sub !== undefined && <span className="truncate text-[10px] text-text-muted" data-testid={subTestId}>{sub}</span>}
      </span>
    </li>
  )
}

import { useEffect, useState } from 'react'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, ArrowRight, Building2, Search } from 'lucide-react'
import { confirmLinkAccount, fetchLinkAccounts, fetchLinkOrganisations, removeConnection } from './api'
import { LinkSuccess } from './LinkSuccess'
import { LinkSteps, type LinkStep } from './ProjectLinkFlow'
import { useDebouncedValue } from '@/components/forms/useTypeahead'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { ErrorState, Skeleton } from '@/components/ui/States'
import { ProviderMark } from '@/components/ProviderMark'
import { toApiError } from '@/lib/api/client'
import { platformLabel } from '@/lib/platforms'
import { useUi } from '@/stores/ui'

/**
 * INTEG-ON-DEMAND-DISCOVERY-001 (Owner 2026-11-06) — the journey after the platform sign-in:
 *
 *     منظمة → حساب → تم
 *
 * The organisations are asked for when this opens; the accounts of ONE organisation when it is chosen; and
 * the one account that is confirmed is the only thing stored. Lists filter as the reader types. One primary
 * action per step, no explanatory prose: the step says what it is.
 *
 * Everything is keyed by the project, so another project's journey shares nothing with this one.
 */
export function ProjectAccountPicker({ projectId, projectName, connection, onClose, onReturn }: {
  projectId: string
  projectName: string | null
  connection: { id: string; provider: string }
  onClose: () => void
  onReturn: () => void
}) {
  const ar = useUi((s) => s.locale) === 'ar'
  const qc = useQueryClient()
  const Arrow = ar ? ArrowLeft : ArrowRight

  const [step, setStep] = useState<LinkStep>('organisation')
  /** `undefined` — not chosen yet; `null` — «no organisation», the directly-held accounts. */
  const [organisation, setOrganisation] = useState<string | null | undefined>(undefined)
  const [account, setAccount] = useState<string | null>(null)
  const [orgSearch, setOrgSearch] = useState('')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const q = useDebouncedValue(search.trim(), 250)

  const organisations = useQuery({
    queryKey: ['project-link', projectId, connection.id, 'organisations'],
    queryFn: () => fetchLinkOrganisations(projectId, connection.id),
    retry: false,
  })
  const list = organisations.data?.organisations ?? null

  // One organisation is not a question: it is chosen and the step is passed.
  useEffect(() => {
    if (list !== null && list.length === 1 && organisation === undefined) {
      setOrganisation(list[0].id)
      setStep('account')
    }
  }, [list, organisation])

  const accounts = useQuery({
    queryKey: ['project-link', projectId, connection.id, 'accounts', organisation ?? '', q, page],
    queryFn: () => fetchLinkAccounts(projectId, connection.id, { organisation: organisation ?? null, q, page }),
    enabled: step === 'account' && organisation !== undefined,
    placeholderData: keepPreviousData,
    retry: false,
  })

  const confirm = useMutation({
    mutationFn: () => confirmLinkAccount({ projectId, connectionId: connection.id, externalId: account ?? '', organisation: organisation ?? null }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['project-bindings', projectId] })
      void qc.invalidateQueries({ queryKey: ['connection-hub'] })
      void qc.invalidateQueries({ queryKey: ['project-link', projectId] })
      setStep('done')
    },
  })

  // «إلغاء» leaves nothing behind: a sign-in nobody confirmed an account on is removed by the server.
  const cancel = useMutation({
    mutationFn: () => removeConnection(connection.id, { unconfirmedOnly: true }),
    onSettled: onClose,
  })

  const orgName = (o: { id: string | null; name: string | null }) => o.name ?? (o.id === null ? (ar ? 'مباشر' : 'Direct') : o.id)
  const shownOrganisations = (list ?? []).filter((o) => orgName(o).toLowerCase().includes(orgSearch.trim().toLowerCase()))
  const multiple = (list?.length ?? 0) > 1
  const steps: LinkStep[] = multiple || list === null ? ['organisation', 'account', 'done'] : ['account', 'done']
  const total = accounts.data?.total ?? 0
  const perPage = accounts.data?.per_page ?? 25
  const pages = Math.max(1, Math.ceil(total / perPage))
  const refusal = confirm.isError ? toApiError(confirm.error) : null

  const canAdvance = step === 'organisation' ? organisation !== undefined : step === 'account' ? account !== null && !confirm.isPending : false
  const advance = () => {
    if (step === 'organisation') { setAccount(null); setSearch(''); setPage(1); setStep('account') } else if (step === 'account') confirm.mutate()
  }

  return (
    <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4 sm:p-8" role="dialog" aria-modal="true" aria-labelledby="project-picker-title">
      <form
        className="flex w-full max-w-lg flex-col gap-4 rounded-2xl border border-border bg-surface p-5 shadow-2xl"
        data-testid="project-account-picker"
        onSubmit={(e) => { e.preventDefault(); if (canAdvance) advance() }}
      >
        <header className="flex items-center gap-3">
          <ProviderMark provider={connection.provider} size={26} />
          <div className="min-w-0 flex-1">
            <h2 id="project-picker-title" className="truncate text-base font-bold text-text-primary">{platformLabel(connection.provider, ar)}</h2>
            <p className="truncate text-xs text-text-secondary" data-testid="link-project">{projectName ?? '…'}</p>
          </div>
          {step !== 'done' && (
            <Button type="button" variant="ghost" loading={cancel.isPending} onClick={() => cancel.mutate()} data-testid="picker-cancel">
              {ar ? 'إلغاء' : 'Cancel'}
            </Button>
          )}
        </header>

        <LinkSteps steps={steps} current={step} ar={ar} />

        {step === 'done' ? (
          <LinkSuccess projectId={projectId} connectionId={connection.id} onReturn={onReturn} />
        ) : organisations.isLoading ? (
          <Skeleton className="h-40 w-full" />
        ) : organisations.isError ? (
          <ErrorState title={toApiError(organisations.error).message} onRetry={() => void organisations.refetch()} />
        ) : step === 'organisation' ? (
          <fieldset className="flex flex-col gap-1.5" data-testid="picker-organisations">
            <legend className="sr-only">{ar ? 'منظمة' : 'Organisation'}</legend>
            {(list?.length ?? 0) > 6 && (
              <SearchBox value={orgSearch} onChange={setOrgSearch} label={ar ? 'بحث' : 'Search'} testId="picker-organisation-search" />
            )}
            <div className="flex max-h-80 flex-col gap-1.5 overflow-y-auto">
              {shownOrganisations.map((o) => (
                <Row
                  key={o.id ?? ''}
                  name="link-organisation"
                  checked={organisation === o.id}
                  onChange={() => setOrganisation(o.id)}
                  testId={`picker-organisation-${o.id ?? 'direct'}`}
                  icon={<Building2 size={18} className="shrink-0 text-text-secondary" aria-hidden />}
                  title={orgName(o)}
                />
              ))}
              {shownOrganisations.length === 0 && <Empty ar={ar} />}
            </div>
          </fieldset>
        ) : (
          <fieldset className="flex flex-col gap-1.5" data-testid="picker-accounts">
            <legend className="sr-only">{ar ? 'حساب' : 'Account'}</legend>
            <SearchBox value={search} onChange={(v) => { setSearch(v); setPage(1) }} label={ar ? 'بحث' : 'Search'} testId="picker-account-search" />
            {accounts.isLoading ? (
              <Skeleton className="h-40 w-full" />
            ) : accounts.isError ? (
              <ErrorState title={toApiError(accounts.error).message} onRetry={() => void accounts.refetch()} />
            ) : (
              <div className="flex max-h-80 flex-col gap-1.5 overflow-y-auto">
                {(accounts.data?.accounts ?? []).map((a) => (
                  <Row
                    key={a.external_id}
                    name="link-account"
                    checked={account === a.external_id}
                    disabled={a.linked === 'elsewhere'}
                    onChange={() => setAccount(a.external_id)}
                    testId={`picker-account-${a.external_id}`}
                    title={a.name}
                    end={(
                      <>
                        {a.linked === 'here' && <Badge tone="success">{ar ? 'مرتبط' : 'Linked'}</Badge>}
                        {a.linked === 'elsewhere' && <Badge tone="warning">{ar ? 'مشروع آخر' : 'Other project'}</Badge>}
                        {a.currency !== null && <Badge tone="neutral" data-testid={`picker-currency-${a.external_id}`}>{a.currency}</Badge>}
                      </>
                    )}
                  />
                ))}
                {(accounts.data?.accounts.length ?? 0) === 0 && <Empty ar={ar} />}
              </div>
            )}
            {pages > 1 && (
              <div className="flex items-center justify-between text-xs text-text-secondary" data-testid="picker-pages">
                <Button type="button" variant="ghost" size="sm" disabled={page <= 1} onClick={() => setPage(page - 1)} data-testid="picker-prev">{ar ? 'السابق' : 'Previous'}</Button>
                <span className="tnum" dir="ltr">{page.toLocaleString('en-US')} / {pages.toLocaleString('en-US')}</span>
                <Button type="button" variant="ghost" size="sm" disabled={page >= pages} onClick={() => setPage(page + 1)} data-testid="picker-more">{ar ? 'التالي' : 'Next'}</Button>
              </div>
            )}
            {refusal !== null && (
              <p role="alert" className="text-xs font-semibold text-danger" data-testid="picker-refused">
                {refusal.status === 409 ? (ar ? 'مرتبط بمشروع آخر' : 'Linked to another project') : refusal.message}
              </p>
            )}
          </fieldset>
        )}

        {step !== 'done' && (
          <footer className="flex items-center justify-between gap-2">
            {step === 'account' && multiple ? (
              <Button type="button" variant="ghost" onClick={() => setStep('organisation')} data-testid="picker-back">{ar ? 'رجوع' : 'Back'}</Button>
            ) : <span />}
            <Button type="submit" disabled={!canAdvance} loading={confirm.isPending} data-testid="link-next">
              {ar ? 'التالي' : 'Next'} <Arrow size={15} />
            </Button>
          </footer>
        )}
      </form>
    </div>
  )
}

function SearchBox({ value, onChange, label, testId }: { value: string; onChange: (v: string) => void; label: string; testId: string }) {
  return (
    <label className="flex items-center gap-2 rounded-xl border border-border bg-surface-secondary px-3 py-2">
      <Search size={15} className="shrink-0 text-text-secondary" aria-hidden />
      <input
        type="search"
        value={value}
        onChange={(e) => onChange(e.target.value)}
        placeholder={label}
        aria-label={label}
        className="min-w-0 flex-1 bg-transparent text-sm text-text-primary outline-none placeholder:text-text-secondary"
        data-testid={testId}
      />
    </label>
  )
}

function Row({ name, checked, disabled = false, onChange, testId, icon, title, end }: {
  name: string
  checked: boolean
  disabled?: boolean
  onChange: () => void
  testId: string
  icon?: React.ReactNode
  title: string
  end?: React.ReactNode
}) {
  return (
    <label className={`flex items-center gap-3 rounded-xl border px-3 py-2.5 transition-colors ${disabled ? 'cursor-not-allowed opacity-60' : 'cursor-pointer hover:bg-surface-hover'} ${checked ? 'border-brand-500 bg-surface-hover' : 'border-border'}`}>
      <input type="radio" name={name} className="h-4 w-4 shrink-0 accent-brand-600" checked={checked} disabled={disabled} onChange={onChange} data-testid={testId} />
      {icon}
      <span className="min-w-0 flex-1 truncate text-sm font-semibold text-text-primary">{title}</span>
      {end !== undefined && <span className="flex shrink-0 items-center gap-1.5">{end}</span>}
    </label>
  )
}

function Empty({ ar }: { ar: boolean }) {
  return <p className="rounded-xl border border-dashed border-border p-4 text-center text-xs text-text-secondary" data-testid="picker-empty">{ar ? 'لا نتائج' : 'No results'}</p>
}

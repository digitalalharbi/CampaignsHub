import { useEffect, useState } from 'react'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, ArrowRight, Search } from 'lucide-react'
import { confirmLinkSource, fetchLinkSources, removeConnection } from './api'
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
 * INTEG-PROJECT-LINK-STORES-GA4-001 (Owner 2026-11-06) — the journey after the platform's own page, when the
 * source is a store or a GA4 property:
 *
 *     متجر → تم          خاصية → تم
 *
 * What this project's own sign-in reaches is asked for when this opens and stored nowhere; the one that is
 * confirmed is the only thing written, and it is bound to this project. A list long enough to need it is
 * searched as the reader types. One primary action, no explanatory prose: the step says what it is.
 *
 * Everything is keyed by the project, so another project's journey shares nothing with this one.
 */
export function ProjectSourcePicker({ projectId, projectName, connection, onClose, onReturn }: {
  projectId: string
  projectName: string | null
  connection: { id: string; provider: string; kind: 'commerce' | 'measurement' }
  onClose: () => void
  onReturn: () => void
}) {
  const ar = useUi((s) => s.locale) === 'ar'
  const qc = useQueryClient()
  const Arrow = ar ? ArrowLeft : ArrowRight
  const word: LinkStep = connection.kind === 'commerce' ? 'store' : 'property'

  const [step, setStep] = useState<LinkStep>(word)
  const [chosen, setChosen] = useState<string | null>(null)
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const q = useDebouncedValue(search.trim(), 250)

  const sources = useQuery({
    queryKey: ['project-link', projectId, connection.id, 'sources', q, page],
    queryFn: () => fetchLinkSources(projectId, connection.id, { q, page }),
    placeholderData: keepPreviousData,
    retry: false,
  })
  const rows = sources.data?.sources ?? null
  const total = sources.data?.total ?? 0
  const perPage = sources.data?.per_page ?? 25
  const pages = Math.max(1, Math.ceil(total / perPage))

  // One source that can be taken is not a question: it is marked, and «التالي» confirms it.
  useEffect(() => {
    if (rows !== null && chosen === null && q === '' && rows.length === 1 && rows[0].linked !== 'elsewhere') setChosen(rows[0].external_id)
  }, [rows, chosen, q])

  const confirm = useMutation({
    mutationFn: () => confirmLinkSource({ projectId, connectionId: connection.id, externalId: chosen ?? '' }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['project-bindings', projectId] })
      void qc.invalidateQueries({ queryKey: ['commerce-stores'] })
      void qc.invalidateQueries({ queryKey: ['measurement-properties'] })
      void qc.invalidateQueries({ queryKey: ['project-link', projectId] })
      setStep('done')
    },
  })

  // «إلغاء» leaves nothing behind: a sign-in nobody confirmed a source on is removed by the server.
  const cancel = useMutation({
    mutationFn: () => removeConnection(connection.id, { unconfirmedOnly: true }),
    onSettled: onClose,
  })

  const refusal = confirm.isError ? toApiError(confirm.error) : null
  const canAdvance = step !== 'done' && chosen !== null && !confirm.isPending
  // A search box earns its place on a list, not on the one store an authorisation returns.
  const searchable = connection.kind === 'measurement' && (total > 6 || q !== '' || search !== '')

  return (
    <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4 sm:p-8" role="dialog" aria-modal="true" aria-labelledby="project-source-title">
      <form
        className="flex w-full max-w-lg flex-col gap-4 rounded-2xl border border-border bg-surface p-5 shadow-2xl"
        data-testid="project-source-picker"
        data-kind={connection.kind}
        onSubmit={(e) => { e.preventDefault(); if (canAdvance) confirm.mutate() }}
      >
        <header className="flex items-center gap-3">
          <ProviderMark provider={connection.provider} size={26} />
          <div className="min-w-0 flex-1">
            <h2 id="project-source-title" className="truncate text-base font-bold text-text-primary">{platformLabel(connection.provider, ar)}</h2>
            <p className="truncate text-xs text-text-secondary" data-testid="link-project">{projectName ?? '…'}</p>
          </div>
          {step !== 'done' && (
            <Button type="button" variant="ghost" loading={cancel.isPending} onClick={() => cancel.mutate()} data-testid="picker-cancel">
              {ar ? 'إلغاء' : 'Cancel'}
            </Button>
          )}
        </header>

        <LinkSteps steps={['platform', word, 'done']} current={step} ar={ar} />

        {step === 'done' ? (
          <LinkSuccess projectId={projectId} connectionId={connection.id} onReturn={onReturn} />
        ) : (
          <fieldset className="flex flex-col gap-1.5" data-testid="picker-sources">
            <legend className="sr-only">{connection.kind === 'commerce' ? (ar ? 'متجر' : 'Store') : (ar ? 'خاصية' : 'Property')}</legend>
            {searchable && (
              <label className="flex items-center gap-2 rounded-xl border border-border bg-surface-secondary px-3 py-2">
                <Search size={15} className="shrink-0 text-text-secondary" aria-hidden />
                <input
                  type="search"
                  value={search}
                  onChange={(e) => { setSearch(e.target.value); setPage(1) }}
                  placeholder={ar ? 'بحث' : 'Search'}
                  aria-label={ar ? 'بحث' : 'Search'}
                  className="min-w-0 flex-1 bg-transparent text-sm text-text-primary outline-none placeholder:text-text-secondary"
                  data-testid="picker-source-search"
                />
              </label>
            )}
            {sources.isLoading ? (
              <Skeleton className="h-32 w-full" />
            ) : sources.isError ? (
              <ErrorState title={toApiError(sources.error).message} onRetry={() => void sources.refetch()} />
            ) : (
              <div className="flex max-h-80 flex-col gap-1.5 overflow-y-auto">
                {(rows ?? []).map((s) => (
                  <label
                    key={s.external_id}
                    className={`flex items-center gap-3 rounded-xl border px-3 py-2.5 transition-colors ${s.linked === 'elsewhere' ? 'cursor-not-allowed opacity-60' : 'cursor-pointer hover:bg-surface-hover'} ${chosen === s.external_id ? 'border-brand-500 bg-surface-hover' : 'border-border'}`}
                  >
                    <input
                      type="radio"
                      name="link-source"
                      className="h-4 w-4 shrink-0 accent-brand-600"
                      checked={chosen === s.external_id}
                      disabled={s.linked === 'elsewhere'}
                      onChange={() => setChosen(s.external_id)}
                      data-testid={`picker-source-${s.external_id}`}
                    />
                    <span className="flex min-w-0 flex-1 flex-col">
                      <span className="truncate text-sm font-semibold text-text-primary">{s.name}</span>
                      <span className="truncate text-[11px] text-text-muted">
                        {s.detail !== null && <span dir="auto">{s.detail}</span>}
                        {s.detail !== null && connection.kind === 'measurement' && ' · '}
                        {connection.kind === 'measurement' && <span className="tnum" dir="ltr">{s.external_id}</span>}
                      </span>
                    </span>
                    <span className="flex shrink-0 items-center gap-1.5">
                      {s.linked === 'here' && <Badge tone="success">{ar ? 'مرتبط' : 'Linked'}</Badge>}
                      {s.linked === 'elsewhere' && <Badge tone="warning">{ar ? 'مشروع آخر' : 'Other project'}</Badge>}
                      {s.currency !== null && <Badge tone="neutral" data-testid={`picker-currency-${s.external_id}`}>{s.currency}</Badge>}
                    </span>
                  </label>
                ))}
                {(rows?.length ?? 0) === 0 && (
                  <p className="rounded-xl border border-dashed border-border p-4 text-center text-xs text-text-secondary" data-testid="picker-empty">{ar ? 'لا نتائج' : 'No results'}</p>
                )}
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
            <span />
            <Button type="submit" disabled={!canAdvance} loading={confirm.isPending} data-testid="link-next">
              {ar ? 'التالي' : 'Next'} <Arrow size={15} />
            </Button>
          </footer>
        )}
      </form>
    </div>
  )
}

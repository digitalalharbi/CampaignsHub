import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, ExternalLink } from 'lucide-react'
import { fetchCollaborations, updateDeliverable, type Collaboration, type Deliverable } from './api'
import { Button } from '@/components/ui/Button'
import { ErrorState, Skeleton } from '@/components/ui/States'
import { toApiError } from '@/lib/api/client'
import { useUi } from '@/stores/ui'

/**
 * `/influencers/deliverables` — what is owed, across every agreement (INFL-001).
 *
 * The collaborations page answers "how is this agreement going?"; this one answers the question that
 * cuts the other way — "what is late, right now, anywhere?" — which is invisible when the work is
 * only ever grouped under its own agreement.
 *
 * Overdue is computed on the server from the due date and the status together, so a published post
 * is never called late no matter when it went up.
 */

type Row = Deliverable & { collaboration: Collaboration }

const STATUS_FLOW: { key: string; ar: string; en: string }[] = [
  { key: 'submitted', ar: 'مُسلَّم', en: 'Submitted' },
  { key: 'approved', ar: 'معتمد', en: 'Approved' },
  { key: 'published', ar: 'منشور', en: 'Published' },
]

export function DeliverablesPage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const qc = useQueryClient()
  const [onlyLate, setOnlyLate] = useState(false)

  const query = useQuery({
    queryKey: ['influencers', 'collaborations', ''],
    queryFn: () => fetchCollaborations(),
  })

  const advance = useMutation({
    mutationFn: ({ row, status }: { row: Row; status: string }) =>
      updateDeliverable(row.collaboration.id, row.id, { status }),
    onSuccess: () => void qc.invalidateQueries({ queryKey: ['influencers', 'collaborations'] }),
  })

  const rows = useMemo<Row[]>(() => {
    const all = (query.data?.collaborations ?? []).flatMap((c) =>
      c.deliverables.map((d) => ({ ...d, collaboration: c })),
    )

    // Late first, then by due date. Anything undated sorts last — it cannot be late, and putting it
    // among dated work would make the top of the list look busier than it is.
    return all.sort((a, b) => {
      if (a.is_overdue !== b.is_overdue) return a.is_overdue ? -1 : 1
      if (a.due_on === null) return 1
      if (b.due_on === null) return -1

      return a.due_on.localeCompare(b.due_on)
    })
  }, [query.data])

  const shown = onlyLate ? rows.filter((r) => r.is_overdue) : rows
  const lateCount = rows.filter((r) => r.is_overdue).length
  const error = advance.isError ? toApiError(advance.error) : null

  if (query.isPending) {
    return <div className="grid gap-3">{[0, 1, 2, 3].map((i) => <Skeleton key={i} className="h-16" />)}</div>
  }

  if (query.isError || !query.data) {
    return (
      <ErrorState
        error={query.error}
        title={ar ? 'تعذّر تحميل المخرجات.' : 'Deliverables could not be loaded.'}
        onRetry={() => void query.refetch()}
      />
    )
  }

  const canManage = query.data.can_manage

  return (
    <div className="w-full">
      <header className="mb-5">
        <h1 className="font-heading text-3xl font-extrabold tracking-tight text-text-primary">
          {ar ? 'المخرجات' : 'Deliverables'}
        </h1>
        <p className="mt-1 text-sm text-text-secondary">
          {ar
            ? 'كل ما هو مستحق عبر جميع التعاونات — المتأخر أولًا.'
            : 'Everything owed across every agreement — late work first.'}
        </p>
      </header>

      <div className="mb-4 flex flex-wrap items-center gap-2">
        <button
          type="button"
          onClick={() => setOnlyLate(false)}
          aria-pressed={!onlyLate}
          className={`rounded-lg px-3 py-1.5 text-sm font-semibold ${!onlyLate ? 'bg-brand-primary-soft text-brand-700' : 'text-text-secondary hover:bg-surface-hover'}`}
        >
          {ar ? 'الكل' : 'All'} <span className="tnum" dir="ltr">({rows.length})</span>
        </button>
        <button
          type="button"
          data-testid="filter-overdue"
          onClick={() => setOnlyLate(true)}
          aria-pressed={onlyLate}
          className={`rounded-lg px-3 py-1.5 text-sm font-semibold ${onlyLate ? 'bg-warning/15 text-warning' : 'text-text-secondary hover:bg-surface-hover'}`}
        >
          {ar ? 'المتأخر' : 'Overdue'} <span className="tnum" dir="ltr">({lateCount})</span>
        </button>
      </div>

      {error && (
        <p role="alert" className="mb-4 rounded-xl bg-[var(--negative-background)] px-4 py-3 text-sm text-danger">
          {error.message}
        </p>
      )}

      {shown.length === 0 ? (
        <p className="rounded-2xl border border-dashed border-border px-4 py-12 text-center text-sm text-text-muted">
          {onlyLate
            ? (ar ? 'لا شيء متأخر. ' : 'Nothing is late.')
            : (ar ? 'لا مخرجات مستحقة بعد.' : 'Nothing is owed yet.')}
        </p>
      ) : (
        <ul data-testid="deliverables" className="grid gap-2">
          {shown.map((row) => (
            <li
              key={row.id}
              data-testid={`deliverable-${row.id}`}
              className={`flex flex-wrap items-center justify-between gap-3 rounded-xl border bg-surface px-4 py-3 ${
                row.is_overdue ? 'border-warning/40' : 'border-border'
              }`}
            >
              <div className="min-w-0">
                <p className="flex items-center gap-2 text-[14px] font-bold text-text-primary">
                  {row.is_overdue && <AlertTriangle size={14} className="shrink-0 text-warning" aria-hidden />}
                  {row.type}
                  {row.platform && <span className="font-normal text-text-muted">· {row.platform}</span>}
                </p>
                <p className="mt-0.5 truncate text-[12px] text-text-muted">
                  {row.collaboration.title}
                  {row.collaboration.influencer && ` · ${row.collaboration.influencer.name}`}
                  {row.collaboration.client && ` · ${row.collaboration.client.name}`}
                </p>
              </div>

              <div className="flex flex-wrap items-center gap-2.5">
                {row.due_on && (
                  <span className={`tnum text-[12px] ${row.is_overdue ? 'font-semibold text-warning' : 'text-text-muted'}`} dir="ltr">
                    {row.due_on}
                  </span>
                )}
                <span className="rounded-full bg-surface-secondary px-2.5 py-1 text-[11px] font-semibold text-text-secondary">
                  {row.status}
                </span>
                {row.submitted_url && (
                  <a
                    href={row.submitted_url}
                    target="_blank"
                    rel="noreferrer noopener"
                    className="inline-flex items-center gap-1 text-[12px] font-semibold text-brand-600 hover:underline"
                  >
                    <ExternalLink size={12} aria-hidden /> {ar ? 'المحتوى' : 'View'}
                  </a>
                )}
                {canManage && nextStep(row.status) !== null && (
                  <Button
                    size="sm"
                    variant="secondary"
                    disabled={advance.isPending}
                    onClick={() => advance.mutate({ row, status: nextStep(row.status)!.key })}
                  >
                    {ar ? `وسم: ${nextStep(row.status)!.ar}` : `Mark ${nextStep(row.status)!.en.toLowerCase()}`}
                  </Button>
                )}
              </div>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

/** The next honest step, or null when there is nothing left to advance to. */
function nextStep(status: string): { key: string; ar: string; en: string } | null {
  const index = STATUS_FLOW.findIndex((s) => s.key === status)

  if (status === 'pending') return STATUS_FLOW[0]
  if (index === -1 || index === STATUS_FLOW.length - 1) return null

  return STATUS_FLOW[index + 1]
}

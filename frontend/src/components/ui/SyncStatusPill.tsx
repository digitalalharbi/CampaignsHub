import { syncStatusMeaning } from '@/lib/syncStatus'

/**
 * A sync status, in words the reader speaks and a colour that matches what it means.
 *
 * INTEG-RUNTIME §8. The pill exists so that no surface has to decide either one for itself: before
 * it, `AnalyticsPage` printed the raw backend key on a green pill by default, so `awaiting_assignment`
 * — an account nobody had connected to a project — was reported to the customer as a success.
 *
 * `title` carries the one-line meaning, so the word is explained on hover without a legend.
 */
export function SyncStatusPill({ status, ar }: { status: string | null | undefined; ar: boolean }) {
  const meaning = syncStatusMeaning(status)

  const surface: Record<string, string> = {
    danger: 'bg-[var(--negative-background)] text-danger',
    success: 'bg-[var(--positive-background)] text-success',
    warning: 'bg-[var(--warning-background)] text-warning',
    neutral: 'bg-surface-muted text-text-secondary',
  }

  return (
    <span
      data-testid="sync-status-pill"
      data-status={status ?? 'unknown'}
      title={ar ? meaning.hint_ar : meaning.hint_en}
      className={`rounded-full px-2 py-0.5 text-xs font-semibold ${surface[meaning.tone]}`}
    >
      {status ? (ar ? meaning.ar : meaning.en) : '—'}
    </span>
  )
}

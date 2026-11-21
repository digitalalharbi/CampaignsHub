import { Loader2, TriangleAlert } from 'lucide-react'
import { fmtClock } from '@/lib/datetime'

/**
 * PERF-INSTANT-DATA-001 — the one sentence a screen owes its reader while it shows an answer it is
 * about to replace.
 *
 * Keeping the previous figures on screen is only honest if the page says what they are. This is that
 * statement, in one place, with the four states `useSwrQuery` can be in:
 *
 *  - `previous`  — these figures were read for another period (named); the selected one is loading;
 *  - `refreshing` — these figures are the current selection; a newer reading is on its way;
 *  - `failed`    — the newest reading failed; these figures are the last good ones, with their time;
 *  - nothing     — settled; draws nothing.
 *
 * It renders inline and reserves no block of its own, so an idle page carries no empty line. The
 * time is the moment this browser fetched the answer — NOT when the data was synced from the
 * platform, which each page already states with `DataFreshness`.
 */
export function RefreshNote({
  ar,
  isRefreshing,
  isShowingPrevious,
  refreshFailed,
  updatedAt,
  shownPeriod,
  onRetry,
  testid = 'refresh-note',
}: {
  ar: boolean
  isRefreshing: boolean
  isShowingPrevious: boolean
  refreshFailed: boolean
  updatedAt: number
  /** The period the figures on screen were read for, e.g. `2026-10-01 → 2026-10-10`. */
  shownPeriod?: string
  onRetry?: () => void
  testid?: string
}) {
  const state = isShowingPrevious ? 'previous' : refreshFailed ? 'failed' : isRefreshing ? 'refreshing' : null
  if (state === null) return null

  const stamp = updatedAt > 0 ? fmtClock(updatedAt) : null

  return (
    <span data-testid={testid} data-state={state} role="status" aria-live="polite" className={`inline-flex items-center gap-1.5 text-xs ${state === 'failed' ? 'font-semibold text-warning' : 'text-text-secondary'}`}>
      {state === 'failed' ? <TriangleAlert size={12} aria-hidden /> : <Loader2 size={12} className="motion-safe:animate-spin" aria-hidden />}
      {state === 'previous' && (
        <span>
          {ar ? 'تُعرض أرقام الفترة ' : 'Showing the figures for '}
          <span dir="ltr" className="tnum">{shownPeriod}</span>
          {ar ? ' — جارٍ تحديثها للفترة المختارة…' : ' — updating to the selected period…'}
        </span>
      )}
      {state === 'refreshing' && <span>{ar ? 'جارٍ التحديث…' : 'Updating…'}</span>}
      {state === 'failed' && (
        <span>
          {ar ? 'تعذّر التحديث — تُعرض آخر أرقام صحيحة' : 'Could not refresh — showing the last good figures'}
          {stamp !== null && <> <span dir="ltr" className="tnum">({stamp})</span></>}
          {onRetry && <button type="button" onClick={onRetry} className="ms-2 font-bold text-brand-600 hover:underline">{ar ? 'أعد المحاولة' : 'Retry'}</button>}
        </span>
      )}
    </span>
  )
}

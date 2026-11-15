import { ChevronLeft, ChevronRight } from 'lucide-react'

/**
 * NOV-FIT-001 — one line of paging: «‹  3 / 9  ›», and the total as a quiet figure.
 *
 * A fitted page shows exactly as many rows as fit, so the rest is a page away. The pager is always drawn, even for
 * one page, so the table above it stands the same height whether it holds three rows or three hundred.
 * The chevrons point the way the language reads: «next» is to the left in Arabic.
 */
export function Pager({
  page, pages, onPage, total, ar, testid, hideWhenSingle = false,
}: { page: number; pages: number; onPage: (page: number) => void; total?: number; ar: boolean; testid?: string; hideWhenSingle?: boolean }) {
  const last = Math.max(1, pages)
  // A control that does nothing teaches the reader to ignore it — but the space stays, so the page above does not move.
  if (hideWhenSingle && last <= 1) return <div aria-hidden className="h-11 shrink-0" />
  const Prev = ar ? ChevronRight : ChevronLeft
  const Next = ar ? ChevronLeft : ChevronRight

  return (
    <nav data-testid={testid} aria-label={ar ? 'الصفحات' : 'Pages'} className="flex h-11 shrink-0 items-center justify-between gap-2 text-sm text-text-muted">
      <span className="tnum" dir="ltr">{total !== undefined ? total : ''}</span>
      <div className="flex items-center gap-1">
        <button
          type="button"
          aria-label={ar ? 'السابق' : 'Previous'}
          disabled={page <= 1}
          onClick={() => onPage(page - 1)}
          className="flex h-9 w-9 items-center justify-center rounded-lg border border-border bg-surface text-text-secondary hover:bg-surface-hover disabled:opacity-40"
        >
          <Prev size={16} aria-hidden />
        </button>
        <span className="tnum min-w-14 text-center font-semibold text-text-secondary" dir="ltr" aria-live="polite">{page} / {last}</span>
        <button
          type="button"
          aria-label={ar ? 'التالي' : 'Next'}
          disabled={page >= last}
          onClick={() => onPage(page + 1)}
          className="flex h-9 w-9 items-center justify-center rounded-lg border border-border bg-surface text-text-secondary hover:bg-surface-hover disabled:opacity-40"
        >
          <Next size={16} aria-hidden />
        </button>
      </div>
    </nav>
  )
}

import { useState, type ReactNode } from 'react'
import { SlidersHorizontal, X } from 'lucide-react'
import { Modal } from './Modal'

/**
 * One control instead of three bands of them — SIMPLIFY-001, generalised.
 *
 * `/app/dashboard` opened with a saved-views bar, an objective row and a platform row: three bands
 * of configuration between the reader and any number. Somebody who had never used the product met
 * the settings before the answers. The same shape had grown on Clients, Tasks, Alerts, Content and
 * Files — a search box, then two or three rows of chips, then finally the data.
 *
 * This folds those rows behind a single button and states what is applied IN WORDS beside it, which
 * is the part that makes folding honest: **a filtered list that looks unfiltered is worse than a
 * noisy toolbar.** Nothing is removed, nothing changes server-side, and every control keeps working
 * exactly as it did — it is reached in one click instead of occupying the top of the page forever.
 *
 * What deliberately does NOT belong in here:
 *
 * - **Search.** Searching is how a person finds a row, not how they configure a view; burying it
 *   would make the page harder to use rather than simpler.
 * - **A list/board or grid/table switcher.** That is how the page is READ, and it is used constantly.
 *
 * `summary` should read as a sentence a non-technical user understands («مفتوحة · أولوية عالية»),
 * never the internal filter keys. When nothing is narrowed, pass the «everything» wording rather than
 * an empty string, so the line never disappears and leaves the reader guessing what they are looking
 * at.
 */
export function ViewCustomiser({
  id,
  ar,
  summary,
  active = false,
  title,
  onClear,
  children,
}: {
  /** Distinguishes the testids when a page carries more than one customiser. */
  id: string
  ar: boolean
  /** What is currently applied, in words. Shown next to the button so folding hides no state. */
  summary: ReactNode
  /** Whether anything is actually narrowed — drives the marker and the clear affordance. */
  active?: boolean
  /** Overrides the dialog heading; defaults to «الفلاتر / Filters». */
  title?: string
  /** Clears every filter at once. Omitted where a page has nothing meaningful to reset to. */
  onClear?: () => void
  children: ReactNode
}) {
  const [open, setOpen] = useState(false)
  const label = ar ? 'الفلاتر' : 'Filters'

  return (
    <>
      <div className="flex flex-wrap items-center gap-2">
        <button
          type="button"
          data-testid={`${id}-customise`}
          onClick={() => setOpen(true)}
          className="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-xl border border-border-strong bg-surface px-3 text-sm font-semibold text-text-primary hover:bg-surface-hover"
        >
          <SlidersHorizontal size={15} aria-hidden />
          {label}
          {active && (
            <span
              aria-hidden
              data-testid={`${id}-customise-active`}
              className="inline-flex h-2 w-2 rounded-full bg-brand-500"
            />
          )}
        </button>

        {/*
          The applied state, always shown.
          Without it, folding the controls turns a filtered list into a short list and the reader has
          no way to tell that rows are missing on purpose. Rendered even when nothing is narrowed, so
          its absence never has to be interpreted.
        */}
        <span data-testid={`${id}-applied`} className="min-w-0 text-[13px] text-text-secondary">
          {summary}
        </span>

        {active && onClear && (
          <button
            type="button"
            data-testid={`${id}-clear`}
            onClick={onClear}
            className="inline-flex shrink-0 items-center gap-1 text-[13px] font-semibold text-text-muted underline underline-offset-2 hover:text-text-primary"
          >
            <X size={13} aria-hidden /> {ar ? 'إزالة الفلاتر' : 'Clear'}
          </button>
        )}
      </div>

      <Modal open={open} onClose={() => setOpen(false)} title={title ?? label} size="lg">
        <div data-testid={`${id}-customise-body`} className="grid gap-5">
          {children}
        </div>
      </Modal>
    </>
  )
}

/** A labelled row of choices inside the dialog, so each group of chips says what it filters. */
export function FilterGroup({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="grid gap-2">
      <span className="text-xs font-bold uppercase tracking-wide text-text-muted">{label}</span>
      <div className="flex flex-wrap items-center gap-1.5">{children}</div>
    </div>
  )
}

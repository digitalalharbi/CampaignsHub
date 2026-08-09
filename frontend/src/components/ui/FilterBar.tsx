import { useEffect, useId, useMemo, useRef, useState, type ReactNode } from 'react'
import { Check, ChevronDown, Search, SlidersHorizontal, X } from 'lucide-react'
import { Modal } from './Modal'

/**
 * The filters, on the page — UX-FILTERS-001.
 *
 * ## Why this exists next to `ViewCustomiser` rather than instead of it
 *
 * `ViewCustomiser` folded every filter on a page behind one button (SIMPLIFY-001). It solved a real
 * problem — five bands of chips above a library is a settings screen, not a library — and it solved
 * it too widely: the platform, the period and the client are not configuration, they are how this
 * product is USED. Folding them made the system look thinner than it is, and made a narrowed page
 * indistinguishable from a short one at a glance.
 *
 * So the rule is now a division rather than a switch: **the filters somebody reaches for daily are
 * on the page; only the rare ones fold.** `FilterBar` renders the first group inline and keeps the
 * second behind `More filters` — the same dialog, for the axes that earn it.
 *
 * ## What the bar owes the reader, beyond the controls themselves
 *
 * - **Applied filters, as removable chips.** A control set to a value tells you what it is set to
 *   only if you look at it; a chip row tells you what is narrowing the page in one glance, and each
 *   chip removes exactly its own filter. This is what makes a filtered page legible.
 * - **One Reset.** Clearing eight controls by hand is how people end up reloading the page instead.
 * - **A count on every multi-select**, so a closed popover still says «3» rather than «Platform».
 *
 * Nothing here talks to a server or owns state: every control is driven by the page, so the filters
 * and the query that fetches the data cannot drift apart.
 */

const T = {
  more: { ar: 'المزيد من الفلاتر', en: 'More filters' },
  reset: { ar: 'إعادة ضبط', en: 'Reset' },
  applied: { ar: 'الفلاتر المطبَّقة', en: 'Applied filters' },
  filters: { ar: 'الفلاتر', en: 'Filters' },
  all: { ar: 'الكل', en: 'All' },
  none: { ar: 'لا يوجد خيار', en: 'No options' },
  searchOptions: { ar: 'ابحث…', en: 'Search…' },
  selectAll: { ar: 'تحديد الكل', en: 'Select all' },
  clear: { ar: 'مسح', en: 'Clear' },
  remove: { ar: 'إزالة', en: 'Remove' },
}

const t = (key: keyof typeof T, ar: boolean) => (ar ? T[key].ar : T[key].en)

/** One narrowing currently in force, and the way to undo just that one. */
export type AppliedFilter = {
  /** Unique within the bar — axis and value, e.g. `providers:meta`. */
  key: string
  /** The axis, in the reader's language: «المنصة». */
  axis: string
  /** The value, in the reader's language: «ميتا» — never the wire key. */
  label: string
  onRemove: () => void
}

const CONTROL =
  'inline-flex h-9 items-center gap-1.5 rounded-xl border border-border bg-surface px-2.5 text-sm ' +
  'font-semibold text-text-primary transition-colors hover:bg-surface-hover ' +
  'focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40'

export function FilterBar({
  id,
  ar,
  children,
  applied = [],
  onReset,
  advanced,
  advancedActive = false,
  trailing,
}: {
  /** Distinguishes testids where a page carries more than one bar. */
  id: string
  ar: boolean
  /** The always-visible controls — the daily ones. */
  children: ReactNode
  /** What is narrowing the page right now, each removable on its own. */
  applied?: AppliedFilter[]
  /** Clears every filter the bar owns, including the folded ones. */
  onReset?: () => void
  /** The rare axes. Omit it and no `More filters` button is rendered at all. */
  advanced?: ReactNode
  /** Whether anything inside `advanced` is narrowing — drives the marker on the button. */
  advancedActive?: boolean
  /** Actions that belong beside the filters rather than in the page header (view switchers, export). */
  trailing?: ReactNode
}) {
  const [open, setOpen] = useState(false)

  return (
    <section
      data-testid={`${id}-filters`}
      aria-label={t('filters', ar)}
      className="rounded-2xl border border-border bg-surface p-3"
    >
      <div className="flex flex-wrap items-end gap-2">
        {children}

        {advanced && (
          <button
            type="button"
            data-testid={`${id}-more-filters`}
            onClick={() => setOpen(true)}
            className={CONTROL}
          >
            <SlidersHorizontal size={15} aria-hidden />
            {t('more', ar)}
            {advancedActive && (
              <span
                aria-hidden
                data-testid={`${id}-more-filters-active`}
                className="inline-flex h-2 w-2 rounded-full bg-brand-500"
              />
            )}
          </button>
        )}

        {trailing && <div className="ms-auto flex flex-wrap items-center gap-2">{trailing}</div>}
      </div>

      {/*
        The applied row. Present only when something is applied — an empty «Applied filters:» label
        above an empty row is a line that says nothing every time the page is unfiltered, which is
        most of the time.
      */}
      {applied.length > 0 && (
        <div
          data-testid={`${id}-applied`}
          className="mt-3 flex flex-wrap items-center gap-1.5 border-t border-border pt-3"
        >
          <span className="text-xs font-bold uppercase tracking-wide text-text-muted">
            {t('applied', ar)}
          </span>
          {applied.map((f) => (
            <span
              key={f.key}
              data-testid={`${id}-applied-${f.key}`}
              className="inline-flex items-center gap-1 rounded-full border border-brand-500/40 bg-brand-500/10 px-2 py-0.5 text-xs font-semibold text-text-primary"
            >
              <span className="text-text-secondary">{f.axis}:</span>
              {f.label}
              <button
                type="button"
                onClick={f.onRemove}
                aria-label={`${t('remove', ar)} ${f.axis}: ${f.label}`}
                className="rounded-full p-0.5 text-text-muted hover:bg-surface-hover hover:text-text-primary"
              >
                <X size={12} aria-hidden />
              </button>
            </span>
          ))}
          {onReset && (
            <button
              type="button"
              data-testid={`${id}-reset`}
              onClick={onReset}
              className="ms-1 text-xs font-semibold text-text-muted underline underline-offset-2 hover:text-text-primary"
            >
              {t('reset', ar)}
            </button>
          )}
        </div>
      )}

      {advanced && (
        <Modal open={open} onClose={() => setOpen(false)} title={t('more', ar)} size="lg">
          <div data-testid={`${id}-more-filters-body`} className="grid gap-5">
            {advanced}
          </div>
        </Modal>
      )}
    </section>
  )
}

/** The label above a control, in the one style every filter in the product uses. */
function ControlLabel({ children, htmlFor }: { children: ReactNode; htmlFor?: string }) {
  return (
    <label htmlFor={htmlFor} className="text-xs font-semibold text-text-secondary">
      {children}
    </label>
  )
}

/**
 * A single-valued axis — period, sort, a status that cannot be two things at once.
 *
 * A native `<select>` deliberately: it is keyboard-navigable, screen-readable, and on a phone it
 * opens the platform's own picker rather than a popover somebody has to scroll inside a page that
 * is already scrolling.
 */
export function FilterSelect({
  label,
  value,
  options,
  onChange,
  testid,
  width = 'min-w-36',
}: {
  label: string
  value: string
  options: Array<{ value: string; label: string }>
  onChange: (value: string) => void
  testid?: string
  width?: string
}) {
  const id = useId()

  return (
    <div className={`flex flex-col gap-1 ${width}`}>
      <ControlLabel htmlFor={id}>{label}</ControlLabel>
      <select
        id={id}
        data-testid={testid}
        aria-label={label}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        className="h-9 rounded-xl border border-border bg-surface px-2 text-sm font-semibold text-text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40"
      >
        {options.map((o) => (
          <option key={o.value} value={o.value}>
            {o.label}
          </option>
        ))}
      </select>
    </div>
  )
}

/**
 * A multi-valued axis, visible on the page and honest when closed.
 *
 * The trigger carries the count, so «Platform · 2» is readable without opening anything — the whole
 * reason a folded control is allowed to be folded. Above `searchAfter` options it grows a search
 * box, because a client list is four hundred names long and scrolling is not selecting.
 */
export function FilterMulti({
  label,
  values,
  options,
  onChange,
  testid,
  searchAfter = 8,
  ar,
}: {
  label: string
  values: string[]
  options: Array<{ value: string; label: string }>
  onChange: (next: string[]) => void
  testid?: string
  /** Above this many options the popover grows a search box. */
  searchAfter?: number
  ar: boolean
}) {
  const [open, setOpen] = useState(false)
  const [term, setTerm] = useState('')
  const box = useRef<HTMLDivElement>(null)

  /*
   * Closing on an outside click and on Escape.
   *
   * Both, not one: a popover that only closes on Escape traps a mouse user behind it, and one that
   * only closes on an outside click traps a keyboard user. `mousedown` rather than `click` so the
   * popover is gone before whatever was clicked reacts.
   */
  useEffect(() => {
    if (!open) return
    const away = (e: MouseEvent) => {
      if (box.current && !box.current.contains(e.target as Node)) setOpen(false)
    }
    const esc = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setOpen(false)
    }
    document.addEventListener('mousedown', away)
    document.addEventListener('keydown', esc)
    return () => {
      document.removeEventListener('mousedown', away)
      document.removeEventListener('keydown', esc)
    }
  }, [open])

  const shown = useMemo(() => {
    const q = term.trim().toLowerCase()
    return q === '' ? options : options.filter((o) => o.label.toLowerCase().includes(q))
  }, [options, term])

  /*
   * An axis with nothing to choose from is not a filter — it is a control that can only disappoint.
   * So it is DISABLED, and it stays where it is — CLICK-STABLE-001.
   *
   * It used to return `null`, which meant the bar's shape was a function of a query still in flight:
   * the campaign axis appeared the instant its options landed and shoved every control after it —
   * `More filters` among them — to a new position. Measured on the dashboard, that button moved 655px
   * sideways and 67px down within 200ms of becoming clickable.
   *
   * A press and its release must reach the SAME element for a click to exist. When the target slides
   * out from under the pointer in between, no click event is produced at all: no error, no effect,
   * and a person who is certain they clicked. That is what took three specs down on firefox — the
   * slowest of the three browsers, so the one whose settling most often overlapped the press.
   *
   * `disabled` still refuses the interaction. It just refuses it in place.
   */
  const empty = options.length === 0

  const toggle = (value: string) =>
    onChange(values.includes(value) ? values.filter((v) => v !== value) : [...values, value])

  return (
    <div className="flex min-w-36 flex-col gap-1" ref={box}>
      <ControlLabel>{label}</ControlLabel>
      <div className="relative">
        <button
          type="button"
          data-testid={testid}
          disabled={empty}
          aria-expanded={empty ? undefined : open}
          aria-haspopup={empty ? undefined : 'listbox'}
          onClick={() => setOpen((o) => !o)}
          className={`${CONTROL} w-full justify-between ${empty ? 'cursor-default text-text-muted' : ''} ${values.length > 0 ? 'border-brand-500/50 bg-brand-500/10' : ''}`}
        >
          <span className="truncate">
            {empty
              ? t('none', ar)
              : values.length === 0
                ? t('all', ar)
                : values.length === 1
                  ? (options.find((o) => o.value === values[0])?.label ?? values[0])
                  : `${values.length}`}
          </span>
          <ChevronDown size={14} aria-hidden className="shrink-0 text-text-muted" />
        </button>

        {open && !empty && (
          <div
            role="listbox"
            aria-label={label}
            data-testid={testid ? `${testid}-options` : undefined}
            className="absolute top-full z-30 mt-1 max-h-72 w-64 overflow-auto rounded-xl border border-border-strong bg-surface p-1.5 shadow-[var(--shadow-medium)] start-0"
          >
            {options.length > searchAfter && (
              <div className="sticky top-0 bg-surface pb-1.5">
                <span className="relative block">
                  <Search
                    size={14}
                    aria-hidden
                    className="absolute top-2.5 text-text-muted start-2"
                  />
                  <input
                    type="search"
                    value={term}
                    aria-label={`${label} — ${t('searchOptions', ar)}`}
                    placeholder={t('searchOptions', ar)}
                    onChange={(e) => setTerm(e.target.value)}
                    className="h-8 w-full rounded-lg border border-border bg-surface text-sm text-text-primary ps-7 pe-2"
                  />
                </span>
              </div>
            )}

            {shown.length === 0 && (
              <p className="px-2 py-3 text-center text-xs text-text-muted">{t('none', ar)}</p>
            )}

            {shown.map((o) => {
              const on = values.includes(o.value)
              return (
                <button
                  key={o.value}
                  type="button"
                  role="option"
                  aria-selected={on}
                  onClick={() => toggle(o.value)}
                  className={`flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-start text-sm ${on ? 'bg-brand-500/10 font-semibold text-text-primary' : 'text-text-secondary hover:bg-surface-hover'}`}
                >
                  <span
                    aria-hidden
                    className={`inline-flex h-4 w-4 shrink-0 items-center justify-center rounded border ${on ? 'border-brand-600 bg-brand-600 text-white' : 'border-border'}`}
                  >
                    {on && <Check size={11} />}
                  </span>
                  <span className="truncate">{o.label}</span>
                </button>
              )
            })}

            {values.length > 0 && (
              <button
                type="button"
                onClick={() => onChange([])}
                className="mt-1 w-full rounded-lg px-2 py-1.5 text-xs font-semibold text-text-muted underline underline-offset-2 hover:text-text-primary"
              >
                {t('clear', ar)}
              </button>
            )}
          </div>
        )}
      </div>
    </div>
  )
}

/**
 * A short closed set, all of it on screen — the period, a handful of objectives.
 *
 * Chips rather than a select when every option fits, because seeing the alternatives is most of
 * what makes a filter discoverable. Above about six values this becomes a wall and `FilterMulti`
 * is the right control instead.
 */
export function FilterChips({
  label,
  value,
  options,
  onChange,
  testid,
}: {
  label: string
  value: string
  options: Array<{ value: string; label: string }>
  onChange: (value: string) => void
  testid?: string
}) {
  return (
    <div className="flex flex-col gap-1">
      <ControlLabel>{label}</ControlLabel>
      <div
        role="group"
        aria-label={label}
        data-testid={testid}
        className="inline-flex h-9 items-center rounded-xl border border-border bg-surface-secondary p-0.5"
      >
        {options.map((o) => (
          <button
            key={o.value}
            type="button"
            aria-pressed={value === o.value}
            onClick={() => onChange(o.value)}
            className={`h-8 rounded-lg px-2.5 text-sm font-semibold transition-colors ${
              value === o.value
                ? 'bg-brand-600 text-white shadow-[var(--shadow-small)]'
                : 'text-text-secondary hover:bg-surface-hover hover:text-text-primary'
            }`}
          >
            {o.label}
          </button>
        ))}
      </div>
    </div>
  )
}

/** The search box, which is how a person finds a row rather than how they configure a view. */
export function FilterSearch({
  value,
  onChange,
  placeholder,
  testid,
}: {
  value: string
  onChange: (value: string) => void
  placeholder: string
  testid?: string
}) {
  const id = useId()

  return (
    <div className="flex min-w-52 flex-1 flex-col gap-1">
      <ControlLabel htmlFor={id}>{placeholder}</ControlLabel>
      <span className="relative block">
        <Search size={15} aria-hidden className="absolute top-2.5 text-text-muted start-2.5" />
        <input
          id={id}
          type="search"
          data-testid={testid}
          value={value}
          aria-label={placeholder}
          onChange={(e) => onChange(e.target.value)}
          className="h-9 w-full rounded-xl border border-border bg-surface text-sm text-text-primary ps-8 pe-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40"
        />
      </span>
    </div>
  )
}

import type { ReactNode } from 'react'
import { MODE_LABELS } from './live/modes'

/**
 * REPORT-COMPOSITION-CONTROL-001 — a report's two levels, drawn once for every builder.
 *
 * The MAIN sections are the detailed link's tabs (Summary, Performance dashboard, Platforms, Content,
 * Tables); the COMPONENTS are the registry's sections, each listed under the main section it belongs
 * to. The saved-report controls and the live-link builder both render this tree, so the Owner meets
 * one vocabulary in both places — the control under each row is the caller's (a server-saved switch
 * there, a pre-creation checkbox here).
 *
 * A main section switched off keeps its components listed and dimmed: the choice inside it is kept
 * for when it is switched back on, and the server already withholds whatever no open view draws.
 */
export const VIEW_KEYS = ['summary', 'dashboard', 'platforms', 'content', 'tables'] as const
export type ViewKey = (typeof VIEW_KEYS)[number]

export interface TreeRow {
  key: string
  title_ar: string
  title_en: string
  view?: string
}

/** A view state: on, off, or fixed by a layer above this one (the report, for a link). */
export type ViewState = 'on' | 'off' | 'locked_off'

export function CompositionTree({
  rows,
  ar,
  offersViews,
  viewState,
  onView,
  viewDisabled = false,
  control,
  rowState,
  testid = 'composition-tree',
}: {
  rows: TreeRow[]
  ar: boolean
  /** Only the detailed product has main sections to switch; the others are one composition each. */
  offersViews: boolean
  viewState: (view: ViewKey) => ViewState
  onView: (view: ViewKey, on: boolean) => void
  viewDisabled?: boolean
  control: (row: TreeRow) => ReactNode
  /** Extra state on a row (e.g. withheld by the chosen form), as data attributes. */
  rowState?: (row: TreeRow) => string | undefined
  testid?: string
}) {
  const groups = VIEW_KEYS.map((view) => ({ view, items: rows.filter((r) => (r.view ?? 'dashboard') === view) }))
    .filter((g) => g.items.length > 0 || offersViews)
  const onCount = VIEW_KEYS.filter((v) => viewState(v) === 'on').length

  return (
    <div data-testid={testid} className="grid gap-2">
      {groups.map(({ view, items }) => {
        const state = offersViews ? viewState(view) : 'on'
        const label = ar ? MODE_LABELS[view].ar : MODE_LABELS[view].en
        // The last open main section cannot be closed: a report with no section has nothing to open on.
        const last = state === 'on' && onCount <= 1

        return (
          <section
            key={view}
            data-testid={`${testid}-view-${view}`}
            data-state={state}
            className="rounded-xl border border-border bg-surface"
          >
            <header className="flex items-center justify-between gap-2 border-b border-border px-3 py-2">
              <span className="text-sm font-bold text-text-primary">{label}</span>
              {offersViews && (state === 'locked_off' ? (
                <span data-testid={`${testid}-view-locked-${view}`} className="text-[11px] text-text-muted">
                  {ar ? 'مخفي في التقرير' : 'Hidden by the report'}
                </span>
              ) : (
                <label className="flex items-center gap-1.5 text-xs text-text-secondary">
                  {state === 'on' ? (ar ? 'ظاهر' : 'Shown') : (ar ? 'مخفي' : 'Hidden')}
                  <input
                    type="checkbox"
                    data-testid={`${testid}-view-toggle-${view}`}
                    checked={state === 'on'}
                    disabled={viewDisabled || last}
                    title={last ? (ar ? 'يلزم قسم رئيسي واحد على الأقل' : 'At least one main section is required') : undefined}
                    onChange={(e) => onView(view, e.target.checked)}
                    className="h-4 w-4 accent-brand-600"
                  />
                </label>
              ))}
            </header>
            {items.length > 0 && (
              <ul className={`grid gap-x-4 px-3 py-1 sm:grid-cols-2 ${state === 'on' ? '' : 'opacity-50'}`}>
                {items.map((row) => (
                  <li key={row.key} data-testid={`${testid}-row-${row.key}`} data-withheld={rowState?.(row)} className="flex min-w-0 items-center justify-between gap-2 py-1.5">
                    <span className="min-w-0 truncate text-sm text-text-primary">{ar ? row.title_ar : row.title_en}</span>
                    {control(row)}
                  </li>
                ))}
              </ul>
            )}
          </section>
        )
      })}
    </div>
  )
}

/** The list a builder sends: null (every view) when all are on, so an untouched choice stays sparse. */
export function viewsBody(on: Record<ViewKey, boolean>): ViewKey[] | null {
  const list = VIEW_KEYS.filter((v) => on[v])

  return list.length === VIEW_KEYS.length ? null : list
}

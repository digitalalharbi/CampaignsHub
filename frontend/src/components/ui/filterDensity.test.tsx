import { describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { FilterBar, FilterChips, FilterSelect } from './FilterBar'

/**
 * DASHBOARD-COMMAND-BAR-001 — a bar somebody READS past, and a bar somebody SLICES with.
 *
 * Six control groups on the dashboard wrapped onto three rows and put 195px between the header and
 * the first figure. The controls were not the problem: each one is a label on its own line above
 * its input, which is the right shape on a page opened to slice data and the wrong one on a page
 * opened to read it.
 *
 * `density` decides that, and these hold the two halves of it — that dense actually changes the
 * shape, and that the analysis surface's bar is untouched.
 */
function bar(density?: 'comfortable' | 'dense') {
  return render(
    <FilterBar id="t" ar density={density} applied={[]} onReset={vi.fn()}>
      <FilterChips
        label="الفترة"
        value="7"
        testid="t-period"
        options={[{ value: '7', label: '7' }, { value: '30', label: '30' }]}
        onChange={vi.fn()}
      />
      <FilterSelect
        label="الهدف"
        value="all"
        testid="t-objective"
        options={[{ value: 'all', label: 'الكل' }]}
        onChange={vi.fn()}
      />
    </FilterBar>,
  )
}

describe('the filter bar density', () => {
  /** Comfortable is what every page has had, and nothing here changes it. */
  it('stacks each label above its control by default', () => {
    bar()

    const row = screen.getByTestId('t-period').parentElement!

    expect(row.className).toContain('flex-col')
    expect(screen.getByTestId('t-filters').getAttribute('data-density')).toBe('comfortable')
  })

  /** Dense puts the label on the control's own line — one row instead of two, per control. */
  it('puts the label beside its control when dense', () => {
    bar('dense')

    const row = screen.getByTestId('t-period').parentElement!

    expect(row.className).not.toContain('flex-col')
    expect(row.className).toContain('items-center')
    expect(screen.getByTestId('t-filters').getAttribute('data-density')).toBe('dense')
  })

  /**
   * **And nothing is hidden to achieve it.**
   *
   * The compaction is a layout change, not a removal: every control the page passed is still in the
   * document and still labelled. A bar that got shorter by dropping a control would pass a height
   * measurement and fail the reader.
   */
  it('keeps every control, and every label, when dense', () => {
    bar('dense')

    expect(screen.getByTestId('t-period')).toBeInTheDocument()
    expect(screen.getByTestId('t-objective')).toBeInTheDocument()
    expect(screen.getByText('الفترة')).toBeInTheDocument()
    expect(screen.getByLabelText('الهدف')).toBeInTheDocument()
  })

  /**
   * A dense bar folds later than a comfortable one.
   *
   * Between 640px and the desktop breakpoint a dense bar's controls are wider — the label sits
   * beside them — and they wrapped into a block taller than the form this replaced. On a tablet it
   * therefore shows the same compact summary a phone shows.
   */
  it('folds on a tablet when dense, and only on a phone when comfortable', () => {
    const { unmount } = bar('dense')
    expect(screen.getByTestId('t-filters-toggle').className).toContain('lg:hidden')
    unmount()

    bar()
    expect(screen.getByTestId('t-filters-toggle').className).toContain('sm:hidden')
  })
})

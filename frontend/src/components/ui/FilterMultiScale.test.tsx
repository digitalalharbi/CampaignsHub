import { describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen } from '@testing-library/react'

import { FilterMulti } from './FilterBar'

/**
 * UX-MULTISELECT-SCALE-001 — the campaign selector at real production cardinality.
 *
 * `FilterMulti` rendered EVERY matching option into the DOM. Five campaigns is fine; a real account
 * has two hundred and an ad-level selector has thousands, and «every option, always» is the
 * full-estate DOM load the requirement forbids — a popover that takes a second to open and a
 * keystroke that re-renders the whole estate.
 *
 * Two properties are asserted here, and the second is the one that protects the money:
 *
 *   1. **The rendered list is bounded**, and the control SAYS how many it is not showing rather than
 *      quietly truncating. A list that silently stops at 100 tells a reader their campaign does not
 *      exist.
 *   2. **«Select all» is scoped to the results in front of you**, never to the estate. An ambiguous
 *      global select-all on a report builder is how a client report comes to include campaigns
 *      nobody chose — and the reader cannot tell from the output that it happened.
 */
const opts = (n: number, prefix = 'Campaign') =>
  Array.from({ length: n }, (_, i) => ({ value: `c-${i}`, label: `${prefix} ${i}` }))

const open = () => fireEvent.click(screen.getByTestId('sel'))

const renderMulti = (options: Array<{ value: string; label: string }>, values: string[] = []) => {
  const onChange = vi.fn()
  render(<FilterMulti label="Campaign" testid="sel" ar={false} values={values} options={options} onChange={onChange} />)

  return onChange
}

describe('the multi-select at production cardinality', () => {
  it('shows every option when there are few enough to show', () => {
    renderMulti(opts(5))
    open()

    expect(screen.getAllByRole('option')).toHaveLength(5)
    expect(screen.queryByTestId('sel-truncated')).not.toBeInTheDocument()
  })

  it('still shows all fifty — a medium account is not truncated', () => {
    renderMulti(opts(50))
    open()

    expect(screen.getAllByRole('option')).toHaveLength(50)
  })

  /*
   * The DOM stays bounded at 250. The exact cap matters less than that one exists and is stated:
   * an unbounded list is the difference between a popover that opens and one that hangs.
   */
  it('bounds the DOM at a large estate, and says what it is not showing', () => {
    renderMulti(opts(250))
    open()

    const rendered = screen.getAllByRole('option')
    expect(rendered.length).toBeLessThan(250)
    expect(rendered.length).toBeGreaterThan(0)

    // Not a silent truncation — a reader who cannot find their campaign is told to narrow, rather
    // than concluding it does not exist.
    expect(screen.getByTestId('sel-truncated')).toHaveTextContent('250')
  })

  it('lets search reach a campaign the cap had hidden', () => {
    renderMulti(opts(250))
    open()

    fireEvent.change(screen.getByRole('searchbox'), { target: { value: 'Campaign 249' } })

    expect(screen.getByRole('option', { name: /Campaign 249/ })).toBeInTheDocument()
    expect(screen.queryByTestId('sel-truncated')).not.toBeInTheDocument()
  })
})

describe('selecting many at once', () => {
  /*
   * The scoped bulk action. It names the number it will select, and that number is the number in
   * front of the reader — the filtered results, not the estate.
   */
  it('selects exactly the results currently shown, not the whole estate', () => {
    const onChange = renderMulti(opts(250))
    open()

    fireEvent.change(screen.getByRole('searchbox'), { target: { value: 'Campaign 1' } })

    const button = screen.getByTestId('sel-select-results')
    // «Campaign 1», «Campaign 1x», «Campaign 1xx» — 111 of the 250, and the button says so.
    expect(button).toHaveTextContent('111')

    fireEvent.click(button)

    const selected = onChange.mock.calls.at(-1)?.[0] as string[]
    expect(selected).toHaveLength(111)
    expect(selected).not.toContain('c-200')
  })

  it('adds to what was already chosen rather than replacing it', () => {
    const onChange = renderMulti(opts(20), ['c-19'])
    open()

    fireEvent.change(screen.getByRole('searchbox'), { target: { value: 'Campaign 1' } })
    fireEvent.click(screen.getByTestId('sel-select-results'))

    const selected = onChange.mock.calls.at(-1)?.[0] as string[]
    expect(selected).toContain('c-19')
    // …and no duplicates, because «Campaign 19» is in the results too.
    expect(new Set(selected).size).toBe(selected.length)
  })

  it('offers no bulk action when there is nothing to bulk-select', () => {
    renderMulti(opts(3))
    open()

    expect(screen.queryByTestId('sel-select-results')).not.toBeInTheDocument()
  })

  /* Clear still clears everything the reader had chosen, filtered view or not. */
  it('keeps Clear meaning all of it', () => {
    const onChange = renderMulti(opts(20), ['c-1', 'c-2'])
    open()

    fireEvent.click(screen.getByText('Clear'))

    expect(onChange).toHaveBeenLastCalledWith([])
  })
})

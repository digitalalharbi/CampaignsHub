import { useState } from 'react'
import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { FilterBar, FilterMulti, FilterSelect } from './FilterBar'

/**
 * The claim this component exists to make: **the daily filters are on the page.**
 *
 * These are not styling tests. Each one fails if a filter goes back behind a button, if a narrowed
 * page stops saying what narrowed it, or if removing one chip removes more than its own filter —
 * the three ways a filter bar quietly becomes a settings screen again.
 */
describe('FilterBar', () => {
  const platforms = [
    { value: 'meta', label: 'Meta' },
    { value: 'tiktok', label: 'TikTok' },
  ]

  it('renders its controls without anything being opened first', () => {
    render(
      <FilterBar id="dash" ar={false}>
        <FilterSelect label="Period" value="30" options={[{ value: '30', label: '30 days' }]} onChange={() => {}} />
        <FilterMulti label="Platform" values={[]} options={platforms} onChange={() => {}} ar={false} />
      </FilterBar>,
    )

    // Present in the document, not merely reachable from it.
    expect(screen.getByLabelText('Period')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Platform|All/ })).toBeInTheDocument()
    // And nothing had to be clicked to get there.
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  /** `More filters` is offered ONLY when a page actually has rare axes to fold. */
  it('offers no More filters button when there is nothing folded', () => {
    render(
      <FilterBar id="dash" ar={false}>
        <FilterSelect label="Period" value="30" options={[{ value: '30', label: '30 days' }]} onChange={() => {}} />
      </FilterBar>,
    )

    expect(screen.queryByTestId('dash-more-filters')).not.toBeInTheDocument()
  })

  /**
   * A chip removes its own filter and leaves the others standing.
   *
   * The failure this guards is the one that makes people distrust chips entirely: clicking × on
   * «Platform: Meta» clearing the client too, because the handler cleared an axis rather than a value.
   */
  it('removes exactly the filter whose chip was clicked', () => {
    const removeMeta = vi.fn()
    const removeClient = vi.fn()

    render(
      <FilterBar
        id="dash"
        ar={false}
        applied={[
          { key: 'providers:meta', axis: 'Platform', label: 'Meta', onRemove: removeMeta },
          { key: 'clients:c1', axis: 'Client', label: 'Nakheel', onRemove: removeClient },
        ]}
      >
        <span />
      </FilterBar>,
    )

    fireEvent.click(screen.getByRole('button', { name: 'Remove Platform: Meta' }))

    expect(removeMeta).toHaveBeenCalledTimes(1)
    expect(removeClient) .not.toHaveBeenCalled()
  })

  /** Nothing applied → no chip row at all, rather than an empty labelled row on every unfiltered page. */
  it('shows no applied row when nothing is narrowing the page', () => {
    render(
      <FilterBar id="dash" ar={false} onReset={() => {}}>
        <span />
      </FilterBar>,
    )

    expect(screen.queryByTestId('dash-applied')).not.toBeInTheDocument()
    expect(screen.queryByTestId('dash-reset')).not.toBeInTheDocument()
  })
})

describe('FilterMulti', () => {
  const clients = [
    { value: 'a', label: 'Alpha' },
    { value: 'b', label: 'Beta' },
    { value: 'c', label: 'Gamma' },
  ]

  /**
   * Closed, it still says what it is doing.
   *
   * One selection is NAMED and several collapse to a count — «Beta» tells the reader more than «1»,
   * and three names in a trigger button is a list nobody finishes reading.
   */
  it('names a single selection and counts several', () => {
    const { rerender } = render(
      <FilterMulti label="Client" values={['b']} options={clients} onChange={() => {}} ar={false} testid="client" />,
    )
    expect(screen.getByTestId('client')).toHaveTextContent('Beta')

    rerender(
      <FilterMulti label="Client" values={['a', 'b']} options={clients} onChange={() => {}} ar={false} testid="client" />,
    )
    expect(screen.getByTestId('client')).toHaveTextContent('2')
  })

  /**
   * An axis with no options still cannot be used — and it holds its place while it says so.
   *
   * This asserted `toBeEmptyDOMElement` until CLICK-STABLE-001, and the disappearing act was the
   * defect: the campaign axis has no options until its query returns, so the control arrived late
   * and shoved every control to its right — `More filters` among them — to a new position. A press
   * and its release have to reach the same element for a click to exist, so a control that moves in
   * between is a click that never happens. Three gate specs died of it on firefox.
   *
   * The original intent is kept by `disabled` rather than by absence: the control refuses the
   * interaction, it just refuses it without moving the page.
   */
  it('keeps its place but refuses the interaction when the axis has no options', () => {
    render(
      <FilterMulti label="Ad set" values={[]} options={[]} onChange={() => {}} ar={false} testid="adset" />,
    )

    const trigger = screen.getByTestId('adset')
    expect(trigger).toBeDisabled()
    expect(trigger).toHaveTextContent('No options')

    // And it cannot be opened by clicking it anyway.
    fireEvent.click(trigger)
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument()
  })

  it('adds to the selection rather than replacing it', () => {
    function Host() {
      const [values, setValues] = useState<string[]>(['a'])
      return (
        <FilterMulti label="Client" values={values} options={clients} onChange={setValues} ar={false} testid="client" />
      )
    }

    render(<Host />)
    fireEvent.click(screen.getByTestId('client'))
    fireEvent.click(screen.getByRole('option', { name: 'Gamma' }))

    // Two selected, so the trigger collapses to a count — which is the observable proof that the
    // first value survived the second click.
    expect(screen.getByTestId('client')).toHaveTextContent('2')
  })

  it('closes on Escape', () => {
    render(
      <FilterMulti label="Client" values={[]} options={clients} onChange={() => {}} ar={false} testid="client" />,
    )

    fireEvent.click(screen.getByTestId('client'))
    expect(screen.getByRole('listbox')).toBeInTheDocument()

    fireEvent.keyDown(document, { key: 'Escape' })
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument()
  })
})

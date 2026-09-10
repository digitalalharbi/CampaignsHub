import { describe, expect, it } from 'vitest'
import { render } from '@testing-library/react'
import { BudgetReading } from './BudgetReading'

/**
 * A panel with nothing to say renders nothing — it does not take the Budget tab down with it.
 *
 * The guard read `reading === undefined`, so a payload of `null` fell through to
 * `reading.signal?.fastest` and threw. React then unmounts the whole tab, not the one panel, and the
 * reader gets a blank Budget screen with no explanation. An endpoint answering `null` is not
 * hypothetical: `ApiResponse` carries whatever the service returned, and an install answering from
 * before this panel existed sends exactly that.
 */
describe('the budget reading with nothing to read', () => {
  it('renders nothing for an absent payload rather than throwing', () => {
    for (const absent of [undefined, null]) {
      const { container } = render(<BudgetReading reading={absent} locale="en" />)
      expect(container).toBeEmptyDOMElement()
    }
  })
})

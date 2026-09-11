import { describe, expect, it } from 'vitest'
import { fireEvent, render, screen } from '@testing-library/react'
import { FilterMulti } from '@/components/ui/FilterBar'
import { CANONICAL_OBJECTIVE_KEYS, CANONICAL_OBJECTIVE_RAW, canonicalObjectiveLabel } from '@/features/campaigns/canonicalObjectives'

/**
 * CONTENT-FILTER-TRUTH-001 — Content and Analytics name objectives the same way.
 *
 * The owner's production screenshot shows Content offering «الوعي · التحويلات · المبيعات · الزيارات»
 * — RAW provider objectives — with a second «المسار التسويقي» control beside them over the same
 * server axis. Analytics has used the five PRODUCT objectives since ANALYTICS-OBJECTIVE-SYSTEM-001,
 * so the two surfaces disagreed about what an objective is, and «المبيعات» on Content excluded every
 * `conversions` campaign while offering «التحويلات» as its rival.
 *
 * The backend half is `ContentObjectiveTaxonomyTest`. This holds the half a reader can see.
 */
describe('the Content library speaks the product’s objective vocabulary', () => {
  /**
   * The taxonomy itself, asserted against the file both surfaces read.
   *
   * Not «Content renders five options» — that would pass if Content grew its own list of five with
   * different words in it. The claim is that there is ONE list.
   */
  it('offers exactly the five canonical objectives, and Sales covers its whole raw family', () => {
    expect(CANONICAL_OBJECTIVE_KEYS).toEqual([
      'awareness_engagement',
      'traffic',
      'leads',
      'app_promotion',
      'sales',
    ])

    /* «Do NOT expose raw Conversions as a separate visible objective beside Sales.» */
    expect(CANONICAL_OBJECTIVE_KEYS).not.toContain('conversions')
    expect(CANONICAL_OBJECTIVE_KEYS).not.toContain('purchases')
    expect(CANONICAL_OBJECTIVE_KEYS).not.toContain('add_to_cart')

    expect(CANONICAL_OBJECTIVE_RAW.sales).toEqual(['sales', 'conversions', 'add_to_cart', 'purchases'])
  })

  /**
   * A RAW value still reads as a word rather than taking the page down.
   *
   * The library accepted raw objectives for years, so a bookmark or a saved view carries one. The
   * label function used to index a map and throw on a miss — a blank page, not a wrong word — and
   * making those values reachable is exactly what this unit does.
   */
  it('labels a raw objective by the canonical one that covers it', () => {
    expect(canonicalObjectiveLabel('conversions', 'ar')).toBe(canonicalObjectiveLabel('sales', 'ar'))
    expect(canonicalObjectiveLabel('purchases', 'en')).toBe('Sales')

    /* And something nobody has mapped shows as itself rather than as an empty space or a crash. */
    expect(canonicalObjectiveLabel('some_new_provider_objective', 'en')).toBe('some_new_provider_objective')
  })
})

/**
 * An option with nothing behind it is SHOWN, disabled, with its zero.
 *
 * «Never show a selectable option that is known to have zero matching rows without clearly
 * disabling/stating it.» Hiding it would be the other failure the library already records: a
 * vocabulary that looks smaller than it is, so an operator concludes the account has no collection
 * ads rather than that the picker has no word for them.
 *
 * Asserted on the CONTROL rather than through the page: this is `FilterMulti`'s contract, every axis
 * with a closed vocabulary depends on it, and a page-level test would prove it for one of them.
 */
describe('a filter option that reaches nothing', () => {
  const options = [
    { value: 'sales', label: 'Sales', count: 4 },
    { value: 'traffic', label: 'Traffic', count: 0 },
    { value: 'leads', label: 'Leads', count: 2 },
  ]

  const open = () => {
    render(<FilterMulti label="Objective" ar={false} testid="objectives" values={[]} options={options} onChange={() => {}} />)
    fireEvent.click(screen.getByTestId('objectives'))
  }

  it('is offered, disabled, with its count beside it', () => {
    open()

    const traffic = screen.getByRole('option', { name: /Traffic/ })

    expect(traffic).toBeDisabled()
    expect(traffic).toHaveAttribute('data-count', '0')
    expect(traffic.textContent).toContain('0')
  })

  it('leaves the reachable ones operable, with their own counts', () => {
    open()

    const sales = screen.getByRole('option', { name: /Sales/ })

    expect(sales).not.toBeDisabled()
    expect(sales).toHaveAttribute('data-count', '4')
  })

  /**
   * And a CHOSEN option stays operable at zero, or a reader could not undo their own narrowing.
   *
   * This is the case a naive «disable when count is 0» gets wrong: the moment a choice empties the
   * result, the control that would take it back becomes unclickable.
   */
  it('keeps a chosen option clickable even when it now reaches nothing', () => {
    render(<FilterMulti label="Objective" ar={false} testid="objectives" values={['traffic']} options={options} onChange={() => {}} />)
    fireEvent.click(screen.getByTestId('objectives'))

    expect(screen.getByRole('option', { name: /Traffic/ })).not.toBeDisabled()
  })
})

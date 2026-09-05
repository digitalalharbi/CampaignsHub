import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { DistributionBars } from './DistributionBars'
import { renderWithProviders } from '@/test/utils'

/**
 * VISUAL-FIRST-001 / clause D — «CAMPAIGN + AD-SET DISTRIBUTION → contribution/distribution bars».
 *
 * The block above this one decomposes what MOVED. This shows where the money SITS — a different
 * question, and one a change decomposition can never answer: an account can be perfectly stable and
 * still hold most of its budget behind one line.
 *
 * Everything below is the money contract at this grain. A share needs a total and a total needs
 * every part, so a row whose spend cannot be stated in the reporting currency makes the whole block
 * decline. Summing the rest would divide by an incomplete total and every share would be too large
 * — while looking exactly like a correct one.
 */
const row = (key: string, spend: number | null, over: Record<string, unknown> = {}) => ({
  key,
  label: key,
  totals: {
    spend,
    spend_converted: spend,
    spend_original: spend,
    spend_withheld_rows: 0,
    money_original_currencies: 1,
    money_original_currency: 'SAR',
    ...over,
  } as never,
})

describe('where the spend sits', () => {
  it('ranks the lines and names the concentration', () => {
    renderWithProviders(
      <DistributionBars testid="d" title="Where the spend sits" currency="SAR" ar={false}
        rows={[row('small', 1000), row('big', 9000)]} />,
      { locale: 'en' },
    )

    expect(screen.getByTestId('d-concentration')).toHaveTextContent('90%')
    expect(screen.getAllByTestId('d-row')).toHaveLength(2)
  })

  /** One line is not a distribution — there is nothing to compare it against. */
  it('renders nothing for a single line', () => {
    const { container } = renderWithProviders(
      <DistributionBars testid="d" title="t" currency="SAR" ar={false} rows={[row('only', 5000)]} />,
      { locale: 'en' },
    )

    expect(container).toBeEmptyDOMElement()
  })

  /**
   * A withheld row is REAL money in another unit. The block declines rather than dividing by a
   * total missing it — this is the defect the money contract exists to prevent, one grain down.
   */
  it('declines when a row’s spend cannot be stated in the reporting currency', () => {
    renderWithProviders(
      <DistributionBars testid="d" title="t" currency="SAR" ar={false}
        rows={[
          row('ok', 9000),
          row('withheld', null, { spend_converted: null, spend_original: 4000, spend_withheld_rows: 3 }),
        ]} />,
      { locale: 'en' },
    )

    expect(screen.getByTestId('d-declined')).toHaveTextContent(/could not be read in the reporting currency/)
    expect(screen.queryByTestId('d-row')).not.toBeInTheDocument()
  })

  /**
   * A row that recorded NO spend is not the same thing. It is simply not part of the distribution,
   * and excluding it changes no other row's share — so the block still draws.
   */
  it('still draws when a row simply has no spend', () => {
    renderWithProviders(
      <DistributionBars testid="d" title="t" currency="SAR" ar={false}
        rows={[row('ok', 9000), row('none', 1000), row('absent', null, { spend_converted: null, spend_original: null, money_original_currencies: 0 })]} />,
      { locale: 'en' },
    )

    expect(screen.queryByTestId('d-declined')).not.toBeInTheDocument()
    expect(screen.getAllByTestId('d-row').length).toBeGreaterThanOrEqual(2)
  })

  /** No spend at all is «nothing to distribute», not a row of empty bars. */
  it('declines when nothing was spent', () => {
    renderWithProviders(
      <DistributionBars testid="d" title="t" currency="SAR" ar={false} rows={[row('a', 0), row('b', 0)]} />,
      { locale: 'en' },
    )

    expect(screen.getByTestId('d-declined')).toHaveTextContent(/no spend in this period/i)
  })
})

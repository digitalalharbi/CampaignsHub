import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { StatCard, StatGrid } from './StatCard'
import { renderWithProviders } from '@/test/utils'

/**
 * UX-KPI-PRESENTATION-001 — one card, and the direction rule that was actually wrong.
 *
 * Nine components drew a labelled number and agreed on the idea and on nothing else: `p-3.5` against
 * `p-4`, `text-sm` labels against `text-xs`, some giving the value `dir="ltr"` and some not. A
 * customer moving between two pages of one product met two designs of the same object, and a row of
 * cards drawn by two of them did not line up.
 */
describe('the value is always written left to right', () => {
  /**
   * A number is written LTR in every language. In an Arabic layout an unmarked figure with a suffix —
   * «1.2K SAR», «-12%» — is reordered by the bidi algorithm: the currency can end up in front of the
   * amount, and a minus sign at the wrong end of a delta. `CampaignsPage` drew its value with no
   * `dir` at all.
   */
  it('marks the figure ltr in Arabic', () => {
    renderWithProviders(<StatCard label="الإنفاق" value="1.2K SAR" testid="spend" />, { locale: 'ar' })

    expect(screen.getByTestId('spend-value')).toHaveAttribute('dir', 'ltr')
  })

  /**
   * And that is NOT the same as aligning it left.
   *
   * `text-start` keeps the number under its own label, at the side the reader starts from — the
   * right edge in Arabic. Conflating the two is how a card ends up with its label on one side and
   * its figure on the other, which is the complaint the requirement opens with.
   */
  it('starts the figure where the reader starts, not on the left', () => {
    renderWithProviders(<StatCard label="الإنفاق" value="1.2K SAR" testid="spend" />, { locale: 'ar' })

    const cls = screen.getByTestId('spend-value').className
    expect(cls).toContain('text-start')
    expect(cls).not.toContain('text-left')
  })
})

describe('the cards line up', () => {
  /** A card with a hint and a card without one are the same height, or a row reads as an accident. */
  it('reserves the value row whether or not there is a hint', () => {
    renderWithProviders(
      <>
        <StatCard label="A" value="1" testid="a" />
        <StatCard label="B" value="2" hint="since Monday" testid="b" />
      </>,
      { locale: 'en' },
    )

    expect(screen.getByTestId('a-value').className).toContain('min-h-8')
    expect(screen.getByTestId('b-value').className).toContain('min-h-8')
  })

  /**
   * Five KPIs in a four-column grid leave one card alone on a row looking like a mistake. `auto-fit`
   * lets the cards share the row they have, which is what «additional KPIs shown elegantly rather
   * than as random uneven cards» asks for.
   */
  it('lays out on auto-fit rather than a fixed column count', () => {
    const { container } = renderWithProviders(
      <StatGrid>
        <StatCard label="A" value="1" />
      </StatGrid>,
      { locale: 'en' },
    )

    const grid = container.querySelector('[style*="grid-template-columns"]') as HTMLElement

    expect(grid.style.gridTemplateColumns).toContain('auto-fit')
    // The minimum is what stops a number being squeezed until it wraps mid-figure.
    expect(grid.style.gridTemplateColumns).toContain('minmax')
  })

  it('inherits the reading direction of the page', () => {
    const { container } = renderWithProviders(<StatGrid><StatCard label="A" value="1" /></StatGrid>, { locale: 'ar' })

    expect(container.querySelector('[dir="rtl"]')).not.toBeNull()
  })
})

/** NUMBER-PRESENTATION-001 — the compact figure keeps the exact one within reach. */
describe('the exact figure', () => {
  it('hangs on the value when the display abbreviated it', () => {
    renderWithProviders(<StatCard label="Spend" value="4.85M SAR" exact="4,850,321 SAR" testid="spend" />, { locale: 'en' })

    expect(screen.getByTestId('spend-value')).toHaveAttribute('title', '4,850,321 SAR')
  })

  it('attaches nothing when there is nothing to reveal', () => {
    renderWithProviders(<StatCard label="Spend" value="940 SAR" testid="spend" />, { locale: 'en' })

    expect(screen.getByTestId('spend-value')).not.toHaveAttribute('title')
  })
})

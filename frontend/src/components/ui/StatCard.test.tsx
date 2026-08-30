import { describe, expect, it } from 'vitest'
import { screen, within } from '@testing-library/react'
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

    expect(screen.getByTestId('a-value').className).toContain('min-h-9')
    expect(screen.getByTestId('b-value').className).toContain('min-h-9')
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

/**
 * UX-KPI-PRESENTATION-001 acceptance — the SAME KPI set, read in both languages.
 *
 * The separate direction tests above each hold one rule. What the requirement asks for is the
 * comparison: the same five figures, rendered twice, differing in the ONE property that is supposed
 * to differ. A card that quietly picks a different size, weight or alignment in Arabic is the defect
 * the whole consolidation exists to remove, and it is invisible to a test that only ever renders one
 * language.
 */
describe('one KPI set, both directions', () => {
  const SET = [
    { label: 'Spend', ar: 'الإنفاق', value: '32.4K SAR' },
    { label: 'Results', ar: 'النتائج', value: '1,204' },
    { label: 'CPA', ar: 'تكلفة النتيجة', value: '21 SAR' },
    { label: 'ROAS', ar: 'العائد', value: '15.36×' },
    { label: 'CTR', ar: 'نسبة النقر', value: '2.4%' },
  ]

  const renderSet = (locale: 'ar' | 'en') =>
    renderWithProviders(
      <StatGrid>
        {SET.map((k, i) => (
          <StatCard key={k.label} label={locale === 'ar' ? k.ar : k.label} value={k.value} testid={`k${i}`} />
        ))}
      </StatGrid>,
      { locale },
    )

  it('gives every figure the same size, weight and alignment in Arabic as in English', () => {
    const en = renderSet('en')
    const enClasses = SET.map((_, i) => within(en.container).getByTestId(`k${i}-value`).className)
    en.unmount()

    const ar = renderSet('ar')
    const arClasses = SET.map((_, i) => within(ar.container).getByTestId(`k${i}-value`).className)

    expect(arClasses).toEqual(enClasses)
    // And every one of them is still marked as a number, in both.
    for (let i = 0; i < SET.length; i += 1) {
      expect(within(ar.container).getByTestId(`k${i}-value`)).toHaveAttribute('dir', 'ltr')
    }
  })

  it('turns the grid, and only the grid, around', () => {
    const en = renderSet('en')
    expect(en.container.querySelector('[style*="grid-template-columns"]')).toHaveAttribute('dir', 'ltr')
    en.unmount()

    const ar = renderSet('ar')
    expect(ar.container.querySelector('[style*="grid-template-columns"]')).toHaveAttribute('dir', 'rtl')
  })

  /**
   * A surface that adds a sixth KPI cannot produce a ragged final row.
   *
   * With a fixed column count it can: five cards in a four-column grid leave one alone, and the
   * «additional KPIs» a page grows over time are exactly how that happens. This asserts the grid
   * carries NO fixed column class at any breakpoint — the property that makes the tail impossible
   * rather than merely absent today.
   */
  it('cannot be given a ragged tail by adding one more figure', () => {
    for (const count of [5, 6, 7, 8]) {
      const view = renderWithProviders(
        <StatGrid>
          {Array.from({ length: count }, (_, i) => <StatCard key={i} label={`K${i}`} value="1" />)}
        </StatGrid>,
        { locale: 'en' },
      )

      const grid = view.container.querySelector('[style*="grid-template-columns"]') as HTMLElement
      expect(grid.className, `${count} cards`).not.toMatch(/grid-cols-\d/)
      expect(grid.style.gridTemplateColumns).toContain('auto-fit')
      view.unmount()
    }
  })
})

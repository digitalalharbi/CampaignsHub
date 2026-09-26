import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { PageIntro } from './PageIntro'

/**
 * UX-PAGE-HERO-001 — the product's own header, and what it is allowed to leave out.
 *
 * Ninety-two surfaces drew their own `<h1>` and five used this one, so the shared header was the
 * minority spelling and every page that opted out also lost the purpose line, the badge row and the
 * wrapping rule that keeps a header off a phone's horizontal scrollbar.
 *
 * The three additions are asserted here because each replaces something a surface previously had to
 * invent: the scope it is showing, the figures it is about, and the freedom to say nothing.
 */
describe('the page hero', () => {
  it('says which scope the page is showing, above the title', () => {
    render(<PageIntro title="التقارير" eyebrow="محفظة العميل" testid="h" />)

    expect(screen.getByTestId('h-eyebrow').textContent).toBe('محفظة العميل')
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('التقارير')
  })

  /**
   * `purpose` was required, so a surface with nothing useful to say wrote something anyway. Filler
   * under a title is worse than a title alone, so silence has to be expressible.
   */
  it('renders no purpose line when there is nothing worth saying', () => {
    const { container } = render(<PageIntro title="Reports" testid="h" />)

    expect(container.querySelector('p')).toBeNull()
  })

  it('still renders a purpose when one is given', () => {
    render(<PageIntro title="Reports" purpose="What you send a client." testid="h" />)

    expect(screen.getByText('What you send a client.')).toBeInTheDocument()
  })

  /** A page that leads with prose makes its reader read to find out how they are doing. */
  it('carries the figures in the header rather than below the fold', () => {
    render(<PageIntro title="Reports" testid="h" kpis={<span>spend</span>} />)

    expect(screen.getByTestId('h-kpis').textContent).toContain('spend')
  })

  it('omits the figure row entirely when a page has none', () => {
    render(<PageIntro title="Reports" testid="h" />)

    expect(screen.queryByTestId('h-kpis')).toBeNull()
  })

  /**
   * The wrapping rule this component exists for: a header row that cannot wrap is the most common
   * cause of a page that scrolls sideways, and content reachable only by dragging is content a phone
   * user will not find.
   */
  it('keeps the title row and the actions wrappable', () => {
    render(<PageIntro title="Reports" actions={<button type="button">New</button>} testid="h" />)

    const row = screen.getByRole('heading', { level: 1 }).closest('div')?.parentElement?.parentElement
    expect(row?.className).toContain('flex-wrap')
  })
})

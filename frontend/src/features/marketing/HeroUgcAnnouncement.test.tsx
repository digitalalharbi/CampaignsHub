import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, within } from '@testing-library/react'
import { PublicHomePage } from './PublicHomePage'
import { renderWithProviders, signOut } from '@/test/utils'

/**
 * MKT-UGC-002 — the hero chooser announces influencer & UGC without offering it.
 *
 * `offeredCopy()` used to delete the influencer path outright while `features.influencersUgc` was
 * false, which is why the service is invisible in the hero: a visitor who wants influencer or UGC
 * work reads three options, none of which is theirs, and concludes the product does not do it.
 *
 * Announcing it has to hold two claims at once, and they pull in opposite directions — the visitor
 * must SEE the service and must not be able to ASK for it. Every test below asserts one of the two,
 * because a card that satisfies only the first is a promise the switched-off sub-system cannot keep,
 * and a card that satisfies only the second is the state this ticket exists to end.
 */
describe('the hero chooser — influencer & UGC, announced', () => {
  afterEach(signOut)
  beforeEach(signOut)

  /** The chooser's own list, so «after» below is measured against siblings and nothing else. */
  const paths = (): HTMLElement[] => {
    const soon = screen.getByTestId('hero-path-soon-influencer')
    const list = soon.parentElement
    if (list === null) throw new Error('the announced card is not inside the chooser list')

    return Array.from(list.children) as HTMLElement[]
  }

  it('announces the service in Arabic, with the copy the rest of the page already uses', () => {
    renderWithProviders(<PublicHomePage />, { route: '/', locale: 'ar' })

    const card = screen.getByTestId('hero-path-soon-influencer')
    expect(within(card).getByText('علاقات المؤثرين وUGC')).toBeInTheDocument()
    expect(within(card).getByText('إدارة حملات المؤثرين وصنّاع المحتوى والتعاونات من مكان واحد.')).toBeInTheDocument()
    expect(within(card).getByText('قريبًا')).toBeInTheDocument()
  })

  it('announces it in English too', () => {
    renderWithProviders(<PublicHomePage />, { route: '/', locale: 'en' })

    const card = screen.getByTestId('hero-path-soon-influencer')
    expect(within(card).getByText('Influencer relations & UGC')).toBeInTheDocument()
    expect(within(card).getByText('Run influencer campaigns, content creators and collaborations from one place.')).toBeInTheDocument()
    expect(within(card).getByText('Coming soon')).toBeInTheDocument()
  })

  /**
   * Directly below «أحتاج خدمات إعلانية» — asserted as ADJACENCY, not as «somewhere after».
   * The requirement is a position in a list of four, and «index is greater» would still pass if a
   * fifth card were inserted between them.
   */
  it('sits immediately below the paid-services path', () => {
    renderWithProviders(<PublicHomePage />, { route: '/', locale: 'ar' })

    const keys = paths().map((el) => el.getAttribute('data-testid'))
    expect(keys).toEqual([
      'hero-path-self-service',
      'hero-path-multi-client',
      'hero-path-services',
      'hero-path-soon-influencer',
    ])
  })

  /**
   * The claim that matters. Not «the click does nothing» — there is nothing to click: no button, no
   * link, no radio state, and `aria-disabled` so a screen reader is told the same thing the badge
   * tells everyone else.
   */
  it('cannot be selected, followed, or pressed', () => {
    renderWithProviders(<PublicHomePage />, { route: '/', locale: 'ar' })

    const card = screen.getByTestId('hero-path-soon-influencer')
    expect(card.tagName).not.toBe('BUTTON')
    expect(card).toHaveAttribute('aria-disabled', 'true')
    expect(card).not.toHaveAttribute('aria-pressed')
    expect(within(card).queryAllByRole('button')).toHaveLength(0)
    expect(within(card).queryAllByRole('link')).toHaveLength(0)
  })

  /**
   * The regression this design could most easily cause: the announced path is now IN `start.paths`,
   * and three surfaces map that array into `journeyTo()`. Any one of them left unfiltered puts the
   * influencer intake back on the page while the backend still refuses it.
   */
  it('puts no route into the withdrawn sub-system anywhere on the page', () => {
    renderWithProviders(<PublicHomePage />, { route: '/', locale: 'ar' })

    const hrefs = screen.getAllByRole('link').map((a) => a.getAttribute('href'))
    expect(hrefs.some((h) => h?.includes('influencer'))).toBe(false)
    expect(hrefs).not.toContain('/requests/new?module=influencer-marketing')
  })

  /** Announced OR offered, never both: the real journey's own control must not also be rendered. */
  it('does not render the real influencer journey beside the announcement', () => {
    renderWithProviders(<PublicHomePage />, { route: '/', locale: 'ar' })

    expect(screen.queryByTestId('hero-path-influencer')).not.toBeInTheDocument()
    expect(screen.queryByTestId('hero-journey-link-influencer')).not.toBeInTheDocument()
    expect(screen.queryByTestId('closing-journey-influencer')).not.toBeInTheDocument()
  })
})

/**
 * The other side of the flag.
 *
 * `features` reads `import.meta.env` once at import, so the ON state cannot be reached by flipping a
 * value inside a running test — the module has to be re-evaluated. The assertion is made against the
 * copy layer because that is where the decision is taken; the chooser only renders what it is given,
 * and a path with `to` and no `soon` is by construction the ordinary selectable card.
 */
describe('the hero chooser — influencer & UGC, offered', () => {
  afterEach(() => {
    vi.unstubAllEnvs()
    vi.resetModules()
  })

  it('restores the real journey and drops the announcement when the feature is on', async () => {
    vi.stubEnv('VITE_INFLUENCERS_UGC', 'true')
    vi.resetModules()

    const { HOME_COPY } = await import('./homeCopy')

    for (const locale of ['ar', 'en'] as const) {
      const influencer = HOME_COPY[locale].start.paths.filter((p) => p.key === 'influencer')

      // Exactly one — never an announcement standing next to the journey it announces.
      expect(influencer).toHaveLength(1)
      expect(influencer[0].soon).toBeUndefined()
      expect(influencer[0].badge).toBeUndefined()
      expect(influencer[0].to).toBe('/requests/new?module=influencer-marketing')
      expect(influencer[0].includes.length).toBeGreaterThan(0)
    }
  })
})

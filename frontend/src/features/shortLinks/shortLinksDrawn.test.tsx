import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { ShortLinksPage } from './ShortLinksPage'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', async (orig) => ({
  ...(await orig<Record<string, unknown>>()),
  listShortLinks: vi.fn(), createShortLink: vi.fn(), deleteShortLink: vi.fn(),
}))

import { listShortLinks } from './api'

/**
 * VIZ-OPS-001 — which links people actually press.
 *
 * The page lists every link with its click count and totals them into one card. «Which of these is
 * doing the work» is a comparison a reader makes by scanning a column, and it is the only question a
 * short-link library exists to answer.
 *
 * ## What it does not claim
 *
 * There is no per-day series on this payload, so there is no trend and none is implied — only a
 * ranking of what has been counted. A link nobody has pressed is a reported zero, not a missing
 * figure, so it stays in the ranking at the bottom rather than being withheld.
 */
const link = (slug: string, clicks: number) => ({
  id: slug, slug, kind: 'link' as const, short_url: `https://x.test/${slug}`,
  shows: 'https://example.test', clicks, last_clicked_at: null, is_active: true, created_at: null,
})

describe('the short link library, drawn', () => {
  beforeEach(() => vi.clearAllMocks())

  it('ranks the links by the clicks they were given', async () => {
    vi.mocked(listShortLinks).mockResolvedValue([link('a', 3), link('b', 90), link('c', 40)] as never)
    renderWithProviders(<ShortLinksPage />, { locale: 'en' })

    expect(await screen.findByTestId('short-link-clicks-chart')).toBeInTheDocument()
    const legend = screen.getByTestId('short-link-clicks-legend')
    expect(legend.textContent?.indexOf('b')).toBeLessThan(legend.textContent?.indexOf('c') ?? -1)
  })

  it('keeps a link nobody has pressed, because a reported zero is a figure', async () => {
    vi.mocked(listShortLinks).mockResolvedValue([link('busy', 50), link('quiet', 0)] as never)
    renderWithProviders(<ShortLinksPage />, { locale: 'en' })

    expect(await screen.findByTestId('short-link-clicks-legend')).toHaveTextContent('quiet')
  })

  it('draws nothing when a single link cannot be compared with anything', async () => {
    // One bar is not a ranking; it is the same figure the card above already states.
    vi.mocked(listShortLinks).mockResolvedValue([link('only', 12)] as never)
    renderWithProviders(<ShortLinksPage />, { locale: 'en' })

    await screen.findByTestId('short-links-kpi-clicks')
    expect(screen.queryByTestId('short-link-clicks-chart')).toBeNull()
  })

  it('draws nothing when no link has ever been pressed', async () => {
    // A ranking of zeros ranks nothing, and bars of equal length imply a comparison nobody can make.
    vi.mocked(listShortLinks).mockResolvedValue([link('a', 0), link('b', 0)] as never)
    renderWithProviders(<ShortLinksPage />, { locale: 'en' })

    await screen.findByTestId('short-links-kpi-clicks')
    expect(screen.queryByTestId('short-link-clicks-chart')).toBeNull()
  })
})

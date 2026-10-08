import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import { ShortLinksPage } from './ShortLinksPage'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

vi.mock('./api', async (orig) => ({
  ...(await (orig() as Promise<Record<string, unknown>>)),
  listShortLinksWithMeta: vi.fn(),
  createShortLink: vi.fn(),
  disableShortLink: vi.fn(),
  deleteShortLink: vi.fn(),
}))

import { listShortLinksWithMeta } from './api'

/**
 * SHORT-LINK-HOPS-001 — the curve says when, and says what it cannot speak for.
 *
 * A counter that only goes up cannot distinguish a link followed three hundred times last spring
 * from one followed three hundred times this week. The curve can — but only back to the day this
 * installation started writing follows down, and every link older than that has a real total and no
 * history at all.
 *
 * The failure worth guarding is the comfortable one: filling the untimed period with zeroes and
 * letting a reader take a flat line for a quiet month. These hold the three states apart — nothing
 * recorded, partially recorded, fully recorded — because only the middle one is visibly ambiguous
 * and it is the one most installations will actually be in.
 */
const link = (slug: string, clicks: number, recorded: number | null) => ({
  id: `id-${slug}`,
  slug,
  kind: 'link' as const,
  short_url: `https://campaignshub.io/l/${slug}`,
  shows: 'https://example.com/offer',
  clicks,
  recorded_follows: recorded,
  last_clicked_at: null,
  is_active: true,
  created_at: null,
})

describe('the short link trend', () => {
  beforeEach(() => {
    signInWith(['campaigns.view', 'campaigns.update'])
  })

  afterEach(() => {
    signOut()
    vi.clearAllMocks()
  })

  it('draws no curve, and says why, when no follow has ever been timed', async () => {
    vi.mocked(listShortLinksWithMeta).mockResolvedValue({
      data: [link('a', 85, 0)],
      meta: { recording_since: null, daily: [] },
    } as never)

    renderWithProviders(<ShortLinksPage />, { locale: 'en' })

    expect(await screen.findByTestId('short-link-trend-unrecorded')).toBeInTheDocument()
    /* And it must not quietly draw a flat line instead. */
    expect(screen.queryByTestId('short-link-trend-chart')).not.toBeInTheDocument()
  })

  it('draws the curve and states the day recording began', async () => {
    vi.mocked(listShortLinksWithMeta).mockResolvedValue({
      data: [link('a', 12, 12)],
      meta: {
        recording_since: '2026-10-01T00:00:00+00:00',
        daily: [
          { day: '2026-10-01', follows: 4 },
          { day: '2026-10-02', follows: 0 },
          { day: '2026-10-03', follows: 8 },
        ],
      },
    } as never)

    renderWithProviders(<ShortLinksPage />, { locale: 'en' })

    expect(await screen.findByTestId('short-link-trend-chart')).toBeInTheDocument()
    expect(screen.getByTestId('short-link-trend-since')).toHaveTextContent('2026-10-01')
  })

  /**
   * The case this unit exists for.
   *
   * 12 follows timed against 85 counted: the curve is real and it is not the story. Saying so is
   * the difference between a chart a reader can act on and one that quietly understates a link.
   */
  it('says how much of the counted total the curve can account for', async () => {
    vi.mocked(listShortLinksWithMeta).mockResolvedValue({
      data: [link('a', 85, 12)],
      meta: {
        recording_since: '2026-10-01T00:00:00+00:00',
        daily: [{ day: '2026-10-01', follows: 12 }],
      },
    } as never)

    renderWithProviders(<ShortLinksPage />, { locale: 'en' })

    const note = await screen.findByTestId('short-link-trend-partial')

    expect(note).toHaveTextContent('12')
    expect(note).toHaveTextContent('85')
  })

  it('stays quiet about a gap when there is none', async () => {
    vi.mocked(listShortLinksWithMeta).mockResolvedValue({
      data: [link('a', 9, 9)],
      meta: {
        recording_since: '2026-10-01T00:00:00+00:00',
        daily: [{ day: '2026-10-01', follows: 9 }],
      },
    } as never)

    renderWithProviders(<ShortLinksPage />, { locale: 'en' })

    await screen.findByTestId('short-link-trend-chart')

    await waitFor(() => {
      expect(screen.queryByTestId('short-link-trend-partial')).not.toBeInTheDocument()
    })
  })

  /* No links at all is the empty state's job, not the trend's — it has nothing to be a trend OF. */
  it('shows no trend card at all when there are no links', async () => {
    vi.mocked(listShortLinksWithMeta).mockResolvedValue({
      data: [],
      meta: { recording_since: null, daily: [] },
    } as never)

    renderWithProviders(<ShortLinksPage />, { locale: 'en' })

    await waitFor(() => {
      expect(screen.queryByTestId('short-link-trend-unrecorded')).not.toBeInTheDocument()
      expect(screen.queryByTestId('short-link-trend-chart')).not.toBeInTheDocument()
    })
  })
})

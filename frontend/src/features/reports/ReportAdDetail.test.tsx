import { describe, expect, it, vi } from 'vitest'
import { fireEvent, screen } from '@testing-library/react'
import { ReportAdDetail } from './ReportAdDetail'
import { ReportAdsSection, type ReportAd } from './ReportAdsSection'
import { renderWithProviders } from '@/test/utils'

/**
 * REPORT-AD-PREVIEW-001 §C — the ad card opens, and what opens is bounded by the share.
 *
 * Production rendered the cards as `<article>` with no handler: a client pressing the ad they were
 * deciding about got nothing. Four things are pinned here, and the last two are the ones a shared
 * link makes dangerous to get wrong:
 *
 *   1. a card that can open says so in the DOM, and a card that cannot does not pretend;
 *   2. the modal can be left — by the close button, by Escape, by the backdrop;
 *   3. money in it carries the scope's own currency, or no currency word at all;
 *   4. an unmeasured figure is absent rather than printed as a zero with a unit on it.
 */
const preview = (over: Record<string, unknown> = {}) => ({
  state: 'available',
  kind: 'image',
  image_url: 'https://cdn/ad.jpg',
  video_url: null,
  thumbnail_url: null,
  expires_at: null,
  note_ar: null,
  note_en: null,
  cards: null,
  ...over,
}) as ReportAd['preview']

const ad = (over: Partial<ReportAd> = {}): ReportAd => ({
  id: 'a1',
  name: 'Eid film',
  provider: 'meta',
  campaign_name: 'Eid sales',
  objective: 'sales',
  preview: preview(),
  spend: 3000,
  impressions: 120_000,
  clicks: 3400,
  conversions: 88,
  ctr: 0.0283,
  cpa: 34.09,
  roas: 4.2,
  reason: 'Highest return on spend in this window.',
  ...over,
})

describe('opening an ad from a report', () => {
  it('marks the card as openable only when there is somewhere to open', () => {
    const onOpen = vi.fn()
    const { unmount } = renderWithProviders(
      <ReportAdsSection ads={[ad()]} locale="en" onOpen={onOpen} />, { locale: 'en' },
    )

    const card = screen.getByTestId('report-ad-card')
    expect(card).toHaveAttribute('data-openable', 'true')

    fireEvent.click(card)
    expect(onOpen).toHaveBeenCalledWith(expect.objectContaining({ name: 'Eid film' }))

    unmount()

    /*
     * The printed page has nowhere to open, so its card is not a button. A control that cannot
     * succeed must not invite the press — that is the defect this section shipped with.
     */
    renderWithProviders(<ReportAdsSection ads={[ad()]} locale="en" />, { locale: 'en' })
    expect(screen.getByTestId('report-ad-card')).toHaveAttribute('data-openable', 'false')
  })

  it('can be left by the button, by Escape and by the backdrop', () => {
    const onClose = vi.fn()
    renderWithProviders(
      <ReportAdDetail ad={ad()} currency="USD" locale="en" onClose={onClose} />, { locale: 'en' },
    )

    fireEvent.click(screen.getByTestId('report-ad-detail-close'))
    expect(onClose).toHaveBeenCalledTimes(1)

    fireEvent.keyDown(document, { key: 'Escape' })
    expect(onClose).toHaveBeenCalledTimes(2)

    fireEvent.click(screen.getByTestId('report-ad-detail'))
    expect(onClose).toHaveBeenCalledTimes(3)
  })

  /**
   * MONEY-USD-001 — the modal is one screen deeper than the card, which makes a mislabelled figure
   * more convincing, not less. A scope bought in USD says USD here too.
   */
  it('prints money in the scope’s own currency, and none when the scope states none', () => {
    const { unmount } = renderWithProviders(
      <ReportAdDetail ad={ad()} currency="USD" locale="en" onClose={() => {}} />, { locale: 'en' },
    )

    expect(screen.getByTestId('report-ad-detail-figures')).toHaveTextContent('3,000 USD')
    expect(screen.getByTestId('report-ad-detail-figures')).not.toHaveTextContent('SAR')

    unmount()

    renderWithProviders(
      <ReportAdDetail ad={ad()} currency={null} locale="en" onClose={() => {}} />, { locale: 'en' },
    )

    const figures = screen.getByTestId('report-ad-detail-figures')
    expect(figures).toHaveTextContent('3,000')
    /*
     * A bare figure, rather than one wearing whichever currency the reporting page happens to use.
     * Read off the value cells, not the whole block: the LABELS legitimately contain «CTR».
     */
    const values = [...figures.querySelectorAll('dd')].map((d) => d.textContent ?? '')
    expect(values.join(' ')).not.toMatch(/[A-Z]{3}/)
  })

  it('says nothing about a return nobody measured', () => {
    const brand = ad({ roas: 0, cpa: 0, conversions: null })

    renderWithProviders(
      <ReportAdDetail ad={brand} currency="USD" locale="en" onClose={() => {}} />, { locale: 'en' },
    )

    const figures = screen.getByTestId('report-ad-detail-figures')
    // «0.00×» is the claim the card used to make: an ad that lost every riyal it was given.
    expect(figures).not.toHaveTextContent('0.00×')
    expect(figures).not.toHaveTextContent(/Cost per result/)
    // The figures it DID report are still there.
    expect(figures).toHaveTextContent('120,000')
  })

  it('says why the platform sent no file, rather than drawing an empty frame', () => {
    const withheld = ad({
      preview: preview({ state: 'withheld', image_url: null, note_en: 'The platform’s preview link carries a credential.' }),
    })

    renderWithProviders(
      <ReportAdDetail ad={withheld} currency="USD" locale="en" onClose={() => {}} />, { locale: 'en' },
    )

    expect(screen.getByTestId('report-ad-detail-poster-absent')).toHaveTextContent(/credential/i)
    expect(screen.queryByTestId('report-ad-detail-poster')).not.toBeInTheDocument()
  })

  /** A film plays where the platform gave a file; the still is not passed off as the ad. */
  it('plays a video ad instead of showing its poster as the whole ad', () => {
    const film = ad({
      preview: preview({ kind: 'video', video_url: 'https://cdn/ad.mp4', thumbnail_url: 'https://cdn/frame.jpg', image_url: null }),
    })

    const { container } = renderWithProviders(
      <ReportAdDetail ad={film} currency="USD" locale="en" onClose={() => {}} />, { locale: 'en' },
    )

    expect(container.querySelector('video')).toBeInTheDocument()
  })

  /**
   * The share's boundary, asserted as text: the modal may print only what the payload already
   * carried onto the card. No ids, no account numbers, no internal diagnostics.
   */
  it('exposes nothing the share did not already carry', () => {
    renderWithProviders(
      <ReportAdDetail ad={ad()} currency="USD" locale="en" onClose={() => {}} />, { locale: 'en' },
    )

    const text = screen.getByTestId('report-ad-detail').textContent ?? ''
    expect(text).not.toContain('a1')
    expect(text).toContain('Eid sales')
  })
})

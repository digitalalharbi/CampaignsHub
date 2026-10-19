import { describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
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

  it('shows the whole creative in the modal: contained, never cropped by a second fit utility', () => {
    renderWithProviders(<ReportAdDetail ad={ad()} currency="USD" locale="en" onClose={() => {}} />, { locale: 'en' })

    const img = screen.getByTestId('report-ad-detail-poster')

    expect(img.className).toMatch(/object-contain/)
    expect(img.className).not.toMatch(/object-cover/)
    // A viewer opened on purpose loads its one image now — a lazy image in a 0×0 box never enters the viewport.
    expect(img).toHaveAttribute('loading', 'eager')
  })

  it('draws a typed cover, not a broken frame, when no media exists', () => {
    renderWithProviders(
      <ReportAdDetail ad={ad({ preview: preview({ state: 'never_fetched', image_url: null }) })} currency="USD" locale="en" onClose={() => {}} />, { locale: 'en' },
    )

    const cover = screen.getByTestId('report-ad-detail-poster-absent')

    expect(cover).toHaveAttribute('data-cover', 'fallback')
    expect(cover.textContent).toMatch(/Meta/)
  })

  it('says why the platform sent no file, rather than drawing an empty frame', () => {
    const withheld = ad({
      preview: preview({ state: 'withheld', image_url: null, note_en: 'The platform’s preview link carries a credential.' }),
    })

    renderWithProviders(
      <ReportAdDetail ad={withheld} currency="USD" locale="en" onClose={() => {}} />, { locale: 'en' },
    )

    // Said to the client in their words: the link does not show it — not how our link carries a credential.
    expect(screen.getByTestId('report-ad-detail-poster-absent')).toHaveTextContent('Not shown on this link')
    expect(screen.getByTestId('report-ad-detail-poster-absent')).not.toHaveTextContent(/credential/i)
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
    expect(text).toContain('Eid film')
    /*
     * CLIENT-REPORT-ENTITY-BOUNDARY-001 — and not the campaign it sat in.
     *
     * The card that opens this dialog stopped printing it; a detail view that put it back would be
     * the same leak one click deeper, which is exactly where nobody looks for it.
     */
    expect(text).not.toContain('Eid sales')
  })
  /**
   * CONTENT-PREVIEW-SHAPES-001 — a COLLECTION reaches the client's report, products and all.
   *
   * This surface rendered `CreativeCarousel` only when the kind was exactly `carousel`, so a
   * collection in a client's own report drew its hero and nothing else — the state the component's
   * docblock records having been rescued from, still live here because the gate was outside it.
   */
  it('draws a collection’s products, not just its hero', () => {
    renderWithProviders(
      <ReportAdDetail
        ad={ad({
          preview: preview({
            kind: 'collection',
            cards_reported: true,
            cards: [
              { index: 0, kind: 'image', image_url: 'https://cdn/p1.jpg', video_url: null, thumbnail_url: null, headline: 'Linen shirt' },
              { index: 1, kind: 'image', image_url: 'https://cdn/p2.jpg', video_url: null, thumbnail_url: null, headline: 'Wide trousers' },
            ],
          }),
        })}
        currency="USD"
        locale="en"
        onClose={() => {}}
      />, { locale: 'en' },
    )

    expect(screen.getByTestId('creative-carousel')).toBeInTheDocument()
    expect(screen.getByText('Collection products')).toBeInTheDocument()
  })

  /**
   * And when the tiles did not arrive, the client is told which absence it is.
   *
   * This said «this product does not fetch them yet». The tiles are fetched now
   * (CONTENT-COLLECTION-TILES-001), so reaching this arm means the ad's interaction zone could not be
   * read — and on a CLIENT report that distinction matters most: «we have not built it» and «one ad's
   * tiles failed to sync» are different things to tell somebody paying for the campaign.
   */
  it('names the real absence for a collection with no tiles', () => {
    renderWithProviders(
      <ReportAdDetail
        ad={ad({ preview: preview({ kind: 'collection', cards: null, cards_reported: false }) })}
        currency="USD"
        locale="en"
        onClose={() => {}}
      />, { locale: 'en' },
    )

    expect(screen.getByText(/did not arrive in the last sync/)).toBeInTheDocument()
    expect(screen.queryByText(/sent no card breakdown/)).not.toBeInTheDocument()
    expect(screen.queryByText(/does not fetch them yet/)).not.toBeInTheDocument()
  })

})

describe('the detail states a withheld spend the card already states', () => {
  /*
   * The card reads spend through the money contract; the dialog one click deeper read `cash(ad.spend)`,
   * which is null for a figure withheld for want of a rate — so the card said «412 USD» and the
   * dialog the client opened from it dropped Spend altogether.
   */
  const withheld = ad({
    spend: null,
    spend_original: 412.5,
    spend_withheld_rows: 3,
    money_original_currency: 'USD',
    money_original_currencies: 1,
  })

  it('prints the amount in the currency it was recorded in', () => {
    renderWithProviders(<ReportAdDetail ad={withheld} currency="SAR" locale="en" onClose={() => {}} />, { locale: 'en' })

    const figures = screen.getByTestId('report-ad-detail-figures')
    expect(figures).toHaveTextContent('Spend')
    expect(figures).toHaveTextContent(/412(\.50?)?\s*USD/)
  })
})

/*
 * REPORT-CONTENT-FULL-FRAME-001 (Owner, 2026-10-19) — a reel opened from a report shows the whole frame.
 *
 * Measured in a browser, not inferred: with the content's trend chart below it, the stage — a column-flex
 * child with `overflow-hidden` — was SHRUNK from its intended 512px to 312px (and the dialog never scrolled),
 * leaving a 9:16 reel 175px wide. And a video whose platform sent no aspect left the stage `unknown`, a fixed
 * 288px strip, because an unarmed player holds no file and so never measures it. jsdom cannot measure pixels,
 * so these pin the two things that prevent it.
 */
describe('a tall reel in a report keeps its frame', () => {
  const film = (over: Record<string, unknown> = {}) => ad({
    preview: preview({ kind: 'video', image_url: null, video_url: 'https://cdn/reel.mp4', thumbnail_url: 'https://cdn/cover.jpg', ...over }),
  })

  it('never lets the dialog shrink the stage: the dialog scrolls instead', () => {
    renderWithProviders(<ReportAdDetail ad={film()} currency="USD" locale="en" onClose={() => {}}>{<div>trend</div>}</ReportAdDetail>, { locale: 'en' })

    const stage = screen.getByTestId('report-ad-detail-stage')
    expect(stage.className).toMatch(/\bshrink-0\b/)
    expect(stage.className).toMatch(/\boverflow-hidden\b/)
  })

  it('gives a film of unknown shape room, not a 288px strip', () => {
    renderWithProviders(<ReportAdDetail ad={film()} currency="USD" locale="en" onClose={() => {}} />, { locale: 'en' })

    const stage = screen.getByTestId('report-ad-detail-stage')
    expect(stage).toHaveAttribute('data-shape', 'unknown')
    expect(stage.className).not.toMatch(/\bh-72\b/)
    expect(stage.className).toMatch(/h-\[56vh\]/)
  })

  it('learns a film\u2019s shape from its cover before it is played', async () => {
    // Stand in for the browser's image decode: 1080 x 1920 is a story.
    const real = window.Image
    class Cover {
      naturalWidth = 1080
      naturalHeight = 1920
      onload: (() => void) | null = null
      set src(_v: string) { queueMicrotask(() => this.onload?.()) }
    }
    ;(window as unknown as { Image: unknown }).Image = Cover
    try {
      renderWithProviders(<ReportAdDetail ad={film()} currency="USD" locale="en" onClose={() => {}} />, { locale: 'en' })

      await waitFor(() => expect(screen.getByTestId('report-ad-detail-stage')).toHaveAttribute('data-shape', 'vertical'))
      expect(screen.getByTestId('report-ad-detail-stage').className).toMatch(/h-\[64vh\]/)
    } finally {
      ;(window as unknown as { Image: unknown }).Image = real
    }
  })

  it('trusts the platform when it states the shape, and does not probe the cover', () => {
    const real = window.Image
    let probed = 0
    class Cover {
      naturalWidth = 0
      naturalHeight = 0
      onload: (() => void) | null = null
      set src(_v: string) { probed++ }
    }
    ;(window as unknown as { Image: unknown }).Image = Cover
    try {
      renderWithProviders(<ReportAdDetail ad={film({ aspect: 'vertical' })} currency="USD" locale="en" onClose={() => {}} />, { locale: 'en' })

      expect(probed).toBe(0)
      expect(screen.getByTestId('report-ad-detail-stage')).toHaveAttribute('data-shape', 'vertical')
    } finally {
      ;(window as unknown as { Image: unknown }).Image = real
    }
  })
})


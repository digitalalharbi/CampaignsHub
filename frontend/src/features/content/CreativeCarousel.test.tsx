import { describe, expect, it } from 'vitest'
import { fireEvent, screen, within } from '@testing-library/react'
import { CreativeCarousel } from './CreativeCarousel'
import type { CreativePreview } from './api'
import { renderWithProviders } from '@/test/utils'

/**
 * §15 — the acceptance claims for a carousel.
 *
 * The defect these exist to prevent is not a missing feature: a five-card carousel synced into the
 * singular columns keeps its FIRST card, so every surface rendered a fifth of what ran with nothing
 * on screen admitting it. So the claims are about what the reader is TOLD:
 *
 *   - the cards are all there, each with its own copy and destination;
 *   - «this platform sent no breakdown» is said out loud, never rendered as a one-card carousel;
 *   - a card whose link was refused is COUNTED, so «one of two is shown» is visible;
 *   - a field the client's link removed simply is not there — no labelled empty row announcing it.
 */

const preview = (over: Partial<CreativePreview> = {}): CreativePreview => ({
  state: 'available',
  kind: 'carousel',
  image_url: 'data:image/svg+xml;base64,PHN2Zy8+',
  video_url: null,
  thumbnail_url: null,
  expires_at: null,
  note_ar: null,
  note_en: null,
  cards_reported: true,
  cards_withheld: 0,
  cards: [
    {
      index: 0,
      kind: 'image',
      image_url: 'data:image/svg+xml;base64,PHN2Zy8+',
      video_url: null,
      thumbnail_url: null,
      headline: 'Card one',
      body: 'First card body',
      cta: 'SHOP_NOW',
      destination_url: 'https://shop.example.com/a',
    },
    {
      index: 1,
      kind: 'image',
      image_url: 'data:image/svg+xml;base64,PHN2Zy8+',
      video_url: null,
      thumbnail_url: null,
      headline: 'Card two',
      body: 'Second card body',
      cta: 'SHOP_NOW',
      destination_url: 'https://shop.example.com/b',
    },
  ],
  ...over,
})

describe('CreativeCarousel', () => {
  it('shows the selected card with its own copy and destination', async () => {
    renderWithProviders(<CreativeCarousel preview={preview()} locale="en" />, { locale: 'en' })

    const panel = await screen.findByTestId('creative-carousel')
    expect(within(panel).getByText('Card 1 of 2')).toBeInTheDocument()
    expect(within(panel).getByText('Card one')).toBeInTheDocument()
    expect(within(panel).getByText('https://shop.example.com/a')).toBeInTheDocument()
    // The destination is TEXT — a link chosen by whoever wrote the ad is not one this page follows.
    expect(within(panel).queryByRole('link', { name: /shop.example.com/ })).not.toBeInTheDocument()
  })

  it('moves between cards, and each card carries its own words', async () => {
    renderWithProviders(<CreativeCarousel preview={preview()} locale="en" />, { locale: 'en' })

    const panel = await screen.findByTestId('creative-carousel')
    fireEvent.click(within(panel).getByRole('button', { name: 'Next card' }))

    expect(within(panel).getByText('Card two')).toBeInTheDocument()
    expect(within(panel).getByText('https://shop.example.com/b')).toBeInTheDocument()
    expect(within(panel).queryByText('Card one')).not.toBeInTheDocument()
  })

  it('moves with the arrow keys as well as the buttons', async () => {
    renderWithProviders(<CreativeCarousel preview={preview()} locale="en" />, { locale: 'en' })

    const panel = await screen.findByTestId('creative-carousel')
    fireEvent.keyDown(within(panel).getByRole('group'), { key: 'ArrowRight' })

    expect(within(panel).getByText('Card two')).toBeInTheDocument()
  })

  /** «No breakdown sent» must never render as a carousel that happens to have one card. */
  it('says plainly when the platform sent no card breakdown', async () => {
    renderWithProviders(
      <CreativeCarousel preview={preview({ cards: null, cards_reported: false })} locale="en" />,
      { locale: 'en' },
    )

    expect(
      await screen.findByText(/This platform sent no card breakdown/),
    ).toBeInTheDocument()
    expect(screen.queryByTestId('creative-carousel')).not.toBeInTheDocument()
  })

  /**
   * AD-MEDIA-RECOVERY-001 / CONTENT-COLLECTION-TILES-001 — the same absence, and it is NOT the same sentence.
   *
   * A carousel with no breakdown is the platform's answer: it sent one asset and that is the ad. A
   * collection with no tiles is not — Snapchat exposes them, and a collection ad by definition has
   * products beneath its hero. Telling the reader «this platform sent no card breakdown» is false
   * twice over and sends an operator looking for a sync fault at Snapchat that does not exist.
   *
   * The sentence used to continue «this product does not fetch them yet, so the gap is ours». That was
   * true when it was written and is false now: the tiles ARE fetched — zone → `creative_element_ids`
   * → `creative_elements` → media. So this arm no longer means «we never ask»; it means we asked and
   * this ad's zone could not be read, which is the fact a reader needs. This test moved with it,
   * because a guard pinning a sentence that has outlived its defect keeps the stale sentence alive.
   */
  it('says whose gap it is when a collection has no product tiles', async () => {
    renderWithProviders(
      <CreativeCarousel
        preview={preview({ kind: 'collection', cards: null, cards_reported: false })}
        locale="en"
      />,
      { locale: 'en' },
    )

    expect(await screen.findByText(/did not arrive in the last sync/)).toBeInTheDocument()
    expect(screen.getByText(/interaction zone/)).toBeInTheDocument()

    // And it no longer claims a gap the product has since closed.
    expect(screen.queryByText(/does not fetch them yet/)).not.toBeInTheDocument()

    // The platform is not accused of a gap that is not its own.
    expect(screen.queryByText(/sent no card breakdown/)).not.toBeInTheDocument()
    expect(screen.queryByText(/all it exposes/)).not.toBeInTheDocument()
  })

  /** A refused card is counted, so «one of two is shown» is something the reader can see. */
  it('states how many card links were withheld', async () => {
    renderWithProviders(
      <CreativeCarousel
        preview={preview({ cards: [preview().cards![0]], cards_withheld: 1 })}
        locale="en"
      />,
      { locale: 'en' },
    )

    expect(await screen.findByText('1 card links carried a credential and were not shown.')).toBeInTheDocument()
  })

  /** A field the client's link REMOVED is absent — not a labelled blank announcing what is withheld. */
  it('draws no row for a field the link removed', async () => {
    const redacted = preview()
    redacted.cards = [
      { index: 0, kind: 'image', image_url: 'data:image/svg+xml;base64,PHN2Zy8+', video_url: null, thumbnail_url: null },
    ]

    renderWithProviders(<CreativeCarousel preview={redacted} locale="en" />, { locale: 'en' })

    const panel = await screen.findByTestId('creative-carousel')
    expect(within(panel).queryByText('Headline')).not.toBeInTheDocument()
    expect(within(panel).queryByText('Destination')).not.toBeInTheDocument()
    // The picture is what a carousel IS, and it stays.
    expect(within(panel).getAllByRole('img').length).toBeGreaterThan(0)
  })

  /** Nothing at all for an image or a video — an empty carousel section is a section that lies. */
  it('renders nothing for a creative that is not a carousel', () => {
    const { container } = renderWithProviders(
      <CreativeCarousel preview={preview({ kind: 'image' })} locale="en" />,
      { locale: 'en' },
    )

    expect(container).toBeEmptyDOMElement()
  })

  /** A video card mounts a player rather than a picture — the kind is per card, not per creative. */
  it('mounts a player for a video card', async () => {
    renderWithProviders(
      <CreativeCarousel
        preview={preview({
          cards: [
            {
              index: 0,
              kind: 'video',
              image_url: null,
              video_url: 'https://cdn.example.com/card.mp4',
              thumbnail_url: 'data:image/svg+xml;base64,PHN2Zy8+',
              headline: 'A moving card',
            },
          ],
        })}
        locale="en"
      />,
      { locale: 'en' },
    )

    const panel = await screen.findByTestId('creative-carousel')
    const video = panel.querySelector('video')
    expect(video).not.toBeNull()
    // Nothing preloads and nothing autoplays, on a card as on a creative.
    expect(video?.getAttribute('preload')).toBe('metadata')
    expect(video?.autoplay).toBe(false)
  })
})

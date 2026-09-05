import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { CreativeCarousel } from './CreativeCarousel'
import { renderWithProviders } from '@/test/utils'
import type { CreativePreview } from './api'

/**
 * CONTENT-PREVIEW-SHAPES-001 — the two shapes whose media is not one asset.
 *
 * `readPreview()` has understood `collection` and `catalog` since the shapes requirement shipped and
 * no install carried a row of either, so both branches were code nobody had seen render. Seeded, and
 * then two surfaces turned out to ignore the shape the reading was already carrying:
 *
 *   - a COLLECTION rendered its hero and dropped its tiles, because this component returned null
 *     unless the kind was exactly `carousel` — «showing one sixth of it», in the words of the
 *     reading's own note;
 *   - a CATALOG ad said «no preview available — the platform did not expose the content asset»,
 *     which is a false accusation against a provider doing exactly what the format is.
 */
const preview = (over: Partial<CreativePreview> = {}): CreativePreview => ({
  kind: 'carousel',
  state: 'available',
  aspect: 'square',
  image_url: 'data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=',
  thumbnail_url: 'data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=',
  video_url: null,
  expires_at: null,
  note_ar: null,
  note_en: null,
  cards_reported: true,
  cards_withheld: 0,
  cards: [
    { headline: 'Linen shirt', body: 'Four colours.', cta: 'SHOP_NOW', destination_url: 'https://x.test/1', image_url: 'data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=', thumbnail_url: null, video_url: null },
    { headline: 'Wide trousers', body: 'Relaxed cut.', cta: 'SHOP_NOW', destination_url: 'https://x.test/2', image_url: 'data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=', thumbnail_url: null, video_url: null },
  ],
  ...over,
} as CreativePreview)

describe('a collection shows its tiles, not only its hero', () => {
  it('renders the cards of a collection', () => {
    renderWithProviders(<CreativeCarousel preview={preview({ kind: 'collection' })} locale="en" />, { locale: 'en' })

    expect(screen.getByText('Linen shirt')).toBeInTheDocument()
  })

  /** Named for what it is: a collection's cards are products, a carousel's are slides. */
  it('calls them products rather than carousel cards', () => {
    renderWithProviders(<CreativeCarousel preview={preview({ kind: 'collection' })} locale="en" />, { locale: 'en' })

    expect(screen.getByText('Collection products')).toBeInTheDocument()
    expect(screen.queryByText('Carousel cards')).not.toBeInTheDocument()
  })

  it('still calls a carousel’s cards carousel cards', () => {
    renderWithProviders(<CreativeCarousel preview={preview({ kind: 'carousel' })} locale="en" />, { locale: 'en' })

    expect(screen.getByText('Carousel cards')).toBeInTheDocument()
  })

  /**
   * And nothing else grows a card section. An image ad with a stray `cards` array — which a provider
   * can send — must not sprout a gallery it does not have.
   */
  it('renders nothing for a shape that has no cards', () => {
    const { container } = renderWithProviders(
      <CreativeCarousel preview={preview({ kind: 'image' })} locale="en" />, { locale: 'en' },
    )

    expect(container).toBeEmptyDOMElement()
  })
})

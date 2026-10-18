import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { AdPoster } from './AdPoster'
import type { CreativePreview } from './api'
import { renderWithProviders } from '@/test/utils'

const preview = (over: Partial<CreativePreview> = {}): CreativePreview => ({
  state: 'unavailable', kind: 'other', image_url: null, video_url: null, thumbnail_url: null, aspect: null, expires_at: null,
  note_ar: null, note_en: 'A ChatGPT Ads chat card: its image is an uploaded file the platform’s API offers no way to read back.',
  cards: null, cards_reported: false, cards_withheld: 0, text: null, ...over,
} as CreativePreview)

/**
 * OPENAI-CHAT-CARD-TEXT-001 — a chat card whose picture cannot be had shows its words, and says so.
 */
describe('a ChatGPT Ads card with no readable image', () => {
  it('draws its title and body as a text card, still marked as an absence', () => {
    renderWithProviders(
      <AdPoster preview={preview({ text: { headline: 'إذا محتار في هدية', body: 'خواتم وسبح بتصاميم راقية' } })} name="card" testid="p" provider="openai_ads" />,
      { locale: 'ar' },
    )

    const card = screen.getByTestId('p-absent')
    expect(card).toHaveAttribute('data-cover', 'text-card')
    expect(card).toHaveAttribute('data-absence')
    expect(card).toHaveTextContent('إذا محتار في هدية')
    expect(card).toHaveTextContent('خواتم وسبح بتصاميم راقية')
    expect(card).toHaveTextContent('الصورة غير متاحة')
    expect(card.querySelector('img')).toBeNull()
  })

  it('keeps the plain absence when the card has no text', () => {
    renderWithProviders(<AdPoster preview={preview({ text: null })} name="card" testid="p" provider="openai_ads" />, { locale: 'en' })

    expect(screen.getByTestId('p-absent')).not.toHaveAttribute('data-cover', 'text-card')
  })
})

import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'

import { CreativeViewer } from './CreativeViewer'
import { renderWithProviders } from '@/test/utils'
import type { CreativeCard } from './api'

/**
 * CONTENT-VIEWER-CARDS-001 — a Story Ad is its cards, and the modal never showed them.
 *
 * `CreativeCarousel` was mounted only on the full detail page, so opening a multi-card creative from
 * the library gave an empty «no preview» box while its actual content sat one navigation away.
 */
const carousel = (over: Partial<CreativeCard['preview']> = {}): CreativeCard => ({
  id: 'cr-1',
  name: 'Ramadan Story',
  provider: 'snapchat',
  campaign_name: 'Always-On',
  duration_seconds: null,
  preview: {
    state: 'available',
    kind: 'carousel',
    image_url: null,
    video_url: null,
    thumbnail_url: null,
    expires_at: null,
    note_ar: null,
    note_en: null,
    cards_reported: true,
    cards_withheld: 0,
    cards: [
      { headline: 'First card', body: null, cta: null, destination: null, image_url: 'data:image/png;base64,iVBORw0KGgo=' },
      { headline: 'Second card', body: null, cta: null, destination: null, image_url: 'data:image/png;base64,iVBORw0KGgo=' },
    ],
    ...over,
  },
} as unknown as CreativeCard)

describe('the creative modal', () => {
  it('shows a multi-card creative’s cards instead of «no preview»', () => {
    renderWithProviders(
      <CreativeViewer creatives={[carousel()]} index={0} onIndexChange={() => {}} onClose={() => {}} />,
      { locale: 'en' },
    )

    expect(screen.getByTestId('creative-viewer')).toBeInTheDocument()
    expect(screen.getByText('First card')).toBeInTheDocument()
    expect(screen.queryByText(/No preview available$/)).not.toBeInTheDocument()
  })

  /** A creative with genuinely nothing still says so — the carousel branch must not swallow that. */
  it('still says «no preview» when there are no cards and no asset', () => {
    const empty = carousel({ cards: [], kind: 'image' })

    renderWithProviders(
      <CreativeViewer creatives={[empty]} index={0} onIndexChange={() => {}} onClose={() => {}} />,
      { locale: 'en' },
    )

    expect(screen.queryByText('First card')).not.toBeInTheDocument()
    expect(screen.getByText(/No preview/)).toBeInTheDocument()
  })

  /** A withheld preview stays withheld: cards must not become a way around the credential rule. */
  it('does not show cards when the preview itself was withheld', () => {
    const withheld = carousel({ state: 'withheld', note_en: 'The platform’s preview link carries a credential.' })

    renderWithProviders(
      <CreativeViewer creatives={[withheld]} index={0} onIndexChange={() => {}} onClose={() => {}} />,
      { locale: 'en' },
    )

    expect(screen.queryByText('First card')).not.toBeInTheDocument()
    expect(screen.getByText(/carries a credential/)).toBeInTheDocument()
  })
})

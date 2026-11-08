import { beforeAll, describe, expect, it } from 'vitest'
import { fireEvent, screen } from '@testing-library/react'
import { AdPoster } from './AdPoster'
import { CreativeVideoPlayer } from './CreativeVideoPlayer'
import { CreativeCarousel } from './CreativeCarousel'
import { firstFrameSrc } from './adPreview'
import type { CreativePreview } from './api'
import { PrintDocument } from '@/features/reports/PrintDocument'
import { renderWithProviders } from '@/test/utils'

/**
 * CREATIVE-VIEWER-FORMATS-001 — every format shows a live cover and opens.
 *
 * Owner, 2026-11-08: «إعلانات التشكيلة أو الكاتلوج أو الكولكشن لا تظهر لسناب شات … يجب أن تظهر جميع
 * المحتويات … غلاف ثم عند الفتح يفتح الصورة أو الفيديو». The shapes below are the live link's own,
 * read from its `ads_roster` (no token, no URL from it is copied here): a collection whose top snap
 * is a film with no still (117 rows), a video with no still (125), a collection whose tiles came
 * back blank (20), and a ChatGPT chat card with its words and no picture (11).
 */

beforeAll(() => {
  if (!(document as Document & { fonts?: unknown }).fonts) {
    Object.defineProperty(document, 'fonts', { value: { ready: Promise.resolve() }, configurable: true })
  }
})

const FILM = 'https://storage.example.test/combine_snaps/top-snap.mp4'

const preview = (over: Partial<CreativePreview> = {}): CreativePreview => ({
  state: 'available',
  kind: 'collection',
  aspect: null,
  image_url: null,
  video_url: FILM,
  thumbnail_url: null,
  expires_at: null,
  note_ar: null,
  note_en: null,
  cards: null,
  cards_reported: false,
  cards_withheld: 0,
  ...over,
}) as CreativePreview

describe('a film with no still is its own cover', () => {
  it('asks for a frame just past zero, and leaves a link that already has a fragment alone', () => {
    expect(firstFrameSrc(FILM)).toBe(`${FILM}#t=0.1`)
    expect(firstFrameSrc(`${FILM}#t=2`)).toBe(`${FILM}#t=2`)
  })

  /** The operator's Analytics grid and campaign centre drew «Video — no cover» for the same ad the client saw. */
  it('draws the first frame on an operator surface, not only on the client’s', () => {
    renderWithProviders(<AdPoster preview={preview()} name="May 11 AD9" testid="grid-poster" />, { locale: 'en' })

    const frame = screen.getByTestId('grid-poster-first-frame')
    expect(frame.tagName).toBe('VIDEO')
    expect(frame).toHaveAttribute('data-cover', 'video-first-frame')
    expect(frame.getAttribute('src')).toBe(`${FILM}#t=0.1`)
    expect(frame.getAttribute('preload')).toBe('metadata')
    expect(screen.queryByTestId('grid-poster-absent')).toBeNull()
  })

  it('does the same for a plain video with no still', () => {
    renderWithProviders(<AdPoster preview={preview({ kind: 'video' })} name="Gift AD2" testid="grid-poster" forClient />, { locale: 'en' })

    expect(screen.getByTestId('grid-poster-first-frame').getAttribute('src')).toBe(`${FILM}#t=0.1`)
  })
})

describe('the viewer opens on a frame, and plays', () => {
  /** Measured on the live link: the viewer opened to a black rectangle under a play button. */
  it('lays the film’s first frame under the play button when the platform sent no poster', () => {
    renderWithProviders(<CreativeVideoPlayer src={FILM} poster={null} />, { locale: 'en' })

    const frame = screen.getByTestId('video-player-first-frame')
    expect(frame.getAttribute('src')).toBe(`${FILM}#t=0.1`)

    // And the reader can still play it: the frame goes, the real player arms with the file.
    fireEvent.click(screen.getByRole('button', { name: 'Play' }))
    expect(screen.queryByTestId('video-player-first-frame')).toBeNull()
    expect(document.querySelector(`video[src="${FILM}"]`)).not.toBeNull()
  })

  it('keeps the platform’s own poster where it sent one', () => {
    renderWithProviders(<CreativeVideoPlayer src={FILM} poster="/media/covers/aa/cover.jpg" />, { locale: 'en' })

    expect(screen.queryByTestId('video-player-first-frame')).toBeNull()
  })
})

describe('a collection whose tiles came back blank', () => {
  it('says so once instead of paging through empty cards', () => {
    const blank = (index: number) => ({
      index, kind: 'image' as const, image_url: null, video_url: null, thumbnail_url: null,
      headline: null, body: null, cta: null, destination_url: null,
    })
    renderWithProviders(
      <CreativeCarousel preview={preview({ cards_reported: true, cards: [0, 1, 2, 3, 4, 5].map(blank) })} locale="en" />,
      { locale: 'en' },
    )

    expect(screen.getByTestId('creative-carousel-blank')).toHaveTextContent('6 cards arrived from the platform with no picture and no text')
    expect(screen.queryByText('No preview available for this card')).toBeNull()
    expect(screen.queryByRole('button', { name: 'Next card' })).toBeNull()
  })
})

describe('the printed file', () => {
  const ad = (name: string, p: CreativePreview) => ({ name, provider: 'snapchat', spend: 100, conversions: 3, preview: p })

  it('prints a film’s first frame and a chat card’s own words, not a sentence', () => {
    const data = {
      period: { from: '2026-10-09', to: '2026-11-07' }, platforms: [], objective: 'Sales',
      ads: [
        ad('Collection film', preview()),
        {
          ...ad('Chat card', preview({
            state: 'unavailable', kind: 'other', video_url: null,
            text: { headline: 'Silver rings', body: 'Hand-finished, shipped nationwide' },
          })),
          provider: 'openai_ads',
        },
      ],
    } as never

    const { container } = renderWithProviders(<PrintDocument data={data} reportName="Content" currency="SAR" />, { locale: 'en' })

    const film = container.querySelector('video[data-print-film]')
    expect(film, 'the printed file drew no frame for a film with no still').not.toBeNull()
    expect(film?.getAttribute('src')).toBe(`${FILM}#t=0.1`)

    const chat = container.querySelector('.doc-ad-chat')
    expect(chat).toHaveTextContent('Silver rings')
    expect(chat).toHaveTextContent('Hand-finished, shipped nationwide')
    expect(container.querySelectorAll('.doc-ad-absent')).toHaveLength(0)
  })
})

describe('a ChatGPT chat card opened in a viewer', () => {
  /** It opened to a thumbnail-sized card floating in an empty stage. */
  it('draws the whole card at reading size, with the platform named', () => {
    const chat = preview({
      state: 'unavailable', kind: 'other', video_url: null,
      text: { headline: 'Silver rings', body: 'A long body that a tile would clamp at four lines but a viewer shows whole.' },
    })
    renderWithProviders(<AdPoster preview={chat} name="Chat" testid="v" textScale="stage" forClient provider="openai_ads" />, { locale: 'en' })

    const card = screen.getByTestId('v-absent')
    expect(card).toHaveAttribute('data-cover', 'text-card')
    expect(card).toHaveTextContent('ChatGPT')
    expect(card.querySelector('.line-clamp-4')).toBeNull()
  })
})

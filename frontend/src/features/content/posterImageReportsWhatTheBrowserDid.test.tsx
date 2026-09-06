import { describe, expect, it } from 'vitest'
import { fireEvent, screen } from '@testing-library/react'
import { renderWithProviders } from '@/test/utils'
import { AdPoster } from './AdPoster'
import type { CreativePreview } from './api'

/**
 * AD-PREVIEW-001 — the browser's own verdict, which no server-side check can reach.
 *
 * `integrations:probe --media` fetches every first-page asset from the VPS and reports status,
 * content type and decoded dimensions. On the live estate it answers 11 usable, 0 unusable — and the
 * owner was still looking at blank cards. Both can be true at once: the datacentre's fetch and the
 * browser's fetch are different requests, made at different times, under different policies, against
 * a signed link with an expiry on it.
 *
 * So the `<img>` has to say what happened, and until now it said nothing at all. A refused asset, an
 * expired signature, a 200 carrying an HTML error page — every one of them left an element that
 * painted nothing, kept its space, and gave no sentence, no state, and nothing any test could hold.
 *
 * These cases drive the two events a real browser fires. `data-media` is not decoration: it is the
 * only thing that lets an acceptance check distinguish «the element is in the DOM» from «pixels
 * arrived», which is the exact distinction the owner's blank screen turned on.
 */
const AVAILABLE: CreativePreview = {
  state: 'available',
  kind: 'image',
  aspect: null,
  image_url: 'https://storage.googleapis.com/bucket/a.png',
  video_url: null,
  thumbnail_url: null,
  expires_at: null,
  note_ar: null,
  note_en: null,
}

describe('what the card does when the browser cannot draw the asset', () => {
  it('starts pending, and reports the decoded size once pixels arrive', () => {
    renderWithProviders(<AdPoster preview={AVAILABLE} name="An ad" testid="poster" />)

    const img = screen.getByTestId('poster')
    expect(img.getAttribute('data-media')).toBe('pending')

    // What a browser reports for a real decode.
    Object.defineProperty(img, 'naturalWidth', { value: 1080, configurable: true })
    Object.defineProperty(img, 'naturalHeight', { value: 1920, configurable: true })
    fireEvent.load(img)

    expect(img.getAttribute('data-media')).toBe('loaded')
    expect(img.getAttribute('data-natural')).toBe('1080x1920')
  })

  /**
   * The defect the owner reported, in one case.
   *
   * Before this, the assertion below found an `<img>` still sitting there — present, sized, and
   * painting nothing. A card that has failed has to SAY it has failed.
   */
  it('a refused asset draws a sentence instead of an empty frame', () => {
    renderWithProviders(<AdPoster preview={AVAILABLE} name="An ad" testid="poster" />)

    fireEvent.error(screen.getByTestId('poster'))

    expect(screen.queryByTestId('poster')).toBeNull()
    const absent = screen.getByTestId('poster-absent')
    expect(absent.getAttribute('data-absence')).toBe('fetch_failed')
    expect(absent.textContent ?? '').toMatch(/انتهت صلاحيته|expired/)
  })

  /**
   * A `load` event with no pixels is a failure, not a success.
   *
   * This is the shape a CDN produces when a signature has died: 200, a body, an `onLoad` — and
   * nothing decodable. Trusting the event rather than the dimensions is precisely how a blank card
   * passes for a working one.
   */
  it('a load that decoded nothing is treated as a failure', () => {
    renderWithProviders(<AdPoster preview={AVAILABLE} name="An ad" testid="poster" />)

    const img = screen.getByTestId('poster')
    Object.defineProperty(img, 'naturalWidth', { value: 0, configurable: true })
    Object.defineProperty(img, 'naturalHeight', { value: 0, configurable: true })
    fireEvent.load(img)

    expect(screen.getByTestId('poster-absent').getAttribute('data-absence')).toBe('fetch_failed')
  })

  /** The request carries no referrer — a CDN refusing on it fails invisibly from the server. */
  it('sends no referrer to the provider', () => {
    renderWithProviders(<AdPoster preview={AVAILABLE} name="An ad" testid="poster" />)

    expect(screen.getByTestId('poster').getAttribute('referrerpolicy')).toBe('no-referrer')
  })
})

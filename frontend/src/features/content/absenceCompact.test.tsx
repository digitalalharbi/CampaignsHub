import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { AdPoster } from './AdPoster'
import { renderWithProviders } from '@/test/utils'

/**
 * CONTENT-MEDIA-ABSENCE-COMPACT-001 — an absence is three words in the picture box, not a paragraph.
 *
 * «A large explanatory paragraph inside the media area is NOT an acceptable replacement … if
 * genuinely unavailable, use a small compact absence state.»
 *
 * The sentence itself is not the problem and is not removed: an operator reads it to decide whether
 * to re-sync, to wait, or to do nothing, and every clause in it was added because somebody acted on
 * a vaguer one. What was wrong was printing it at 11px inside a 128-pixel frame, twenty-four times
 * down a grid. It is on the element's `title` and in an `sr-only` span now — reachable by hover, by
 * a screen reader and by any test that reads the page, and costing the grid no space.
 */
const preview = (over: Record<string, unknown> = {}) => ({
  state: 'unavailable',
  kind: 'image',
  image_url: null,
  video_url: null,
  thumbnail_url: null,
  expires_at: null,
  note_ar: null,
  note_en: null,
  cards: null,
  ...over,
}) as never

describe('the absence that stands in for a picture', () => {
  it('prints a short label, not the sentence, as its visible text', () => {
    renderWithProviders(<AdPoster preview={preview()} name="Hero" testid="p" />, { locale: 'en' })

    const label = screen.getByTestId('p-absent-label')
    expect(label).toBeVisible()
    expect(label.textContent ?? '').toBe('No file')

    /* Three or four words. A limit rather than an exact string, so the wording can still improve. */
    expect((label.textContent ?? '').split(/\s+/).length).toBeLessThanOrEqual(4)
  })

  /** And the full sentence is still there — on the title, where a reader who wants it can reach it. */
  it('keeps the whole explanation within reach', () => {
    renderWithProviders(<AdPoster preview={preview()} name="Hero" testid="p" />, { locale: 'en' })

    const box = screen.getByTestId('p-absent')
    expect(box.getAttribute('title') ?? '').toContain('exposed no file')
    expect(box).toHaveTextContent('exposed no file')
  })

  /**
   * Each shape keeps its OWN short label.
   *
   * «No file» on a catalog ad would be the false accusation `absenceLabel` spent a unit removing,
   * shortened: a catalog ad is not missing anything.
   */
  it.each([
    ['catalog', { kind: 'catalog', state: 'available' }, 'Catalog ad'],
    ['a coverless video', { kind: 'video', state: 'available', video_url: 'https://cdn/a.mp4' }, 'Video, no cover'],
    ['a never-fetched row', { state: 'never_fetched' }, 'Never fetched'],
    ['an expired link', { state: 'expired' }, 'Link expired'],
  ])('says what %s is in its own words', (_name, over, expected) => {
    renderWithProviders(<AdPoster preview={preview(over)} name="Hero" testid="p" />, { locale: 'en' })

    expect(screen.getByTestId('p-absent-label').textContent).toBe(expected)
  })
})

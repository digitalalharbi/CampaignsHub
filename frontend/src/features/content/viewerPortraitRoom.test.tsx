import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { AdPreviewDialog } from './AdPreviewDialog'
import { renderWithProviders } from '@/test/utils'
import type { CreativeCard } from './api'

/**
 * CONTENT-VIEWER-PORTRAIT-001 — «a 9:16 Story must actually look like a Story».
 *
 * ## What the owner asked for
 *
 * «For portrait creative: preserve portrait aspect, show full top, show full bottom, logo/CTA must
 * not be cropped, centre the media… Opening the creative should make it easier to judge the ad than
 * the grid card.» And on a phone: «A portrait Story may occupy most of the viewport height. The user
 * must be able to see the COMPLETE Story.»
 *
 * ## Why a class assertion and not a screenshot
 *
 * The stage is bounded in `vh` and the asset is bounded by the stage, so what decides whether a
 * story is readable is the stage's own height — one number, chosen per shape. A browser pass covers
 * the rendering; this covers the decision, which is the part that silently reverts.
 *
 * The stage was a single `46vh` for every shape. A 16:9 film fills that comfortably and a 9:16 story
 * contained inside it is under a third of the screen on a phone — smaller than the card it was
 * opened from, which is the opposite of what opening it is for.
 */
const creative = (over: Partial<CreativeCard> = {}): CreativeCard =>
  ({
    id: 'c1', name: 'Story', platform: 'snapchat', format: 'video', status: 'active',
    width: 1080, height: 1920, aspect_ratio: '9:16',
    preview: {
      state: 'available', kind: 'image', aspect: 'vertical',
      image_url: 'https://cdn.example/story.jpg', thumbnail_url: null, video_url: null,
      note_ar: null, note_en: null,
    },
    metrics: null,
    ...over,
  }) as unknown as CreativeCard

describe('the viewer’s stage', () => {
  /** Owner regression 5 — a portrait 9:16 quick viewer is fully contained AND given the room. */
  it('is taller for a portrait creative than for a landscape one', () => {
    const { unmount } = renderWithProviders(
      <AdPreviewDialog creative={creative()} locale="ar" onClose={() => {}} />, { locale: 'ar' },
    )
    const portrait = screen.getByTestId('ad-preview-dialog-stage').className
    unmount()

    renderWithProviders(
      <AdPreviewDialog
        creative={creative({
          width: 1920, height: 1080, aspect_ratio: '16:9',
          preview: { ...creative().preview, aspect: 'horizontal' } as never,
        })}
        locale="ar"
        onClose={() => {}}
      />,
      { locale: 'ar' },
    )
    const landscape = screen.getByTestId('ad-preview-dialog-stage').className

    expect(portrait, 'a story gets no more room than a landscape film').not.toBe(landscape)
  })

  /**
   * Owner item 10 — the way out stays on screen while the media scrolls under it.
   *
   * A near-full-screen viewer on a phone whose Close button is at the top of a scrolling column
   * leaves a reader who has scrolled to the figures with no visible way back.
   */
  it('keeps Close reachable while the panel scrolls', () => {
    renderWithProviders(<AdPreviewDialog creative={creative()} locale="ar" onClose={() => {}} />, { locale: 'ar' })

    const header = screen.getByTestId('ad-preview-dialog-close').closest('div')

    expect(header?.className ?? '').toMatch(/sticky/)
  })
})

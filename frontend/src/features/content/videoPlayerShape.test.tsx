import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { CreativeVideoPlayer } from './CreativeVideoPlayer'
import { renderWithProviders } from '@/test/utils'

/**
 * CONTENT-PREVIEW-SHAPES-001 — the player draws the frame the ad actually ran in.
 *
 * The player was `aspect-video` for everything. Measured on a live 9:16 story creative — one the API
 * reports as `vertical` and whose row says `9:16` — the element came back 768×432: the film the
 * client paid for, drawn as a letterboxed sliver in a landscape box, with black either side.
 *
 * The poster beside it had been reading the shape through `aspectClass()` since AD-PREVIEW-001. Only
 * the player never asked, which is why «the preview shows the right shape» could be true of a page
 * and false of the one element on it that plays.
 */
describe('the video player’s frame', () => {
  const video = () => document.querySelector('video') as HTMLVideoElement

  it('draws a story in the shape a story ran in', () => {
    renderWithProviders(<CreativeVideoPlayer src="/demo/x.mp4" aspect="vertical" />, { locale: 'en' })

    expect(video().className).toContain('aspect-[9/16]')
    expect(video().className).not.toContain('aspect-video')
  })

  it('draws a square ad square', () => {
    renderWithProviders(<CreativeVideoPlayer src="/demo/x.mp4" aspect="square" />, { locale: 'en' })

    expect(video().className).toContain('aspect-square')
  })

  /** An unknown shape keeps the landscape frame — the right default, and the old behaviour. */
  it('falls back to landscape when the platform reported no shape', () => {
    renderWithProviders(<CreativeVideoPlayer src="/demo/x.mp4" />, { locale: 'en' })

    expect(video().className).toContain('aspect-video')
  })

  it('draws a horizontal ad in the landscape frame it ran in', () => {
    renderWithProviders(<CreativeVideoPlayer src="/demo/x.mp4" aspect="horizontal" />, { locale: 'en' })

    expect(video().className).toContain('aspect-video')
  })

  /**
   * A vertical film is capped by the viewport, as the still beside it already was.
   *
   * Honouring 9:16 at full width made the frame 1,365px tall on a 768px column — a black pillar the
   * reader had to scroll past to reach the controls. Fixing the shape without this makes the page
   * worse than the landscape box it replaced.
   */
  it('caps a vertical film to the viewport instead of growing a black pillar', () => {
    renderWithProviders(<CreativeVideoPlayer src="/demo/x.mp4" aspect="vertical" />, { locale: 'en' })

    expect(video().className).toContain('max-h-[70vh]')
    expect(video().className).not.toContain('w-full')
  })

  /** A landscape frame never needed the cap, and must not be narrowed by one. */
  it('leaves a landscape film at full width', () => {
    renderWithProviders(<CreativeVideoPlayer src="/demo/x.mp4" aspect="horizontal" />, { locale: 'en' })

    expect(video().className).toContain('w-full')
    expect(video().className).not.toContain('max-h-[70vh]')
  })

  /**
   * Nothing is fetched until the reader asks — the performance rule this player was built around,
   * asserted here so a shape fix cannot quietly turn a grid of stories into a grid of downloads.
   */
  it('still holds no source until the reader presses play', () => {
    renderWithProviders(<CreativeVideoPlayer src="/demo/x.mp4" aspect="vertical" />, { locale: 'en' })

    expect(video().getAttribute('src')).toBeNull()
    expect(screen.getByLabelText('Play')).toBeInTheDocument()
  })
})

import { describe, expect, it, vi } from 'vitest'
import { fireEvent, screen } from '@testing-library/react'
import { CreativeViewer } from './CreativeViewer'
import type { CreativeCard } from './api'
import { renderWithProviders } from '@/test/utils'

/**
 * §15.3/§15.4 — moving between creatives, and what happens to the video when you do.
 *
 * The claim that matters most here is the negative one: a `<video>` exists only inside the viewer,
 * holds no `src` until somebody presses play, and is torn down when the viewer moves on. Reviewing
 * forty creatives with the arrow keys must not leave forty streams open, and it must not leave the
 * previous one talking over the next.
 */

const creative = (over: Partial<CreativeCard> = {}): CreativeCard =>
  ({
    id: 'a',
    name: 'First',
    format: 'image',
    provider: 'meta',
    status: 'active',
    campaign_id: null,
    campaign_name: null,
    preview: {
      state: 'available',
      kind: 'image',
      image_url: 'https://cdn.example.com/a.jpg',
      video_url: null,
      thumbnail_url: null,
      expires_at: null,
      note_ar: null,
      note_en: null,
    },
    aspect_ratio: '1:1',
    duration_seconds: null,
    width: 1080,
    height: 1080,
    file_size: null,
    grouped: false,
    is_demo: false,
    freshness: { last_synced_at: null, source_updated_at: null, first_seen_at: null, last_active_at: null },
    objective: null,
    path: 'awareness',
    headline_metrics: [],
    metrics: null,
    fatigue: { status: 'insufficient_data', signals: [], reason_ar: '', reason_en: '' },
    ...over,
  }) as CreativeCard

const video = creative({
  id: 'b',
  name: 'Second',
  format: 'video',
  duration_seconds: 30,
  preview: {
    state: 'available',
    kind: 'video',
    image_url: null,
    video_url: 'https://cdn.example.com/film.mp4',
    thumbnail_url: 'https://cdn.example.com/film.jpg',
    expires_at: null,
    note_ar: null,
    note_en: null,
  },
})

describe('CreativeViewer', () => {
  it('moves to the next creative on the arrow key that points forward', () => {
    const onIndexChange = vi.fn()
    renderWithProviders(
      <CreativeViewer creatives={[creative(), video]} index={0} onIndexChange={onIndexChange} onClose={vi.fn()} />,
      { locale: 'en' },
    )

    fireEvent.keyDown(document, { key: 'ArrowRight' })
    expect(onIndexChange).toHaveBeenCalledWith(1)
  })

  /**
   * In Arabic the arrow that points at the NEXT card is the left one.
   *
   * Binding to the key's name rather than its direction makes the viewer feel backwards in RTL —
   * pressing the arrow that visually points forward goes back.
   */
  it('follows reading direction rather than the key name in Arabic', () => {
    const onIndexChange = vi.fn()
    renderWithProviders(
      <CreativeViewer creatives={[creative(), video]} index={0} onIndexChange={onIndexChange} onClose={vi.fn()} />,
      { locale: 'ar' },
    )

    fireEvent.keyDown(document, { key: 'ArrowLeft' })
    expect(onIndexChange).toHaveBeenCalledWith(1)
  })

  it('closes on Escape', () => {
    const onClose = vi.fn()
    renderWithProviders(
      <CreativeViewer creatives={[creative()]} index={0} onIndexChange={vi.fn()} onClose={onClose} />,
      { locale: 'en' },
    )

    fireEvent.keyDown(document, { key: 'Escape' })
    expect(onClose).toHaveBeenCalled()
  })

  it('does not run past either end of the set', () => {
    const onIndexChange = vi.fn()
    const { unmount } = renderWithProviders(
      <CreativeViewer creatives={[creative(), video]} index={0} onIndexChange={onIndexChange} onClose={vi.fn()} />,
      { locale: 'en' },
    )
    fireEvent.keyDown(document, { key: 'ArrowLeft' })
    expect(onIndexChange).not.toHaveBeenCalled()
    unmount()

    renderWithProviders(
      <CreativeViewer creatives={[creative(), video]} index={1} onIndexChange={onIndexChange} onClose={vi.fn()} />,
      { locale: 'en' },
    )
    fireEvent.keyDown(document, { key: 'ArrowRight' })
    expect(onIndexChange).not.toHaveBeenCalled()
  })

  /** The player exists, and holds nothing until asked — no autoplay, no stream, no preload of media. */
  it('mounts a video with no source until play is pressed', () => {
    const { container } = renderWithProviders(
      <CreativeViewer creatives={[video]} index={0} onIndexChange={vi.fn()} onClose={vi.fn()} />,
      { locale: 'en' },
    )

    const element = container.querySelector('video')

    expect(element).not.toBeNull()
    expect(element?.getAttribute('src')).toBeNull()
    expect(element?.getAttribute('preload')).toBe('metadata')
    expect(element?.hasAttribute('autoplay')).toBe(false)
    expect(element?.getAttribute('poster')).toBe('https://cdn.example.com/film.jpg')

    fireEvent.click(screen.getByRole('button', { name: 'Play' }))
    expect(container.querySelector('video')?.getAttribute('src')).toBe('https://cdn.example.com/film.mp4')
  })

  /**
   * Two creatives sharing one video file are still two creatives.
   *
   * Keyed by `video_url`, both produced the same React key, the player was reused across the
   * navigation, and the second creative opened already armed and mid-playback. Keyed by the
   * creative's id it remounts, which is also what stops the first video.
   */
  it('remounts the player when moving between creatives that share a video file', () => {
    const second = { ...video, id: 'c', name: 'Third' }
    const { container, rerender } = renderWithProviders(
      <CreativeViewer creatives={[video, second]} index={0} onIndexChange={vi.fn()} onClose={vi.fn()} />,
      { locale: 'en' },
    )

    fireEvent.click(screen.getByRole('button', { name: 'Play' }))
    expect(container.querySelector('video')?.getAttribute('src')).toBe('https://cdn.example.com/film.mp4')

    rerender(<CreativeViewer creatives={[video, second]} index={1} onIndexChange={vi.fn()} onClose={vi.fn()} />)

    // Unarmed again: the new creative starts from its poster, not from the last one's timeline.
    expect(container.querySelector('video')?.getAttribute('src')).toBeNull()
    expect(screen.getByRole('button', { name: 'Play' })).toBeInTheDocument()
  })

  /** Zoom is for images. A video carries a poster, which is an image, and must NOT get zoom controls. */
  it('offers no zoom controls over a video', () => {
    renderWithProviders(
      <CreativeViewer creatives={[video]} index={0} onIndexChange={vi.fn()} onClose={vi.fn()} />,
      { locale: 'en' },
    )

    expect(screen.queryByRole('button', { name: 'Zoom in' })).toBeNull()
    expect(screen.queryByRole('button', { name: 'Actual size' })).toBeNull()
  })

  /** Zoom belongs to the image, and resets when the viewer moves to another creative. */
  it('zooms an image and offers a way back to its actual size', () => {
    const { rerender } = renderWithProviders(
      <CreativeViewer creatives={[creative(), video]} index={0} onIndexChange={vi.fn()} onClose={vi.fn()} />,
      { locale: 'en' },
    )

    fireEvent.click(screen.getByRole('button', { name: 'Zoom in' }))
    expect(screen.getByText('125%')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Actual size' }))
    expect(screen.getByText('100%')).toBeInTheDocument()

    // And a different creative opens at its own size rather than inheriting the last one's zoom.
    fireEvent.click(screen.getByRole('button', { name: 'Zoom in' }))
    rerender(<CreativeViewer creatives={[creative(), creative({ id: 'c' })]} index={1} onIndexChange={vi.fn()} onClose={vi.fn()} />)
    expect(screen.getByText('100%')).toBeInTheDocument()
  })

  /** A withheld asset explains itself; it never renders a broken frame. */
  it('states why an asset is unavailable instead of showing an empty box', () => {
    renderWithProviders(
      <CreativeViewer
        creatives={[
          creative({
            preview: {
              state: 'expired',
              kind: 'image',
              image_url: null,
              video_url: null,
              thumbnail_url: null,
              expires_at: '2026-08-01T00:00:00+00:00',
              note_ar: 'انتهت صلاحية رابط المنصة — يحتاج مزامنة جديدة.',
              note_en: 'The platform link has expired — it needs a fresh sync.',
            },
          }),
        ]}
        index={0}
        onIndexChange={vi.fn()}
        onClose={vi.fn()}
      />,
      { locale: 'en' },
    )

    expect(screen.getByText(/needs a fresh sync/)).toBeInTheDocument()
  })
})

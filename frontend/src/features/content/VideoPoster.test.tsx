import { afterEach, describe, expect, it, vi } from 'vitest'
import { act, render, screen } from '@testing-library/react'
import { VideoPoster } from './VideoPoster'

/**
 * CONTENT-VIDEO-POSTER-001 — the card must paint a frame or say it cannot. Never neither.
 *
 * The gate has failed on WebKit with «the card neither painted a frame nor gave up and explained
 * itself», and that sentence is a precise description of a state this component could actually reach.
 *
 * `settle()` marks the card painted only when `readyState >= 2`, and it runs on mount and on the
 * media events. The eight-second budget calls `onUnavailable()` only when `readyState < 2`. So a
 * video whose data ARRIVES after mount without firing another event the component listens for — the
 * exact WebKit behaviour the component's own docblock describes — satisfies neither branch: the
 * give-up declines because there IS a frame, and nothing sets `painted` because no event came to say
 * so. The card sits unpainted for ever, which is the owner's blank card.
 */
const withReadyState = (value: number): HTMLVideoElement => {
  const el = screen.getByTestId('creative-video-poster') as HTMLVideoElement
  Object.defineProperty(el, 'readyState', { configurable: true, get: () => value })

  return el
}

describe('a video card that can neither paint nor give up', () => {
  afterEach(() => vi.useRealTimers())

  it('paints when the frame arrived without an event to announce it', () => {
    vi.useFakeTimers()
    const onUnavailable = vi.fn()

    render(<VideoPoster src="https://cdn.example/film.mp4" className="h-8 w-8" onUnavailable={onUnavailable} />)

    // The data lands after mount, and no event the component listens for follows it.
    withReadyState(2)
    act(() => { vi.advanceTimersByTime(9000) })

    expect(
      screen.getByTestId('creative-video-poster').getAttribute('data-painted'),
      'the frame was there and the card never said so',
    ).toBe('true')
    expect(onUnavailable, 'a card with a frame reported itself unavailable').not.toHaveBeenCalled()
  })

  /** And the other branch is unchanged: no data by the budget is still an honest absence. */
  it('gives up when the budget passes with no frame', () => {
    vi.useFakeTimers()
    const onUnavailable = vi.fn()

    render(<VideoPoster src="https://cdn.example/film.mp4" className="h-8 w-8" onUnavailable={onUnavailable} />)

    withReadyState(0)
    act(() => { vi.advanceTimersByTime(9000) })

    expect(onUnavailable, 'the card sat on an empty frame without explaining itself').toHaveBeenCalled()
  })
})

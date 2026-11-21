import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { act, render } from '@testing-library/react'

const FLAG = 'campaignshub.chunk-reload-at'

/**
 * REDIRECT-ONCE-002 — the stale-chunk reload must not run in the first instant after a chunk fails.
 *
 * A chunk request aborted because the page is being navigated away from rejects exactly like a chunk a deploy
 * removed. Reloading at once replaced the navigation that caused it; waiting lets a document that is going away
 * take the timer with it.
 */
describe('lazyPage — the one reload after a deploy', () => {
  const reload = vi.fn()

  beforeEach(() => {
    vi.useFakeTimers()
    reload.mockClear()
    sessionStorage.removeItem(FLAG)
    vi.stubGlobal('location', { ...window.location, reload })
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.unstubAllGlobals()
  })

  /** Several routes failing at once, from one module instance: its «reload already scheduled» latch is module state. */
  async function mountFailing(count = 1) {
    vi.resetModules()
    const { lazyPage } = await import('./lazyPage')
    for (let i = 0; i < count; i++) {
      const Page = lazyPage(() => Promise.reject(new Error('Failed to fetch dynamically imported module')), 'Page' as never) as unknown as () => null
      render(<Page />)
    }
  }

  it('does not reload in the instant the chunk fails, and has not spent the once-only flag', async () => {
    await mountFailing()
    await act(async () => { await vi.advanceTimersByTimeAsync(50) })

    expect(reload).not.toHaveBeenCalled()
    expect(sessionStorage.getItem(FLAG)).toBeNull()
  })

  it('reloads once, after the wait, when the page is still here — the chunk really is gone', async () => {
    await mountFailing(2)
    await act(async () => { await vi.advanceTimersByTimeAsync(1500) })

    expect(reload).toHaveBeenCalledTimes(1)
    expect(sessionStorage.getItem(FLAG)).not.toBeNull()
  })
})

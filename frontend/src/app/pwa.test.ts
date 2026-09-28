import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { registerServiceWorker } from './pwa'

/**
 * A first-time visitor is never refreshed out from under themselves.
 *
 * `sw.js` calls `clients.claim()` when it activates, so `controllerchange` fires on a FIRST install
 * as well as on an update. Reloading on both meant every first visit to production reloaded itself —
 * the one thing this module's own note says never happens.
 *
 * It was invisible for as long as the E2E gate served a dev server, where `import.meta.env.PROD` is
 * false and none of this code runs. The day the gate served a built app, seventeen specs failed with
 * «Execution context was destroyed, most likely because of a navigation».
 */
describe('the service worker taking control', () => {
  const listeners = new Map<string, () => void>()
  let reload: ReturnType<typeof vi.fn>

  function arrange(hadController: boolean): void {
    listeners.clear()
    reload = vi.fn()

    vi.stubGlobal('navigator', {
      serviceWorker: {
        controller: hadController ? {} : null,
        register: vi.fn().mockResolvedValue({ waiting: null, addEventListener: vi.fn() }),
        addEventListener: (type: string, fn: () => void) => listeners.set(type, fn),
      },
    })
    // `window.location.reload` is the observable act; everything else here exists to reach it.
    vi.stubGlobal('window', {
      addEventListener: (type: string, fn: () => void) => { if (type === 'load') fn() },
      location: { reload },
    })
    vi.stubGlobal('document', { getElementById: () => null })
  }

  beforeEach(() => vi.stubEnv('PROD', true))
  afterEach(() => { vi.unstubAllGlobals(); vi.unstubAllEnvs() })

  it('does not reload the page when the first worker claims it', async () => {
    arrange(false)
    registerServiceWorker()
    await Promise.resolve()

    listeners.get('controllerchange')?.()

    expect(reload).not.toHaveBeenCalled()
  })

  /** An update is the case the reload exists for, and it must still happen. */
  it('reloads when a new worker replaces the one that was driving the page', async () => {
    arrange(true)
    registerServiceWorker()
    await Promise.resolve()

    listeners.get('controllerchange')?.()

    expect(reload).toHaveBeenCalledTimes(1)
  })

  /** And only once, however many times control changes hands. */
  it('reloads once, not once per controllerchange', async () => {
    arrange(true)
    registerServiceWorker()
    await Promise.resolve()

    listeners.get('controllerchange')?.()
    listeners.get('controllerchange')?.()

    expect(reload).toHaveBeenCalledTimes(1)
  })
})

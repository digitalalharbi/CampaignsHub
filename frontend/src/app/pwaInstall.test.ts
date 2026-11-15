import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { captureInstallPrompt, installState, isIos, promptInstall, resetInstallStateForTests } from './pwaInstall'

/** NOTIF-PWA-001 — the install button's state: Android prompt, iPhone steps, nothing once installed. */
function promptEvent(outcome: 'accepted' | 'dismissed' = 'accepted') {
  const e = new Event('beforeinstallprompt', { cancelable: true }) as Event & { prompt: () => Promise<void>; userChoice: Promise<{ outcome: string }> }
  e.prompt = vi.fn().mockResolvedValue(undefined)
  e.userChoice = Promise.resolve({ outcome })

  return e
}

describe('pwaInstall', () => {
  beforeEach(() => {
    resetInstallStateForTests()
    window.matchMedia = vi.fn().mockReturnValue({ matches: false }) as never
  })
  afterEach(() => vi.restoreAllMocks())

  it('is unavailable until the browser offers the prompt, then offers it once', async () => {
    captureInstallPrompt()
    expect(installState()).toBe('unavailable')

    const e = promptEvent()
    window.dispatchEvent(e)
    expect(e.defaultPrevented).toBe(true) // held for the button, not shown as the browser's own bar
    expect(installState()).toBe('prompt')

    expect(await promptInstall()).toBe(true)
    expect(e.prompt).toHaveBeenCalledTimes(1)
    expect(installState()).toBe('unavailable') // a prompt can only be used once
  })

  it('reports installed once the app is installed or opened standalone', () => {
    captureInstallPrompt()
    window.dispatchEvent(promptEvent())
    window.dispatchEvent(new Event('appinstalled'))
    expect(installState()).toBe('installed')

    resetInstallStateForTests()
    window.matchMedia = vi.fn().mockReturnValue({ matches: true }) as never
    expect(installState()).toBe('installed')
  })

  it('recognises iPhone and iPad (including an iPad that calls itself a Mac) and nothing else', () => {
    expect(isIos({ userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) Safari', maxTouchPoints: 5 })).toBe(true)
    expect(isIos({ userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari', maxTouchPoints: 5 })).toBe(true)
    expect(isIos({ userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari', maxTouchPoints: 0 })).toBe(false)
    expect(isIos({ userAgent: 'Mozilla/5.0 (Linux; Android 14) Chrome/124', maxTouchPoints: 5 })).toBe(false)
  })
})

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

/**
 * THEME-DARK-PRIMARY-001 — dark is the product default, and only a person changes it.
 *
 * `stores/ui.ts` reads the remembered choice at MODULE LOAD and applies it to `<html>` before React
 * paints, so every case here has to reset the module registry and re-import. Testing the exported
 * store without that would assert against whatever the first import happened to compute.
 */
const THEME_KEY = 'campaign-hub-theme'

async function bootWith(stored: string | null) {
  localStorage.clear()
  if (stored !== null) localStorage.setItem(THEME_KEY, stored)
  document.documentElement.removeAttribute('data-theme')
  vi.resetModules()

  return await import('./ui')
}

describe('the theme a first-time visitor gets', () => {
  beforeEach(() => localStorage.clear())
  afterEach(() => vi.unstubAllGlobals())

  it('is dark, with nothing remembered', async () => {
    const { useUi } = await bootWith(null)

    expect(useUi.getState().theme).toBe('dark')
    expect(document.documentElement.getAttribute('data-theme')).toBe('dark')
  })

  it('is still dark when the operating system asks for light', async () => {
    /*
     * The whole point of the decision: a laptop set to light for someone's mail client has not
     * chosen a theme for this product. If `prefers-color-scheme` were consulted, the same account
     * would look like two different products depending on which machine opened it.
     */
    vi.stubGlobal('matchMedia', (query: string) => ({
      matches: query.includes('light'),
      media: query,
      addEventListener: () => {},
      removeEventListener: () => {},
      addListener: () => {},
      removeListener: () => {},
      onchange: null,
      dispatchEvent: () => false,
    }))

    const { useUi } = await bootWith(null)

    expect(useUi.getState().theme).toBe('dark')
    expect(document.documentElement.getAttribute('data-theme')).toBe('dark')
  })

  it('falls back to dark — not light — when the stored value is unusable', async () => {
    const { useUi } = await bootWith('chartreuse')

    expect(useUi.getState().theme).toBe('dark')
  })
})

describe('a choice the person actually made', () => {
  beforeEach(() => localStorage.clear())

  it('is honoured when they previously chose light', async () => {
    const { useUi } = await bootWith('light')

    expect(useUi.getState().theme).toBe('light')
    expect(document.documentElement.getAttribute('data-theme')).toBe('light')
  })

  it('survives the next full page load, because it is written down', async () => {
    const first = await bootWith(null)
    first.useUi.getState().toggleTheme()

    expect(first.useUi.getState().theme).toBe('light')
    expect(localStorage.getItem(THEME_KEY)).toBe('light')

    // A hard navigation: the module is evaluated again against the store it just wrote.
    localStorage.setItem(THEME_KEY, 'light')
    document.documentElement.removeAttribute('data-theme')
    vi.resetModules()
    const second = await import('./ui')

    expect(second.useUi.getState().theme).toBe('light')
    expect(document.documentElement.getAttribute('data-theme')).toBe('light')
  })

  it('toggles back to dark and remembers that too', async () => {
    const { useUi } = await bootWith('light')
    useUi.getState().toggleTheme()

    expect(useUi.getState().theme).toBe('dark')
    expect(localStorage.getItem(THEME_KEY)).toBe('dark')
    expect(document.documentElement.getAttribute('data-theme')).toBe('dark')
  })
})

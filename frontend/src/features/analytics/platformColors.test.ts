import { describe, expect, it } from 'vitest'
import { PLATFORM_COLORS, platformColor } from './components'

/**
 * THEME-DARK-PRIMARY-001 — a platform's colour must survive the theme it is drawn on.
 *
 * TikTok and X are monochrome brands whose primary is black. As literals they produced an invisible
 * donut slice, a legend dot that read as a hole, and a bar of no apparent length — on every screen
 * that draws a platform, which is Analytics, Campaigns and the integrations rail.
 *
 * The fix is that no platform colour is a literal any more: each is a token the theme layer states,
 * so dark can answer differently from light. This asserts the property rather than the hex, because
 * the hex is the theme's business and the indirection is the thing that must not regress.
 */
describe('platform colours', () => {
  it('are theme tokens, never baked-in literals', () => {
    for (const [platform, value] of Object.entries(PLATFORM_COLORS)) {
      expect(value, `${platform} must resolve through the theme layer`).toMatch(/^var\(--platform-[a-z_]+\)$/)
    }
  })

  it('never hand back a raw hex, including for a platform nobody has heard of', () => {
    expect(platformColor('tiktok')).toBe('var(--platform-tiktok)')
    expect(platformColor('x')).toBe('var(--platform-x)')
    expect(platformColor('a-platform-that-does-not-exist')).not.toMatch(/#[0-9a-fA-F]{3,8}/)
  })

  it('gives Google the same identity under both of the keys the providers use', () => {
    // The registry carries `google` with a `google_ads` alias; a donut keyed one way and a legend
    // keyed the other must not draw the same platform in two colours.
    expect(platformColor('google_ads')).toBe(platformColor('google'))
  })

  it('covers every platform the product draws', () => {
    for (const p of ['meta', 'google', 'google_ads', 'tiktok', 'snapchat', 'x', 'linkedin']) {
      expect(PLATFORM_COLORS[p], `${p} has no colour`).toBeDefined()
    }
  })
})

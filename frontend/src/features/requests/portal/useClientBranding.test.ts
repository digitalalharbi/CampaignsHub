import { describe, expect, it } from 'vitest'
import { brandStyle } from './useClientBranding'
import type { ClientBranding } from './clientSpace'

function branding(colors: Record<string, string>): ClientBranding {
  return { space: null, colors, fonts: {}, white_label_requested: false, logos: [] }
}

/**
 * The bug this pins: the override originally set only `--brand-600`, which the inspector showed
 * applied and the screen did not. Tailwind v4 utilities like `bg-brand-600` read
 * `--color-brand-600`, so half the variable families were being set — the kind of half-applied
 * styling that looks done in devtools and ships broken.
 */
describe('brandStyle', () => {
  it('sets both variable families, because the utilities and the hand-written CSS read different ones', () => {
    const style = brandStyle(branding({ primary: '#B4531F' }))

    expect(style['--brand-600']).toBe('#B4531F')
    expect(style['--color-brand-600']).toBe('#B4531F')
    expect(style['--brand-primary']).toBe('#B4531F')
  })

  /**
   * The value is stored data that lands in a style attribute. An arbitrary string there is a way to
   * put something nobody reviewed into the page's CSS, so it must look like a hex colour or be
   * dropped entirely.
   */
  it.each([
    'red',
    'var(--something)',
    'url(https://evil.test/x)',
    '#12',
    '#1234567',
    'expression(alert(1))',
  ])('refuses %s rather than passing it through', (value) => {
    expect(brandStyle(branding({ primary: value }))).toEqual({})
  })

  it.each(['#abc', '#AABBCC', '#AABBCCDD'])('accepts the hex form %s', (value) => {
    expect(brandStyle(branding({ primary: value }))['--brand-600']).toBe(value)
  })

  /** No brand set is the normal case for an unbranded agency, not an error — the default palette stands. */
  it('returns nothing when the agency has set no colour', () => {
    expect(brandStyle(null)).toEqual({})
    expect(brandStyle(branding({}))).toEqual({})
  })
})

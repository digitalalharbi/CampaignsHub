import { describe, expect, it } from 'vitest'
import { agencyAccentFor, brandStyle, contrastWithWhite, MIN_ACCENT_CONTRAST } from './useClientBranding'
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

  /** BRANDING-HIERARCHY-001 — white text sits on the accent, so an accent it cannot be read on is not applied. */
  it.each(['#FFEB3B', '#AABBCC', '#ffffff', '#abc'])('refuses %s: white text on it would be unreadable', (value) => {
    expect(contrastWithWhite(value)).toBeLessThan(MIN_ACCENT_CONTRAST)
    expect(brandStyle(branding({ primary: value }))).toEqual({})
  })

  it('applies an agency accent only when the agency chose one that white text can be read on', () => {
    expect(agencyAccentFor(undefined)).toBeUndefined()
    expect(agencyAccentFor('#0D8A6F')).toBeUndefined() // the product's own: nothing changes
    expect(agencyAccentFor('#FFEB3B')).toBeUndefined() // too pale
    expect(agencyAccentFor('#7A1FA2')?.['--brand-600']).toBe('#7A1FA2')
  })

  it('states contrast the WCAG way', () => {
    expect(contrastWithWhite('#000000')).toBeCloseTo(21, 0)
    expect(contrastWithWhite('#ffffff')).toBeCloseTo(1, 1)
  })

  it.each(['#234', '#223344', '#223344FF'])('accepts the hex form %s', (value) => {
    expect(brandStyle(branding({ primary: value }))['--brand-600']).toBe(value)
  })

  /** No brand set is the normal case for an unbranded agency, not an error — the default palette stands. */
  it('returns nothing when the agency has set no colour', () => {
    expect(brandStyle(null)).toEqual({})
    expect(brandStyle(branding({}))).toEqual({})
  })
})

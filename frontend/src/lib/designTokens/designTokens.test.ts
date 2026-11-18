import { describe, expect, it } from 'vitest'
import { DESIGN_TOKENS, platformHex, seriesHex, statusColors, tokenColor } from './index'

describe('design tokens (UI + email share one module)', () => {
  it('states every colour in both themes', () => {
    expect(Object.keys(DESIGN_TOKENS.themes.dark).sort()).toEqual(Object.keys(DESIGN_TOKENS.themes.light).sort())
  })

  it('resolves platforms, series and the calm statuses', () => {
    expect(platformHex('meta')).toBe('#1877f2')
    expect(platformHex('google_ads', 'dark')).toBe('#f28b82')
    expect(seriesHex()[0]).toBe(tokenColor('brand-600'))
    expect(statusColors('on_track').color).toBe('#0d8a6f')
  })

  it('has no alarm red in the status vocabulary', () => {
    const names = Object.values(DESIGN_TOKENS.status).flatMap((s) => [s.color, s.background])
    expect(names.some((n) => n.includes('danger') || n.includes('negative'))).toBe(false)
  })

  it('throws on an unknown token instead of rendering blank', () => {
    expect(() => tokenColor('bordr')).toThrow()
  })
})

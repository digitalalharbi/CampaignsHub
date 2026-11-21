import { describe, expect, it } from 'vitest'
import { contrastRatio, ensureContrast, fromHex, readableOn, relativeLuminance, toHex } from './contrast'
import { FONT_PAIRS, pickFontPair, toneFromPicture } from './fontPairs'
import { deriveTokens, designTokensSchema, fontSizes, lintTokens, restyle, MIN_PX, type PictureReading } from './tokens'

const dark: PictureReading = {
  swatches: [{ rgb: [18, 20, 28], share: 0.5 }, { rgb: [200, 40, 50], share: 0.2 }, { rgb: [90, 92, 100], share: 0.2 }, { rgb: [230, 225, 215], share: 0.1 }],
  classes: { tone: 'dark', temperature: 'warm', complexity: 'simple' },
}
const light: PictureReading = {
  swatches: [{ rgb: [240, 238, 232], share: 0.6 }, { rgb: [30, 120, 90], share: 0.2 }, { rgb: [200, 190, 170], share: 0.2 }],
  classes: { tone: 'light', temperature: 'neutral', complexity: 'simple' },
}

describe('contrast', () => {
  it('matches the WCAG reference values', () => {
    expect(contrastRatio([0, 0, 0], [255, 255, 255])).toBeCloseTo(21, 5)
    expect(contrastRatio([119, 119, 119], [255, 255, 255])).toBeCloseTo(4.48, 1)
    expect(relativeLuminance([255, 255, 255])).toBeCloseTo(1, 5)
  })

  it('leaves a readable colour alone and nudges an unreadable one just enough', () => {
    expect(ensureContrast([20, 20, 20], [255, 255, 255])).toEqual([20, 20, 20])

    const fixed = ensureContrast([150, 150, 150], [255, 255, 255])

    expect(contrastRatio(fixed, [255, 255, 255])).toBeGreaterThanOrEqual(4.5)
    expect(contrastRatio(fixed, [255, 255, 255])).toBeLessThan(5.2)
  })

  it('round-trips hex and picks the better of black and white', () => {
    expect(toHex(fromHex('#c8283c'))).toBe('#c8283c')
    expect(readableOn([10, 10, 10])).toEqual([255, 255, 255])
    expect(readableOn([250, 250, 250])).toEqual([0, 0, 0])
  })
})

describe('font pairs', () => {
  it('are open-licence and one display plus one text font each', () => {
    for (const p of FONT_PAIRS) {
      expect(p.licence).toBe('OFL')
      expect(p.display).not.toBe('')
      expect(p.text).not.toBe('')
    }
  })

  it('a tone picks its own pair, and the picture decides the tone when the brand states none', () => {
    expect(pickFontPair('bold').key).toBe('cairo-tajawal')
    expect(toneFromPicture({ tone: 'dark', temperature: 'warm', complexity: 'simple' })).toBe('luxury')
    expect(toneFromPicture({ tone: 'light', temperature: 'cool', complexity: 'busy' })).toBe('bold')
    expect(toneFromPicture({ tone: 'light', temperature: 'cool', complexity: 'simple' })).toBe('soft')
  })
})

describe('deriving the tokens', () => {
  it('yields a valid, lint-clean token set for a dark and a light picture', () => {
    for (const picture of [dark, light]) {
      const t = deriveTokens(picture)

      expect(designTokensSchema.safeParse(t).success).toBe(true)
      expect(lintTokens(t)).toEqual([])
      expect(t.source).toBe('derived')
    }
  })

  it('takes the accent from the vivid colour of the picture, not the grey, and keeps its label readable', () => {
    const t = deriveTokens(dark)
    const [r, g] = fromHex(t.colors.accent)

    expect(r).toBeGreaterThan(g + 30)
    expect(contrastRatio(fromHex(t.colors.accentText), fromHex(t.colors.accent))).toBeGreaterThanOrEqual(4.5)
  })

  it('the Brand Kit wins over the picture', () => {
    const t = deriveTokens(dark, { tone: 'soft', fontPairKey: 'almarai-kufi', colors: { accent: '#1e78c8' } })

    expect(t.source).toBe('brand_kit')
    expect(t.fontPair.key).toBe('almarai-kufi')
    expect(contrastRatio(fromHex(t.colors.accentText), fromHex(t.colors.accent))).toBeGreaterThanOrEqual(4.5)
  })

  it('is deterministic', () => {
    expect(deriveTokens(dark)).toEqual(deriveTokens(dark))
  })
})

describe('swapping the style', () => {
  it('changes the font pair and accent and nothing else, and stays lint-clean', () => {
    const a = deriveTokens(dark)
    const b = restyle(a, { fontPairKey: 'cairo-tajawal', accent: '#e0a800' })

    expect(b.fontPair.key).toBe('cairo-tajawal')
    expect(b.colors.accent).not.toBe(a.colors.accent)
    expect({ ...b, fontPair: a.fontPair, colors: { ...b.colors, accent: a.colors.accent, accentText: a.colors.accentText } }).toEqual(a)
    expect(lintTokens(b)).toEqual([])
  })
})

describe('type scale', () => {
  it('scales with the canvas and never drops under the legible floor', () => {
    const t = deriveTokens(dark)
    const big = fontSizes(t, 1080)
    const small = fontSizes(t, 540)

    expect(big.hook).toBe(102)
    for (const k of Object.keys(MIN_PX) as Array<keyof typeof MIN_PX>) {
      expect(big[k]).toBeGreaterThanOrEqual(MIN_PX[k])
      expect(small[k]).toBeGreaterThanOrEqual(MIN_PX[k] / 2 - 1)
    }
  })

  it('keeps the hierarchy: hook > headline > cta > message', () => {
    const s = fontSizes(deriveTokens(dark), 1080)

    expect(s.hook).toBeGreaterThan(s.headline)
    expect(s.headline).toBeGreaterThan(s.cta)
    expect(s.cta).toBeGreaterThan(s.message)
  })
})

describe('the lint', () => {
  it('flags a fourth layer colour and an unknown font pair', () => {
    const t = deriveTokens(dark)

    expect(lintTokens(t, ['#111111', '#222222', '#333333']).map((i) => i.code)).toEqual([])
    expect(lintTokens(t, ['#111111', '#222222', '#333333', '#444444']).map((i) => i.code)).toContain('too_many_colours')
    expect(lintTokens({ ...t, fontPair: { key: 'x', display: 'Comic Sans', text: 'Papyrus' } }).map((i) => i.code)).toContain('font_pair_unknown')
  })

  it('flags a label that cannot be read on its fill', () => {
    const t = deriveTokens(dark)

    expect(lintTokens({ ...t, colors: { ...t.colors, accent: '#777777', accentText: '#888888' } }).map((i) => i.code)).toContain('accent_label_contrast')
  })
})

function lcg(seed: number) {
  let s = seed >>> 0

  return () => {
    s = (Math.imul(s, 1664525) + 1013904223) >>> 0

    return s / 2 ** 32
  }
}

describe('property: whatever the picture, the derived tokens pass the lint', () => {
  it('holds for 300 seeded random palettes', () => {
    const rnd = lcg(20261121)

    for (let i = 0; i < 300; i++) {
      const swatches = Array.from({ length: 5 }, () => ({ rgb: [Math.floor(rnd() * 256), Math.floor(rnd() * 256), Math.floor(rnd() * 256)] as [number, number, number], share: rnd() * 0.4 + 0.05 }))
      const picture: PictureReading = {
        swatches,
        classes: { tone: rnd() < 0.5 ? 'dark' : 'light', temperature: 'neutral', complexity: rnd() < 0.5 ? 'busy' : 'simple' },
      }
      const t = deriveTokens(picture)

      expect(lintTokens(t), `palette ${i}`).toEqual([])
      expect(designTokensSchema.safeParse(t).success, `palette ${i}`).toBe(true)
    }
  })
})

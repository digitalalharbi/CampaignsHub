import { z } from 'zod'
import { contrastRatio, ensureContrast, fromHex, readableOn, toHex, type RGB } from './contrast'
import { FONT_PAIRS, pickFontPair, toneFromPicture, type Tone } from './fontPairs'

/**
 * CONTENT-STUDIO-001 batch 3 — the design tokens: the ONE set every output of a session uses, which is what makes six different
 * compositions read as one brand. Font and colour are flexible (the Brand Kit wins, otherwise they are derived from the picture and
 * the person can swap them in one press); the type scale, the spacing grid and the contrast floor are not.
 */
const hex = z.string().regex(/^#[0-9a-f]{6}$/i)

export const designTokensSchema = z.object({
  source: z.enum(['brand_kit', 'derived']),
  fontPair: z.object({ key: z.string().min(1), display: z.string().min(1), text: z.string().min(1) }),
  /** Fractions of the canvas width, so the same scale serves 1080×1920, 4:5 and 1:1. */
  typeScale: z.object({ hook: z.number().positive(), headline: z.number().positive(), message: z.number().positive(), cta: z.number().positive() }),
  colors: z.object({ textOnDark: hex, textOnLight: hex, accent: hex, accentText: hex, surface: hex, surfaceText: hex }),
  /** Corner radius as a fraction of the canvas width; spacing is a multiple of 8px; stroke is in px at 1080 wide. */
  radius: z.number().min(0),
  spacing: z.number().int().positive().refine((n) => n % 8 === 0, 'spacing is on the 8px grid'),
  stroke: z.number().positive(),
  motion: z.object({ ease: z.tuple([z.number(), z.number(), z.number(), z.number()]), enterMs: z.number().positive(), holdMs: z.number().positive() }),
})

export type DesignTokens = z.infer<typeof designTokensSchema>

export interface BrandKit {
  tone?: Tone
  fontPairKey?: string
  colors?: Partial<{ accent: string; surface: string; textOnDark: string; textOnLight: string }>
}

export interface PictureReading {
  swatches: ReadonlyArray<{ rgb: RGB; share: number }>
  classes: { tone: 'light' | 'dark'; temperature: 'warm' | 'cool' | 'neutral'; complexity: 'busy' | 'simple' }
}

const sat = (c: RGB): number => (Math.max(...c) === 0 ? 0 : (Math.max(...c) - Math.min(...c)) / Math.max(...c))
const lum = (c: RGB): number => 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2]
const scale = (c: RGB, k: number): [number, number, number] => [c[0] * k, c[1] * k, c[2] * k]

const TYPE_SCALE = { hook: 0.095, headline: 0.075, message: 0.05, cta: 0.06 } as const

/** The smallest sizes at which each role stays legible on a phone, in px at 1080 wide. */
export const MIN_PX = { hook: 72, headline: 56, message: 38, cta: 44 } as const

export function fontSizes(tokens: DesignTokens, canvasWidth: number): Record<keyof typeof MIN_PX, number> {
  const out = {} as Record<keyof typeof MIN_PX, number>

  for (const k of Object.keys(MIN_PX) as Array<keyof typeof MIN_PX>) {
    const floor = (MIN_PX[k] * canvasWidth) / 1080
    out[k] = Math.round(Math.max(tokens.typeScale[k] * canvasWidth, floor) / 2) * 2
  }

  return out
}

/** An accent that carries a label: push it until the label colour that suits it reaches 4.5:1. */
function accentWithLabel(seed: RGB, surface: RGB): { accent: [number, number, number]; label: [number, number, number] } {
  let accent: [number, number, number] = [seed[0], seed[1], seed[2]]

  // Stand apart from the ground first (3:1 is the floor for a shape), then make the label on it readable.
  accent = ensureContrast(accent, surface, 3)

  for (let step = 0; step <= 40; step++) {
    const label = readableOn(accent)

    if (contrastRatio(label, accent) >= 4.5) return { accent, label }

    // Darken a light accent and lighten a dark one a little at a time; a mid tone has no good label until it moves.
    accent = lum(accent) >= 128 ? scale(accent, 0.96) : [accent[0] + (255 - accent[0]) * 0.04, accent[1] + (255 - accent[1]) * 0.04, accent[2] + (255 - accent[2]) * 0.04]
  }

  return { accent, label: readableOn(accent) }
}

export function deriveTokens(picture: PictureReading, brand: BrandKit = {}): DesignTokens {
  const fromBrand = brand.colors !== undefined && Object.keys(brand.colors).length > 0 && (brand.colors.accent !== undefined || brand.fontPairKey !== undefined || brand.tone !== undefined)
  const sw = [...picture.swatches]
  const darkest = sw.reduce<RGB>((m, s) => (lum(s.rgb) < lum(m) ? s.rgb : m), sw[0]?.rgb ?? [20, 20, 24])
  const lightest = sw.reduce<RGB>((m, s) => (lum(s.rgb) > lum(m) ? s.rgb : m), sw[0]?.rgb ?? [240, 240, 240])
  const vivid = sw.filter((s) => s.share >= 0.04).sort((a, b) => sat(b.rgb) * (0.5 + b.share) - sat(a.rgb) * (0.5 + a.share))[0]?.rgb ?? [200, 160, 60]

  const surface: RGB = brand.colors?.surface ? fromHex(brand.colors.surface) : picture.classes.tone === 'dark' ? scale(darkest, 0.45) : scale(lightest, 0.97)
  const seed: RGB = brand.colors?.accent ? fromHex(brand.colors.accent) : vivid
  const { accent, label } = accentWithLabel(seed, surface)
  const tone = brand.tone ?? toneFromPicture(picture.classes)
  const pair = FONT_PAIRS.find((p) => p.key === brand.fontPairKey) ?? pickFontPair(tone)

  return designTokensSchema.parse({
    source: fromBrand ? 'brand_kit' : 'derived',
    fontPair: { key: pair.key, display: pair.display, text: pair.text },
    typeScale: TYPE_SCALE,
    colors: {
      textOnDark: brand.colors?.textOnDark ?? '#f7f5f2',
      textOnLight: brand.colors?.textOnLight ?? '#16181d',
      accent: toHex(accent),
      accentText: toHex(label),
      surface: toHex(surface),
      surfaceText: toHex(readableOn(surface)),
    },
    radius: 0.02,
    spacing: 16,
    stroke: 3,
    motion: { ease: [0.22, 1, 0.36, 1], enterMs: 450, holdMs: 800 },
  })
}

/** «Swap the style»: a new font pair and/or accent for EVERY output of the session at once; nothing else moves. */
export function restyle(tokens: DesignTokens, change: { fontPairKey?: string; accent?: string }): DesignTokens {
  const pair = FONT_PAIRS.find((p) => p.key === change.fontPairKey) ?? FONT_PAIRS.find((p) => p.key === tokens.fontPair.key)!
  const surface = fromHex(tokens.colors.surface)
  const { accent, label } = change.accent ? accentWithLabel(fromHex(change.accent), surface) : { accent: fromHex(tokens.colors.accent), label: fromHex(tokens.colors.accentText) }

  return designTokensSchema.parse({
    ...tokens,
    fontPair: { key: pair.key, display: pair.display, text: pair.text },
    colors: { ...tokens.colors, accent: toHex(accent), accentText: toHex(label) },
  })
}

export type LintCode = 'text_contrast' | 'accent_label_contrast' | 'surface_label_contrast' | 'font_pair_unknown' | 'too_many_colours'

export interface LintIssue {
  code: LintCode
  detail: string
}

/** The checks run before anything is shown: contrast ≥ 4.5 for every text role, one display and one text font, ≤ 3 colours in layers. */
export function lintTokens(t: DesignTokens, layerColours: ReadonlyArray<string> = []): LintIssue[] {
  const out: LintIssue[] = []
  const c = (k: keyof DesignTokens['colors']) => fromHex(t.colors[k])

  if (contrastRatio(c('textOnDark'), [0, 0, 0]) < 4.5) out.push({ code: 'text_contrast', detail: 'textOnDark on black' })
  if (contrastRatio(c('textOnLight'), [255, 255, 255]) < 4.5) out.push({ code: 'text_contrast', detail: 'textOnLight on white' })
  if (contrastRatio(c('accentText'), c('accent')) < 4.5) out.push({ code: 'accent_label_contrast', detail: 'accentText on accent' })
  if (contrastRatio(c('surfaceText'), c('surface')) < 4.5) out.push({ code: 'surface_label_contrast', detail: 'surfaceText on surface' })
  if (!FONT_PAIRS.some((p) => p.display === t.fontPair.display && p.text === t.fontPair.text)) out.push({ code: 'font_pair_unknown', detail: `${t.fontPair.display} + ${t.fontPair.text}` })

  const distinct = new Set(layerColours.map((x) => x.toLowerCase()))

  if (distinct.size > 3) out.push({ code: 'too_many_colours', detail: `${distinct.size} colours in layers` })

  return out
}

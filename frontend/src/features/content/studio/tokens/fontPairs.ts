/**
 * CONTENT-STUDIO-001 batch 3 — curated Arabic font pairs, one for display and one for text, all open-licence (OFL) and meant to be
 * self-hosted. A pair is chosen by the tone of the brand and the class of the picture; one output never mixes pairs.
 */
export type Tone = 'luxury' | 'bold' | 'soft' | 'modern'

export interface FontPair {
  key: string
  tone: Tone
  display: string
  text: string
  /** The weights the pair draws at: display heavy, text medium so a supporting line never looks thin beside the hook. */
  displayWeight: number
  textWeight: number
  licence: 'OFL'
}

/**
 * Owner 2026-11-22: the earlier pairs drew every hook in a plain UI sans at 700 and every line at 400 — legible, but it read as a
 * form, not an advertisement. Each pair now leads with a display face built for headlines (Lalezar, Changa ExtraBold, El Messiri,
 * Almarai ExtraBold, Cairo Black) and sets supporting text at a medium weight.
 */
export const FONT_PAIRS: ReadonlyArray<FontPair> = [
  { key: 'messiri-readex', tone: 'luxury', display: 'El Messiri', text: 'Readex Pro', displayWeight: 700, textWeight: 500, licence: 'OFL' },
  { key: 'lalezar-cairo', tone: 'bold', display: 'Lalezar', text: 'Cairo', displayWeight: 400, textWeight: 600, licence: 'OFL' },
  { key: 'almarai-tajawal', tone: 'soft', display: 'Almarai', text: 'Tajawal', displayWeight: 800, textWeight: 500, licence: 'OFL' },
  { key: 'changa-plex', tone: 'modern', display: 'Changa', text: 'IBM Plex Sans Arabic', displayWeight: 800, textWeight: 500, licence: 'OFL' },
  { key: 'cairo-tajawal', tone: 'bold', display: 'Cairo', text: 'Tajawal', displayWeight: 900, textWeight: 500, licence: 'OFL' },
]

export function pickFontPair(tone: Tone): FontPair {
  return FONT_PAIRS.find((p) => p.tone === tone) ?? FONT_PAIRS[0]!
}

/** A tone from what the picture is when the brand states none: dark and simple reads as luxury, busy and warm as bold, light as soft. */
export function toneFromPicture(c: { tone: 'light' | 'dark'; temperature: 'warm' | 'cool' | 'neutral'; complexity: 'busy' | 'simple' }): Tone {
  if (c.tone === 'dark' && c.complexity === 'simple') return 'luxury'
  if (c.complexity === 'busy') return 'bold'
  if (c.tone === 'light') return 'soft'

  return 'modern'
}

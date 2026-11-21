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
  licence: 'OFL'
}

export const FONT_PAIRS: ReadonlyArray<FontPair> = [
  { key: 'plex-readex', tone: 'luxury', display: 'IBM Plex Sans Arabic', text: 'Readex Pro', licence: 'OFL' },
  { key: 'cairo-tajawal', tone: 'bold', display: 'Cairo', text: 'Tajawal', licence: 'OFL' },
  { key: 'almarai-kufi', tone: 'soft', display: 'Noto Kufi Arabic', text: 'Almarai', licence: 'OFL' },
  { key: 'readex-plex', tone: 'modern', display: 'Readex Pro', text: 'IBM Plex Sans Arabic', licence: 'OFL' },
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

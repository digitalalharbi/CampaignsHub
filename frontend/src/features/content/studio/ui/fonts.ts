import type { FontPair } from '../tokens/fontPairs'

/**
 * CONTENT-LIBRARY-SNAP-001 — the studio's fonts, really loaded before anything is drawn or measured.
 *
 * The font pairs named Cairo, Tajawal, Readex Pro, Almarai and Noto Kufi Arabic, but only IBM Plex Sans Arabic was ever shipped, so
 * every design fell back to the system face: the «primitive» look the Owner saw, and text measured in one face and drawn in another.
 * Each face is OFL, self-hosted from @fontsource, imported only when the studio opens (none of it reaches the rest of the app), and
 * the canvas waits on `document.fonts.load` for the exact weights the looks draw with.
 */
const LOADERS: Record<string, () => Promise<unknown>> = {
  'IBM Plex Sans Arabic': () => Promise.all([import('@fontsource/ibm-plex-sans-arabic/400.css'), import('@fontsource/ibm-plex-sans-arabic/700.css')]),
  Cairo: () => Promise.all([import('@fontsource/cairo/400.css'), import('@fontsource/cairo/700.css')]),
  Tajawal: () => Promise.all([import('@fontsource/tajawal/400.css'), import('@fontsource/tajawal/700.css')]),
  'Readex Pro': () => Promise.all([import('@fontsource/readex-pro/400.css'), import('@fontsource/readex-pro/700.css')]),
  Almarai: () => Promise.all([import('@fontsource/almarai/400.css'), import('@fontsource/almarai/700.css')]),
  'Noto Kufi Arabic': () => Promise.all([import('@fontsource/noto-kufi-arabic/400.css'), import('@fontsource/noto-kufi-arabic/700.css')]),
}

/** The weights the looks draw with: bold for display, regular for text. */
export const DISPLAY_WEIGHT = 700
export const TEXT_WEIGHT = 400

const done = new Set<string>()

export async function ensureFonts(pair: Pick<FontPair, 'display' | 'text'>): Promise<void> {
  const families = [...new Set([pair.display, pair.text])].filter((f) => !done.has(f))
  if (families.length === 0) return

  await Promise.all(families.map((f) => LOADERS[f]?.().catch(() => undefined)))

  const set = typeof document !== 'undefined' ? (document as Document & { fonts?: FontFaceSet }).fonts : undefined
  if (set) {
    // Arabic sample text, so the Arabic subset is the one fetched, not only Latin.
    await Promise.all(families.flatMap((f) => [
      set.load(`${DISPLAY_WEIGHT} 40px "${f}"`, 'اطلب الآن 123').catch(() => []),
      set.load(`${TEXT_WEIGHT} 40px "${f}"`, 'اطلب الآن 123').catch(() => []),
    ]))
  }
  for (const f of families) done.add(f)
}

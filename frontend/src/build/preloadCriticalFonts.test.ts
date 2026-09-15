import { describe, expect, it } from 'vitest'
import { preloadCriticalFonts } from './preloadCriticalFonts'

/**
 * MKT-FIX-001 — the preload, tested where it can actually be tested.
 *
 * The first attempt at a guard was an E2E assertion on the served document, and it SKIPPED on all
 * three browsers: the gate runs the dev server, where a build plugin does not run. A test that
 * always skips is not coverage — it is the reassuring shape of coverage, which is worse than none.
 *
 * The plugin is a pure function of the bundle's file names, so it is exercised directly: give it a
 * bundle, read the tags it asks for.
 */
const bundle = {
  'assets/index-AbCdEfGh.js': {},
  'assets/index-IjKlMnOp.css': {},
  'assets/inter-latin-wght-normal-Dx4kXJAl.woff2': {},
  'assets/inter-cyrillic-wght-normal-QrStUvWx.woff2': {},
  'assets/ibm-plex-sans-arabic-arabic-400-normal-CyU-ddYS.woff2': {},
  'assets/ibm-plex-sans-arabic-arabic-700-normal-9rXc6cUL.woff2': {},
  'assets/ibm-plex-sans-arabic-latin-400-normal-Bo5KPYvw.woff2': {},
  'assets/ibm-plex-sans-arabic-arabic-400-normal-EA1o-u_Q.woff': {},
}

function tagsFor(files: Record<string, unknown>) {
  const plugin = preloadCriticalFonts() as never as {
    generateBundle: (o: unknown, b: unknown) => void
    transformIndexHtml: () => Array<{ tag: string; attrs: Record<string, string> }>
  }
  plugin.generateBundle({}, files)

  return plugin.transformIndexHtml()
}

describe('the critical font preload', () => {
  it('names the three faces the first screen needs, and only those', () => {
    const hrefs = tagsFor(bundle).map((t) => t.attrs.href)

    expect(hrefs).toHaveLength(3)
    expect(hrefs).toContain('/assets/inter-latin-wght-normal-Dx4kXJAl.woff2')
    expect(hrefs).toContain('/assets/ibm-plex-sans-arabic-arabic-400-normal-CyU-ddYS.woff2')
    // The hero heading's own weight — see the production measurement in the plugin's comment.
    expect(hrefs).toContain('/assets/ibm-plex-sans-arabic-arabic-700-normal-9rXc6cUL.woff2')
  })

  /**
   * Preloading a face the first screen does not paint costs a request and delays the ones it does.
   * Cyrillic, the 700 weight and the Latin subset of the Arabic family are all real files in the
   * bundle and none of them belongs in the head.
   */
  it('does not preload a face the first screen never paints', () => {
    const hrefs = tagsFor(bundle).map((t) => t.attrs.href).join(' ')

    expect(hrefs).not.toContain('cyrillic')
    expect(hrefs).not.toContain('ibm-plex-sans-arabic-latin')
  })

  /**
   * `crossorigin` is not optional even same-origin: a font is always fetched in CORS mode, so a
   * preload without it fetches the bytes a SECOND time — the opposite of the point.
   */
  it('marks every preload crossorigin, and as a font', () => {
    for (const tag of tagsFor(bundle)) {
      expect(tag.tag).toBe('link')
      expect(tag.attrs.rel).toBe('preload')
      expect(tag.attrs.as).toBe('font')
      expect(tag.attrs.type).toBe('font/woff2')
      expect(tag.attrs).toHaveProperty('crossorigin')
    }
  })

  /** `.woff` is the legacy fallback; preloading it would fetch a file modern browsers never use. */
  it('preloads woff2 only', () => {
    expect(tagsFor(bundle).every((t) => t.attrs.href.endsWith('.woff2'))).toBe(true)
  })

  /** A bundle whose font names change asks for nothing rather than guessing. */
  it('asks for nothing when the faces are not in the bundle', () => {
    expect(tagsFor({ 'assets/index-AbCdEfGh.js': {} })).toEqual([])
  })
})

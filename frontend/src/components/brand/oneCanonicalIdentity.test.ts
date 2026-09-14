import { describe, expect, it } from 'vitest'

/**
 * BRAND-CANONICAL-001 — one identity, and no surface may build a second.
 *
 * ## Why this file exists rather than another entry on a list
 *
 * `noPlaceholderIdentity.test.ts` names SIX files and checks each of them draws the canonical mark.
 * Its brand-tile pattern is good — it would have matched the defect below character for character —
 * and it never ran against the file that had it. `PublicHeader.tsx`, whose own docblock says «the one
 * public header. Every public page wears this and none builds its own», rendered a Lucide megaphone
 * in a gradient tile beside a hardcoded English wordmark, and shipped to production that way, because
 * nobody had added it to the list.
 *
 * A guard that names its subjects can only catch regressions in surfaces somebody remembered. It
 * cannot stop the next surface from inventing a second logo, which is the failure this repeats.
 *
 * So this one is inverted: it walks EVERY source file and asserts the absence of an identity built
 * anywhere but here. A new page cannot escape it by being new.
 */
const SOURCES = import.meta.glob('/src/**/*.{tsx,ts}', { query: '?raw', import: 'default', eager: true }) as Record<string, string>

/** The canonical implementations, and the tests that read them, are the one place allowed to. */
const CANONICAL = /^\/src\/components\/brand\//

const files = Object.entries(SOURCES).filter(([path]) => !CANONICAL.test(path) && !path.includes('.test.'))

describe('one canonical CampaignsHub identity', () => {
  it('finds source to inspect, or it is asserting nothing', () => {
    expect(files.length).toBeGreaterThan(200)
  })

  /**
   * The glyph-in-a-tile, which is what every one of these defects has actually been.
   *
   * Narrow on purpose: `Megaphone` is still the Campaigns nav icon and `Users` still labels a people
   * screen. What this matches is one of them used AS the product mark — inside the small rounded
   * tile the logo goes in.
   */
  it('no surface draws a glyph in a brand tile', () => {
    /*
     * A BRAND tile, not every tile.
     *
     * The first draft matched any rounded box holding one of these glyphs and named fifteen files —
     * most of them section headers where a `Users` glyph labels a people screen and a `ShieldCheck`
     * labels security. Those are a feature's icons and are meant to stay; rewriting them would have
     * been a detector bug enforced as a migration.
     *
     * What identifies the PRODUCT tile is the brand fill: the gradient or brand-numbered background
     * the logo used to sit on. A feature icon is tinted by its own semantic colour or by nothing.
     */
    /*
     * The GRADIENT tile specifically, which is the one the logo actually sat in.
     *
     * `bg-brand-600` was in the pattern for one run and named two more files — both a primary ACTION
     * button with a `ShieldCheck` on it, «verify now». A filled brand button is the product's accent
     * doing its job, and matching it would have forced those buttons into a worse colour to satisfy
     * a brand rule they were never breaking.
     */
    const brandTile = /bg-gradient-to-[a-z]{1,2} from-brand-\d[^>]*>\s*<(Megaphone|Users|ShieldCheck|Sparkles|Rocket|Zap|LayoutDashboard)\s/
    const offenders = files.filter(([, src]) => brandTile.test(src)).map(([path]) => path)

    expect(offenders).toEqual([])
  })

  /**
   * The WORDMARK as JSX text — `<span …>CampaignsHub</span>`.
   *
   * Matched as `>CampaignsHub<` so it catches the rendered name and not the many honest prose
   * mentions inside string literals («sign in to your CampaignsHub account»), which are the product
   * being NAMED in a sentence rather than a logo being drawn.
   */
  it('no surface hardcodes the wordmark as rendered text', () => {
    const offenders = files.filter(([, src]) => />CampaignsHub</.test(src)).map(([path]) => path)

    expect(offenders).toEqual([])
  })

  /** The mark's colours arrive through tokens. A hex outside the brand folder is a second source. */
  it('no surface paints a brand colour of its own', () => {
    /*
     * PAINTED, not merely present.
     *
     * The mark's colours arrive through `--brand-mark` and `--brand-600`; a hex that fills, strokes
     * or backgrounds something is a second source of truth and drifts at the next rebrand — which is
     * how the PWA update banner, raw DOM outside React and Tailwind, kept the old green through one.
     *
     * A hex sitting in `useState('#0d8a6f')` is the DEFAULT of a colour a user then picks for their
     * own taxonomy option. That is data with a sensible starting value, not the identity, and a rule
     * that could not tell the two apart would have forced a worse default to satisfy a test.
     */
    const painted = /(?:background|color|fill|stroke)\s*[:=]\s*"?'?#(?:0[dD]8[aA]6[fF]|[eE]8[aA]33[dD]|[fF]7[fF]5[fF]0)/
    const offenders = files.filter(([, src]) => painted.test(src)).map(([path]) => path)

    expect(offenders).toEqual([])
  })
})

/**
 * The legacy identity, anywhere it could come back.
 *
 * `brandIdentity.test.tsx` already holds `favicon.svg` to the canonical geometry and refuses the old
 * purple in that one file. This widens the same refusal to every text asset the site can serve and
 * every source file, because the favicon was never the only place a mark can hide — the public
 * header carried one for the life of the brand, and a served SVG or a manifest can carry another.
 */
const PUBLIC = import.meta.glob('/public/**/*.{svg,webmanifest,json,js,html}', {
  query: '?raw',
  import: 'default',
  eager: true,
}) as Record<string, string>

describe('the legacy identity cannot come back', () => {
  /** The purple the mark used to be drawn in. Named here so its return is a failing test, not a report. */
  const LEGACY_PURPLE = /#?863bff/i

  it('inspects the public assets, or it is asserting nothing', () => {
    expect(Object.keys(PUBLIC).length).toBeGreaterThan(2)
  })

  it('no served asset carries the legacy purple', () => {
    const offenders = Object.entries(PUBLIC)
      .filter(([, src]) => LEGACY_PURPLE.test(src))
      .map(([path]) => path)

    expect(offenders).toEqual([])
  })

  it('no source file carries the legacy purple', () => {
    const offenders = files.filter(([, src]) => LEGACY_PURPLE.test(src)).map(([path]) => path)

    expect(offenders).toEqual([])
  })

  /**
   * The icon names the manifest and the head point at are the GENERATED ones.
   *
   * A hand-exported `logo.png` appearing beside them is how a second identity re-enters a build:
   * nothing references it, until something does.
   */
  it('ships no hand-exported logo file beside the generated icons', () => {
    const stray = Object.keys(PUBLIC).filter((path) => /\/(logo|brand|mark)[-.]/i.test(path))

    expect(stray).toEqual([])
  })
})

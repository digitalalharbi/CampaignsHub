import { describe, expect, it } from 'vitest'

/**
 * TYPOGRAPHY-PRODUCT-POLISH-001 — thirteen sizes between 9.5px and 15px, most decided once each.
 *
 * ## What was there
 *
 * 742 arbitrary font sizes across THIRTEEN values — 9.5, 10, 11, 11.5, 12, 12.5, 13, 13.5, 14, 14.5,
 * 15, 28, 34 — against 2,302 uses of the Tailwind scale. Six of the thirteen were half-pixel
 * neighbours of another: 11.5 beside 11, 12.5 beside 12, 13.5 beside 13, 14.5 beside 14. Nobody can
 * see half a pixel at this size; what a reader sees is that two labels which should match do not,
 * and what a developer sees is thirteen precedents for inventing a fourteenth.
 *
 * ## What this collapses, and what it deliberately leaves
 *
 * The half-pixel neighbours are folded into their whole-pixel siblings — 78 occurrences across 24
 * files — everywhere EXCEPT `features/marketing` and `features/auth`. Those two carry the visual
 * regression baselines (`homepage.spec.ts-snapshots`, `auth-visual.spec.ts-snapshots`), and moving
 * type there changes pixels the gate compares against a stored image. Regenerating a baseline is a
 * deliberate act with its own evidence, not a side effect of a tidy-up, so they keep their sizes and
 * this test names them.
 *
 * ## What the test is for
 *
 * Not to freeze the mess. The remaining sizes are still more than a scale should have, and the row
 * says so. This stops the ONE thing that made it a mess: a new half-pixel size, invented in a moment,
 * that nobody can see and everybody afterwards copies.
 */
/*
 * Read through Vite rather than `node:fs`: this suite's tsconfig carries no Node types, and the
 * eager glob is resolved at build time, so its keys are repository paths in every runner.
 */
const TREE: Record<string, string> = import.meta.glob('/src/**/*.{ts,tsx}', {
  query: '?raw',
  import: 'default',
  eager: true,
})

/** [path relative to the frontend root, source] for every file in the tree. */
function sourceFiles(): [string, string][] {
  return Object.entries(TREE).map(([path, source]) => [path.replace(/^\/+/, ''), source])
}

/** A size with a fraction: the class of value this exists to stop. */
const HALF_PIXEL = /text-\[\d+\.\d+px\]/

/**
 * The two areas that keep theirs, with the reason.
 *
 * Kept as an explicit list rather than a pattern: an exemption nobody can read is how a guard stops
 * guarding, and these two are exempt for a specific, temporary reason.
 */
const BASELINED = {
  'src/features/marketing': 'carries the homepage visual baselines — moving type here changes pixels the gate compares',
  'src/features/auth': 'carries the auth visual baselines, same reason',
}

describe('TYPOGRAPHY-PRODUCT-POLISH-001 — no half-pixel type sizes', () => {
  it('finds none outside the two areas that carry visual baselines', () => {
    const offenders = sourceFiles()
      .filter(([relative]) => !Object.keys(BASELINED).some((dir) => relative.startsWith(dir)))
      .flatMap(([relative, source]) =>
        source
          .split('\n')
          .map((line, i) => [i + 1, line] as const)
          .filter(([, line]) => HALF_PIXEL.test(line))
          .map(([n, line]) => `${relative}:${n}  ${(line.match(HALF_PIXEL) ?? [''])[0]}`),
      )

    expect(offenders, 'half a pixel is invisible, and every one of these is a precedent for the next').toEqual([])
  })

  /** The scanner reads the tree, and the exemptions are real rather than a way to pass. */
  it('reads the whole source tree and the exempt areas still exist', () => {
    const files = sourceFiles()

    expect(files.length).toBeGreaterThan(300)
    for (const dir of Object.keys(BASELINED)) {
      expect(files.some(([f]) => f.startsWith(dir)), `${dir} is exempt and must exist`).toBe(true)
    }
  })

  /** And it can see one when there is one — the exempt areas still hold theirs. */
  it('still finds the half-pixel sizes inside the exempt areas', () => {
    const inExempt = sourceFiles()
      .filter(([relative]) => Object.keys(BASELINED).some((dir) => relative.startsWith(dir)))
      .some(([, source]) => HALF_PIXEL.test(source))

    expect(inExempt, 'if these are gone the exemption should go with them').toBe(true)
  })
})

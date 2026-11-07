import { describe, expect, it } from 'vitest'

/**
 * TYPOGRAPHY-PRODUCT-POLISH-001 — a ratchet on arbitrary font sizes.
 *
 * `scripts/migrate-type-scale.mjs` moved every `text-[Npx]` that lies within 1px of a scale step
 * (xs 13 · sm 14 · base 16 · lg 18 · xl 20 · 2xl 24 · 3xl 30 · 4xl 36) onto the scale class. What is
 * left is either (a) a size with NO step within 1px (11, 10, 9.5, 28, 26, 32, 34 …), (b) inside an
 * allow-listed area, (c) in the PDF print route's import closure, whose fit floor must not move, or
 * (d) a chart-library numeric `fontSize:` / a `leading-[…]` the migration deliberately leaves.
 *
 * This number may only go DOWN: lower REMAINING when you remove one, never raise it. A new arbitrary
 * size is a new precedent — use a scale step, or add a step to tokens.css and map it in index.css.
 */
const TREE: Record<string, string> = import.meta.glob('/src/**/*.{ts,tsx}', {
  query: '?raw',
  import: 'default',
  eager: true,
})
/** The expression the migration was measured with. */
const ARBITRARY = /text-\[[0-9.]+(?:px|rem)\]|leading-\[[0-9.]+(?:px|rem)?\]|fontSize: ?'?[0-9.]+(?:px)?/g

/** Areas excluded from the count, each with its reason. Test files are always excluded. */
const ALLOW = {
  'src/features/marketing': 'homepage visual-regression baselines — moving type changes compared pixels',
  'src/features/auth': 'auth visual-regression baselines, same reason',
} as const

/**
 * Post-migration count (1,115 before the codemod, counting everything). Only ever lower it.
 *
 * 2026-11-07 — re-baselined once, at the merge of the type-scale branch with the main that had moved on (batch/pricing-tenancy-1):
 * the codemod was re-run over the merged tree, and the 39 sizes it leaves are ones with no scale step within 1px (11px, 9px)
 * that landed in main (GA4, integrations, reports, console panels) after 729 was measured. From here the number only goes down.
 */
const REMAINING = 768

/** Scale classes the migration emits; each must resolve to a real token. */
const MAPPED_STEPS = ['xs', 'sm', 'base', 'lg', 'xl', '2xl', '3xl'] as const

function counted(): number {
  return Object.entries(TREE)
    .map(([p, s]) => [p.replace(/^\/+/, ''), s] as const)
    .filter(([p]) => !/\.test\.tsx?$/.test(p) && !Object.keys(ALLOW).some((d) => p.startsWith(d)))
    .reduce((a, [, s]) => a + (s.match(ARBITRARY) ?? []).length, 0)
}

describe('TYPOGRAPHY-PRODUCT-POLISH-001 — arbitrary type sizes only go down', () => {
  it('has no more arbitrary sizes than the post-migration count', () => {
    expect(counted(), 'use a scale step; this number may only decrease').toBeLessThanOrEqual(REMAINING)
  })

  it('reads the tree and the allow-listed areas still exist', () => {
    const paths = Object.keys(TREE).map((p) => p.replace(/^\/+/, ''))
    expect(paths.length).toBeGreaterThan(300)
    for (const dir of Object.keys(ALLOW)) expect(paths.some((p) => p.startsWith(dir)), dir).toBe(true)
  })

  /*
   * tokens.css / index.css cannot be read here: this suite runs with `css: false`, which hands back
   * an empty string for any stylesheet, `?raw` included. So the guarantee that each emitted step is
   * a real token is asserted from the other side — every step is already in wide use in the tree
   * (a class the theme did not define would have been caught long ago), and the definitions were
   * checked against tokens.css and index.css when the codemod was written.
   */
  it('every step the migration emits is a scale class already in wide use', () => {
    const source = Object.entries(TREE)
      .filter(([p]) => !/\.test\.tsx?$/.test(p))
      .map(([, s]) => s)
      .join('\n')
    for (const step of MAPPED_STEPS) {
      const uses = (source.match(new RegExp(`(?<![\\w-])text-${step}(?![\\w-])`, 'g')) ?? []).length
      expect(uses, `text-${step}`).toBeGreaterThan(20)
    }
  })
})

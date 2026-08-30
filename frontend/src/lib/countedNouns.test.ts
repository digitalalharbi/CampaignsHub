import { describe, expect, it } from 'vitest'

/**
 * TYPOGRAPHY-PRODUCT-POLISH-001 — the noun beside a number is derived, not typed.
 *
 * «You have an unfinished connection: 1 accounts available» and «1 حسابًا متاحًا» were on the same
 * screen, in the same release. Neither is a typo: each was written once, correctly for the number in
 * front of the author, and then met every other number.
 *
 * `lib/counted` holds the rule — one at 1, dual at 2, plural through 10, accusative after, and
 * English plurals — and this scan stops the next hand-written one.
 */
const TREE: Record<string, string> = import.meta.glob('/src/**/*.{ts,tsx}', {
  query: '?raw',
  import: 'default',
  eager: true,
})

/**
 * A `${…}` immediately followed by a noun this product counts, in either language.
 *
 * The Arabic side lists the FORMS, not the lemma: «7 أيام» and «30 يومًا» are the two a hand-written
 * site gets right and wrong in turn, and a pattern that only knew «يوم» would have watched the one
 * spelling nobody actually types.
 *
 * The tail is `(?!\p{L})` rather than `\b`, because JavaScript's word boundary is defined over ASCII:
 * after «أيام» it asks for an adjacent ASCII letter and finds a backtick, so the pattern silently
 * matched nothing in Arabic — a guard that passes for the wrong reason.
 */
const HAND_WRITTEN =
  /\$\{[^}]{1,80}\}\s(campaigns|accounts|clients|days|reports|حمل(ة|ات)|حساب(ًا|ات)?|عميل(ًا)?|عملاء|يوم(ًا)?|أيام|تقرير|تقارير)(?!\p{L})/u

/** Named, each with the reason it is not the shared rule's business. */
const EXEMPT: Record<string, string> = {
  'src/lib/counted.ts': 'the rule itself',
  'src/features/reports/attributionWindow.ts':
    'prose, not a count: «نقرة خلال يوم واحد» — a numeral there reads as machine output, and the two agree from three upwards',
  'src/features/signup/PlanChooser.tsx':
    'the intro window is a plan term (30 days), never one day, and its sentence is written by the plan',
}

describe('counted nouns', () => {
  it('are not written by hand beside a number', () => {
    const offenders = Object.entries(TREE)
      .map(([path, source]) => [path.replace(/^\/+/, ''), source] as const)
      .filter(([path]) => !/\.test\.tsx?$/.test(path))
      .filter(([path]) => EXEMPT[path] === undefined)
      .filter(([, source]) => HAND_WRITTEN.test(source))
      .map(([path]) => path)

    expect(offenders, 'count the noun through lib/counted — or add the file to EXEMPT with a reason').toEqual([])
  })

  it('reads the source tree', () => {
    expect(Object.keys(TREE).length).toBeGreaterThan(200)
  })

  /** An exemption for a file that no longer needs one is a hole nobody meant to leave open. */
  it('exempts only files that still write their own', () => {
    const stale = Object.keys(EXEMPT)
      .filter((path) => path !== 'src/lib/counted.ts')
      .filter((path) => {
        const source = TREE['/' + path]
        return source === undefined || !HAND_WRITTEN.test(source)
      })

    expect(stale, 'these no longer write their own — take the exemption away').toEqual([])
  })
})

import { describe, expect, it } from 'vitest'
import { selectableMetrics } from './metricCatalog'

/**
 * KPI-KEY-EXISTS-001 — a page that names a metric gets that metric, or the naming fails loudly.
 *
 * ## The defect this was written for
 *
 * `CreativesPage` declared thirteen keys for the Content library's strip and the last one was
 * `completion_rate`. The catalogue's key is `video_completion_rate`. `buildItems` filters on
 * `SPECS[key]`, so the twelve that existed rendered and the thirteenth vanished — no warning, no
 * gap, no missing-metric card. The page simply showed one fewer figure than it had asked for, and
 * the only reason it surfaced at all was a LAYOUT measurement: the skeleton reserved thirteen cards
 * and twelve arrived, so the toolbar underneath moved.
 *
 * ## Why a source sweep rather than a typed key
 *
 * Typing the arrays against the catalogue is the better fix and is a larger change — several
 * surfaces build key lists at runtime from objectives, filters and the reader's own picks. This is
 * the part that can be held now: every key written as a LITERAL in a metric key list has to exist,
 * so the next typo cannot be silent while that refactor waits.
 */
const SOURCES = import.meta.glob('/src/**/*.{ts,tsx}', {
  query: '?raw',
  import: 'default',
  eager: true,
}) as Record<string, string>

/**
 * The lists that are actually HANDED to the catalogue.
 *
 * Two earlier spellings of this were wrong in opposite directions. Keying on the NAME required
 * `KPI` or `METRIC` in it and matched nothing the moment `CONTENT_KPI_KEYS` was renamed — a guard
 * that matches nothing agrees with everything, which its own self-check caught. Keying on any
 * `*_KEYS` swept in `COLOR_KEYS` and this page's `AXIS_KEYS`, neither of which the catalogue has
 * ever been asked about.
 *
 * The invariant is narrower than both: a list reaches `buildItems`, which silently drops any key
 * `SPECS` does not hold. So this finds what is passed to `metricsForKeys` and checks THAT.
 */
const PASSED = /metricsForKeys\(\s*([A-Za-z0-9_]+)/g
const declarationOf = (source: string, name: string): string | null => {
  const found = new RegExp(`const\\s+${name}\\s*(?::[^=]+)?=\\s*\\[([^\\]]*)\\]`).exec(source)

  return found === null ? null : found[1]
}

const LITERAL = /'([a-z0-9_]+)'/g

describe('a declared metric key exists in the catalogue', () => {
  const known = new Set(selectableMetrics(false).map((m) => m.key))
  const offenders: string[] = []
  let lists = 0

  for (const [path, raw] of Object.entries(SOURCES)) {
    if (path.includes('.test.') || path.includes('metricCatalog')) continue

    /*
     * Only the files that hand a key list to the CATALOGUE, which is where a typo goes silent.
     *
     * Keying on the NAME was brittle — it required `KPI` or `METRIC` in it, and matched nothing the
     * moment a list was renamed, and a guard that matches nothing agrees with everything. Keying on
     * `*_KEYS` alone was too wide and swept in `COLOR_KEYS`, which the catalogue has never been
     * asked about. The invariant is about the lists that reach `buildItems`, so that is the test.
     */
    if (!/metricsForKeys|metricCatalog/.test(raw)) continue

    for (const call of raw.matchAll(PASSED)) {
      const body = declarationOf(raw, call[1])

      /* Built inline or imported from elsewhere — nothing to read here, and not a silent typo. */
      if (body === null) continue

      lists++

      for (const key of body.matchAll(LITERAL)) {
        if (!known.has(key[1])) offenders.push(`${path}: ${call[1]} names «${key[1]}»`)
      }
    }
  }

  it('found the lists it claims to guard', () => {
    expect(known.size, 'the catalogue is empty — this guard is checking nothing').toBeGreaterThan(10)
    expect(lists, 'no declared key list was found; has the naming convention changed?').toBeGreaterThan(0)
  })

  it('has no key that the catalogue has never heard of', () => {
    expect(
      offenders,
      'These metric keys are declared by a surface and are not in the catalogue, so `buildItems`\n'
      + 'drops them and the card never appears — silently:\n  ' + offenders.join('\n  '),
    ).toEqual([])
  })
})

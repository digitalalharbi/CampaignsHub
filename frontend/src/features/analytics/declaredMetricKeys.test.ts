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

/** `const SOMETHING_KPI_KEYS = [ ... ]` / `..._METRIC_KEYS = [ ... ]` — the declared lists. */
const DECLARATION = /const\s+([A-Z0-9_]*(?:KPI|METRIC)_KEYS)\s*(?::[^=]+)?=\s*\[([^\]]*)\]/g
const LITERAL = /'([a-z0-9_]+)'/g

describe('a declared metric key exists in the catalogue', () => {
  const known = new Set(selectableMetrics(false).map((m) => m.key))
  const offenders: string[] = []
  let lists = 0

  for (const [path, raw] of Object.entries(SOURCES)) {
    if (path.includes('.test.')) continue

    for (const match of raw.matchAll(DECLARATION)) {
      lists++

      for (const key of match[2].matchAll(LITERAL)) {
        if (!known.has(key[1])) offenders.push(`${path}: ${match[1]} names «${key[1]}»`)
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

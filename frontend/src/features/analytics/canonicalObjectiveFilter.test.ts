import { describe, expect, it } from 'vitest'

import {
  CANONICAL_OBJECTIVE_KEYS,
  canonicalOfRaw,
  rawObjectivesFor,
} from '@/features/campaigns/canonicalObjectives'
import { layoutFor } from './metricCatalog'

/**
 * ANALYTICS-OBJECTIVE-SYSTEM-001 / ANALYTICS-FILTER-TRUTH-001 — one objective control, and it scopes
 * the SERVER.
 *
 * Two things had to be true at once and were not. The reader was offered «المسار التسويقي» and
 * «الهدف» side by side — two controls over one concept, which could disagree — and the headline row
 * was chosen by whichever of them was set. This asserts the single canonical control both narrows the
 * query and picks a headline row that the objective is actually judged on.
 */
describe('canonical objective as the only objective axis', () => {
  const mixed = layoutFor('all').primary

  it('every canonical objective changes the headline row away from the mixed-scope one', () => {
    for (const key of CANONICAL_OBJECTIVE_KEYS) {
      expect(layoutFor(key).primary, `«${key}» fell through to the operational row`).not.toEqual(mixed)
    }
  })

  it('awareness and engagement share a headline of attention, never of return', () => {
    const keys = layoutFor('awareness_engagement').primary
    expect(keys).toContain('impressions')
    expect(keys).toContain('reach')
    // A brand budget was never bought to return anything; a ROAS or a CPA here would be arithmetic
    // over an event the money never sought.
    expect(keys).not.toContain('roas')
    expect(keys).not.toContain('cpa')
  })

  it('app promotion headlines installs and their cost', () => {
    const keys = layoutFor('app_promotion').primary
    expect(keys).toContain('installs')
    expect(keys).toContain('cpi')
  })

  it('sales headlines the order, its cost and the return', () => {
    const keys = layoutFor('sales').primary
    expect(keys).toContain('cpa')
    expect(keys).toContain('roas')
  })

  /*
   * A canonical bucket is deliberately wider than a family, so when the scope turns out to hold ONE
   * family the narrower row is the truer one — a video-only scope should be headlined by completion
   * rate, not by the blended attention row that also has to serve a static awareness buy.
   */
  it('narrows to the family layout when the scope holds exactly one family of that canonical', () => {
    expect(layoutFor('awareness_engagement', ['video']).primary).toEqual(layoutFor('video').primary)
    expect(layoutFor('awareness_engagement', ['engagement']).primary).toEqual(layoutFor('engagement').primary)
  })

  it('keeps the canonical row when the scope spans more than one of its families', () => {
    expect(layoutFor('awareness_engagement', ['video', 'awareness']).primary).toEqual(
      layoutFor('awareness_engagement').primary,
    )
  })

  /*
   * The chosen objective is what the reader ASKED for. A family in scope that does not belong to it
   * cannot silently re-headline the page — that would answer a question nobody asked.
   */
  it('never lets a foreign family override the chosen objective', () => {
    expect(layoutFor('sales', ['awareness']).primary).toEqual(layoutFor('sales').primary)
  })

  /*
   * ANALYTICS-FILTER-TRUTH-001 — the canonical key is a LABEL. The metrics API filters on raw
   * objectives, so sending the canonical key itself would leave the query unscoped while the heading
   * claimed otherwise.
   */
  describe('the request carries raw objectives, never the canonical key', () => {
    it('expands every canonical key into a non-empty raw list', () => {
      for (const key of CANONICAL_OBJECTIVE_KEYS) {
        const raw = rawObjectivesFor(key)
        expect(raw.length, `«${key}» would send an empty objective list`).toBeGreaterThan(0)
        // Each item must be a RAW objective belonging to this canonical. Three canonical keys share
        // a name with a raw objective («traffic», «leads», «sales»), so «not the canonical key» would
        // be the wrong rule — the rule is that every item resolves back to the key that produced it.
        for (const objective of raw) {
          expect(canonicalOfRaw(objective), `«${objective}» is not a raw objective of «${key}»`).toBe(key)
        }
      }
    })

    it('sends nothing at all for «كل الأهداف» — an empty list is not a scope', () => {
      expect(rawObjectivesFor('all')).toEqual([])
    })
  })
})

import { describe, expect, it } from 'vitest'

import {
  decodePath, drillInto, drillUpTo, encodePath, nextLevel, parentFor, stepLabel, type DrillStep,
} from './drilldown'

/**
 * HIERARCHY-ENTITY-ANALYTICS-DRILLDOWN — the path is the scope, and it may never lie about itself.
 *
 * The failure that matters here is not a crash. It is a breadcrumb that says «ad sets of the summer
 * campaign» over a list that is really every ad set in the project — the reader then acts on figures
 * belonging to campaigns they are not looking at, and nothing on screen says so.
 */
const step = (level: DrillStep['level'], id: string, name: string | null = null): DrillStep => ({ level, id, name })

describe('the drill path', () => {
  it('narrows to the level immediately above, never one further up', () => {
    const path = [step('campaign', 'c1'), step('ad_set', 's1')]

    expect(parentFor('ad_set', path)).toBe('c1')
    expect(parentFor('ad', path)).toBe('s1')
  })

  /**
   * The defect this pins. With only a campaign pinned, the ad list is the campaign's ENTIRE ad
   * population — so it must be listed unnarrowed rather than narrowed by a campaign id the endpoint
   * would read as an ad-set id, and the breadcrumb must not claim an ad set that was never chosen.
   */
  it('does not pass a campaign where an ad set is expected', () => {
    expect(parentFor('ad', [step('campaign', 'c1')])).toBeNull()
  })

  it('lists everything when nothing is pinned', () => {
    expect(parentFor('ad_set', [])).toBeNull()
    expect(parentFor('ad', [])).toBeNull()
  })

  it('survives a round trip through the URL', () => {
    const path = [step('campaign', 'c1'), step('ad_set', 's1')]

    expect(decodePath(encodePath(path))).toEqual([step('campaign', 'c1'), step('ad_set', 's1')])
  })

  /** A hand-edited link is a reader's mistake, not a crash — trust the deepest prefix that parses. */
  it('keeps the trustworthy prefix of a malformed link', () => {
    expect(decodePath('campaign:c1~nonsense')).toEqual([step('campaign', 'c1')])
    expect(decodePath('campaign:c1~ad_set:')).toEqual([step('campaign', 'c1')])
    expect(decodePath(null)).toEqual([])
  })

  /**
   * A path must descend, but it need not start at the top: drilling from the ad-set tab into ads
   * without ever pinning a campaign is the ordinary case, and `ad_set:s1` is a complete path.
   */
  it('accepts a path that starts below the top level', () => {
    expect(decodePath('ad_set:s1')).toEqual([step('ad_set', 's1')])
    expect(parentFor('ad', decodePath('ad_set:s1'))).toBe('s1')
  })

  /** What it still refuses is a sequence that does not descend — it keeps the prefix and stops. */
  it('stops at the first step that does not descend', () => {
    expect(decodePath('ad:a1~campaign:c1')).toEqual([step('ad', 'a1')])
    expect(decodePath('campaign:c1~ad:a1')).toEqual([step('campaign', 'c1')])
  })

  it('replaces everything at or below the level it descends into', () => {
    const deep = [step('campaign', 'c1'), step('ad_set', 's1'), step('ad', 'a1')]

    expect(drillInto(deep, step('ad_set', 's2'))).toEqual([step('campaign', 'c1'), step('ad_set', 's2')])
  })

  it('steps back out keeping only what is above', () => {
    const deep = [step('campaign', 'c1'), step('ad_set', 's1'), step('ad', 'a1')]

    expect(drillUpTo(deep, 'ad')).toEqual([step('campaign', 'c1'), step('ad_set', 's1')])
    expect(drillUpTo(deep, 'campaign')).toEqual([])
  })

  it('knows where the hierarchy ends', () => {
    expect(nextLevel('campaign')).toBe('ad_set')
    expect(nextLevel('ad')).toBe('creative')
    expect(nextLevel('creative')).toBeNull()
  })

  /**
   * An entity the structure sweep has removed still has an id, and the reader is entitled to see it.
   * A dash would read as «nothing here» for something that really ran and really spent money.
   */
  it('shows an id rather than a dash for an entity that no longer has a name', () => {
    expect(stepLabel(step('ad_set', 's1', null))).toBe('s1')
    expect(stepLabel(step('ad_set', 's1', 'Summer'))).toBe('Summer')
  })
})

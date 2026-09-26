import { describe, expect, it } from 'vitest'

import {
  creativeScope, decodePath, drillInto, drillUpTo, encodePath, namesVersion, nextLevel, parentFor,
  rememberName, stepLabel, subscribeNames, withNames,
  type DrillStep,
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

  /** The library takes the deepest rung — both would narrow twice for one choice. */
  it('narrows the creative library by the deepest pinned rung', () => {
    expect(creativeScope([step('campaign', 'c1'), step('ad_set', 's1'), step('ad', 'a1')])).toEqual({ ad_ids: ['a1'] })
    expect(creativeScope([step('campaign', 'c1'), step('ad_set', 's1')])).toEqual({ ad_set_ids: ['s1'] })
  })

  /**
   * A campaign narrows nothing in the library, which has no campaign axis on this route. Passing a
   * campaign id into an ad filter would return an empty list reading «this campaign has no creatives».
   */
  it('does not invent an ad filter out of a campaign', () => {
    expect(creativeScope([step('campaign', 'c1')])).toEqual({})
    expect(creativeScope([])).toEqual({})
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

/**
 * A name that arrives from the SERVER arrives after the render that asked for it.
 *
 * `rememberName` used to be called only while drawing a row the reader had just clicked, so a plain
 * map was enough — whoever needed the name was rendering anyway. `parent_names` lands with the
 * response, when nothing is re-rendering, and a crumb that reads the map at that moment has already
 * drawn the uuid. So the registry has to say that it changed.
 */
describe('the name registry', () => {
  it('tells its listeners when a name it did not have arrives', () => {
    let told = 0
    const stop = subscribeNames(() => { told += 1 })
    const before = namesVersion()

    rememberName('s-notify', 'Riyadh · 18-34')

    expect(told).toBe(1)
    expect(namesVersion()).not.toBe(before)
    expect(withNames([{ level: 'ad_set', id: 's-notify', name: null }])[0].name).toBe('Riyadh · 18-34')

    stop()
  })

  /** Re-remembering the same name must not redraw the page on every response. */
  it('says nothing when the name it is given is the one it already holds', () => {
    rememberName('s-quiet', 'Jeddah')

    let told = 0
    const stop = subscribeNames(() => { told += 1 })

    rememberName('s-quiet', 'Jeddah')
    rememberName('s-quiet', null)
    rememberName('s-quiet', '')

    expect(told).toBe(0)

    stop()
  })

  it('stops telling a listener that unsubscribed', () => {
    let told = 0
    subscribeNames(() => { told += 1 })()

    rememberName('s-gone', 'Dammam')

    expect(told).toBe(0)
  })
})

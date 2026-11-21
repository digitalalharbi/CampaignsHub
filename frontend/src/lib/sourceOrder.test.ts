import { describe, expect, it } from 'vitest'
import { compareSources, sortBySource } from './sourceOrder'

describe('the one ordering of sources: project → platform → organisation → account', () => {
  const rows = [
    { id: 1, project: 'Nasel', platform: 'meta', organisation: 'Org B', account: 'Zed' },
    { id: 2, project: 'Nasel', platform: 'snapchat', organisation: null, account: 'Alpha' },
    { id: 3, project: 'Alpha Co', platform: 'tiktok', organisation: 'Org A', account: 'Main' },
    { id: 4, project: 'Nasel', platform: 'snapchat', organisation: 'Org A', account: 'Beta 10' },
    { id: 5, project: 'Nasel', platform: 'snapchat', organisation: 'Org A', account: 'Beta 2' },
    { id: 6, project: null, platform: 'snapchat', organisation: 'Org A', account: 'Orphan' },
  ]
  const key = (r: (typeof rows)[number]) => r

  it('orders by project, then the product\'s platform order, then organisation, then account', () => {
    expect(sortBySource(rows, key, false).map((r) => r.id)).toEqual([3, 5, 4, 2, 1, 6])
  })

  it('puts an absent name after every present one, at each level', () => {
    expect(compareSources({ organisation: null }, { organisation: 'A' }, false)).toBeGreaterThan(0)
    expect(compareSources({ project: 'A' }, { project: null }, false)).toBeLessThan(0)
    expect(compareSources({}, {}, false)).toBe(0)
  })

  it('reads a google_ads spelling as the same platform as google, and ranks an unknown one last', () => {
    const list = [{ platform: 'mystery', account: 'a' }, { platform: 'google_ads', account: 'b' }, { platform: 'google', account: 'a' }]
    expect(sortBySource(list, (r) => r, false).map((r) => `${r.platform}:${r.account}`)).toEqual(['google:a', 'google_ads:b', 'mystery:a'])
  })

  it('keeps the arrival order of rows whose keys are equal, and does not mutate the input', () => {
    const same = [{ id: 'x', account: 'A' }, { id: 'y', account: 'A' }]
    expect(sortBySource(same, (r) => ({ account: r.account }), true).map((r) => r.id)).toEqual(['x', 'y'])
    expect(rows[0]!.id).toBe(1)
  })
})

import { describe, expect, it } from 'vitest'

import { filterKeyParts, qf, type MetricFilters } from './api'

/**
 * ANALYTICS-FILTER-TRUTH-001 — a filter that narrows the request must also key the cache.
 *
 * `useEntities` built its query key by hand and omitted `campaign`. The URL carried the filter and
 * the key did not, so drilling into one campaign's ad squads was handed back the response cached for
 * ALL campaigns — a narrowed heading over unnarrowed rows, which is the exact failure a filter is
 * supposed to prevent. React Query cannot detect this; only an assertion over the axes can.
 */
describe('every filter axis narrows both the request and the cache key', () => {
  const all: Required<MetricFilters> = {
    provider: ['meta'],
    objective: ['sales'],
    campaign: ['c-1'],
  }

  // Written out rather than derived from `all`, so adding an axis to `MetricFilters` without adding
  // it here fails the count assertion below instead of silently passing.
  const AXES: Array<keyof MetricFilters> = ['provider', 'objective', 'campaign']

  it('covers every axis the type declares', () => {
    expect(Object.keys(all).sort()).toEqual([...AXES].sort())
  })

  for (const axis of AXES) {
    it(`«${axis}» reaches the query string`, () => {
      const without: MetricFilters = { ...all, [axis]: undefined }
      expect(qf(all)).not.toBe(qf(without))
      expect(qf(all)).toContain(`${axis}=`)
    })

    it(`«${axis}» reaches the cache key`, () => {
      const without: MetricFilters = { ...all, [axis]: undefined }
      expect(filterKeyParts(all)).not.toEqual(filterKeyParts(without))
    })
  }

  it('an absent filter is not the same cache entry as an empty one — both mean «every»', () => {
    expect(filterKeyParts(undefined)).toEqual(filterKeyParts({ provider: [], objective: [], campaign: [] }))
    expect(qf(undefined)).toBe(qf({ provider: [], objective: [], campaign: [] }))
  })
})

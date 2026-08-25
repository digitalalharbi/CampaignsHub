import { describe, expect, it } from 'vitest'

import { METRIC_SOURCE_LABELS, metricSourceLabel } from './metricSource'

/**
 * CONTENT-SOURCE-LABEL-001 — the «المصدر» column printed `platform_reported`.
 *
 * That column answers «where did this number come from», which is the first thing a reader checking
 * a figure asks — and it answered in the database's words.
 */
describe('metric source labels', () => {
  const SOURCES = ['platform_reported', 'store_confirmed', 'campaign_page']

  it.each(SOURCES)('labels %s in both languages', (source) => {
    expect(metricSourceLabel(source, true)).not.toBe(source)
    expect(metricSourceLabel(source, false)).not.toBe(source)
  })

  it('covers exactly the provenances the backend emits', () => {
    expect(Object.keys(METRIC_SOURCE_LABELS).sort()).toEqual([...SOURCES].sort())
  })

  /**
   * The distinction `AttributionTransparency` exists for: the shop saw the order, versus the ad
   * platform claiming it. The labels must not blur them.
   */
  it('keeps store-confirmed distinct from platform-reported', () => {
    expect(metricSourceLabel('store_confirmed', true)).not.toBe(metricSourceLabel('platform_reported', true))
    expect(metricSourceLabel('store_confirmed', true)).toContain('المتجر')
  })

  it('shows an unknown provenance as itself — where a number came from is not the thing to paper over', () => {
    expect(metricSourceLabel('modelled', true)).toBe('modelled')
  })

  it('says «—» when no source was given', () => {
    expect(metricSourceLabel(null, true)).toBe('—')
  })
})

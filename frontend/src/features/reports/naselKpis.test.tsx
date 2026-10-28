import { describe, expect, it } from 'vitest'
import { metricLabel, gateResultKeys } from './live/liveMetrics'
import type { LivePayload } from './api'

const payload = (over: Partial<LivePayload>) => over as LivePayload
const meta = { ar: 'عملاء النموذج الأصلي', en: 'Native-form leads' } as never

/** NASEL KPIs — the operator's own names, and the funnel steps only where lead events are configured. */
describe('custom KPI names and funnel steps', () => {
  it('uses the operator’s name for a card when one was given, else the catalogue name', () => {
    expect(metricLabel(meta, 'native_leads', payload({ kpi_labels: { native_leads: 'عملاء النماذج' } }), true)).toBe('عملاء النماذج')
    expect(metricLabel(meta, 'native_leads', payload({ kpi_labels: { native_leads: '  ' } }), false)).toBe('Native-form leads')
    expect(metricLabel(meta, 'native_leads', payload({}), true)).toBe('عملاء النموذج الأصلي')
  })

  it('offers form starts and page views only where lead events are configured', () => {
    const on = payload({ results: { configured: false, total: null, cost: null, by_platform: {}, by_path: {}, events: {}, leads: { configured: true, total: null, landing: null, native: null, cost: null, by_platform: {}, by_path: {}, events: {} } } })
    expect(gateResultKeys(['spend', 'form_starts', 'page_views'], on)).toEqual(['spend', 'form_starts', 'page_views'])
    expect(gateResultKeys(['spend', 'form_starts', 'page_views'], payload({}))).toEqual(['spend'])
  })
})

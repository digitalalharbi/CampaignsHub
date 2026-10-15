import { describe, expect, it } from 'vitest'
import { creativeChips, creativeReadings, reportMetrics } from './reportMetrics'

const kpis = { spend: 120, impressions: 9000, leads: 40, cpl: 3, conversion_rate: 0.1, ctr: 0.02 }
const base = { objective: 'leads', kpis, currency: 'USD', metric_set: ['spend', 'impressions', 'leads', 'cpl'] }

/**
 * LEAD-DESTINATION-001 — a WhatsApp buy is judged on messaging results and what one cost, and its
 * figures carry the platform's own name for them. A website lead campaign is untouched.
 */
describe('a messaging destination', () => {
  it('names the result and its cost as messaging, not as leads', () => {
    const m = reportMetrics({ ...base, lead_destination: 'whatsapp' }, false)
    expect(m.map((x) => x.label)).toEqual(['Spend', 'Impressions', 'Messages', 'Cost per message'])
  })

  it('names them in Arabic', () => {
    const m = reportMetrics({ ...base, lead_destination: 'instagram_direct' }, true)
    expect(m.map((x) => x.label)).toContain('تكلفة الرسالة')
  })

  it('leaves a website lead campaign calling its results leads', () => {
    const m = reportMetrics({ ...base, lead_destination: 'landing_page' }, false)
    expect(m.map((x) => x.label)).not.toContain('Messages')
  })

  it('shows no purchase or ROAS chip on a creative of a messaging buy', () => {
    const chips = creativeChips('leads', 'whatsapp')
    expect(chips).toEqual(['leads', 'cpl', 'impressions', 'spend'])
    for (const banned of ['roas', 'revenue', 'purchases']) expect(chips).not.toContain(banned)
  })

  it('reads the creative on messaging results and their cost', () => {
    const r = creativeReadings({ leads: 12, cpl: 2.5, impressions: 900, spend: 30 }, 'leads', undefined, false, 'USD', 'whatsapp')
    expect(r.map((x) => x.label).slice(0, 2)).toEqual(['Messages', 'Cost per message'])
  })
})

/**
 * REPORT-KPI-PRESETS-001 — a sale is an order, its value and the return, in short client words; a
 * known result type is never printed as the generic «النتائج».
 */
describe('short client-facing names by objective', () => {
  const sales = { objective: 'sales', kpis: { spend: 1, purchases: 2, revenue: 3, cpa: 4, roas: 5 }, currency: 'SAR', metric_set: ['spend', 'purchases', 'revenue', 'cpa', 'roas'] }

  it('names a sales report’s figures الطلبات · المبيعات · تكلفة الطلب · العائد', () => {
    const labels = reportMetrics(sales, true).map((m) => m.label)
    expect(labels).toEqual(['الإنفاق', 'الطلبات', 'المبيعات', 'تكلفة الطلب', 'العائد'])
  })

  it('names a traffic report’s figures النقرات · تكلفة النقرة · زيارات الصفحة', () => {
    const t = { objective: 'traffic', kpis: { spend: 1, impressions: 2, clicks: 3, ctr: 0.1, cpc: 4, landing_page_views: 5 }, currency: 'SAR', metric_set: ['spend', 'impressions', 'clicks', 'ctr', 'cpc', 'landing_page_views'] }
    const labels = reportMetrics(t, true).map((m) => m.label)
    expect(labels).toContain('النقرات')
    expect(labels).toContain('تكلفة النقرة')
    expect(labels).toContain('زيارات الصفحة')
  })

  it('never calls a known result type «النتائج»', () => {
    for (const objective of ['sales', 'leads', 'traffic', 'awareness', 'app_installs']) {
      const labels = reportMetrics({ objective, kpis: {}, currency: 'SAR', metric_set: ['leads', 'cpl', 'purchases', 'cpa'], lead_destination: objective === 'leads' ? 'whatsapp' : null }, true).map((m) => m.label)
      expect(labels).not.toContain('النتائج')
    }
  })
})

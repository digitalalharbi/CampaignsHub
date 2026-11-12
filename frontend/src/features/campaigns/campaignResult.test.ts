import { describe, expect, it } from 'vitest'
import { campaignResult, resultCostText, resultFamilyOf, returnsRevenue } from './campaignResult'

/**
 * OBJECTIVE-RESULT-001 — the frontend mirror of `ObjectiveResult.php`, held to the same cases as
 * `ObjectiveResultTest`. 1KCOFFEE: Snapchat TRAFFIC, 2,881 landing page views, 18,464 clicks, 0 orders.
 */
const COFFEE = { spend: 1440.5, spend_withheld_rows: 0, clicks: 18464, landing_page_views: 2881, conversions: 0, impressions: 900000 }

describe('the result an objective is judged on', () => {
  it('reads a traffic campaign’s visits, not its orders', () => {
    const r = campaignResult('traffic', COFFEE, { landing_page_views: true, clicks: true, conversions: true })

    expect(r.key).toBe('landing_page_views')
    expect(r.value).toBe(2881)
    expect(r.label?.ar).toBe('زيارات الصفحة')
    expect(r.costLabel?.ar).toBe('تكلفة الزيارة')
    expect(resultCostText(r, COFFEE, 'SAR')).toMatch(/0\.5/)
  })

  it('falls back to clicks named «النقرات», never «زيارات»', () => {
    const r = campaignResult('landing_page_views', { ...COFFEE, landing_page_views: 0 })

    expect(r.key).toBe('clicks')
    expect(r.value).toBe(18464)
    expect(r.label?.ar).toBe('النقرات')
    expect(r.label?.ar).not.toContain('زيارات')
  })

  it('names every family’s own result', () => {
    const row = { reach: 10, impressions: 20, landing_page_views: 3, clicks: 4, engagements: 5, video_views: 6, leads: 7, conversions: 8, installs: 9 }
    const expected: Array<[string, string]> = [
      ['awareness', 'reach'], ['reach', 'reach'], ['traffic', 'landing_page_views'], ['engagement', 'engagements'],
      ['video_views', 'video_views'], ['leads', 'leads'], ['sales', 'conversions'], ['purchases', 'conversions'], ['app_installs', 'installs'],
    ]
    for (const [objective, key] of expected) expect(campaignResult(objective, row).key, objective).toBe(key)
  })

  it('reads a leads campaign’s landing-page conversions as landing-page leads, never orders', () => {
    const r = campaignResult('leads', { leads: 0, conversions: 62, spend: 620 })
    expect(r.key).toBe('conversions')
    expect(r.label?.ar).toBe('مهتمين الهبوط')
    expect(campaignResult('sales', { conversions: 3 }).label?.ar).toBe('الطلبات')
  })

  it('has no result for an objective that is not set, and says why', () => {
    for (const objective of [null, 'other', 'store_visits', 'SALES']) {
      const r = campaignResult(objective, COFFEE)
      expect(resultFamilyOf(objective)).toBe('unknown')
      expect(r.value).toBeNull()
      expect(r.missingLabel?.ar).toBe('الهدف غير محدد')
    }
  })

  it('says «no data» for a metric the platform never sent, and keeps a reported zero', () => {
    expect(campaignResult('app_installs', { installs: 0 }, { installs: false }).missing).toBe('not_reported')
    expect(campaignResult('app_installs', { installs: 0 }, { installs: true }).value).toBe(0)
  })

  it('prefers the result a backend row already carries', () => {
    const r = campaignResult('traffic', { ...COFFEE, result_key: 'clicks', result: 18464 })
    expect(r.key).toBe('clicks')
    expect(r.value).toBe(18464)
  })

  it('prices awareness per thousand, and states a withheld cost in the platform’s own currency', () => {
    const aware = campaignResult('awareness', { reach: null, impressions: 50000, spend: 500 })
    expect(aware.key).toBe('impressions')
    expect(aware.per).toBe(1000)
    expect(resultCostText(aware, { reach: null, impressions: 50000, spend: 500 }, 'SAR')).toMatch(/10/)

    expect(resultCostText(campaignResult('traffic', COFFEE), { ...COFFEE, spend: 0, spend_original: 400, spend_withheld_rows: 2, money_original_currency: 'USD', money_original_currencies: 1 }, 'SAR')).toBe('0.14 USD')
  })

  it('gives revenue and ROAS to sales only', () => {
    expect(returnsRevenue('sales')).toBe(true)
    expect(returnsRevenue('conversions')).toBe(true)
    for (const o of ['traffic', 'awareness', 'leads', 'app_installs', 'other', null]) expect(returnsRevenue(o)).toBe(false)
  })
})

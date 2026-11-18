import { describe, expect, it } from 'vitest'
import { figuresFor, type ReportAd } from './ReportAdsSection'

/**
 * REPORT-CARD-OBJECTIVE-001 — Owner 2026-11-17: a content card states spend, result and return by its objective.
 *
 * Production (كرم النخيل, sales group): the card read «الإنفاق» alone while the ad carried 81 purchases and a 35.08×
 * return, because the link defines no Results and the generic count is withheld. The group's own count is printed instead.
 */
const salesAd = {
  name: 'Maamoul UGC',
  provider: 'snapchat',
  spend: 146.72,
  clicks: 409,
  purchases: 81,
  roas: 35.0793,
  result_defined: false,
} as ReportAd

describe('a card in an objective group states that objective', () => {
  it('a sales card reads spend, purchases and return', () => {
    const figures = figuresFor(salesAd, true, 'USD', { family: 'sales' })

    expect(figures.map((f) => f.label)).toEqual(['الإنفاق', 'الطلبات', 'العائد'])
    expect(figures.find((f) => f.label === 'الطلبات')?.value).toBe('81')
    expect(figures.find((f) => f.label === 'العائد')?.value).toBe('35.08×')
  })

  it('outside a group nothing is added', () => {
    expect(figuresFor(salesAd, true, 'USD').map((f) => f.label)).not.toContain('الطلبات')
  })

  it('a count the platform did not report is never printed as a figure', () => {
    const lead = { ...salesAd, leads: 0, reported: { leads: false } } as ReportAd
    expect(figuresFor(lead, true, 'USD', { family: 'leads' }).map((f) => f.label)).not.toContain('العملاء المحتملون')
  })
})

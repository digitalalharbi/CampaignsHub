import { describe, expect, it } from 'vitest'
import { clientBlurb, productName } from './reportProduct'

/**
 * REPORT-CLIENT-WORDING-001 — the agency's product names stay on the agency's side. A client reads what the
 * report contains and how fresh it is, never «Link Report» / «تقرير رابط» / «Saved Report» / «تقرير محفوظ».
 */
describe('clientBlurb', () => {
  const INTERNAL = /Link Report|Saved Report|تقرير رابط|تقرير محفوظ/

  it.each([
    ['live', 'ar'], ['live', 'en'], ['snapshot', 'ar'], ['snapshot', 'en'],
  ] as const)('never names the tool in %s / %s', (mode, locale) => {
    expect(clientBlurb(mode, locale)).not.toMatch(INTERNAL)
    expect(clientBlurb(mode, locale).length).toBeGreaterThan(20)
  })

  it('tells a client a live link is refreshed on every open, and a fixed one is as prepared', () => {
    expect(clientBlurb('live', 'en')).toMatch(/refreshed every time/)
    expect(clientBlurb('snapshot', 'en')).toMatch(/as prepared/)
  })

  it('leaves the agency-side names exactly as they were', () => {
    expect(productName('live', 'detailed', 'en')).toBe('Link Report')
    expect(productName('snapshot', 'detailed', 'ar')).toBe('تقرير محفوظ')
  })
})

import { afterEach, describe, expect, it } from 'vitest'
import { setCodeMarks, withMark } from '@/lib/money/mark'

/** REPORT-CURRENCY-CODE-001 — the shared report names its currency by code; every other screen keeps the short mark. */
describe('currency codes on the shared report', () => {
  afterEach(() => setCodeMarks(false))

  it('prints USD, SAR and EUR by code when the shared report switches it on', () => {
    setCodeMarks(true)
    expect(withMark('4.59K', 'USD')).toBe('4.59K USD')
    expect(withMark('950', 'sar')).toBe('950 SAR')
    expect(withMark('12', 'EUR')).toBe('12 EUR')
  })

  it('keeps the short mark everywhere else', () => {
    expect(withMark('950', 'SAR')).toBe('950 ر.س')
    expect(withMark('4.59K', 'USD')).toBe('4.59K $')
  })
})

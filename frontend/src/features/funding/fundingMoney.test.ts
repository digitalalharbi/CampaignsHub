import { describe, expect, it } from 'vitest'

/**
 * BUDGET-CURRENCY-001 — every money output of the budgets surfaces goes through `money.tsx` (the number, then its short currency
 * mark). A funding file that formats a figure on its own is the next «1,234» with no unit.
 */
const files = import.meta.glob('./*.tsx', { query: '?raw', import: 'default', eager: true }) as Record<string, string>
const own = new Set(['./money.tsx'])

describe('money in the budgets surfaces', () => {
  it('no funding component formats a number itself', () => {
    for (const [path, src] of Object.entries(files)) {
      if (own.has(path) || path.endsWith('.test.tsx')) continue
      expect(src, `${path} must use Money / formatMoney`).not.toMatch(/toLocaleString\(/)
      expect(src, `${path} must use Money / formatMoney`).not.toMatch(/\bL\.sar\b/)
    }
  })
})

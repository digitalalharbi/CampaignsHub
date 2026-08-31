import { describe, expect, it } from 'vitest'
import { money } from './format'

/**
 * MONEY-USD-001 — a money figure carries the currency it was measured in, or it carries none.
 *
 * ## The defect this file exists to prevent, observed in production
 *
 * «تكلفة النتيجة 18.05 SAR» on an account whose spend is denominated in USD. Nothing converted it;
 * nothing claimed a rate. `money()` took a currency parameter that DEFAULTED to `'SAR'`, and a
 * surface that had no currency to hand simply omitted the argument — so the figure was stamped with
 * the market this product happens to sell in, on a page whose Arabic locale made that look
 * deliberate.
 *
 * A wrong currency is worse than a missing one: 18.05 SAR and 18.05 USD are different costs, and a
 * media buyer acts on the number in front of them.
 *
 * ## Why the guard is a source scan and not only a unit test
 *
 * The unit test below pins the helper's behaviour. It cannot see the call sites, and the call sites
 * are where the defect lives: a page that never passes a currency compiles, renders, and lies. The
 * scan reads every source file for a one-argument call and fails on it.
 */
const TREE: Record<string, string> = import.meta.glob('/src/**/*.{ts,tsx}', {
  query: '?raw',
  import: 'default',
  eager: true,
})

/** `money(x)` / `moneyExact(x)` — one argument, so the currency is whatever the helper decides. */
const UNSTAMPED = /\b(money|moneyExact)\(\s*[^,()]*(\([^()]*\))?[^,()]*\)/

/**
 * Comments are stripped before the scan.
 *
 * The files that FIXED this defect describe it — «`money()` defaults to SAR, and the row carried no
 * currency» — and a scan that reads prose would report the explanation as the offence, which is the
 * fastest way to teach somebody to delete the explanation.
 */
function withoutComments(source: string): string {
  return source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:])\/\/[^\n]*/g, '$1')
}

describe('a money figure carries its own currency', () => {
  it('never falls back to a currency the caller did not state', () => {
    expect(money(18.05, 'USD')).toContain('USD')
    expect(money(18.05, 'SAR')).toContain('SAR')
    // A figure nobody measured is not a figure in some default currency.
    expect(money(null, 'USD')).toBe('—')
  })

  it('is never called without one', () => {
    const offenders = Object.entries(TREE)
      .map(([path, source]) => [path.replace(/^\/+/, ''), source] as const)
      .filter(([path]) => !/\.test\.tsx?$/.test(path))
      .filter(([path]) => path !== 'src/features/analytics/format.ts')
      .filter(([, source]) => UNSTAMPED.test(withoutComments(source)))
      .map(([path]) => path)

    expect(
      offenders,
      'these print a money figure without saying which currency it is in — «18.05 SAR» on a USD account is the production defect',
    ).toEqual([])
  })
})

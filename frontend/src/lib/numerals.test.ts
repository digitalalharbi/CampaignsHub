import { afterEach, describe, expect, it } from 'vitest'
import { formatFixed, formatNumber, numeralLocale, numeralSystem } from './numerals'
import { useAuth } from '@/stores/auth'
import type { AuthUser } from '@/lib/api/types'
import { compact, money, moneyExact, num, percent, ratio } from '@/features/analytics/format'

/**
 * NUMERAL-PREFERENCE-001 — the setting that was stored, validated, exposed and ignored.
 *
 * Two axes that were being confused for one:
 *
 *  - LANGUAGE (`locale`) — Arabic copy. The product's standing rule, documented in twelve places, is
 *    that this does NOT change the numerals: «١٢٬٤٠٠ ريال» beside an English platform name is
 *    unreadable, and an Arabic screenshot has to stay comparable with the English one.
 *  - PREFERENCE (`number_format`) — an explicit request for Arabic-Indic digits.
 *
 * The rule constrains the first. It never said a person may not ask for the second. These tests pin
 * both halves, because collapsing them is how the rule would get broken by accident later.
 */
function signInWithFormat(format?: 'latin' | 'arabic') {
  useAuth.getState().setUser({
    id: 'u1', name: 'Ops', email: 'ops@test', permissions: [], is_platform_admin: false,
    ...(format ? { number_format: format } : {}),
  } as unknown as AuthUser)
}

afterEach(() => useAuth.getState().setUser(null))

describe('which numerals the product uses', () => {
  it('defaults to Latin when nobody is signed in', () => {
    // The marketing site, the login page and the client shared report all render without a user.
    expect(numeralSystem()).toBe('latin')
    expect(numeralLocale()).toBe('en-US')
  })

  it('defaults to Latin for a signed-in person who never chose', () => {
    signInWithFormat()
    expect(numeralSystem()).toBe('latin')
  })

  it('honours an explicit choice of Arabic digits', () => {
    signInWithFormat('arabic')
    expect(numeralSystem()).toBe('arabic')
    expect(numeralLocale()).toBe('ar-EG-u-nu-arab')
  })

  it('falls back to Latin rather than guessing at an unknown value', () => {
    useAuth.getState().setUser({ id: 'u1', permissions: [], number_format: 'devanagari' } as unknown as AuthUser)
    expect(numeralSystem()).toBe('latin')
  })
})

describe('the formatters, under each system', () => {
  it('writes Latin digits by default', () => {
    signInWithFormat('latin')
    expect(formatNumber(1234)).toBe('1,234')
    expect(formatFixed(3.5, 2)).toBe('3.50')
    expect(num(96_122)).toBe('96,122')
    expect(percent(0.005, 1)).toBe('0.5%')
    expect(ratio(3.5, '×')).toBe('3.50×')
    expect(money(900, 'SAR')).toBe('900 SAR')
  })

  it('writes Arabic-Indic digits when that is what was asked for', () => {
    signInWithFormat('arabic')
    // Every one of these went through `toFixed` or a hardcoded `en-US` before, which is why the
    // preference reached nothing at all.
    expect(num(96_122)).toMatch(/[٠-٩]/)
    expect(percent(0.005, 1)).toMatch(/[٠-٩]/)
    expect(ratio(3.5, '×')).toMatch(/[٠-٩]/)
    expect(compact(1_500)).toMatch(/[٠-٩]/)
    expect(moneyExact(29.71, 'SAR')).toMatch(/[٠-٩]/)
  })

  it('keeps the currency code and the K/M suffix in Latin, because they are not numbers', () => {
    signInWithFormat('arabic')
    expect(money(1_500, 'SAR')).toContain('SAR')
    expect(compact(1_500)).toContain('K')
    expect(compact(2_000_000)).toContain('M')
  })

  it('still refuses a missing figure in both systems', () => {
    for (const f of ['latin', 'arabic'] as const) {
      signInWithFormat(f)
      expect(num(null)).toBe('—')
      expect(percent(undefined)).toBe('—')
      expect(money(null, 'SAR')).toBe('—')
    }
  })

  it('does not round a real sub-unit figure away in either system', () => {
    // COMPACT-ZERO-001 must survive the change: 0.028 is not «0».
    signInWithFormat('latin')
    expect(compact(0.028)).toBe('0.03')
    signInWithFormat('arabic')
    expect(compact(0.028)).not.toMatch(/^0$/)
    expect(compact(0.028)).toMatch(/[٠-٩]/)
  })
})

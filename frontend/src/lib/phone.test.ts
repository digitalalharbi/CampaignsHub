import { describe, expect, it } from 'vitest'
import { isReadablePhone, normalisePhone } from './phone'

/**
 * PHONE-001 — the browser must accept exactly what the backend accepts.
 *
 * The cases are deliberately the same list as `PhoneNumberTest.php`. Two implementations of one rule
 * is a real cost, paid because the form is where the customer is refused and the server is where the
 * value is stored; keeping the tables identical is what stops them drifting into two different rules,
 * which is the state this feature was written to end.
 */
describe('normalisePhone', () => {
  const saudi: Array<[string, string]> = [
    ['+966501234567', '+966501234567'],
    ['+966 50 123 4567', '+966501234567'],
    ['+966-50-123-4567', '+966501234567'],
    ['+966 (50) 123 4567', '+966501234567'],
    ['00966501234567', '+966501234567'],
    ['966501234567', '+966501234567'],
    ['0501234567', '+966501234567'],
    ['050 123 4567', '+966501234567'],
    ['050-123-4567', '+966501234567'],
    ['501234567', '+966501234567'],
    ['٠٥٠١٢٣٤٥٦٧', '+966501234567'],
    ['+٩٦٦٥٠١٢٣٤٥٦٧', '+966501234567'],
    ['۰۵۰۱۲۳۴۵۶۷', '+966501234567'],
    ['9660501234567', '+966501234567'],
    ['  +966501234567  ', '+966501234567'],
  ]

  it.each(saudi)('reads %s as %s', (input, expected) => {
    expect(normalisePhone(input)).toBe(expected)
  })

  it('keeps a number that names its own country', () => {
    expect(normalisePhone('+20 123 456 7890')).toBe('+201234567890')
    expect(normalisePhone('+971 50 123 4567')).toBe('+971501234567')
    expect(normalisePhone('0090 532 123 4567')).toBe('+905321234567')
  })

  it('reads a bare national number in the chosen country, not as another country code (PHONE-CHOSEN-COUNTRY-001)', () => {
    // Production 2026-11-26: an Egyptian applicant chose +20, typed 1012345678, and was stored as +1012345678.
    expect(normalisePhone('1012345678', '20')).toBe('+201012345678')
    expect(normalisePhone('01012345678', '20')).toBe('+201012345678')
    expect(normalisePhone('201012345678', '20')).toBe('+201012345678')
    expect(normalisePhone('447700900123', '44')).toBe('+447700900123')
    expect(normalisePhone('0501234567', '971')).toBe('+971501234567')
    // With no country chosen, a bare foreign number keeps its own code, as before.
    expect(normalisePhone('201012345678')).toBe('+201012345678')
    expect(normalisePhone('+1 415 555 0100', '20')).toBe('+14155550100')
  })

  it.each([null, undefined, '', 'not a phone', '---', '123', '+9665012345678901234'])(
    'returns null for unreadable input %s',
    (input) => {
      expect(normalisePhone(input)).toBeNull()
    },
  )
})

describe('isReadablePhone', () => {
  it('accepts the national format the old regex refused', () => {
    expect(isReadablePhone('0501234567')).toBe(true)
    expect(isReadablePhone('٠٥٠١٢٣٤٥٦٧')).toBe(true)
  })

  it('accepts an empty field — whether it is required is a separate question', () => {
    expect(isReadablePhone('')).toBe(true)
    expect(isReadablePhone(null)).toBe(true)
  })

  it('still refuses something that is not a number', () => {
    expect(isReadablePhone('call me maybe')).toBe(false)
  })
})

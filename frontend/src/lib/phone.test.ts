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

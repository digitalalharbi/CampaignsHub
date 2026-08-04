import { describe, expect, it } from 'vitest'
import { belongsToAccountStep, passwordProblem, validateAccountStep } from './registerValidation'

/**
 * SIGNUP-STEP-001 — the rules the account step enforces, on their own.
 *
 * They exist to mirror `RegisterRequest` on the server, so the cases below are the server's cases:
 * `Password::min(8)->letters()->numbers()`, `confirmed`, `email:rfc`, and the length caps. A rule
 * that is stricter here refuses an account the platform would have accepted; a rule that is looser
 * puts the refusal on the packages step, which is the failure the whole unit removes.
 */

const valid = {
  tenant_name: 'Acme',
  name: 'Tester',
  email: 'new@test.dev',
  password: 'secret123',
  password_confirmation: 'secret123',
}

describe('validateAccountStep', () => {
  it('passes a form the server would accept', () => {
    expect(validateAccountStep(valid, false)).toEqual({})
  })

  it.each([
    ['tenant_name', { tenant_name: '' }],
    ['name', { name: '   ' }],
    ['email', { email: '' }],
    ['password', { password: '' }],
    ['password_confirmation', { password_confirmation: '' }],
  ])('requires %s', (field, patch) => {
    expect(validateAccountStep({ ...valid, ...patch }, false)).toHaveProperty(field)
  })

  it('refuses an address with no domain', () => {
    expect(validateAccountStep({ ...valid, email: 'not-an-address' }, false).email).toMatch(/valid email/i)
  })

  /** The cap the server states — a longer value is refused there, so refusing it here saves a trip. */
  it('refuses a name past the column it has to fit', () => {
    expect(validateAccountStep({ ...valid, tenant_name: 'x'.repeat(121) }, false).tenant_name).toMatch(/121|120|at most/i)
  })

  it('refuses a confirmation that does not match', () => {
    const errors = validateAccountStep({ ...valid, password_confirmation: 'something-else' }, false)
    expect(errors.password_confirmation).toMatch(/does not match/i)
    expect(errors.password).toBeUndefined()
  })

  /** Arabic is not a translation of the English here — it is the first language of this product. */
  it('answers in the reader’s language', () => {
    expect(validateAccountStep({ ...valid, password: 'short' }, true).password).toMatch(/كلمة المرور/)
  })
})

describe('passwordProblem', () => {
  it.each([
    ['short', /8/],
    ['12345678', /letter/i],
    ['letters-only', /number/i],
  ])('rejects %s', (password, expected) => {
    expect(passwordProblem(password, false)).toMatch(expected)
  })

  it('accepts eight characters with a letter and a number', () => {
    expect(passwordProblem('secret12', false)).toBeUndefined()
  })

  /**
   * A non-Latin letter counts as a letter.
   *
   * `Password::letters()` uses a Unicode-aware check, and an Arabic-first product whose password
   * rule silently meant "Latin letters" would refuse a password the server accepts.
   */
  it('counts an Arabic letter as a letter', () => {
    expect(passwordProblem('كلمةسرية12', false)).toBeUndefined()
  })
})

describe('belongsToAccountStep', () => {
  it('routes the server’s account errors back to the step that has the field', () => {
    expect(belongsToAccountStep(['email'])).toBe(true)
    expect(belongsToAccountStep(['plan_code'])).toBe(false)
    expect(belongsToAccountStep(['plan_code', 'email'])).toBe(true)
  })
})

import { describe, expect, it } from 'vitest'
import { SEND_NOW_AR, SEND_NOW_EN, sendNowFailure, sendNowWord } from './sendNowWords'

/**
 * NOTIFICATION-ENROLMENT-001 — a «send me this now» press never prints a raw key.
 *
 * The list is every state the server can answer with: the digest dispatcher's (`sent`, `already_sent`, `awaiting_credentials`,
 * `skipped`, `failed`, the gate's refusals), the alert sweep's counts, the two refusals of the route itself, and the limiter.
 */
const SERVER_STATES = [
  'sent', 'already_sent', 'awaiting_credentials', 'skipped', 'failed', 'unsubscribed', 'suppressed', 'limit_reached',
  'address_not_proven', 'not_enrolled', 'switched_off', 'held_for_digest', 'held_by_quiet_hours', 'below_importance',
  'repeat_limit', 'out_of_scope', 'throttled',
]

describe('every answer of a send-now press has a word in both languages', () => {
  it.each(SERVER_STATES)('%s', (state) => {
    for (const ar of [true, false]) {
      const w = sendNowWord(ar, state)
      expect(w, `${state} has no word`).toBeTruthy()
      expect(w).not.toMatch(/_/)
      expect(w).not.toBe(state)
    }
    expect(Object.keys(SEND_NOW_AR).sort()).toEqual(Object.keys(SEND_NOW_EN).sort())
  })

  it('a reason with its own word wins, and an unknown answer reads as a failure rather than a key', () => {
    expect(sendNowWord(false, 'skipped', 'no_activity')).toBe('No activity in the period')
    expect(sendNowWord(false, 'skipped', 'something_else')).toBe('Nothing in your scope')
    expect(sendNowWord(false, 'brand_new_state')).toBe('Could not be sent')
    expect(sendNowWord(true, 'brand_new_state')).toBe('تعذّر الإرسال')
  })

  it('a failed request is a refusal code from the body, the limiter, or a plain failure', () => {
    expect(sendNowFailure({ response: { status: 422, data: { errors: { code: 'address_not_proven' } } } })).toBe('address_not_proven')
    expect(sendNowFailure({ response: { status: 429 } })).toBe('throttled')
    expect(sendNowFailure(new Error('network'))).toBe('failed')
  })
})

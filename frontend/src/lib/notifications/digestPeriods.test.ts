import { describe, expect, it } from 'vitest'
import { checkPeriod, DIGEST_FREQUENCIES, DIGEST_PERIOD_KEYS } from './digestPeriods'

describe('digest period rules (mirror of the backend table)', () => {
  it('every frequency answers every period, so no pair is silently unknown', () => {
    for (const f of DIGEST_FREQUENCIES) {
      for (const p of DIGEST_PERIOD_KEYS) expect(checkPeriod(p, f).ok || checkPeriod(p, f).alternative !== null).toBe(true)
    }
  })

  it('blocks an illogical pair and offers the alternative', () => {
    expect(checkPeriod('last_week', 'daily')).toEqual({ ok: false, alternative: 'last_7' })
    expect(checkPeriod('yesterday', 'monthly')).toEqual({ ok: false, alternative: 'last_month' })
    expect(checkPeriod('last_month', 'monthly')).toEqual({ ok: true, alternative: null })
  })

  it('every alternative is itself allowed for that frequency', () => {
    for (const f of DIGEST_FREQUENCIES) {
      for (const p of DIGEST_PERIOD_KEYS) {
        const alt = checkPeriod(p, f).alternative
        if (alt) expect(checkPeriod(alt, f).ok).toBe(true)
      }
    }
  })
})

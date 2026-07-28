import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  allowedNext, canTransition, isOfframp, paymentStatusForStage, REQUEST_STAGES, stageLabel,
  transitionJourney, TRANSITION_MAP,
} from './api'
import { api } from '@/lib/api/client'

describe('requestJourney/api state machine', () => {
  afterEach(() => vi.restoreAllMocks())

  it('mirrors the backend transition map for key stages', () => {
    expect(allowedNext('submitted')).toEqual(['under_review', 'rejected', 'cancelled', 'on_hold'])
    expect(allowedNext('payment_pending')).toEqual(['paid', 'payment_failed', 'cancelled', 'on_hold'])
    // Terminal stage — no further transitions.
    expect(allowedNext('archived')).toEqual([])
    // Unknown stage is safe (empty), never throws.
    expect(allowedNext('nonsense')).toEqual([])
  })

  it('gates transitions: valid enabled, invalid rejected', () => {
    expect(canTransition('submitted', 'under_review')).toBe(true)
    expect(canTransition('submitted', 'paid')).toBe(false)
    expect(canTransition('draft', 'completed')).toBe(false)
  })

  it('has a transition entry and a bilingual label for every stage', () => {
    for (const s of REQUEST_STAGES) {
      expect(TRANSITION_MAP[s]).toBeDefined()
      expect(stageLabel(s, true)).not.toBe(s) // an Arabic label exists
      expect(stageLabel(s, false)).not.toBe(s) // an English label exists
    }
  })

  it('couples the money-moving stages to a payment status, others to null', () => {
    expect(paymentStatusForStage('payment_pending')).toBe('pending')
    expect(paymentStatusForStage('paid')).toBe('paid')
    expect(paymentStatusForStage('payment_failed')).toBe('failed')
    expect(paymentStatusForStage('refunded')).toBe('refunded')
    expect(paymentStatusForStage('under_review')).toBeNull()
  })

  it('flags off-ramp stages', () => {
    expect(isOfframp('cancelled')).toBe(true)
    expect(isOfframp('on_hold')).toBe(true)
    expect(isOfframp('in_progress')).toBe(false)
  })

  it('PATCHes the journey endpoint and unwraps { journey_stage, payment_status }', async () => {
    const spy = vi.spyOn(api, 'patch').mockResolvedValue({
      data: { data: { journey_stage: 'under_review', payment_status: null } },
    } as never)
    const res = await transitionJourney('req-1', 'under_review', 'looks good')
    expect(res).toEqual({ journey_stage: 'under_review', payment_status: null })
    expect(spy).toHaveBeenCalledWith('/app/requests/req-1/journey', { stage: 'under_review', reason: 'looks good' })
  })

  it('omits reason when not provided', async () => {
    const spy = vi.spyOn(api, 'patch').mockResolvedValue({
      data: { data: { journey_stage: 'qualified', payment_status: null } },
    } as never)
    await transitionJourney('req-1', 'qualified')
    expect(spy).toHaveBeenCalledWith('/app/requests/req-1/journey', { stage: 'qualified' })
  })
})

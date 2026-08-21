import { describe, expect, it } from 'vitest'
import { readMetric } from './metricCatalog'

/**
 * FX-WITHHELD-UI-001 — the reading that decides whether a real figure or a zero reaches the card.
 *
 * The live Snapchat account reported 3,465.33 USD. No USD→SAR rate exists, so FX-001 stored
 * `value = null` deliberately and the aggregator coalesced it to 0 for arithmetic. Every screen then
 * printed «0 SAR» under a label saying the platform had not sent it — which the payload disproves.
 */
const spendSpec = { format: (v: number) => `${v} SAR` } as never

describe('withheld money', () => {
  it('shows the platform figure with its own currency instead of a zero', () => {
    const reading = readMetric(
      'spend',
      spendSpec,
      { spend: 0, spend_original: 3465.33, spend_withheld_rows: 198, money_original_currency: 'USD', money_original_currencies: 1 } as never,
      undefined,
    )

    expect(reading).toEqual({ kind: 'withheld', original: '3,465.33 USD' })
  })

  it('does not claim a currency it cannot name', () => {
    // Two currencies withheld: the original total spans both, so one label would misstate it.
    const reading = readMetric(
      'spend',
      spendSpec,
      { spend: 0, spend_original: 5100, spend_withheld_rows: 2, money_original_currency: 'USD', money_original_currencies: 2 } as never,
      undefined,
    )

    expect(reading.kind).not.toBe('withheld')
  })

  it('leaves a genuinely converted figure alone', () => {
    const reading = readMetric(
      'spend',
      spendSpec,
      { spend: 375, spend_original: 100, spend_withheld_rows: 0, money_original_currency: null, money_original_currencies: 0 } as never,
      undefined,
    )

    expect(reading).toEqual({ kind: 'value', text: '375 SAR' })
  })

  it('a real zero stays a real zero', () => {
    // Nothing withheld: the account spent nothing that day, and that is a measurement.
    const reading = readMetric(
      'spend',
      spendSpec,
      { spend: 0, spend_original: 0, spend_withheld_rows: 0 } as never,
      undefined,
    )

    expect(reading).toEqual({ kind: 'value', text: '0 SAR' })
  })
})

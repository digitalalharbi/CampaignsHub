import { describe, expect, it } from 'vitest'
import { carryTarget } from './carry'
import { L } from './labels'

/** BUDGET-CARRY-001 — «ترحيل للشهر الجديد» moves a closed period's end to the end of the new month. */
describe('carryTarget', () => {
  it('an open period has nothing to move', () => {
    expect(carryTarget(null, '2026-11-27')).toBeNull()
  })

  it('a period that already closed carries into this month', () => {
    expect(carryTarget('2026-10-31', '2026-11-27')).toBe('2026-11-30')
  })

  it('a period ending this month carries into the next one', () => {
    expect(carryTarget('2026-11-30', '2026-11-27')).toBe('2026-12-31')
  })

  it('a period ending mid-month runs to the end of that month', () => {
    expect(carryTarget('2026-11-15', '2026-11-10')).toBe('2026-11-30')
  })

  it('crosses the year', () => {
    expect(carryTarget('2026-12-31', '2026-12-20')).toBe('2027-01-31')
  })
})

describe('the approved terms', () => {
  it('names the three buttons and the log as approved by the Owner', () => {
    expect(L.edit.ar).toBe('تعديل المبلغ')
    expect(L.renew.ar).toBe('ترحيل للشهر الجديد')
    expect(L.del.ar).toBe('أرشفة')
    expect(L.history.ar).toBe('سجل التعديلات')
    expect(L.reason.ar).toBe('الملاحظة')
  })
})

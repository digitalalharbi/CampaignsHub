import { describe, expect, it } from 'vitest'
import { changeFieldLabel, changeValueLabel, changedFields } from './campaignChangeHistory'

/** CAMPAIGN-MGMT-CHANGE-HISTORY-001 — a change reads as what changed, in the reader's language. */
describe('campaign change history', () => {
  it('names the audited fields and says their values the way the product does', () => {
    expect(changeFieldLabel('total_budget', 'ar')).toBe('الميزانية الإجمالية')
    expect(changeFieldLabel('status', 'en')).toBe('Status')
    expect(changeValueLabel('total_budget', '2000', 'ar', 'SAR')).toBe('2,000 SAR')
    expect(changeValueLabel('status', 'paused', 'ar', null)).not.toBe('paused')
    expect(changeValueLabel('status', 'paused', 'en', null)).toMatch(/Paused/i)
    expect(changeValueLabel('objective', 'sales', 'ar', null)).not.toBe('sales')
    expect(changeValueLabel('name', 'رمضان', 'ar', null)).toBe('رمضان')
    expect(changeValueLabel('priority', null, 'ar', null)).toBe('—')
  })

  it('keeps an unknown field by its stored name and value — never hidden, never guessed at', () => {
    expect(changeFieldLabel('some_new_column', 'ar')).toBe('some_new_column')
    expect(changeValueLabel('some_new_column', 'x', 'ar', null)).toBe('x')
  })

  it('lists only the fields whose stored value changed', () => {
    expect(changedFields({ name: 'A', status: 'active', total_budget: 1000 }, { name: 'A', status: 'paused', total_budget: '1000' })).toEqual(['status'])
    expect(changedFields(null, { status: 'paused' })).toEqual([])
    expect(changedFields({ status: 'active' }, { status: 'active', priority: 'high' })).toEqual(['priority'])
  })
})

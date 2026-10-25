import { describe, expect, it } from 'vitest'
import { kpiRowClass } from './kpiRow'

describe('the KPI row', () => {
  it('keeps the seven-card sales funnel on one row at both breakpoints, and leaves smaller sets as they were', () => {
    expect(kpiRowClass(7, 'lg')).toBe('lg:grid-cols-7')
    expect(kpiRowClass(7, 'xl')).toBe('xl:grid-cols-7')
    expect(kpiRowClass(8, 'xl')).toBe('xl:grid-cols-7')
    expect(kpiRowClass(5, 'lg')).toBe('lg:grid-cols-6')
    expect(kpiRowClass(6, 'xl')).toBe('xl:grid-cols-6')
  })
})

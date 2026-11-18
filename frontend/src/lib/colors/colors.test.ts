import { describe, expect, it } from 'vitest'
import { PLATFORM_COLOR, PLATFORM_KEYS, STATUS_COLOR, STATUS_LABEL, budgetStatus, platformColor } from './index'

describe('the shared colour system', () => {
  it('gives every platform exactly one theme token, and no two platforms share one', () => {
    const values = PLATFORM_KEYS.map((k) => PLATFORM_COLOR[k])
    expect(new Set(values).size).toBe(values.length)
    for (const v of values) expect(v).toMatch(/^var\(--platform-[a-z]+\)$/)
  })

  it('reads any spelling of a platform as the same colour', () => {
    expect(platformColor('google_ads')).toBe(platformColor('google'))
    expect(platformColor('facebook')).toBe(platformColor('meta'))
    expect(platformColor('chatgpt')).toBe(platformColor('openai_ads'))
  })

  it('states green within, orange near, red exceeded, and a reading-less unit as neutral', () => {
    expect(budgetStatus(0.5)).toBe('within')
    expect(budgetStatus(0.8)).toBe('near')
    expect(budgetStatus(0.99)).toBe('near')
    expect(budgetStatus(1)).toBe('exceeded')
    expect(budgetStatus(1.4)).toBe('exceeded')
    expect(budgetStatus(null)).toBe('unknown')
    expect(budgetStatus(Number.NaN)).toBe('unknown')
    expect(STATUS_COLOR.within).toBe('var(--success)')
    expect(STATUS_COLOR.near).toBe('var(--warning)')
    expect(STATUS_COLOR.exceeded).toBe('var(--danger)')
  })

  it('never leaves a status without its word (colour alone is not a signal)', () => {
    for (const s of ['within', 'near', 'exceeded', 'unknown'] as const) {
      expect(STATUS_LABEL[s].ar.length).toBeGreaterThan(0)
      expect(STATUS_LABEL[s].en.length).toBeGreaterThan(0)
    }
  })
})

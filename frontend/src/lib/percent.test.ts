import { describe, expect, it } from 'vitest'
import { formatWholePercent } from './percent'

describe('formatWholePercent — PROJECT-LIFECYCLE-001 §8 («الإعداد» 10000%)', () => {
  it('shows a 0–100 value as itself, never ×100', () => {
    expect(formatWholePercent(100)).toBe('100%')
    expect(formatWholePercent(67)).toBe('67%')
    expect(formatWholePercent(0)).toBe('0%')
  })

  it('rounds, clamps and survives missing values', () => {
    expect(formatWholePercent(66.6)).toBe('67%')
    expect(formatWholePercent(10000)).toBe('100%')
    expect(formatWholePercent(-5)).toBe('0%')
    expect(formatWholePercent(undefined)).toBe('0%')
    expect(formatWholePercent(Number.NaN)).toBe('0%')
  })
})

describe('every use of setup_completion goes through the one formatter', () => {
  const TREE: Record<string, string> = import.meta.glob('/src/**/*.{ts,tsx}', { query: '?raw', import: 'default', eager: true })

  it('nothing multiplies setup_completion, and every rendered one is formatted by formatWholePercent', () => {
    const offenders: string[] = []
    for (const [path, src] of Object.entries(TREE)) {
      if (path.includes('.test.') || path.endsWith('/lib/percent.ts')) continue
      if (/setup_completion[^\n]{0,20}\*\s*100/.test(src)) offenders.push(`${path}: ×100`)
      if (/\{[^{}]*setup_completion\}%/.test(src) || /`\$\{[^}]*setup_completion\}%`/.test(src)) offenders.push(`${path}: raw %`)
    }
    expect(offenders).toEqual([])
  })
})

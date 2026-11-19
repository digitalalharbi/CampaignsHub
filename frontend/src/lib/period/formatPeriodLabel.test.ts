import { describe, expect, it } from 'vitest'
import examples from './periodLabelExamples.json'
import { formatPeriodLabel, formatPeriodRange } from './formatPeriodLabel'
import { presetPeriod, type PeriodKey, type PresetKey } from './periodMath'

/** The Owner's approved examples (shared with the PHP twin), today = 2026-11-19. */
describe('formatPeriodLabel — the approved examples', () => {
  for (const c of examples.cases) {
    for (const locale of ['ar', 'en'] as const) {
      it(`${c.key} ${c.from}..${c.to} (${locale})`, () => {
        expect(formatPeriodLabel({ key: c.key as PeriodKey, from: c.from, to: c.to, locale })).toBe(c[locale])
      })
    }
  }

  it('the preset RESOLVER and the label agree: every example preset resolves to its own dates on that day', () => {
    for (const c of examples.cases) {
      if (c.key === 'custom') continue
      const p = presetPeriod(c.key as PresetKey, { today: examples.today })
      expect([p.from, p.to]).toEqual([c.from, c.to])
    }
  })

  it('infers the name from the dates only when asked, and never for a custom range', () => {
    const t = examples.today
    expect(formatPeriodLabel({ from: '2026-11-12', to: '2026-11-18', locale: 'en', today: t, inferName: true })).toBe('Last 7 days: Nov 12 – Nov 18, 2026')
    expect(formatPeriodLabel({ from: '2026-11-12', to: '2026-11-18', locale: 'en', today: t })).toBe('Nov 12 – Nov 18, 2026')
    expect(formatPeriodLabel({ from: '2026-11-03', to: '2026-11-10', locale: 'ar', today: t, inferName: true })).toBe('3 نوفمبر – 10 نوفمبر 2026')
  })

  it('orders a reversed pair and keeps Latin digits and the en-dash', () => {
    expect(formatPeriodRange('2026-11-10', '2026-11-03', 'ar')).toBe('3 نوفمبر – 10 نوفمبر 2026')
    expect(formatPeriodRange('2026-11-03', '2026-11-10', 'ar')).not.toMatch(/[٠-٩]/)
    expect(formatPeriodRange('2026-11-03', '2026-11-10', 'ar')).toContain('–')
  })
})

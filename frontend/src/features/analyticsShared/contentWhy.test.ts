import { describe, expect, it } from 'vitest'
import type { CreativeCard } from '@/features/content/api'
import { bestAndWeakest, contentWhy } from './contentWhy'

const card = (id: string, o: Partial<{ provider: string; objective: string; imp: number; clicks: number; spend: number; freq: number; ctrChange: number | null }>): CreativeCard => ({
  id, name: `c-${id}`, provider: o.provider ?? 'meta', objective: o.objective ?? 'sales',
  metrics: { impressions: o.imp ?? 10000, clicks: o.clicks ?? 100, spend: o.spend ?? 100, frequency: o.freq ?? null },
  fatigue: { status: 'watch', signals: o.ctrChange === undefined ? [] : [{ metric: 'ctr', direction: 'down', change: o.ctrChange }], reason_ar: '', reason_en: '' },
}) as unknown as CreativeCard

describe('contentWhy — against the same objective AND platform, from real figures only', () => {
  it('compares each item with its group average and states its share of the group spend', () => {
    const rows = contentWhy([card('a', { clicks: 200, spend: 300 }), card('b', { clicks: 100, spend: 100 }), card('c', { clicks: 60, spend: 100 })])
    const a = rows.find((r) => r.id === 'a')!
    expect(a.groupAvg).toBeCloseTo((0.02 + 0.01 + 0.006) / 3, 6)
    expect(a.gap).toBeCloseTo(0.02 / ((0.02 + 0.01 + 0.006) / 3) - 1, 6)
    expect(a.share).toBeCloseTo(300 / 500, 6)
    expect(a.verdict).toBe('above')
    expect(rows.find((r) => r.id === 'c')!.verdict).toBe('below')
  })

  it('never compares across platforms or objectives, and needs three fair items', () => {
    const rows = contentWhy([card('a', {}), card('b', {}), card('c', { provider: 'tiktok' }), card('d', { objective: 'leads' })])
    expect(rows).toEqual([])
  })

  it('leaves out an item under a thousand impressions rather than guessing its rate', () => {
    const rows = contentWhy([card('a', { imp: 999 }), card('b', {}), card('c', {}), card('d', {})])
    expect(rows.map((r) => r.id).sort()).toEqual(['b', 'c', 'd'])
  })

  it('carries frequency and the click-rate change the server reported — null when it reported none', () => {
    const rows = contentWhy([card('a', { freq: 3.2, ctrChange: -0.25 }), card('b', {}), card('c', {})])
    expect(rows.find((r) => r.id === 'a')).toMatchObject({ frequency: 3.2, ctrChange: -0.25 })
    expect(rows.find((r) => r.id === 'b')).toMatchObject({ frequency: null, ctrChange: null })
  })

  it('picks the best two and the weakest one per group', () => {
    const rows = contentWhy([card('a', { clicks: 300 }), card('b', { clicks: 200 }), card('c', { clicks: 100 }), card('d', { clicks: 50 })])
    const r = bestAndWeakest(rows)
    expect(r.best.map((x) => x.id)).toEqual(['a', 'b'])
    expect(r.weakest.map((x) => x.id)).toEqual(['d'])
  })
})

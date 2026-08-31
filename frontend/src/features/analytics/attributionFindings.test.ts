import { describe, expect, it } from 'vitest'
import { attributionFindings } from './attributionFindings'
import type { Attribution, PlatformClaim } from './api'

/**
 * DATA-QUALITY-OPERATOR-UX-001 · CROSS-PLATFORM-ATTRIBUTION-DEPTH-001 — the attribution half, read
 * for the person who has to act.
 *
 * The distinction these findings exist to hold: a window that DIFFERS between platforms and a window
 * that is UNKNOWN are not the same problem. The first means the totals were collected under
 * different rules and should not be added; the second means nobody can say whether they were.
 * Merging them into «attribution issue» is what makes a reader stop reading them.
 */
const basis = (over: Partial<PlatformClaim['attribution']> = {}) => ({
  windows: [{ window: '7d_click', rows: 10 }],
  mixed_windows: false,
  window_known: true,
  click_through_days: 7,
  view_through_days: null,
  includes_view_through: false,
  unknown_ar: null,
  unknown_en: null,
  ...over,
})

const claim = (provider: string, attribution = basis()): PlatformClaim => ({
  provider,
  platform_reported_orders: 40,
  platform_reported_revenue: 8000,
  store_confirmed_orders: 30,
  store_confirmed_revenue: 6000,
  difference: 10,
  ratio: 1.33,
  attribution,
  currency: 'SAR',
})

const payload = (platforms: PlatformClaim[], unattributed?: Partial<Attribution['unattributed']>): Attribution => ({
  period: { from: '2026-08-01', to: '2026-08-30' },
  platform_reported: { platforms } as never,
  store_confirmed: {} as never,
  overlap: {} as never,
  dedup: {} as never,
  models: [] as never,
  unattributed: { available: false, orders: null, revenue: null, share: null, by_method: [], ...unattributed } as never,
} as never)

describe('the attribution findings', () => {
  it('separates a window that differs from a window nobody stated', () => {
    const findings = attributionFindings(payload([
      claim('meta', basis({ click_through_days: 7 })),
      claim('snapchat', basis({ click_through_days: 1 })),
      claim('x', basis({ window_known: false, click_through_days: null, includes_view_through: null })),
    ]))

    const keys = findings.map((f) => f.key)
    expect(keys).toContain('click-windows-differ')
    expect(keys).toContain('unknown-window-x')

    // The differing-windows finding is about the SET, not repeated per platform.
    expect(keys.filter((k) => k === 'click-windows-differ')).toHaveLength(1)
  })

  /** One platform whose own figures mix two windows is a different sentence again. */
  it('names a platform whose own figures were collected under two rules', () => {
    const [finding] = attributionFindings(payload([
      claim('meta', basis({ mixed_windows: true, windows: [{ window: '7d_click', rows: 6 }, { window: '1d_click', rows: 4 }] })),
    ]))

    expect(finding?.key).toBe('mixed-windows-meta')
    expect(finding?.what.en).toMatch(/more than one attribution window/)
    expect(finding?.what.en).toMatch(/7d_click/)
  })

  it('says nothing when every platform agrees and states its window', () => {
    expect(attributionFindings(payload([claim('meta'), claim('snapchat')]))).toEqual([])
  })

  /**
   * View-through counted by one platform and not another is a difference in DEFINITION, and saying
   * so is the whole point: otherwise the platform that counts views looks like the better buy.
   */
  it('separates counting a view from counting a click', () => {
    const findings = attributionFindings(payload([
      claim('meta', basis({ includes_view_through: true, view_through_days: 1 })),
      claim('snapchat', basis({ includes_view_through: false })),
    ]))

    expect(findings.map((f) => f.key)).toContain('view-through-differs')
    expect(findings.find((f) => f.key === 'view-through-differs')?.affects.en).toMatch(/definition, not in performance/)
  })

  /** Orders nobody claimed are normal, and are reported as such rather than as a fault. */
  it('reports unclaimed orders at watch, and says they are ordinary', () => {
    const findings = attributionFindings(payload(
      [claim('meta')],
      { available: true, orders: 12, share: 0.2 },
    ))

    const unclaimed = findings.find((f) => f.key === 'unattributed-orders')
    expect(unclaimed?.severity).toBe('watch')
    expect(unclaimed?.owner).toBe('nobody')
    expect(unclaimed?.what.en).toMatch(/12 orders/)
    expect(unclaimed?.what.en).toMatch(/20%/)
  })

  it('says nothing at all without a payload', () => {
    expect(attributionFindings(undefined)).toEqual([])
  })
})

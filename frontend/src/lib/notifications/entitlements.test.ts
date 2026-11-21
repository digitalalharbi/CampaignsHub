import { describe, expect, it } from 'vitest'
import { ENTITLEMENT_DEFAULTS, ENTITLEMENT_KEYS, isLocked, showsUpgrade } from './entitlements'
import type { EntitlementState } from './types'

const state = (over: Partial<EntitlementState>): EntitlementState => ({
  key: 'notifications.external',
  mode: 'full',
  allowed: true,
  can_edit: true,
  upgrade: false,
  grace_ends_at: null,
  limits: {},
  ...over,
})

describe('notification entitlements', () => {
  it('lists the eleven keys of the spec plus the trash retention, every one on except the footprint removal', () => {
    expect(ENTITLEMENT_KEYS).toHaveLength(12)
    const off = ENTITLEMENT_KEYS.filter((k) => !ENTITLEMENT_DEFAULTS[k])
    expect(off).toEqual(['branding.remove_platform_footprint'])
  })

  it('locks a view-only key and offers the upgrade, and leaves a full key alone', () => {
    expect(isLocked(state({ mode: 'view_only', allowed: false, can_edit: false, upgrade: true }))).toBe(true)
    expect(showsUpgrade(state({ mode: 'grace', upgrade: true }))).toBe(true)
    expect(isLocked(state({}))).toBe(false)
    expect(isLocked(undefined)).toBe(false)
  })
})

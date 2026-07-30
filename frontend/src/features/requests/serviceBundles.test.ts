import { describe, expect, it } from 'vitest'
import { SERVICE_BUNDLES, bundleForSelection, findBundle } from './serviceBundles'

/**
 * Pure-logic guarantees for the goal-first bundles. That every service key really exists in the seeded
 * catalogue is asserted on the backend (ServiceBundleCatalogTest), next to the seeder that defines them —
 * a typo there would silently select nothing and let a client submit an empty request.
 */
describe('service bundles', () => {
  it('gives every bundle a distinct key and a non-trivial set of services', () => {
    const keys = SERVICE_BUNDLES.map((b) => b.key)
    expect(new Set(keys).size).toBe(keys.length)

    for (const b of SERVICE_BUNDLES) {
      expect(b.services.length).toBeGreaterThanOrEqual(3)
      expect(new Set(b.services).size, `${b.key} repeats a service`).toBe(b.services.length)
      expect(b.titleAr.trim()).not.toBe('')
      expect(b.forAr.trim()).not.toBe('')
      expect(b.titleEn.trim()).not.toBe('')
      expect(b.forEn.trim()).not.toBe('')
    }
  })

  it('recognises a selection that came from a bundle, in any order', () => {
    const bundle = SERVICE_BUNDLES[0]
    expect(bundleForSelection([...bundle.services].reverse())?.key).toBe(bundle.key)
  })

  it('does not claim a bundle for a hand-picked or partial selection', () => {
    const bundle = SERVICE_BUNDLES[0]
    expect(bundleForSelection([])).toBeUndefined()
    expect(bundleForSelection(bundle.services.slice(0, 2))).toBeUndefined()
    expect(bundleForSelection([...bundle.services, 'gtm'])).toBeUndefined()
  })

  it('looks a bundle up by key', () => {
    expect(findBundle('launch')?.services).toContain('new_campaign')
    expect(findBundle('nope')).toBeUndefined()
  })
})

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { SERVICE_BUNDLES, bundleForSelection, findBundle } from './serviceBundles'

/**
 * A bundle is only useful if every key it names really exists in the seeded catalogue — a typo would
 * silently select nothing and the client would submit an empty request believing they had chosen.
 */
const seeder = readFileSync(
  resolve(__dirname, '../../../../backend/database/seeders/TaxonomyEngineSeeder.php'),
  'utf8',
)
const catalogueKeys = new Set([...seeder.matchAll(/'key' => '([a-z_]+)'/g)].map((m) => m[1]))

describe('service bundles', () => {
  it('references only services that exist in the seeded catalogue', () => {
    for (const bundle of SERVICE_BUNDLES) {
      for (const key of bundle.services) {
        expect(catalogueKeys.has(key), `${bundle.key} → ${key}`).toBe(true)
      }
    }
  })

  it('gives every bundle a distinct key and a non-trivial set of services', () => {
    const keys = SERVICE_BUNDLES.map((b) => b.key)
    expect(new Set(keys).size).toBe(keys.length)
    for (const b of SERVICE_BUNDLES) {
      expect(b.services.length).toBeGreaterThanOrEqual(3)
      expect(new Set(b.services).size).toBe(b.services.length)
      expect(b.titleAr.trim()).not.toBe('')
      expect(b.forAr.trim()).not.toBe('')
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

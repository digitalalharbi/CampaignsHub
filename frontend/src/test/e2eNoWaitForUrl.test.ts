import { describe, expect, it } from 'vitest'

/**
 * GATE-WK-NAV-001 — an e2e spec never WAITS for a navigation; it asserts where the page is.
 *
 * `page.waitForURL` subscribes to a navigation. When the page's own code replaces or redirects that navigation, WebKit
 * rejects the wait with «Navigation canceled by policy check» although the page lands exactly where the test expects.
 * It failed `workspace-two-portals` on #963, #967 and again in the 2026-11-08 gate, each time fixed in one call site
 * and left in the other thirteen. `expect(page).toHaveURL` polls the URL, which is the claim the test is making.
 */
const specs = import.meta.glob('../../e2e/*.spec.ts', { query: '?raw', import: 'default', eager: true }) as Record<string, string>

describe('e2e navigation waits', () => {
  it('finds the specs it is guarding', () => {
    expect(Object.keys(specs).length).toBeGreaterThan(50)
  })

  it('uses no waitForURL call', () => {
    const offenders = Object.entries(specs)
      .filter(([, source]) => source.split('\n').some((l) => !l.trim().startsWith('//') && !l.trim().startsWith('*') && /\.waitForURL\(/.test(l)))
      .map(([file]) => file)

    expect(offenders, 'use `await expect(page).toHaveURL(...)` instead of `page.waitForURL(...)`').toEqual([])
  })
})

import { describe, expect, it } from 'vitest'
import { ALL_POLICY_KEYS, POLICY_CONTEXTS, policyLink, policyLinks, type PolicyKey } from './policyLinks'
import { HOME_COPY } from '@/features/marketing/homeCopy'

/**
 * POLICY-PLACEMENT-001 — the policies MOVED; none of them vanished.
 *
 * The whole risk of this unit is quiet loss: a legal page that still exists, still has a route, and
 * is no longer linked from anywhere a person will look. These tests are the ledger for that.
 */

describe('policy placement', () => {
  /**
   * The claim, plainly: every policy this product publishes is offered somewhere in the product.
   *
   * A page with a route and no link is a page that is only reachable by typing its URL, which for a
   * legal disclosure is the same as not publishing it.
   */
  it('offers every policy in at least one context', () => {
    const placed = new Set<PolicyKey>(Object.values(POLICY_CONTEXTS).flat() as PolicyKey[])
    const orphaned = ALL_POLICY_KEYS.filter((k) => !placed.has(k))

    expect(orphaned, `these policies are reachable only by typing their URL: ${orphaned.join(', ')}`).toEqual([])
  })

  /**
   * The nine that were moved OFF the public footer are really off it — and really somewhere else.
   *
   * Named one by one rather than counted, because «the footer got shorter» is also what deleting
   * them would look like.
   */
  const MOVED: PolicyKey[] = [
    'account-deletion', 'data-requests', 'retention', 'subprocessors', 'acceptable-use',
    'subscriptions-refunds', 'oauth-disclosure', 'system-status',
  ]

  it.each(MOVED)('%s is off the public footer and inside the product', (key) => {
    expect(POLICY_CONTEXTS.public).not.toContain(key)

    const contexts = Object.entries(POLICY_CONTEXTS)
      .filter(([name]) => name !== 'public')
      .filter(([, keys]) => (keys as readonly PolicyKey[]).includes(key))

    expect(contexts.length, `${key} was removed from the footer and given no home`).toBeGreaterThan(0)
  })

  /** The public footer keeps exactly what a visitor without an account can act on. */
  it('leaves the public footer with the visitor-facing policies only', () => {
    expect([...POLICY_CONTEXTS.public]).toEqual(['privacy', 'terms', 'data-processing', 'cookies', 'security'])
  })

  /**
   * The footer copy itself no longer names them.
   *
   * The registry could be right while the marketing page still rendered its own hardcoded list, so
   * this reads the copy the footer actually maps over — in both languages.
   */
  it.each(['ar', 'en'] as const)('the %s public footer no longer links the moved policies', (locale) => {
    const routes = HOME_COPY[locale].footer.groups.flatMap((g) => g.links.map((l) => l.to))

    for (const key of MOVED) {
      expect(routes, `the ${locale} footer still links /${key}`).not.toContain(`/${key}`)
    }

    // …and still links the ones a visitor needs.
    for (const key of POLICY_CONTEXTS.public) {
      expect(routes, `the ${locale} footer lost /${key}`).toContain(`/${key}`)
    }
  })

  /** Every link points at a page the router actually serves — no invented paths. */
  it('points every context at a real public policy route', () => {
    // The slug list in `router.tsx`, mirrored: a link to a slug the router does not know renders
    // the not-found state, which is a dead link with extra steps.
    const ROUTED = [
      'privacy', 'terms', 'data-processing', 'cookies', 'security', 'about', 'contact', 'support', 'faq',
      'retention', 'subprocessors', 'account-deletion', 'data-requests', 'acceptable-use',
      'subscriptions-refunds', 'oauth-disclosure', 'system-status',
    ]

    for (const key of ALL_POLICY_KEYS) {
      expect(ROUTED, `/${key} has no route`).toContain(policyLink(key, 'en').to.replace('/', ''))
    }
  })

  it('labels every link in the reader’s language', () => {
    const ar = policyLinks('account', 'ar').map((l) => l.label)
    const en = policyLinks('account', 'en').map((l) => l.label)

    expect(ar).toContain('حذف الحساب والبيانات')
    expect(en).toContain('Account & data deletion')
    // No key ever leaks through as a label.
    expect([...ar, ...en].some((l) => l.includes('-'))).toBe(false)
  })
})

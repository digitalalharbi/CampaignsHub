import { describe, expect, it } from 'vitest'
import { HOME_COPY } from './homeCopy'
import { LEGAL_DOCS, findLegalDoc } from './legalContent'
import { POLICY_CONTEXTS } from '@/features/legal/policyLinks'

/**
 * LEGAL-001 — every footer link goes somewhere real, in both languages.
 *
 * The failure this guards against is the one the brief singles out: a footer full of links that
 * render a not-found page or an empty shell. It is easy to introduce — a link is one line of copy and
 * the page it names is somewhere else entirely — and it is invisible until somebody clicks.
 *
 * So the check is structural rather than a spot check of a few slugs: every `to` in every footer
 * column either resolves to a published document or is one of the small set of application routes the
 * marketing pages legitimately point at.
 */

/** Application routes the footer may link to that are not policy documents. */
const APP_ROUTES = ['/register', '/login', '/requests/new', '/requests/track']

describe('the public footer', () => {
  for (const locale of ['ar', 'en'] as const) {
    describe(locale, () => {
      const copy = HOME_COPY[locale]

      it('links only to destinations that exist', () => {
        const targets = copy.footer.groups.flatMap((g) => g.links.map((l) => l.to))
        expect(targets.length).toBeGreaterThan(0)

        for (const to of targets) {
          if (APP_ROUTES.includes(to)) continue
          const slug = to.replace(/^\//, '')
          expect(
            findLegalDoc(locale, slug),
            `footer link ${to} (${locale}) has no page behind it`,
          ).toBeDefined()
        }
      })

      it('names every group and every link', () => {
        for (const group of copy.footer.groups) {
          expect(group.title.trim()).not.toBe('')
          expect(group.links.length).toBeGreaterThan(0)
          for (const link of group.links) {
            expect(link.label.trim(), `an empty label in ${group.title}`).not.toBe('')
          }
        }
      })

      /**
       * The pages the brief requires — each reachable, and each from the right place.
       *
       * This assertion used to demand that all seventeen hang off the public footer. That was the
       * placement rule at the time; POLICY-PLACEMENT-001 changed it, because «حذف الحساب والبيانات»
       * and «الاشتراكات والاسترداد» in front of a visitor with no account is not disclosure, it is
       * clutter — and it left the person who needs them, who is signed in looking at their account
       * or their invoice, with nowhere to find them.
       *
       * What the assertion protects is unchanged and is the part that matters: **no required page
       * becomes unreachable.** The visitor-facing ones are checked on the footer; the rest are
       * checked against the in-product contexts that now carry them.
       */
      it('offers every page the brief requires, from the place that suits it', () => {
        const linked = new Set(copy.footer.groups.flatMap((g) => g.links.map((l) => l.to.replace(/^\//, ''))))

        // What a visitor without an account can act on stays on the public footer.
        for (const required of [
          'privacy', 'terms', 'data-processing', 'cookies', 'security',
          'about', 'contact', 'support', 'faq',
        ]) {
          expect(linked.has(required), `${required} is not linked from the ${locale} footer`).toBe(true)
        }

        // The operational ones moved INTO the product — and none of them was merely dropped.
        const inProduct = new Set(
          Object.entries(POLICY_CONTEXTS)
            .filter(([name]) => name !== 'public')
            .flatMap(([, keys]) => keys as readonly string[]),
        )

        for (const moved of [
          'account-deletion', 'data-requests', 'retention', 'subprocessors',
          'acceptable-use', 'subscriptions-refunds', 'oauth-disclosure', 'system-status',
        ]) {
          expect(linked.has(moved), `${moved} is still on the ${locale} public footer`).toBe(false)
          expect(inProduct.has(moved), `${moved} left the footer and landed nowhere`).toBe(true)
        }
      })
    })
  }
})

describe('the public documents', () => {
  for (const locale of ['ar', 'en'] as const) {
    it(`every ${locale} document has a title, an intro and at least one section`, () => {
      for (const doc of LEGAL_DOCS[locale]) {
        expect(doc.title.trim(), `${doc.slug} has no title`).not.toBe('')
        expect(doc.intro.trim(), `${doc.slug} has no intro`).not.toBe('')
        expect(doc.sections.length, `${doc.slug} has no sections`).toBeGreaterThan(0)

        for (const section of doc.sections) {
          expect(section.heading.trim(), `${doc.slug} has an unnamed section`).not.toBe('')
          const hasContent = (section.body?.length ?? 0) > 0 || (section.bullets?.length ?? 0) > 0
          expect(hasContent, `${doc.slug} › ${section.heading} is empty`).toBe(true)
        }
      }
    })
  }

  /** Arabic and English publish the same set — a page missing in one language is a dead link in it. */
  it('publishes the same documents in both languages', () => {
    const ar = LEGAL_DOCS.ar.map((d) => d.slug).sort()
    const en = LEGAL_DOCS.en.map((d) => d.slug).sort()
    expect(ar).toEqual(en)
  })

  /**
   * No placeholder text anywhere.
   *
   * The brief forbids it explicitly, and it is exactly what survives a rushed content pass — one
   * «Lorem ipsum» or «TODO» in a policy is worse than the missing page it replaced.
   */
  it('contains no placeholder or filler text', () => {
    const forbidden = /lorem ipsum|placeholder|TBD|TODO|coming soon\.\.\.|نص تجريبي|قريبًا\.\.\./i

    for (const locale of ['ar', 'en'] as const) {
      for (const doc of LEGAL_DOCS[locale]) {
        const all = [doc.title, doc.intro, ...doc.sections.flatMap((s) => [s.heading, ...(s.body ?? []), ...(s.bullets ?? [])])]
        for (const text of all) {
          expect(forbidden.test(text), `${locale}/${doc.slug} contains placeholder text: ${text.slice(0, 60)}`).toBe(false)
        }
      }
    }
  })

  /**
   * No claim we cannot stand behind.
   *
   * A policy asserting a certification the operator does not hold is a written commitment the product
   * fails the first time anyone checks — and these pages are read by platform reviewers, not only by
   * customers.
   */
  it('claims no certification the operator does not hold', () => {
    const unsupported = /ISO\s?27001|SOC\s?2|PCI[- ]?DSS|HIPAA|bank[- ]grade|military[- ]grade|معتمد من الآيزو/i

    for (const locale of ['ar', 'en'] as const) {
      for (const doc of LEGAL_DOCS[locale]) {
        const all = [doc.intro, ...doc.sections.flatMap((s) => [...(s.body ?? []), ...(s.bullets ?? [])])]
        for (const text of all) {
          expect(unsupported.test(text), `${locale}/${doc.slug} claims: ${text.slice(0, 80)}`).toBe(false)
        }
      }
    }
  })

  /** Policy documents carry the not-legal-advice note; company pages do not need it. */
  it('marks every policy document as not being legal advice', () => {
    const policies = [
      'privacy', 'terms', 'data-processing', 'cookies', 'security', 'retention',
      'subprocessors', 'account-deletion', 'data-requests', 'acceptable-use',
      'subscriptions-refunds', 'oauth-disclosure',
    ]

    for (const locale of ['ar', 'en'] as const) {
      for (const slug of policies) {
        expect(findLegalDoc(locale, slug)?.disclaimer, `${locale}/${slug} has no disclaimer`).toBeTruthy()
      }
    }
  })
})

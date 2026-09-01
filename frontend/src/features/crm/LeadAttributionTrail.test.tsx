import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { LeadAttributionTrail } from './LeadAttributionTrail'
import type { LeadAttribution } from './types'
import { renderWithProviders } from '@/test/utils'

/**
 * LEAD-SOURCE-ATTRIBUTION-001 — the four states must not collapse into one dash.
 *
 * A client looking at a lead asks one question: which ad produced this person. The answer often has
 * a hole in it, and the product is only trustworthy if the READER can tell which kind of hole:
 *
 *   «LinkedIn has no ad sets» is a fact about LinkedIn and needs no action;
 *   «Meta sends the ad set and this lead has not got one» is our sync dropping data;
 *   «nobody paid for this lead» is neither.
 *
 * Rendering all three as «—» answers none of them, and is the version of this screen that quietly
 * hides a month of broken ingestion.
 */
const chain = (over: Partial<LeadAttribution> = {}): LeadAttribution => ({
  route: 'native_form',
  route_label: 'نموذج على المنصة',
  route_label_en: 'Native form',
  platform: { state: 'named', provider: 'meta', label: 'ميتا', label_en: 'Meta' },
  rungs: [
    { rung: 'creative', state: 'named', id: 'cr-1', name: 'Villa 3BR', reason: null, reason_en: null },
    { rung: 'ad', state: 'named', id: 'ad-1', name: 'Villa carousel', reason: null, reason_en: null },
    { rung: 'adset', state: 'named', id: 'set-1', name: 'Riyadh 25-45', reason: null, reason_en: null },
    { rung: 'campaign', state: 'named', id: 'cmp-1', name: 'Riyadh — Q3', reason: null, reason_en: null },
  ],
  complete: true,
  web: {},
  ...over,
})

describe('the chain behind one lead', () => {
  it('names each rung the platform supplied, with the id beside the name', () => {
    renderWithProviders(<LeadAttributionTrail attribution={chain()} locale="ar" />)

    expect(screen.getByTestId('lead-attribution-platform')).toHaveTextContent('ميتا')
    expect(screen.getByTestId('lead-rung-campaign')).toHaveTextContent('Riyadh — Q3')
    expect(screen.getByTestId('lead-rung-campaign')).toHaveTextContent('cmp-1')
    expect(screen.getByTestId('lead-rung-creative')).toHaveAttribute('data-state', 'named')
  })

  /** A platform limit is stated in the platform's own terms, and is never marked as a gap. */
  it('explains a rung the platform does not have', () => {
    renderWithProviders(
      <LeadAttributionTrail
        attribution={chain({
          platform: { state: 'named', provider: 'linkedin', label: 'لينكدإن', label_en: 'LinkedIn' },
          rungs: [
            { rung: 'creative', state: 'named', id: 'cr-9', name: 'Whitepaper', reason: null, reason_en: null },
            { rung: 'ad', state: 'not_offered', id: null, name: null, reason: 'المحتوى نفسه هو الإعلان في لينكدإن.', reason_en: 'In LinkedIn the creative IS the ad.' },
            { rung: 'adset', state: 'not_offered', id: null, name: null, reason: 'لا تملك لينكدإن مستوى مجموعات إعلانية.', reason_en: 'LinkedIn has no ad-set level.' },
            { rung: 'campaign', state: 'named', id: 'cmp-9', name: 'Enterprise Q3', reason: null, reason_en: null },
          ],
        })}
        locale="ar"
      />,
    )

    expect(screen.getByTestId('lead-rung-adset')).toHaveTextContent('لا تملك لينكدإن مستوى مجموعات إعلانية.')
    expect(screen.queryByTestId('lead-rung-gap-adset')).not.toBeInTheDocument()
  })

  /** The opposite case, and the one somebody has to act on. */
  it('marks a rung the platform does send and this lead lacks', () => {
    renderWithProviders(
      <LeadAttributionTrail
        attribution={chain({
          complete: false,
          rungs: [
            { rung: 'creative', state: 'missing', id: null, name: null, reason: null, reason_en: null },
            { rung: 'ad', state: 'missing', id: null, name: null, reason: null, reason_en: null },
            { rung: 'adset', state: 'named', id: 'set-1', name: 'Riyadh 25-45', reason: null, reason_en: null },
            { rung: 'campaign', state: 'named', id: 'cmp-1', name: 'Riyadh — Q3', reason: null, reason_en: null },
          ],
        })}
        locale="ar"
      />,
    )

    expect(screen.getByTestId('lead-rung-gap-creative')).toBeInTheDocument()
    expect(screen.getByTestId('lead-rung-ad')).toHaveAttribute('data-state', 'missing')

    // And a gap must never read like a platform limit — different words, different meaning.
    expect(screen.getByTestId('lead-rung-creative')).toHaveTextContent(/لم يصل/)
  })

  it('says plainly when no ad platform is behind the lead, and shows what the link carried', () => {
    renderWithProviders(
      <LeadAttributionTrail
        attribution={chain({
          route: 'website_form',
          route_label: 'نموذج على الموقع',
          route_label_en: 'Website form',
          platform: { state: 'no_platform', provider: null, label: null, label_en: null },
          rungs: [
            { rung: 'creative', state: 'no_platform', id: null, name: null, reason: null, reason_en: null },
            { rung: 'ad', state: 'no_platform', id: null, name: null, reason: null, reason_en: null },
            { rung: 'adset', state: 'no_platform', id: null, name: null, reason: null, reason_en: null },
            { rung: 'campaign', state: 'no_platform', id: null, name: null, reason: null, reason_en: null },
          ],
          web: { utm_source: 'newsletter', landing_page: 'https://example.test/villas' },
        })}
        locale="ar"
      />,
    )

    expect(screen.getByTestId('lead-attribution-route')).toHaveTextContent('نموذج على الموقع')
    expect(screen.queryByTestId('lead-attribution-platform')).not.toBeInTheDocument()
    expect(screen.getByTestId('lead-attribution-web')).toHaveTextContent('newsletter')
    expect(screen.getByTestId('lead-rung-campaign')).toHaveTextContent(/لا توجد منصة/)
  })

  /**
   * The English reader gets the same answer, not a blank.
   *
   * The reasons are authored in Arabic first. If only those reached the screen, switching to English
   * would turn every explained rung back into an unexplained one — the exact failure this component
   * was built to remove, reintroduced by the language toggle.
   */
  it('answers in English when the reader is reading English', () => {
    renderWithProviders(
      <LeadAttributionTrail
        attribution={chain({
          platform: { state: 'named', provider: 'linkedin', label: 'لينكدإن', label_en: 'LinkedIn' },
          rungs: [
            { rung: 'creative', state: 'named', id: 'cr-9', name: 'Whitepaper', reason: null, reason_en: null },
            { rung: 'ad', state: 'named', id: 'cr-9', name: 'Whitepaper', reason: null, reason_en: null },
            {
              rung: 'adset',
              state: 'not_offered',
              id: null,
              name: null,
              reason: 'لا تملك لينكدإن مستوى مجموعات إعلانية.',
              reason_en: 'LinkedIn has no separate ad-set level.',
            },
            { rung: 'campaign', state: 'named', id: 'cmp-9', name: 'Enterprise Q3', reason: null, reason_en: null },
          ],
        })}
        locale="en"
      />,
    )

    expect(screen.getByTestId('lead-attribution-platform')).toHaveTextContent('LinkedIn')
    expect(screen.getByTestId('lead-attribution-route')).toHaveTextContent('Native form')
    expect(screen.getByTestId('lead-rung-adset')).toHaveTextContent('LinkedIn has no separate ad-set level.')
  })

  it('flags a platform this product has never modelled rather than vouching for it', () => {
    renderWithProviders(
      <LeadAttributionTrail
        attribution={chain({ platform: { state: 'unrecognised', provider: 'pinterest', label: 'pinterest', label_en: 'pinterest' } })}
        locale="ar"
      />,
    )

    expect(screen.getByTestId('lead-attribution-unrecognised')).toBeInTheDocument()
  })
})

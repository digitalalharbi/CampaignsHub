import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { renderWithProviders } from '@/test/utils'
import { brand } from '@/lib/brand'
import { CampaignsHubLogo } from './CampaignsHubLogo'
import { CampaignsHubBrandFooter } from './CampaignsHubBrandFooter'
// Imported as text rather than read from disk: this suite is type-checked and run as browser code.
import faviconSource from '../../../public/favicon.svg?raw'

/**
 * BRAND-MARK-001 / BRAND-LOCKUP-001 — one identity, from one source.
 *
 * The identity document exists because a logo that is re-drawn per surface stops being one logo:
 * «logo-green.svg، logo-white.svg، logo-ar.png» is the failure it names. These cases pin the rules
 * that keep that from happening again — the geometry has one home, the lockup words are not the
 * product's tagline, and CampaignsHub credits itself without taking a client's report from them.
 */
describe('the CampaignsHub identity', () => {
  it('draws the approved geometry, and the gold dot that never changes', () => {
    renderWithProviders(<CampaignsHubLogo variant="mark" />, { locale: 'en' })

    const svg = screen.getByTestId('campaignshub-logo').querySelector('svg')!
    const paths = [...svg.querySelectorAll('path')].map((p) => p.getAttribute('d'))

    // Three strokes converging on one point — the identity's own description.
    expect(paths).toEqual([
      'M8 14 H24 C34 14 34 32 42 32',
      'M8 32 H42',
      'M8 50 H24 C34 50 34 32 42 32',
    ])
    expect(svg.getAttribute('viewBox')).toBe('0 0 64 64')
    // The body takes its colour from the container, which is what lets ONE file serve both themes.
    expect(svg.querySelector('g')?.getAttribute('stroke')).toBe('currentColor')
    // The dot does not.
    expect(svg.querySelector('circle')?.getAttribute('fill')).toContain('--brand-gold')
  })

  /**
   * The favicon cannot inherit a colour, so it is the one place the geometry is duplicated. If the
   * two ever disagree, a browser tab shows a different logo from the product — which is precisely
   * the state this repository was in, with a purple mark in `favicon.svg`.
   */
  it('keeps the favicon on the same geometry as the component', () => {
    const favicon = faviconSource

    for (const d of ['M8 14 H24 C34 14 34 32 42 32', 'M8 32 H42', 'M8 50 H24 C34 50 34 32 42 32']) {
      expect(favicon, 'the favicon has drifted from the mark').toContain(d)
    }
    expect(favicon).toContain('#e8a33d')
    // And never the mark that was shipped by mistake.
    expect(favicon.toLowerCase()).not.toContain('863bff')
  })

  it('says the lockup line, which is not the product tagline', () => {
    renderWithProviders(<CampaignsHubLogo variant="full" locale="en" />, { locale: 'en' })

    expect(screen.getByTestId('brand-lockup-line')).toHaveTextContent('PAID MEDIA IN ONE PLACE')
    // The product sentence belongs to the product, not to the logo. Collapsing the two would put
    // one of them somewhere it is wrong.
    expect(screen.queryByText(brand.tagline)).not.toBeInTheDocument()
    expect(brand.lockup.lineEn).not.toBe(brand.tagline)
    expect(brand.lockup.lineAr).not.toBe(brand.taglineAr)
  })

  it('reads as the Arabic lockup in Arabic', () => {
    renderWithProviders(<CampaignsHubLogo variant="full" locale="ar" />, { locale: 'ar' })

    expect(screen.getByTestId('brand-lockup-line')).toHaveTextContent('منصة إدارة الحملات المدفوعة')
    expect(screen.getByTestId('campaignshub-logo')).toHaveTextContent('كامبينز')
  })

  it('credits CampaignsHub on a client report without advertising at it', () => {
    renderWithProviders(<CampaignsHubBrandFooter locale="en" year={2026} />, { locale: 'en' })

    expect(screen.getByTestId('powered-by-campaignshub')).toHaveAttribute(
      'href',
      `https://${brand.domain}`,
    )
    expect(screen.getByTestId('campaignshub-brand-footer')).toHaveTextContent('All rights reserved')
    // The CTA is opt-in: a printed client report is not a place to sell them the tool.
    expect(screen.queryByTestId('campaignshub-cta')).not.toBeInTheDocument()
  })

  it('offers the invitation only where it was asked for', () => {
    renderWithProviders(<CampaignsHubBrandFooter locale="ar" year={2026} cta />, { locale: 'ar' })

    expect(screen.getByTestId('campaignshub-cta')).toHaveTextContent('أنشئ تقاريرك عبر')
    expect(screen.getByTestId('campaignshub-cta')).toHaveAttribute('target', '_blank')
  })

  /**
   * A snapshot is a record of a period. Re-opening one next year must not restamp its copyright as
   * current, so the year is given to this component rather than read from the clock inside it.
   */
  it('prints the year it was given, not today', () => {
    renderWithProviders(<CampaignsHubBrandFooter locale="en" year={2024} />, { locale: 'en' })

    expect(screen.getByTestId('campaignshub-brand-footer')).toHaveTextContent('© 2024')
  })
})

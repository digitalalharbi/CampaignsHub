import { describe, expect, it } from 'vitest'
import { screen, waitFor } from '@testing-library/react'

import { renderWithProviders } from '@/test/utils'
import { brand, productName } from '@/lib/brand'

/**
 * BRAND-MARK-001 — the tab follows the language the page is being read in.
 *
 * ## Two faults of one shape
 *
 * `PublicPageShell` interpolated `productName(locale)` into the title inside an effect whose
 * dependency list was `[title]`. It ran once. A reader switching to English got an English page
 * under an Arabic tab and no way to tell why — and the code read as though it handled the case,
 * which is what kept it invisible.
 *
 * The HOMEPAGE set no title at all, so it kept whatever `index.html` shipped: the Arabic tagline and
 * the Latin product name, on an English page, for as long as the reader stayed there.
 *
 * ## What this asserts
 *
 * The rendered document title, after a locale change — not the expression that builds it. The
 * expression was correct in both places; what was wrong was when it ran.
 */
describe('the public tab follows the reader’s language', () => {
  it('names the homepage in Arabic, with the Arabic product name', async () => {
    const { PublicHomePage } = await import('./PublicHomePage')
    renderWithProviders(<PublicHomePage />, { locale: 'ar', route: '/' })

    await waitFor(() => expect(document.title).toContain(productName('ar')))
    expect(document.title).toContain(brand.taglineAr)
    expect(document.title).not.toContain(brand.tagline)
  })

  it('renames it when the reader switches to English', async () => {
    const { PublicHomePage } = await import('./PublicHomePage')
    renderWithProviders(<PublicHomePage />, { locale: 'en', route: '/' })

    await waitFor(() => expect(document.title).toContain(productName('en')))
    expect(document.title).toContain(brand.tagline)
    expect(document.title).not.toContain(brand.taglineAr)
  })

  /**
   * The identity itself, which the owner fixed the spelling of twice: «كامبينز هب» with no fatha in
   * Arabic, «CampaignsHub» in English, and never one standing in for the other.
   */
  it('uses the approved spelling of the product’s name in each language', () => {
    expect(productName('ar')).toBe('كامبينز هب')
    expect(productName('en')).toBe('CampaignsHub')
    expect(productName('ar')).not.toContain('َ') // the fatha the owner ruled out
  })

  it('renders something, so the assertions above are about a real page', async () => {
    const { PublicHomePage } = await import('./PublicHomePage')
    renderWithProviders(<PublicHomePage />, { locale: 'ar', route: '/' })

    await waitFor(() => expect(screen.getAllByRole('link').length).toBeGreaterThan(0))
  })
})

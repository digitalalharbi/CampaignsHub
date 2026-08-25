import { describe, expect, it } from 'vitest'
import { screen, within } from '@testing-library/react'
import { PublicHomePage } from './PublicHomePage'
import { renderWithProviders, signOut } from '@/test/utils'

/**
 * MKT-CONTACT-001 — the public site says how to reach a person, and says it correctly.
 *
 * The failure this guards against is specific and has happened elsewhere in this product: an address
 * assembled from the site URL, producing `info@https://campaignshub.io/` — a string that looks like
 * contact details and reaches nobody. So the assertions are on the exact `href` values, not on the
 * visible text, because the visible text was never the part that broke.
 */
describe('the public contact footer', () => {
  it('offers a real mailto and a real tel', () => {
    signOut()
    renderWithProviders(<PublicHomePage />, { route: '/', locale: 'ar' })

    const email = screen.getByTestId('footer-email')
    const phone = screen.getByTestId('footer-phone')

    expect(email).toHaveAttribute('href', 'mailto:info@campaignshub.io')
    // Unbroken E.164: some dialers refuse a `tel:` containing spaces.
    expect(phone).toHaveAttribute('href', 'tel:+966532115582')
  })

  /** The malformed address, named so it can never come back quietly. */
  it('never builds the address out of the site URL', () => {
    signOut()
    renderWithProviders(<PublicHomePage />, { route: '/', locale: 'ar' })

    const hrefs = screen.getAllByRole('link').map((a) => a.getAttribute('href') ?? '')

    expect(hrefs).not.toContain('mailto:info@https://campaignshub.io/')
    expect(hrefs.some((h) => h.startsWith('mailto:') && h.includes('http'))).toBe(false)
    expect(document.body.textContent).not.toContain('info@https://')
  })

  it('reads correctly in Arabic', () => {
    signOut()
    renderWithProviders(<PublicHomePage />, { route: '/', locale: 'ar' })

    const block = screen.getByTestId('footer-contact')

    expect(within(block).getByText('تواصل معنا')).toBeInTheDocument()
    expect(within(block).getByText('البريد الإلكتروني:')).toBeInTheDocument()
    expect(within(block).getByText('الجوال:')).toBeInTheDocument()
    expect(within(block).getByTestId('footer-phone')).toHaveTextContent('+966 53 211 5582')
  })

  it('and in English', () => {
    signOut()
    renderWithProviders(<PublicHomePage />, { route: '/', locale: 'en' })

    const block = screen.getByTestId('footer-contact')

    expect(within(block).getByText('Contact us')).toBeInTheDocument()
    expect(within(block).getByText('Email:')).toBeInTheDocument()
    expect(within(block).getByText('Phone:')).toBeInTheDocument()
  })

  /**
   * The values carry their own direction.
   *
   * Inside an RTL footer an unmarked «+966 53 211 5582» renders with the plus stranded at the wrong
   * end, and an address can break around the «@» — both of which make real contact details look
   * like a rendering bug.
   */
  it('marks the address and the number as left-to-right', () => {
    signOut()
    renderWithProviders(<PublicHomePage />, { route: '/', locale: 'ar' })

    expect(screen.getByTestId('footer-email')).toHaveAttribute('dir', 'ltr')
    expect(screen.getByTestId('footer-phone')).toHaveAttribute('dir', 'ltr')
  })

  /** Both are links, so a phone can be tapped and an address does not have to be retyped. */
  it('renders them as links rather than as text', () => {
    signOut()
    renderWithProviders(<PublicHomePage />, { route: '/', locale: 'en' })

    expect(screen.getByTestId('footer-email').tagName).toBe('A')
    expect(screen.getByTestId('footer-phone').tagName).toBe('A')
  })
})

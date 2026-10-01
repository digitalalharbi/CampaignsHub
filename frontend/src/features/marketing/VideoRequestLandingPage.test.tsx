import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { VideoRequestLandingPage } from './VideoRequestLandingPage'
import { renderWithProviders } from '@/test/utils'

/**
 * SHORT-LINKS-LANDING-001 — the page an ad sends people to.
 *
 * The claims a browser pass cannot make, held here: the number is never written into the interface,
 * nothing about the product leaks onto a conversion page, and the page cannot acquire a way of
 * forwarding itself.
 */
describe('the video-request landing page', () => {
  it('sends the reader to WhatsApp, and to nothing else', () => {
    renderWithProviders(<VideoRequestLandingPage />, { locale: 'ar' })

    const cta = screen.getByTestId('whatsapp-cta')

    expect(cta).toHaveAttribute('href', 'https://wa.me/966553190369')

    /*
      A real `<a href>`, not a button with a handler: it works with JavaScript disabled, opens in a
      new tab, and long-presses to «copy link» — all of which a reviewer expects of a destination.
      `tagName` is the only way to assert that from here.
    */
    expect(cta.tagName).toBe('A')
  })

  /**
   * And it is not filed under the short-link feature.
   *
   * The page was first served at `/l/{slug}`, which made it a forwarder that happened to render,
   * and made its button depend on a row somebody could deactivate without opening this page.
   */
  it('depends on nothing in the short-link feature', () => {
    renderWithProviders(<VideoRequestLandingPage />, { locale: 'ar' })

    expect(screen.getByTestId('whatsapp-cta').getAttribute('href')).not.toMatch(/\/l\//)
  })

  it('is the offer and nothing about the system behind it', () => {
    renderWithProviders(<VideoRequestLandingPage />, { locale: 'ar' })

    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('اطلب الفيديوهات بسهولة')

    for (const absent of ['كامبينز هب', 'CampaignsHub', 'تسجيل الدخول', 'لوحة التحكم']) {
      expect(screen.queryByText(absent), `the page carries «${absent}»`).toBeNull()
    }

    expect(document.querySelector('nav'), 'a conversion page grew navigation').toBeNull()
    expect(document.querySelector('form'), 'a conversion page grew a form').toBeNull()
  })

  /** Arabic is the page's own, not whatever the last visitor left in storage. */
  it('states its own direction and language', () => {
    renderWithProviders(<VideoRequestLandingPage />, { locale: 'en' })

    const page = screen.getByTestId('video-request-landing')

    expect(page).toHaveAttribute('dir', 'rtl')
    expect(page).toHaveAttribute('lang', 'ar')
  })
})

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
  it('sends the reader through the short link, never to a number written here', () => {
    renderWithProviders(<VideoRequestLandingPage />, { locale: 'ar' })

    const cta = screen.getByTestId('whatsapp-cta')

    expect(cta).toHaveAttribute('href', '/l/m5pxgr2')
    /*
      The destination lives on the short-link record, which is where somebody can change it. A
      number in this file would be a second copy, and the two would differ the first time one moved.
    */
    expect(document.body.innerHTML, 'a WhatsApp number is written into the page').not.toMatch(/wa\.me|96655|whatsapp\.com/i)
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

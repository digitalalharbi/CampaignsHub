import { afterEach, describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { PublicHomePage } from './PublicHomePage'
import { renderWithProviders, signOut } from '@/test/utils'

/** Collects the href of every rendered link — the decision buttons are <Link>s, so this is the
 *  most direct way to assert each journey navigates to its exact route (incl. query params). */
function linkHrefs(): (string | null)[] {
  return screen.getAllByRole('link').map((l) => l.getAttribute('href'))
}

describe('PublicHomePage — decision section', () => {
  afterEach(() => signOut())

  it('renders the 4 journey cards, each navigating to its exact route incl. query params', () => {
    signOut()
    renderWithProviders(<PublicHomePage />, { locale: 'en' })
    const hrefs = linkHrefs()
    expect(hrefs).toContain('/register?journey=self-managed')
    expect(hrefs).toContain('/register?journey=agency')
    expect(hrefs).toContain('/requests/new?service=paid-media')
    expect(hrefs).toContain('/requests/new?service=influencer-marketing')
  })

  it('renders the accounts bar with system + client login', () => {
    signOut()
    renderWithProviders(<PublicHomePage />, { locale: 'en' })
    const hrefs = linkHrefs()
    expect(hrefs).toContain('/login')
    expect(hrefs).toContain('/client/login')
    expect(screen.getByText('I already have an account')).toBeInTheDocument()
  })

  it('keeps the headline and shows the new hero subtext', () => {
    signOut()
    renderWithProviders(<PublicHomePage />, { locale: 'en' })
    expect(screen.getByRole('heading', { name: /All your paid ad campaigns in one place/i })).toBeInTheDocument()
    expect(screen.getByText(/Run your own campaigns, manage your clients/i)).toBeInTheDocument()
  })

  it('does not duplicate the journey options — each journey route appears exactly once', () => {
    signOut()
    renderWithProviders(<PublicHomePage />, { locale: 'en' })
    const hrefs = linkHrefs()
    for (const route of [
      '/register?journey=self-managed',
      '/register?journey=agency',
      '/requests/new?service=paid-media',
      '/requests/new?service=influencer-marketing',
    ]) {
      expect(hrefs.filter((h) => h === route)).toHaveLength(1)
    }
  })

  it('renders the Arabic journeys under RTL', () => {
    signOut()
    renderWithProviders(<PublicHomePage />, { locale: 'ar' })
    expect(screen.getByText('كيف تريد استخدام CampaignsHub؟')).toBeInTheDocument()
    expect(linkHrefs()).toContain('/register?journey=agency')
  })
})

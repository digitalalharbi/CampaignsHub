import { afterEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, within } from '@testing-library/react'
import { PublicHomePage } from './PublicHomePage'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

/** Toggled by the error-state test; the mock reads it so we can simulate a catalog load failure. */
const catalogState = vi.hoisted(() => ({ isError: false }))

/**
 * The inline paid-media selector is engine-fed via `usePaidMediaCatalog` (the single public catalog).
 * We mock ONLY that hook so the homepage renders a deterministic catalog (3 categories, flat services)
 * with no network. The pure helpers (`servicesForKeys`, `servicesInCategory`, `featuredServices`) stay
 * real via `importOriginal`, so the featured strip and category swaps are exercised for real.
 */
vi.mock('@/features/paid-media/publicCatalog', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/features/paid-media/publicCatalog')>()
  const svc = (key: string, category_key: string, ar: string, en: string, sort_order: number) => ({
    key, category_key, label_ar: ar, label_en: en,
    description_ar: `${ar} وصف`, description_en: `${en} description`,
    icon: null, sort_order, required_field_rules: [],
  })
  const categories = [
    { key: 'launch_manage', label_ar: 'إدارة الحملات', label_en: 'Launch & manage', icon: null, sort_order: 1 },
    { key: 'optimization', label_ar: 'التحسين والأداء', label_en: 'Optimization', icon: null, sort_order: 2 },
    { key: 'reporting_dashboards', label_ar: 'التقارير', label_en: 'Reporting', icon: null, sort_order: 3 },
  ]
  const services = [
    svc('new_campaign', 'launch_manage', 'إطلاق حملة جديدة', 'New campaign', 1),
    svc('existing_management', 'launch_manage', 'إدارة حملات قائمة', 'Manage existing', 2),
    svc('improve_performance', 'optimization', 'تحسين الأداء', 'Improve performance', 1),
    svc('reduce_cpa_cpl', 'optimization', 'خفض التكلفة', 'Reduce CPA CPL', 2),
    svc('weekly_report', 'reporting_dashboards', 'تقرير أسبوعي', 'Weekly report', 1),
  ]
  const catalog = { version: 'v1', categories, services }
  return {
    ...actual,
    usePaidMediaCatalog: () =>
      catalogState.isError
        ? { data: undefined, categories: [], featured: [], isLoading: false, isError: true, refetch: vi.fn() }
        : { data: catalog, categories, featured: actual.featuredServices(catalog), isLoading: false, isError: false, refetch: vi.fn() },
  }
})

/** Collects the href of every rendered link — journey buttons are <Link>s, so this asserts each
 *  journey navigates to its exact route (incl. query params). */
function linkHrefs(): (string | null)[] {
  return screen.getAllByRole('link').map((l) => l.getAttribute('href'))
}

/** Reveal the inline paid-media services by clicking the «I need paid-media services» journey. */
function revealServices(): void {
  fireEvent.click(screen.getByText('I need paid-media services'))
}

describe('PublicHomePage — journeys', () => {
  afterEach(() => {
    catalogState.isError = false
    signOut()
  })

  it('the 3 navigating journeys go to their exact v4 routes; the services journey does not navigate', () => {
    signOut()
    renderWithProviders(<PublicHomePage />, { locale: 'en' })
    const hrefs = linkHrefs()
    expect(hrefs).toContain('/register?journey=self-managed&module=paid-media')
    expect(hrefs).toContain('/register?journey=agency&module=paid-media')
    expect(hrefs).toContain('/requests/new?module=influencer-marketing')
    // The paid-media journey reveals services inline; no bare navigate to the paid-media intake exists.
    expect(hrefs).not.toContain('/requests/new?module=paid-media')
    expect(hrefs).not.toContain('/requests/new?service=paid-media')
  })

  it('the anonymous header exposes external actions with correct hrefs and NO internal/admin login', () => {
    signOut()
    renderWithProviders(<PublicHomePage />, { locale: 'ar' })
    const header = screen.getByRole('banner')
    const link = (name: RegExp) => within(header).getByRole('link', { name })
    expect(link(/ابدأ الآن/)).toHaveAttribute('href', '/register')
    expect(link(/تسجيل الدخول/)).toHaveAttribute('href', '/login')
    expect(link(/اطلب خدمة/)).toHaveAttribute('href', '/requests/new')
    expect(link(/متابعة طلباتي/)).toHaveAttribute('href', '/client/login')
    // No internal/admin login is ever exposed on public surfaces (internal admin reuses the same /login).
    expect(within(header).queryByText(/تسجيل دخول النظام/)).not.toBeInTheDocument()
    expect(within(header).queryByText(/دخول العميل/)).not.toBeInTheDocument()
  })

  it('the authenticated header replaces sign-up actions with a Dashboard link', () => {
    signInWith([])
    renderWithProviders(<PublicHomePage />, { locale: 'en' })
    const header = screen.getByRole('banner')
    expect(within(header).getByRole('link', { name: /Dashboard/i })).toHaveAttribute('href', '/dashboard')
    expect(within(header).queryByRole('link', { name: /Get started/i })).not.toBeInTheDocument()
  })

  it('renders the "already have an account" block with workspace + client-tracking login (not system login)', () => {
    signOut()
    renderWithProviders(<PublicHomePage />, { locale: 'en' })
    const hrefs = linkHrefs()
    expect(hrefs).toContain('/login')
    expect(hrefs).toContain('/client/login')
    expect(screen.getByText('Do you already have an account?')).toBeInTheDocument()
    // «Workspace login» is unique to this block; it must point at the standard /login.
    expect(screen.getByRole('link', { name: /Workspace login/i })).toHaveAttribute('href', '/login')
    // «Track my requests» appears in both the header and this block; every instance targets /client/login.
    const trackLinks = screen.getAllByRole('link', { name: /Track my requests/i })
    expect(trackLinks.length).toBeGreaterThanOrEqual(1)
    trackLinks.forEach((l) => expect(l).toHaveAttribute('href', '/client/login'))
  })

  it('renders the two clearly-separated commercial tracks (SaaS subscription + service request)', () => {
    signOut()
    renderWithProviders(<PublicHomePage />, { locale: 'en' })
    expect(screen.getByText('SaaS subscription')).toBeInTheDocument()
    expect(screen.getByText('Service request')).toBeInTheDocument()
    // A representative step from each track, in the right column.
    expect(screen.getByText('Connect your platforms')).toBeInTheDocument()
    expect(screen.getByText('Receive a quote')).toBeInTheDocument()
  })

  it('never renders the removed «تسجيل دخول النظام» wording anywhere on the page', () => {
    signOut()
    renderWithProviders(<PublicHomePage />, { locale: 'ar' })
    expect(screen.queryByText(/تسجيل دخول النظام/)).not.toBeInTheDocument()
    expect(screen.queryByText(/دخول الإدارة/)).not.toBeInTheDocument()
  })

  it('keeps the headline and shows the new hero subtext', () => {
    signOut()
    renderWithProviders(<PublicHomePage />, { locale: 'en' })
    expect(screen.getByRole('heading', { name: /All your paid ad campaigns in one place/i })).toBeInTheDocument()
    expect(screen.getByText(/Run your campaigns yourself in a professional workspace/i)).toBeInTheDocument()
  })

  it('does not duplicate the journey options — each navigating journey route appears exactly once', () => {
    signOut()
    renderWithProviders(<PublicHomePage />, { locale: 'en' })
    const hrefs = linkHrefs()
    for (const route of ['/register?journey=self-managed&module=paid-media', '/register?journey=agency&module=paid-media', '/requests/new?module=influencer-marketing']) {
      expect(hrefs.filter((h) => h === route)).toHaveLength(1)
    }
  })

  it('renders the Arabic journeys under RTL', () => {
    signOut()
    renderWithProviders(<PublicHomePage />, { locale: 'ar' })
    expect(screen.getByText('كيف تريد استخدام CampaignsHub؟')).toBeInTheDocument()
    expect(linkHrefs()).toContain('/register?journey=agency&module=paid-media')
    expect(document.querySelector('[dir="rtl"]')).not.toBeNull()
  })
})

describe('PublicHomePage — inline paid-media services', () => {
  afterEach(() => {
    catalogState.isError = false
    signOut()
  })

  it('is hidden until the services journey is chosen, then reveals category tabs + featured cards', () => {
    signOut()
    renderWithProviders(<PublicHomePage />, { locale: 'en' })
    expect(screen.queryByText('New campaign')).not.toBeInTheDocument()
    expect(screen.queryByRole('tab', { name: 'Popular' })).not.toBeInTheDocument()

    revealServices()

    // Category tabs (Popular + engine categories) and the derived featured strip (one lead per category).
    expect(screen.getByRole('tab', { name: 'Popular' })).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: 'Launch & manage' })).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: 'Optimization' })).toBeInTheDocument()
    expect(screen.getByText('New campaign')).toBeInTheDocument()
    expect(screen.getByText('Improve performance')).toBeInTheDocument()
    expect(screen.getByText('Weekly report')).toBeInTheDocument()
  })

  it('multi-select shows the selected count, and the CTA carries the exact selected keys', () => {
    signOut()
    renderWithProviders(<PublicHomePage />, { locale: 'en' })
    revealServices()

    fireEvent.click(screen.getByText('New campaign'))
    fireEvent.click(screen.getByText('Improve performance'))

    expect(screen.getByText((_, el) => el?.textContent === 'Selected services: 2')).toBeInTheDocument()

    const cta = screen.getAllByRole('link').map((l) => l.getAttribute('href'))
    expect(cta).toContain('/requests/new?module=paid-media&services=new_campaign,improve_performance')
  })

  it('clicking a different category swaps the shown services', () => {
    signOut()
    renderWithProviders(<PublicHomePage />, { locale: 'en' })
    revealServices()

    // A non-lead service is not in the default (featured) strip.
    expect(screen.queryByText('Reduce CPA CPL')).not.toBeInTheDocument()

    fireEvent.click(screen.getByRole('tab', { name: 'Optimization' }))

    expect(screen.getByText('Reduce CPA CPL')).toBeInTheDocument()
    // A featured service from another category is no longer shown.
    expect(screen.queryByText('Weekly report')).not.toBeInTheDocument()
  })

  it('«View all services» opens the drawer, and its selections stay in sync with the inline strip', () => {
    signOut()
    renderWithProviders(<PublicHomePage />, { locale: 'en' })
    revealServices()

    fireEvent.click(screen.getByRole('button', { name: 'View all services' }))

    const dialog = screen.getByRole('dialog')
    expect(within(dialog).getByText('All paid-media services')).toBeInTheDocument()
    expect(within(dialog).getByPlaceholderText('Search services…')).toBeInTheDocument()

    // Selecting inside the drawer reflects into the shared selected count.
    fireEvent.click(within(dialog).getByText('Manage existing'))
    expect(within(dialog).getByText((_, el) => el?.textContent === 'Selected services: 1')).toBeInTheDocument()
  })

  it('shows an error state + retry on catalog load failure — and NO service cards', () => {
    catalogState.isError = true
    signOut()
    renderWithProviders(<PublicHomePage />, { locale: 'en' })
    revealServices()

    expect(screen.getByText("Couldn't load services")).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Retry/i })).toBeInTheDocument()
    // Never fall back to a static/demo list.
    expect(screen.queryByText('New campaign')).not.toBeInTheDocument()
    expect(screen.queryByRole('tab', { name: 'Popular' })).not.toBeInTheDocument()
  })
})

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

/** Reveal the inline paid-media services by clicking the services option (locale-agnostic — it is the
 *  only control carrying `aria-expanded`). */
function revealServices(): void {
  const btn = screen.getAllByRole('button').find((b) => b.hasAttribute('aria-expanded'))
  if (!btn) throw new Error('services reveal option not found')
  fireEvent.click(btn)
}

/**
 * Internal/system vocabulary that must NEVER appear on the public homepage (v5). These are the
 * forbidden terms; the page must read entirely in customer-facing language.
 */
const FORBIDDEN = [
  /SaaS/, /اشتراك SaaS/, /المشتركين/, /للمشتركين/, /للوكالات/, /حساب وكالة/, /مساحة وكالة/,
  /مساحة العمل/, /Workspace/, /Tenant/, /Company Tenant/, /Personal Workspace/, /Operations Console/,
  /Module Entitlements/, /واجهة المشترك/, /تجربة الشركة/, /دخول مساحة العمل/, /تسجيل دخول النظام/,
]

describe('PublicHomePage — v5 journeys & header', () => {
  afterEach(() => {
    catalogState.isError = false
    signOut()
  })

  it('the anonymous header exposes the 4 external actions with correct hrefs and NO internal/admin login', () => {
    signOut()
    renderWithProviders(<PublicHomePage />, { locale: 'ar' })
    const header = screen.getByRole('banner')
    const link = (name: RegExp) => within(header).getByRole('link', { name })
    expect(link(/إنشاء حساب/)).toHaveAttribute('href', '/register')
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
    expect(within(header).queryByRole('link', { name: /Create account/i })).not.toBeInTheDocument()
  })

  it('the 3 navigating options go to their exact v5 routes; the services option does not navigate', () => {
    signOut()
    renderWithProviders(<PublicHomePage />, { locale: 'en' })
    const hrefs = linkHrefs()
    expect(hrefs).toContain('/register?journey=self-service&module=paid-media')
    expect(hrefs).toContain('/register?journey=multi-client&module=paid-media')
    expect(hrefs).toContain('/requests/new?module=influencer-marketing')
    // The paid-media services option reveals the selector inline; no bare navigate to the intake exists.
    expect(hrefs).not.toContain('/requests/new?module=paid-media')
    expect(hrefs).not.toContain('/requests/new?service=paid-media')
  })

  it('the 4th journey route (paid-media services) is carried by the CTA once a service is picked', () => {
    signOut()
    renderWithProviders(<PublicHomePage />, { locale: 'en' })
    revealServices()
    fireEvent.click(screen.getByText('New campaign'))
    expect(linkHrefs()).toContain('/requests/new?module=paid-media&services=new_campaign')
  })

  it('renders the below-card login block with ONLY log-in + track-my-requests (no internal login)', () => {
    signOut()
    renderWithProviders(<PublicHomePage />, { locale: 'ar' })
    const hrefs = linkHrefs()
    expect(hrefs).toContain('/login')
    expect(hrefs).toContain('/client/login')
    // Every «تسجيل الدخول» instance points at /login; every «متابعة طلباتي» at /client/login.
    screen.getAllByRole('link', { name: /تسجيل الدخول/ }).forEach((l) => expect(l).toHaveAttribute('href', '/login'))
    screen.getAllByRole('link', { name: /متابعة طلباتي/ }).forEach((l) => expect(l).toHaveAttribute('href', '/client/login'))
    // The below-card helper line, in customer language (no «دخول مساحة العمل»).
    expect(screen.getByText(/سجّل الدخول لإدارة حملاتك/)).toBeInTheDocument()
  })

  it('does not duplicate the options — each navigating route appears exactly once', () => {
    signOut()
    renderWithProviders(<PublicHomePage />, { locale: 'en' })
    const hrefs = linkHrefs()
    for (const route of ['/register?journey=self-service&module=paid-media', '/register?journey=multi-client&module=paid-media', '/requests/new?module=influencer-marketing']) {
      expect(hrefs.filter((h) => h === route)).toHaveLength(1)
    }
  })

  it('shows the customer-facing hero: eyebrow, headline and description', () => {
    signOut()
    renderWithProviders(<PublicHomePage />, { locale: 'en' })
    expect(screen.getByRole('heading', { name: /All your paid ad campaigns in one place/i })).toBeInTheDocument()
    expect(screen.getByText(/from one clear dashboard/i)).toBeInTheDocument()
    expect(screen.getByText('Paid advertising management')).toBeInTheDocument()
  })

  it('renders the Arabic options card «كيف تريد البدء؟» under RTL', () => {
    signOut()
    renderWithProviders(<PublicHomePage />, { locale: 'ar' })
    expect(screen.getByText('كيف تريد البدء؟')).toBeInTheDocument()
    expect(linkHrefs()).toContain('/register?journey=multi-client&module=paid-media')
    expect(document.querySelector('[dir="rtl"]')).not.toBeNull()
  })
})

describe('PublicHomePage — forbidden internal jargon guard', () => {
  afterEach(() => {
    catalogState.isError = false
    signOut()
  })

  it('renders NONE of the forbidden internal terms in Arabic (incl. the revealed services)', () => {
    signOut()
    renderWithProviders(<PublicHomePage />, { locale: 'ar' })
    revealServices()
    for (const term of FORBIDDEN) expect(screen.queryByText(term)).not.toBeInTheDocument()
  })

  it('renders NONE of the forbidden internal terms in English (incl. the revealed services)', () => {
    signOut()
    renderWithProviders(<PublicHomePage />, { locale: 'en' })
    revealServices()
    for (const term of FORBIDDEN) expect(screen.queryByText(term)).not.toBeInTheDocument()
  })
})

describe('PublicHomePage — inline paid-media services', () => {
  afterEach(() => {
    catalogState.isError = false
    signOut()
  })

  it('is hidden until the services option is chosen, then reveals category tabs + featured cards', () => {
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

  it('HOME-013: the influencer portal shows a differentiated hero + tailored preview', () => {
    signOut()
    renderWithProviders(<PublicHomePage />, { route: '/?portal=influencer', locale: 'en' })
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent(/influencer & content campaigns/i)
    expect(within(screen.getByTestId('portal-preview')).getByText('Deliverables')).toBeInTheDocument()
    expect(screen.queryByTestId('campaign-overview')).not.toBeInTheDocument() // no paid overview for this portal
  })

  it('HOME-013: the client portal shows request-tracking hero + preview', () => {
    signOut()
    renderWithProviders(<PublicHomePage />, { route: '/?portal=client', locale: 'en' })
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent(/track your requests/i)
    expect(within(screen.getByTestId('portal-preview')).getByText('Invoices')).toBeInTheDocument()
  })

  it('HOME-013: default (no portal) keeps the paid campaign overview preview', () => {
    signOut()
    renderWithProviders(<PublicHomePage />, { route: '/', locale: 'en' })
    expect(screen.getByTestId('campaign-overview')).toBeInTheDocument()
    expect(screen.queryByTestId('portal-preview')).not.toBeInTheDocument()
  })
})

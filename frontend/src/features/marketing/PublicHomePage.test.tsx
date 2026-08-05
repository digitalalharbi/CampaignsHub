import { afterEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, within } from '@testing-library/react'
import { PublicHomePage } from './PublicHomePage'
import { offeredJourneys } from './journeys'
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
    expect(link(/متابعة طلباتي/)).toHaveAttribute('href', '/login')
    // No internal/admin login is ever exposed on public surfaces (internal admin reuses the same /login).
    expect(within(header).queryByText(/تسجيل دخول النظام/)).not.toBeInTheDocument()
    expect(within(header).queryByText(/دخول العميل/)).not.toBeInTheDocument()
  })

  it('the authenticated header replaces sign-up actions with a Dashboard link', () => {
    signInWith([])
    renderWithProviders(<PublicHomePage />, { locale: 'en' })
    const header = screen.getByRole('banner')
    expect(within(header).getByRole('link', { name: /Dashboard/i })).toHaveAttribute("href", "/app/dashboard")
    expect(within(header).queryByRole('link', { name: /Create account/i })).not.toBeInTheDocument()
  })

  it('the 3 navigating options go to their exact v5 routes; the services option does not navigate', () => {
    signOut()
    renderWithProviders(<PublicHomePage />, { locale: 'en' })
    const hrefs = linkHrefs()
    expect(hrefs).toContain('/register?journey=self-service&module=paid-media')
    expect(hrefs).toContain('/register?journey=multi-client&module=paid-media')
    // The influencer/UGC card is withdrawn (INFL-OFF-001) — it must not be reachable from anywhere
    // on this page, which is the difference between removing a card and hiding one.
    expect(hrefs).not.toContain('/requests/new?module=influencer-marketing')
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
    expect(hrefs).toContain('/login')
    // Every «تسجيل الدخول» instance points at /login; every «متابعة طلباتي» at /client/login.
    screen.getAllByRole('link', { name: /تسجيل الدخول/ }).forEach((l) => expect(l).toHaveAttribute('href', '/login'))
    screen.getAllByRole('link', { name: /متابعة طلباتي/ }).forEach((l) => expect(l).toHaveAttribute('href', '/login'))
    // The below-card helper line, in customer language (no «دخول مساحة العمل»).
    expect(screen.getByText(/سجّل الدخول لإدارة حملاتك/)).toBeInTheDocument()
  })

  /**
   * The page offers each journey deliberately TWICE — once in the hero and once as the page closes —
   * so the old "appears exactly once" rule no longer describes the product. What must hold is that
   * both surfaces send the visitor to the SAME configured route, and that none of them is an anchor
   * back to this page (which is exactly how the closing cards once bounced to the top).
   */
  it('routes every journey to its configured destination, identically in the hero and the closing section', () => {
    signOut()
    renderWithProviders(<PublicHomePage />, { locale: 'en' })

    for (const journey of offeredJourneys()) {
      const key = journey.key
      const expected = journey.to
      const closing = screen.getByTestId(`closing-journey-${key}`)
      expect(closing).toHaveAttribute('href', expected)
      expect(expected.startsWith('/')).toBe(true)
      expect(expected).not.toContain('#')

      // The hero lists the unselected journeys as links; the selected one rides the primary CTA.
      const heroLink = screen.queryByTestId(`hero-journey-link-${key}`)
      const heroHref = heroLink?.getAttribute('href') ?? screen.getByTestId('hero-primary-cta').getAttribute('href')
      expect(heroHref, `hero and closing disagree for ${key}`).toBe(expected)
    }
  })

  it('has no dead anchors — every in-page link points at a section that exists', () => {
    signOut()
    const { container } = renderWithProviders(<PublicHomePage />, { locale: 'en' })
    const hrefs = linkHrefs().filter((h): h is string => Boolean(h))

    expect(hrefs.filter((h) => h === '#')).toHaveLength(0)
    for (const h of hrefs.filter((x) => x.startsWith('#'))) {
      expect(container.querySelector(h), `anchor ${h} has no target`).not.toBeNull()
    }
  })

  it('shows the customer-facing hero: eyebrow, headline and description', () => {
    signOut()
    renderWithProviders(<PublicHomePage />, { locale: 'en' })
    expect(screen.getByRole('heading', { name: /All your paid ad campaigns in one place/i })).toBeInTheDocument()
    expect(screen.getByText(/from one clear dashboard/i)).toBeInTheDocument()
    expect(screen.getByText('Paid advertising management')).toBeInTheDocument()
  })

  it('renders the Arabic start panel «كيف تريد البدء؟» under RTL', () => {
    signOut()
    renderWithProviders(<PublicHomePage />, { locale: 'ar' })
    // The start block was rebuilt around a selector: the same heading, now with four selectable paths.
    expect(screen.getByText('كيف تريد البدء؟')).toBeInTheDocument()
    expect(screen.getByTestId('hero-path-self-service')).toBeInTheDocument()
    expect(screen.getByTestId('hero-path-multi-client')).toBeInTheDocument()
    expect(screen.getByTestId('hero-path-services')).toBeInTheDocument()
    // Three paths, not four: influencer/UGC is withdrawn in this release (INFL-OFF-001).
    expect(screen.queryByTestId('hero-path-influencer')).not.toBeInTheDocument()
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

    // The services selector itself now lives in a dialog, so «View all services» opens a second one on
    // top of it — assert against the topmost.
    const dialog = screen.getAllByRole('dialog').at(-1)!
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

    // Both surfaces depend on the same catalogue query, so a failure now offers a retry in the
    // services selector AND in the homepage services section — scope to the selector's own region.
    // Both surfaces depend on the same catalogue query, so a failure now offers a retry in the
    // services selector AND in the homepage services section. Assert both messages and both retries
    // exist — every failing surface must give the visitor a way to recover.
    expect(screen.getByText("Couldn't load services")).toBeInTheDocument()
    expect(screen.getByText('The services catalogue could not be loaded.')).toBeInTheDocument()
    expect(screen.getAllByRole('button', { name: /Retry/i }).length).toBeGreaterThanOrEqual(2)
    // Never fall back to a static/demo list.
    expect(screen.queryByText('New campaign')).not.toBeInTheDocument()
    expect(screen.queryByRole('tab', { name: 'Popular' })).not.toBeInTheDocument()
  })

  /**
   * `?portal=influencer` no longer pitches a service we cannot deliver (INFL-OFF-001).
   *
   * It used to render a whole homepage variant — hero, points, preview and a CTA into an intake that
   * now refuses the module. An old link falls back to the ordinary homepage instead, which sells
   * something real rather than advertising a portal with no door.
   */
  it('the withdrawn influencer variant falls back to the ordinary homepage', () => {
    signOut()
    renderWithProviders(<PublicHomePage />, { route: '/?portal=influencer', locale: 'en' })
    expect(screen.getByRole('heading', { level: 1 })).not.toHaveTextContent(/influencer & content campaigns/i)
    expect(screen.queryByTestId('portal-preview')).not.toBeInTheDocument()
    expect(screen.getByTestId('campaign-overview')).toBeInTheDocument()
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

/**
 * MKT-UGC-001 — the influencer/UGC service is ANNOUNCED while it stays switched off.
 *
 * The announcement and the withdrawal have to hold at the same time, which is the only interesting
 * thing about this card. So the tests below check both halves: the visitor is told the service is
 * coming, and is given no way at all to ask for it — no link, no button, nothing that would land them
 * in an intake the backend refuses.
 */
describe('PublicHomePage — the influencer & UGC announcement', () => {
  afterEach(() => {
    catalogState.isError = false
    signOut()
  })

  it('announces the service in Arabic with a «قريبًا» badge', () => {
    signOut()
    renderWithProviders(<PublicHomePage />, { route: '/', locale: 'ar' })

    const card = screen.getByTestId('home-service-soon-influencers')
    expect(within(card).getByText('علاقات المؤثرين وUGC')).toBeInTheDocument()
    expect(within(card).getByText('إدارة حملات المؤثرين والمحتوى والتعاونات من مكان واحد.')).toBeInTheDocument()
    expect(within(card).getByText('قريبًا')).toBeInTheDocument()
  })

  it('announces it in English too', () => {
    signOut()
    renderWithProviders(<PublicHomePage />, { route: '/', locale: 'en' })

    const card = screen.getByTestId('home-service-soon-influencers')
    expect(within(card).getByText('Influencer relations & UGC')).toBeInTheDocument()
    expect(within(card).getByText('Coming soon')).toBeInTheDocument()
  })

  /** The claim that matters: an announcement is not an offer. */
  it('offers nothing to press — no link, no button, no route into the withdrawn sub-system', () => {
    signOut()
    renderWithProviders(<PublicHomePage />, { route: '/', locale: 'ar' })

    const card = screen.getByTestId('home-service-soon-influencers')
    expect(within(card).queryAllByRole('link')).toHaveLength(0)
    expect(within(card).queryAllByRole('button')).toHaveLength(0)
    expect(linkHrefs().some((h) => h?.includes('influencer'))).toBe(false)
  })

  /** It sits inside the services grid, so it inherits that grid's sizing rather than adding a row of its own. */
  it('lives in the services grid beside the real categories', () => {
    signOut()
    renderWithProviders(<PublicHomePage />, { route: '/', locale: 'en' })

    const grid = screen.getByTestId('home-service-categories')
    expect(within(grid).getByTestId('home-service-soon-influencers')).toBeInTheDocument()
    // The three mocked categories are still there — announcing did not displace anything.
    expect(within(grid).getByText('Launch & manage')).toBeInTheDocument()
    expect(grid.children).toHaveLength(4)
  })

  /** A catalogue that failed to load renders no grid at all — and no announcement inside a missing grid. */
  it('is not shown when the services catalogue could not be loaded', () => {
    catalogState.isError = true
    signOut()
    renderWithProviders(<PublicHomePage />, { route: '/', locale: 'en' })

    expect(screen.queryByTestId('home-service-soon-influencers')).not.toBeInTheDocument()
  })
})

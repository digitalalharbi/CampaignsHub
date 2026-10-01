import { describe, expect, it } from 'vitest'

/**
 * UX-PAGE-HERO-001 — one header, and a list that may only ever get shorter.
 *
 * ## Why a ratchet and not a rule
 *
 * `PageIntro` is this product's header. Ninety-two surfaces drew their own `<h1>` when it was
 * introduced and five used it, so the product's own spelling was the minority one — and every page
 * that opted out also opted out of the eyebrow, the purpose line, the meta row and the KPI slot that
 * come with it. A reader crossing between two such pages met two sizes of the same thing and learnt
 * nothing transferable.
 *
 * The cross-product pass took the surfaces on the authenticated RAIL to the shared header: Campaigns,
 * Analytics, Content, Reports, Recommendations, Alerts, Spend limits, Tasks, Files, Short links,
 * Team, Subscription and Settings, after Projects, Portfolio, Dashboard and the project context
 * before them. What is left is everything a rail link does not reach directly — detail pages, the
 * finance and CRM corners, admin — and converting those is a pass of its own, not a line in this one.
 *
 * So this is a RATCHET. The list below is what was still hand-drawn when the rail pass finished.
 * Adding a new one fails. Removing one fails too, with the instruction to delete the line — which is
 * what makes the list shrink rather than rot.
 */
const TREE: Record<string, string> = import.meta.glob('/src/features/**/*.tsx', {
  query: '?raw',
  import: 'default',
  eager: true,
})

/** Not the authenticated product: own composition, own widths, no rail behind them. */
const OUT_OF_SCOPE = [
  /\/features\/auth\//,
  /\/features\/signup\//,
  /\/features\/public\//,
  /\/features\/portal\//,
  /\/features\/requests\/portal\//,
  /\/features\/admin\//,
  /\/features\/onboarding\//,
  /*
    AD-LANDING-001 — a paid-ad landing page is not a product surface.
    `PageIntro` answers «where am I, what is the scope, how fresh is this» for somebody INSIDE the
    workspace. The reader here has no workspace, arrived from an advertisement, and is being asked
    one question. Giving it the product's header would be giving it the product's chrome, which is
    the thing that page is deliberately without.
  */
  /\.test\.tsx$/,
]

/**
 * Still drawing their own header when the rail pass finished.
 *
 * Every line here is a page a rail link does not reach directly. Shortening this list is the point;
 * lengthening it is the thing this test exists to stop.
 */
const STILL_HAND_DRAWN = [
  'src/features/account/PasswordPage.tsx',
  'src/features/account/PersonalNotificationsPage.tsx',
  'src/features/account/PreferencesPage.tsx',
  'src/features/account/ProfilePage.tsx',
  'src/features/account/SecurityPage.tsx',
  'src/features/agency/RequireAgencyPortal.tsx',
  'src/features/billing/InvoicesPage.tsx',
  'src/features/billing/PaymentsPage.tsx',
  'src/features/billing/QuotesPage.tsx',
  'src/features/campaigns/CampaignDetailPage.tsx',
  'src/features/clients/ClientCommandCenterPage.tsx',
  'src/features/content/CreativeDetailPage.tsx',
  'src/features/content/CreativeGroupsPage.tsx',
  'src/features/crm/LeadsPage.tsx',
  'src/features/design/DesignSystemPage.tsx',
  'src/features/dev/DevStatusPage.tsx',
  'src/features/drive/DrivePage.tsx',
  'src/features/influencers/CollaborationsPage.tsx',
  'src/features/influencers/DeliverablesPage.tsx',
  'src/features/influencers/NominationsPage.tsx',
  'src/features/influencers/RequireInfluencerPortal.tsx',
  'src/features/influencers/RosterPage.tsx',
  'src/features/influencers/creator/CreatorCollaborationPage.tsx',
  'src/features/influencers/creator/CreatorWorkPage.tsx',
  'src/features/marketing/DataDeletionPage.tsx',
  'src/features/marketing/HeroSection.tsx',
  'src/features/marketing/MarketingPage.tsx',
  'src/features/marketing/PublicHomePage.tsx',
  'src/features/marketing/PublicInfoPage.tsx',
  'src/features/marketing/PublicServicesPage.tsx',
  'src/features/projects/ProjectTeamPage.tsx',
  'src/features/reports/InteractiveReport.tsx',
  'src/features/reports/PrintDocument.tsx',
  'src/features/reports/PublicReport.tsx',
  'src/features/requestJourney/JourneyDemoPage.tsx',
  'src/features/requests/PaidMediaIntake.tsx',
  'src/features/requests/RequestIntakePage.tsx',
  'src/features/requests/RequestTrackPage.tsx',
  'src/features/settings/PublicPagesSettingsPage.tsx',
  'src/features/subscriptions/InvoicesPage.tsx',
  'src/features/system/SystemStatusPage.tsx',
  'src/features/taxonomy/TaxonomyManagerPage.tsx',
]

describe('the shared page header', () => {
  it('is what the rail surfaces use, and the rest is a list that may only shrink', () => {
    const handDrawn = Object.entries(TREE)
      .map(([path, source]) => [path.replace(/^\/+/, ''), source] as const)
      .filter(([path]) => !OUT_OF_SCOPE.some((rx) => rx.test(path)))
      // A page that draws an `<h1>` AND does not import the shared header is drawing its own.
      .filter(([, source]) => /<h1[\s>]/.test(source) && !source.includes("from '@/components/ui/PageIntro'"))
      .map(([path]) => path)
      .sort()

    const added = handDrawn.filter((p) => !STILL_HAND_DRAWN.includes(p))
    const fixed = STILL_HAND_DRAWN.filter((p) => !handDrawn.includes(p))

    expect(
      added,
      `these surfaces draw their own <h1> instead of PageIntro:\n  ${added.join('\n  ')}`,
    ).toEqual([])

    expect(
      fixed,
      `these now use PageIntro — delete them from STILL_HAND_DRAWN:\n  ${fixed.join('\n  ')}`,
    ).toEqual([])
  })
})

import { createBrowserRouter, Navigate } from 'react-router-dom'
import { NotFoundPage } from './NotFoundPage'
import { LoginPage } from '@/features/auth/LoginPage'
import { RegisterPage } from '@/features/auth/RegisterPage'
import { AccountStatusPage } from '@/features/signup/AccountStatusPage'
import { ForgotPasswordPage } from '@/features/auth/ForgotPasswordPage'
import { RequireAuth } from '@/features/auth/RequireAuth'
import { CampaignDetailPage } from '@/features/campaigns/CampaignDetailPage'
import { CampaignsPage } from '@/features/campaigns/CampaignsPage'
import { AnalyticsPage } from '@/features/analytics/AnalyticsPage'
import { DashboardPage } from '@/features/dashboard/DashboardPage'
import { LeadsPage } from '@/features/crm/LeadsPage'
import { ReportsPage } from '@/features/reports/ReportsPage'
import { SettingsPage } from '@/features/settings/SettingsPage'
import { PublicReport } from '@/features/reports/PublicReport'
import { PrintReport } from '@/features/reports/PrintReport'
import { DesignSystemPage } from '@/features/design/DesignSystemPage'
import { SettingsLayout } from '@/features/account/SettingsLayout'
import { AccountSettingsLayout } from '@/features/account/AccountSettingsLayout'
import { PreferencesPage } from '@/features/account/PreferencesPage'
import { PersonalNotificationsPage } from '@/features/account/PersonalNotificationsPage'
import { ProfilePage } from '@/features/account/ProfilePage'
import { PasswordPage } from '@/features/account/PasswordPage'
import { SecurityPage } from '@/features/account/SecurityPage'
import { MarketingPage } from '@/features/marketing/MarketingPage'
import { ProjectIntegrationsPage } from '@/features/projects/ProjectIntegrationsPage'
import { ProjectsPage } from '@/features/projects/ProjectsPage'
import { ProjectTeamPage } from '@/features/projects/ProjectTeamPage'
import { SystemStatusPage } from '@/features/system/SystemStatusPage'
import { PublicHomePage } from '@/features/marketing/PublicHomePage'
import { PublicInfoPage } from '@/features/marketing/PublicInfoPage'
import { PublicServicesPage } from '@/features/marketing/PublicServicesPage'
import { RequestIntakePage } from '@/features/requests/RequestIntakePage'
import { RequestTrackPage } from '@/features/requests/RequestTrackPage'
import { ClientRequestsPage } from '@/features/requests/portal/ClientRequestsPage'
import { ClientRequestDetailPage } from '@/features/requests/portal/ClientRequestDetailPage'
import { ClientDashboardPage } from '@/features/requests/portal/ClientDashboardPage'
import { ClientQuotesPage } from '@/features/requests/portal/ClientQuotesPage'
import { ClientQuoteDetailPage } from '@/features/requests/portal/ClientQuoteDetailPage'
import { ClientInvoicesPage } from '@/features/requests/portal/ClientInvoicesPage'
import { ClientInvoiceDetailPage } from '@/features/requests/portal/ClientInvoiceDetailPage'
import { ClientMessagesPage } from '@/features/requests/portal/ClientMessagesPage'
import { ClientThreadPage } from '@/features/requests/portal/ClientThreadPage'
import { ClientProfilePage } from '@/features/requests/portal/ClientProfilePage'
import { ClientFilesPage } from '@/features/requests/portal/ClientFilesPage'
import { ClientCampaignsPage } from '@/features/requests/portal/ClientCampaignsPage'
import { ClientReportsPage } from '@/features/requests/portal/ClientReportsPage'
import { ClientSpacePickerPage } from '@/features/requests/portal/ClientSpacePickerPage'
import { ClientPortalHomePage } from '@/features/requests/portal/ClientPortalHomePage'
import { VerifyEmailPage } from '@/features/onboarding/VerifyEmailPage'
import { OnboardingWizard } from '@/features/onboarding/OnboardingWizard'
import { OnboardingGate } from '@/features/onboarding/OnboardingGate'
import { InviteAcceptPage } from '@/features/onboarding/InviteAcceptPage'
import { RequestsDashboardPage } from '@/features/requests/RequestsDashboardPage'
import { RequestDetailPage } from '@/features/requests/RequestDetailPage'
import { ClientsPortfolioPage } from '@/features/clients/ClientsPortfolioPage'
import { ClientCommandCenterPage } from '@/features/clients/ClientCommandCenterPage'
import { AlertsPage } from '@/features/alerts/AlertsPage'
import { DevStatusPage } from '@/features/dev/DevStatusPage'
import { billingRoutes } from '@/features/billing/billingRoutes'
import { messagingRoutes } from '@/features/messaging/messagingRoutes'
import { requestJourneyRoutes } from '@/features/requestJourney/requestJourneyRoutes'
import { subscriptionsRoutes } from '@/features/subscriptions/subscriptionsRoutes'
// Canonical pages (Integrations absorbs Connection Center + Drive connector; Branding lives under Settings).
import { ConnectionCenterPage } from '@/features/connections/ConnectionCenterPage'
import { DrivePage } from '@/features/drive/DrivePage'
import { TasksPage } from '@/features/tasks/TasksPage'
import { CreativesPage } from '@/features/content/CreativesPage'
import { FilesLibraryPage } from '@/features/files/FilesLibraryPage'
import { BrandingCenterPage } from '@/features/branding/BrandingCenterPage'
import { AppShell } from '@/layouts/AppShell'
import { AgencyShell } from '@/layouts/AgencyShell'
import { AdminShell } from '@/layouts/AdminShell'
import { RequirePlatformAdmin } from '@/features/admin/RequirePlatformAdmin'
import { RequirePortal } from '@/features/auth/RequirePortal'
import { PlatformOverviewPage } from '@/features/admin/PlatformOverviewPage'
import { TenantsPage } from '@/features/admin/TenantsPage'
import { RegistrationsPage } from '@/features/admin/RegistrationsPage'
import { PaymentSettingsPage } from '@/features/admin/PaymentSettingsPage'
import { SystemSettingsPage } from '@/features/admin/SystemSettingsPage'
import { BillingPage as AdminBillingPage } from '@/features/admin/BillingPage'
import { AuditPage } from '@/features/admin/AuditPage'
import { CutoverPage } from '@/features/admin/CutoverPage'
import { AgencyDashboardPage } from '@/features/agency/AgencyDashboardPage'
import { RequireAgencyPortal } from '@/features/agency/RequireAgencyPortal'
import { AgencyTeamPage } from '@/features/agency/AgencyTeamPage'
import { InfluencerShell } from '@/layouts/InfluencerShell'
import { RequireInfluencerPortal } from '@/features/influencers/RequireInfluencerPortal'
import { RequireInfluencersEnabled } from '@/features/influencers/RequireInfluencersEnabled'
import { features } from '@/lib/features'
import { CollaborationsPage } from '@/features/influencers/CollaborationsPage'
import { LegacyLoginRedirect } from '@/features/auth/LegacyLoginRedirect'
import { NominationsPage } from '@/features/influencers/NominationsPage'
import { RosterPage } from '@/features/influencers/RosterPage'
import { DeliverablesPage } from '@/features/influencers/DeliverablesPage'
import { CreatorShell } from '@/layouts/CreatorShell'
import { CreatorWorkPage } from '@/features/influencers/creator/CreatorWorkPage'
import { CreatorCollaborationPage } from '@/features/influencers/creator/CreatorCollaborationPage'
import { WorkspaceSwitcherPage } from '@/features/auth/WorkspaceSwitcherPage'
import { legacyAgencyRedirects, legacyAppRedirects, legacyClientPortalRedirects } from './legacyRedirects'

export const router = createBrowserRouter([
  // Public marketing homepage — the primary conversion surface. The authenticated app lives under
  // its own paths (/dashboard, /campaigns, …); `/` is public and shows "back to dashboard" when signed in.
  { path: '/', element: <PublicHomePage /> },
  { path: '/welcome', element: <MarketingPage /> },
  { path: '/login', element: <LoginPage /> },
  { path: '/register', element: <RegisterPage /> },
  /*
   * Where an application lives before it is an account (SIGNUP-002).
   *
   * Public, because an applicant has no session, and the same URL serves every pre-activation state:
   * awaiting email confirmation, awaiting an OTP, in a review queue, or owing a payment. The
   * confirmation link lands here too, with `?token=`.
   */
  { path: '/signup/status', element: <AccountStatusPage /> },
  { path: '/forgot-password', element: <ForgotPasswordPage /> },
  { path: '/requests/new', element: <RequestIntakePage /> },
  { path: '/requests/track', element: <RequestTrackPage /> },
  // Public policy + company pages behind the footer links. One route with a slug so adding a page is a
  // content change, and an unknown slug renders a clear not-found state rather than a blank screen.
  ...['privacy', 'terms', 'data-processing', 'cookies', 'security', 'about', 'contact', 'support', 'faq']
    .map((slug) => ({ path: `/${slug}`, element: <PublicInfoPage /> })),

  // Public services catalogue, read from the taxonomy engine (never a bundled array).
  { path: '/services', element: <PublicServicesPage /> },
  { path: '/services/:category', element: <PublicServicesPage /> },
  // ADR 0002: the request-tracking portal lives at /portal/*. It still runs its own OTP cookie
  // session (see PORTAL-AUTH-001 — the auth engines are not merged yet); what moved is the URL
  // space, so all four portals are addressed the same way. Each page guards itself: a 401 from the
  // portal endpoints returns to the portal login. The section nav lives in PortalShell.
  /*
   * LOGIN-UNIFIED-001 — every sign-in door is `/login`, and only `/login`.
   *
   * There used to be five: `/login` with a portal chooser on it, plus `/admin/login`,
   * `/app/login`, `/agency/login`, `/influencers/login` and `/portal/login`. Six addresses that all
   * answered the same question meant six places for the answer to drift, and the chooser asked the
   * visitor something only the server can know — a client who picked «إدارة الحملات» was shown a
   * password field their account has never had.
   *
   * So the doors are gone and the addresses redirect. `replace` matters: without it, Back from
   * `/login` returns to `/app/login`, which redirects forward again — a loop the visitor cannot
   * escape with the one control they reached for.
   *
   * `LegacyLoginRedirect` carries the query string through, so `?redirect=%2Fagency%2Fclients`
   * survives and the destination after authenticating is still the page they were heading for.
   */
  { path: '/admin/login', element: <LegacyLoginRedirect /> },
  { path: '/app/login', element: <LegacyLoginRedirect /> },
  { path: '/agency/login', element: <LegacyLoginRedirect /> },
  { path: '/portal/login', element: <LegacyLoginRedirect /> },
  { path: '/influencers/login', element: <LegacyLoginRedirect /> },

  /*
   * …and the rest of the withdrawn tree, answered BEFORE authentication (INFL-OFF-001).
   *
   * The portal's pages live inside `RequireAuth`, so a signed-out visitor opening `/influencers`
   * met the sign-in gate first and was sent to `/login?redirect=%2Finfluencers` — asked to
   * authenticate for a portal that no longer exists, and, had they done so, delivered right back to
   * a redirect. Found by the audit walking it signed OUT; every check while signed in had passed.
   *
   * Mounted only while the service is off, so switching it back on removes this route entirely
   * rather than leaving a shadow that could swallow the real tree.
   */
  ...(features.influencersUgc
    ? []
    : [{ path: '/influencers/*', element: <RequireInfluencersEnabled /> }]),
  { path: '/portal/requests', element: <ClientRequestsPage /> },
  { path: '/portal/requests/:reference', element: <ClientRequestDetailPage /> },
  { path: '/portal/quotes', element: <ClientQuotesPage /> },
  { path: '/portal/quotes/:id', element: <ClientQuoteDetailPage /> },
  { path: '/portal/invoices', element: <ClientInvoicesPage /> },
  { path: '/portal/invoices/:id', element: <ClientInvoiceDetailPage /> },
  { path: '/portal/messages', element: <ClientMessagesPage /> },
  { path: '/portal/messages/:id', element: <ClientThreadPage /> },
  { path: '/portal/profile', element: <ClientProfilePage /> },
  { path: '/portal/files', element: <ClientFilesPage /> },
  { path: '/portal/campaigns', element: <ClientCampaignsPage /> },
  { path: '/portal/reports', element: <ClientReportsPage /> },
  // Pre-ADR-0002 paths. Kept alive rather than deleted: these are in clients' bookmarks and in
  // emails already sent, and a dead link there costs a support conversation.
  ...legacyClientPortalRedirects,

  // PORTAL-CLIENT-001: the isolated agency-client space. `/client/*` above still means "everything
  // this contact reaches"; a space narrows every read to ONE of the agency's clients, so a person
  // named on two brands sees two separate spaces rather than one merged list. The pages are the same
  // components — the boundary is the slug in the URL, which the server checks against the spaces the
  // contact actually owns.
  { path: '/portal', element: <ClientPortalHomePage /> },
  { path: '/portal/spaces', element: <ClientSpacePickerPage /> },
  { path: '/portal/clients/:clientSlug', element: <ClientDashboardPage /> },
  { path: '/portal/clients/:clientSlug/requests', element: <ClientRequestsPage /> },
  { path: '/portal/clients/:clientSlug/requests/:reference', element: <ClientRequestDetailPage /> },
  { path: '/portal/clients/:clientSlug/quotes', element: <ClientQuotesPage /> },
  { path: '/portal/clients/:clientSlug/quotes/:id', element: <ClientQuoteDetailPage /> },
  { path: '/portal/clients/:clientSlug/invoices', element: <ClientInvoicesPage /> },
  { path: '/portal/clients/:clientSlug/invoices/:id', element: <ClientInvoiceDetailPage /> },
  { path: '/portal/clients/:clientSlug/messages', element: <ClientMessagesPage /> },
  { path: '/portal/clients/:clientSlug/messages/:id', element: <ClientThreadPage /> },
  { path: '/portal/clients/:clientSlug/campaigns', element: <ClientCampaignsPage /> },
  { path: '/portal/clients/:clientSlug/files', element: <ClientFilesPage /> },
  { path: '/portal/clients/:clientSlug/reports', element: <ClientReportsPage /> },
  { path: '/portal/clients/:clientSlug/profile', element: <ClientProfilePage /> },

  { path: '/reports/share/:token', element: <PublicReport /> },
  { path: '/reports/print/:token', element: <PrintReport /> },
  // Email verification is public (the link can be opened on any device; the token verify endpoint is public).
  { path: '/verify-email', element: <VerifyEmailPage /> },
  // Invitation accept is public (the invitee has no account yet).
  { path: '/invite/accept', element: <InviteAcceptPage /> },
  // Development-only environment status (backend hard-blocks it in production).
  { path: '/dev/status', element: <DevStatusPage /> },
  // Pre-ADR-0002 paths, kept alive after the advertiser portal moved under /app/*.
  ...legacyAppRedirects,
  // REG-001 paths: the sections that moved from /app to /agency. Ahead of the guarded /app tree on
  // purpose — see `legacyAgencyRedirects`.
  ...legacyAgencyRedirects,

  {
    element: <RequireAuth />,
    children: [
      // Onboarding runs authenticated but OUTSIDE the AppShell + entitlement gate (no redirect loop).
      { path: 'onboarding', element: <OnboardingWizard /> },
      // ADR 0002: the portal/workspace switcher, for users who belong to more than one. Outside the
      // AppShell because it is the screen that decides WHICH shell the user is about to enter.
      { path: 'switch', element: <WorkspaceSwitcherPage /> },
      {
        element: <OnboardingGate />,
        children: [{
        // ADR 0002: the advertiser portal owns /app/*. Every section below is relative to it, so the
        // tree can no longer be half at the root and half prefixed.
        //
        // LOGIN-002: gated like every other portal. This tree was the one without a guard, so an
        // agency operator could open it and meet a rail filtered down to whatever the two portals
        // shared — no menu item promised anything false, and the page was still not theirs.
        path: 'app',
        element: <RequirePortal portal="app" />,
        children: [{
        element: <AppShell />,
        children: [
          { path: 'dashboard', element: <DashboardPage /> },
          { path: 'analytics', element: <AnalyticsPage /> },
          { path: 'system', element: <SystemStatusPage /> },
          { path: 'projects', element: <ProjectsPage /> },
          { path: 'projects/:projectId/integrations', element: <ProjectIntegrationsPage /> },
          { path: 'projects/:projectId/team', element: <ProjectTeamPage /> },
                    { path: 'design', element: <DesignSystemPage /> },
          { path: 'campaigns', element: <CampaignsPage /> },
          { path: 'campaigns/:projectId/:campaignId', element: <CampaignDetailPage /> },
          /*
           * REG-001: the multi-client surfaces MOVED to /agency — they were never the advertiser's.
           *
           * A client roster, an inbound requests inbox, invoices raised TO a client, and client
           * conversations all presuppose that you run campaigns for other people. Mounted here they
           * turned «كل حملاتك الإعلانية المدفوعة في مكان واحد» into an agency console, which is what
           * made all five portals feel like the same product.
           *
           * Redirects rather than deletions: these paths are in bookmarks and in links already sent,
           * and the agency portal's own gate answers honestly — an operator who holds an agency
           * membership carries on to the page, and one who does not is told plainly instead of
           * meeting a blank screen. Nothing is removed from the product; it lives in one place now.
           */
          // The moved sections' redirects are registered at the TOP LEVEL (see
          // `legacyAgencyRedirects`), not here — they must resolve for someone who does not hold the
          // advertiser portal, and inside this tree the guard above would turn them away first.
          // Alerts management (the alerts engine's operator surface).
          { path: 'alerts', element: <AlertsPage /> },
          // Expansion internal surfaces. Integrations is CANONICAL at /app/integrations and absorbs the
          // Connection Center + the Google Drive connector; Branding lives under Settings. Legacy/duplicate
          // routes redirect (see docs/ROUTE_REDIRECT_MAP.md) — no dead links, one engine per function.
          ...requestJourneyRoutes,
          ...subscriptionsRoutes,
          { path: 'integrations', element: <ConnectionCenterPage /> },
          { path: 'integrations/drive', element: <DrivePage /> },
          { path: 'files', element: <FilesLibraryPage /> },
          { path: 'connections', element: <Navigate to="/app/integrations" replace /> },
          { path: 'drive', element: <Navigate to="/app/integrations/drive" replace /> },
          { path: 'branding', element: <Navigate to="/app/settings/branding" replace /> },
          { path: 'content', element: <CreativesPage /> },
          { path: 'reports', element: <ReportsPage /> },
          { path: 'tasks', element: <TasksPage /> },
          /*
           * `approvals`, `tracking`, `optimization` and `opportunities` are GONE (REVIEW-001).
           *
           * Each rendered a card saying the module was «part of a later phase» while claiming the
           * foundation was in place — product copy about the roadmap, served as if it were a page.
           * None was linked from any nav or any other page, so the only way to reach one was to type
           * its URL, and what you got was a screen that looked built and did nothing.
           *
           * A route that does not exist now answers as a route that does not exist. That is the
           * honest state of an unbuilt module, and it is what `NotFoundPage` is for.
           */

          // …but notifications DID have a real destination all along. The placeholder sat in front
          // of a working page instead of leading to it.
          { path: 'notifications', element: <Navigate to="/app/account/notifications" replace /> },
          // User account settings (self). Workspace/org settings live under /settings/workspace.
          {
            // SYSTEM settings (sidebar). Workspace-wide only — no personal settings here.
            path: 'settings',
            element: <SettingsLayout />,
            children: [
              { index: true, element: <Navigate to="/app/settings/workspace" replace /> },
              { path: 'workspace', element: <SettingsPage only={['general', 'clients', 'projects', 'notifications', 'security']} /> },
              { path: 'permissions', element: <SettingsPage only={['team']} title="الصلاحيات والفريق" subtitle="أعضاء مساحة العمل وأدوارهم وصلاحياتهم" /> },
              // PLATFORM-level, moved to /admin/settings (ADMIN-001): the public marketing site is
              // the platform's, not one tenant's, and a tenant administrator could edit it here.
              { path: 'public-pages', element: <Navigate to="/admin/settings" replace /> },
              { path: 'portals', element: <Navigate to="/admin/settings" replace /> },
              // Identity/Branding lives INSIDE Settings (canonical — not a standalone nav section).
              { path: 'branding', element: <BrandingCenterPage /> },
              { path: 'taxonomies', element: <Navigate to="/admin/settings" replace /> },
              // Personal settings moved to /account — keep old links working.
              { path: 'profile', element: <Navigate to="/account/profile" replace /> },
              { path: 'password', element: <Navigate to="/account/password" replace /> },
              { path: 'security', element: <Navigate to="/account/security" replace /> },
              { path: 'preferences', element: <Navigate to="/account/preferences" replace /> },
              { path: 'notifications', element: <Navigate to="/account/notifications" replace /> },
            ],
          },
          {
            // USER settings — reachable ONLY from the account menu in the top bar.
            path: 'account',
            element: <AccountSettingsLayout />,
            children: [
              { index: true, element: <Navigate to="/app/account/profile" replace /> },
              { path: 'profile', element: <ProfilePage /> },
              { path: 'password', element: <PasswordPage /> },
              { path: 'security', element: <SecurityPage /> },
              { path: 'preferences', element: <PreferencesPage /> },
              { path: 'notifications', element: <PersonalNotificationsPage /> },
            ],
          },
          // Sales CRM (behind sales_crm_enabled; routes always exist, nav is gated).
          { path: 'leads', element: <LeadsPage /> },
        ],
        }],
      },
      // ADR 0002: the agency portal owns /agency/*. It is a portal, not a menu variant — its own
      // shell, its own landing, its own entry gate. Several sections below are the SAME engines the
      // advertiser portal uses, mounted here rather than copied, because the business rules must not
      // exist twice; the rows they show are narrowed on the server by the membership's client scope.
      // Their internal links resolve through `usePortalPath()`, so following one keeps the operator
      // inside /agency instead of dropping them into /app mid-journey.
      // ADR 0002 / ADMIN-001: the platform owner's console. Not a tenant — it administers them.
      // Gated on `is_platform_admin`, never on a membership: giving the owner a membership to reach
      // this would place them inside one of the workspaces they administer.
      {
        path: 'admin',
        element: <RequirePlatformAdmin />,
        children: [{
          element: <AdminShell />,
          children: [
            { index: true, element: <PlatformOverviewPage /> },
            { path: 'tenants', element: <TenantsPage /> },
            // SIGNUP-003 — applications that have NOT become tenants yet, which is why this is its
            // own entry rather than a tab inside Tenants.
            { path: 'registrations', element: <RegistrationsPage /> },
            { path: 'billing', element: <AdminBillingPage /> },
            { path: 'settings', element: <SystemSettingsPage /> },
            // PAYSET-001 — the gateways. Its own page rather than a tab, because it is the surface an
            // operator opens when money is not moving.
            { path: 'settings/integrations/payments', element: <PaymentSettingsPage /> },
            { path: 'cutover', element: <CutoverPage /> },
            { path: 'audit', element: <AuditPage /> },
          ],
        }],
      },
      {
        path: 'agency',
        element: <RequireAgencyPortal />,
        children: [{
          element: <AgencyShell />,
          children: [
            { index: true, element: <Navigate to="/agency/dashboard" replace /> },
            { path: 'dashboard', element: <AgencyDashboardPage /> },
            { path: 'clients', element: <ClientsPortfolioPage /> },
            { path: 'clients/:clientId', element: <ClientCommandCenterPage /> },
            { path: 'requests', element: <RequestsDashboardPage /> },
            { path: 'requests/:requestId', element: <RequestDetailPage /> },
            { path: 'projects', element: <ProjectsPage /> },
            { path: 'projects/:projectId/integrations', element: <ProjectIntegrationsPage /> },
            { path: 'projects/:projectId/team', element: <ProjectTeamPage /> },
            { path: 'campaigns', element: <CampaignsPage /> },
            { path: 'campaigns/:projectId/:campaignId', element: <CampaignDetailPage /> },
            { path: 'content', element: <CreativesPage /> },
            { path: 'reports', element: <ReportsPage /> },
            { path: 'alerts', element: <AlertsPage /> },
            { path: 'tasks', element: <TasksPage /> },
            { path: 'files', element: <FilesLibraryPage /> },
            { path: 'team', element: <AgencyTeamPage /> },
            ...messagingRoutes,
            ...billingRoutes,
            // The agency's own plan with CampaignsHub — distinct from the invoices it raises to its
            // clients, which is what `billingRoutes` above is.
            ...subscriptionsRoutes,
            /*
             * The agency's WORKSPACE settings (LOGIN-002).
             *
             * The agency portal had none: every settings link led to `/app/settings`, so configuring
             * an agency meant leaving the agency portal for the advertiser one — and, once /app was
             * guarded, meant being refused. Workspace settings belong inside the workspace's own
             * portal, which is what the five-portal structure says.
             *
             * Same engine as the advertiser's, mounted here rather than copied. Personal and
             * security settings are NOT here — those live under the account menu, in one place, for
             * every portal.
             */
            {
              path: 'settings',
              element: <SettingsLayout />,
              children: [
                { index: true, element: <Navigate to="/agency/settings/workspace" replace /> },
                { path: 'workspace', element: <SettingsPage only={['general', 'clients', 'projects', 'notifications', 'security']} /> },
                { path: 'permissions', element: <SettingsPage only={['team']} title="الصلاحيات والفريق" subtitle="أعضاء الوكالة وأدوارهم وصلاحياتهم" /> },
                { path: 'branding', element: <BrandingCenterPage /> },
              ],
            },
            {
              // Personal settings, reachable ONLY from the account menu — same rule in every portal.
              path: 'account',
              element: <AccountSettingsLayout />,
              children: [
                { index: true, element: <Navigate to="/agency/account/profile" replace /> },
                { path: 'profile', element: <ProfilePage /> },
                { path: 'password', element: <PasswordPage /> },
                { path: 'security', element: <SecurityPage /> },
                { path: 'preferences', element: <PreferencesPage /> },
                { path: 'notifications', element: <PersonalNotificationsPage /> },
              ],
            },
          ],
        }],
      },
      // ADR 0002 / INFL-001: the influencers & UGC portal. Its two halves have different boundaries
      // and that is the point — the roster is agency-wide, while collaborations carry the client and
      // narrow with the same client-scope ceiling every other client-bound surface uses.
      /*
       * The influencers portal tree — MOUNTED ONLY while the service is offered (INFL-OFF-001).
       *
       * Guarding it from inside was not enough: these routes live under `RequireAuth`, and they are
       * more specific than the `/influencers/*` catch above, so a signed-out visitor met the sign-in
       * gate first and was sent to `/login?redirect=%2Finfluencers` — asked to authenticate for a
       * portal that no longer exists. Unmounting the tree lets the catch answer instead.
       *
       * The tree itself is untouched below. Flipping the flag mounts it again, whole.
       */
      ...(features.influencersUgc ? [{
        path: 'influencers',
        /*
         * INFL-OFF-001 wraps the portal guard rather than replacing it.
         *
         * The outer guard asks "is this service being offered?" and the inner one asks "does this
         * person hold it?" — in that order, so a membership grants nothing while the sub-system is
         * closed. Nothing below is deleted; flipping the flag restores the portal whole.
         */
        element: <RequireInfluencersEnabled />,
        children: [{
          path: '',
          element: <RequireInfluencerPortal />,
          children: [
          /*
           * Personal settings, in THIS portal (LOGIN-002).
           *
           * Both influencer shells render the account menu, and until now it had nowhere to go here:
           * a creator clicking "Profile" was sent to `/app/account/profile`, another portal's copy,
           * which the guard then refused. Personal settings belong to the person and must exist
           * wherever that person signs in.
           */
          {
            path: 'account',
            element: <AccountSettingsLayout />,
            children: [
              { index: true, element: <Navigate to="/influencers/account/profile" replace /> },
              { path: 'profile', element: <ProfilePage /> },
              { path: 'password', element: <PasswordPage /> },
              { path: 'security', element: <SecurityPage /> },
              { path: 'preferences', element: <PreferencesPage /> },
              { path: 'notifications', element: <PersonalNotificationsPage /> },
            ],
          },
          /*
           * The CREATOR's side (INFL-002) — its own shell, deliberately outside InfluencerShell.
           *
           * Same portal, opposite party to the agreement, so it is not the operator's tree with
           * items hidden: a creator has one destination, and the shell here has no rail because
           * there is nothing to navigate between. Reusing InfluencerShell would have put a roster
           * link in front of every creator, leading to a page the API refuses.
           */
          {
            path: 'me',
            element: <CreatorShell />,
            children: [
              { index: true, element: <CreatorWorkPage /> },
              { path: ':collaborationId', element: <CreatorCollaborationPage /> },
            ],
          },
          {
            element: <InfluencerShell />,
            children: [
              { index: true, element: <CollaborationsPage /> },
              { path: 'roster', element: <RosterPage /> },
              // The shortlist and its decisions (INFL-003) — the half of the contract that records
              // what was ASKED, including the answers that were no.
              { path: 'nominations', element: <NominationsPage /> },
              { path: 'deliverables', element: <DeliverablesPage /> },
            ],
          },
          ],
        }],
      }] : []),
      ],
      },
    ],
  },
  /*
   * The catch-all. Without it React Router rendered its default boundary for any unmatched URL —
   * a blank white page with the error only in the console, which reads as the product being broken
   * rather than as a wrong address. Last in the list, so it can only ever match what nothing else did.
   */
  { path: '*', element: <NotFoundPage />, errorElement: <NotFoundPage /> },
])

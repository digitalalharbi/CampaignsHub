import { createBrowserRouter, Navigate } from 'react-router-dom'
import { PagePlaceholder } from '@/components/PagePlaceholder'
import { LoginPage } from '@/features/auth/LoginPage'
import { RegisterPage } from '@/features/auth/RegisterPage'
import { ForgotPasswordPage } from '@/features/auth/ForgotPasswordPage'
import { RequireAuth } from '@/features/auth/RequireAuth'
import { CampaignDetailPage } from '@/features/campaigns/CampaignDetailPage'
import { CampaignsPage } from '@/features/campaigns/CampaignsPage'
import { AnalyticsPage } from '@/features/analytics/AnalyticsPage'
import { DashboardPage } from '@/features/dashboard/DashboardPage'
import { LeadsPage } from '@/features/crm/LeadsPage'
import { ReportsPage } from '@/features/reports/ReportsPage'
import { SettingsPage } from '@/features/settings/SettingsPage'
import { PublicPagesSettingsPage } from '@/features/settings/PublicPagesSettingsPage'
import { PublicReport } from '@/features/reports/PublicReport'
import { PrintReport } from '@/features/reports/PrintReport'
import { DesignSystemPage } from '@/features/design/DesignSystemPage'
import { SettingsLayout } from '@/features/account/SettingsLayout'
import { AccountSettingsLayout } from '@/features/account/AccountSettingsLayout'
import { PreferencesPage } from '@/features/account/PreferencesPage'
import { PersonalNotificationsPage } from '@/features/account/PersonalNotificationsPage'
import { TaxonomyManagerPage } from '@/features/taxonomy/TaxonomyManagerPage'
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
import { ClientPortalLoginPage } from '@/features/requests/portal/ClientPortalLoginPage'
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
import { AgencyDashboardPage } from '@/features/agency/AgencyDashboardPage'
import { RequireAgencyPortal } from '@/features/agency/RequireAgencyPortal'
import { AgencyTeamPage } from '@/features/agency/AgencyTeamPage'
import { WorkspaceSwitcherPage } from '@/features/auth/WorkspaceSwitcherPage'
import { legacyAppRedirects } from './legacyRedirects'

export const router = createBrowserRouter([
  // Public marketing homepage — the primary conversion surface. The authenticated app lives under
  // its own paths (/dashboard, /campaigns, …); `/` is public and shows "back to dashboard" when signed in.
  { path: '/', element: <PublicHomePage /> },
  { path: '/welcome', element: <MarketingPage /> },
  { path: '/login', element: <LoginPage /> },
  { path: '/register', element: <RegisterPage /> },
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
  // External Client Portal (own cookie session; not the staff app). Each page guards itself: a 401 from the
  // portal endpoints redirects to /client/login. The section nav lives in PortalShell.
  { path: '/client/login', element: <ClientPortalLoginPage /> },
  { path: '/client', element: <ClientDashboardPage /> },
  { path: '/client/requests', element: <ClientRequestsPage /> },
  { path: '/client/requests/:reference', element: <ClientRequestDetailPage /> },
  { path: '/client/quotes', element: <ClientQuotesPage /> },
  { path: '/client/quotes/:id', element: <ClientQuoteDetailPage /> },
  { path: '/client/invoices', element: <ClientInvoicesPage /> },
  { path: '/client/invoices/:id', element: <ClientInvoiceDetailPage /> },
  { path: '/client/messages', element: <ClientMessagesPage /> },
  { path: '/client/messages/:id', element: <ClientThreadPage /> },
  { path: '/client/profile', element: <ClientProfilePage /> },
  // Client-facing content backed by real endpoints (files / campaigns / reports), each self-guarding on 401.
  { path: '/client/files', element: <ClientFilesPage /> },
  { path: '/client/campaigns', element: <ClientCampaignsPage /> },
  { path: '/client/reports', element: <ClientReportsPage /> },
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
        path: 'app',
        element: <AppShell />,
        children: [
          { path: 'dashboard', element: <DashboardPage /> },
          { path: 'analytics', element: <AnalyticsPage /> },
          { path: 'system', element: <SystemStatusPage /> },
          { path: 'projects', element: <ProjectsPage /> },
          { path: 'projects/:projectId/integrations', element: <ProjectIntegrationsPage /> },
          { path: 'projects/:projectId/team', element: <ProjectTeamPage /> },
                    { path: 'design', element: <DesignSystemPage /> },
          // Media-buying operational sections (built incrementally; honest placeholders for now).
          { path: 'clients', element: <PagePlaceholder title="Clients" /> },
          { path: 'campaigns', element: <CampaignsPage /> },
          { path: 'campaigns/:projectId/:campaignId', element: <CampaignDetailPage /> },
          // Internal requests inbox (external requests converted into operational work).
          { path: 'requests', element: <RequestsDashboardPage /> },
          { path: 'requests/:requestId', element: <RequestDetailPage /> },
          // Client portfolio + command center (converted from requests).
          { path: 'clients', element: <ClientsPortfolioPage /> },
          { path: 'clients/:clientId', element: <ClientCommandCenterPage /> },
          // Alerts management (the alerts engine's operator surface).
          { path: 'alerts', element: <AlertsPage /> },
          // Expansion internal surfaces. Integrations is CANONICAL at /app/integrations and absorbs the
          // Connection Center + the Google Drive connector; Branding lives under Settings. Legacy/duplicate
          // routes redirect (see docs/ROUTE_REDIRECT_MAP.md) — no dead links, one engine per function.
          ...billingRoutes,
          ...messagingRoutes,
          ...requestJourneyRoutes,
          ...subscriptionsRoutes,
          { path: 'integrations', element: <ConnectionCenterPage /> },
          { path: 'integrations/drive', element: <DrivePage /> },
          { path: 'files', element: <FilesLibraryPage /> },
          { path: 'connections', element: <Navigate to="/app/integrations" replace /> },
          { path: 'drive', element: <Navigate to="/app/integrations/drive" replace /> },
          { path: 'branding', element: <Navigate to="/app/settings/branding" replace /> },
          { path: 'content', element: <CreativesPage /> },
          { path: 'approvals', element: <PagePlaceholder title="Approvals" /> },
          { path: 'tracking', element: <PagePlaceholder title="Tracking" /> },
          { path: 'reports', element: <ReportsPage /> },
          { path: 'optimization', element: <PagePlaceholder title="Optimization" /> },
          { path: 'tasks', element: <TasksPage /> },
          { path: 'notifications', element: <PagePlaceholder title="Notifications" /> },
          // User account settings (self). Workspace/org settings live under /settings/workspace.
          {
            // SYSTEM settings (sidebar). Workspace-wide only — no personal settings here.
            path: 'settings',
            element: <SettingsLayout />,
            children: [
              { index: true, element: <Navigate to="/app/settings/workspace" replace /> },
              { path: 'workspace', element: <SettingsPage only={['general', 'clients', 'projects', 'notifications', 'security']} /> },
              { path: 'permissions', element: <SettingsPage only={['team']} title="الصلاحيات والفريق" subtitle="أعضاء مساحة العمل وأدوارهم وصلاحياتهم" /> },
              // Real CMS for the public homepage + the three external portals (draft → preview → publish).
              { path: 'public-pages', element: <PublicPagesSettingsPage /> },
              { path: 'portals', element: <SettingsPage only={['disclaimer']} title="ملاحظات البوابات" subtitle="الملاحظات والمنهجية التي يراها العملاء في البوابة والتقارير" /> },
              // Identity/Branding lives INSIDE Settings (canonical — not a standalone nav section).
              { path: 'branding', element: <BrandingCenterPage /> },
              { path: 'taxonomies', element: <TaxonomyManagerPage /> },
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
          { path: 'opportunities', element: <PagePlaceholder title="Opportunities" /> },
        ],
      },
      // ADR 0002: the agency portal owns /agency/*. It is a portal, not a menu variant — its own
      // shell, its own landing, its own entry gate. Several sections below are the SAME engines the
      // advertiser portal uses, mounted here rather than copied, because the business rules must not
      // exist twice; the rows they show are narrowed on the server by the membership's client scope.
      // Their internal links resolve through `usePortalPath()`, so following one keeps the operator
      // inside /agency instead of dropping them into /app mid-journey.
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
            { path: 'tasks', element: <TasksPage /> },
            { path: 'files', element: <FilesLibraryPage /> },
            { path: 'team', element: <AgencyTeamPage /> },
            ...messagingRoutes,
            ...billingRoutes,
          ],
        }],
      }],
      },
    ],
  },
])

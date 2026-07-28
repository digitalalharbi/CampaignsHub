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
import { PublicReport } from '@/features/reports/PublicReport'
import { PrintReport } from '@/features/reports/PrintReport'
import { DesignSystemPage } from '@/features/design/DesignSystemPage'
import { SettingsLayout } from '@/features/account/SettingsLayout'
import { ProfilePage } from '@/features/account/ProfilePage'
import { PasswordPage } from '@/features/account/PasswordPage'
import { SecurityPage } from '@/features/account/SecurityPage'
import { MarketingPage } from '@/features/marketing/MarketingPage'
import { ProjectIntegrationsPage } from '@/features/projects/ProjectIntegrationsPage'
import { ProjectsPage } from '@/features/projects/ProjectsPage'
import { ProjectTeamPage } from '@/features/projects/ProjectTeamPage'
import { SystemStatusPage } from '@/features/system/SystemStatusPage'
import { PublicHomePage } from '@/features/marketing/PublicHomePage'
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
import { BrandingCenterPage } from '@/features/branding/BrandingCenterPage'
import { AppShell } from '@/layouts/AppShell'

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
  {
    element: <RequireAuth />,
    children: [
      // Onboarding runs authenticated but OUTSIDE the AppShell + entitlement gate (no redirect loop).
      { path: 'onboarding', element: <OnboardingWizard /> },
      {
        element: <OnboardingGate />,
        children: [{
        element: <AppShell />,
        children: [
          { path: 'dashboard', element: <DashboardPage /> },
          { path: 'analytics', element: <AnalyticsPage /> },
          { path: 'system', element: <SystemStatusPage /> },
          { path: 'projects', element: <ProjectsPage /> },
          { path: 'projects/:projectId/integrations', element: <ProjectIntegrationsPage /> },
          { path: 'projects/:projectId/team', element: <ProjectTeamPage /> },
          { path: 'integrations', element: <Navigate to="/app/integrations" replace /> },
          { path: 'design', element: <DesignSystemPage /> },
          // Media-buying operational sections (built incrementally; honest placeholders for now).
          { path: 'clients', element: <PagePlaceholder title="Clients" /> },
          { path: 'campaigns', element: <CampaignsPage /> },
          { path: 'campaigns/:projectId/:campaignId', element: <CampaignDetailPage /> },
          // Internal requests inbox (external requests converted into operational work).
          { path: 'app/requests', element: <RequestsDashboardPage /> },
          { path: 'app/requests/:requestId', element: <RequestDetailPage /> },
          // Client portfolio + command center (converted from requests).
          { path: 'app/clients', element: <ClientsPortfolioPage /> },
          { path: 'app/clients/:clientId', element: <ClientCommandCenterPage /> },
          // Alerts management (the alerts engine's operator surface).
          { path: 'app/alerts', element: <AlertsPage /> },
          // Expansion internal surfaces. Integrations is CANONICAL at /app/integrations and absorbs the
          // Connection Center + the Google Drive connector; Branding lives under Settings. Legacy/duplicate
          // routes redirect (see docs/ROUTE_REDIRECT_MAP.md) — no dead links, one engine per function.
          ...billingRoutes,
          ...messagingRoutes,
          ...requestJourneyRoutes,
          ...subscriptionsRoutes,
          { path: 'app/integrations', element: <ConnectionCenterPage /> },
          { path: 'app/integrations/drive', element: <DrivePage /> },
          { path: 'app/connections', element: <Navigate to="/app/integrations" replace /> },
          { path: 'app/drive', element: <Navigate to="/app/integrations/drive" replace /> },
          { path: 'app/branding', element: <Navigate to="/settings/branding" replace /> },
          { path: 'content', element: <PagePlaceholder title="Content" /> },
          { path: 'approvals', element: <PagePlaceholder title="Approvals" /> },
          { path: 'tracking', element: <PagePlaceholder title="Tracking" /> },
          { path: 'reports', element: <ReportsPage /> },
          { path: 'optimization', element: <PagePlaceholder title="Optimization" /> },
          { path: 'tasks', element: <PagePlaceholder title="Tasks" /> },
          { path: 'notifications', element: <PagePlaceholder title="Notifications" /> },
          // User account settings (self). Workspace/org settings live under /settings/workspace.
          {
            path: 'settings',
            element: <SettingsLayout />,
            children: [
              { index: true, element: <ProfilePage /> },
              { path: 'profile', element: <ProfilePage /> },
              { path: 'password', element: <PasswordPage /> },
              { path: 'security', element: <SecurityPage /> },
              // Identity/Branding lives INSIDE Settings (canonical — not a standalone nav section).
              { path: 'branding', element: <BrandingCenterPage /> },
              { path: 'preferences', element: <PagePlaceholder title="Preferences" /> },
              { path: 'notifications', element: <PagePlaceholder title="Notifications" /> },
              { path: 'workspace', element: <SettingsPage /> },
              { path: 'support', element: <PagePlaceholder title="Support" /> },
            ],
          },
          // Sales CRM (behind sales_crm_enabled; routes always exist, nav is gated).
          { path: 'leads', element: <LeadsPage /> },
          { path: 'opportunities', element: <PagePlaceholder title="Opportunities" /> },
        ],
      }],
      },
    ],
  },
])

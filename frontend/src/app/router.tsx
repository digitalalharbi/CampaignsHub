import { createBrowserRouter } from 'react-router-dom'
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
import { IntegrationsPage } from '@/features/integrations/IntegrationsPage'
import { MarketingPage } from '@/features/marketing/MarketingPage'
import { ProjectIntegrationsPage } from '@/features/projects/ProjectIntegrationsPage'
import { ProjectsPage } from '@/features/projects/ProjectsPage'
import { ProjectTeamPage } from '@/features/projects/ProjectTeamPage'
import { SystemStatusPage } from '@/features/system/SystemStatusPage'
import { PublicHomePage } from '@/features/marketing/PublicHomePage'
import { RequestIntakePage } from '@/features/requests/RequestIntakePage'
import { RequestTrackPage } from '@/features/requests/RequestTrackPage'
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
  { path: '/reports/share/:token', element: <PublicReport /> },
  { path: '/reports/print/:token', element: <PrintReport /> },
  {
    element: <RequireAuth />,
    children: [
      {
        element: <AppShell />,
        children: [
          { path: 'dashboard', element: <DashboardPage /> },
          { path: 'analytics', element: <AnalyticsPage /> },
          { path: 'system', element: <SystemStatusPage /> },
          { path: 'projects', element: <ProjectsPage /> },
          { path: 'projects/:projectId/integrations', element: <ProjectIntegrationsPage /> },
          { path: 'projects/:projectId/team', element: <ProjectTeamPage /> },
          { path: 'integrations', element: <IntegrationsPage /> },
          { path: 'design', element: <DesignSystemPage /> },
          // Media-buying operational sections (built incrementally; honest placeholders for now).
          { path: 'clients', element: <PagePlaceholder title="Clients" /> },
          { path: 'campaigns', element: <CampaignsPage /> },
          { path: 'campaigns/:projectId/:campaignId', element: <CampaignDetailPage /> },
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
      },
    ],
  },
])

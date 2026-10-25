import { createBrowserRouter } from 'react-router-dom'
import { LoginPage } from '@/features/auth/LoginPage'
import { RequireAuth } from '@/features/auth/RequireAuth'
import { CampaignDetailPage } from '@/features/campaigns/CampaignDetailPage'
import { CampaignsPage } from '@/features/campaigns/CampaignsPage'
import { LeadsPage } from '@/features/crm/LeadsPage'
import { DesignSystemPage } from '@/features/design/DesignSystemPage'
import { IntegrationsPage } from '@/features/integrations/IntegrationsPage'
import { MarketingPage } from '@/features/marketing/MarketingPage'
import { ProjectIntegrationsPage } from '@/features/projects/ProjectIntegrationsPage'
import { ProjectOverviewPage } from '@/features/projects/ProjectOverviewPage'
import { ProjectsPage } from '@/features/projects/ProjectsPage'
import { ProjectTeamPage } from '@/features/projects/ProjectTeamPage'
import { ReportsPage } from '@/features/reports/ReportsPage'
import { SystemStatusPage } from '@/features/system/SystemStatusPage'
import { ComingSoonPage } from '@/components/ComingSoonPage'
import { TasksPage } from '@/features/projects/TasksPage'
import { NotificationsPage } from '@/features/system/NotificationsPage'
import { SettingsPage } from '@/features/system/SettingsPage'
import { AppShell } from '@/layouts/AppShell'

export const router = createBrowserRouter([
  { path: '/welcome', element: <MarketingPage /> },
  { path: '/login', element: <LoginPage /> },
  {
    element: <RequireAuth />,
    children: [
      {
        path: '/',
        element: <AppShell />,
        children: [
          { index: true, element: <SystemStatusPage /> },
          { path: 'projects', element: <ProjectsPage /> },
          { path: 'projects/:projectId/overview', element: <ProjectOverviewPage /> },
          { path: 'projects/:projectId/integrations', element: <ProjectIntegrationsPage /> },
          { path: 'projects/:projectId/team', element: <ProjectTeamPage /> },
          { path: 'integrations', element: <IntegrationsPage /> },
          { path: 'design', element: <DesignSystemPage /> },
          // Media-buying operational sections
          { path: 'clients', element: <ComingSoonPage titleKey="Clients" /> },
          { path: 'campaigns', element: <CampaignsPage /> },
          { path: 'campaigns/:projectId/:campaignId', element: <CampaignDetailPage /> },
          { path: 'content', element: <ComingSoonPage titleKey="Content" /> },
          { path: 'approvals', element: <ComingSoonPage titleKey="Approvals" /> },
          { path: 'tracking', element: <ComingSoonPage titleKey="Tracking" /> },
          { path: 'reports', element: <ReportsPage /> },
          { path: 'optimization', element: <ComingSoonPage titleKey="Optimization" /> },
          { path: 'tasks', element: <TasksPage /> },
          { path: 'notifications', element: <NotificationsPage /> },
          { path: 'settings', element: <SettingsPage /> },
          // Sales CRM (behind sales_crm_enabled; routes always exist, nav is gated).
          { path: 'leads', element: <LeadsPage /> },
          { path: 'opportunities', element: <ComingSoonPage titleKey="Opportunities" /> },
        ],
      },
    ],
  },
])

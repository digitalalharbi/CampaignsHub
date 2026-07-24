import { createBrowserRouter } from 'react-router-dom'
import { PagePlaceholder } from '@/components/PagePlaceholder'
import { LoginPage } from '@/features/auth/LoginPage'
import { RequireAuth } from '@/features/auth/RequireAuth'
import { CampaignDetailPage } from '@/features/campaigns/CampaignDetailPage'
import { CampaignsPage } from '@/features/campaigns/CampaignsPage'
import { LeadsPage } from '@/features/crm/LeadsPage'
import { DesignSystemPage } from '@/features/design/DesignSystemPage'
import { IntegrationsPage } from '@/features/integrations/IntegrationsPage'
import { MarketingPage } from '@/features/marketing/MarketingPage'
import { ProjectIntegrationsPage } from '@/features/projects/ProjectIntegrationsPage'
import { ProjectsPage } from '@/features/projects/ProjectsPage'
import { ProjectTeamPage } from '@/features/projects/ProjectTeamPage'
import { SystemStatusPage } from '@/features/system/SystemStatusPage'
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
          { path: 'reports', element: <PagePlaceholder title="Reports" /> },
          { path: 'optimization', element: <PagePlaceholder title="Optimization" /> },
          { path: 'tasks', element: <PagePlaceholder title="Tasks" /> },
          { path: 'notifications', element: <PagePlaceholder title="Notifications" /> },
          { path: 'settings', element: <PagePlaceholder title="Settings" /> },
          // Sales CRM (behind sales_crm_enabled; routes always exist, nav is gated).
          { path: 'leads', element: <LeadsPage /> },
          { path: 'opportunities', element: <PagePlaceholder title="Opportunities" /> },
        ],
      },
    ],
  },
])

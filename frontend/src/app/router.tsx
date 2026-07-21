import { createBrowserRouter } from 'react-router-dom'
import { PagePlaceholder } from '@/components/PagePlaceholder'
import { LoginPage } from '@/features/auth/LoginPage'
import { RequireAuth } from '@/features/auth/RequireAuth'
import { LeadsPage } from '@/features/crm/LeadsPage'
import { DesignSystemPage } from '@/features/design/DesignSystemPage'
import { IntegrationsPage } from '@/features/integrations/IntegrationsPage'
import { SystemStatusPage } from '@/features/system/SystemStatusPage'
import { AppShell } from '@/layouts/AppShell'

export const router = createBrowserRouter([
  { path: '/login', element: <LoginPage /> },
  {
    element: <RequireAuth />,
    children: [
      {
        path: '/',
        element: <AppShell />,
        children: [
          { index: true, element: <SystemStatusPage /> },
          { path: 'leads', element: <LeadsPage /> },
          { path: 'clients', element: <PagePlaceholder title="Clients" /> },
          { path: 'campaigns', element: <PagePlaceholder title="Campaigns" /> },
          { path: 'integrations', element: <IntegrationsPage /> },
          { path: 'reports', element: <PagePlaceholder title="Reports" /> },
          { path: 'settings', element: <PagePlaceholder title="Settings" /> },
          { path: 'design', element: <DesignSystemPage /> },
        ],
      },
    ],
  },
])

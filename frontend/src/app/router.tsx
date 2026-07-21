import { createBrowserRouter } from 'react-router-dom'
import { PagePlaceholder } from '@/components/PagePlaceholder'
import { LoginPage } from '@/features/auth/LoginPage'
import { SystemStatusPage } from '@/features/system/SystemStatusPage'
import { AppShell } from '@/layouts/AppShell'

export const router = createBrowserRouter([
  { path: '/login', element: <LoginPage /> },
  {
    path: '/',
    element: <AppShell />,
    children: [
      { index: true, element: <SystemStatusPage /> },
      { path: 'clients', element: <PagePlaceholder title="Clients" /> },
      { path: 'campaigns', element: <PagePlaceholder title="Campaigns" /> },
      { path: 'reports', element: <PagePlaceholder title="Reports" /> },
      { path: 'settings', element: <PagePlaceholder title="Settings" /> },
    ],
  },
])

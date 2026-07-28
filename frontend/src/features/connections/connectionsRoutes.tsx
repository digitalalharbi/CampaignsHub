import type { ReactElement } from 'react'
import { ConnectionCenterPage } from './ConnectionCenterPage'

/**
 * Route fragment for the Connection Center. The orchestrator spreads this into the authenticated AppShell
 * children (relative paths), so the resulting URL is /app/connections. We DO NOT edit router.tsx.
 */
export const connectionsRoutes: { path: string; element: ReactElement }[] = [
  { path: 'app/connections', element: <ConnectionCenterPage /> },
]

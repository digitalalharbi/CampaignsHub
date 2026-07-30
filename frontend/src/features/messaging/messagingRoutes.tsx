import type { ReactElement } from 'react'
import { ThreadsPage } from './ThreadsPage'

/**
 * Route fragment for the internal Messaging area. The app orchestrator imports this array and mounts it under
 * the authenticated AppShell (it also owns the nav). Paths are absolute under /app.
 */
export const messagingRoutes: { path: string; element: ReactElement }[] = [
  { path: 'messages', element: <ThreadsPage /> },
]

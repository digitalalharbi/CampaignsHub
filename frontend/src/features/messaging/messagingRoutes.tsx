import type { ReactElement } from 'react'
import { lazyPage } from '@/app/lazyPage'

// PERF-RECOVERY-001 — a route table's pages load when the route is opened, like the router's own.
const ThreadsPage = lazyPage(() => import('./ThreadsPage'), 'ThreadsPage')

/**
 * Route fragment for the internal Messaging area. The app orchestrator imports this array and mounts it under
 * the authenticated AppShell (it also owns the nav). Paths are absolute under /app.
 */
export const messagingRoutes: { path: string; element: ReactElement }[] = [
  { path: 'messages', element: <ThreadsPage /> },
]

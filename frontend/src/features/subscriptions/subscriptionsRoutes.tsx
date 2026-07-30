import type { ReactElement } from 'react'
import { SubscriptionsPage } from './SubscriptionsPage'

/**
 * Route fragment for the internal Subscriptions area. The app orchestrator imports this array and mounts it
 * under the authenticated AppShell (it also owns the nav). Paths are absolute under /app.
 */
export const subscriptionsRoutes: { path: string; element: ReactElement }[] = [
  { path: 'subscriptions', element: <SubscriptionsPage /> },
]

import type { ReactElement } from 'react'
import { lazyPage } from '@/app/lazyPage'

// PERF-RECOVERY-001 — a route table's pages load when the route is opened, like the router's own.
const InvoicesPage = lazyPage(() => import('./InvoicesPage'), 'InvoicesPage')
const SubscriptionsPage = lazyPage(() => import('./SubscriptionsPage'), 'SubscriptionsPage')

/**
 * Route fragment for the internal Subscriptions area. The app orchestrator imports this array and mounts it
 * under the authenticated AppShell (it also owns the nav). Paths are absolute under /app.
 */
export const subscriptionsRoutes: { path: string; element: ReactElement }[] = [
  { path: 'subscriptions', element: <SubscriptionsPage /> },
  // CampaignsHub's own invoices to this customer — NOT the agency's invoices to its clients, which
  // live under /billing and answer to a different permission (SUBINV-001).
  { path: 'subscriptions/invoices', element: <InvoicesPage /> },
]

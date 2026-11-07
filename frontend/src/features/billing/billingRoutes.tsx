import type { ReactElement } from 'react'
import { lazyPage } from '@/app/lazyPage'

// PERF-RECOVERY-001 — a route table's pages load when the route is opened, like the router's own.
const QuotesPage = lazyPage(() => import('./QuotesPage'), 'QuotesPage')
const InvoicesPage = lazyPage(() => import('./InvoicesPage'), 'InvoicesPage')
const PaymentsPage = lazyPage(() => import('./PaymentsPage'), 'PaymentsPage')
const FinanceOverviewPage = lazyPage(() => import('./FinanceOverviewPage'), 'FinanceOverviewPage')

/**
 * Route fragment for the internal Billing area. The app orchestrator imports this array and mounts it under
 * the authenticated AppShell (it also owns the nav). Paths are absolute under /app.
 */
export const billingRoutes: { path: string; element: ReactElement }[] = [
  // FINANCE-001: the consolidated overview is the entry point; the three lists live beneath it.
  { path: 'finance', element: <FinanceOverviewPage /> },
  { path: 'billing', element: <FinanceOverviewPage /> },
  { path: 'billing/quotes', element: <QuotesPage /> },
  { path: 'billing/invoices', element: <InvoicesPage /> },
  { path: 'billing/payments', element: <PaymentsPage /> },
]

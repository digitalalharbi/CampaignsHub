import type { ReactElement } from 'react'
import { QuotesPage } from './QuotesPage'
import { InvoicesPage } from './InvoicesPage'
import { PaymentsPage } from './PaymentsPage'
import { FinanceOverviewPage } from './FinanceOverviewPage'

/**
 * Route fragment for the internal Billing area. The app orchestrator imports this array and mounts it under
 * the authenticated AppShell (it also owns the nav). Paths are absolute under /app.
 */
export const billingRoutes: { path: string; element: ReactElement }[] = [
  // FINANCE-001: the consolidated overview is the entry point; the three lists live beneath it.
  { path: 'app/finance', element: <FinanceOverviewPage /> },
  { path: 'app/billing', element: <FinanceOverviewPage /> },
  { path: 'app/billing/quotes', element: <QuotesPage /> },
  { path: 'app/billing/invoices', element: <InvoicesPage /> },
  { path: 'app/billing/payments', element: <PaymentsPage /> },
]

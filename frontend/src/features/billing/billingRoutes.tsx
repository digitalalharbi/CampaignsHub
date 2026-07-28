import type { ReactElement } from 'react'
import { QuotesPage } from './QuotesPage'
import { InvoicesPage } from './InvoicesPage'
import { PaymentsPage } from './PaymentsPage'

/**
 * Route fragment for the internal Billing area. The app orchestrator imports this array and mounts it under
 * the authenticated AppShell (it also owns the nav). Paths are absolute under /app.
 */
export const billingRoutes: { path: string; element: ReactElement }[] = [
  { path: 'app/billing', element: <QuotesPage /> },
  { path: 'app/billing/quotes', element: <QuotesPage /> },
  { path: 'app/billing/invoices', element: <InvoicesPage /> },
  { path: 'app/billing/payments', element: <PaymentsPage /> },
]

import { NavLink } from 'react-router-dom'
import { CreditCard, FileText, ReceiptText } from 'lucide-react'
import { useUi } from '@/stores/ui'

/**
 * Shared sub-navigation for the Billing area (Quotes / Invoices / Payments). The sidebar only links to
 * /app/billing, so these tabs are how the invoices and payments views become reachable. Self-contained copy.
 */
const COPY = {
  ar: { quotes: 'عروض الأسعار', invoices: 'الفواتير', payments: 'المدفوعات' },
  en: { quotes: 'Quotes', invoices: 'Invoices', payments: 'Payments' },
}

const TABS = [
  { to: '/app/billing', key: 'quotes', icon: FileText, end: true },
  { to: '/app/billing/invoices', key: 'invoices', icon: ReceiptText, end: false },
  { to: '/app/billing/payments', key: 'payments', icon: CreditCard, end: false },
] as const

export function BillingTabs() {
  const locale = useUi((s) => s.locale)
  const c = COPY[locale]
  return (
    <div className="flex flex-wrap gap-1 border-b border-border">
      {TABS.map((t) => (
        <NavLink
          key={t.to}
          to={t.to}
          end={t.end}
          className={({ isActive }) =>
            `flex items-center gap-2 rounded-t-lg px-3 py-2 text-sm font-semibold transition-colors ${
              isActive ? 'border-b-2 border-brand-600 text-brand-600' : 'text-text-secondary hover:text-text-primary'
            }`
          }
        >
          <t.icon size={16} /> {c[t.key]}
        </NavLink>
      ))}
    </div>
  )
}

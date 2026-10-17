import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { ArrowRight, MessageCircleQuestion, Receipt } from 'lucide-react'
import { fetchPortalOverview } from './portalOverviewApi'
import { useClientSpacePath } from './clientSpace'
import { useUi } from '@/stores/ui'

/**
 * CP-DASH-001 — «what is waiting on me», above everything else on the client's dashboard.
 *
 * Only what needs the client's action: requests the agency is waiting on them for, and invoices past
 * their due date (or due). Each line links to the list that holds it. Nothing to do draws nothing at
 * all — an empty "all clear" box is noise on the page a client opens every week — and a count the
 * server did not send (the section is off for this client) is simply not offered.
 */
export function PortalAttention() {
  const ar = useUi((s) => s.locale) === 'ar'
  const spaceTo = useClientSpacePath()
  // Shares its key with the portfolio below: one request, two readers.
  const overview = useQuery({ queryKey: ['portal', 'overview'], queryFn: () => fetchPortalOverview(), retry: false })
  const a = overview.data?.attention

  const items = [
    a?.requests_waiting ? {
      key: 'requests', to: spaceTo('/requests'), icon: MessageCircleQuestion, tone: 'warning' as const,
      text: ar ? `${a.requests_waiting} ${a.requests_waiting === 1 ? 'طلب ينتظر ردّك' : 'طلبات تنتظر ردّك'}` : `${a.requests_waiting} ${a.requests_waiting === 1 ? 'request is' : 'requests are'} waiting on you`,
    } : null,
    a?.invoices_overdue ? {
      key: 'overdue', to: spaceTo('/invoices'), icon: Receipt, tone: 'danger' as const,
      text: ar ? `${a.invoices_overdue} ${a.invoices_overdue === 1 ? 'فاتورة متأخرة' : 'فواتير متأخرة'}` : `${a.invoices_overdue} ${a.invoices_overdue === 1 ? 'invoice is' : 'invoices are'} overdue`,
    } : null,
    a?.invoices_due ? {
      key: 'due', to: spaceTo('/invoices'), icon: Receipt, tone: 'warning' as const,
      text: ar ? `${a.invoices_due} ${a.invoices_due === 1 ? 'فاتورة مستحقة' : 'فواتير مستحقة'}` : `${a.invoices_due} ${a.invoices_due === 1 ? 'invoice is' : 'invoices are'} due`,
    } : null,
  ].filter((x): x is NonNullable<typeof x> => x !== null)

  if (items.length === 0) return null

  return (
    <section data-testid="portal-attention" aria-label={ar ? 'يحتاج انتباهك' : 'Needs your attention'} className="mb-5 grid gap-2">
      <h2 className="text-sm font-bold text-text-primary">{ar ? 'يحتاج انتباهك' : 'Needs your attention'}</h2>
      {items.map((i) => (
        <Link
          key={i.key}
          to={i.to}
          data-testid={`attention-${i.key}`}
          className={`flex items-center justify-between gap-3 rounded-2xl border px-4 py-3 text-sm font-semibold hover:opacity-90 ${
            i.tone === 'danger' ? 'border-danger/40 bg-[var(--negative-background)] text-danger' : 'border-warning/40 bg-[var(--warning-background)] text-text-primary'
          }`}
        >
          <span className="flex items-center gap-2"><i.icon size={16} aria-hidden /> {i.text}</span>
          <ArrowRight size={14} className="rtl:rotate-180" aria-hidden />
        </Link>
      ))}
    </section>
  )
}

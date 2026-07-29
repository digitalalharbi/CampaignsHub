import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { listInvoices, listQuotes } from '@/features/billing/api'
import { TaxTreatmentChip } from '@/features/billing/QuotesPage'
import { useUi } from '@/stores/ui'

/**
 * Client billing tab — this client's quotes + invoices, filtered from the tenant billing ledger
 * (both models carry client_workspace_id). Read-only rollup; actions live in the Billing section.
 */
export function TabBilling({ clientId }: { clientId: string }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const quotesQ = useQuery({ queryKey: ['billing', 'quotes'], queryFn: listQuotes })
  const invoicesQ = useQuery({ queryKey: ['billing', 'invoices', 'all'], queryFn: () => listInvoices() })

  const quotes = (quotesQ.data ?? []).filter((q) => q.client_workspace_id === clientId)
  const invoices = (invoicesQ.data ?? []).filter((i) => i.client_workspace_id === clientId)
  const outstanding = invoices.reduce((s, i) => s + Math.max(0, Number(i.total) - Number(i.amount_paid)), 0)

  if (quotesQ.isLoading || invoicesQ.isLoading) return <div className="h-40 animate-pulse rounded-xl bg-surface-secondary" />
  if (quotesQ.isError || invoicesQ.isError) return <p className="rounded-xl border border-danger/30 bg-danger/5 p-6 text-center text-sm text-danger">{ar ? 'تعذّر تحميل بيانات الفوترة.' : 'Could not load billing data.'}</p>

  return (
    <div className="space-y-4 text-sm">
      <div className="grid grid-cols-3 gap-3">
        {[[ar ? 'عروض الأسعار' : 'Quotes', quotes.length], [ar ? 'الفواتير' : 'Invoices', invoices.length], [ar ? 'المتبقي' : 'Outstanding', `${outstanding.toLocaleString('en-US')} SAR`]].map(([l, v]) => (
          <div key={String(l)} className="rounded-xl border border-border bg-surface-secondary p-4 text-center">
            <div className="tnum text-xl font-extrabold text-text-primary" dir="ltr">{v as string}</div>
            <div className="mt-1 text-xs text-text-muted">{l as string}</div>
          </div>
        ))}
      </div>

      {quotes.length === 0 && invoices.length === 0 ? (
        <p className="rounded-xl border border-dashed border-border p-8 text-center text-text-secondary">
          {ar ? 'لا فواتير أو عروض أسعار لهذا العميل بعد.' : 'No quotes or invoices for this client yet.'}
        </p>
      ) : (
        <div className="grid gap-4 md:grid-cols-2">
          <section>
            <h3 className="mb-2 font-bold text-text-primary">{ar ? 'عروض الأسعار' : 'Quotes'}</h3>
            <ul className="space-y-2">
              {quotes.slice(0, 8).map((q) => (
                <li key={q.id}>
                  <Link to="/app/billing" className="flex items-center justify-between gap-2 rounded-xl border border-border p-3 hover:border-brand-400">
                    <span className="font-mono text-xs font-semibold text-brand-600" dir="ltr">{q.number}</span>
                    <span className="flex items-center gap-2">
                      <TaxTreatmentChip treatment={q.tax_treatment} ar={ar} />
                      <span className="tnum font-bold" dir="ltr">{Number(q.total).toLocaleString('en-US')} {q.currency}</span>
                    </span>
                  </Link>
                </li>
              ))}
              {quotes.length === 0 && <li className="text-text-muted">—</li>}
            </ul>
          </section>
          <section>
            <h3 className="mb-2 font-bold text-text-primary">{ar ? 'الفواتير' : 'Invoices'}</h3>
            <ul className="space-y-2">
              {invoices.slice(0, 8).map((i) => (
                <li key={i.id}>
                  <Link to="/app/billing/invoices" className="flex items-center justify-between gap-2 rounded-xl border border-border p-3 hover:border-brand-400">
                    <span className="font-mono text-xs font-semibold text-brand-600" dir="ltr">{i.number}</span>
                    <span className="flex items-center gap-2">
                      <span className="rounded-full bg-surface-hover px-2 py-0.5 text-[10px] font-semibold text-text-secondary">{i.status}</span>
                      <span className="tnum font-bold" dir="ltr">{Number(i.total).toLocaleString('en-US')} {i.currency}</span>
                    </span>
                  </Link>
                </li>
              ))}
              {invoices.length === 0 && <li className="text-text-muted">—</li>}
            </ul>
          </section>
        </div>
      )}
    </div>
  )
}

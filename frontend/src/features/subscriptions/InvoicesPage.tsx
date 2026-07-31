import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Download, Link2, Loader2, Unlink } from 'lucide-react'
import {
  listSubscriptionInvoices, revokeSubscriptionInvoiceShare, shareSubscriptionInvoice,
  subscriptionInvoiceDownloadUrl, type SubscriptionInvoice,
} from './api'
import { toApiError } from '@/lib/api/client'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'

/**
 * The customer's own CampaignsHub invoices (SUBINV-001).
 *
 * Deliberately separate from `/app/billing`, which is what an AGENCY invoices its clients. Putting
 * both on one screen would mean a customer's own subscription bills sat behind the permission that
 * governs their clients' bills — and would make "revenue" a number that answers neither question.
 */

const COPY = {
  ar: {
    title: 'فواتير الاشتراك',
    subtitle: 'فواتير CampaignsHub لك. فواتيرك لعملائك في قسم الفوترة.',
    empty: 'لا توجد فواتير بعد.',
    number: 'رقم الفاتورة', issued: 'تاريخ الإصدار', total: 'الإجمالي', outstanding: 'المتبقي', status: 'الحالة',
    statuses: { issued: 'صادرة', paid: 'مدفوعة', refunded: 'مستردة', void: 'ملغاة' } as Record<string, string>,
    tax: 'الضريبة', download: 'تنزيل', share: 'مشاركة', revoke: 'إلغاء المشاركة', shared: 'رابط المشاركة',
  },
  en: {
    title: 'Subscription invoices',
    subtitle: 'What CampaignsHub billed you. Your own invoices to your clients are under Billing.',
    empty: 'No invoices yet.',
    number: 'Number', issued: 'Issued', total: 'Total', outstanding: 'Outstanding', status: 'Status',
    statuses: { issued: 'Issued', paid: 'Paid', refunded: 'Refunded', void: 'Void' } as Record<string, string>,
    tax: 'Tax', download: 'Download', share: 'Share', revoke: 'Revoke link', shared: 'Share link',
  },
} as const

export function InvoicesPage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const c = COPY[ar ? 'ar' : 'en']
  const queryClient = useQueryClient()
  const canManage = useAuth((s) => s.hasPermission('subscriptions.manage'))

  const invoices = useQuery({ queryKey: ['subscription-invoices'], queryFn: listSubscriptionInvoices })
  const refresh = () => void queryClient.invalidateQueries({ queryKey: ['subscription-invoices'] })

  const share = useMutation({ mutationFn: shareSubscriptionInvoice, onSuccess: refresh })
  const revoke = useMutation({ mutationFn: revokeSubscriptionInvoiceShare, onSuccess: refresh })

  if (invoices.isPending) {
    return <div className="flex justify-center p-10"><Loader2 className="animate-spin text-brand-600" /></div>
  }

  if (invoices.isError) {
    return <p className="text-sm text-danger">{toApiError(invoices.error).message}</p>
  }

  const rows = invoices.data?.invoices ?? []

  return (
    <div data-testid="subscription-invoices" className="flex flex-col gap-4">
      <header>
        <h1 className="font-heading text-2xl font-extrabold text-text-primary">{c.title}</h1>
        <p className="mt-1 text-sm text-text-secondary">{c.subtitle}</p>
      </header>

      {rows.length === 0 ? (
        <p data-testid="subscription-invoices-empty" className="rounded-2xl border border-border bg-surface p-6 text-sm text-text-secondary">{c.empty}</p>
      ) : (
        <div className="overflow-x-auto rounded-2xl border border-border bg-surface">
          <table className="w-full min-w-[44rem] text-sm">
            <thead className="border-b border-border text-xs text-text-muted">
              <tr>
                <th className="p-3 text-start font-semibold">{c.number}</th>
                <th className="p-3 text-start font-semibold">{c.issued}</th>
                <th className="p-3 text-start font-semibold">{c.total}</th>
                <th className="p-3 text-start font-semibold">{c.outstanding}</th>
                <th className="p-3 text-start font-semibold">{c.status}</th>
                <th className="p-3 text-start font-semibold" />
              </tr>
            </thead>
            <tbody>
              {rows.map((invoice) => (
                <Row
                  key={invoice.id}
                  invoice={invoice}
                  copy={c}
                  canManage={canManage}
                  onShare={() => share.mutate(invoice.id)}
                  onRevoke={() => revoke.mutate(invoice.id)}
                />
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}

function Row({
  invoice, copy, canManage, onShare, onRevoke,
}: {
  invoice: SubscriptionInvoice
  copy: typeof COPY['en'] | typeof COPY['ar']
  canManage: boolean
  onShare: () => void
  onRevoke: () => void
}) {
  return (
    <tr data-testid="subscription-invoice-row" data-status={invoice.status} className="border-b border-border last:border-0">
      <td className="p-3 font-mono font-semibold text-text-primary" dir="ltr">{invoice.number}</td>
      <td className="p-3 text-text-secondary" dir="ltr">{invoice.issued_at?.slice(0, 10) ?? '—'}</td>
      <td className="p-3 text-text-primary" dir="ltr">
        {invoice.total} {invoice.currency}
        {/* The treatment is named, not only the amount: `zero_rated` and `exempt` both compute to
            zero and are different statements to a tax authority. */}
        <span className="block text-[11px] text-text-muted">
          {copy.tax}: {invoice.tax_total} ({invoice.tax_treatment})
        </span>
      </td>
      <td className="p-3 text-text-secondary" dir="ltr">{invoice.outstanding} {invoice.currency}</td>
      <td className="p-3">
        <span className={`rounded-lg px-2 py-0.5 text-xs font-semibold ${invoice.status === 'paid' ? 'bg-[var(--positive-background)] text-[var(--positive-foreground)]' : 'bg-surface-secondary text-text-secondary'}`}>
          {copy.statuses[invoice.status] ?? invoice.status}
        </span>
      </td>
      <td className="p-3">
        <div className="flex flex-wrap items-center gap-2">
          {/* A real endpoint, so this is a real link rather than a rendered blob. */}
          <a
            data-testid={`invoice-download-${invoice.number}`}
            href={subscriptionInvoiceDownloadUrl(invoice.id)}
            className="flex items-center gap-1 text-xs font-semibold text-brand-600 hover:underline"
          >
            <Download size={13} /> {copy.download}
          </a>

          {canManage && (invoice.is_shared ? (
            <button type="button" data-testid={`invoice-revoke-${invoice.number}`} onClick={onRevoke}
              className="flex items-center gap-1 text-xs font-semibold text-text-secondary hover:text-danger">
              <Unlink size={13} /> {copy.revoke}
            </button>
          ) : (
            <button type="button" data-testid={`invoice-share-${invoice.number}`} onClick={onShare}
              className="flex items-center gap-1 text-xs font-semibold text-text-secondary hover:text-text-primary">
              <Link2 size={13} /> {copy.share}
            </button>
          ))}
        </div>

        {invoice.share_url && (
          <span data-testid={`invoice-share-url-${invoice.number}`} className="mt-1 block break-all text-[11px] text-text-muted" dir="ltr">
            {invoice.share_url}
          </span>
        )}
      </td>
    </tr>
  )
}

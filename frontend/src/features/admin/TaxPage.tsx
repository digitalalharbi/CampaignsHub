import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { fetchInvoiceReview, fetchTax, updateTaxEdition, type TaxEdition } from './api'
import { Button } from '@/components/ui/Button'
import { Num } from '@/components/ui/Num'
import { ErrorState, Skeleton } from '@/components/ui/States'
import { toApiError } from '@/lib/api/client'
import { fmtDate } from '@/lib/datetime'
import { useUi } from '@/stores/ui'

const TREATMENT = {
  basic_15: { ar: 'ضريبة قياسية 15%', en: 'Standard VAT 15%' },
  zero_rated: { ar: 'خاضع بنسبة صفر', en: 'Zero-rated' },
  exempt: { ar: 'معفى', en: 'Exempt' },
  out_of_scope: { ar: 'خارج نطاق الضريبة', en: 'Out of scope' },
  historical_5: { ar: '5% (تاريخي)', en: '5% (historical)' },
} as Record<string, { ar: string; en: string }>

const ISSUE = {
  total_differs_from_charge: { ar: 'إجمالي الفاتورة يختلف عن المبلغ المطلوب', en: 'Total differs from the charge' },
  paid_amount_differs_from_collected: { ar: 'المدفوع المسجّل يختلف عن المحصّل', en: 'Recorded paid ≠ collected' },
  paid_without_collection: { ar: 'مسجّلة مدفوعة دون تحصيل', en: 'Marked paid, not collected' },
  no_tax_treatment: { ar: 'بلا معالجة ضريبية', en: 'No tax treatment' },
} as Record<string, { ar: string; en: string }>

/**
 * `/admin/tax` — PRICING-VAT-002: the VAT treatment and price basis of each catalogue edition, and the invoices that need an
 * accountant. A change applies to new sales only — every subscription keeps the terms it was sold under — and only the legal
 * treatments can be chosen. The review is read-only: it flags documents and changes none.
 */
export function TaxPage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const tax = useQuery({ queryKey: ['admin', 'tax'], queryFn: fetchTax })
  const review = useQuery({ queryKey: ['admin', 'tax-review'], queryFn: fetchInvoiceReview })

  return (
    <div data-testid="admin-tax" className="w-full">
      <header className="mb-5">
        <h1 className="font-heading text-3xl font-extrabold tracking-tight text-text-primary">{ar ? 'ضريبة القيمة المضافة' : 'VAT'}</h1>
        <p className="mt-1 max-w-2xl text-sm text-text-secondary">
          {ar ? 'المعالجة الضريبية لكل إصدار من الباقات وهل أسعاره تشمل الضريبة. التغيير يسري على المبيعات الجديدة فقط؛ كل اشتراك قائم يحتفظ بشروطه.'
            : 'The VAT treatment of each catalogue edition and whether its prices include VAT. A change applies to new sales only; every existing subscription keeps its terms.'}
        </p>
      </header>
      {tax.isPending && <Skeleton className="h-32" />}
      {tax.isError && <ErrorState error={tax.error} ar={ar} title={ar ? 'تعذّر التحميل.' : 'Could not load.'} onRetry={() => void tax.refetch()} />}
      {tax.data && (
        <ul className="grid gap-2">
          {tax.data.editions.map((e) => <EditionRow key={e.id} e={e} ar={ar} treatments={tax.data.treatments.map((t) => t.key)} />)}
        </ul>
      )}
      {(tax.data?.needs_customer_eligibility ?? []).length > 0 && (
        <p data-testid="tax-eligibility-note" className="mt-3 max-w-2xl text-[12px] text-text-muted">
          {ar ? 'غير متاحة على مستوى الإصدار: ' : 'Not available for a whole edition: '}
          {(tax.data?.needs_customer_eligibility ?? []).map((t) => (ar ? TREATMENT[t]?.ar : TREATMENT[t]?.en) ?? t).join('، ')}
          {ar ? ' — تعتمد على أهلية العميل (إقامته وإثباتها) ولا تُطبَّق على كتالوج كامل.'
            : ' — each depends on the customer’s eligibility (residence and evidence of it) and never applies to a whole catalogue.'}
        </p>
      )}

      <section data-testid="tax-review" className="mt-8">
        <h2 className="font-heading text-lg font-bold text-text-primary">{ar ? 'فواتير تحتاج مراجعة محاسبية' : 'Invoices for accounting review'}</h2>
        <p className="mt-1 max-w-2xl text-[13px] text-text-secondary">
          {ar ? 'قراءة فقط: لا تُعدَّل أي فاتورة تاريخية تلقائيًا. التصحيح، إن لزم، بإشعار دائن يقرره المحاسب.'
            : 'Read-only: no historical invoice is changed automatically. A correction, if any, is a credit note an accountant decides on.'}
        </p>
        {review.data && (
          <>
            <p data-testid="tax-review-summary" className="mt-2 text-sm text-text-primary">
              {ar ? 'فُحصت' : 'Checked'} <Num>{review.data.checked}</Num> · {ar ? 'تحتاج مراجعة' : 'need review'} <Num>{review.data.flagged}</Num>
            </p>
            {review.data.rows.length > 0 && (
              <div className="mt-2 overflow-x-auto rounded-xl border border-border">
                <table className="w-full min-w-[640px] text-[13px]">
                  <thead className="bg-surface-secondary text-start text-text-muted">
                    <tr>
                      <th className="px-3 py-2 text-start">{ar ? 'الفاتورة' : 'Invoice'}</th>
                      <th className="px-3 py-2 text-start">{ar ? 'الإجمالي' : 'Total'}</th>
                      <th className="px-3 py-2 text-start">{ar ? 'المطلوب' : 'Charged'}</th>
                      <th className="px-3 py-2 text-start">{ar ? 'الملاحظة' : 'Issue'}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {review.data.rows.map((r) => (
                      <tr key={r.invoice_id} data-testid={`tax-review-${r.number}`} className="border-t border-border">
                        <td className="px-3 py-2 text-text-primary"><bdi>{r.number}</bdi> <span className="text-text-muted">{r.tenant}</span>
                          {r.issued_at && <span className="ms-2 text-[11px] text-text-muted">{fmtDate(r.issued_at)}</span>}</td>
                        <td className="px-3 py-2"><Num>{r.total}</Num> {r.currency}</td>
                        <td className="px-3 py-2">{r.charged === null ? '—' : <><Num>{r.charged}</Num> {r.currency}</>}</td>
                        <td className="px-3 py-2 text-warning">{r.issues.map((i) => (ar ? ISSUE[i]?.ar : ISSUE[i]?.en) ?? i).join(' · ')}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </>
        )}
      </section>
    </div>
  )
}

function EditionRow({ e, ar, treatments }: { e: TaxEdition; ar: boolean; treatments: string[] }) {
  const qc = useQueryClient()
  const [treatment, setTreatment] = useState(e.tax_treatment)
  const [inclusive, setInclusive] = useState(e.prices_include_vat)
  const [reason, setReason] = useState('')
  const save = useMutation({
    mutationFn: () => updateTaxEdition(e.id, { tax_treatment: treatment, prices_include_vat: inclusive, reason: reason.trim() }),
    onSuccess: () => { setReason(''); void qc.invalidateQueries({ queryKey: ['admin', 'tax'] }) },
  })
  const changed = treatment !== e.tax_treatment || inclusive !== e.prices_include_vat

  return (
    <li data-testid={`tax-edition-${e.number}`} className="grid gap-2 rounded-xl border border-border bg-surface px-4 py-3">
      <div className="flex flex-wrap items-center gap-2">
        <span className="font-semibold text-text-primary">{e.label}</span>
        <span className="rounded-full bg-surface-secondary px-2 py-0.5 text-[11px] text-text-secondary">{e.currency} · {e.status}</span>
        <span className="text-[12px] text-text-muted">
          {ar ? `${e.subscriptions_keeping_their_terms} اشتراك قائم يحتفظ بشروطه` : `${e.subscriptions_keeping_their_terms} existing subscriptions keep their terms`}
        </span>
      </div>
      <div className="flex flex-wrap items-center gap-2 text-sm">
        <select data-testid={`tax-treatment-${e.number}`} value={treatment} onChange={(ev) => setTreatment(ev.target.value)}
          className="h-9 rounded-lg border border-border bg-surface px-2 text-sm">
          {treatments.map((t) => <option key={t} value={t}>{ar ? TREATMENT[t]?.ar : TREATMENT[t]?.en}</option>)}
        </select>
        <select data-testid={`tax-basis-${e.number}`} value={inclusive ? 'inclusive' : 'exclusive'} onChange={(ev) => setInclusive(ev.target.value === 'inclusive')}
          className="h-9 rounded-lg border border-border bg-surface px-2 text-sm">
          <option value="exclusive">{ar ? 'الأسعار قبل الضريبة (تُضاف عند الدفع)' : 'Prices exclude VAT (added at payment)'}</option>
          <option value="inclusive">{ar ? 'الأسعار شاملة الضريبة' : 'Prices include VAT'}</option>
        </select>
        <input data-testid={`tax-reason-${e.number}`} value={reason} onChange={(ev) => setReason(ev.target.value)} placeholder={ar ? 'السبب' : 'Reason'}
          className="h-9 min-w-0 flex-1 rounded-lg border border-border bg-surface px-2 text-sm" />
        <Button data-testid={`tax-save-${e.number}`} size="sm" disabled={!changed || reason.trim().length < 5 || save.isPending} onClick={() => save.mutate()}>
          {ar ? 'حفظ' : 'Save'}
        </Button>
      </div>
      {save.isError && <p className="text-xs font-semibold text-danger">{toApiError(save.error).message}</p>}
    </li>
  )
}

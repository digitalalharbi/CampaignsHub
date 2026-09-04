import { useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { AlertTriangle, ChevronDown, ChevronUp, CreditCard, Play } from 'lucide-react'
import { useUi } from '@/stores/ui'
import { useAuth } from '@/stores/auth'
import { BillingTabs } from './BillingTabs'
import {
  formatDateTime, formatMoney, isPayable, listInvoices, paymentDisplayState, startPayment,
  type Invoice, type Payment, type PaymentDisplayState,
} from './api'

const COPY = {
  ar: {
    title: 'المدفوعات', subtitle: 'افتح جلسة دفع لفاتورة صادرة وتابع محاولات المزوّد. لا يُسجَّل أي سداد إلا بعد إشعار موثّق من المزوّد.',
    honest: 'لا يوجد مسار خلفي لسرد كل المدفوعات بعد؛ تعرض هذه الصفحة الجلسات التي تبدؤها هنا. مع عدم ربط مزوّد حقيقي تبقى الجلسة «بانتظار اعتماد المزوّد» ولا يُحصّل أي مبلغ.',
    payable_title: 'فواتير قابلة للسداد', none_payable: 'لا توجد فواتير قابلة للسداد حاليًا.',
    start: 'بدء جلسة دفع', starting: 'جارٍ الفتح…', loading: 'جارٍ التحميل…', error: 'تعذّر تحميل الفواتير.',
    sessions_title: 'جلسات الدفع (هذه الجلسة)', no_sessions: 'لم تبدأ أي جلسة دفع بعد.',
    provider: 'المزوّد', amount: 'المبلغ', attempts: 'محاولات المزوّد', attempt: 'محاولة',
    when: 'الوقت', session_id: 'معرّف الجلسة', error_col: 'الخطأ', state: 'الحالة',
    show_attempts: 'عرض المحاولات', hide_attempts: 'إخفاء المحاولات', invoice: 'الفاتورة',
  },
  en: {
    title: 'Payments', subtitle: 'Open a payment session for an issued invoice and track provider attempts. No payment is settled without a verified provider webhook.',
    honest: 'There is no backend endpoint to list all payments yet; this page shows the sessions you start here. With no real provider wired, a session stays "awaiting provider credentials" and nothing is charged.',
    payable_title: 'Payable invoices', none_payable: 'No payable invoices right now.',
    start: 'Start payment session', starting: 'Opening…', loading: 'Loading…', error: 'Could not load invoices.',
    sessions_title: 'Payment sessions (this session)', no_sessions: 'No payment sessions started yet.',
    provider: 'Provider', amount: 'Amount', attempts: 'Provider attempts', attempt: 'Attempt',
    when: 'When', session_id: 'Session id', error_col: 'Error', state: 'State',
    show_attempts: 'Show attempts', hide_attempts: 'Hide attempts', invoice: 'Invoice',
  },
}

/** Bilingual label + tone for a payment's honest display state. */
export const PAYMENT_STATE: Record<string, { ar: string; en: string; tone: string }> = {
  awaiting_provider_credentials: { ar: 'بانتظار اعتماد المزوّد', en: 'Awaiting provider credentials', tone: 'bg-warning/15 text-warning' },
  pending: { ar: 'قيد الانتظار', en: 'Pending', tone: 'bg-info/15 text-info' },
  processing: { ar: 'قيد المعالجة', en: 'Processing', tone: 'bg-info/15 text-info' },
  paid: { ar: 'مدفوعة', en: 'Paid', tone: 'bg-success/15 text-success' },
  failed: { ar: 'فشلت', en: 'Failed', tone: 'bg-danger/15 text-danger' },
  refunded: { ar: 'مستردّة', en: 'Refunded', tone: 'bg-surface-hover text-text-muted' },
}

export function paymentStateMeta(state: PaymentDisplayState, ar: boolean) {
  const m = PAYMENT_STATE[state] ?? { ar: state, en: state, tone: 'bg-surface-hover text-text-secondary' }
  return { label: ar ? m.ar : m.en, tone: m.tone }
}

/** One observed provider round-trip (the response of a real `pay` call). */
interface RoundTrip {
  at: string
  state: PaymentDisplayState
  provider: string
  session_id: string | null
  error: string | null
}

interface PaymentSession {
  payment: Payment
  invoiceNumber: string
  roundtrips: RoundTrip[]
}

export function PaymentsPage() {
  const locale = useUi((s) => s.locale)
  const ar = locale === 'ar'
  const c = COPY[locale]
  const canManage = useAuth((s) => s.hasPermission('billing.manage'))

  const q = useQuery({ queryKey: ['billing', 'invoices', 'payable'], queryFn: () => listInvoices() })
  const payable = (q.data ?? []).filter(isPayable)

  // Sessions accumulate from real `pay` responses, keyed by payment id. A stable idempotency key per invoice
  // means retrying returns the SAME payment — so repeated starts append attempts rather than fabricating rows.
  const [sessions, setSessions] = useState<Record<string, PaymentSession>>({})
  const [expanded, setExpanded] = useState<Record<string, boolean>>({})

  const startM = useMutation({
    mutationFn: (invoice: Invoice) => startPayment(invoice.id).then((payment) => ({ payment, invoice })),
    onSuccess: ({ payment, invoice }) => {
      const trip: RoundTrip = {
        at: new Date().toISOString(),
        state: paymentDisplayState(payment),
        provider: payment.provider,
        session_id: payment.provider_session_id,
        error: payment.error,
      }
      setSessions((prev) => {
        const existing = prev[payment.id]
        return {
          ...prev,
          [payment.id]: {
            payment,
            invoiceNumber: invoice.number,
            roundtrips: existing ? [...existing.roundtrips, trip] : [trip],
          },
        }
      })
    },
  })

  const sessionList = Object.values(sessions).sort(
    (a, b) => (b.roundtrips.at(-1)?.at ?? '').localeCompare(a.roundtrips.at(-1)?.at ?? ''),
  )

  return (
    <div className="flex w-full flex-col gap-4">
      <header className="flex flex-col gap-1">
        <h1 className="text-3xl font-extrabold tracking-tight text-text-primary">{c.title}</h1>
        <p className="text-sm text-text-secondary">{c.subtitle}</p>
      </header>

      <BillingTabs />

      <p className="flex items-start gap-2 rounded-xl bg-surface-hover px-3 py-2.5 text-xs text-text-secondary">
        <AlertTriangle size={15} className="mt-0.5 shrink-0 text-warning" /> {c.honest}
      </p>

      <div className="grid gap-5 md:grid-cols-2">
        {/* Payable invoices — the surface you start a payment on. */}
        <section className="flex flex-col gap-2">
          <h2 className="text-sm font-bold text-text-primary">{c.payable_title}</h2>
          {q.isLoading ? (
            <p className="rounded-xl border border-dashed border-border p-8 text-center text-sm text-text-secondary">{c.loading}</p>
          ) : q.isError ? (
            <p className="rounded-xl border border-danger/30 bg-danger/5 p-8 text-center text-sm text-danger">{c.error}</p>
          ) : payable.length === 0 ? (
            <div className="flex flex-col items-center gap-2 rounded-xl border border-dashed border-border p-10 text-center text-text-secondary">
              <CreditCard size={22} /><span className="text-sm">{c.none_payable}</span>
            </div>
          ) : (
            payable.map((inv) => {
              const outstanding = Math.max(0, Number(inv.total) - Number(inv.amount_paid))
              const busy = startM.isPending && startM.variables?.id === inv.id
              return (
                <div key={inv.id} className="flex items-center justify-between gap-3 rounded-2xl border border-border bg-surface p-3.5">
                  <div className="flex flex-col gap-0.5">
                    <span className="font-mono text-xs font-semibold text-brand-600" dir="ltr">{inv.number}</span>
                    <span className="tnum text-sm font-bold text-text-primary" dir="ltr">{formatMoney(outstanding, inv.currency)}</span>
                  </div>
                  {canManage ? (
                    <button
                      onClick={() => startM.mutate(inv)}
                      disabled={busy}
                      className="flex items-center gap-1.5 rounded-lg bg-brand-600 px-2.5 py-1.5 text-xs font-bold text-white hover:bg-brand-700 disabled:opacity-50"
                    >
                      <Play size={13} /> {busy ? c.starting : c.start}
                    </button>
                  ) : null}
                </div>
              )
            })
          )}
        </section>

        {/* Sessions started this session, with an attempts drill-down. */}
        <section className="flex flex-col gap-2">
          <h2 className="text-sm font-bold text-text-primary">{c.sessions_title}</h2>
          {sessionList.length === 0 ? (
            <div className="flex flex-col items-center gap-2 rounded-xl border border-dashed border-border p-10 text-center text-text-secondary">
              <CreditCard size={22} /><span className="text-sm">{c.no_sessions}</span>
            </div>
          ) : (
            sessionList.map((s) => {
              const state = paymentDisplayState(s.payment)
              const meta = paymentStateMeta(state, ar)
              const open = expanded[s.payment.id] ?? false
              return (
                <div key={s.payment.id} className="flex flex-col gap-2 rounded-2xl border border-border bg-surface p-3.5">
                  <div className="flex items-start justify-between gap-3">
                    <div className="flex flex-col gap-0.5">
                      <span className="font-mono text-xs font-semibold text-brand-600" dir="ltr">{s.invoiceNumber}</span>
                      <span className="tnum text-sm font-bold text-text-primary" dir="ltr">{formatMoney(s.payment.amount, s.payment.currency)}</span>
                      <span className="text-[11px] text-text-muted">{c.provider}: <span dir="ltr">{s.payment.provider}</span></span>
                    </div>
                    <span className={`whitespace-nowrap rounded-full px-2.5 py-1 text-[11px] font-semibold ${meta.tone}`}>{meta.label}</span>
                  </div>

                  <button
                    onClick={() => setExpanded((e) => ({ ...e, [s.payment.id]: !open }))}
                    className="flex w-fit items-center gap-1 text-xs font-semibold text-brand-600 hover:text-brand-700"
                  >
                    {open ? <ChevronUp size={13} /> : <ChevronDown size={13} />}
                    {open ? c.hide_attempts : c.show_attempts} ({s.roundtrips.length})
                  </button>

                  {open ? (
                    <div className="overflow-x-auto rounded-xl border border-border">
                      <table className="w-full min-w-[420px] text-xs">
                        <thead className="bg-surface-hover text-text-secondary">
                          <tr>
                            <th className="p-2 text-start font-semibold">#</th>
                            <th className="p-2 text-start font-semibold">{c.when}</th>
                            <th className="p-2 text-start font-semibold">{c.state}</th>
                            <th className="p-2 text-start font-semibold">{c.session_id}</th>
                            <th className="p-2 text-start font-semibold">{c.error_col}</th>
                          </tr>
                        </thead>
                        <tbody>
                          {s.roundtrips.map((t, i) => {
                            const tMeta = paymentStateMeta(t.state, ar)
                            return (
                              <tr key={t.at + i} className="border-t border-border">
                                <td className="p-2 tnum text-text-muted" dir="ltr">{i + 1}</td>
                                <td className="p-2 tnum text-text-secondary" dir="ltr">{formatDateTime(t.at)}</td>
                                <td className="p-2"><span className={`rounded-full px-1.5 py-0.5 text-[10px] font-semibold ${tMeta.tone}`}>{tMeta.label}</span></td>
                                <td className="p-2 font-mono text-text-secondary" dir="ltr">{t.session_id ?? '—'}</td>
                                <td className="p-2 text-text-secondary" dir="ltr">{t.error ?? '—'}</td>
                              </tr>
                            )
                          })}
                        </tbody>
                      </table>
                    </div>
                  ) : null}
                </div>
              )
            })
          )}
        </section>
      </div>
    </div>
  )
}

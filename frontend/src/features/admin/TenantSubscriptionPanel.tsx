import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { actOnTenantSubscription, fetchTenantSubscription, type TenantSubscriptionAction, type TenantSubscriptionState } from './api'
import { subscriptionStateLabel } from './labels'
import { Button } from '@/components/ui/Button'
import { DateField } from '@/components/ui/DateField'
import { Num } from '@/components/ui/Num'
import { toApiError } from '@/lib/api/client'

/**
 * ADMIN-FULL-CONTROL-001 — one workspace's plan and subscription state, from its tenant drawer, with no money moving.
 *
 * Which actions are offered follows the state: complimentary access has no date to extend, and only it converts. The server is what
 * enforces all of this; the form asks for the one thing each action needs, and always for a reason.
 */
const money = (amount: string, currency: string | null) => `${Number(amount).toLocaleString('en-US', { minimumFractionDigits: 2 })} ${currency ?? ''}`.trim()

const COPY: Record<TenantSubscriptionAction, { ar: string; en: string; hintAr: string; hintEn: string }> = {
  activate: { ar: 'تفعيل دون دفع', en: 'Activate with no payment', hintAr: 'يفتح المساحة حتى التاريخ المحدد، ثم يصبح التجديد مستحقًا. لا يُسجل دفع ولا فاتورة.', hintEn: 'Opens the workspace until the date set, then renewal falls due. No payment or invoice is recorded.' },
  extend: { ar: 'تمديد', en: 'Extend', hintAr: 'ينقل تاريخ الانتهاء دون أي مبلغ، ويرفع أي تأخر في السداد.', hintEn: 'Moves the end date for nothing, and clears any past-due state.' },
  complimentary: { ar: 'دخول مباشر دون دفع', en: 'Direct entry with no payment', hintAr: 'مفتوح بلا تاريخ انتهاء: لا يُطلب أي مبلغ عند التجديد ما دام ساريًا، ولا فاتورة ولا عملية لدى مزوّد.', hintEn: 'Open-ended: renewal asks for nothing while it stands, with no invoice or provider transaction.' },
  convert_paid: { ar: 'تحويل إلى مدفوع', en: 'Convert to paid', hintAr: 'ينتهي الدخول المجاني ويُطلب الدفع بالسعر الكامل في التاريخ المحدد، لا قبله.', hintEn: 'Free entry ends and payment is asked for at the full price on the date set, not before.' },
  convert_discount: { ar: 'تحويل إلى خصم', en: 'Convert to a discount', hintAr: 'ينتهي الدخول المجاني ويُطلب الدفع في التاريخ المحدد بخصم على ذلك المبلغ فقط.', hintEn: 'Free entry ends and payment is asked for on the date set, with a discount on that charge only.' },
  change_plan: { ar: 'تغيير الباقة', en: 'Change plan', hintAr: 'ينقل المساحة إلى الباقة بسعرها وعملتها دون أي مبلغ الآن. لا يغيّر حالة الاشتراك.', hintEn: 'Moves the workspace to the plan at its own price and currency, for nothing now. The state does not change.' },
  end: { ar: 'إنهاء', en: 'End', hintAr: 'يتوقف دخول المساحة وتبقى بياناتها كاملة. يمكن إعادة تفعيلها لاحقًا.', hintEn: 'Access to the workspace stops and every row of its data stays. It can be activated again later.' },
}

function offered(sub: TenantSubscriptionState | null): TenantSubscriptionAction[] {
  if (sub === null) return ['activate', 'complimentary']
  if (sub.open_ended) return ['change_plan', 'convert_paid', 'convert_discount', 'end']
  if (sub.status === 'canceled') return ['activate', 'complimentary']
  return ['extend', 'activate', 'complimentary', 'change_plan', 'end']
}

export function TenantSubscriptionPanel({ tenantId, ar }: { tenantId: string; ar: boolean }) {
  const client = useQueryClient()
  const query = useQuery({ queryKey: ['admin', 'tenant-subscription', tenantId], queryFn: () => fetchTenantSubscription(tenantId) })
  const [action, setAction] = useState<TenantSubscriptionAction | null>(null)
  const [plan, setPlan] = useState('')
  const [interval, setInterval] = useState<'monthly' | 'annual'>('monthly')
  const [date, setDate] = useState('')
  const [percent, setPercent] = useState('')
  const [confirmed, setConfirmed] = useState(false)
  const [reason, setReason] = useState('')

  const act = useMutation({
    mutationFn: () => actOnTenantSubscription(tenantId, {
      action: action!,
      reason: reason.trim(),
      ...(plan !== '' ? { plan_code: plan } : {}),
      ...(needsPlan || plan !== '' ? { interval } : {}),
      ...(needsDate ? { ends_on: date } : {}),
      ...(action === 'convert_discount' ? { discount_percent: Number(percent) } : {}),
      ...(action === 'complimentary' ? { confirm_complimentary: confirmed } : {}),
    }),
    onSuccess: () => {
      setAction(null); setPlan(''); setDate(''); setPercent(''); setConfirmed(false); setReason('')
      void client.invalidateQueries({ queryKey: ['admin', 'tenant-subscription', tenantId] })
      void client.invalidateQueries({ queryKey: ['admin', 'tenant', tenantId] })
      void client.invalidateQueries({ queryKey: ['admin', 'tenants'] })
    },
  })

  const sub = query.data?.subscription ?? null
  const needsDate = action === 'activate' || action === 'extend' || action === 'convert_paid' || action === 'convert_discount'
  const needsPlan = action === 'change_plan' || (sub === null && (action === 'activate' || action === 'complimentary'))
  const dateLabel = action === 'extend' || action === 'activate' ? (ar ? 'ساري حتى' : 'Valid until') : (ar ? 'تاريخ طلب الدفع' : 'Payment is asked for on')
  const ready = action !== null && reason.trim().length >= 5
    && (!needsDate || date !== '')
    && (!needsPlan || plan !== '')
    && (action !== 'convert_discount' || (Number(percent) >= 1 && Number(percent) <= 99))
    && (action !== 'complimentary' || confirmed)
  const inputClass = 'h-9 w-full min-w-0 rounded-lg border border-border bg-surface px-3 text-sm text-text-primary'
  const labelClass = 'flex min-w-0 flex-col gap-1 text-xs font-semibold text-text-secondary'

  return (
    <section data-testid="tenant-subscription" className="mt-5">
      <h3 className="text-[12px] font-semibold uppercase tracking-wide text-text-muted">{ar ? 'الاشتراك' : 'Subscription'}</h3>
      {query.isError && <p className="mt-2 text-xs text-danger">{toApiError(query.error).message}</p>}
      {query.data && (
        <div className="mt-2 flex flex-col gap-2 rounded-lg bg-surface-secondary px-3 py-2 text-[13px]">
          <div data-testid="tenant-subscription-state" data-status={sub?.status ?? 'none'} className="flex flex-wrap items-center gap-x-3 gap-y-1">
            <span className="font-semibold text-text-primary">{sub ? subscriptionStateLabel(sub.status, ar) : (ar ? 'لا يوجد اشتراك' : 'No subscription')}</span>
            {sub?.plan_name && <span className="text-text-secondary">{sub.plan_name}</span>}
            {sub?.unit_amount && !sub.open_ended && <span className="tnum text-text-secondary" dir="ltr"><Num>{money(sub.unit_amount, sub.currency)}</Num></span>}
            {sub?.open_ended && <span data-testid="tenant-subscription-open-ended" className="rounded-full bg-info/15 px-2 py-0.5 text-[11px] font-semibold text-info">{ar ? 'دخول مباشر دون دفع · بلا تاريخ انتهاء' : 'Direct entry with no payment · no end date'}</span>}
            {sub?.commercial_basis === 'admin_grant' && <span className="text-[12px] text-text-muted">{ar ? 'مفعّل بقرار إداري دون دفع' : 'Activated by an administrator with no payment'}</span>}
            {sub?.current_period_end && <span className="tnum text-text-secondary" dir="ltr"><Num>{sub.current_period_end.slice(0, 10)}</Num></span>}
            {sub?.next_charge && (
              <span data-testid="tenant-subscription-next-charge" className="text-[12px] text-text-secondary">
                {ar ? 'الدفعة القادمة' : 'Next charge'}: <Num>{money(sub.next_charge.amount, sub.next_charge.currency)}</Num> ({sub.next_charge.discount_percent}%)
              </span>
            )}
          </div>

          <div className="flex flex-wrap gap-1.5">
            {offered(sub).map((key) => (
              <button key={key} type="button" data-testid={`subscription-action-${key}`} aria-pressed={action === key}
                onClick={() => { setAction(action === key ? null : key); setConfirmed(false); act.reset() }}
                className={`rounded-lg border px-2 py-0.5 text-[12px] font-semibold ${action === key ? 'border-brand-500 text-brand-700' : 'border-border text-text-secondary hover:text-text-primary'}`}>
                {ar ? COPY[key].ar : COPY[key].en}
              </button>
            ))}
          </div>

          {action && (
            <form data-testid="subscription-form" className="flex flex-col gap-2" onSubmit={(e) => { e.preventDefault(); if (ready) act.mutate() }}>
              <p data-testid="subscription-hint" className="text-xs text-text-secondary">{ar ? COPY[action].hintAr : COPY[action].hintEn}</p>
              <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                {(needsPlan || action !== 'extend') && action !== 'end' && (
                  <label className={labelClass}>{ar ? 'الباقة' : 'Plan'}
                    <select data-testid="subscription-plan" value={plan} onChange={(e) => setPlan(e.target.value)} className={inputClass}>
                      <option value="">{needsPlan ? (ar ? 'اختر' : 'Choose') : (ar ? 'الباقة الحالية' : 'Current plan')}</option>
                      {query.data.plans.map((p) => <option key={p.code} value={p.code}>{p.name}</option>)}
                    </select>
                  </label>
                )}
                {(needsPlan || plan !== '') && (
                  <label className={labelClass}>{ar ? 'المدة' : 'Term'}
                    <select data-testid="subscription-interval" value={interval} onChange={(e) => setInterval(e.target.value as 'monthly' | 'annual')} className={inputClass}>
                      <option value="monthly">{ar ? 'شهري' : 'Monthly'}</option>
                      <option value="annual">{ar ? 'سنوي' : 'Annual'}</option>
                    </select>
                  </label>
                )}
                {needsDate && (
                  <div className={labelClass}><label htmlFor="subscription-date">{dateLabel}</label>
                    <DateField id="subscription-date" data-testid="subscription-date" value={date} onChange={setDate} />
                  </div>
                )}
                {action === 'convert_discount' && (
                  <label className={labelClass}>{ar ? 'نسبة الخصم %' : 'Discount %'}
                    <input data-testid="subscription-discount" dir="ltr" inputMode="numeric" value={percent} onChange={(e) => setPercent(e.target.value)} className={inputClass} />
                  </label>
                )}
              </div>
              {action === 'complimentary' && (
                <label className="flex items-start gap-2 rounded-xl border border-warning/40 bg-warning/10 p-3 text-sm font-semibold text-text-primary">
                  <input data-testid="subscription-confirm-complimentary" type="checkbox" className="mt-0.5" checked={confirmed} onChange={(e) => setConfirmed(e.target.checked)} />
                  <span className="min-w-0">{ar ? 'أؤكد منح دخول مباشر دون دفع وبلا تاريخ انتهاء. هذا ليس دفعًا ولا تُصدر له فاتورة.' : 'I confirm direct entry with no payment and no end date. This is not a payment and no invoice is issued.'}</span>
                </label>
              )}
              <label className={labelClass}>{ar ? 'السبب (يُسجَّل في سجل تدقيق المساحة)' : 'Reason (recorded in the workspace’s audit trail)'}
                <input data-testid="subscription-reason" value={reason} onChange={(e) => setReason(e.target.value)} className={inputClass} />
              </label>
              {act.isError && <p data-testid="subscription-error" className="text-xs font-semibold text-danger">{toApiError(act.error).message}</p>}
              <div>
                <Button data-testid="subscription-submit" type="submit" size="sm" variant={action === 'end' ? 'danger' : 'primary'} disabled={!ready || act.isPending}>{ar ? 'تنفيذ' : 'Apply'}</Button>
              </div>
            </form>
          )}

          {query.data.history.length > 0 && (
            <ol data-testid="subscription-history" className="flex flex-col gap-0.5 text-[12px] text-text-muted">
              {query.data.history.map((h, i) => (
                <li key={`${h.action}-${i}`} className="min-w-0 break-words">
                  <span className="font-semibold text-text-secondary">{ar ? COPY[h.action as TenantSubscriptionAction]?.ar ?? h.action : COPY[h.action as TenantSubscriptionAction]?.en ?? h.action}</span>
                  {h.at && <span className="tnum ms-2" dir="ltr"><Num>{h.at.slice(0, 10)}</Num></span>}
                  {h.by && <span className="ms-2">{h.by}</span>}
                  {h.reason && <span className="ms-2">— {h.reason}</span>}
                </li>
              ))}
            </ol>
          )}
        </div>
      )}
    </section>
  )
}

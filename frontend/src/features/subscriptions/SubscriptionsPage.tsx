import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, CalendarClock, CheckCircle2, CreditCard, Gauge } from 'lucide-react'
import { useUi } from '@/stores/ui'
import { useAuth } from '@/stores/auth'
import { toApiError } from '@/lib/api/client'
import {
  cancelPlanChange, detachPaymentMethod, getCurrent, getPlans, quotePlanChange, requestPlanChange,
  type CurrentSubscription, type RenewalMode, type SubscriptionPlan, type UsageMetric,
} from './api'
import { PolicyNote } from '@/features/legal/PolicyFooter'

/** Bilingual copy — self-contained to this feature (Arabic-first). */
const COPY = {
  ar: {
    title: 'الاشتراكات', subtitle: 'اطّلع على خطة المستأجر الحالية والاستهلاك مقابل الحدود، وبدّل الخطة.',
    no_permission: 'لا تملك صلاحية عرض الاشتراكات.',
    loading: 'جارٍ التحميل…', error: 'تعذّر تحميل بيانات الاشتراك.',
    current_plan: 'الخطة الحالية', default_plan: 'الخطة الافتراضية (بدون اشتراك مُسجَّل)',
    per_month: '/ شهرياً', seats: 'المقاعد', period_end: 'نهاية الدورة',
    usage_title: 'الاستهلاك والحدود', metric: 'المقياس', used: 'المُستهلك', remaining: 'المتبقّي', limit: 'الحد',
    unlimited: 'غير محدود', catalogue: 'الخطط المتاحة', change: 'التبديل إلى هذه الخطة',
    current_badge: 'خطتك الحالية', changing: 'جارٍ التبديل…', changed: 'تم تحديث الاشتراك',
    manage_note: 'تبديل الخطة يتطلّب صلاحية subscriptions.manage.',
    st_active: 'نشِط', st_trialing: 'فترة تجريبية', st_past_due: 'متأخّر السداد', st_canceled: 'مُلغى',
    features: 'المزايا',
    m_projects: 'المشاريع', m_team_members: 'أعضاء الفريق', m_connections: 'الربط', m_reports_per_month: 'التقارير / شهر',
    f_support: 'الدعم', f_ai_assist: 'مساعد الذكاء', f_white_label: 'العلامة البيضاء', yes: 'نعم', no: 'لا',
    review: 'مراجعة التغيير', cancel: 'إلغاء',
    quote_title: 'تفاصيل تغيير الباقة',
    quote_loading: 'جارٍ حساب الفرق…',
    days_left: 'الأيام المتبقية في الدورة الحالية',
    credit: 'رصيد المدة غير المستخدمة',
    new_price: 'سعر الباقة الجديدة للدورة',
    prorated: 'قيمة الباقة الجديدة للمدة المتبقية',
    due_now: 'المستحق الآن',
    upgrade_note: 'لن تُفعَّل الباقة الجديدة إلا بعد تأكيد الدفع من بوابة الدفع.',
    downgrade_note: 'لن يُخصم أو يُسترد أي مبلغ. تحتفظ بباقتك الحالية حتى نهاية الدورة المدفوعة، ثم يبدأ التغيير.',
    confirm_upgrade: 'المتابعة إلى الدفع',
    confirm_downgrade: 'تأكيد التغيير عند نهاية الدورة',
    confirm_free: 'تأكيد التغيير',
    pending_title: 'تغيير باقة قيد التنفيذ',
    pending_at: 'يبدأ في',
    pending_awaiting_payment: 'بانتظار تأكيد الدفع — باقتك الحالية لم تتغيّر.',
    withdraw: 'سحب الطلب',
    awaiting_credentials: 'بوابة الدفع غير مهيّأة بعد، لذلك لم يُفتح أي طلب دفع فعلي ولم يتغيّر شيء.',
    pay_now: 'إتمام الدفع',
    renewal_title: 'التجديد القادم',
    renewal_ready: 'يُخصم التجديد تلقائياً من البطاقة المحفوظة.',
    renewal_no_card: 'لا توجد بطاقة محفوظة، لذلك ستصلك فاتورة التجديد لتسديدها بنفسك.',
    renewal_no_gateway: 'لا توجد بوابة دفع مهيّأة، لذلك لا يمكن خصم التجديد تلقائياً.',
    renewal_unsupported: 'بوابة الدفع الحالية لا تدعم الخصم التلقائي، لذلك ستصلك فاتورة التجديد لتسديدها بنفسك.',
    remove_card: 'إزالة البطاقة',
    removing_card: 'جارٍ الإزالة…',
    remove_card_note: 'إزالة البطاقة لا تُلغي الاشتراك؛ ستصلك فاتورة التجديد لتسديدها بنفسك.',
  },
  en: {
    title: 'Subscriptions', subtitle: 'See the tenant’s current plan and usage against limits, and change plan.',
    no_permission: 'You do not have permission to view subscriptions.',
    loading: 'Loading…', error: 'Could not load subscription data.',
    current_plan: 'Current plan', default_plan: 'Default plan (no explicit subscription)',
    per_month: '/ month', seats: 'Seats', period_end: 'Period ends',
    usage_title: 'Usage & limits', metric: 'Metric', used: 'Used', remaining: 'Remaining', limit: 'Limit',
    unlimited: 'Unlimited', catalogue: 'Available plans', change: 'Switch to this plan',
    current_badge: 'Your current plan', changing: 'Switching…', changed: 'Subscription updated',
    manage_note: 'Changing the plan requires the subscriptions.manage permission.',
    st_active: 'Active', st_trialing: 'Trialing', st_past_due: 'Past due', st_canceled: 'Canceled',
    features: 'Features',
    m_projects: 'Projects', m_team_members: 'Team members', m_connections: 'Connections', m_reports_per_month: 'Reports / month',
    f_support: 'Support', f_ai_assist: 'AI assist', f_white_label: 'White-label', yes: 'Yes', no: 'No',
    review: 'Review this change', cancel: 'Cancel',
    quote_title: 'What this change costs',
    quote_loading: 'Working out the difference…',
    days_left: 'Days left in the current period',
    credit: 'Credit for the time you have not used',
    new_price: 'New plan, per period',
    prorated: 'New plan for the days that remain',
    due_now: 'Due now',
    upgrade_note: 'The new plan starts only once the gateway confirms the payment.',
    downgrade_note: 'Nothing is charged and nothing is refunded. You keep your current plan until the period you paid for ends.',
    confirm_upgrade: 'Continue to payment',
    confirm_downgrade: 'Confirm the change at period end',
    confirm_free: 'Confirm the change',
    pending_title: 'A plan change is pending',
    pending_at: 'Starts on',
    pending_awaiting_payment: 'Waiting for the payment to be confirmed — your current plan has not changed.',
    withdraw: 'Withdraw',
    awaiting_credentials: 'No payment gateway is configured, so no real payment was opened and nothing has changed.',
    pay_now: 'Complete the payment',
    renewal_title: 'Next renewal',
    renewal_ready: 'The renewal is taken automatically from the card on file.',
    renewal_no_card: 'There is no card on file, so the renewal will be sent to you as an invoice to pay.',
    renewal_no_gateway: 'No payment gateway is configured, so renewals cannot be taken automatically.',
    renewal_unsupported: 'The current gateway does not take automatic payments, so the renewal will be sent to you as an invoice.',
    remove_card: 'Remove card',
    removing_card: 'Removing…',
    remove_card_note: 'Removing the card does not cancel your subscription; the renewal will be sent to you as an invoice.',
  },
}
type Copy = (typeof COPY)['ar']

const STATUS_TONE: Record<string, string> = {
  active: 'bg-success/15 text-success',
  trialing: 'bg-info/15 text-info',
  past_due: 'bg-warning/15 text-warning',
  canceled: 'bg-surface-secondary text-text-muted',
}

function statusLabel(status: string, c: Copy): string {
  const map: Record<string, string> = { active: c.st_active, trialing: c.st_trialing, past_due: c.st_past_due, canceled: c.st_canceled }
  return map[status] ?? status
}

function metricLabel(metric: string, c: Copy): string {
  const map: Record<string, string> = {
    projects: c.m_projects, team_members: c.m_team_members, connections: c.m_connections, reports_per_month: c.m_reports_per_month,
  }
  return map[metric] ?? metric
}

function featureLabel(key: string, c: Copy): string {
  const map: Record<string, string> = { support: c.f_support, ai_assist: c.f_ai_assist, white_label: c.f_white_label }
  return map[key] ?? key.replace(/_/g, ' ')
}

function num(value: number | null | undefined, unlimited: string): string {
  if (value === null || value === undefined) return unlimited
  return value.toLocaleString('en-US')
}

function fmtDate(iso: string | null): string {
  if (!iso) return '—'
  const d = new Date(iso)
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleDateString('en-CA')
}

export function SubscriptionsPage() {
  const locale = useUi((s) => s.locale)
  const c = COPY[locale]
  const canView = useAuth((s) => s.hasPermission('subscriptions.view'))
  const canManage = useAuth((s) => s.hasPermission('subscriptions.manage'))
  const qc = useQueryClient()

  const currentQ = useQuery({ queryKey: ['subscriptions', 'current'], queryFn: getCurrent, retry: false, enabled: canView })
  const plansQ = useQuery({ queryKey: ['subscriptions', 'plans'], queryFn: getPlans, retry: false, enabled: canView })

  /*
   * Quote, then confirm — never one click (PAY-002).
   *
   * The numbers ARE the decision. A customer told «you will be charged 133.33 SAR now for the 20
   * days left, and 300.00 SAR every month after that» is making one; a customer shown a Switch
   * button and then a bank message is not. `chosen` holds the plan under review, and nothing is
   * committed until they confirm it.
   */
  const [chosen, setChosen] = useState<SubscriptionPlan | null>(null)

  const quoteQ = useQuery({
    queryKey: ['subscriptions', 'plan-change-quote', chosen?.code],
    queryFn: () => quotePlanChange(chosen!.code, 'monthly'),
    enabled: chosen !== null,
    retry: false,
  })

  const changeM = useMutation({
    mutationFn: (planCode: string) => requestPlanChange(planCode, 'monthly'),
    onSuccess: (result) => {
      qc.invalidateQueries({ queryKey: ['subscriptions', 'current'] })
      setChosen(null)
      /*
       * A real checkout is a page, not a fetch — so the browser goes there.
       *
       * When there is none (`checkout_url` is null because no gateway holds credentials) nothing is
       * navigated and the pending banner says so, rather than sending somebody to a blank tab.
       */
      if (result.payment?.checkout_url) window.location.assign(result.payment.checkout_url)
    },
  })

  const withdrawM = useMutation({
    mutationFn: cancelPlanChange,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['subscriptions', 'current'] }),
  })

  /** Taking the card off file. Refetches, because the renewal mode above changes with it. */
  const detachM = useMutation({
    mutationFn: detachPaymentMethod,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['subscriptions', 'current'] }),
  })

  if (!canView) {
    return (
      <div className="mx-auto w-full max-w-5xl p-4 md:p-6">
        <p className="rounded-xl border border-dashed border-border p-8 text-center text-sm text-text-secondary">{c.no_permission}</p>
      </div>
    )
  }

  const isLoading = currentQ.isLoading || plansQ.isLoading
  const isError = currentQ.isError || plansQ.isError
  const current = currentQ.data
  const plans = plansQ.data ?? []
  const currentCode = current?.plan?.code ?? null

  return (
    <div className="mx-auto flex w-full max-w-5xl flex-col gap-5 p-4 md:p-6">
      <header className="flex flex-col gap-1">
        <h1 className="flex items-center gap-2 text-3xl font-extrabold tracking-tight text-text-primary"><CreditCard size={26} /> {c.title}</h1>
        <p className="text-sm text-text-secondary">{c.subtitle}</p>
      </header>

      {/* POLICY-PLACEMENT-001 — «what am I paying for, and how do I stop», beside the subscription. */}
      <PolicyNote context="billing" />

      {isLoading ? (
        <div className="flex flex-col gap-3">{[0, 1].map((i) => <div key={i} className="h-40 animate-pulse rounded-2xl bg-surface-secondary" />)}</div>
      ) : isError || !current ? (
        <div className="rounded-2xl border border-danger/30 bg-[var(--negative-background)] p-6 text-center text-sm text-danger">{c.error}</div>
      ) : (
        <>
          <CurrentPlanCard current={current} c={c} />

          {current.renewal && (
            <RenewalCard
              renewal={current.renewal}
              c={c}
              canManage={canManage}
              busy={detachM.isPending}
              onRemove={() => detachM.mutate()}
            />
          )}

          {/* A change that is agreed but not in force. Never merged into the current plan above. */}
          {current.subscription?.scheduled_change && (
            <PendingChangeBanner
              change={current.subscription.scheduled_change}
              c={c}
              canManage={canManage}
              busy={withdrawM.isPending}
              onWithdraw={() => withdrawM.mutate()}
            />
          )}

          <UsageTable usage={current.usage} c={c} />
          <section className="flex flex-col gap-3">
            <h2 className="text-sm font-bold text-text-primary">{c.catalogue}</h2>
            {changeM.isError && <span className="text-xs font-semibold text-danger">{toApiError(changeM.error).message}</span>}
            {!canManage && <p className="text-xs text-text-tertiary">{c.manage_note}</p>}
            <div className="grid gap-3 md:grid-cols-3">
              {plans.map((plan) => (
                <PlanCard
                  key={plan.code}
                  plan={plan}
                  isCurrent={plan.code === currentCode}
                  canManage={canManage}
                  pending={changeM.isPending && changeM.variables === plan.code}
                  onChange={() => setChosen(plan)}
                  c={c}
                />
              ))}
            </div>
          </section>

          {chosen && (
            <ProrationReview
              plan={chosen}
              quote={quoteQ.data?.quote ?? null}
              loading={quoteQ.isPending}
              error={quoteQ.isError ? toApiError(quoteQ.error).message : null}
              busy={changeM.isPending}
              c={c}
              onCancel={() => setChosen(null)}
              onConfirm={() => changeM.mutate(chosen.code)}
            />
          )}
        </>
      )}
    </div>
  )
}

/**
 * How the next payment will be taken — PAY-TOKEN-003.
 *
 * The customer agreed to automatic renewal before they paid, and until now nothing on this page told
 * them whether the product could actually perform one. Without a card the renewal is an invoice
 * somebody has to remember to visit, and the first sign of a missed one is a past-due notice. The
 * reason is named rather than summarised, because `no_saved_method` is theirs to fix while
 * `no_gateway` and `provider_unsupported` belong to whoever runs the install.
 */
function RenewalCard({
  renewal, c, canManage, busy, onRemove,
}: {
  renewal: RenewalMode; c: Copy; canManage: boolean; busy: boolean; onRemove: () => void
}) {
  const message = renewal.unattended
    ? c.renewal_ready
    : renewal.reason === 'no_gateway'
      ? c.renewal_no_gateway
      : renewal.reason === 'provider_unsupported'
        ? c.renewal_unsupported
        : c.renewal_no_card

  return (
    <div data-testid="renewal-mode" data-reason={renewal.reason} className="flex flex-col gap-2 rounded-2xl border border-border bg-surface p-5">
      <span className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-text-muted">
        <CreditCard size={14} /> {c.renewal_title}
      </span>
      <p className="text-sm text-text-secondary">{message}</p>
      {renewal.card && (
        <span data-testid="renewal-card" className="tnum text-sm font-semibold text-text-primary" dir="ltr">{renewal.card}</span>
      )}
      {renewal.card && canManage && (
        <div className="flex flex-col gap-1">
          <button
            type="button"
            data-testid="remove-card"
            onClick={onRemove}
            disabled={busy}
            className="self-start rounded-xl border border-border px-3 py-1.5 text-xs font-semibold text-text-secondary hover:bg-surface-secondary disabled:opacity-60"
          >
            {busy ? c.removing_card : c.remove_card}
          </button>
          {/* Said before they click, not after: «remove card» and «cancel my subscription» are easy
              to confuse, and only one of them is what this button does. */}
          <p className="text-xs text-text-tertiary">{c.remove_card_note}</p>
        </div>
      )}
    </div>
  )
}

function CurrentPlanCard({ current, c }: { current: CurrentSubscription; c: Copy }) {
  const plan = current.plan
  const sub = current.subscription
  return (
    <div className="flex flex-col gap-3 rounded-2xl border border-border bg-surface p-5">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="flex flex-col gap-0.5">
          <span className="text-xs font-semibold uppercase tracking-wide text-text-muted">{current.is_default_plan ? c.default_plan : c.current_plan}</span>
          <span className="text-2xl font-extrabold text-text-primary">{plan?.name ?? '—'}</span>
        </div>
        {sub && <span className={`rounded-full px-3 py-1 text-xs font-semibold ${STATUS_TONE[sub.status] ?? 'bg-surface-secondary text-text-muted'}`}>{statusLabel(sub.status, c)}</span>}
      </div>
      <div className="flex flex-wrap items-center gap-x-6 gap-y-1 text-sm text-text-secondary">
        {plan && <span className="tnum font-bold text-text-primary" dir="ltr">{plan.price_monthly} {plan.currency} <span className="text-xs font-normal text-text-muted">{c.per_month}</span></span>}
        {sub && <span>{c.seats}: <span className="tnum font-semibold text-text-primary">{num(sub.seats, c.unlimited)}</span></span>}
        {sub?.current_period_end && <span>{c.period_end}: <span className="tnum font-semibold text-text-primary">{fmtDate(sub.current_period_end)}</span></span>}
      </div>
    </div>
  )
}

function UsageTable({ usage, c }: { usage: Record<string, UsageMetric>; c: Copy }) {
  const rows = Object.entries(usage)
  return (
    <section className="flex flex-col gap-3">
      <h2 className="flex items-center gap-2 text-sm font-bold text-text-primary"><Gauge size={16} /> {c.usage_title}</h2>
      <div className="overflow-x-auto rounded-2xl border border-border bg-surface">
        <table className="w-full min-w-[420px] text-sm">
          <thead className="bg-surface-secondary text-xs text-text-secondary">
            <tr>
              <th className="p-3 text-start font-semibold">{c.metric}</th>
              <th className="p-3 text-end font-semibold">{c.used}</th>
              <th className="p-3 text-end font-semibold">{c.remaining}</th>
              <th className="p-3 text-end font-semibold">{c.limit}</th>
            </tr>
          </thead>
          <tbody>
            {rows.map(([metric, u]) => (
              <tr key={metric} className="border-t border-border">
                <td className="p-3 font-medium text-text-primary">{metricLabel(metric, c)}</td>
                <td className="p-3 text-end tnum text-text-secondary" dir="ltr">{num(u.used, c.unlimited)}</td>
                <td className="p-3 text-end tnum text-text-secondary" dir="ltr">{u.remaining === null ? c.unlimited : num(u.remaining, c.unlimited)}</td>
                <td className="p-3 text-end tnum text-text-secondary" dir="ltr">{u.limit === null ? c.unlimited : num(u.limit, c.unlimited)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  )
}

function PlanCard({
  plan, isCurrent, canManage, pending, onChange, c,
}: {
  plan: SubscriptionPlan; isCurrent: boolean; canManage: boolean; pending: boolean; onChange: () => void; c: Copy
}) {
  const features = Object.entries(plan.features)
  return (
    <div className={`flex flex-col gap-3 rounded-2xl border bg-surface p-5 ${isCurrent ? 'border-brand-500 ring-1 ring-brand-500/30' : 'border-border'}`}>
      <div className="flex items-start justify-between gap-2">
        <span className="font-bold text-text-primary">{plan.name}</span>
        {isCurrent && <span className="inline-flex items-center gap-1 whitespace-nowrap rounded-full bg-brand-500/15 px-2 py-0.5 text-[11px] font-semibold text-brand-600"><CheckCircle2 size={12} /> {c.current_badge}</span>}
      </div>
      <div className="tnum text-xl font-extrabold text-text-primary" dir="ltr">{plan.price_monthly} {plan.currency}<span className="ms-1 text-xs font-normal text-text-muted">{c.per_month}</span></div>

      {features.length > 0 && (
        <ul className="flex flex-col gap-1 border-t border-border pt-3 text-xs text-text-secondary">
          {features.map(([key, value]) => (
            <li key={key} className="flex items-center justify-between gap-2">
              <span>{featureLabel(key, c)}</span>
              <span className="font-semibold text-text-primary">
                {typeof value === 'boolean' ? (value ? c.yes : c.no) : String(value)}
              </span>
            </li>
          ))}
        </ul>
      )}

      {canManage && (
        <button
          type="button"
          disabled={isCurrent || pending}
          onClick={onChange}
          className="mt-auto rounded-lg bg-brand-600 px-3 py-2 text-sm font-bold text-white transition-colors hover:bg-brand-700 disabled:cursor-not-allowed disabled:opacity-50"
        >
          {isCurrent ? c.current_badge : pending ? c.changing : c.change}
        </button>
      )}
    </div>
  )
}

/**
 * A change that has been agreed and is not in force yet (PAY-002).
 *
 * Two different situations wear the same shape and must not read the same: a downgrade waiting for
 * the calendar, and an upgrade waiting for money. The second one says plainly that nothing has
 * changed, because that is the thing a customer will otherwise assume wrong.
 */
function PendingChangeBanner({
  change, c, canManage, busy, onWithdraw,
}: {
  change: NonNullable<NonNullable<CurrentSubscription['subscription']>['scheduled_change']>
  c: Copy
  canManage: boolean
  busy: boolean
  onWithdraw: () => void
}) {
  return (
    <div
      data-testid="pending-plan-change"
      data-awaiting-payment={change.awaiting_payment}
      role="status"
      className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-border bg-surface-secondary p-4"
    >
      <div className="flex items-start gap-2.5">
        {change.awaiting_payment ? <AlertTriangle size={17} className="mt-0.5 shrink-0 text-warning" />
          : <CalendarClock size={17} className="mt-0.5 shrink-0 text-info" />}
        <div>
          <p className="text-sm font-bold text-text-primary">{c.pending_title} — {change.plan_name}</p>
          <p className="mt-0.5 text-[13px] text-text-secondary">
            {change.awaiting_payment
              ? c.pending_awaiting_payment
              : `${c.pending_at} ${fmtDate(change.effective_at)}`}
          </p>
        </div>
      </div>

      {canManage && (
        <button
          type="button"
          data-testid="withdraw-plan-change"
          disabled={busy}
          onClick={onWithdraw}
          className="rounded-lg border border-border px-3 py-1.5 text-xs font-semibold text-text-secondary hover:text-text-primary disabled:opacity-50"
        >
          {c.withdraw}
        </button>
      )}
    </div>
  )
}

/**
 * The numbers, before anything is committed.
 *
 * Every line is shown rather than a single total: the credit for unused time is the part a customer
 * would otherwise believe was taken from them, and a total that does not show it looks like being
 * charged twice for the same month.
 */
function ProrationReview({
  plan, quote, loading, error, busy, c, onCancel, onConfirm,
}: {
  plan: SubscriptionPlan
  quote: import('./api').ProrationQuote | null
  loading: boolean
  error: string | null
  busy: boolean
  c: Copy
  onCancel: () => void
  onConfirm: () => void
}) {
  const money = (value: string, currency: string) => `${value} ${currency}`
  const isDowngrade = quote?.effective === 'period_end'
  const owes = quote !== null && Number(quote.due_now) > 0

  return (
    <section data-testid="proration-review" className="flex flex-col gap-3 rounded-2xl border border-brand-500 bg-surface p-5">
      <h2 className="text-sm font-bold text-text-primary">{c.quote_title} — {plan.name}</h2>

      {loading && <p className="text-sm text-text-secondary">{c.quote_loading}</p>}
      {error && <p className="text-sm font-semibold text-danger">{error}</p>}

      {quote && (
        <>
          <dl className="flex flex-col gap-1.5 text-sm">
            <Row label={c.days_left} value={`${quote.remaining_days} / ${quote.period_days}`} />
            <Row label={c.new_price} value={money(quote.new_period_price, quote.currency)} />
            <Row label={c.prorated} value={money(quote.prorated_new, quote.currency)} />
            {/* Shown as a deduction, because that is what it is. */}
            <Row label={c.credit} value={`− ${money(quote.credit, quote.currency)}`} />
            <div className="mt-1 flex items-center justify-between border-t border-border pt-2">
              <dt className="text-sm font-bold text-text-primary">{c.due_now}</dt>
              <dd data-testid="due-now" className="tnum text-lg font-extrabold text-text-primary" dir="ltr">
                {money(quote.due_now, quote.currency)}
              </dd>
            </div>
          </dl>

          {/* The honest sentence, and it differs by direction. */}
          <p data-testid="proration-note" className="text-[13px] leading-relaxed text-text-secondary">
            {isDowngrade ? c.downgrade_note : c.upgrade_note}
          </p>

          <div className="flex flex-wrap gap-2">
            <button
              type="button"
              data-testid="confirm-plan-change"
              disabled={busy}
              onClick={onConfirm}
              className="rounded-lg bg-brand-600 px-4 py-2 text-sm font-bold text-white hover:bg-brand-700 disabled:opacity-50"
            >
              {isDowngrade ? c.confirm_downgrade : owes ? c.confirm_upgrade : c.confirm_free}
            </button>
            <button
              type="button"
              onClick={onCancel}
              className="rounded-lg border border-border px-4 py-2 text-sm font-semibold text-text-secondary hover:text-text-primary"
            >
              {c.cancel}
            </button>
          </div>
        </>
      )}
    </section>
  )
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-center justify-between gap-3">
      <dt className="text-text-secondary">{label}</dt>
      <dd className="tnum font-semibold text-text-primary" dir="ltr">{value}</dd>
    </div>
  )
}

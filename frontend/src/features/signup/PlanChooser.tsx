import { useQuery } from '@tanstack/react-query'
import { Check, Loader2 } from 'lucide-react'
import { fetchPlans, type BillingInterval, type Plan } from './api'
import { useUi } from '@/stores/ui'

/**
 * Choosing a plan and a term, as part of signing up (PLAN-001).
 *
 * Everything shown here comes from the server's catalogue. A price written into the browser is a
 * price that will eventually disagree with the one the checkout charges, and the whole point of the
 * plans engine is that the figure quoted and the figure billed are one statement.
 *
 * What it does NOT do is claim an introductory month the plan does not offer, or an annual term a plan is not sold
 * on: both are read per plan, and the annual toggle disappears when nothing is sold that way.
 */

const COPY = {
  ar: {
    heading: 'اختر الباقة',
    monthly: 'شهري',
    annual: 'سنوي',
    perMonth: '/شهريًا',
    perYear: '/سنويًا',
    /*
      The paid introductory month — PAY-AUDIT-003. It is a PRICE, not a trial: there is no free
      period anywhere in this product, and calling a charge «تجربة» invites somebody to expect one.

      Arabic number agreement: 3–10 take the plural («7 أيام»), 11 and above the singular accusative
      («30 يومًا»). The old string said «30 أيام» the moment the term became a month — the same
      mistake MAIL-007 and MAIL-014 each had to correct.
    */
    intro: (days: number, fee: string, currency: string) =>
      `أول ${days} ${days <= 10 ? 'أيام' : 'يومًا'} بـ ${fee} ${currency}`,
    noAnnual: 'غير متاحة سنويًا',
    loading: 'جارٍ تحميل الباقات…',
    unavailable: 'تعذّر تحميل الباقات الآن، ولا يمكن إتمام التسجيل دون اختيار باقة.',
    retry: 'إعادة المحاولة',
  },
  en: {
    heading: 'Choose a plan',
    monthly: 'Monthly',
    annual: 'Annual',
    perMonth: '/month',
    perYear: '/year',
    intro: (days: number, fee: string, currency: string) =>
      `First ${days} days for ${fee} ${currency}`,
    noAnnual: 'Not sold annually',
    loading: 'Loading plans…',
    unavailable: 'Plans could not be loaded right now, and registration cannot be completed without one.',
    retry: 'Try again',
  },
} as const

export function PlanChooser({
  value, interval, onChange, onIntervalChange,
}: {
  value: string | null
  interval: BillingInterval
  onChange: (code: string) => void
  onIntervalChange: (interval: BillingInterval) => void
}) {
  const ar = useUi((s) => s.locale) === 'ar'
  const c = COPY[ar ? 'ar' : 'en']

  const plans = useQuery({ queryKey: ['plans'], queryFn: fetchPlans })

  if (plans.isPending) {
    return (
      <p className="flex items-center gap-2 text-sm text-text-secondary">
        <Loader2 size={15} className="animate-spin" /> {c.loading}
      </p>
    )
  }

  /*
   * A catalogue we could not read is said out loud — and, since PLAN-PAID-001, it is a dead end
   * rather than a footnote.
   *
   * The plan used to be optional, so a failed price list was worth shrugging at. There is no free
   * tier to fall back to now: an application naming no plan owes an amount nobody can compute and
   * would sit at the payment gate forever. So the honest thing is to say the step cannot be
   * completed and offer the only useful action — try again.
   */
  if (plans.isError || !plans.data) {
    return (
      <div data-testid="plans-unavailable" className="rounded-xl border border-border bg-surface-secondary p-3">
        <p className="text-sm text-text-secondary">{c.unavailable}</p>
        <button
          type="button"
          onClick={() => void plans.refetch()}
          className="mt-2 text-sm font-semibold text-brand-600 hover:underline"
        >
          {c.retry}
        </button>
      </div>
    )
  }

  const anyAnnual = plans.data.plans.some((p) => p.price_annual !== null)

  /*
   * Cards, because this is a step of its own now (PLAN-001e).
   *
   * An earlier attempt put this control on the same screen as the credentials, where the page's
   * 768px budget left room for a row of pills and nothing else — a price list too small to read.
   * Splitting the form gave the question its own screen, so each plan can state its price, its term
   * and its trial in words.
   */
  return (
    <section data-testid="plan-chooser" aria-label={c.heading} className="flex flex-col gap-2.5">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <span className="text-sm font-bold text-text-primary">{c.heading}</span>

        {/* The term toggle only exists when something is actually sold on an annual term. */}
        {anyAnnual && (
          <div className="flex rounded-lg border border-border p-0.5">
            {(['monthly', 'annual'] as const).map((k) => (
              <button
                key={k}
                type="button"
                data-testid={`plan-interval-${k}`}
                aria-pressed={interval === k}
                onClick={() => onIntervalChange(k)}
                className={`rounded px-3 py-1 text-xs font-semibold ${interval === k ? 'bg-brand-primary-soft text-brand-700' : 'text-text-secondary hover:text-text-primary'}`}
              >
                {c[k]}
              </button>
            ))}
          </div>
        )}
      </div>

      {/* One column on phones, three across from `sm` — a price list is unreadable side by side at 375px. */}
      <div className="grid gap-2 sm:grid-cols-3">
        {plans.data.plans.map((plan) => (
          <PlanPill
            key={plan.code}
            plan={plan}
            interval={interval}
            ar={ar}
            copy={c}
            selected={value === plan.code}
            onSelect={() => onChange(plan.code)}
          />
        ))}
      </div>
    </section>
  )
}

function PlanPill({
  plan, interval, ar, copy, selected, onSelect,
}: {
  plan: Plan
  interval: BillingInterval
  ar: boolean
  copy: typeof COPY['en'] | typeof COPY['ar']
  selected: boolean
  onSelect: () => void
}) {
  // Null is a statement, not a missing value: this plan is not sold on the chosen term, and showing
  // the other term's price instead would quote a figure nobody can buy.
  const price = interval === 'annual' ? plan.price_annual : plan.price_monthly
  const unavailable = price === null

  /*
    Stated only where it applies: a plan that offers one, a term it is sold on, and the MONTHLY term.
    The annual term is bought outright (PAY-AUDIT-003), so advertising an introductory month beside
    an annual price would promise a charge the checkout will not make.
  */
  const intro = interval === 'monthly' && plan.trial_days > 0 && !unavailable
    ? copy.intro(plan.trial_days, plan.trial_fee, plan.currency)
    : undefined

  return (
    <button
      type="button"
      data-testid={`plan-${plan.code}`}
      data-selected={selected}
      aria-pressed={selected}
      disabled={unavailable}
      onClick={onSelect}
      className={`flex flex-col gap-0.5 rounded-xl border p-2.5 text-start transition-colors disabled:opacity-50 ${selected ? 'border-brand-500 bg-brand-primary-soft' : 'border-border bg-surface hover:border-brand-400'}`}
    >
      <span className="flex items-center gap-1.5 text-sm font-bold text-text-primary">
        {ar ? plan.name_ar : plan.name}
        {selected && <Check size={14} className="shrink-0 text-brand-600" />}
      </span>

      {unavailable ? (
        <span className="text-xs text-text-muted">{copy.noAnnual}</span>
      ) : (
        <span className="text-sm font-semibold text-text-secondary" dir="ltr">
          {price} {plan.currency}
          <span className="text-xs font-normal text-text-muted">
            {interval === 'annual' ? copy.perYear : copy.perMonth}
          </span>
        </span>
      )}

      {intro && (
        <span data-testid={`plan-${plan.code}-intro`} className="text-xs font-semibold text-brand-600">
          {intro}
        </span>
      )}

      {(ar ? plan.summary_ar : plan.summary_en) && (
        <span className="text-[11px] leading-snug text-text-muted">{ar ? plan.summary_ar : plan.summary_en}</span>
      )}
    </button>
  )
}

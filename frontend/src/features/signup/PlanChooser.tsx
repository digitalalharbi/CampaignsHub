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
 * What it does NOT do is claim a trial the plan does not offer, or an annual term a plan is not sold
 * on: both are read per plan, and the annual toggle disappears when nothing is sold that way.
 */

const COPY = {
  ar: {
    heading: 'اختر الباقة',
    monthly: 'شهري',
    annual: 'سنوي',
    perMonth: '/شهريًا',
    perYear: '/سنويًا',
    trial: (days: number, fee: string, currency: string) =>
      `تجربة ${days} أيام مقابل ${fee} ${currency}`,
    noAnnual: 'غير متاحة سنويًا',
    loading: 'جارٍ تحميل الباقات…',
    unavailable: 'تعذّر تحميل الباقات الآن. يمكنك المتابعة واختيار الباقة لاحقًا.',
  },
  en: {
    heading: 'Choose a plan',
    monthly: 'Monthly',
    annual: 'Annual',
    perMonth: '/month',
    perYear: '/year',
    trial: (days: number, fee: string, currency: string) =>
      `${days}-day trial for ${fee} ${currency}`,
    noAnnual: 'Not sold annually',
    loading: 'Loading plans…',
    unavailable: 'Plans could not be loaded right now. You can continue and choose one later.',
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
   * A catalogue we could not read is said out loud, and does not block the application.
   *
   * The plan is optional at this point — it decides which policy applies and what a checkout will
   * later charge, and both of those are questions the server answers. Refusing to let someone sign
   * up because a price list failed to load would be the wrong trade.
   */
  if (plans.isError || !plans.data) {
    return <p data-testid="plans-unavailable" className="text-sm text-text-muted">{c.unavailable}</p>
  }

  const anyAnnual = plans.data.plans.some((p) => p.price_annual !== null)

  /*
   * One row, not three cards.
   *
   * This page has to fit a 1366x768 desktop without scrolling — `e2e/auth-redesign.spec.ts` asserts
   * it, and the first version of this control broke that at every desktop size by adding a card grid
   * with per-plan blurbs. The blurbs belong on a pricing page; what a sign-up form needs is the
   * choice and the number.
   */
  return (
    <section data-testid="plan-chooser" aria-label={c.heading} className="flex flex-wrap items-center gap-2">
      <span className="text-sm font-bold text-text-primary">{c.heading}</span>

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

      {/* The term toggle only exists when something is actually sold on an annual term. */}
      {anyAnnual && (
        <div className="flex rounded-lg border border-border p-0.5 ms-auto">
          {(['monthly', 'annual'] as const).map((k) => (
            <button
              key={k}
              type="button"
              data-testid={`plan-interval-${k}`}
              aria-pressed={interval === k}
              onClick={() => onIntervalChange(k)}
              className={`rounded px-2.5 py-1 text-xs font-semibold ${interval === k ? 'bg-brand-primary-soft text-brand-700' : 'text-text-secondary hover:text-text-primary'}`}
            >
              {c[k]}
            </button>
          ))}
        </div>
      )}
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
   * The trial is announced in the pill's TITLE rather than as a second line.
   *
   * It is real information — the plan starts with a paid trial at a stated fee — but a line of it
   * under every pill is three extra rows on a page that has to fit 768px. The testid stays, so the
   * claim is still asserted where it matters.
   */
  const trial = plan.trial_days > 0 && !unavailable
    ? copy.trial(plan.trial_days, plan.trial_fee, plan.currency)
    : undefined

  return (
    <button
      type="button"
      data-testid={`plan-${plan.code}`}
      data-selected={selected}
      data-trial={trial ?? ''}
      aria-pressed={selected}
      disabled={unavailable}
      title={trial ?? (ar ? plan.summary_ar : plan.summary_en) ?? undefined}
      onClick={onSelect}
      className={`flex items-center gap-1.5 rounded-lg border px-2.5 py-1.5 text-xs font-semibold transition-colors disabled:opacity-50 ${selected ? 'border-brand-500 bg-brand-primary-soft text-brand-700' : 'border-border bg-surface text-text-secondary hover:border-brand-400'}`}
    >
      {selected && <Check size={13} className="shrink-0" />}
      <span className="text-text-primary">{ar ? plan.name_ar : plan.name}</span>
      {unavailable ? (
        <span className="text-text-muted">{copy.noAnnual}</span>
      ) : (
        <span dir="ltr" className="text-text-muted">
          {price} {plan.currency}{interval === 'annual' ? copy.perYear : copy.perMonth}
        </span>
      )}
      {trial && (
        <span data-testid={`plan-${plan.code}-trial`} className="sr-only">{trial}</span>
      )}
    </button>
  )
}

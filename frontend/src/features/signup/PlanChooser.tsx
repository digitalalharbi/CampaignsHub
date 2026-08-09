import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Check, Loader2, Table2 } from 'lucide-react'
import { fetchPlans, type BillingInterval, type Plan } from './api'
import { useUi } from '@/stores/ui'
import { Modal } from '@/components/ui/Modal'
import { COMPARISON, plansForJourney, whyUpgrade, type Journey } from './planFit'

/**
 * Choosing a plan and a term, as part of signing up (PLAN-001).
 *
 * Everything shown here comes from the server's catalogue. A price written into the browser is a
 * price that will eventually disagree with the one the checkout charges, and the whole point of the
 * plans engine is that the figure quoted and the figure billed are one statement.
 *
 * ## What the restructure changed, and why
 *
 * The cards used to carry every difference between the plans, which made three specifications and
 * no decision — and on the agency path they compared plans an agency was never going to buy. Now the
 * card answers four questions and stops: what is it called, what does it cost, who is it for, and
 * what do I get. Everything else moved into «compare all features», one press away, where a table is
 * the right shape for it.
 *
 * ## The introductory price is not the price
 *
 * Growth's headline is 49, not 9. Leading with the introductory figure sells a number nobody pays
 * for more than a month, and the surprise arrives on the second charge — which is exactly how an
 * offer becomes a complaint. So the regular price is the large one and the offer is stated beneath
 * it, in full and in one line: what it is, how long it lasts, what it becomes, and what it commits
 * you to.
 */

const COPY = {
  ar: {
    heading: 'اختر الباقة المناسبة',
    monthly: 'شهري',
    annual: 'سنوي',
    perMonth: '/شهريًا',
    perYear: '/سنويًا',
    /*
      The paid introductory month — PAY-AUDIT-003 / SUB-COMMIT-001. It is a PRICE, not a trial:
      there is no free period anywhere in this product, and calling a charge «تجربة» invites somebody
      to expect one.

      Arabic number agreement: 3–10 take the plural («7 أيام»), 11 and above the singular accusative
      («30 يومًا»). The old string said «30 أيام» the moment the term became a month — the same
      mistake MAIL-007 and MAIL-014 each had to correct.
    */
    intro: (days: number, fee: string, regular: string, currency: string) =>
      `أول ${days} ${days <= 10 ? 'أيام' : 'يومًا'} بـ ${fee} ${currency}، ثم ${regular} ${currency} شهريًا`,
    commitment: (months: number) => `التزام أولي ${months} ${months <= 10 ? 'أشهر' : 'شهرًا'}`,
    noAnnual: 'غير متاحة سنويًا',
    recommended: 'موصى بها',
    choose: 'اختيار هذه الباقة',
    chosen: 'الباقة المحددة',
    compare: 'مقارنة جميع المزايا',
    compareTitle: 'مقارنة الباقات',
    loading: 'جارٍ تحميل الباقات…',
    unavailable: 'تعذّر تحميل الباقات الآن، ولا يمكن إتمام التسجيل دون اختيار باقة.',
    retry: 'إعادة المحاولة',
  },
  en: {
    heading: 'Choose the plan that fits',
    monthly: 'Monthly',
    annual: 'Annual',
    perMonth: '/month',
    perYear: '/year',
    intro: (days: number, fee: string, regular: string, currency: string) =>
      `First ${days} days for ${fee} ${currency}, then ${regular} ${currency} a month`,
    commitment: (months: number) => `${months}-month minimum commitment`,
    noAnnual: 'Not sold annually',
    recommended: 'Recommended',
    choose: 'Choose this plan',
    chosen: 'Selected',
    compare: 'Compare all features',
    compareTitle: 'Plan comparison',
    loading: 'Loading plans…',
    unavailable: 'Plans could not be loaded right now, and registration cannot be completed without one.',
    retry: 'Try again',
  },
} as const

type Copy = typeof COPY['en'] | typeof COPY['ar']

/**
 * Growth is the recommended plan — the owner's pricing of 2026-08-09.
 *
 * A constant rather than a column: it is a marketing position, not a property of the catalogue, and
 * the moment it becomes a column somebody has to keep it in step across three plans.
 */
const RECOMMENDED = 'growth'

export function PlanChooser({
  value, interval, onChange, onIntervalChange, journey = null,
}: {
  value: string | null
  interval: BillingInterval
  onChange: (code: string) => void
  onIntervalChange: (interval: BillingInterval) => void
  /**
   * How this applicant said they will use CampaignsHub — PLAN-FIT-001.
   *
   * The plans on offer follow the question that was asked: the path is the KIND of use, the plan is
   * the capacity. Null means «not asked yet», and shows the whole catalogue rather than guessing.
   */
  journey?: Journey | null
}) {
  const ar = useUi((s) => s.locale) === 'ar'
  const c = COPY[ar ? 'ar' : 'en']
  const [comparing, setComparing] = useState(false)

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

  /*
   * The plans this journey is actually offered, in the order they step up — PLAN-FIT-001.
   *
   * Filtered rather than merely re-ordered: offering a multi-client workspace the entry plan means
   * offering three projects and three connections to somebody who needs one set per client, and the
   * plan-limit refusals would be the first thing they met.
   */
  const offered = plansForJourney(plans.data.plans, journey)
  const anyAnnual = offered.some((p) => p.price_annual !== null)

  /*
   * One card is CENTRED and kept to a readable width rather than stretched across the row.
   *
   * The agency path offers exactly one plan, and a lone card filling the full width reads as a
   * banner — as though the choice were still to come. Constrained, it reads as the answer.
   */
  const grid = offered.length === 1
    ? 'mx-auto w-full max-w-sm'
    : offered.length > 2 ? 'grid gap-[clamp(0.375rem,0.9vh,0.5rem)] sm:grid-cols-3' : 'grid gap-[clamp(0.375rem,0.9vh,0.5rem)] sm:grid-cols-2'

  return (
    <section data-testid="plan-chooser" aria-label={c.heading} className="flex min-w-0 flex-col gap-[clamp(0.375rem,1.1vh,0.625rem)]">
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

      {/* One column on phones, side by side from `sm` — a price list is unreadable at 375px. */}
      <div className={grid}>
        {offered.map((plan, i) => (
          <PlanCard
            key={plan.code}
            plan={plan}
            interval={interval}
            ar={ar}
            copy={c}
            selected={value === plan.code}
            onSelect={() => onChange(plan.code)}
            // What this plan gives, or adds over the one before it — from the catalogue's own limits.
            why={whyUpgrade(plan, offered[i - 1], ar)}
            recommended={plan.code === RECOMMENDED}
          />
        ))}
      </div>

      {/*
        The whole table, one press away — and NOT on the cards.

        Seven axes on a card is a specification; the reader has to hold three of them in their head
        to compare anything. Side by side in a table it is one glance, and the cards get to stay a
        decision.
      */}
      {offered.length > 1 && (
        <button
          type="button"
          data-testid="plan-compare-open"
          onClick={() => setComparing(true)}
          className="inline-flex w-fit items-center gap-1.5 text-xs font-semibold text-brand-600 hover:underline"
        >
          <Table2 size={14} /> {c.compare}
        </button>
      )}

      <Modal open={comparing} onClose={() => setComparing(false)} title={c.compareTitle} size="lg">
        <ComparisonTable plans={offered} ar={ar} />
      </Modal>
    </section>
  )
}

/** Every axis the catalogue publishes, for the plans this journey can actually buy. */
function ComparisonTable({ plans, ar }: { plans: Plan[]; ar: boolean }) {
  return (
    // Wide content scrolls inside its own box rather than making the page scroll sideways.
    <div className="overflow-x-auto">
      <table data-testid="plan-comparison" className="w-full min-w-[26rem] text-sm">
        <thead>
          <tr className="border-b border-border">
            <th className="p-2 text-start text-xs font-semibold text-text-muted" />
            {plans.map((p) => (
              <th key={p.code} className="p-2 text-start text-sm font-bold text-text-primary">
                {ar ? p.name_ar : p.name}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {COMPARISON.map((row) => (
            <tr key={row.key} className="border-b border-border last:border-b-0">
              <th scope="row" className="p-2 text-start text-xs font-semibold text-text-secondary">
                {ar ? row.ar : row.en}
              </th>
              {plans.map((p) => (
                <td key={p.code} data-testid={`compare-${row.key}-${p.code}`} className="tnum p-2 text-sm text-text-primary">
                  {row.value(p, ar)}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

function PlanCard({
  plan, interval, ar, copy, selected, onSelect, why, recommended,
}: {
  plan: Plan
  interval: BillingInterval
  ar: boolean
  copy: Copy
  selected: boolean
  onSelect: () => void
  /** «Projects: 3 → 25» — computed, so it cannot drift from what the backend enforces. */
  why: string[]
  recommended: boolean
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
  const hasIntro = interval === 'monthly' && plan.trial_days > 0 && !unavailable
  const commitment = hasIntro ? (plan.minimum_commitment_months ?? 0) : 0

  /*
    The card is ONE control, and the call to action inside it is a span.

    A real <button> nested in a <button> is invalid, and splitting them would give the same card two
    controls that do the same thing — two tab stops, two things to explain to a screen reader. The
    affordance is visual; the semantics stay a single pressable card.
  */
  return (
    <button
      type="button"
      data-testid={`plan-${plan.code}`}
      data-selected={selected}
      aria-pressed={selected}
      disabled={unavailable}
      onClick={onSelect}
      /*
        Fluid padding and a fluid gap — AUTH-FIT-001. Two cards at fixed spacing pushed the submit
        button off a 1366×768 laptop, which is the screen this step is most often completed on.
      */
      className={`flex h-full min-w-0 flex-col gap-[clamp(0.125rem,0.45vh,0.25rem)] rounded-xl border p-[clamp(0.5rem,1.1vh,0.75rem)] text-start transition-colors disabled:opacity-50 ${selected ? 'border-brand-500 bg-brand-primary-soft' : 'border-border bg-surface hover:border-brand-400'}`}
    >
      <span className="flex flex-wrap items-center gap-1.5 text-sm font-bold text-text-primary">
        {ar ? plan.name_ar : plan.name}
        {recommended && (
          <span
            data-testid={`plan-${plan.code}-recommended`}
            className="rounded-full bg-brand-primary-soft px-1.5 py-0.5 text-[10px] font-bold text-brand-700"
          >
            {copy.recommended}
          </span>
        )}
      </span>

      {/* The price is the headline — the regular one, never the introductory one. */}
      {unavailable ? (
        <span className="text-xs text-text-muted">{copy.noAnnual}</span>
      ) : (
        <span className="flex items-baseline gap-1 font-bold text-text-primary" dir="ltr">
          <span className="tnum text-[clamp(1.0625rem,1.4vw,1.25rem)]">{price}</span>
          <span className="text-xs font-semibold text-text-secondary">{plan.currency}</span>
          <span className="text-xs font-normal text-text-muted">
            {interval === 'annual' ? copy.perYear : copy.perMonth}
          </span>
        </span>
      )}

      {/* The offer, stated in full beneath the price it discounts — never in place of it. */}
      {hasIntro && (
        <span data-testid={`plan-${plan.code}-intro`} className="text-[11px] font-semibold leading-[1.35] text-brand-600">
          {copy.intro(plan.trial_days, plan.trial_fee, plan.price_monthly, plan.currency)}
          {commitment > 0 && (
            <>
              {' · '}
              <span data-testid={`plan-${plan.code}-commitment`}>{copy.commitment(commitment)}</span>
            </>
          )}
        </span>
      )}

      {/* Who it is for, in the catalogue's own words. */}
      {(ar ? plan.summary_ar : plan.summary_en) && (
        <span className="text-[11px] leading-[1.35] text-text-muted">{ar ? plan.summary_ar : plan.summary_en}</span>
      )}

      {/*
        What you get, or what this adds over the plan before it — the concrete numbers, not another
        adjective. Capped at four: past that a card stops being a decision.
      */}
      {why.length > 0 && (
        <span data-testid={`plan-${plan.code}-why`} className="mt-0.5 flex flex-col gap-0.5">
          {why.map((line) => (
            <span key={line} className="tnum flex items-start gap-1 text-[11px] font-semibold leading-[1.35] text-text-secondary">
              <Check size={12} className="mt-0.5 shrink-0 text-brand-600" />
              <span dir={ar ? 'rtl' : 'ltr'}>{line}</span>
            </span>
          ))}
        </span>
      )}

      {/* Pushed to the bottom so cards of unequal height still line their actions up. */}
      {!unavailable && (
        <span
          className={`mt-[clamp(0.25rem,0.7vh,0.5rem)] block rounded-lg px-3 py-[clamp(0.25rem,0.6vh,0.375rem)] text-center text-xs font-bold ${selected ? 'bg-brand-600 text-white' : 'bg-surface-secondary text-brand-700'}`}
        >
          {selected ? `✓ ${copy.chosen}` : copy.choose}
        </span>
      )}
    </button>
  )
}

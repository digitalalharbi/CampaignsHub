import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Check, Loader2, Table2 } from 'lucide-react'
import { fetchPlans, type BillingInterval, type Plan } from './api'
import { useUi } from '@/stores/ui'
import { Modal } from '@/components/ui/Modal'
import { comparisonFor, plansForJourney, whyUpgrade, type ComparisonGroup, type Journey } from './planFit'

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
    chooseNamed: (name: string) => `اختيار ${name}`,
    loading: 'جارٍ تحميل الباقات…',
    /*
      Three different things went wrong, and they are said as three different things — SIGNUP-CAT-001.

      They used to share one sentence, «تعذّر تحميل الباقات», which is true of only the first. An
      empty catalogue and a catalogue with nothing for this path are CONFIGURATION faults: the server
      answered perfectly, and telling somebody to «retry» a correct answer sends them round a loop
      that cannot end.
    */
    unreachable: 'تعذّر الوصول إلى الخادم لتحميل الباقات. لا يمكن إتمام التسجيل دون اختيار باقة.',
    unreachableWhy: 'المشكلة في الاتصال بالخادم وليست في حسابك. أعد المحاولة، وإن تكررت فأبلغ الدعم.',
    empty: 'لا توجد أي باقة معروضة للاشتراك حاليًا.',
    emptyWhy: 'حُمّلت قائمة الباقات من الخادم وجاءت فارغة — هذا خلل في الإعداد وليس في اتصالك. لا يمكن إتمام التسجيل حتى تُعرض باقة واحدة على الأقل.',
    noneForPath: 'لا توجد باقة معروضة لهذا المسار.',
    noneForPathWhy: 'الخادم يعرض باقات، لكن لا شيء منها مخصص للمسار الذي اخترته. جرّب المسار الآخر، أو أبلغ الدعم — هذا خلل في الإعداد.',
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
    chooseNamed: (name: string) => `Choose ${name}`,
    loading: 'Loading plans…',
    unreachable: 'The server could not be reached to load the plans. Registration cannot be completed without one.',
    unreachableWhy: 'This is a connection problem, not a problem with your details. Try again, and tell support if it keeps happening.',
    empty: 'No plan is on sale at the moment.',
    emptyWhy: 'The plan list loaded from the server and came back empty — that is a configuration fault, not your connection. Registration cannot be completed until at least one plan is offered.',
    noneForPath: 'No plan is offered for this path.',
    noneForPathWhy: 'The server does offer plans, but none of them belongs to the path you chose. Try the other path, or tell support — this is a configuration fault.',
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

/**
 * A step that cannot be completed, and the reason it cannot — SIGNUP-CAT-001.
 *
 * One component for all three refusals so they cannot drift into three different shapes. `retry` is
 * omitted where retrying is pointless: a correct answer does not become a different answer because
 * somebody pressed a button, and offering one there is a loop with no exit.
 */
function Blocked({ testid, title, why, retry }: {
  testid: string
  title: string
  why: string
  retry?: { label: string; onRetry: () => void }
}) {
  return (
    <div data-testid={testid} role="alert" className="rounded-xl border border-border bg-surface-secondary p-3">
      <p className="text-sm font-semibold text-text-primary">{title}</p>
      <p className="mt-1 text-xs leading-relaxed text-text-secondary">{why}</p>
      {retry !== undefined && (
        <button
          type="button"
          data-testid={`${testid}-retry`}
          onClick={retry.onRetry}
          className="mt-2 text-sm font-semibold text-brand-600 hover:underline"
        >
          {retry.label}
        </button>
      )}
    </div>
  )
}


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
      <p data-testid="plans-loading" className="flex items-center gap-2 text-sm text-text-secondary">
        <Loader2 size={15} className="animate-spin" /> {c.loading}
      </p>
    )
  }

  /*
   * **The server could not be reached.** A transport failure, and the only one worth retrying.
   *
   * Kept a dead end rather than a footnote: since PLAN-PAID-001 there is no free tier to fall back
   * to, so an application naming no plan owes an amount nobody can compute and would sit at the
   * payment gate forever. Fail-closed, and say which kind of failure it was.
   */
  if (plans.isError || !plans.data) {
    return (
      <Blocked
        testid="plans-unavailable"
        title={c.unreachable}
        why={c.unreachableWhy}
        retry={{ label: c.retry, onRetry: () => void plans.refetch() }}
      />
    )
  }

  /*
   * **The server answered, and offered nothing.** A configuration fault, not a transport one.
   *
   * This rendered the heading and an empty space: no plans, no explanation, no way forward — an
   * applicant staring at a step that cannot be completed and no reason given. Proven by feeding the
   * component a 200 with `plans: []`, which produced exactly the heading and nothing else.
   *
   * It is deliberately NOT called «could not load», because the load succeeded. Saying otherwise
   * sends somebody to check their connection over a fault that is ours.
   */
  if (plans.data.plans.length === 0) {
    return <Blocked testid="plans-empty" title={c.empty} why={c.emptyWhy} retry={{ label: c.retry, onRetry: () => void plans.refetch() }} />
  }

  /*
   * The plans this journey is actually offered, in the order they step up — PLAN-FIT-001.
   *
   * Filtered rather than merely re-ordered: offering a multi-client workspace the entry plan means
   * offering three projects and three connections to somebody who needs one set per client, and the
   * plan-limit refusals would be the first thing they met.
   */
  const offered = plansForJourney(plans.data.plans, journey)

  /*
   * **The catalogue is fine; this PATH is offered nothing.** The third distinct fault.
   *
   * Reachable whenever the codes in `OFFERED` and the codes on sale drift apart — exactly what a
   * rename like `scale` → `agency` can do if the catalogue is not migrated with it. Silence here
   * would strand only the agency applicants, which is the kind of half-broken nobody notices.
   *
   * No retry: refetching returns the same correct answer. What it names instead is the other path,
   * which is the one action that can actually get somebody moving.
   */
  if (offered.length === 0) {
    return <Blocked testid="plans-none-for-path" title={c.noneForPath} why={c.noneForPathWhy} />
  }

  const anyAnnual = offered.some((p) => p.price_annual !== null)

  /*
   * A lone card FILLS the column — SIGNUP-CMP-001.
   *
   * It was capped at `max-w-sm` on the reasoning that a full-width card reads as a banner. On the
   * agency path, where Agency is the only plan sold, that left a narrow card adrift in a wide column
   * with white space either side — which reads as something unfinished, not as the answer. The fix
   * is to use the width rather than to avoid it: the card fills the column and lays its capacities
   * out in two columns (see `solo`), so the space is filled with content instead of margin.
   */
  const solo = offered.length === 1
  const grid = solo
    ? 'w-full'
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
            solo={solo}
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
        <ComparisonTable
          plans={offered}
          // Only the rows that mean something to THIS reader, comparing THESE plans.
          groups={comparisonFor(offered, journey)}
          interval={interval}
          ar={ar}
          copy={c}
          onPick={(code) => { onChange(code); setComparing(false) }}
        />
      </Modal>
    </section>
  )
}

/**
 * The full comparison — grouped, and shaped for the screen it is on (SIGNUP-CMP-001).
 *
 * It was one flat table of eight rows: capacity, capability and support all rendered alike, so
 * nothing stood out and the whole thing read as a database dump. Three named groups answer three
 * different questions instead, and {@see comparisonFor} drops the rows that would be dead weight —
 * «العملاء» for somebody running their own campaigns, and a capability no plan on screen has.
 *
 * DESKTOP is a table, because that is what a table is for. MOBILE is one block per plan, because a
 * three-column table at 375px either overflows sideways or squeezes every figure into two
 * characters — and a comparison nobody can read is not a comparison. Neither layout scrolls
 * horizontally.
 *
 * Every figure comes from the catalogue. Nothing here is written down.
 */
function ComparisonTable({
  plans, groups, interval, ar, copy, onPick,
}: {
  plans: Plan[]
  groups: ComparisonGroup[]
  interval: BillingInterval
  ar: boolean
  copy: Copy
  /** Picking from the table selects the plan AND closes — the table is a decision aid, not a museum. */
  onPick: (code: string) => void
}) {
  const priceOf = (p: Plan) => (interval === 'annual' ? p.price_annual : p.price_monthly)
  const per = interval === 'annual' ? copy.perYear : copy.perMonth

  const Head = ({ plan }: { plan: Plan }) => (
    <>
      <span className="flex flex-wrap items-center gap-1.5">
        <span className="text-sm font-bold text-text-primary">{ar ? plan.name_ar : plan.name}</span>
        {plan.code === RECOMMENDED && (
          <span className="rounded-full bg-brand-primary-soft px-1.5 py-0.5 text-[10px] font-bold text-brand-700">
            {copy.recommended}
          </span>
        )}
      </span>
      {/* The price for the term currently chosen, so the table and the cards never disagree. */}
      <span className="mt-0.5 flex items-baseline gap-1 font-bold text-text-primary" dir="ltr">
        {priceOf(plan) === null ? (
          <span className="text-xs font-normal text-text-muted">{copy.noAnnual}</span>
        ) : (
          <>
            <span className="tnum text-base">{priceOf(plan)}</span>
            <span className="text-[11px] font-semibold text-text-secondary">{plan.currency}</span>
            <span className="text-[11px] font-normal text-text-muted">{per}</span>
          </>
        )}
      </span>
      {(ar ? plan.summary_ar : plan.summary_en) && (
        <span className="mt-0.5 block text-[11px] font-normal leading-snug text-text-muted">
          {ar ? plan.summary_ar : plan.summary_en}
        </span>
      )}
    </>
  )

  const Cta = ({ plan }: { plan: Plan }) => (
    <button
      type="button"
      data-testid={`compare-choose-${plan.code}`}
      onClick={() => onPick(plan.code)}
      className={`mt-2 block w-full rounded-lg px-3 py-1.5 text-center text-xs font-bold ${plan.code === RECOMMENDED ? 'bg-brand-600 text-white' : 'bg-surface-secondary text-brand-700'}`}
    >
      {copy.chooseNamed(ar ? plan.name_ar : plan.name)}
    </button>
  )

  return (
    <div data-testid="plan-comparison">
      {/* ── Desktop: a real comparison table ─────────────────────────────────────────────── */}
      <table className="hidden w-full table-fixed sm:table">
        <thead>
          <tr className="border-b border-border align-top">
            <th className="w-[30%] p-2" />
            {plans.map((p) => (
              <th
                key={p.code}
                className={`p-2 text-start ${p.code === RECOMMENDED ? 'rounded-t-xl bg-brand-primary-soft/40' : ''}`}
              >
                <Head plan={p} />
              </th>
            ))}
          </tr>
        </thead>
        {groups.map((group) => (
          <tbody key={group.key}>
            <tr>
              <th
                colSpan={plans.length + 1}
                scope="colgroup"
                className="pt-3 pb-1 text-start text-[11px] font-bold uppercase tracking-wide text-text-muted"
              >
                {ar ? group.ar : group.en}
              </th>
            </tr>
            {group.rows.map((row) => (
              <tr key={row.key} className="border-b border-border last:border-b-0">
                <th scope="row" className="p-2 text-start text-xs font-semibold text-text-secondary">
                  {ar ? row.ar : row.en}
                </th>
                {plans.map((p) => (
                  <td
                    key={p.code}
                    data-testid={`compare-${row.key}-${p.code}`}
                    className={`tnum p-2 text-sm text-text-primary ${p.code === RECOMMENDED ? 'bg-brand-primary-soft/40' : ''}`}
                  >
                    {row.value(p, ar)}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        ))}
        <tfoot>
          <tr>
            <td className="p-2" />
            {plans.map((p) => (
              <td key={p.code} className={`p-2 align-top ${p.code === RECOMMENDED ? 'rounded-b-xl bg-brand-primary-soft/40' : ''}`}>
                <Cta plan={p} />
              </td>
            ))}
          </tr>
        </tfoot>
      </table>

      {/* ── Mobile: one block per plan, stacked. No sideways scrolling, nothing squeezed. ── */}
      <div className="flex flex-col gap-3 sm:hidden">
        {plans.map((p) => (
          <section
            key={p.code}
            data-testid={`compare-card-${p.code}`}
            className={`rounded-xl border p-3 ${p.code === RECOMMENDED ? 'border-brand-500 bg-brand-primary-soft/40' : 'border-border bg-surface'}`}
          >
            <Head plan={p} />
            {groups.map((group) => (
              <div key={group.key} className="mt-2">
                <p className="text-[11px] font-bold uppercase tracking-wide text-text-muted">
                  {ar ? group.ar : group.en}
                </p>
                <dl className="mt-1">
                  {group.rows.map((row) => (
                    <div key={row.key} className="flex items-baseline justify-between gap-2 border-b border-border py-1 last:border-b-0">
                      <dt className="text-xs text-text-secondary">{ar ? row.ar : row.en}</dt>
                      <dd className="tnum text-xs font-semibold text-text-primary">{row.value(p, ar)}</dd>
                    </div>
                  ))}
                </dl>
              </div>
            ))}
            <Cta plan={p} />
          </section>
        ))}
      </div>
    </div>
  )
}

function PlanCard({
  plan, interval, ar, copy, selected, onSelect, why, recommended, solo = false,
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
  /** The only card on the path: it fills the column, so its content spreads instead of stacking. */
  solo?: boolean
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
        <span
          data-testid={`plan-${plan.code}-why`}
          className={`mt-0.5 gap-x-4 gap-y-0.5 ${solo ? 'grid sm:grid-cols-2' : 'flex flex-col'}`}
        >
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

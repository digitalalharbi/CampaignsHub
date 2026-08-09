import type { PlanQuote } from './api'

/**
 * Everything the customer is agreeing to, before they are asked for a card — SUB-CONSENT-001.
 *
 * The introductory price is an offer with a commitment behind it, and an offer whose terms appear
 * only in a confirmation email is not one anybody agreed to. Six facts, each on its own line,
 * because «9 now, then 149 a month, minimum three months» asks somebody to do arithmetic at the
 * exact moment they are being asked to pay:
 *
 *   what is taken today · the regular price after it · the minimum commitment ·
 *   the date money moves again · the total the commitment costs · how renewal and cancelling work
 *
 * Every figure comes from the server's quote. None is computed here — the same sum implemented twice
 * eventually disagrees, and the half a customer is shown must be the half they are charged.
 *
 * Latin digits and `dir="ltr"` on the amounts, as everywhere in this product: a price in
 * Eastern-Arabic numerals cannot be compared against the card statement it will appear on.
 */

const T = {
  ar: {
    heading: 'ما ستوافق عليه',
    today: 'المبلغ المستحق اليوم',
    regular: 'السعر الشهري بعد الشهر التمهيدي',
    commitment: 'الحد الأدنى للالتزام',
    months: (n: number) => `${n} ${n <= 10 ? 'أشهر' : 'شهرًا'}`,
    nextPayment: 'تاريخ الدفعة التالية',
    remaining: 'الدفعات المتبقية داخل الالتزام',
    payments: (n: number) => `${n} ${n <= 10 ? 'دفعات' : 'دفعة'}`,
    totalCommitted: 'إجمالي مبلغ الالتزام',
    terms: 'التجديد والإلغاء',
    termsBody: (n: number) =>
      `يتجدد الاشتراك شهريًا تلقائيًا. يمكنك طلب الإلغاء في أي وقت، ويسري الإلغاء بعد انتهاء مدة الالتزام (${n} ${n <= 10 ? 'أشهر' : 'شهرًا'}) — أي أن الدفعات المتفق عليها داخل هذه المدة تبقى مستحقة. بعد انتهائها يصبح الاشتراك شهريًا عاديًا يمكن إلغاؤه قبل الدورة التالية.`,
    termsNoCommitment:
      'يتجدد الاشتراك تلقائيًا. يمكنك الإلغاء في أي وقت وسيسري قبل الدورة التالية، ولا توجد مدة التزام.',
    agree: 'أوافق على المبالغ ومدة الالتزام وشروط التجديد والإلغاء الموضحة أعلاه.',
  },
  en: {
    heading: 'What you are agreeing to',
    today: 'Due today',
    regular: 'Monthly price after the introductory month',
    commitment: 'Minimum commitment',
    months: (n: number) => `${n} months`,
    nextPayment: 'Next payment date',
    remaining: 'Payments remaining in the commitment',
    payments: (n: number) => `${n}`,
    totalCommitted: 'Total committed amount',
    terms: 'Renewal and cancellation',
    termsBody: (n: number) =>
      `The subscription renews monthly. You can ask to cancel at any time; cancellation takes effect after the ${n}-month commitment ends — the payments agreed inside it remain due. After that it becomes an ordinary monthly subscription you can cancel before the next cycle.`,
    termsNoCommitment:
      'The subscription renews automatically. You can cancel at any time and it takes effect before the next cycle. There is no commitment period.',
    agree: 'I agree to the amounts, the commitment period, and the renewal and cancellation terms above.',
  },
}

function Line({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex flex-wrap items-baseline justify-between gap-2 border-b border-border py-2 last:border-b-0">
      <span className="text-xs text-text-secondary">{label}</span>
      <span className="tnum text-sm font-bold text-text-primary" dir="ltr">{children}</span>
    </div>
  )
}

export function CommitmentDisclosure({
  quote,
  ar,
  agreed,
  onAgreedChange,
}: {
  quote: PlanQuote
  ar: boolean
  agreed: boolean
  onAgreedChange: (next: boolean) => void
}) {
  const t = ar ? T.ar : T.en
  const committed = quote.commitment_months > 0

  return (
    <section
      data-testid="commitment-disclosure"
      className="w-full rounded-2xl border border-border bg-surface p-4 text-start"
    >
      <h3 className="mb-2 font-bold text-text-primary">{t.heading}</h3>

      <Line label={t.today}>{quote.due_now} {quote.currency}</Line>
      <Line label={t.regular}>{quote.regular_monthly} {quote.currency}</Line>

      {committed && (
        <>
          <Line label={t.commitment}>{t.months(quote.commitment_months)}</Line>
          {/*
            «How many more times will this card be charged?» — the question the other lines describe
            without answering. Today's is excluded: it has its own line and is the one being
            authorised right now.
          */}
          <Line label={t.remaining}>
            <span data-testid="commitment-remaining">{t.payments(quote.remaining_committed_payments)}</span>
          </Line>
          {/* The figure nobody works out for themselves, and the one that decides it. */}
          <Line label={t.totalCommitted}>
            <span data-testid="commitment-total">{quote.total_committed} {quote.currency}</span>
          </Line>
        </>
      )}

      <Line label={t.nextPayment}>{quote.next_payment_on}</Line>

      <p className="mt-3 text-xs leading-6 text-text-secondary">
        <span className="font-semibold text-text-primary">{t.terms}: </span>
        {committed ? t.termsBody(quote.commitment_months) : t.termsNoCommitment}
      </p>

      {/*
        A real gate, not a notice. The server refuses to open a committed charge without the
        agreement, so this checkbox is the only place it can come from — and the Pay button stays
        disabled until it is ticked, rather than failing after somebody has pressed it.
      */}
      {committed && (
        <label className="mt-3 flex cursor-pointer items-start gap-2 text-xs leading-6 text-text-primary">
          <input
            type="checkbox"
            data-testid="commitment-agree"
            checked={agreed}
            onChange={(e) => onAgreedChange(e.target.checked)}
            className="mt-1 h-4 w-4 shrink-0 rounded border-border-strong accent-[var(--brand-600)]"
          />
          <span>{t.agree}</span>
        </label>
      )}
    </section>
  )
}

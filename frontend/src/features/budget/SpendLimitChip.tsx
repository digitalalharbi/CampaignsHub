import { AlertTriangle, CircleHelp, TrendingUp } from 'lucide-react'
import { coveringLimits, worstLimitState, type CoveredCampaign } from './limitCoverage'
import type { SpendLimitReading } from './spendLimitsApi'
import type { Locale } from '@/stores/ui'

/**
 * BUDGET-CONNECTED-001 — the limit, on the campaign it constrains.
 *
 * ## What this is for
 *
 * A spend limit reached its own page, the alert evaluator and the daily digest, and nowhere an
 * operator actually works. A campaign at 94% of a limit looked identical to one with no limit at
 * all on the campaigns board, so the figure was only ever met by somebody who already knew to go
 * looking for it.
 *
 * ## It appears only when there is something to say
 *
 * `ok` draws nothing. A chip on every card saying «within its limit» is noise that teaches a reader
 * to stop seeing the chips, and the one that matters is the one they then miss. `approaching`,
 * `over` and `unknown` each mean a person should look.
 *
 * ## «Unknown» is not «fine»
 *
 * A limit whose spend cannot be compared — two currencies and no exchange rate — is shown, in its
 * own muted tone. Hiding it would be reporting safety the product cannot see, which is the exact
 * failure the governance feature exists to prevent, arriving through the feature meant to prevent it.
 *
 * ## What it must never imply
 *
 * CampaignsHub watches spend and warns. It does not stop delivery on any ad platform. That sentence
 * travels with the payload rather than being written here, and it is on the chip's own `title`,
 * because a badge reading «over limit» on a campaign that is still serving is exactly where somebody
 * assumes something was paused for them.
 */
export function SpendLimitChip({
  campaign,
  limits,
  enforcementNote,
  locale,
}: {
  campaign: CoveredCampaign
  limits: SpendLimitReading[]
  /** The server's own sentence about what a limit does — never a copy written in the browser. */
  enforcementNote: string
  locale: Locale
}) {
  const ar = locale === 'ar'
  const { covering, unmatched } = coveringLimits(limits, campaign)
  const state = worstLimitState(covering)

  if (state === null || state === 'ok') {
    /*
     * An account-scoped limit that could not be matched is still worth a word, even when everything
     * matchable is fine: «there is a limit here I cannot place» is a different statement from
     * «nothing constrains this campaign», and only one of them is true.
     */
    return unmatched.length === 0 ? null : (
      <span
        data-testid="spend-limit-unmatched"
        title={enforcementNote}
        className="inline-flex items-center gap-1 rounded-full bg-surface-hover px-2 py-0.5 text-[10px] font-semibold text-text-muted"
      >
        <CircleHelp size={11} aria-hidden />
        {ar ? 'حد على مستوى الحساب' : 'An account-level limit'}
      </span>
    )
  }

  const worst = covering.filter((l) => l.state === state)[0]
  const pct = worst?.utilisation === null || worst?.utilisation === undefined
    ? null
    : Math.round(worst.utilisation * 100)

  const tone = state === 'over'
    ? 'bg-danger/10 text-danger'
    : state === 'approaching'
      ? 'bg-warning/10 text-warning'
      : 'bg-surface-hover text-text-muted'

  const Icon = state === 'unknown' ? CircleHelp : state === 'over' ? AlertTriangle : TrendingUp

  const label = state === 'over'
    ? (ar ? 'تجاوز حدّ الإنفاق' : 'Over its spend limit')
    : state === 'approaching'
      ? (ar ? 'يقترب من حدّ الإنفاق' : 'Approaching its spend limit')
      /* The reason travels from the server; this says only that there is one. */
      : (ar ? 'حدّ إنفاق لا يمكن قياسه' : 'A spend limit that cannot be measured')

  return (
    <span
      data-testid="spend-limit-chip"
      data-state={state}
      title={enforcementNote}
      className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold ${tone}`}
    >
      <Icon size={11} aria-hidden />
      {label}
      {/*
        The percentage only where one exists. An `unknown` limit has no utilisation by definition,
        and printing «0%» beside «cannot be measured» would answer the question it just refused.
      */}
      {pct !== null && <span className="tnum" dir="ltr">{pct}%</span>}
    </span>
  )
}

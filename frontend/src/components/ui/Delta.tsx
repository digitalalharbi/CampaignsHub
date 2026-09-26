import { ArrowDownRight, ArrowUpRight, Minus } from 'lucide-react'
import { Num } from '@/components/ui/Num'
import { percent } from '@/features/analytics/format'
import { SPECS } from '@/features/analytics/metricCatalog'

/**
 * UX-DELTA-PRESENTATION-001 — one movement, one rule for whether it is good news.
 *
 * ## What this replaces
 *
 * Four components had grown their own: `FindingKpiTile`, `CreativePulseSection`,
 * `CampaignCommandCenter` and the client `TabAnalytics`. Each picked its own icon set, its own
 * threshold for "no change", and — the part that matters — its own answer to *which direction is
 * good*. That is the same fragmentation `StatCard` was written to end, one level down: a customer
 * moving between two pages meets two designs of the same object, and worse, can meet two opinions
 * about whether a rising CPA is green.
 *
 * ## Direction comes from the catalogue, never from the caller
 *
 * `SPECS` already states it, and it is the only place that should: `invertGood` for every cost-per,
 * where down is the win; `neutral` for figures like spend, where neither direction is good news on its
 * own and colouring one would be a judgement the number does not support. A caller passes the metric
 * key and gets the product's answer — it cannot hold a different opinion, because it is never asked.
 *
 * ## What it refuses to draw
 *
 * A movement needs two figures. With no previous value there is no delta and nothing is rendered: an
 * arrow drawn from an absent baseline claims a change nobody measured, which is the one thing a
 * dashboard must not do with a client's money. A previous value of zero is the same refusal — every
 * increase from nothing is "infinite", and that is a division, not an insight.
 *
 * ## Direction of the text, which is not the same as its alignment
 *
 * `dir="ltr"` on the figure, for the reason `StatCard` documents: an unmarked «-12%» in an Arabic
 * layout can have its sign moved to the wrong end by the bidi algorithm, and a minus that jumps is a
 * number that lies.
 */
export type DeltaTone = 'good' | 'bad' | 'flat' | 'neutral'

/** Below this, a movement is noise and is shown as flat rather than coloured. */
const FLAT_BELOW = 0.005

export function deltaOf(current: number | null | undefined, previous: number | null | undefined): number | null {
  if (typeof current !== 'number' || typeof previous !== 'number') return null
  if (!Number.isFinite(current) || !Number.isFinite(previous)) return null
  // From nothing, every rise is infinite. That is arithmetic, not a trend.
  if (previous === 0) return null

  return (current - previous) / Math.abs(previous)
}

/** Good, bad, flat — or neutral where the catalogue says neither direction is news. */
export function deltaTone(metric: string, change: number | null, override?: DirectionOverride): DeltaTone {
  if (change === null || Math.abs(change) < FLAT_BELOW) return 'flat'

  const spec = SPECS[metric]
  const neutral = override?.neutral ?? spec?.neutral
  if (neutral) return 'neutral'

  const roseIsGood = !(override?.invertGood ?? spec?.invertGood)

  return (change > 0) === roseIsGood ? 'good' : 'bad'
}

/**
 * For a figure the catalogue does not carry.
 *
 * Every metric the dashboard leads with is in `SPECS`, and for those the catalogue is the answer. But
 * surfaces do assemble rows from keys it has never held — a provider's own field, a commerce funnel
 * step — and those had no way to say «down is the win» except by rendering their own pill. An override
 * is narrower than a second component: it supplies the one fact the catalogue is missing and changes
 * nothing else about how a movement is drawn.
 */
export type DirectionOverride = { invertGood?: boolean; neutral?: boolean }

/*
 * A pill rather than bare text — the treatment `MetricStrip` had already arrived at.
 *
 * It was the fifth copy of this rule and the best-looking one: a tinted pill separates the movement
 * from the figure it qualifies, which is what lets a reader scan a row of fourteen cards and see the
 * red ones. Consolidating upward rather than flattening to the plainest version is the point — the
 * uplift is supposed to raise the floor, not lower the ceiling.
 */
const TONE_CLASS: Record<DeltaTone, string> = {
  good: 'text-success bg-[var(--positive-background)]',
  bad: 'text-danger bg-[var(--negative-background)]',
  flat: 'text-text-muted bg-surface-secondary',
  neutral: 'text-text-secondary bg-surface-secondary',
}

export function Delta({
  metric,
  current,
  previous,
  change: given,
  direction,
  since,
  ar = false,
  testid,
}: {
  /** The catalogue key — it decides which direction is good. */
  metric: string
  current?: number | null
  previous?: number | null
  /**
   * A ratio already computed by whoever had both windows (0.12 = +12%).
   *
   * Some payloads state the movement and never send the two figures — the metric strip's items are
   * one — so this accepts either shape. It does NOT accept both opinions: when a ratio is given it is
   * used, because recomputing one from figures the caller may have rounded would quietly disagree
   * with the number the API reported.
   */
  change?: number | null
  /** Only for a key `SPECS` does not carry — see `DirectionOverride`. */
  direction?: DirectionOverride
  /** What it is being compared against, said rather than assumed. */
  since?: string
  ar?: boolean
  testid?: string
}) {
  const change = given ?? deltaOf(current, previous)

  if (change === null || !Number.isFinite(change)) return null

  const tone = deltaTone(metric, change, direction)
  const Icon = tone === 'flat' ? Minus : change > 0 ? ArrowUpRight : ArrowDownRight

  return (
    <span
      data-testid={testid ?? 'delta'}
      data-tone={tone}
      dir="ltr"
      className={`inline-flex items-center gap-0.5 rounded-full px-1.5 py-0.5 text-xs font-semibold ${TONE_CLASS[tone]}`}
      aria-label={`${ar ? 'التغير' : 'Change'} ${(change * 100).toFixed(0)}%`}
    >
      <Icon size={12} aria-hidden />
      <span dir="ltr" className="tnum">
        <Num>{percent(Math.abs(change), 0)}</Num>
      </span>
      {since && <span className="font-medium text-text-muted">{since}</span>}
    </span>
  )
}

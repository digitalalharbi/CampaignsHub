import type { Locale } from '@/stores/ui'
import type { CreativeMetrics } from './api'
import { creativeFigureText } from './creativeMoney'
import { metricState, unavailableChip, unavailableReason } from './metrics'

/**
 * CONTENT-RESULT-AVAILABILITY-001 §4 — a card must show «0» and «—» as different things.
 *
 * ## Why a component and not a longer string
 *
 * `formatMetric` already says «غير مُرسَل», and on a detail page with room for a sentence that is
 * the right answer. On a CARD it is not: three or four of those stacked read as a list of faults,
 * and the owner's instruction is explicit that an unavailable metric must not raise a warning —
 * only a mark quiet enough to be ignored and specific enough to be asked.
 *
 * So the card shows the em dash, and the reason is one hover away. The dash is the figure's own
 * absence rather than a word standing in for it, which is also why it lines up with the numbers
 * beside it instead of pushing the column wider.
 *
 * ## The dotted underline
 *
 * A mark that can be asked a question has to look askable. A bare «—» is indistinguishable from a
 * layout gap, and a warning triangle next to every unmeasured result turns an ordinary fact about
 * an advertising platform into four alarms on one card.
 */
export function MetricValue({
  metrics,
  metricKey,
  currency,
  locale,
}: {
  metrics: CreativeMetrics | null
  metricKey: string
  currency: string | null
  locale: Locale
}) {
  const state = metricState(metrics, metricKey)
  const reason = state.kind === 'not_provided' ? state.reason : undefined

  if (reason === undefined) {
    return <>{creativeFigureText(metrics, metricKey, currency, locale)}</>
  }

  return (
    <span
      data-testid="metric-unavailable"
      data-reason={reason}
      /*
       * `title` rather than a custom tooltip: it is the one hover text a screen reader, a touch
       * device's long-press and a keyboard focus all already understand, and this is a figure on a
       * dense card rather than a surface worth building a popover for.
       */
      title={`${unavailableChip(locale)} — ${unavailableReason(reason, locale)}`}
      className="cursor-help border-b border-dotted border-border-strong text-text-muted decoration-dotted"
    >
      —
    </span>
  )
}

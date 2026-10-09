import { Badge } from '@/components/ui/Badge'
import type { CampaignRelevance } from '@/features/campaigns/campaignRelevance'

/**
 * CONTENT-BROWSER-PARITY-001 — «is this still running on the platform?», on the card.
 *
 * The owner asked for it by name: «إذا كان هناك علامة لتوضيح هل المحتوى فعال أو واقف في المنصة جدا
 * ممتاز». It is not decoration. The library now puts what is running above what has stopped, and a
 * reader who cannot see WHY one card sits above another is back to an order they cannot account for
 * — which is the thing that made him doubt the figures in the first place.
 *
 * ## Three states, because there are three
 *
 * Serving, idle and stopped are what `relevanceOf` returns, and the middle one earns its place:
 * «switched on and producing nothing» is a different thing from «switched off», and it is the one an
 * operator can still act on. Collapsing it into either neighbour would hide exactly the case worth
 * finding.
 *
 * ## What it is NOT read from
 *
 * Never from whether figures exist. A creative that spent 5,000 last month and stopped yesterday is
 * stopped; historical spend implying «active» is the defect this badge exists to make visible rather
 * than one it may repeat. The state comes from the platform's own status and the last ACTIVE date,
 * through the one rule the campaigns workspace and the analytics rows already read.
 */
const COPY: Record<CampaignRelevance, { ar: string; en: string; tone: 'success' | 'warning' | 'neutral' }> = {
  /* Masculine, because a محتوى is masculine — the campaigns workspace says «تعمل» of a حملة. */
  serving: { ar: 'يعمل', en: 'Running', tone: 'success' },
  idle: { ar: 'خامل', en: 'Idle', tone: 'warning' },
  stopped: { ar: 'متوقف', en: 'Stopped', tone: 'neutral' },
}

export function DeliveryBadge({ state, ar }: { state: CampaignRelevance; ar: boolean }) {
  const copy = COPY[state]

  /*
   * The state travels on a wrapper rather than on `Badge`.
   *
   * `Badge` takes a tone, a class and a test id and nothing else, and widening a shared primitive
   * so one caller can hang a data attribute on it is how a UI component ends up with a prop bag.
   * The wrapper carries the machine-readable fact; the badge stays the visual.
   */
  return (
    <span className="w-fit" data-testid="creative-delivery-state" data-state={state}>
      <Badge tone={copy.tone}>{ar ? copy.ar : copy.en}</Badge>
    </span>
  )
}

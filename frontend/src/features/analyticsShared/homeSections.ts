/**
 * ANALYTICS-POPUP-001 — which section of «التحليلات» a card on «الرئيسية» opens.
 *
 * The Owner's order: Home is a brief summary read from the analytics data, and each card opens the MATCHING analytics
 * section in a popup. A card is matched by what it is about (the page it used to link to); a card whose page is not an
 * analytics matter (tasks, approvals, invoices, integrations…) is not matched and keeps its link.
 */
export type AnalyticsSectionId = 'performance' | 'board' | 'platforms' | 'creative' | 'site' | 'quality'

/** `/{portal}/<segment>` → the section that answers the same question. First match wins. */
const BY_SEGMENT: ReadonlyArray<readonly [RegExp, AnalyticsSectionId]> = [
  [/^\/(?:app|agency)\/analytics(?:[/?#]|$)/, 'performance'],
  [/^\/(?:app|agency)\/campaigns(?:[/?#]|$)/, 'board'],
  [/^\/(?:app|agency)\/(?:ad-groups|ads)(?:[/?#]|$)/, 'board'],
  [/^\/(?:app|agency)\/content(?:[/?#]|$)/, 'creative'],
  [/^\/(?:app|agency)\/(?:budgets|spend-limits)(?:[/?#]|$)/, 'board'],
]

export function analyticsSectionFor(to: string): AnalyticsSectionId | null {
  for (const [re, id] of BY_SEGMENT) if (re.test(to)) return id

  return null
}

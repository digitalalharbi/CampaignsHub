/**
 * ENTITY-RELEVANCE-ORDERING-001 — the order the «needs attention» list is presented in.
 *
 * It sorted by severity rank alone. Severity is a small integer, so a project with a dozen flagged
 * campaigns is mostly ties, and `sort` leaves those in the order the list arrived in — the campaigns
 * API's own. Two identical reads could present the same problems in a different sequence, and an
 * operator working down a list they have half-finished cannot tell whether it moved because
 * something happened or because nothing did.
 *
 * Rank first, then a key that cannot move. The same rule, and the same reason, as the campaign
 * breakdown's spend-then-id ordering.
 */
export interface AttentionEntry {
  id: string
  /** Higher is more severe. */
  rank: number
  name: string
}

export function orderAttention<T extends AttentionEntry>(entries: T[]): T[] {
  return [...entries].sort(
    (a, b) => b.rank - a.rank || a.name.localeCompare(b.name) || a.id.localeCompare(b.id),
  )
}

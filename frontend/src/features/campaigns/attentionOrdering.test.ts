import { describe, expect, it } from 'vitest'

import { orderAttention, type AttentionEntry } from './attentionOrdering'

/**
 * ENTITY-RELEVANCE-ORDERING-001 — the «needs attention» list is TOTALLY ordered.
 *
 * It sorted by severity rank alone. Severity is a small integer, so a project with a dozen flagged
 * campaigns is mostly ties — and `Array.prototype.sort` resolves those by the order the list arrived
 * in, which is the campaigns API's own order. Two identical reads could present the same problems in
 * a different sequence, and an operator working down a list they have half-finished cannot tell
 * whether it changed because something happened or because nothing did.
 *
 * The same defect, and the same fix, as the campaign breakdown's spend-only sort: rank first, then a
 * key that cannot move.
 */
const e = (id: string, rank: number, name = 'C'): AttentionEntry => ({ id, rank, name })

describe('the order problems are presented in', () => {
  it('puts the most severe first', () => {
    const out = orderAttention([e('low', 1), e('high', 9), e('mid', 5)])

    expect(out.map((x) => x.id)).toEqual(['high', 'mid', 'low'])
  })

  /*
   * The tie-break, tested on scrambled input — the only form that fails when the rule is removed.
   * Through the page it would pass either way, because the list arrives in name order already.
   */
  it('breaks a tie on a key that cannot move', () => {
    const out = orderAttention([e('c-9', 5, 'Zulu'), e('c-3', 5, 'Alpha'), e('c-7', 5, 'Alpha')])

    // Same rank → by name, then by id. Never by arrival order.
    expect(out.map((x) => x.id)).toEqual(['c-3', 'c-7', 'c-9'])
  })

  it('gives the same answer however the input arrived', () => {
    const rows = [e('a', 3, 'X'), e('b', 3, 'X'), e('c', 7, 'Y')]

    expect(orderAttention(rows).map((x) => x.id)).toEqual(orderAttention([...rows].reverse()).map((x) => x.id))
  })

  it('never drops an entry', () => {
    expect(orderAttention([e('a', 1), e('b', 1), e('c', 1)])).toHaveLength(3)
  })
})

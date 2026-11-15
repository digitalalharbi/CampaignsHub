/**
 * AD-GROUP-TERM-001 — the one name for the level between a campaign and its ads (Owner, 2026-11-15).
 *
 * Arabic says «المجموعات» / «المجموعة» everywhere — rail, tabs, pages, pickers, filters, exports — and the
 * English side says «Ad groups» / «Ad group», whatever the platform calls it (Meta's «ad set», TikTok's
 * «ad group»). The guard in `noForbiddenTerms.test.ts` keeps the retired long form off the screen.
 */
export const AD_GROUPS = { ar: 'المجموعات', en: 'Ad groups' } as const
export const AD_GROUP = { ar: 'المجموعة', en: 'Ad group' } as const

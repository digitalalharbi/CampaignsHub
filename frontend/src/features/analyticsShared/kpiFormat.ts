import { percent, ratio } from '@/features/analytics/format'

/**
 * ANALYTICS-SHARED-001 — the written form of a rate and of a frequency, once.
 *
 * The /r/ report and the internal board each kept their own copy of these three formats. Both agreed on nearly every
 * figure (money and counts already go through the one contract), and disagreed on exactly one: the report printed a
 * frequency as «2.75×» — the same suffix a ROAS carries — while the board printed «2.75». A frequency is how many times a
 * person saw the ad, not a multiple of spend, so the board's reading is the right one and is the one both now use.
 *
 * `parity.test.tsx` renders one fixture through both surfaces and fails on any difference in these.
 */
export const rateText = (n: number | null | undefined): string => (n === null || n === undefined ? '—' : percent(n, 2))

export const frequencyText = (n: number | null | undefined): string => (n === null || n === undefined ? '—' : ratio(n, ''))

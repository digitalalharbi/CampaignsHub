import { getData } from '@/lib/api/client'
import { formatNumber } from '@/lib/numerals'
import { contentWhy, type WhyInput, type WhyRow } from '@/features/analyticsShared/contentWhy'
import { libraryQueryString, type LibraryQuery } from './api'

/**
 * CONTENT-CARDS-001 — where each piece of content stands among the content bought for the SAME objective.
 *
 * Nothing here ranks or classifies. The server (`ContentStanding`) calls the report's own ranker, so the class on a card is
 * the class the live report gives the same content; «ليش نجح / ليش ما نجح» is `contentWhy`, the one function Analytics
 * draws, run over the whole scope. This module reads those answers and words them.
 */
export type StandingClass = 'best' | 'improve' | null

export interface StandingItem {
  id: string
  objective: string | null
  provider: string
  family: string
  /** The metric the order rests on (ROAS for sales, CPL for leads…) — null when nothing in the objective was reported. */
  metric: string | null
  metric_label_ar: string | null
  metric_label_en: string | null
  higher_is_better: boolean | null
  rank: number | null
  of: number | null
  class: StandingClass
  /** The objective's OWN result: orders, visits or leads — never a figure the objective was not bought for. */
  result: { key: string | null; value: number | null; label_ar: string | null; label_en: string | null; missing_ar: string | null; missing_en: string | null }
}

export interface StandingPayload {
  items: StandingItem[]
  why_rows: WhyInput[]
  read: number
  in_scope: number
  window: { from: string; to: string }
}

/** The axes that define WHICH content is being ranked together. Search, health, sort and page only choose what is drawn. */
const SCOPE_KEYS = ['from', 'to', 'providers', 'campaign_ids', 'objectives', 'kinds', 'project_ids', 'client_ids', 'statuses', 'ad_set_ids', 'ad_ids'] as const

export function standingQuery(query: LibraryQuery): LibraryQuery {
  const out: Record<string, unknown> = {}
  for (const key of SCOPE_KEYS) {
    const value = (query as Record<string, unknown>)[key]
    if (value !== undefined) out[key] = value
  }

  return out as LibraryQuery
}

export const getStanding = (query: LibraryQuery) => getData<StandingPayload>(`/content-standing${libraryQueryString(standingQuery(query))}`)

// ── wording ──────────────────────────────────────────────────────────────────────────────────────────

export const CLASS_WORDS: Record<'best' | 'improve', { ar: string; en: string }> = {
  best: { ar: 'الأفضل أداءً', en: 'Best performing' },
  improve: { ar: 'فرصة للتحسين', en: 'Room to improve' },
}

export const NOT_MEASURED = { ar: 'غير مُقاس', en: 'Not measured' }

export function classLabel(c: StandingClass, ar: boolean): string | null {
  return c === null ? null : CLASS_WORDS[c][ar ? 'ar' : 'en']
}

/** «3 من 12» — a position inside the content of the same objective. Null when the item is not in the order. */
export function rankLabel(item: Pick<StandingItem, 'rank' | 'of'> | undefined, ar: boolean): string | null {
  if (!item || item.rank === null || item.of === null) return null

  return ar ? `${formatNumber(item.rank)} من ${formatNumber(item.of)}` : `${formatNumber(item.rank)} of ${formatNumber(item.of)}`
}

export interface Indicator {
  label: string
  /** The figure, or «غير مُقاس» — a result nobody reported is never a zero. */
  text: string
  measured: boolean
}

export function indicatorOf(item: StandingItem | undefined, ar: boolean): Indicator | null {
  if (!item) return null
  const r = item.result
  const label = (ar ? r.label_ar : r.label_en) ?? (ar ? 'النتيجة' : 'Result')
  if (r.key === null || r.value === null) {
    return { label: (ar ? r.label_ar : r.label_en) ?? (ar ? 'نتيجة الهدف' : 'Objective result'), text: ar ? NOT_MEASURED.ar : NOT_MEASURED.en, measured: false }
  }

  return { label, text: formatNumber(r.value, { maximumFractionDigits: r.value % 1 === 0 ? 0 : 2 }), measured: true }
}

// ── why ──────────────────────────────────────────────────────────────────────────────────────────────

export interface WhyReading {
  verdict: 'above' | 'below' | 'level'
  /** The item's click rate against the average of its objective + platform, as a whole percent (+20 = 20% above). */
  gapPercent: number | null
  /** How much of the group's spend it carried, as a whole percent. */
  sharePercent: number | null
  frequency: number | null
  /** The click-rate change over time the fatigue signals report, as a whole percent. */
  ctrChangePercent: number | null
  fatigue: WhyRow['fatigue']
  groupSize: number
}

export function whyReading(row: WhyRow | undefined): WhyReading | null {
  if (!row) return null

  return {
    verdict: row.verdict,
    gapPercent: row.gap === null ? null : Math.round(row.gap * 100),
    sharePercent: row.share === null ? null : Math.round(row.share * 100),
    frequency: row.frequency,
    ctrChangePercent: row.ctrChange === null ? null : Math.round(row.ctrChange * 100),
    fatigue: row.fatigue,
    groupSize: row.groupSize,
  }
}

/** One map of `contentWhy` over the whole scope, by content id. */
export function whyById(rows: readonly WhyInput[]): Map<string, WhyRow> {
  return new Map(contentWhy(rows).map((r) => [r.id, r]))
}

const FATIGUE_WORDS: Record<string, { ar: string; en: string }> = {
  improving: { ar: 'يتحسّن', en: 'Improving' },
  stable: { ar: 'مستقر', en: 'Stable' },
  watch: { ar: 'يحتاج مراقبة', en: 'Watch' },
  fatigued: { ar: 'تعب (إرهاق إعلاني)', en: 'Fatigued' },
  insufficient_data: { ar: 'بيانات قليلة', en: 'Little data' },
}

export const fatigueWord = (status: string, ar: boolean): string => (FATIGUE_WORDS[status] ?? FATIGUE_WORDS.insufficient_data)[ar ? 'ar' : 'en']

/** The headline sentence of the popup: why it worked or why it did not, in plain Saudi. */
export function whySentence(reading: WhyReading | null, ar: boolean): string {
  if (reading === null) {
    return ar
      ? 'غير مُقاس: المقارنة تحتاج 3 محتويات على الأقل بنفس الهدف ونفس المنصة، ولكل واحد 1,000 ظهور.'
      : 'Not measured: the comparison needs at least 3 items with the same objective and platform, 1,000 impressions each.'
  }
  if (reading.gapPercent === null) return ar ? 'غير مُقاس: ما فيه متوسط نقارن عليه.' : 'Not measured: there is no average to compare against.'
  const n = formatNumber(Math.abs(reading.gapPercent))
  if (reading.verdict === 'level') return ar ? 'قريب من متوسط المحتوى بنفس الهدف والمنصة.' : 'Close to the average of content with the same objective and platform.'
  if (reading.verdict === 'above') return ar ? `ليش نجح: نسبة الضغط أعلى من المتوسط بـ ${n}%.` : `Why it worked: click rate is ${n}% above the average.`

  return ar ? `ليش ما نجح: نسبة الضغط أقل من المتوسط بـ ${n}%.` : `Why it did not: click rate is ${n}% below the average.`
}

// ── compare ──────────────────────────────────────────────────────────────────────────────────────────

export interface CompareColumn {
  id: string
  family: string
  resultKey: string | null
  value: number | null
  /** Versus the FIRST selected item (the reference), as a fraction; null for the reference itself or when either figure is missing. */
  delta: number | null
  isReference: boolean
}

export interface ObjectiveComparison {
  comparable: boolean
  /** Why it is not comparable — said, never silently ranked across. */
  reason: 'objective_mismatch' | 'unit_mismatch' | 'not_measured' | null
  family: string | null
  resultKey: string | null
  columns: CompareColumn[]
}

/**
 * Content against content on ONE shared objective indicator.
 *
 * Content bought for different objectives is never ranked against each other: orders, visits and leads are three different
 * things. In that case no delta is produced at all and the reason travels so the popup can say it. The same objective with a
 * different unit (landing page views against clicks) is refused for the same reason.
 */
export function compareOnObjective(items: readonly StandingItem[]): ObjectiveComparison {
  const columns: CompareColumn[] = items.map((i, n) => ({
    id: i.id, family: i.family, resultKey: i.result.key, value: i.result.value, delta: null, isReference: n === 0,
  }))
  const families = new Set(items.map((i) => i.family))
  if (families.size > 1 || families.has('unknown')) return { comparable: false, reason: 'objective_mismatch', family: null, resultKey: null, columns }
  const keys = new Set(items.map((i) => i.result.key))
  const key = items[0]?.result.key ?? null
  if (keys.size > 1) return { comparable: false, reason: 'unit_mismatch', family: items[0].family, resultKey: null, columns }
  if (key === null || items.every((i) => i.result.value === null)) return { comparable: false, reason: 'not_measured', family: items[0]?.family ?? null, resultKey: key, columns }

  const base = columns[0]?.value ?? null
  for (const c of columns.slice(1)) {
    c.delta = base === null || base === 0 || c.value === null ? null : (c.value - base) / Math.abs(base)
  }

  return { comparable: true, reason: null, family: items[0].family, resultKey: key, columns }
}

export const COMPARE_MIN = 2
export const COMPARE_MAX = 4

import { formatMoneyReading, readCostPer, type MoneyTotals } from '@/lib/money/contract'
import { moneyExact } from '@/features/analytics/format'

/**
 * OBJECTIVE-RESULT-001 — what «النتائج» means for ONE campaign, decided by its objective.
 *
 * The mirror of `backend/app/Domains/Campaigns/Support/ObjectiveResult.php`, for the screens that read
 * totals (the campaign page's summary, a platform's slice of a campaign) rather than the
 * `byCampaign` rows that already carry `result_key` / `result`. Where a row carries them, they win:
 * the backend table is the source, and this file only answers the same question the same way.
 *
 * The rule (Owner, MASTER_FINAL §هـ, Appendix A task 1):
 *   awareness → reach (impressions when reach is not stated) · traffic → landing page views when
 *   reported and above zero, else clicks NAMED «النقرات» · engagement → engagements · video → video
 *   views · leads → leads (else form leads) · sales → orders, and revenue/ROAS belong here only ·
 *   app → installs · unknown → no result, «الهدف غير محدد».
 */
export type ResultFamily = 'awareness' | 'traffic' | 'engagement' | 'video' | 'leads' | 'sales' | 'app' | 'unknown'

type Words = { ar: string; en: string }

const TABLE: Record<ResultFamily, { key: string | null; fallback: string | null; then?: string }> = {
  awareness: { key: 'reach', fallback: 'impressions' },
  traffic: { key: 'landing_page_views', fallback: 'clicks' },
  engagement: { key: 'engagements', fallback: null },
  video: { key: 'video_views', fallback: null },
  // «مهتمين = النماذج + أحداث صفحة الهبوط»: a leads campaign's landing-page conversions are landing-page leads.
  leads: { key: 'leads', fallback: 'form_leads', then: 'conversions' },
  sales: { key: 'conversions', fallback: null },
  app: { key: 'installs', fallback: null },
  unknown: { key: null, fallback: null },
}

/** The style guide's words — identical to `ObjectiveResult::LABELS`. */
const LABELS: Record<string, Words & { cost: Words }> = {
  reach: { ar: 'الوصول', en: 'Reach', cost: { ar: 'تكلفة الألف وصول', en: 'Cost per 1,000 reached' } },
  impressions: { ar: 'الظهور', en: 'Impressions', cost: { ar: 'تكلفة الألف ظهور (CPM)', en: 'Cost per 1,000 impressions (CPM)' } },
  landing_page_views: { ar: 'زيارات الصفحة', en: 'Landing page views', cost: { ar: 'تكلفة الزيارة', en: 'Cost per visit' } },
  clicks: { ar: 'النقرات', en: 'Clicks', cost: { ar: 'تكلفة النقرة (CPC)', en: 'Cost per click (CPC)' } },
  engagements: { ar: 'التفاعلات', en: 'Engagements', cost: { ar: 'تكلفة التفاعل (CPE)', en: 'Cost per engagement (CPE)' } },
  video_views: { ar: 'المشاهدات', en: 'Video views', cost: { ar: 'تكلفة المشاهدة (CPV)', en: 'Cost per view (CPV)' } },
  leads: { ar: 'المهتمين', en: 'Leads', cost: { ar: 'تكلفة المهتم (CPL)', en: 'Cost per lead (CPL)' } },
  form_leads: { ar: 'مهتمين النماذج', en: 'Form leads', cost: { ar: 'تكلفة المهتم (CPL)', en: 'Cost per lead (CPL)' } },
  conversions: { ar: 'الطلبات', en: 'Orders', cost: { ar: 'تكلفة الطلب (CPA)', en: 'Cost per order (CPA)' } },
  installs: { ar: 'التثبيتات', en: 'Installs', cost: { ar: 'تكلفة التثبيت (CPI)', en: 'Cost per install (CPI)' } },
}

export type MissingReason = 'objective_unknown' | 'not_reported' | 'mixed'

const MISSING: Record<MissingReason, Words> = {
  objective_unknown: { ar: 'الهدف غير محدد', en: 'Objective not set' },
  not_reported: { ar: 'بدون بيانات: المنصة لم ترسل هذا المؤشر', en: 'No data: the platform did not send this metric' },
  mixed: { ar: 'أهداف مختلفة: كل حملة بنتيجتها', en: 'Different objectives: each campaign has its own result' },
}

/** `CampaignObjective::family()`, for the stored objective values. Anything else is unknown, never guessed. */
const FAMILY_OF: Record<string, ResultFamily> = {
  awareness: 'awareness', reach: 'awareness',
  traffic: 'traffic', landing_page_views: 'traffic',
  engagement: 'engagement',
  video_views: 'video',
  leads: 'leads',
  sales: 'sales', conversions: 'sales', purchases: 'sales', add_to_cart: 'sales',
  app_installs: 'app',
}

export function resultFamilyOf(objective: string | null | undefined): ResultFamily {
  return (objective && FAMILY_OF[objective]) || 'unknown'
}

export interface CampaignResult {
  family: ResultFamily
  /** The metric this campaign's result is — null when its objective is not set. */
  key: string | null
  /** Null when there is no result to state (`missing` says why); a real zero is 0. */
  value: number | null
  label: Words | null
  costLabel: Words | null
  /** Thousand for the awareness units (priced per thousand); one otherwise. */
  per: number
  missing: MissingReason | null
  missingLabel: Words | null
}

type Row = Record<string, unknown> | MoneyTotals | null | undefined

function num(row: Row, key: string): number | null {
  const v = (row as Record<string, unknown> | null | undefined)?.[key]
  return typeof v === 'number' && Number.isFinite(v) ? v : (typeof v === 'string' && v !== '' && Number.isFinite(Number(v)) ? Number(v) : null)
}

/** A key whose meaning depends on the family — identical to `ObjectiveResult::FAMILY_LABELS`. */
const FAMILY_LABELS: Partial<Record<ResultFamily, Record<string, Words & { cost: Words }>>> = {
  leads: { conversions: { ar: 'مهتمين الهبوط', en: 'Landing page leads', cost: { ar: 'تكلفة المهتم (CPL)', en: 'Cost per lead (CPL)' } } },
}

function shape(family: ResultFamily, key: string | null, value: number | null, missing: MissingReason | null): CampaignResult {
  const words = key ? (FAMILY_LABELS[family]?.[key] ?? LABELS[key]) : null
  return {
    family,
    key,
    value,
    label: words ? { ar: words.ar, en: words.en } : null,
    costLabel: words ? words.cost : null,
    per: key === 'reach' || key === 'impressions' ? 1000 : 1,
    missing,
    missingLabel: missing ? MISSING[missing] : null,
  }
}

/**
 * The result of one row. `reported` is the row's own «which keys did a platform send» map; without
 * it a positive figure counts as reported and a zero stays a zero.
 */
export function campaignResult(objective: string | null | undefined, row: Row, reported?: Record<string, boolean> | null): CampaignResult {
  const family = resultFamilyOf(objective)

  // A backend row already carries the answer — the table there is the source.
  const carried = (row as Record<string, unknown> | null | undefined)?.result_key
  if (typeof carried === 'string' && LABELS[carried]) {
    return shape(family, carried, num(row, 'result'), (row as Record<string, unknown>).result_missing_reason as MissingReason | null ?? null)
  }

  const { key, fallback, then } = TABLE[family]
  if (key === null) return shape(family, null, null, 'objective_unknown')

  const chain = [key, ...[fallback, then].filter((k): k is string => typeof k === 'string')]
  const value = (k: string) => num(row, k)
  for (const k of chain) if ((value(k) ?? 0) > 0) return shape(family, k, value(k), null)
  if (reported) for (const k of chain) if (reported[k] === true && value(k) !== null) return shape(family, k, 0, null)

  if (!reported) {
    const k = chain.find((c) => value(c) !== null) ?? key
    return shape(family, k, value(k), value(k) === null ? 'not_reported' : null)
  }

  return shape(family, key, null, 'not_reported')
}

/** The words for a result key — the same table, for a total whose unit was chosen elsewhere. */
export function labelForKey(key: string | null | undefined): Words | null {
  return key && LABELS[key] ? { ar: LABELS[key].ar, en: LABELS[key].en } : null
}

/** The result in words a reader can scan — «2,881 زيارات الصفحة» is the card's job; this is its label. */
export function resultLabel(r: CampaignResult, ar: boolean): string {
  if (r.label) return ar ? r.label.ar : r.label.en
  return ar ? 'النتائج' : 'Results'
}

export function costLabelOf(r: CampaignResult, ar: boolean): string {
  if (r.costLabel) return ar ? r.costLabel.ar : r.costLabel.en
  return ar ? 'تكلفة النتيجة' : 'Cost per result'
}

/** Why there is no result — «الهدف غير محدد» or «بدون بيانات», never a bare «0». */
export function missingText(r: CampaignResult, ar: boolean): string | null {
  return r.missingLabel ? (ar ? r.missingLabel.ar : r.missingLabel.en) : null
}

/**
 * The cost of ONE result, through the money contract: withheld or mixed spend states no cost, and
 * a result of zero has no cost per it.
 */
export function resultCostText(r: CampaignResult, row: Row, currency: string | null): string {
  if (r.key === null || r.value === null || r.value <= 0) return '—'
  const spend = num(row, 'spend')
  const cost = spend === null ? null : (spend / r.value) * r.per
  const bag = { ...(row as Record<string, unknown> | null | undefined ?? {}), __result_cost: cost } as MoneyTotals
  return formatMoneyReading(readCostPer(bag, '__result_cost', r.value / r.per, currency, true), moneyExact)
}

/** Return on ad spend belongs to sales only — every other objective was never bought to return revenue. */
export function returnsRevenue(objective: string | null | undefined): boolean {
  return resultFamilyOf(objective) === 'sales'
}

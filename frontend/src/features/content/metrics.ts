import type { CreativeMetrics } from './api'
import type { Locale } from '@/stores/ui'

/**
 * §15.15 — showing a figure, or saying honestly why there isn't one.
 *
 * The rule this file exists to enforce: **a metric the platform did not send is not zero.**
 *
 * In JavaScript `0` and `null` are both falsy, so `value || '—'` and `value ?? 0` each destroy the
 * distinction in one keystroke, and the result is a card reading «Completion rate 0%» beside forty
 * thousand impressions — which says the video was a catastrophe, when the truth is that a text ad
 * has no completion rate and never did. Every metric is read through `metricState` so that «zero»,
 * «not provided» and «no data yet» are three different renderings and nobody has to remember the
 * rule at each call site.
 */

export type MetricState =
  /** A real, measured figure — including a real zero. */
  | { kind: 'value'; value: number }
  /** The provider does not report this metric for this creative. */
  | { kind: 'not_provided'; reason?: UnavailableReason }
  /** Reported, but the inputs for a ratio are missing — ROAS with no revenue, CPA with no orders. */
  | { kind: 'no_data' }

/**
 * CONTENT-RESULT-AVAILABILITY-001 — why a result is «—», in the server's own words.
 *
 * The three the reader can meet, and they are different facts about somebody's advertising:
 *
 *  - `not_reported` — the platform does not measure this here. Asking again will not help.
 *  - `not_attributable` — the campaign has the figure and it cannot be pinned to THIS creative for
 *    THIS period. The number exists; it is not this creative's to claim.
 *  - `no_activity` — the creative was not serving in the period, so there is nothing to have
 *    measured. Not a gap, a schedule.
 *
 * `reported` is the fourth value and is the ordinary case; it never reaches the reader as a
 * sentence because there is nothing to explain.
 */
export type UnavailableReason = 'not_reported' | 'not_attributable' | 'no_activity'

/** Ratios that must never be shown when their denominator is absent (§15.15). */
const DERIVED = new Set([
  'ctr', 'cpc', 'cpm', 'cpa', 'roas', 'conversion_rate',
  'aov', 'cost_per_view', 'view_rate', 'completion_rate', 'hook_rate',
])

export function metricState(metrics: CreativeMetrics | null, key: string): MetricState {
  if (metrics === null) return { kind: 'no_data' }

  /*
   * Availability is read BEFORE the number, and that order is the whole fix.
   *
   * A provider asked for a metric it does not measure answers `0` — Snapchat is asked for purchases
   * on every creative and returns a zero for an account with no purchase measurement at all. So the
   * figure arrives as a perfectly ordinary number and the old reading, which consulted `reported`
   * only when the value was ABSENT, printed «الطلبات 0» for something nobody has ever measured.
   *
   * The server now says which — CONTENT-RESULT-AVAILABILITY-001 — and a reason other than
   * `reported` outranks the figure beside it. The figure itself is left in the payload: it is real
   * provenance and a developer surface may want it, but it is not what the card claims.
   */
  const availability = metrics.availability?.[key]

  if (availability !== undefined && availability !== 'reported') {
    return { kind: 'not_provided', reason: availability }
  }

  const raw = metrics[key]

  if (typeof raw === 'number') return { kind: 'value', value: raw }

  /*
   * `reported` is the provider's own answer about whether it sends this at all.
   *
   * A derived ratio is never in `reported` — it is computed, not sent — so an absent ratio is «we
   * could not compute it» (no denominator), while an absent RAW metric is «the platform does not
   * send it». Two different sentences, and conflating them puts «Not provided» under a number the
   * platform reports perfectly well.
   */
  if (DERIVED.has(key)) return { kind: 'no_data' }

  return metrics.reported?.[key] === false ? { kind: 'not_provided' } : { kind: 'no_data' }
}

/**
 * How many objective metrics stand beside the card's fixed Spend cell.
 *
 * Not a preference: the grid is two columns and the card has to stay compact, so one fixed figure and
 * three chosen ones is what fits. `CreativeMetrics::HEADLINE_MINIMUM` is the server's matching number.
 */
const BESIDE_SPEND = 3

/**
 * The metrics a card draws BESIDE its fixed Spend cell — Owner defect 95.
 *
 * One function because two things need the same answer and had two: the grid filtered `spend` out of
 * `headline_metrics` and sliced, while the «no displayable metrics» sentence was gated on the length
 * of the UNFILTERED list. `CreativeMetrics::supportable()` returns exactly `['spend']` when nothing
 * else about a creative can be headlined — its deliberate last resort, «the one question asked of
 * every campaign» — so that length was 1 in the one state the sentence exists for, the panel never
 * fired, and the reader got a price with three empty columns and no explanation.
 *
 * Spend is removed rather than kept because the fixed cell already carries it, through the money
 * contract, which is the only reader that can state a withheld amount.
 */
export const besideSpend = (headline: readonly string[]): string[] =>
  headline.filter((key) => key !== 'spend').slice(0, BESIDE_SPEND)

const LABELS: Record<string, { ar: string; en: string }> = {
  spend: { ar: 'الإنفاق', en: 'Spend' },
  impressions: { ar: 'الظهور', en: 'Impressions' },
  clicks: { ar: 'النقرات', en: 'Clicks' },
  conversions: { ar: 'النتائج', en: 'Results' },
  revenue: { ar: 'الإيراد', en: 'Revenue' },
  ctr: { ar: 'نسبة النقر', en: 'CTR' },
  cpc: { ar: 'تكلفة النقرة', en: 'CPC' },
  cpm: { ar: 'تكلفة الألف ظهور', en: 'CPM' },
  cpa: { ar: 'تكلفة النتيجة', en: 'Cost per result' },
  roas: { ar: 'العائد على الإنفاق', en: 'ROAS' },
  conversion_rate: { ar: 'معدل التحويل', en: 'Conversion rate' },
  frequency: { ar: 'التكرار', en: 'Frequency' },
  reach: { ar: 'الوصول', en: 'Reach' },
  video_views: { ar: 'مشاهدات الفيديو', en: 'Video views' },
  view_rate: { ar: 'معدل المشاهدة', en: 'View rate' },
  completion_rate: { ar: 'معدل الإكمال', en: 'Completion rate' },
  active_days: { ar: 'أيام النشاط', en: 'Active days' },
  /*
   * The rest of `MarketingPath::headlineMetrics()` — every key a path can actually ask for.
   *
   * These were missing, and `metricLabel` falls back to the key, so a sales creative's own headline
   * row read `orders`, `aov` and `cost_per_view` in the middle of Arabic copy. The list is not
   * decorative: it has to cover the enum, or the surface that shows the most important metrics is
   * the one that shows them untranslated.
   */
  orders: { ar: 'الطلبات', en: 'Orders' },
  aov: { ar: 'متوسط قيمة الطلب', en: 'Average order value' },
  cost_per_lpv: { ar: 'تكلفة زيارة صفحة الهبوط', en: 'Cost per landing page view' },
  landing_page_views: { ar: 'زيارات صفحة الهبوط', en: 'Landing page views' },
  cost_per_view: { ar: 'تكلفة المشاهدة', en: 'Cost per view' },
  hook_rate: { ar: 'معدل الجذب', en: 'Hook rate' },
  add_to_cart: { ar: 'الإضافة إلى السلة', en: 'Add to cart' },
  checkout: { ar: 'بدء الدفع', en: 'Checkout' },
  purchases: { ar: 'عمليات الشراء', en: 'Purchases' },
  engagements: { ar: 'التفاعلات', en: 'Engagements' },
  video_views_3s: { ar: 'مشاهدات 3 ثوانٍ', en: '3-second views' },
  video_p100: { ar: 'مشاهدات مكتملة', en: 'Completed views' },
  video_avg_watch_seconds: { ar: 'متوسط مدة المشاهدة', en: 'Average watch time' },
  /*
   * Owner defect 94d — the same hole as the comment above, three families later.
   *
   * `metricLabel` falls back to the KEY, and eight keys that `ObjectiveFamily::headlineMetrics()`
   * can ask for had no entry: a leads creative's headline row read `leads` and `cpl`, an engagement
   * creative read `engagement_rate` and `cpe`, and an app creative read `installs` and `cpi` — raw
   * English identifiers in the middle of Arabic copy, on the row that carries the most important
   * figures the card has.
   *
   * A list that must cover an enum is guarded by a test now rather than by remembering, because this
   * is the second time it drifted and the first fix was a comment saying it must not.
   */
  leads: { ar: 'العملاء المحتملون', en: 'Leads' },
  cpl: { ar: 'تكلفة العميل المحتمل', en: 'Cost per lead' },
  installs: { ar: 'التثبيتات', en: 'Installs' },
  cpi: { ar: 'تكلفة التثبيت', en: 'Cost per install' },
  sign_ups: { ar: 'التسجيلات', en: 'Sign-ups' },
  app_opens: { ar: 'فتحات التطبيق', en: 'App opens' },
  page_views: { ar: 'مشاهدات الصفحة', en: 'Page views' },
  engagement_rate: { ar: 'معدل التفاعل', en: 'Engagement rate' },
  cpe: { ar: 'تكلفة التفاعل', en: 'Cost per engagement' },
}

/**
 * The sentence a reader gets instead of a figure — PRODUCT-COPY, not a status code.
 *
 * One line each, about the platform and the period, in the reader's own language. None of them
 * names a column, a grain, a request or a failure, because none of those is the customer's problem:
 * what varies is what an advertising platform exposes for an account, and a product that says so
 * plainly is not a product that looks broken.
 */
export function unavailableReason(reason: UnavailableReason, locale: Locale): string {
  const ar = locale === 'ar'

  switch (reason) {
    case 'not_reported':
      return ar
        ? 'بيانات التحويل غير متاحة لهذا المحتوى ضمن مستوى القياس الحالي.'
        : 'Conversion data is not available for this content at the current level of measurement.'
    case 'not_attributable':
      return ar
        ? 'تتوفر بيانات التحويل للحملة، لكن لا يمكن نسبها لهذا المحتوى بدقة خلال الفترة المحددة.'
        : 'The campaign has conversion data, but it cannot be attributed to this content precisely for the selected period.'
    case 'no_activity':
      return ar
        ? 'لم تُرسل المنصة نتيجة قابلة للقياس لهذا المحتوى خلال الفترة المحددة.'
        : 'The platform reported no measurable result for this content during the selected period.'
  }
}

/** The short form beside a «—», for a card that must stay compact. */
export function unavailableChip(locale: Locale): string {
  return locale === 'ar' ? 'غير متاح لهذا المحتوى' : 'Not available for this content'
}

export const metricLabel = (key: string, locale: Locale): string =>
  LABELS[key] ? LABELS[key][locale === 'ar' ? 'ar' : 'en'] : key

const RATE = new Set(['ctr', 'conversion_rate', 'view_rate', 'completion_rate', 'hook_rate', 'engagement_rate'])
// Every cost carries its currency. `aov`, `cost_per_view` and `cost_per_lpv` were missing, so three
// headline figures rendered as bare numbers beside ones that named the currency — which reads as a
// count rather than as money.
// `cpl`, `cpi` and `cpe` are costs too — a cost without its currency reads as a count.
const MONEY = new Set(['spend', 'revenue', 'cpc', 'cpm', 'cpa', 'aov', 'cost_per_view', 'cost_per_lpv', 'cpl', 'cpi', 'cpe'])

/**
 * Which of the primitive's kinds a creative metric is — the one place that decides.
 *
 * `formatMetric` above answers the same question inline, and it has to keep doing so for the places
 * that render a metric OUTSIDE a table. What must not happen is a third answer: a table that decided
 * `cpa` was a plain number would print it without a currency beside one that printed it with, and a
 * reader comparing the two surfaces has no way to know which is right.
 */
export function metricKind(key: string): 'number' | 'money' | 'percent' | 'ratio' {
  if (RATE.has(key)) return 'percent'
  if (key === 'roas') return 'ratio'
  if (MONEY.has(key)) return 'money'

  return 'number'
}

/**
 * A metric as text — Latin digits in both languages, per the contract.
 *
 * Arabic-Indic digits were tried and abandoned: figures sit beside English metric names, currency
 * codes and dates, and the mixture is unreadable in a sentence and unusable in a table column that
 * has to align.
 */
/**
 * CREATIVE-MONEY-TRUTH-001 — `currency` is required, and that is the point.
 *
 * It used to default to `'SAR'`. Twelve call sites omitted it, so twelve surfaces printed «SAR»
 * beside whatever number they held — including the Creative Library card's own spend, on a project
 * whose money is in USD. A default that is right for most customers is not a default, it is a wrong
 * answer for the rest of them, arrived at silently.
 *
 * Making it required is what stops that returning: a new call site cannot omit it, because the
 * compiler will not let it. `null` is an allowed value and means «no single currency can be named» —
 * the reach spans projects reporting differently, or the pipeline has recorded nothing to convert
 * into yet — and a monetary figure is then refused rather than labelled with a guess.
 */
export function formatMetric(state: MetricState, key: string, locale: Locale, currency: string | null): string {
  const ar = locale === 'ar'

  if (state.kind === 'not_provided') return ar ? 'غير مُرسَل' : 'Not provided'
  if (state.kind === 'no_data') return ar ? 'لا توجد بيانات' : 'No data'

  const { value } = state

  if (RATE.has(key)) return `${(value * 100).toFixed(2)}%`
  if (key === 'roas') return `${value.toFixed(2)}×`
  if (MONEY.has(key)) {
    // A number whose currency cannot be named is not a figure anybody can act on, and printing it
    // bare invites the reader to assume the one they expect.
    if (currency === null) return ar ? 'العملة غير محددة' : 'Currency not stated'

    return `${value.toLocaleString('en-US', { maximumFractionDigits: 2 })} ${currency}`
  }

  return value.toLocaleString('en-US', { maximumFractionDigits: value % 1 === 0 ? 0 : 2 })
}

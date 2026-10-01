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
 * CONTENT-RESULT-AVAILABILITY-001 — what a result figure IS, in the server's own five words.
 *
 * Two of them are figures and three are dashes, and the three say different things about somebody's
 * advertising:
 *
 *  - `not_reported` — the platform sent no value for this here. An absent field, not a zero.
 *  - `measurement_unverified` — a zero arrived and nothing proves this account measures the metric
 *    at all. Deliberately not «not provided»: the provider DID send something, and what is unknown
 *    is whether it means anything. Calling it «not provided» would replace one unsupported claim
 *    with another.
 *  - `not_attributable` — the figure exists for the campaign or the account in this period and is
 *    not this creative's. The number is real; it is not this ad's to claim.
 *
 * `reported_value` and `real_zero_confirmed` are the two that print the figure. A zero is only ever
 * shown as a zero under the second, because that is the only state in which «this ad sold nothing»
 * is something the product can actually say.
 */
export type ResultAvailability =
  | 'reported_value'
  | 'real_zero_confirmed'
  | 'not_reported'
  | 'measurement_unverified'
  | 'not_attributable'

/** The three a reader meets as a dash and a sentence. */
export type UnavailableReason = Exclude<ResultAvailability, 'reported_value' | 'real_zero_confirmed'>

const SHOWS_FIGURE: ReadonlySet<string> = new Set(['reported_value', 'real_zero_confirmed'])

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

  if (availability !== undefined && !SHOWS_FIGURE.has(availability)) {
    return { kind: 'not_provided', reason: availability as UnavailableReason }
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
        ? 'لم تُرسل المنصة هذا المؤشر لهذا المحتوى.'
        : 'The platform did not send this metric for this content.'
    case 'measurement_unverified':
      /*
       * Says what is unknown, and no more.
       *
       * «غير متاح» would claim the metric cannot be measured here, which is the claim this state
       * exists because nobody can make: a new account with a working pixel and no sales yet looks
       * exactly like an account with no pixel. What the reader is told is the truth — nobody has
       * verified it — and that is also what tells them where to look.
       */
      return ar
        ? 'لم يتم التحقق من توفر قياس التحويل لهذا الحساب.'
        : 'Conversion measurement has not been verified for this account.'
    case 'not_attributable':
      return ar
        ? 'تتوفر بيانات التحويل، لكن لا يمكن نسبها لهذا المحتوى بدقة خلال الفترة المحددة.'
        : 'Conversion data is available, but it cannot be attributed to this content precisely for the selected period.'
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

  if (state.kind === 'not_provided') {
    /*
     * «غير مُرسَل» is accurate for exactly one of the three reasons — CONTENT-RESULT-AVAILABILITY-001.
     *
     * It is a statement that the platform sent nothing, which is true of `not_reported` and false of
     * the other two: an unverified zero DID arrive, and an unattributable figure exists and belongs
     * to somebody else. Printing it for all three would put a wrong sentence under the figure on
     * every surface that has no room for a tooltip.
     *
     * Those two render the dash here and carry their sentence where there is room for one —
     * {@see MetricValue} on the card and the table, which is where a reader meets them.
     */
    return state.reason === undefined || state.reason === 'not_reported'
      ? (ar ? 'غير مُرسَل' : 'Not provided')
      : '—'
  }
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

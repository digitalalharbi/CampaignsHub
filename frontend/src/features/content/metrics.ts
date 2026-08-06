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
  | { kind: 'not_provided' }
  /** Reported, but the inputs for a ratio are missing — ROAS with no revenue, CPA with no orders. */
  | { kind: 'no_data' }

/** Ratios that must never be shown when their denominator is absent (§15.15). */
const DERIVED = new Set([
  'ctr', 'cpc', 'cpm', 'cpa', 'roas', 'conversion_rate',
  'aov', 'cost_per_view', 'view_rate', 'completion_rate', 'hook_rate',
])

export function metricState(metrics: CreativeMetrics | null, key: string): MetricState {
  if (metrics === null) return { kind: 'no_data' }

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
}

export const metricLabel = (key: string, locale: Locale): string =>
  LABELS[key] ? LABELS[key][locale === 'ar' ? 'ar' : 'en'] : key

const RATE = new Set(['ctr', 'conversion_rate', 'view_rate', 'completion_rate'])
const MONEY = new Set(['spend', 'revenue', 'cpc', 'cpm', 'cpa'])

/**
 * A metric as text — Latin digits in both languages, per the contract.
 *
 * Arabic-Indic digits were tried and abandoned: figures sit beside English metric names, currency
 * codes and dates, and the mixture is unreadable in a sentence and unusable in a table column that
 * has to align.
 */
export function formatMetric(state: MetricState, key: string, locale: Locale, currency = 'SAR'): string {
  const ar = locale === 'ar'

  if (state.kind === 'not_provided') return ar ? 'غير مُرسَل' : 'Not provided'
  if (state.kind === 'no_data') return ar ? 'لا توجد بيانات' : 'No data'

  const { value } = state

  if (RATE.has(key)) return `${(value * 100).toFixed(2)}%`
  if (key === 'roas') return `${value.toFixed(2)}×`
  if (MONEY.has(key)) {
    return `${value.toLocaleString('en-US', { maximumFractionDigits: 2 })} ${currency}`
  }

  return value.toLocaleString('en-US', { maximumFractionDigits: value % 1 === 0 ? 0 : 2 })
}

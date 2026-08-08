import type { MetricItem, MetricReading } from '@/components/ui/MetricStrip'
import type { MetricTotals, Summary } from '@/features/analytics/api'
import { money, num, percent, ratio } from '@/features/analytics/format'

/**
 * Which metrics lead, for the money this campaign is — UX-DASH-001, and §14.6's rule applied.
 *
 * ## One catalogue, read by the dashboard AND the reports
 *
 * This began under `features/dashboard`. It moved here when the reports needed the same answer,
 * because the alternative was a second list of layouts — and two lists describing one rule is how
 * the dashboard and the report come to disagree about what an awareness campaign is judged on
 * without anybody noticing. The server says the same thing in `MarketingPath::headlineMetrics()`
 * and `ReportObjectiveLens`; this is the client's single copy of it.
 *
 * ## Why a layout per objective rather than one row of everything
 *
 * `MetricTotals` carries thirty-odd figures and a dashboard that prints all of them has ranked
 * nothing. Worse, most of them are wrong QUESTIONS rather than wrong answers: a cost per order on a
 * brand campaign that was never meant to sell anything is not an extra column, it is an inflated
 * number a client will set next month's budget on. `MarketingPath::headlineMetrics()` says the same
 * thing on the server; this is the dashboard's side of it.
 *
 * Four to six lead. The rest are `secondary` — one visible click away, on the page.
 *
 * ## Absence, three ways
 *
 * A base metric that no platform reported is `not_provided` — that is what `summary.reported` is
 * for, and it is the only way to tell it from a measured zero, since the sums coalesce. A derived
 * ratio is `no_data` when it is null, which means its denominator was missing. And a real zero
 * stays a zero. `read()` is the only place a value meets a formatter, so there is no call site
 * where `?? 0` could quietly turn the first two into the third.
 */

type Fmt = (n: number) => string

const pct2 = (n: number) => percent(n, 2)
const times = (n: number) => ratio(n, '×')

export type Spec = {
  label: { ar: string; en: string }
  format: Fmt
  /** Computed from sums rather than sent — a null means «no denominator», not «never reported». */
  derived?: boolean
  /** Down is the good direction: every cost-per. */
  invertGood?: boolean
  /** Neither direction is good — colouring it would be a judgement the figure does not support. */
  neutral?: boolean
  hint: { ar: string; en: string }
}

/**
 * Every metric the dashboard can lead with, with its definition.
 *
 * The definitions are the tooltips. They exist because «CPM» and «تكلفة الألف ظهور» are both
 * jargon to the person paying for the campaign, and a dashboard that assumes its reader is a media
 * buyer is a dashboard only media buyers can use.
 */
export const SPECS: Record<string, Spec> = {
  spend: {
    label: { ar: 'الإنفاق', en: 'Spend' },
    format: money,
    invertGood: true,
    neutral: true,
    hint: { ar: 'ما صُرف فعليًا على الإعلانات خلال الفترة، بعملة المشروع.', en: 'What the ads actually cost over the period, in the project currency.' },
  },
  impressions: {
    label: { ar: 'الظهور', en: 'Impressions' },
    format: num,
    hint: { ar: 'عدد مرات عرض الإعلان — قد يُعرض على الشخص نفسه أكثر من مرة.', en: 'How many times the ad was shown — the same person can be counted more than once.' },
  },
  reach: {
    label: { ar: 'الوصول', en: 'Reach' },
    format: num,
    hint: { ar: 'عدد الأشخاص المختلفين الذين رأوا الإعلان مرة واحدة على الأقل.', en: 'How many different people saw the ad at least once.' },
  },
  frequency: {
    label: { ar: 'التكرار', en: 'Frequency' },
    format: (n) => ratio(n, ''),
    derived: true,
    neutral: true,
    hint: { ar: 'متوسط عدد مرات رؤية الشخص الواحد للإعلان = الظهور ÷ الوصول.', en: 'How many times the average person saw the ad — impressions ÷ reach.' },
  },
  cpm: {
    label: { ar: 'تكلفة الألف ظهور', en: 'Cost per 1,000 impressions' },
    format: money,
    derived: true,
    invertGood: true,
    hint: { ar: 'تكلفة كل ألف ظهور — سعر الوصول إلى الجمهور.', en: 'The cost of a thousand impressions — the price of reaching an audience.' },
  },
  clicks: {
    label: { ar: 'النقرات', en: 'Clicks' },
    format: num,
    hint: { ar: 'عدد النقرات على الإعلان.', en: 'How many times the ad was clicked.' },
  },
  ctr: {
    label: { ar: 'معدل النقر', en: 'Click-through rate' },
    format: pct2,
    derived: true,
    hint: { ar: 'نسبة النقر = النقرات ÷ الظهور. تقيس مدى جذب الإعلان.', en: 'Click-through rate — clicks ÷ impressions. How compelling the ad is.' },
  },
  cpc: {
    label: { ar: 'تكلفة النقرة', en: 'Cost per click' },
    format: money,
    derived: true,
    invertGood: true,
    hint: { ar: 'تكلفة النقرة الواحدة = الإنفاق ÷ النقرات.', en: 'What one click costs — spend ÷ clicks.' },
  },
  landing_page_views: {
    label: { ar: 'زيارات صفحة الهبوط', en: 'Landing page views' },
    format: num,
    hint: { ar: 'من نقر ووصل فعلًا إلى الصفحة — أقل من النقرات دائمًا.', en: 'Clicks that actually arrived on the page — always fewer than clicks.' },
  },
  video_views: {
    label: { ar: 'مشاهدات الفيديو', en: 'Video views' },
    format: num,
    hint: { ar: 'عدد مرات بدء مشاهدة الفيديو، بحسب تعريف كل منصة.', en: 'How many times the video started playing, by each platform’s own definition.' },
  },
  video_completions: {
    label: { ar: 'مشاهدات مكتملة', en: 'Completed views' },
    format: num,
    hint: { ar: 'من شاهد الفيديو حتى نهايته.', en: 'People who watched the video to the end.' },
  },
  video_completion_rate: {
    label: { ar: 'معدل إكمال الفيديو', en: 'Completion rate' },
    format: pct2,
    derived: true,
    hint: { ar: 'نسبة من أكمل الفيديو من إجمالي من بدأه.', en: 'The share of viewers who watched to the end.' },
  },
  engagements: {
    label: { ar: 'التفاعل', en: 'Engagement' },
    format: num,
    hint: { ar: 'إعجاب، تعليق، مشاركة، حفظ — كما تحسبها المنصة.', en: 'Likes, comments, shares and saves, as the platform counts them.' },
  },
  engagement_rate: {
    label: { ar: 'معدل التفاعل', en: 'Engagement rate' },
    format: pct2,
    derived: true,
    hint: { ar: 'التفاعلات ÷ الظهور.', en: 'Engagements ÷ impressions.' },
  },
  cpe: {
    label: { ar: 'تكلفة التفاعل', en: 'Cost per engagement' },
    format: money,
    derived: true,
    invertGood: true,
    hint: { ar: 'تكلفة التفاعل الواحد.', en: 'What one engagement costs.' },
  },
  leads: {
    label: { ar: 'العملاء المحتملون', en: 'Leads' },
    format: num,
    hint: { ar: 'من ترك بياناته — ليس عميلًا مؤكدًا ولا عملية بيع.', en: 'People who left their details — not a confirmed customer, and not a sale.' },
  },
  qualified_leads: {
    label: { ar: 'المؤهلون', en: 'Qualified leads' },
    format: num,
    hint: { ar: 'العملاء المحتملون الذين اجتازوا تأهيل فريقك.', en: 'Leads your team has qualified.' },
  },
  cpl: {
    label: { ar: 'تكلفة العميل المحتمل', en: 'Cost per lead' },
    format: money,
    derived: true,
    invertGood: true,
    hint: { ar: 'تكلفة العميل المحتمل الواحد.', en: 'What one lead costs.' },
  },
  conversions: {
    label: { ar: 'النتائج', en: 'Results' },
    format: num,
    hint: {
      ar: 'مجموع ما أبلغت به كل منصة عن الحدث الذي حُسّنت له الحملة — وليس عدد طلبات فريدة.',
      en: 'The sum of what each platform reported for the event its campaign optimised for — not a count of unique orders.',
    },
  },
  conversion_rate: {
    label: { ar: 'معدل التحويل', en: 'Conversion rate' },
    format: pct2,
    derived: true,
    hint: { ar: 'النتائج ÷ النقرات.', en: 'Results ÷ clicks.' },
  },
  add_to_cart: {
    label: { ar: 'الإضافة للسلة', en: 'Add to cart' },
    format: num,
    hint: { ar: 'عدد مرات إضافة منتج إلى السلة بعد الإعلان.', en: 'How many times a product was added to a basket after the ad.' },
  },
  checkout: {
    label: { ar: 'بدء الدفع', en: 'Checkout started' },
    format: num,
    hint: { ar: 'من بدأ خطوة الدفع — ليس طلبًا مكتملًا.', en: 'People who began checkout — not a completed order.' },
  },
  purchases: {
    label: { ar: 'الطلبات', en: 'Orders' },
    format: num,
    hint: { ar: 'عمليات الشراء التي أبلغت بها المنصات — لا سجل المتجر.', en: 'Purchases the platforms reported — not the store’s own ledger.' },
  },
  revenue: {
    label: { ar: 'قيمة الطلبات', en: 'Order value' },
    format: money,
    hint: { ar: 'قيمة المبيعات كما تقدّرها بكسلات المنصات.', en: 'Sales value as the platforms’ pixels estimate it.' },
  },
  cpa: {
    label: { ar: 'تكلفة النتيجة', en: 'Cost per result' },
    format: money,
    derived: true,
    invertGood: true,
    hint: { ar: 'الإنفاق ÷ النتائج، لهذا الهدف وحده.', en: 'Spend ÷ results, for this objective alone.' },
  },
  roas: {
    label: { ar: 'العائد على الإنفاق', en: 'Return on ad spend' },
    format: times,
    derived: true,
    hint: { ar: 'العائد على الإنفاق الإعلاني = الإيراد ÷ الإنفاق.', en: 'Return on ad spend — revenue ÷ spend.' },
  },
  aov: {
    label: { ar: 'متوسط قيمة الطلب', en: 'Average order value' },
    format: money,
    derived: true,
    hint: { ar: 'الإيراد ÷ عدد المشتريات.', en: 'Revenue ÷ purchases.' },
  },
  installs: {
    label: { ar: 'التحميلات', en: 'Installs' },
    format: num,
    hint: { ar: 'عدد مرات تثبيت التطبيق المنسوبة للحملة.', en: 'App installs attributed to the campaign.' },
  },
  cpi: {
    label: { ar: 'تكلفة التحميل', en: 'Cost per install' },
    format: money,
    derived: true,
    invertGood: true,
    hint: { ar: 'تكلفة التثبيت الواحد.', en: 'What one install costs.' },
  },
  registrations: {
    label: { ar: 'التسجيلات', en: 'Registrations' },
    format: num,
    hint: { ar: 'من أنشأ حسابًا بعد التثبيت.', en: 'People who created an account after installing.' },
  },
  in_app_events: {
    label: { ar: 'أحداث داخل التطبيق', en: 'In-app events' },
    format: num,
    hint: { ar: 'الأحداث التي عرّفتها داخل التطبيق وأبلغت بها المنصة.', en: 'Events you defined inside the app that the platform reported.' },
  },
}

/**
 * The layouts — what leads, and what folds, for each objective and each path.
 *
 * `spend` is in every layout because it is the one figure that means the same thing whatever the
 * campaign was for. It is never the LEAD metric though: what the money bought is the answer, and
 * what it cost is the question.
 */
export type Layout = { primary: string[]; secondary: string[] }

const OBJECTIVE_LAYOUTS: Record<string, Layout> = {
  awareness: {
    primary: ['reach', 'impressions', 'frequency', 'cpm'],
    secondary: ['spend', 'video_views', 'video_completions', 'video_completion_rate', 'clicks', 'ctr', 'engagements'],
  },
  traffic: {
    primary: ['clicks', 'ctr', 'cpc', 'landing_page_views'],
    secondary: ['spend', 'impressions', 'reach', 'cpm', 'conversion_rate'],
  },
  leads: {
    primary: ['leads', 'cpl', 'conversion_rate', 'spend'],
    secondary: ['qualified_leads', 'clicks', 'ctr', 'cpc', 'impressions', 'landing_page_views'],
  },
  /*
   * Sales leads with the order, its cost, its value and the return — the four a person decides on.
   *
   * «الإضافة للسلة» heads the secondary row rather than the primary one: it is a step ON THE WAY to
   * an order, so it explains a number rather than being one, and a five-card row stops being scanned.
   */
  sales: {
    primary: ['purchases', 'cpa', 'revenue', 'roas'],
    secondary: ['add_to_cart', 'checkout', 'conversion_rate', 'spend', 'aov', 'clicks', 'ctr', 'cpc', 'impressions', 'landing_page_views'],
  },
  app_installs: {
    primary: ['installs', 'cpi', 'registrations', 'spend'],
    secondary: ['in_app_events', 'clicks', 'ctr', 'impressions', 'cpm'],
  },
  engagement: {
    primary: ['engagements', 'engagement_rate', 'cpe', 'spend'],
    secondary: ['video_views', 'video_completion_rate', 'impressions', 'reach', 'clicks', 'ctr'],
  },
  /*
   * A video buy is awareness money with a different unit of attention.
   *
   * It leads with views and how many of them finished — not with reach, because the question a video
   * budget answers is whether the thing was WATCHED, and a completion rate says that where an
   * impression count cannot.
   */
  video: {
    primary: ['video_views', 'video_completion_rate', 'cpm', 'impressions'],
    secondary: ['spend', 'video_completions', 'reach', 'frequency', 'clicks', 'ctr', 'engagements'],
  },
}

/** A path is coarser than an objective, so it leads with what every objective inside it shares. */
const PATH_LAYOUTS: Record<string, Layout> = {
  awareness: {
    primary: ['impressions', 'reach', 'cpm', 'spend'],
    secondary: ['frequency', 'video_views', 'video_completion_rate', 'clicks', 'ctr', 'engagements'],
  },
  traffic: {
    primary: ['clicks', 'ctr', 'cpc', 'landing_page_views'],
    secondary: ['spend', 'impressions', 'reach', 'cpm', 'conversion_rate'],
  },
  conversion: {
    primary: ['conversions', 'cpa', 'conversion_rate', 'spend'],
    secondary: ['purchases', 'add_to_cart', 'revenue', 'roas', 'aov', 'leads', 'cpl', 'clicks', 'ctr'],
  },
}

/**
 * Mixed objectives get operational figures ONLY — never a blended ROAS or CPA.
 *
 * A cost per result across a brand campaign and a sales campaign divides one objective's money by
 * another objective's events. The arithmetic works and the number is meaningless, which is worse
 * than a gap: nothing on screen tells the reader not to act on it.
 */
const MIXED_LAYOUT: Layout = {
  primary: ['spend', 'impressions', 'clicks', 'ctr'],
  secondary: ['reach', 'cpm', 'cpc', 'frequency', 'landing_page_views'],
}

/**
 * What a cost-per-result is CALLED, for this objective — METRIC-NAMES-001.
 *
 * `cpa` is one stored figure with several honest names: on a sales campaign it is the cost of an
 * order, on a lead campaign the cost of a lead, on an app campaign the cost of an install. Printing
 * «تكلفة النتيجة» everywhere is not wrong so much as vague — and vague is what makes a reader stop
 * trusting the number, because they cannot tell what «نتيجة» meant for this campaign.
 *
 * A label override rather than separate metrics, because they are the same arithmetic over the same
 * column: splitting them would mean four ways to compute one thing.
 */
const OBJECTIVE_LABELS: Record<string, Record<string, { ar: string; en: string }>> = {
  // Sales is the only objective that needs one. A leads layout already leads with `cpl` and an app
  // layout with `cpi`, each of which carries its own name — so overriding `cpa` for them would be a
  // map entry describing behaviour that never happens, which is worse than none: the next reader
  // would believe the mechanism is doing more than it is.
  sales: { cpa: { ar: 'تكلفة الطلب', en: 'Cost per order' }, conversions: { ar: 'الطلبات', en: 'Orders' } },
}

export function layoutFor(objective: string, path: string): Layout {
  if (objective !== 'all' && OBJECTIVE_LAYOUTS[objective]) return OBJECTIVE_LAYOUTS[objective]
  if (path !== 'all' && PATH_LAYOUTS[path]) return PATH_LAYOUTS[path]

  return MIXED_LAYOUT
}

/**
 * One metric as a reading — the only place a value meets a formatter.
 *
 * The order of the two guards is the whole point. `not_provided` is checked FIRST for a base
 * metric, because `current[key]` is a coalesced `0` in exactly that case and reading the value
 * first would print the zero the rest of this file exists to prevent.
 */
export function readMetric(
  key: string,
  spec: Spec,
  totals: Record<string, number | null> | MetricTotals | undefined,
  reported: Record<string, boolean> | undefined,
): MetricReading {
  const value = (totals as Record<string, number | null> | undefined)?.[key] as number | null | undefined

  if (!spec.derived && reported && reported[key] === false) return { kind: 'not_provided' }
  if (value === null || value === undefined) return { kind: 'no_data' }

  return { kind: 'value', text: spec.format(value) }
}

export function dashboardMetrics(
  objective: string,
  path: string,
  summary: Summary | undefined,
  ar: boolean,
): { primary: MetricItem[]; secondary: MetricItem[] } {
  const layout = layoutFor(objective, path)

  const build = (keys: string[], lead: boolean): MetricItem[] =>
    keys
      .filter((key) => SPECS[key])
      .map((key, index) => {
        const spec = SPECS[key]

        const named = OBJECTIVE_LABELS[objective]?.[key]

        return {
          key,
          label: named ? (ar ? named.ar : named.en) : (ar ? spec.label.ar : spec.label.en),
          reading: readMetric(key, spec, summary?.current, summary?.reported),
          delta: summary?.delta?.[key as keyof MetricTotals] ?? null,
          invertGood: spec.invertGood,
          neutral: spec.neutral,
          hint: ar ? spec.hint.ar : spec.hint.en,
          // The first card is what this objective is judged on — unless that is spend, which is
          // what the objective COST rather than what it achieved.
          lead: lead && index === 0 && key !== 'spend',
        }
      })

  return { primary: build(layout.primary, true), secondary: build(layout.secondary, false) }
}

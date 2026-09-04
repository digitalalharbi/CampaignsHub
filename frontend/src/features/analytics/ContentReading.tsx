import { StatCard } from '@/components/ui/StatCard'
import { compact, moneyExact, num, percent, ratio } from '@/features/analytics/format'
import { useUi } from '@/stores/ui'
import type { ContentIntelligence, FormatRow } from '@/features/content/api'

/**
 * ANALYTICS-DIFFERENTIATION-001 — content intelligence, above the ranked table rather than instead of it.
 *
 * ## Why the table was not enough
 *
 * «Which ad did best» is a Dashboard question — what is happening now — and the tab answered only
 * that. The winner is one asset, already made and already spent on; being told its name does not
 * tell a reader what to commission next. So this asks the question whose answer transfers: what KIND
 * of content earns its money on this objective, and how much of the budget is standing behind the
 * other kinds.
 *
 * ## Its shape is its argument, and it is not a row of KPI cards
 *
 * A WIDE card for the comparison, because formats are read down a list against a shared scale; two
 * SQUARE cards beside it for the single figures — the money not on the leading format, and how much
 * content the reading actually spans. That is the requirement's grid, and it is deliberately not the
 * Dashboard's.
 *
 * ## It declines more often than it speaks
 *
 * Most accounts run one format, or run a second one with a single asset in it. Both are refusals
 * here, and both name the true state of the account rather than rendering an empty frame — a
 * comparison invented from one video and one still would be the exact defect this surface exists to
 * avoid.
 */
const REFUSAL: Record<string, { ar: string; en: string }> = {
  no_creative_reported_in_this_period: {
    ar: 'لم يُبلِّغ أي إعلان عن أرقام في هذه الفترة، فلا توجد مادة تُقارَن.',
    en: 'No ad reported figures in this period, so there is no content to compare.',
  },
  only_one_format_ran_enough_to_compare: {
    ar: 'شكل واحد فقط من المحتوى شغّل ما يكفي لتُقاس عليه مقارنة. هذه حقيقة عن الحساب لا نقص في البيانات.',
    en: 'Only one kind of content ran enough to be compared. That is a fact about the account, not a gap in the data.',
  },
  no_metric_every_format_could_answer: {
    ar: 'لا يوجد مؤشر أبلغت عنه كل الأشكال، ومقارنة طرفٍ أبلغ بطرفٍ لم يُبلِّغ ليست مقارنة.',
    en: 'No metric was reported by every format, and comparing one that reported against one that did not is not a comparison.',
  },
}

/**
 * WHY the spend share is absent — three different states that must not share one sentence.
 *
 * The card printed «a format withheld its spend» for all of them, including the ordinary account
 * whose provider does not break spend down to the creative grain. That sends a reader looking for a
 * fault that is not there.
 */
const NO_SPEND_SHARE: Record<string, { ar: string; en: string }> = {
  no_spend_was_reported_at_this_grain: {
    ar: 'لم تُبلِّغ المنصة عن الإنفاق على مستوى الإعلان في هذه الفترة، فلا توجد نسبة تُحسب.',
    en: 'The platform did not report spend at the ad level in this period, so there is no share to compute.',
  },
  a_format_withheld_its_spend: {
    ar: 'حُجب إنفاق أحد الأشكال، والنسبة على مجموع ناقص تبالغ في نفسها.',
    en: 'A format withheld its spend, and a share over an incomplete total overstates itself.',
  },
  nothing_was_spent_in_this_period: {
    ar: 'لم يُنفَق شيء في هذه الفترة.',
    en: 'Nothing was spent in this period.',
  },
}

/** The formats the provider actually names. An unrecognised one is printed as it came. */
const FORMAT: Record<string, { ar: string; en: string }> = {
  video: { ar: 'فيديو', en: 'Video' },
  image: { ar: 'صورة', en: 'Image' },
  carousel: { ar: 'دوّار', en: 'Carousel' },
  text: { ar: 'نص', en: 'Text' },
  collection: { ar: 'مجموعة', en: 'Collection' },
  story: { ar: 'قصة', en: 'Story' },
  unlabelled: { ar: 'بلا تصنيف', en: 'Unlabelled' },
}

const METRIC: Record<string, { ar: string; en: string; kind: 'money' | 'percent' | 'ratio' | 'count' }> = {
  cpa: { ar: 'تكلفة النتيجة', en: 'Cost per result', kind: 'money' },
  cpc: { ar: 'تكلفة النقرة', en: 'Cost per click', kind: 'money' },
  cpm: { ar: 'تكلفة الألف ظهور', en: 'Cost per 1,000 impressions', kind: 'money' },
  cost_per_view: { ar: 'تكلفة المشاهدة', en: 'Cost per view', kind: 'money' },
  cost_per_lpv: { ar: 'تكلفة زيارة الصفحة', en: 'Cost per landing page view', kind: 'money' },
  cpe: { ar: 'تكلفة التفاعل', en: 'Cost per engagement', kind: 'money' },
  revenue: { ar: 'قيمة الطلبات', en: 'Order value', kind: 'money' },
  aov: { ar: 'متوسط قيمة الطلب', en: 'Average order value', kind: 'money' },
  roas: { ar: 'العائد على الإنفاق', en: 'Return on ad spend', kind: 'ratio' },
  frequency: { ar: 'التكرار', en: 'Frequency', kind: 'ratio' },
  ctr: { ar: 'معدل النقر', en: 'Click-through rate', kind: 'percent' },
  conversion_rate: { ar: 'معدل التحويل', en: 'Conversion rate', kind: 'percent' },
  view_rate: { ar: 'معدل المشاهدة', en: 'View rate', kind: 'percent' },
  completion_rate: { ar: 'معدل الإكمال', en: 'Completion rate', kind: 'percent' },
  hook_rate: { ar: 'معدل الجذب', en: 'Hook rate', kind: 'percent' },
  engagement_rate: { ar: 'معدل التفاعل', en: 'Engagement rate', kind: 'percent' },
  conversions: { ar: 'النتائج', en: 'Results', kind: 'count' },
  orders: { ar: 'الطلبات', en: 'Orders', kind: 'count' },
  clicks: { ar: 'النقرات', en: 'Clicks', kind: 'count' },
  video_views: { ar: 'مشاهدات الفيديو', en: 'Video views', kind: 'count' },
  engagements: { ar: 'التفاعلات', en: 'Engagements', kind: 'count' },
  landing_page_views: { ar: 'زيارات الصفحة', en: 'Landing page views', kind: 'count' },
}

const label = (map: Record<string, { ar: string; en: string }>, key: string, ar: boolean) =>
  map[key] ? (ar ? map[key].ar : map[key].en) : key

/**
 * The figure, in the unit its metric is actually in.
 *
 * UX-KPI-PRESENTATION-001 — a cost per result is never compacted: «2» in place of «1.50» is the
 * decision-critical digit removed, and this is a card whose entire purpose is a difference between
 * two costs. Counts compact; rates carry two decimals.
 */
function reading(row: FormatRow, metric: string, currency: string | null): string {
  switch (METRIC[metric]?.kind) {
    case 'money': return moneyExact(row.value, currency)
    case 'percent': return percent(row.value)
    case 'ratio': return ratio(row.value)
    default: return compact(row.value)
  }
}

export function ContentReading({
  data, currency, creativesRead,
}: { data: ContentIntelligence | undefined; currency: string | null; creativesRead: number }) {
  const ar = useUi((s) => s.locale) === 'ar'

  if (!data) return null

  const title = ar ? 'أي نوع من المحتوى يستحق ميزانيته' : 'Which kind of content earns its budget'

  if (data.refusal || !data.metric || data.formats.length < 2) {
    const reason = data.refusal ? REFUSAL[data.refusal] : undefined

    return (
      <div
        className="rounded-2xl border border-border bg-surface p-4 shadow-[var(--shadow-small)]"
        data-testid="content-reading-declined"
      >
        <h3 className="text-sm font-semibold text-text">{title}</h3>
        <p className="mt-2 text-sm text-text-muted">
          {reason ? (ar ? reason.ar : reason.en) : (ar ? 'لا توجد مقارنة يمكن قولها عن هذه الفترة.' : 'There is no comparison that can be stated for this period.')}
        </p>
        {/*
          The held-out formats are NAMED. «One video ran, which is not enough to speak for video» is
          a true and useful sentence about the media plan; dropping those rows silently would let the
          reader believe the format was never tried.
        */}
        {data.too_few_to_speak_for_their_format.length > 0 && (
          <p className="mt-2 text-xs text-text-muted" data-testid="content-reading-too-few">
            {ar ? 'لم يُحتسب: ' : 'Held out: '}
            {data.too_few_to_speak_for_their_format
              .map((f) => `${label(FORMAT, f.format, ar)} (${num(f.creatives)})`)
              .join(ar ? '، ' : ', ')}
          </p>
        )}
      </div>
    )
  }

  const metric = data.metric
  const best = data.formats[0]
  const worst = data.formats[data.formats.length - 1]

  /*
   * The bar is drawn against the WORST value, not against the best.
   *
   * On a cost metric the leader is the smallest number, so scaling to the maximum makes the winner
   * the shortest bar — a chart that reads as the opposite of its own finding. Normalising to the
   * largest value and stating the direction in the caption keeps the geometry honest either way.
   */
  const ceiling = Math.max(...data.formats.map((f) => Math.abs(f.value))) || 1

  return (
    <div className="grid gap-4 lg:grid-cols-3" data-testid="content-reading">
      <div className="rounded-2xl border border-border bg-surface p-4 shadow-[var(--shadow-small)] lg:col-span-2">
        <h3 className="text-sm font-semibold text-text">{title}</h3>
        <p className="mt-1 text-xs text-text-muted">
          {ar
            ? `مقيسة على ${label(METRIC, metric, true)} — ${data.lower_is_better ? 'الأقل أفضل' : 'الأعلى أفضل'}. كل الأشكال قيست على المؤشر نفسه.`
            : `Read on ${label(METRIC, metric, false).toLowerCase()} — ${data.lower_is_better ? 'lower is better' : 'higher is better'}. Every format is measured on the same metric.`}
        </p>

        <ul className="mt-3 space-y-2">
          {data.formats.map((row) => (
            <li key={row.format} className="flex items-center gap-3" data-testid={`content-format-${row.format}`}>
              <span className="w-24 shrink-0 text-sm text-text">{label(FORMAT, row.format, ar)}</span>
              {/*
                `bg-brand-500` and `bg-surface-secondary` — the tokens this theme actually defines.
                An earlier version reached for `bg-primary`, which resolves to nothing here, so the
                LEADING format — the one the whole card is about — drew as an invisible bar over an
                empty track. Nothing in the unit tests could see it: jsdom does not resolve Tailwind,
                and a class name that means nothing is still a class name.
              */}
              <span className="h-2 flex-1 overflow-hidden rounded-full bg-surface-secondary">
                <span
                  className={`block h-full rounded-full ${row.format === best.format ? 'bg-brand-500' : 'bg-border'}`}
                  style={{ width: `${Math.max(2, (Math.abs(row.value) / ceiling) * 100)}%` }}
                />
              </span>
              <span className="tnum w-28 shrink-0 text-end text-sm font-medium text-text">
                {reading(row, metric, currency)}
              </span>
              <span className="tnum w-16 shrink-0 text-end text-xs text-text-muted">
                {num(row.creatives)}
              </span>
            </li>
          ))}
        </ul>

        {/*
          The action names the two ends and stops — the same restraint AdsExplanation keeps. Which
          format to commission next is a decision with a client's money in it; the product's part is
          to put the difference in front of the person who decides.
        */}
        <p className="mt-3 border-t border-border pt-3 text-sm text-text-muted" data-testid="content-reading-action">
          {ar
            ? `«${label(FORMAT, best.format, true)}» يحقق ${label(METRIC, metric, true)} أفضل من «${label(FORMAT, worst.format, true)}» في هذه الفترة، على المشتريات نفسها.`
            : `«${label(FORMAT, best.format, false)}» is reaching a better ${label(METRIC, metric, false).toLowerCase()} than «${label(FORMAT, worst.format, false)}» in this period, on the same buys.`}
        </p>
      </div>

      {/*
        SQUARE, and side by side — the same 2-up the change diagnosis uses.
        Stacked in a third of a 1440 viewport these came out 365px each: 747px of column for two
        figures, taller than the comparison they annotate. `content-start` keeps them their own size
        rather than stretching to whatever the wide card happens to be.
      */}
      <div className="grid grid-cols-2 content-start gap-3">
        <StatCard
          shape="square"
          label={ar ? 'الإنفاق خارج الشكل المتصدّر' : 'Spend not on the leading format'}
          value={
            data.share_of_spend_not_on_the_leading_format === null
              ? '—'
              : percent(data.share_of_spend_not_on_the_leading_format)
          }
          hint={
            data.share_of_spend_not_on_the_leading_format === null
              ? (data.why_no_spend_share && NO_SPEND_SHARE[data.why_no_spend_share]
                  ? (ar ? NO_SPEND_SHARE[data.why_no_spend_share].ar : NO_SPEND_SHARE[data.why_no_spend_share].en)
                  : (ar ? 'لا توجد نسبة يمكن حسابها من هذه الأرقام.' : 'There is no share these figures can support.'))
              : (ar ? `يذهب إلى ما هو دون «${label(FORMAT, best.format, true)}»` : `going to something other than «${label(FORMAT, best.format, false)}»`)
          }
          testid="content-reading-spend-share"
        />
        <StatCard
          shape="square"
          label={ar ? 'إعلانات قُرئت' : 'Ads read'}
          value={compact(creativesRead)}
          hint={ar ? 'كل ما يطابق المرشّحات — لا صفحة واحدة' : 'everything matching the filters, not one page'}
          testid="content-reading-scope"
        />
      </div>
    </div>
  )
}

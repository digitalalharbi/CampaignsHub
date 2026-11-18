import { Panel } from './components'
import { compact, money, percent, ratio } from './format'
import type { Locale } from '@/stores/ui'
import type { PathExplanation, PathLeaders } from './api'
import { Num } from '@/components/ui/Num'

/**
 * OBJECTIVE-ANALYTICS-DEPTH-001 · PLATFORM-DECISION-ANALYTICS-001 · FUNNEL-ANALYTICAL-PATTERN-001
 *
 * ## What this surface is, and the comparison it refuses to draw
 *
 * One block per marketing path — awareness, traffic, conversion — and nothing that crosses them. A
 * leads campaign and an awareness campaign are not better or worse than each other, and a «top
 * campaigns» list across a mixed programme ranks them by whichever metric they happen to share. So
 * the comparison happens INSIDE a path, where it is real, and where a path cannot support one the
 * block says so instead of ranking anyway.
 *
 * «Comparable» is two campaigns that actually SPENT, not two that exist: a strongest of one is a
 * figure wearing a superlative, and «your best sales campaign» said of the only sales campaign tells
 * a client nothing while implying a choice was made.
 *
 * The PLATFORM contribution per path is `PlatformPaths`, directly above this on the Platforms tab.
 * Showing the same shares twice under two headings would teach a reader that the two blocks are
 * different analyses of the same rows, which is exactly what they are not.
 *
 * ## The reading is the funnel's, deliberately
 *
 * Signal → context → explanation → evidence → action. The funnel is the product's most-praised
 * surface because it does not draw a chart and leave the reader to interpret it; this gives the
 * objective paths the same shape. Every step can be absent: a path nobody ran has no signal, a path
 * one campaign ran has no comparison, and where there is no signal there is no action. The reason
 * travels in the action's place, because an action offered without evidence spends somebody's
 * afternoon on the product's guess.
 *
 * Nothing here is a benchmark. The signal is the RANGE this account's own campaigns produced; no
 * industry figure, no «good» threshold. A reader told that 50 is bad has been told something we do
 * not know.
 */
const T = {
  inside: { ar: 'المقارنة داخل هذا المسار وحده', en: 'Compared inside this path alone' },
  share: { ar: 'الحصة', en: 'Share' },
  spend: { ar: 'الإنفاق', en: 'Spend' },
  campaigns: { ar: 'الحملات', en: 'Campaigns' },
  strongest: { ar: 'الأقوى', en: 'Strongest' },
  weakest: { ar: 'الأضعف', en: 'Weakest' },
  signal: { ar: 'ما تقوله الأرقام', en: 'What the figures say' },
  evidence: { ar: 'مبني على', en: 'Based on' },
  action: { ar: 'الخطوة التالية', en: 'Next step' },
  noComparison: { ar: 'لا مقارنة داخل هذا المسار', en: 'No comparison inside this path' },
  nothing: { ar: 'لا إنفاق على هذا المسار في هذه الفترة.', en: 'Nothing was spent on this path in this period.' },
  never: {
    ar: 'المسارات لا تُقارن ببعضها: حملة وعي وحملة مبيعات اشتُريتا لغرضين مختلفين.',
    en: 'Paths are never compared with each other: an awareness campaign and a sales campaign were bought for different things.',
  },
}

const REASON: Record<string, { ar: string; en: string }> = {
  only_one_platform_spent: {
    ar: 'منصة واحدة فقط أنفقت على هذا المسار — لا يوجد طرف آخر تُقارن به.',
    en: 'Only one platform spent on this path — there is no second side to compare it with.',
  },
  nothing_spent_on_this_path: {
    ar: 'لا إنفاق على هذا المسار في هذه الفترة.',
    en: 'Nothing was spent on this path in this period.',
  },
  only_one_campaign_spent: {
    ar: 'حملة واحدة فقط أنفقت على هذا المسار — «الأفضل من واحدة» ليس ترتيبًا.',
    en: 'Only one campaign spent on this path — a strongest of one is not a ranking.',
  },
  two_or_more_platforms_spent: { ar: '', en: '' },
  two_or_more_campaigns_spent: { ar: '', en: '' },
}

/** The metric a path is judged by, named — never a bare key on screen. */
const METRIC: Record<string, { ar: string; en: string }> = {
  cpm: { ar: 'تكلفة الألف ظهور', en: 'Cost per 1K impressions' },
  ctr: { ar: 'نسبة النقر', en: 'Click-through rate' },
  cpa: { ar: 'تكلفة النتيجة', en: 'Cost per result' },
  spend: { ar: 'الإنفاق', en: 'Spend' },
  orders: { ar: 'الطلبات', en: 'Orders' },
  impressions: { ar: 'الظهور', en: 'Impressions' },
  clicks: { ar: 'النقرات', en: 'Clicks' },
  revenue: { ar: 'الإيراد', en: 'Revenue' },
  landing_page_views: { ar: 'زيارات الصفحة', en: 'Landing page views' },
}

/** The name of a figure: one step above caption text, because it names rather than annotates. */

const t = (key: keyof typeof T, ar: boolean) => (ar ? T[key].ar : T[key].en)

const reason = (key: string | null, ar: boolean): string =>
  key === null ? '' : (ar ? REASON[key]?.ar : REASON[key]?.en) ?? key

const metricName = (key: string, ar: boolean): string => (ar ? METRIC[key]?.ar : METRIC[key]?.en) ?? key

/** A path metric formatted as what it is: a rate, a ratio or money. */
function metricValue(metric: string, value: number | null, currency: string | null): string {
  if (value === null) return '—'
  if (metric === 'ctr') return percent(value, 2)
  if (metric === 'roas') return ratio(value)
  if (metric === 'cpm' || metric === 'cpa' || metric === 'spend' || metric === 'revenue') {
    // FX-APPLY-001 — the path's own reporting currency or none; never a guessed «SAR» on a USD figure.
    return money(value, currency)
  }
  return compact(value)
}

export function PathAnalysis({
  locale,
  currency,
  leaders,
  explanations,
  loading,
  error,
}: {
  locale: Locale
  currency: string | null
  leaders: PathLeaders[]
  explanations: PathExplanation[]
  loading?: boolean
  error?: boolean
}) {
  const ar = locale === 'ar'
  const explain = (path: string) => explanations.find((e) => e.path === path) ?? null

  /*
   * ANALYTICS-REPORT-LAYOUT-001 — Owner 2026-11-18: these blocks read as clutter. A path where nothing was
   * spent is not a section: it is named once in one quiet line. A comparable path is a «versus» card — the two
   * campaigns side by side with bars and their figure set large — and the next step as one highlighted line.
   * How it was measured folds away under «كيف حُسبت».
   */
  const empty = leaders.filter((p) => p.comparable_reason === 'nothing_spent_on_this_path')
  const shown = leaders.filter((p) => p.comparable_reason !== 'nothing_spent_on_this_path')

  return (
    <div data-testid="path-analysis" className="flex flex-col gap-4">
      <p data-testid="path-analysis-never" className="text-xs text-text-muted">
        {ar ? T.never.ar : T.never.en}
      </p>

      {empty.length > 0 && (
        <p data-testid="path-analysis-empty" className="text-xs text-text-muted">
          {ar ? 'بلا إنفاق في هذه الفترة: ' : 'No spend this period: '}
          {empty.map((p) => (ar ? p.label_ar : p.label_en)).join(' · ')}
        </p>
      )}

      <div className="grid gap-4 lg:grid-cols-2">
      {shown.map((path) => {
        const leader = path
        const reading = explain(path.path)
        const label = ar ? path.label_ar : path.label_en
        const pair = leader?.comparable && leader.strongest && leader.weakest ? [leader.strongest, leader.weakest] as const : null
        const top = pair ? Math.max(Math.abs(pair[0].value ?? 0), Math.abs(pair[1].value ?? 0)) || 1 : 1

        return (
          <Panel key={path.path} title={label} description={t('inside', ar)} loading={loading} error={error}>
            <div data-testid={`path-${path.path}`} className="flex flex-col gap-3">
              {pair ? (
                <div className="flex flex-col gap-3">
                  {([['strongest', pair[0]], ['weakest', pair[1]]] as const).map(([kind, campaign]) => (
                    <div key={kind} data-testid={`path-${path.path}-${kind}`} className="flex flex-col gap-1">
                      <div className="flex items-baseline justify-between gap-3">
                        <span className="flex min-w-0 items-center gap-2">
                          <span className={`shrink-0 rounded-full px-2 py-0.5 text-[11px] font-bold ${kind === 'strongest' ? 'bg-success/15 text-success' : 'bg-warning/15 text-warning'}`}>{t(kind, ar)}</span>
                          <span className="truncate text-sm font-semibold text-text-primary">{campaign.name}</span>
                        </span>
                        <span className="tnum shrink-0 text-lg font-extrabold text-text-primary" dir="ltr">
                          <Num>{metricValue(campaign.metric, campaign.value, currency)}</Num>
                        </span>
                      </div>
                      <div className="h-2.5 overflow-hidden rounded-full bg-surface-secondary">
                        <div className={`h-full rounded-full ${kind === 'strongest' ? 'bg-success' : 'bg-warning'}`} style={{ width: `${(Math.abs(campaign.value ?? 0) / top) * 100}%` }} />
                      </div>
                      <span className="text-[11px] text-text-muted">{metricName(campaign.metric, ar)}</span>
                    </div>
                  ))}
                </div>
              ) : (
                leader && (
                  <p data-testid={`path-${path.path}-no-comparison`} className="text-sm text-text-secondary">
                    <span className="font-semibold text-text-primary">{t('noComparison', ar)}: </span>
                    {reason(leader.comparable_reason, ar)}
                  </p>
                )
              )}

              {reading && (
                <div data-testid={`path-${path.path}-reading`} className="flex flex-col gap-2">
                  {reading.signal ? (
                    <>
                      {reading.action && (
                        <p data-testid={`path-${path.path}-action`} className="rounded-xl bg-brand-600/10 px-3 py-2 text-sm font-semibold text-text-primary">
                          {t('action', ar)}: {ar ? reading.action.ar : reading.action.en}
                        </p>
                      )}
                      <details className="text-xs text-text-muted">
                        <summary className="cursor-pointer select-none font-semibold">{ar ? 'كيف حُسبت' : 'How it was read'}</summary>
                        <p dir="auto" className="mt-1.5 text-text-secondary">
                          {t('signal', ar)} — {metricName(reading.signal.metric, ar)}:{' '}
                          <span dir="ltr" className="tnum font-bold">{metricValue(reading.signal.metric, reading.signal.best.value, currency)}</span>{' '}
                          ({reading.signal.best.campaign}) →{' '}
                          <span dir="ltr" className="tnum font-bold">{metricValue(reading.signal.metric, reading.signal.worst.value, currency)}</span>{' '}
                          ({reading.signal.worst.campaign})
                        </p>
                        {reading.explanation && <p className="mt-1">{ar ? reading.explanation.ar : reading.explanation.en}</p>}
                        {reading.evidence.length > 0 && (
                          <p className="mt-1">{t('evidence', ar)}: {reading.evidence.map((key) => metricName(key, ar)).join(' · ')}</p>
                        )}
                      </details>
                    </>
                  ) : (
                    <p data-testid={`path-${path.path}-silent`} className="text-sm text-text-secondary">
                      {reason(reading.silent_reason, ar)}
                    </p>
                  )}
                </div>
              )}
            </div>
          </Panel>
        )
      })}
      </div>
    </div>
  )
}

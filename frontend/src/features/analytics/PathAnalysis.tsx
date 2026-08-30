import { Panel } from './components'
import { compact, money, num, percent, ratio } from './format'
import { platformColor } from './components'
import { providerLabel } from '@/features/campaigns/labels'
import type { Locale } from '@/stores/ui'
import type { PathExplanation, PathLeaders, PathPlatforms } from './api'

/**
 * OBJECTIVE-ANALYTICS-DEPTH-001 · PLATFORM-DECISION-ANALYTICS-001 · FUNNEL-ANALYTICAL-PATTERN-001
 *
 * ## What this surface is, and the comparison it refuses to draw
 *
 * One block per marketing path — awareness, traffic, conversion — and nothing that crosses them. A
 * platform buying reach and a platform buying orders are not better or worse than each other, and a
 * league table across a mixed programme invents a verdict out of the work each was given. So the
 * comparison happens INSIDE a path, where it is real, and where a path cannot support one the block
 * says so instead of ranking anyway.
 *
 * «Comparable» is two platforms (or two campaigns) that actually SPENT, not two that exist. A
 * connected platform with no campaign on this path contributes a row of zeros, and «Meta beat TikTok
 * on awareness» where TikTok ran no awareness is a fabricated result that reads exactly like a
 * measured one.
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
  contribution: { ar: 'مساهمة المنصات في هذا المسار', en: 'Platform contribution to this path' },
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
const METRIC_LABEL = 'text-[13px] font-semibold leading-tight'

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
    return money(value, currency ?? 'SAR')
  }
  return compact(value)
}

export function PathAnalysis({
  locale,
  currency,
  platforms,
  leaders,
  explanations,
  loading,
  error,
}: {
  locale: Locale
  currency: string | null
  platforms: PathPlatforms[]
  leaders: PathLeaders[]
  explanations: PathExplanation[]
  loading?: boolean
  error?: boolean
}) {
  const ar = locale === 'ar'
  const byPath = (path: string) => ({
    leader: leaders.find((l) => l.path === path) ?? null,
    explain: explanations.find((e) => e.path === path) ?? null,
  })

  return (
    <div data-testid="path-analysis" className="flex flex-col gap-4">
      {/*
        Said once, above the blocks, rather than implied by their separation: the reason there is no
        «best platform» card anywhere on this page.
      */}
      <p data-testid="path-analysis-never" className="text-xs text-text-muted">
        {ar ? T.never.ar : T.never.en}
      </p>

      {platforms.map((path) => {
        const { leader, explain } = byPath(path.path)
        const label = ar ? path.label_ar : path.label_en

        return (
          <Panel
            key={path.path}
            title={label}
            description={t('contribution', ar)}
            loading={loading}
            error={error}
            className={`path-${path.path}`}
          >
            <div data-testid={`path-${path.path}`} className="flex flex-col gap-4">
              {path.platforms.length === 0 ? (
                <p data-testid={`path-${path.path}-empty`} className="text-sm text-text-secondary">
                  {t('nothing', ar)}
                </p>
              ) : (
                <ul className="flex flex-col gap-2">
                  {path.platforms.map((row) => (
                    <li key={row.provider} data-testid={`path-${path.path}-platform-${row.provider}`}>
                      <div className="mb-1 flex flex-wrap items-baseline justify-between gap-2">
                        <span className={`inline-flex items-center gap-1.5 text-text-primary ${METRIC_LABEL}`}>
                          <span
                            aria-hidden
                            className="inline-block h-2 w-2 rounded-full"
                            style={{ background: platformColor(row.provider) }}
                          />
                          {providerLabel(row.provider, locale)}
                        </span>
                        <span dir="ltr" className="tnum text-xs text-text-secondary">
                          {money(row.spend, currency ?? 'SAR')}
                          {row.spend_share !== null && ` · ${percent(row.spend_share, 0)}`}
                          {` · ${num(row.campaigns)} ${t('campaigns', ar)}`}
                        </span>
                      </div>
                      {/*
                        The bar is the SHARE of this path's spend, never of the project's: «40% of
                        awareness» is a fact about a decision somebody made; «40% of everything» is a
                        fact about the mix, and answers a question nobody asked here.
                      */}
                      <div className="h-1.5 w-full overflow-hidden rounded-full bg-surface-secondary">
                        <div
                          className="h-full rounded-full"
                          style={{
                            width: `${Math.round((row.spend_share ?? 0) * 100)}%`,
                            background: platformColor(row.provider),
                          }}
                        />
                      </div>
                    </li>
                  ))}
                </ul>
              )}

              {/* Strongest and weakest, or the reason a ranking of one would not be one. */}
              {leader?.comparable && leader.strongest && leader.weakest ? (
                <div className="grid gap-3 sm:grid-cols-2">
                  {([['strongest', leader.strongest], ['weakest', leader.weakest]] as const).map(([kind, campaign]) => (
                    <div
                      key={kind}
                      data-testid={`path-${path.path}-${kind}`}
                      className={`rounded-xl border p-3 ${
                        kind === 'strongest' ? 'border-success/40 bg-success/5' : 'border-warning/40 bg-warning/5'
                      }`}
                    >
                      <span className={`block text-text-secondary ${METRIC_LABEL}`}>{t(kind, ar)}</span>
                      <span className="mt-0.5 block truncate text-sm font-bold text-text-primary">{campaign.name}</span>
                      <span dir="ltr" className="tnum mt-1 block text-xs text-text-secondary">
                        {metricName(campaign.metric, ar)}: {metricValue(campaign.metric, campaign.value, currency)}
                      </span>
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

              {/*
                Signal → context → explanation → evidence → action. Absent steps are absent, and the
                reason takes the action's place rather than the product inventing one.
              */}
              {explain && (
                <div data-testid={`path-${path.path}-reading`} className="rounded-xl border border-border bg-surface-secondary/40 p-3">
                  {explain.signal ? (
                    <>
                      <span className={`block text-text-secondary ${METRIC_LABEL}`}>{t('signal', ar)}</span>
                      <p dir="auto" className="mt-1 text-sm text-text-primary">
                        {metricName(explain.signal.metric, ar)}:{' '}
                        <span dir="ltr" className="tnum font-bold">
                          {metricValue(explain.signal.metric, explain.signal.best.value, currency)}
                        </span>{' '}
                        ({explain.signal.best.campaign}) →{' '}
                        <span dir="ltr" className="tnum font-bold">
                          {metricValue(explain.signal.metric, explain.signal.worst.value, currency)}
                        </span>{' '}
                        ({explain.signal.worst.campaign})
                      </p>
                      {explain.explanation && (
                        <p className="mt-1.5 text-sm text-text-secondary">{ar ? explain.explanation.ar : explain.explanation.en}</p>
                      )}
                      {explain.evidence.length > 0 && (
                        <p className="mt-1.5 text-xs text-text-muted">
                          {t('evidence', ar)}: {explain.evidence.map((key) => metricName(key, ar)).join(' · ')}
                        </p>
                      )}
                      {explain.action && (
                        <p data-testid={`path-${path.path}-action`} className="mt-2 text-sm font-semibold text-text-primary">
                          {t('action', ar)}: {ar ? explain.action.ar : explain.action.en}
                        </p>
                      )}
                    </>
                  ) : (
                    <p data-testid={`path-${path.path}-silent`} className="text-sm text-text-secondary">
                      {reason(explain.silent_reason, ar)}
                    </p>
                  )}
                </div>
              )}
            </div>
          </Panel>
        )
      })}
    </div>
  )
}

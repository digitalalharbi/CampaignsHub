import { money, num } from '@/features/analytics/format'
import { campaigns as countedCampaigns } from '@/lib/counted'
import { formatFixed } from '@/lib/numerals'

import { LANDING_COMPARISON, type LandingAnswer } from './campaignsLanding'
import { objectiveLabel } from './labels'

/**
 * CAMPAIGN-INTELLIGENCE-HUB — one landing comparison, with its evidence one click away.
 *
 * The chip is a count; the panel is why. Each compared group states its outcome and objective, its sample (campaigns that cleared
 * the results floor), the peers' median cost per result and the two reference thresholds, and each flagged campaign says which
 * threshold it crossed. The window and currency head the panel because every figure in it is read in them.
 */
export function LandingComparisonChip({ kind, count, answer, ar, range, currency }: {
  kind: 'opportunity' | 'review'
  count: number
  answer: LandingAnswer
  ar: boolean
  range: { from: string; to: string }
  currency: string | null
}) {
  const locale = ar ? 'ar' : 'en'
  const wanted = (k: 'opportunity' | 'costlier' | 'zero') => (kind === 'opportunity' ? k === 'opportunity' : k !== 'opportunity')
  const groups = answer.comparisons.filter((g) => g.flagged.some((f) => wanted(f.kind)))
  const { minResults } = LANDING_COMPARISON
  const ratio = (cost: number, usual: number) => formatFixed(cost / usual, 2)

  return (
    <details className="group relative" data-testid={kind === 'opportunity' ? 'landing-opportunity' : 'landing-inefficient'}>
      <summary className={`inline-flex cursor-pointer list-none items-center gap-1 rounded-full border border-border px-2.5 py-1 ${kind === 'opportunity' ? 'text-success' : 'text-warning'}`}>
        {kind === 'opportunity'
          ? (ar ? `${countedCampaigns(count, 'ar')} أقل تكلفة من نظيراتها — فرصة محتملة` : `${countedCampaigns(count, 'en')} cheaper than their peers — possible room to scale`)
          : (ar ? `${countedCampaigns(count, 'ar')} أعلى تكلفة من نظيراتها — تستحق المراجعة` : `${countedCampaigns(count, 'en')} costlier than their peers — worth a review`)}
      </summary>
      <div className="mt-2 w-full max-w-xl space-y-3 rounded-xl border border-border bg-surface p-3 text-xs text-text-secondary" data-testid="landing-comparison-evidence">
        <p className="text-text-muted" data-testid="landing-comparison-scope">
          {ar
            ? `مقارنة تفسيرية وليست حكمًا بالهدر · الفترة ${range.from} → ${range.to} · العملة ${currency ?? '—'} · العينة: حملات بنفس الهدف والنتيجة وبـ${minResults} نتائج على الأقل لكل حملة`
            : `An explanatory comparison, not a finding of waste · period ${range.from} → ${range.to} · currency ${currency ?? '—'} · sample: campaigns with the same objective and outcome and at least ${minResults} results each`}
        </p>
        {groups.map((g) => (
          <div key={`${g.objective}|${g.outcome}`} className="space-y-1" data-testid="landing-comparison-group">
            <p className="font-semibold text-text-primary">
              {(ar ? g.outcomeLabel?.ar : g.outcomeLabel?.en) ?? g.outcome} · {g.objective === null ? (ar ? 'بلا هدف' : 'No objective') : objectiveLabel(g.objective, locale)}
            </p>
            <p data-testid="landing-comparison-reference">
              {ar
                ? `العينة ${countedCampaigns(g.peers, 'ar')} · الوسيط ${money(g.usualCost, currency)} لكل نتيجة · فرصة عند ${money(g.opportunityAtMost, currency)} أو أقل · أعلى تكلفة عند ${money(g.costlierAtLeast, currency)} أو أكثر`
                : `Sample ${countedCampaigns(g.peers, 'en')} · median ${money(g.usualCost, currency)} per result · opportunity at ${money(g.opportunityAtMost, currency)} or less · costlier at ${money(g.costlierAtLeast, currency)} or more`}
            </p>
            <ul className="space-y-0.5">
              {g.flagged.filter((f) => wanted(f.kind)).map((f) => (
                <li key={`${f.id}-${f.kind}`} data-testid="landing-comparison-reason">
                  <span className="text-text-primary">{f.name ?? f.id}</span>{' — '}
                  {f.kind === 'zero'
                    ? (ar
                      ? `أبلغت المنصة عن 0 نتائج بعد إنفاق ${money(f.spend, currency)} (يُذكر الصفر فقط بعد ${money(g.zeroSpendFloor, currency)})`
                      : `the platform reported 0 results after ${money(f.spend, currency)} of spend (a zero is raised only past ${money(g.zeroSpendFloor, currency)})`)
                    : (ar
                      ? `${money(f.cost, currency)} لكل نتيجة من ${num(f.results)} نتيجة (${ratio(f.cost ?? 0, g.usualCost)}× الوسيط)`
                      : `${money(f.cost, currency)} per result over ${num(f.results)} results (${ratio(f.cost ?? 0, g.usualCost)}× the median)`)}
                </li>
              ))}
            </ul>
          </div>
        ))}
      </div>
    </details>
  )
}

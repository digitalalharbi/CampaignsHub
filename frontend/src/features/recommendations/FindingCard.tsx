import { Link } from 'react-router-dom'
import { ArrowRight } from 'lucide-react'
import { usePortalPath } from '@/app/portalPath'
import { Badge } from '@/components/ui/Badge'
import { Num } from '@/components/ui/Num'
import { MetricLineChart, ProgressRing } from '@/features/analytics/charts'
import { platformColor } from '@/features/analytics/components'
import { lastNDays, useTimeseries } from '@/features/analytics/api'
import { moneyExact, num, percent, ratio } from '@/features/analytics/format'
import { FindingKpiTile as KpiTile, metricName as nameOf } from './FindingKpiTile'
import { providerLabel } from '@/features/campaigns/labels'
import type { ActionSeverity } from './actionCenter'
import type { Finding, FindingNature } from './findings'
import {
  actionLabel, evidenceLabel, factLabel, headline, impactBasis, impactLabel, natureLabel, severityLabel,
} from './findingCopy'

/**
 * RECOMMENDATIONS-VISUAL-001 — a finding a reader understands without reading a paragraph.
 *
 * Top to bottom, the order a decision is made in: how serious and what kind, what it is about, what
 * the figures were and are, what it is costing where that can be stated, the one thing to do, and
 * where the evidence lives. The only sentences on the card are the headline and the action.
 */
export function FindingCard({ finding, projectId, ar, currency }: { finding: Finding; projectId: string; ar: boolean; currency: string | null }) {
  const portalTo = usePortalPath()
  const f = finding
  const locale = ar ? 'ar' : 'en'

  return (
    <article
      data-testid={`finding-${f.id}`}
      data-severity={f.severity}
      data-nature={f.nature}
      className={`flex min-w-0 flex-col gap-3 rounded-2xl border border-border border-s-4 bg-surface p-4 shadow-[var(--shadow-small)] ${RAIL[f.severity]}`}
    >
      <header className="flex flex-wrap items-center gap-2">
        <Badge tone={SEVERITY_TONE[f.severity]} data-testid="finding-severity">{severityLabel(f.severity, ar)}</Badge>
        <Badge tone={NATURE_TONE[f.nature]} data-testid="finding-nature">{natureLabel(f.nature, ar)}</Badge>
        <h3 className="min-w-0 flex-1 text-sm font-bold text-text-primary">{headline(f.code, ar)}</h3>
      </header>

      {(f.subject.name || f.subject.platform) && (
        <p className="flex min-w-0 items-center gap-2 text-xs text-text-secondary" data-testid="finding-subject">
          {f.subject.platform && (
            <span className="inline-flex shrink-0 items-center gap-1.5">
              <span className="h-2.5 w-2.5 rounded-full" style={{ background: platformColor(f.subject.platform) }} aria-hidden />
              {providerLabel(f.subject.platform, locale)}
            </span>
          )}
          {f.subject.name && <span className="truncate font-semibold text-text-primary">{f.subject.name}</span>}
        </p>
      )}

      <div className="flex flex-wrap items-stretch gap-3">
        {f.consumption !== null && (
          <div className="flex shrink-0 items-center" data-testid="finding-consumption">
            <ProgressRing
              value={f.consumption}
              size={84}
              label={percent(f.consumption, 0)}
              tone={f.consumption >= 1 ? 'danger' : f.consumption >= 0.8 ? 'warning' : 'brand'}
            />
          </div>
        )}
        {f.kpis.length > 0 && (
          <dl className="grid min-w-0 flex-1 grid-cols-[repeat(auto-fit,minmax(9.5rem,1fr))] gap-2" data-testid="finding-kpis">
            {f.kpis.map((k) => <KpiTile key={k.key} kpi={k} ar={ar} />)}
          </dl>
        )}
      </div>

      {f.facts.length > 0 && (
        <dl className="flex flex-wrap gap-x-4 gap-y-1 text-xs" data-testid="finding-facts">
          {f.facts.map((fact) => (
            <div key={fact.key} className="flex min-w-0 gap-1">
              <dt className="text-text-muted">{factLabel(fact.key, ar)}:</dt>
              <dd className="min-w-0 truncate font-semibold text-text-primary">
                {fact.key === 'provider' ? providerLabel(String(fact.value), locale)
                  : fact.key === 'pace' && typeof fact.value === 'number' ? <Num>{ratio(fact.value)}</Num>
                    : typeof fact.value === 'number' ? <Num>{num(fact.value)}</Num>
                      : <Num>{fact.value}</Num>}
              </dd>
            </div>
          ))}
        </dl>
      )}

      {f.trend && <FindingTrend projectId={projectId} trend={f.trend} ar={ar} currency={currency} />}

      {f.impact && (
        <div className="rounded-xl bg-surface-secondary px-3 py-2" data-testid="finding-impact">
          <p className="flex flex-wrap items-baseline gap-2 text-sm">
            <span className="text-text-secondary">{impactLabel(f.impact.kind, ar)}</span>
            <span className="text-base font-extrabold text-text-primary"><Num>{moneyExact(f.impact.amount, f.impact.currency)}</Num></span>
          </p>
          <p className="text-[11px] text-text-muted">{impactBasis(f.impact.kind, ar)}</p>
        </div>
      )}

      <footer className="flex flex-wrap items-center justify-between gap-2 border-t border-border pt-3">
        <p className="text-sm font-semibold text-text-primary" data-testid="finding-action">{actionLabel(f.action, ar)}</p>
        <Link
          to={portalTo(f.evidence.path)}
          data-testid="finding-evidence"
          className="inline-flex items-center gap-1 text-xs font-bold text-brand-600 underline-offset-2 hover:underline"
        >
          {evidenceLabel(f.evidence.target, ar)}
          <ArrowRight size={13} className="rtl:rotate-180" aria-hidden />
        </Link>
      </footer>
    </article>
  )
}

/**
 * The campaign's own daily series for the metric the finding is about, from the SAME timeseries
 * endpoint Analytics draws. Nothing is drawn when fewer than two days carry a figure — a line through
 * one point is a claim about a trend nobody saw.
 */
function FindingTrend({ projectId, trend, ar, currency }: { projectId: string; trend: NonNullable<Finding['trend']>; ar: boolean; currency: string | null }) {
  const range = lastNDays(14)
  const series = useTimeseries(projectId, range, { campaign: [trend.campaignId] })
  const rows = series.data ?? []
  const values = rows.filter((r) => typeof (r as unknown as Record<string, unknown>)[trend.metric] === 'number')

  if (series.isLoading) {
    return <div className="h-28 animate-pulse rounded-xl bg-surface-secondary" data-testid="finding-trend-loading" />
  }
  if (values.length < 2) return null

  return (
    <div className="min-w-0" data-testid="finding-trend">
      <p className="mb-1 text-[11px] text-text-muted">{ar ? 'آخر 14 يومًا' : 'Last 14 days'}</p>
      <MetricLineChart
        data={rows as unknown as Array<Record<string, unknown>>}
        height={120}
        currency={currency ?? ''}
        series={[{ key: trend.metric, name: nameOf(trend.metric, ar), kind: trend.kind === 'count' ? 'num' : trend.kind === 'money' ? 'money' : trend.kind }]}
      />
    </div>
  )
}

const RAIL: Record<ActionSeverity, string> = {
  critical: 'border-s-danger',
  warning: 'border-s-warning',
  info: 'border-s-info',
}

const SEVERITY_TONE: Record<ActionSeverity, 'danger' | 'warning' | 'info'> = {
  critical: 'danger',
  warning: 'warning',
  info: 'info',
}

const NATURE_TONE: Record<FindingNature, 'danger' | 'success' | 'warning' | 'neutral'> = {
  problem: 'danger',
  opportunity: 'success',
  risk: 'warning',
  tracking: 'neutral',
}

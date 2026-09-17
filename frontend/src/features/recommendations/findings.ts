import type { ActionItem, ActionSeverity } from './actionCenter'

/**
 * RECOMMENDATIONS-VISUAL-001 — one shape for everything the action centre shows.
 *
 * ## Why a second pass over `ActionItem`
 *
 * `buildActionCentre()` joins what four engines already decided — an alert `AlertEvaluator` raised, a
 * limit `SpendLimitGovernor` read, a creative `CreativeFatigue` judged, a mover `CreativePulse`
 * ranked. It kept each source's own object, and the page then rendered each object as a sentence.
 * A reader had to READ every card to learn what moved, by how much, and what it was costing.
 *
 * This reads the figures those engines already measured and lays them out the same way for all of
 * them: severity, whether it is a problem or an opportunity, the subject, the KPIs before and now,
 * the impact where one can be stated, one action, and where the evidence lives.
 *
 * ## It measures nothing
 *
 * Every number here was put in an engine's payload by that engine. Nothing is recomputed from
 * metrics, no ratio is averaged, and no currency is converted. A figure the engine did not state is
 * `null`, and renders as «—» — never as a zero.
 *
 * ## No finding without evidence
 *
 * `toFinding()` returns NULL when the engine's payload carries nothing measured and no recorded fact.
 * An alert row whose context was lost, a limit with neither consumption nor utilisation, a mover with
 * no figures: each of those would be a card telling somebody to act on a claim nobody can check. The
 * page drops it rather than filling the card with a sentence.
 *
 * ## Impact only where it is arithmetic on stated figures, in one stated currency
 *
 * Overspend is `consumed − limit` in the limit's own currency. Spend with no results is the spend the
 * alert measured. Spend on a fatigued creative is what the pulse measured for it. Each carries its
 * basis. A «you could save…» estimate built on an assumed conversion rate is not in this file and must
 * not be added to it: an invented saving is the most convincing kind of invented number.
 */

export type FindingNature = 'problem' | 'opportunity' | 'risk' | 'tracking'

export type KpiKind = 'money' | 'ratio' | 'percent' | 'count'

export interface FindingKpi {
  key: string
  kind: KpiKind
  before: number | null
  current: number | null
  /** Money only. Null prints the figure with no currency rather than a guessed one. */
  currency?: string | null
  /** False for costs and frequency — a fall is the good direction there. */
  higherIsBetter: boolean
}

export type FindingFactKey = 'provider' | 'expires_at' | 'error' | 'count' | 'basis' | 'threshold' | 'window_days' | 'utilisation' | 'pace' | 'exhaustion' | 'date'

export interface FindingFact {
  key: FindingFactKey
  value: string | number
}

export interface FindingSubject {
  type: 'campaign' | 'creative' | 'spend_limit' | 'connection' | 'leads' | 'project'
  id: string | null
  name: string | null
  platform: string | null
}

export type ImpactKind = 'overspend' | 'projected_overrun' | 'spend_without_results' | 'spend_on_fatigued'

export interface FindingImpact {
  kind: ImpactKind
  amount: number
  currency: string
}

export type FindingAction =
  | 'review_budget'
  | 'pause_or_raise_limit'
  | 'check_conversion_tracking'
  | 'review_cost_drivers'
  | 'refresh_creative'
  | 'scale_winner'
  | 'reconnect'
  | 'assign_leads'
  | 'contact_leads'
  | 'investigate_day'
  | 'fix_budget_currency'
  | 'review_report'
  | 'review_written'

export type EvidenceTarget = 'campaign' | 'content' | 'spend_limits' | 'integrations' | 'leads' | 'alerts'

export interface Finding {
  id: string
  source: ActionItem['kind']
  /** The engine's own type code — e.g. `roas_drop`, `fatigue`, `limit_over`. Drives the headline. */
  code: string
  severity: ActionSeverity
  nature: FindingNature
  subject: FindingSubject
  kpis: FindingKpi[]
  facts: FindingFact[]
  /** A daily series worth drawing: the campaign, the metric, and how to format it. */
  trend: { campaignId: string; metric: string; kind: KpiKind } | null
  /** Budget consumption, drawn as a ring — 0…n, never clamped here. */
  consumption: number | null
  impact: FindingImpact | null
  action: FindingAction
  /** A portal-relative path to the real view that holds the evidence. */
  evidence: { target: EvidenceTarget; path: string }
}

export interface FindingContext {
  projectId: string
  /** The project's reporting currency over the window the alerts measured. Null when mixed or empty. */
  currency: string | null
  /** The pulse's own currency — creative spend is expressed in it. */
  creativeCurrency: string | null
  campaigns: Map<string, { name: string | null; provider: string | null }>
}

const COSTS = new Set(['spend_without_results', 'projected_spend', 'cpa', 'cpl', 'cpc', 'cpm', 'cpi', 'cpe', 'cost_per_result', 'frequency', 'spend'])
const RATIOS = new Set(['roas', 'frequency'])
const PERCENTS = new Set(['ctr', 'conversion_rate', 'engagement_rate', 'video_completion_rate', 'view_rate', 'hook_rate'])
const MONEY = new Set(['spend_without_results', 'projected_spend', 'spend', 'revenue', 'cpa', 'cpl', 'cpc', 'cpm', 'cpi', 'cpe', 'aov', 'cost_per_result'])
/** Outcomes: a rise here is good news about the account, not a risk. */
const OUTCOMES = new Set(['conversions', 'purchases', 'leads', 'revenue', 'roas', 'clicks', 'installs', 'landing_page_views', 'ctr', 'conversion_rate'])

export function kindOf(key: string): KpiKind {
  if (MONEY.has(key)) return 'money'
  if (RATIOS.has(key)) return 'ratio'
  if (PERCENTS.has(key)) return 'percent'
  return 'count'
}

const num = (v: unknown): number | null => (typeof v === 'number' && Number.isFinite(v) ? v : null)
const str = (v: unknown): string | null => (typeof v === 'string' && v !== '' ? v : null)

function kpi(key: string, before: unknown, current: unknown, currency?: string | null): FindingKpi {
  return {
    key,
    kind: kindOf(key),
    before: num(before),
    current: num(current),
    ...(kindOf(key) === 'money' ? { currency: currency ?? null } : {}),
    higherIsBetter: !COSTS.has(key),
  }
}

/** A finding carries evidence when at least one figure was measured, or a fact was recorded. */
export function hasEvidence(f: Pick<Finding, 'kpis' | 'facts'>): boolean {
  return f.kpis.some((k) => k.current !== null) || f.facts.length > 0
}

function campaignSubject(id: string | null, ctx: FindingContext): FindingSubject {
  const known = id ? ctx.campaigns.get(id) : undefined
  return { type: 'campaign', id, name: known?.name ?? null, platform: known?.provider ?? null }
}

function campaignEvidence(id: string | null, ctx: FindingContext): Finding['evidence'] {
  return id ? { target: 'campaign', path: `/campaigns/${ctx.projectId}/${id}` } : { target: 'alerts', path: '/alerts' }
}

function fromAlert(item: Extract<ActionItem, { kind: 'alert' }>, ctx: FindingContext): Omit<Finding, 'id' | 'source' | 'severity'> | null {
  const a = item.alert
  const c = a.context ?? {}
  const campaignId = a.entity_type?.endsWith('UnifiedCampaign') ? a.entity_id : null
  const base = {
    code: a.type,
    subject: campaignSubject(campaignId, ctx),
    evidence: campaignEvidence(campaignId, ctx),
    facts: [] as FindingFact[],
    kpis: [] as FindingKpi[],
    trend: null as Finding['trend'],
    consumption: null as number | null,
    impact: null as FindingImpact | null,
  }
  const trendOf = (metric: string): Finding['trend'] => (campaignId ? { campaignId, metric, kind: kindOf(metric) } : null)

  switch (a.type) {
    case 'roas_drop':
      return { ...base, nature: 'problem', action: 'review_cost_drivers', kpis: [kpi('roas', c.roas_previous, c.roas_current)], trend: trendOf('roas') }

    case 'cpa_increase':
    case 'cpl_increase': {
      const key = a.type === 'cpa_increase' ? 'cpa' : 'cpl'
      const results = a.type === 'cpa_increase' ? 'conversions' : 'leads'
      return {
        ...base,
        nature: 'problem',
        action: 'review_cost_drivers',
        kpis: [kpi(key, c[`${key}_previous`], c[`${key}_current`], ctx.currency), kpi(results, null, c[results])],
        trend: trendOf(key),
      }
    }

    case 'no_results': {
      const spend = num(c.spend)
      return {
        ...base,
        nature: 'problem',
        action: 'check_conversion_tracking',
        kpis: [kpi('spend', null, spend, ctx.currency), kpi('conversions', null, c.conversions)],
        facts: num(c.days) !== null ? [{ key: 'window_days', value: num(c.days)! }] : [],
        trend: trendOf('spend'),
        /* The spend the alert measured, in the project's stated currency — or no impact at all. */
        impact: spend !== null && spend > 0 && ctx.currency ? { kind: 'spend_without_results', amount: spend, currency: ctx.currency } : null,
      }
    }

    case 'budget_risk': {
      if (c.basis_class === 'unmeasurable') {
        return {
          ...base,
          nature: 'tracking',
          action: 'fix_budget_currency',
          facts: str(c.pacing_basis) ? [{ key: 'basis', value: str(c.pacing_basis)! }] : [],
        }
      }
      if (a.entity_type?.endsWith('SpendLimit')) {
        return {
          ...base,
          subject: { type: 'spend_limit', id: a.entity_id, name: null, platform: null },
          evidence: { target: 'spend_limits', path: '/spend-limits' },
          nature: 'risk',
          action: 'pause_or_raise_limit',
          kpis: [kpi('spend', null, c.consumed, str(c.currency))],
          facts: num(c.threshold) !== null ? [{ key: 'threshold', value: num(c.threshold)! }] : [],
          consumption: num(c.consumed) !== null && num(c.limit) ? num(c.consumed)! / num(c.limit)! : null,
        }
      }
      return {
        ...base,
        nature: 'risk',
        action: 'review_budget',
        /* No currency here: the alert's context does not state one, and guessing would mislabel it. */
        kpis: [kpi('spend', null, c.spend, null)],
        consumption: num(c.ratio),
        trend: trendOf('spend'),
      }
    }

    case 'metric_anomaly': {
      const points = Array.isArray(c.points) ? (c.points as Array<Record<string, unknown>>) : []
      const measured = points.filter((p) => typeof p.metric === 'string' && num(p.value) !== null)
      if (measured.length === 0) return { ...base, nature: 'risk', action: 'investigate_day' }
      const lead = measured[0]
      const metric = String(lead.metric)
      const up = lead.direction === 'up'
      /*
       * Good or bad news is decided by the metric AND the direction, and only for metrics where that
       * is unambiguous. Spend moving either way is a RISK — a spike can be a winner scaling or a bid
       * running away, and this payload cannot tell the two apart.
       */
      const nature: FindingNature = OUTCOMES.has(metric)
        ? (up ? 'opportunity' : 'problem')
        : COSTS.has(metric) && metric !== 'spend'
          ? (up ? 'problem' : 'opportunity')
          : 'risk'
      return {
        ...base,
        nature,
        action: nature === 'opportunity' ? 'scale_winner' : 'investigate_day',
        kpis: measured.slice(0, 3).map((p) => kpi(String(p.metric), p.baseline, p.value, ctx.currency)),
        facts: str(lead.date) ? [{ key: 'date', value: String(lead.date) }] : [],
        trend: trendOf(metric),
      }
    }

    case 'sync_failure':
      return {
        ...base,
        subject: { type: 'connection', id: null, name: null, platform: str(c.provider) },
        evidence: { target: 'integrations', path: '/integrations' },
        nature: 'tracking',
        action: 'reconnect',
        facts: [
          ...(str(c.provider) ? [{ key: 'provider' as const, value: str(c.provider)! }] : []),
          ...(str(c.error) ? [{ key: 'error' as const, value: str(c.error)! }] : []),
        ],
      }

    case 'token_expiry':
      return {
        ...base,
        subject: { type: 'connection', id: null, name: null, platform: str(c.provider) },
        evidence: { target: 'integrations', path: '/integrations' },
        nature: 'tracking',
        action: 'reconnect',
        facts: [
          ...(str(c.provider) ? [{ key: 'provider' as const, value: str(c.provider)! }] : []),
          ...(str(c.expires_at) ? [{ key: 'expires_at' as const, value: str(c.expires_at)!.slice(0, 10) }] : []),
        ],
      }

    case 'lead_unassigned':
    case 'lead_no_contact':
    case 'lead_follow_up_overdue':
      return {
        ...base,
        subject: { type: 'leads', id: null, name: null, platform: null },
        evidence: { target: 'leads', path: '/leads' },
        nature: 'problem',
        action: a.type === 'lead_unassigned' ? 'assign_leads' : 'contact_leads',
        facts: num(c.count) !== null ? [{ key: 'count', value: num(c.count)! }] : [],
      }

    case 'report_failed':
      return { ...base, subject: { type: 'project', id: null, name: null, platform: null }, evidence: { target: 'alerts', path: '/alerts' }, nature: 'tracking', action: 'review_report', facts: [] }

    default:
      return null
  }
}

export function toFinding(item: ActionItem, ctx: FindingContext): Finding | null {
  let body: Omit<Finding, 'id' | 'source' | 'severity'> | null = null

  if (item.kind === 'alert') {
    body = fromAlert(item, ctx)
  } else if (item.kind === 'budget') {
    const l = item.limit
    /* Consumption in the LIMIT's currency only. A spend in another currency cannot be set against it. */
    const sameCurrency = l.consumed !== null && l.consumed_currency === l.currency
    const overspend = sameCurrency && l.consumed! > l.amount ? l.consumed! - l.amount : null
    const projected = l.projected_period_spend !== null && l.consumed_currency === l.currency && l.projected_period_spend > l.amount
      ? l.projected_period_spend - l.amount
      : null
    body = {
      code: `limit_${l.state}`,
      subject: { type: 'spend_limit', id: l.id, name: null, platform: l.scope === 'platform' ? l.scope_id : null },
      evidence: { target: 'spend_limits', path: '/spend-limits' },
      nature: l.state === 'unknown' ? 'tracking' : 'risk',
      action: l.state === 'unknown' ? 'fix_budget_currency' : 'pause_or_raise_limit',
      kpis: [
        kpi('spend', null, sameCurrency ? l.consumed : null, l.currency),
        kpi('projected_spend', null, sameCurrency ? l.projected_period_spend : null, l.currency),
      ],
      facts: [
        ...(l.state === 'unknown' ? [{ key: 'basis' as const, value: l.basis }] : []),
        ...(l.pace !== null ? [{ key: 'pace' as const, value: l.pace }] : []),
        ...(l.projected_exhaustion.date ? [{ key: 'exhaustion' as const, value: l.projected_exhaustion.date }] : []),
      ],
      trend: null,
      consumption: l.utilisation,
      impact: overspend !== null
        ? { kind: 'overspend', amount: overspend, currency: l.currency }
        : projected !== null
          ? { kind: 'projected_overrun', amount: projected, currency: l.currency }
          : null,
    }
  } else if (item.kind === 'creative') {
    const c = item.creative
    const signals = item.fatigue?.signals ?? c.fatigue?.signals ?? []
    const spend = item.fatigue?.spend ?? null
    body = {
      code: 'fatigue',
      subject: { type: 'creative', id: c.id, name: c.name, platform: c.provider },
      evidence: { target: 'content', path: `/content/${c.id}` },
      nature: 'problem',
      action: 'refresh_creative',
      kpis: signals.slice(0, 3).map((s) => {
        const r = s as { current?: unknown; previous?: unknown }
        const key = 'key' in s ? String((s as { key: string }).key) : String((s as { metric: string }).metric)
        return kpi(key, r.previous, r.current, ctx.creativeCurrency)
      }),
      facts: [],
      trend: null,
      consumption: null,
      impact: spend !== null && spend > 0 && ctx.creativeCurrency ? { kind: 'spend_on_fatigued', amount: spend, currency: ctx.creativeCurrency } : null,
    }
  } else if (item.kind === 'creative_move') {
    const m = item.move
    body = {
      code: item.direction === 'rising' ? 'creative_rising' : 'creative_declining',
      subject: { type: 'creative', id: m.creative.id, name: m.creative.name, platform: m.creative.provider },
      evidence: { target: 'content', path: `/content/${m.creative.id}` },
      nature: item.direction === 'rising' ? 'opportunity' : 'problem',
      action: item.direction === 'rising' ? 'scale_winner' : 'refresh_creative',
      kpis: [{ ...kpi(m.metric, m.previous, m.current, ctx.creativeCurrency), higherIsBetter: m.higher_wins }],
      facts: [],
      trend: null,
      consumption: null,
      impact: null,
    }
  } else {
    /* Written recommendations keep their own list and their own evidence line. */
    return null
  }

  if (body === null) return null

  const finding: Finding = { id: item.id, source: item.kind, severity: item.severity, ...body }

  return hasEvidence(finding) ? finding : null
}

export function buildFindings(items: ActionItem[], ctx: FindingContext): Finding[] {
  /*
   * An internal spend limit reaches this page twice: as the governor's reading, and as the alert the
   * same governor's crossing raised. One fact, one card — the reading, because it carries the pace
   * and the projection the alert froze at the moment it fired.
   */
  const limits = new Set(items.filter((i) => i.kind === 'budget').map((i) => (i as Extract<ActionItem, { kind: 'budget' }>).limit.id))

  return items
    .filter((i) => !(i.kind === 'alert' && i.alert.entity_type?.endsWith('SpendLimit') && i.alert.entity_id !== null && limits.has(i.alert.entity_id)))
    .map((i) => toFinding(i, ctx))
    .filter((f): f is Finding => f !== null)
}

/** Change of `current` against `before`, as a fraction. Null where there is no honest baseline. */
export function relativeChange(k: FindingKpi): number | null {
  if (k.before === null || k.current === null || k.before === 0) return null
  return (k.current - k.before) / Math.abs(k.before)
}

export function natureCounts(findings: Finding[]): Record<FindingNature, number> {
  const out: Record<FindingNature, number> = { problem: 0, opportunity: 0, risk: 0, tracking: 0 }
  for (const f of findings) out[f.nature] += 1
  return out
}

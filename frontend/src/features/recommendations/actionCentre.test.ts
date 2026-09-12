import { describe, expect, it } from 'vitest'
import { actionCounts, buildActionCentre } from './actionCenter'
import type { AlertEvent } from '@/features/alerts/api'
import type { Recommendation } from './api'
import type { SpendLimitReading } from '@/features/budget/spendLimitsApi'
import type { CreativeCard } from '@/features/content/api'

/**
 * RECOMMENDATIONS-ACTION-CENTER-002 — everything a person should act on, in one place.
 *
 * The page listed recommendations somebody had WRITTEN. Truthful, and it meant an account where
 * nobody had written one showed nothing to do — while the product knew, at that same moment, that a
 * budget was breached, a creative had fatigued and a token was about to expire. Three engines, three
 * surfaces, and the page named «what should I do» showed none of them.
 *
 * Nothing here derives advice. Every item is something another part of the product already decided
 * and already states; this joins and orders them, and each keeps its SOURCE so a reader can tell
 * «fatigue says this creative is spent» from «Sara wrote: stop running this». Those are different
 * claims with different standing.
 */
const alert = (over: Partial<AlertEvent> = {}): AlertEvent => ({
  id: 'a1', project_id: null, rule_id: 'r', type: 'token_expiry', entity_type: null, entity_id: null,
  status: 'open', severity: 'warning', context: null, notification_id: null, task_id: null,
  last_triggered_at: null, snoozed_until: null, resolved_at: null, created_at: null, ...over,
} as AlertEvent)

const limit = (over: Partial<SpendLimitReading> = {}): SpendLimitReading => ({
  id: 'l1', scope: 'project', scope_id: null, enforcement: 'monitor_only', amount: 1000,
  currency: 'SAR', period: { from: '2026-07-01', to: '2026-07-31', days: 31 }, elapsed_days: 15,
  consumed: 500, consumed_currency: 'SAR', remaining: 500, utilisation: 0.5, pace: 1,
  projected_period_spend: 1000, projected_exhaustion: { date: null, reason: 'too_early' },
  thresholds: [80], state: 'ok', basis: null, ...over,
} as SpendLimitReading)

const written = (over: Partial<Recommendation> = {}): Recommendation => ({
  id: 'w1', kind: 'recommendation', status: 'draft', title: 'Raise the budget', body: null,
  platform: null, kpi: null, evidence: null, priority: 'medium', proposed_action: null,
  assignee_id: null, due_date: null, is_demo: false, approved_at: null, created_at: null,
  campaign_id: null, campaign_name: null, ...over,
})

const creative = (id: string): CreativeCard => ({ id, name: `Creative ${id}` } as CreativeCard)

describe('what the action centre holds', () => {
  it('joins all four sources into one queue', () => {
    const items = buildActionCentre({
      alerts: [alert()],
      limits: [limit({ state: 'over' })],
      fatigued: [creative('c1')],
      written: [written()],
    })

    expect(items.map((i) => i.kind).sort()).toEqual(['alert', 'budget', 'creative', 'written'])
  })

  /** The source travels with the item — a derived signal is never presented as somebody's advice. */
  it('keeps each item’s source object, so the row can say where it came from', () => {
    const items = buildActionCentre({ alerts: [alert({ id: 'a9' })] })

    expect(items[0].id).toBe('alert:a9')
    expect(items[0].kind).toBe('alert')
  })

  it('files an alert under the kind its own taxonomy gives it', () => {
    const items = buildActionCentre({ alerts: [alert({ type: 'sync_failure' })] })

    expect(items[0].category).toBe('data')
  })
})

describe('what it leaves out', () => {
  /** A resolved alert is history, and history is not a thing to do. */
  it('drops an alert somebody has already closed', () => {
    expect(buildActionCentre({ alerts: [alert({ status: 'resolved' })] })).toEqual([])
  })

  /** A limit comfortably inside its budget is the normal case, not an action. */
  it('drops a limit that is fine', () => {
    expect(buildActionCentre({ limits: [limit({ state: 'ok' })] })).toEqual([])
  })

  /** Showing a rejected recommendation puts it back in front of the person who rejected it. */
  it('drops a recommendation somebody has already decided about', () => {
    expect(buildActionCentre({ written: [written({ status: 'rejected' })] })).toEqual([])
    expect(buildActionCentre({ written: [written({ status: 'hidden' })] })).toEqual([])
  })
})

describe('how serious each item is', () => {
  it('reads a breached limit as critical and an approaching one as a warning', () => {
    expect(buildActionCentre({ limits: [limit({ state: 'over' })] })[0].severity).toBe('critical')
    expect(buildActionCentre({ limits: [limit({ state: 'approaching' })] })[0].severity).toBe('warning')
  })

  /**
   * A limit that cannot be measured is INFO — neither dropped nor raised.
   *
   * Dropping it reports safety the product cannot see. Raising it to warning would put an
   * unmeasurable thing above a measured breach somebody can act on today.
   */
  it('keeps an unmeasurable limit, below the measured ones', () => {
    const items = buildActionCentre({ limits: [limit({ id: 'u', state: 'unknown' }), limit({ id: 'o', state: 'over' })] })

    expect(items.map((i) => i.id)).toEqual(['budget:o', 'budget:u'])
    expect(items[1].severity).toBe('info')
  })

  /**
   * A written recommendation's priority is its severity — and `medium` is not urgent.
   *
   * `priority` is NOT NULL DEFAULT 'medium' on the table, so «nobody ranked this» and «somebody
   * ranked it medium» are indistinguishable. Treating that as a warning would promote every
   * unranked note above a real one.
   */
  it('reads a written priority without inventing urgency', () => {
    expect(buildActionCentre({ written: [written({ priority: 'critical' })] })[0].severity).toBe('critical')
    expect(buildActionCentre({ written: [written({ priority: 'high' })] })[0].severity).toBe('warning')
    expect(buildActionCentre({ written: [written({ priority: 'medium' })] })[0].severity).toBe('info')
    expect(buildActionCentre({ written: [written({ priority: null })] })[0].severity).toBe('info')
  })

  it('puts the most serious first, whatever source it came from', () => {
    const items = buildActionCentre({
      written: [written({ id: 'w', priority: 'medium' })],
      alerts: [alert({ id: 'a', severity: 'critical' })],
      limits: [limit({ id: 'l', state: 'approaching' })],
    })

    expect(items.map((i) => i.severity)).toEqual(['critical', 'warning', 'info'])
  })
})

describe('what the queue is made of', () => {
  it('counts each severity', () => {
    const items = buildActionCentre({
      alerts: [alert({ id: 'a', severity: 'critical' }), alert({ id: 'b', severity: 'warning' })],
      written: [written()],
    })

    expect(actionCounts(items)).toEqual({ critical: 1, warning: 1, info: 1 })
  })

  it('counts nothing when there is nothing to do', () => {
    expect(actionCounts(buildActionCentre({}))).toEqual({ critical: 0, warning: 0, info: 0 })
  })
})

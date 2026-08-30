import { describe, expect, it } from 'vitest'
import { findingsFor, windowConfidence } from './dataQualityFindings'
import type { FreshnessRow } from './api'

/**
 * DATA-QUALITY-OPERATOR-UX-001 — six answers, not six columns.
 *
 * The tab was a table: platform · latest date · last sync · days with data · missing days · status.
 * Every column is true, and together they answer an administrator's question. The person who opens
 * this tab is an account manager whose client has just asked why last week looks thin, and they need
 * what is wrong, what it affects, how much it matters, what they can check, and whether anybody
 * here can end it — derived, every time, from numbers whose relationship they must already know.
 */
const row = (over: Partial<FreshnessRow> = {}): FreshnessRow => ({
  kind: 'ad_platform',
  provider: 'meta',
  account_id: 'acc-1',
  name: 'Meta Ads',
  latest_metric_date: '2026-08-14',
  data_freshness_at: '2026-08-14',
  days_with_data: 14,
  missing_days: 0,
  last_sync_status: 'fresh',
  last_sync_at: '2026-08-15T02:00:00Z',
  last_sync_error: null,
  ...over,
})

const TODAY = new Date('2026-08-15T09:00:00Z')

describe('a healthy source says nothing', () => {
  /** A finding for every platform is a page nobody reads. Silence has to mean something. */
  it('reports no finding when the data is current and complete', () => {
    expect(findingsFor([row()], TODAY)).toEqual([])
  })
})

describe('each finding names its owner — the answer an operator needs first', () => {
  it('a failed sync is critical, and points at the platform rather than the credentials', () => {
    const [finding] = findingsFor([row({ last_sync_status: 'failed' })], TODAY)

    expect(finding?.severity).toBe('critical')
    /*
     * NOT `credentials`. A failed sync may or may not be an authorisation problem and this row does
     * not say which; sending an operator to re-authorise a healthy integration is a wasted
     * afternoon and teaches them to distrust the next finding.
     */
    expect(finding?.owner).toBe('provider')
    expect(finding?.affects.en).toContain('lower than the truth, not zero')
  })

  it('an unconfigured platform is a credentials answer, and is not a fault', () => {
    const [finding] = findingsFor([row({ last_sync_status: 'awaiting_credentials' })], TODAY)

    expect(finding?.owner).toBe('credentials')
    expect(finding?.affects.en).toContain('not a fault')
  })

  /** The one an operator can finish themselves, said as such. */
  it('an unassigned account is the operator’s to end', () => {
    const [finding] = findingsFor([row({ last_sync_status: 'awaiting_assignment' })], TODAY)

    expect(finding?.owner).toBe('operator')
    expect(finding?.check.en).toContain('you can do it now')
  })
})

describe('lateness and holes are different sentences', () => {
  it('a source silent for two days is attention, and says it may catch up', () => {
    const [finding] = findingsFor([row({ data_freshness_at: '2026-08-13', latest_metric_date: '2026-08-13' })], TODAY)

    expect(finding?.severity).toBe('attention')
    expect(finding?.what.en).toContain('2 days')
    expect(finding?.check.en).toContain('may catch up')
  })

  /** One day behind is every platform, every morning. A finding for it is noise. */
  it('a source silent since yesterday is not a finding', () => {
    expect(findingsFor([row({ data_freshness_at: '2026-08-14', latest_metric_date: '2026-08-14' })], TODAY)).toEqual([])
  })

  /**
   * A hole in the middle is what a client notices — a dip nobody caused — and it is reported at
   * `watch` because a paused campaign makes exactly the same shape and this row cannot tell them
   * apart. Saying «the sync missed them» would be a claim the data does not support.
   */
  it('a gap inside the window is a watch, and offers both explanations', () => {
    const [finding] = findingsFor([row({ missing_days: 3, days_with_data: 11 })], TODAY)

    expect(finding?.severity).toBe('watch')
    expect(finding?.check.en).toContain('paused')
    expect(finding?.check.en).toContain('the sync missed them')
  })
})

describe('the list is ordered by what to open first', () => {
  it('puts critical above attention above watch', () => {
    const findings = findingsFor(
      [
        row({ provider: 'tiktok', missing_days: 2, days_with_data: 12 }),
        row({ provider: 'snapchat', last_sync_status: 'failed' }),
        row({ provider: 'google', last_sync_status: 'awaiting_credentials' }),
      ],
      TODAY,
    )

    expect(findings.map((f) => f.severity)).toEqual(['critical', 'attention', 'watch'])
  })

  /** Coverage is stated where days are the unit, and withheld where they are not (a store). */
  it('states coverage as a share of the window, and nothing for a store', () => {
    expect(findingsFor([row({ missing_days: 3, days_with_data: 9 })], TODAY)[0]?.coverage).toBe(0.75)
    expect(findingsFor([row({ kind: 'store', last_sync_status: 'failed', days_with_data: null, missing_days: null })], TODAY)[0]?.coverage).toBeNull()
  })
})

/**
 * DATA-QUALITY-OPERATOR-UX-001 — «how much of this can I trust», with the arithmetic in the open.
 *
 * Two rules matter more than the number. A window with excellent coverage and one source whose
 * authorisation has FAILED is not «high»: the days that source never reported are not in the
 * denominator at all, so the arithmetic flatters exactly the case an operator most needs to see. And
 * a store is not counted: there is no daily ad row for it to be missing, so counting it would make
 * the fraction mean something different for every tenant.
 */
describe('confidence in a window', () => {
  const adRow = (provider: string, days: number | null, over: Record<string, unknown> = {}): FreshnessRow => ({
    kind: 'ad_platform',
    provider,
    account_id: `acct-${provider}`,
    name: provider,
    latest_metric_date: '2026-08-30',
    data_freshness_at: '2026-08-30T00:00:00Z',
    days_with_data: days,
    missing_days: days === null ? null : Math.max(0, 30 - days),
    last_sync_status: 'fresh',
    last_sync_at: '2026-08-30T00:00:00Z',
    last_sync_error: null,
    ...over,
  } as FreshnessRow)

  it('reads a complete window as high, and says what it counted', () => {
    const rows = [adRow('meta', 30), adRow('snapchat', 30)]
    const c = windowConfidence(rows, 30, [])

    expect(c.level).toBe('high')
    expect(c.daysWithData).toBe(60)
    expect(c.daysExpected).toBe(60)
    expect(c.sourcesCounted).toBe(2)
  })

  it('is capped by a finding, however good the arithmetic looks', () => {
    const rows = [adRow('meta', 30), adRow('snapchat', 29)]
    const findings = findingsFor([adRow('snapchat', 29, { last_sync_status: 'failed', last_sync_error: 'boom' })], new Date('2026-08-30'))

    expect(findings.length).toBeGreaterThan(0)
    const c = windowConfidence(rows, 30, findings)

    // 59/60 is 98% — «high» by arithmetic, and wrong: one source stopped answering.
    expect(c.level).not.toBe('high')
    expect(c.worst?.provider).toBe('snapchat')
  })

  it('leaves stores out of the fraction and says how many it left out', () => {
    const store = { ...adRow('salla', null), kind: 'store' } as FreshnessRow
    const c = windowConfidence([adRow('meta', 15), store], 30, [])

    expect(c.sourcesCounted).toBe(1)
    expect(c.sourcesNotCountable).toBe(1)
    expect(c.daysExpected).toBe(30)
    expect(c.coverage).toBeCloseTo(0.5)
    expect(c.level).toBe('low')
  })

  it('says so rather than dividing by nothing when no source is countable', () => {
    const store = { ...adRow('salla', null), kind: 'store' } as FreshnessRow
    const c = windowConfidence([store], 30, [])

    expect(c.coverage).toBeNull()
    expect(c.daysExpected).toBe(0)
  })

  /** The name is the point: a reader with a source has somewhere to go, a percentage is an argument. */
  it('names the thinnest source even when nothing is wrong enough to be a finding', () => {
    const c = windowConfidence([adRow('meta', 30), adRow('x', 22)], 30, [])

    expect(c.worst?.provider).toBe('x')
  })
})

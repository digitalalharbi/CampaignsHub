import { describe, expect, it } from 'vitest'

import { diagnose, type DiagnosticInput } from './diagnose'

/**
 * ANALYTICS-DIAGNOSTIC-INTELLIGENCE-001 — one reasoning layer, and it may not assert a cause it
 * cannot evidence.
 *
 * The requirement's two named evidence bars are the two things easiest to get wrong when a product
 * starts explaining itself:
 *
 *   1. **No cause without its evidence.** «Your landing page is the problem» is a claim about visits,
 *      and it must not be made when nothing reported visits. A diagnosis built on a coalesced zero is
 *      worse than none — it sends somebody to rebuild a page that was working.
 *   2. **A missing source yields an explicit not-diagnosable state**, named. Silence reads as «no
 *      problem found», which is a different statement and a false one.
 */
const input = (o: Partial<DiagnosticInput> = {}): DiagnosticInput => ({
  objective: 'sales',
  totals: { spend: 1000, impressions: 100000, clicks: 500, landing_page_views: 400, conversions: 10, revenue: 5000 },
  reported: { spend: true, impressions: true, clicks: true, landing_page_views: true, conversions: true, revenue: true },
  ...o,
})

describe('what the diagnostic layer will and will not claim', () => {
  /**
   * The Analytics filter holds a canonical key; `byCampaign` holds a raw provider objective. Both
   * callers are real, and canonicalising blindly resolved only the three canonical keys that happen to
   * appear in their own raw lists. `app_promotion` and `awareness_engagement` returned null and were
   * quietly judged on the two-stage fallback chain — an app-promotion account never examined for
   * conversion, and nothing saying so.
   *
   * `app_promotion` is the only key this can be proven on. `awareness_engagement` is the other one
   * that failed to canonicalise, but its chain — delivery, attraction — is character for character the
   * fallback it fell back to, so no observation distinguishes the fixed code from the broken code
   * there. A test asserting it would pass either way and prove nothing.
   */
  it('judges an objective the same whether it arrives canonical or raw', () => {
    const full = { spend: 1000, impressions: 100000, clicks: 500, landing_page_views: 400, conversions: 0, revenue: 0 }
    const reported = { spend: true, impressions: true, clicks: true, landing_page_views: true, conversions: true, revenue: true }

    const canonical = diagnose({ objective: 'app_promotion', totals: full, reported })
    const raw = diagnose({ objective: 'app_installs', totals: full, reported })

    expect(canonical.findings.map((f) => f.code)).toEqual(raw.findings.map((f) => f.code))
    expect(canonical.findings.some((f) => f.stage === 'conversion')).toBe(true)
  })

  it('reads the chain when every step is reported', () => {
    const d = diagnose(input())

    expect(d.state).toBe('diagnosed')
    expect(d.missing).toEqual([])
  })

  /*
   * «Examined and healthy» is a different answer from «could not be examined», and the first version
   * of this module collapsed them: a perfectly healthy account came back `not_diagnosable`, which
   * reads as «we cannot tell you anything» about an account where nothing is wrong.
   */
  it('gives a healthy account a clean bill rather than a shrug', () => {
    const d = diagnose(input())

    expect(d.state).toBe('diagnosed')
    expect(d.findings).toEqual([])
  })

  it('finds the weakness when there is one', () => {
    const d = diagnose(input({
      totals: { spend: 1000, impressions: 100000, clicks: 500, landing_page_views: 400, conversions: 0, revenue: 0 },
    }))

    expect(d.findings.some((f) => f.stage === 'conversion')).toBe(true)
  })

  /*
   * The rule this exists for. Visits were never reported, so the coalesced 0 must not become «nobody
   * reached your page» — the platform simply never said.
   */
  it('never blames a stage whose evidence was never reported', () => {
    const d = diagnose(input({
      totals: { spend: 1000, impressions: 100000, clicks: 500, landing_page_views: 0, conversions: 10, revenue: 5000 },
      reported: { spend: true, impressions: true, clicks: true, landing_page_views: false, conversions: true, revenue: true },
    }))

    expect(d.findings.every((f) => f.stage !== 'visit')).toBe(true)
    expect(d.missing).toContain('landing_page_views')
  })

  /*
   * A real zero IS evidence. The platform reported the visits and there were none — that is a fact
   * about the campaign, and refusing to say so would be the opposite failure.
   */
  it('does blame a stage whose evidence was reported as zero', () => {
    const d = diagnose(input({
      totals: { spend: 1000, impressions: 100000, clicks: 500, landing_page_views: 0, conversions: 0, revenue: 0 },
      reported: { spend: true, impressions: true, clicks: true, landing_page_views: true, conversions: true, revenue: true },
    }))

    expect(d.findings.some((f) => f.stage === 'visit')).toBe(true)
  })

  /** With nothing downstream of spend reported at all, it says so instead of guessing. */
  it('is explicitly not diagnosable when the chain has no evidence', () => {
    const d = diagnose(input({
      totals: { spend: 1000 },
      reported: { spend: true },
    }))

    expect(d.state).toBe('not_diagnosable')
    expect(d.findings).toEqual([])
    expect(d.missing.length).toBeGreaterThan(0)
  })

  /** No spend at all is not a diagnosis either — there is nothing to explain. */
  it('says there is nothing to diagnose when nothing was spent', () => {
    const d = diagnose(input({ totals: { spend: 0 }, reported: { spend: true } }))

    expect(d.state).toBe('not_diagnosable')
  })

  /*
   * Every finding carries the metrics it was derived from. A claim a reader cannot trace is a verdict
   * wearing the clothes of a measurement — the same rule the campaign card follows.
   */
  it('names the evidence behind every finding', () => {
    for (const f of diagnose(input()).findings) {
      expect(f.evidence.length).toBeGreaterThan(0)
      for (const metric of f.evidence) {
        expect(input().reported[metric]).toBe(true)
      }
    }
  })

  /*
   * A cause inferred rather than measured says so. «Your audience is wrong» is never observed — it is
   * inferred from a low click-through, and the language has to carry that.
   */
  it('marks an inferred cause as probable rather than observed', () => {
    const d = diagnose(input({
      totals: { spend: 1000, impressions: 100000, clicks: 50, landing_page_views: 40, conversions: 1, revenue: 100 },
    }))

    const inferred = d.findings.filter((f) => f.confidence === 'probable')
    expect(inferred.length).toBeGreaterThan(0)
  })

  /** An objective the taxonomy does not know is not diagnosed against a sales chain. */
  it('does not judge an unknown objective by another objective’s chain', () => {
    const d = diagnose(input({ objective: null }))

    expect(d.findings.every((f) => f.stage !== 'value')).toBe(true)
  })
})

/**
 * The lead-quality arm — the chain's last stage for a leads campaign.
 *
 * «Twelve leads» is where this chain used to stop, and it is not the question a lead-gen advertiser
 * has. Twelve leads none of which the sales team could use is a worse outcome than four that
 * converted, and a diagnosis that ends at the count calls that campaign healthy.
 *
 * The stage says exactly one thing, and the restraint is the point: leads arrived, none qualified.
 * There is no ratio, because «what share of leads should qualify» has no cross-account answer — a
 * mortgage broker and a gym are both healthy at rates an order of magnitude apart, and a threshold
 * chosen here would be a benchmark this product invented and then reported back as a finding.
 */
describe('the lead quality stage', () => {
  const leads = (o: Partial<DiagnosticInput> = {}): DiagnosticInput => ({
    objective: 'leads',
    totals: { spend: 1000, impressions: 50000, clicks: 400, landing_page_views: 380, conversions: 12, leads: 12, qualified_leads: 3 },
    reported: { spend: true, impressions: true, clicks: true, landing_page_views: true, conversions: true, leads: true, qualified_leads: true },
    ...o,
  })

  it('says nothing when leads are qualifying', () => {
    const d = diagnose(leads())

    expect(d.state).toBe('diagnosed')
    expect(d.findings.map((f) => f.code)).not.toContain('leads_none_qualified')
  })

  it('names it when leads arrive and none of them qualify', () => {
    const d = diagnose(leads({
      totals: { spend: 1000, impressions: 50000, clicks: 400, landing_page_views: 380, conversions: 12, leads: 12, qualified_leads: 0 },
    }))

    const finding = d.findings.find((f) => f.code === 'leads_none_qualified')
    expect(finding).toBeDefined()
    /* Observed, not probable: it is a count, not an inference from a ratio. */
    expect(finding?.confidence).toBe('observed')
    expect(finding?.evidence).toEqual(['leads', 'qualified_leads'])
  })

  /**
   * `qualified_leads` comes back from a CRM, not from the ad platform, so most accounts do not
   * report it. That must be NAMED rather than judged — inferring quality from the platform's own
   * count would be inventing the feedback the account has not given.
   */
  it('names the missing evidence rather than judging without it', () => {
    const d = diagnose(leads({
      reported: { spend: true, impressions: true, clicks: true, landing_page_views: true, conversions: true, leads: true },
    }))

    expect(d.missing).toContain('qualified_leads')
    expect(d.findings.map((f) => f.code)).not.toContain('leads_none_qualified')
  })

  /** No leads at all is the conversion stage's finding. Saying both would report one problem twice. */
  it('stays silent when there were no leads to qualify', () => {
    const d = diagnose(leads({
      totals: { spend: 1000, impressions: 50000, clicks: 400, landing_page_views: 380, conversions: 0, leads: 0, qualified_leads: 0 },
    }))

    expect(d.findings.map((f) => f.code)).toContain('no_conversions')
    expect(d.findings.map((f) => f.code)).not.toContain('leads_none_qualified')
  })

  /** A sales campaign is not judged on lead quality — it did not buy leads. */
  it('belongs to the leads chain alone', () => {
    const d = diagnose({
      objective: 'sales',
      totals: { spend: 1000, impressions: 50000, clicks: 400, landing_page_views: 380, conversions: 12, revenue: 9000, leads: 12, qualified_leads: 0 },
      reported: { spend: true, impressions: true, clicks: true, landing_page_views: true, conversions: true, revenue: true, leads: true, qualified_leads: true },
    })

    expect(d.findings.map((f) => f.code)).not.toContain('leads_none_qualified')
  })
})

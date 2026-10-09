import { describe, expect, it } from 'vitest'
import { figuresFor, type ReportAd } from './ReportAdsSection'

/**
 * CONTENT-RESULT-AVAILABILITY-001 — «الطلبات لا تظهر», and the chain that caused it.
 *
 * The owner's report was about the client-facing reports: the order count was missing from the ads
 * a client is deciding about. The cause was one `if / else if / else if` — `roas` ELSE `cpa` ELSE
 * the count — so an ad with ANY positive return never showed how many orders it produced, and only
 * the weakest ad in the section, the one with no return at all, fell through far enough to say
 * «النتائج». Two cards reading 8.75× and 5.47× with no count between them, and a third reading
 * «النتائج 0»: exactly what he saw, and exactly what the chain guarantees.
 *
 * `figuresFor` feeds the live tile, the snapshot card and the dialog, so this is tested at the
 * function rather than through one of its three surfaces.
 */

const ad = (over: Partial<ReportAd> = {}): ReportAd => ({
  id: 'a1',
  name: 'Eid film',
  provider: 'meta',
  objective: 'sales',
  preview: null,
  spend: 3000,
  impressions: 120_000,
  clicks: 3400,
  conversions: 88,
  ctr: 0.0283,
  cpa: 34.09,
  roas: 4.2,
  ...over,
} as ReportAd)

const labels = (a: ReportAd) => figuresFor(a, true, 'SAR').map((f) => f.label)
const value = (a: ReportAd, label: string) => figuresFor(a, true, 'SAR').find((f) => f.label === label)?.value

describe('a result leads the card, and a return comments on it', () => {
  /** The defect itself: an ad with a return used to hide its count. */
  it('shows the count on a sales ad that also has a return', () => {
    const sales = ad()

    expect(labels(sales)).toContain('النتائج')
    expect(value(sales, 'النتائج')).toBe('88')
  })

  /**
   * The ORDER is the point, not merely the presence.
   *
   * What it cost, what it bought, then at what multiple — the order a client asks in. A card that
   * printed the multiple before the count would be answering the commentary before the sentence.
   */
  it('puts what the money bought before the multiple it returned', () => {
    expect(labels(ad())).toEqual(['الإنفاق', 'النتائج', 'العائد'])
  })

  /**
   * CTR is what gives way, and only on a card that HAS a result.
   *
   * Three figures is the card's shape and a click-through rate is a traffic measure. An ad with no
   * result still shows it, because that is the only kind of ad it was ever the right third figure
   * for.
   */
  it('keeps the click-through rate on an ad that reports no result at all', () => {
    const brand = ad({ conversions: null, cpa: null, roas: null })

    expect(labels(brand)).toEqual(['الإنفاق', 'نسبة النقر'])
  })

  it('falls back to impressions when even the rate is absent', () => {
    const bare = ad({ conversions: null, cpa: null, roas: null, ctr: null })

    expect(labels(bare)).toEqual(['الإنفاق', 'الظهور'])
  })

  /** Without a return, the cost per result is the commentary — and the count still leads it. */
  it('shows the count beside a cost per result when there is no return', () => {
    const leads = ad({ roas: null, conversions: 42 })

    expect(labels(leads)).toEqual(['الإنفاق', 'النتائج', 'تكلفة النتيجة'])
    expect(value(leads, 'النتائج')).toBe('42')
  })

  /**
   * A zero return is still not printed. «0.00×» is a claim that the ad returned nothing.
   *
   * This rule predates the reordering and must survive it: the count appears, the ratio does not,
   * and the third figure is delivery rather than a fabricated nought.
   */
  it('never prints a zero return, and the count takes its place', () => {
    const unsold = ad({ roas: 0, cpa: 0, conversions: 0 })

    expect(labels(unsold)).toContain('النتائج')
    expect(value(unsold, 'النتائج')).toBe('0')
    expect(labels(unsold)).not.toContain('العائد')
  })

  /**
   * CREATIVE-GRAIN-TRUTH-001 — a count the platform never attributed here is a dash, not a zero.
   *
   * The server withholds the result family for a campaign whose creatives carry no breakdown; the
   * row still carries the platform's zero. Printing it would tell a client this ad sold nothing,
   * which is the specific thing #621 exists to prevent — and moving the count UP the card would
   * have made that wrong answer more prominent, not less, if it were not handled here.
   */
  it('shows a dash rather than a zero when the result was never attributed here', () => {
    /*
     * The SHAPE `metricState` actually reads, which the first version of this case had wrong.
     *
     * It expects `metrics.conversions` to be a NUMBER and the verdict to live in
     * `metrics.availability.conversions`. The fixture passed an object under the metric key, so
     * the state fell through to `no_data` — a dash — and the test passed while proving nothing
     * about attribution at all. The companion case below is what caught it: with a REPORTED zero
     * the same fixture also dashed, which it must not.
     */
    const unattributed = ad({
      conversions: 0,
      roas: null,
      cpa: null,
      metrics: { conversions: 0, availability: { conversions: 'not_attributable' } },
    } as Partial<ReportAd>)

    expect(value(unattributed, 'النتائج')).toBe('—')
  })

  /**
   * …and a REPORTED zero is printed, which is what makes the dash above mean something.
   *
   * «This sales ad produced no orders» is a fact about somebody's advertising and belongs on the
   * card. Only «nobody attributed orders here» is a dash. Without this case the one above passes
   * for any fixture the reader cannot parse.
   */
  it('prints a measured zero, because that is a result and not an absence', () => {
    const sold_nothing = ad({
      conversions: 0,
      roas: null,
      cpa: null,
      metrics: { conversions: 0, availability: { conversions: 'reported_value' } },
    } as Partial<ReportAd>)

    expect(value(sold_nothing, 'النتائج')).toBe('0')
  })
})

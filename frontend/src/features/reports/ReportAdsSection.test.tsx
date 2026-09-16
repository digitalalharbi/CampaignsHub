import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { ReportAdsSection, type AdGroup, type ReportAd } from './ReportAdsSection'
import { renderWithProviders } from '@/test/utils'

/**
 * REPORT-AD-PREVIEW-001 — the ads in the document the client keeps.
 *
 * The generator has carried ad-level rows and their media for a while and nothing rendered them, so
 * the part of a report a client actually recognises was a rank number on a coloured square. Three
 * things this pins, and the second is the one that matters most:
 *
 *   1. an ad with media shows it, with the facts that place it beside the picture;
 *   2. an ad WITHOUT media still renders its row, saying why — the library's own sentence, never a
 *      placeholder and never a frame borrowed from a sibling ad;
 *   3. an absent SECTION says why it is absent, because an empty grid under «الإعلانات» reads as
 *      «your ads were so bad there is nothing to show» — a claim about the client's advertising made
 *      by a gap in ours.
 */
const preview = (over: Record<string, unknown> = {}) => ({
  state: 'available',
  kind: 'image',
  image_url: 'https://cdn/ad.jpg',
  video_url: null,
  thumbnail_url: 'https://cdn/ad-thumb.jpg',
  expires_at: null,
  note_ar: null,
  note_en: null,
  cards: null,
  ...over,
}) as ReportAd['preview']

const ad = (over: Partial<ReportAd> = {}): ReportAd => ({
  id: 'a1',
  name: 'Eid film',
  provider: 'meta',
  objective: 'sales',
  preview: preview(),
  spend: 3000,
  impressions: 120_000,
  clicks: 3400,
  conversions: 88,
  ctr: 0.0283,
  cpa: 34.09,
  roas: 4.2,
  reason: 'Highest return on spend in this window.',
  ...over,
})

/**
 * CLIENT-REPORT-MONEY-REDACTION-001 — the four money states, on the ad card a client reads.
 *
 * `ReportAds` used to write `spend => metrics.spend ?? 0`, so an unconvertible amount arrived here as
 * a zero and this card printed «0 SAR» for an ad that had really spent 412.50 USD. The builder now
 * leaves `spend` null and carries the original beside it, and the card reads both through the one
 * money contract. These four cases are the states that contract distinguishes and `cash()` could not.
 */
describe('the ads section states money truthfully', () => {
  const withheldAd = (over: Partial<ReportAd> = {}): ReportAd =>
    ad({
      spend: null,
      spend_original: 412.5,
      spend_withheld_rows: 3,
      money_original_currency: 'USD',
      money_original_currencies: 1,
      ...over,
    })

  it('prints an unconvertible spend as its own amount and currency', () => {
    renderWithProviders(<ReportAdsSection ads={[withheldAd()]} locale="en" currency="SAR" />, { locale: 'en' })

    const figure = screen.getByText(/41[23]/)
    expect(figure.textContent, 'the platform currency was not stated').toMatch(/USD/)
    expect(figure.textContent, 'an unconvertible amount was labelled with the reporting currency').not.toMatch(/SAR/)
  })

  it('never prints a withheld spend as zero', () => {
    renderWithProviders(<ReportAdsSection ads={[withheldAd()]} locale="en" currency="SAR" />, { locale: 'en' })

    expect(screen.queryByText(/^0(\.0+)?\s*(SAR|USD)?$/), 'a withheld spend rendered as a zero').toBeNull()
  })

  it('prints a converted spend in the reporting currency', () => {
    renderWithProviders(<ReportAdsSection ads={[ad({ spend: 3000 })]} locale="en" currency="SAR" />, { locale: 'en' })

    expect(screen.getByText(/3,000/).textContent).toMatch(/SAR/)
  })

  /**
   * The permission: `ShareService` removes every spend key, so the card is not built from anything.
   * A dash under «Spend» would name the figure the agency withheld, which is what removal avoids.
   */
  it('names no spend at all when the link carried none', () => {
    const redacted = ad()
    delete redacted.spend

    renderWithProviders(<ReportAdsSection ads={[redacted]} locale="en" currency="SAR" />, { locale: 'en' })

    expect(screen.getByText('Eid film')).toBeInTheDocument()
    expect(screen.queryByText('Spend'), 'a link carrying no spend named it anyway').toBeNull()
  })
})

describe('the ads section of a report', () => {
  it('shows the ad, its picture and the facts that place it', () => {
    renderWithProviders(<ReportAdsSection ads={[ad()]} locale="en" />, { locale: 'en' })

    expect(screen.getByTestId('report-ads')).toHaveAttribute('data-state', 'present')
    expect(screen.getByText('Eid film')).toBeInTheDocument()
    // `image_url` is the file; the thumbnail is the fallback — the library's own rule, unchanged here.
    expect(screen.getByTestId('report-ad-poster-0')).toHaveAttribute('src', 'https://cdn/ad.jpg')
    // The platform and the objective place it. NOT the campaign — CLIENT-REPORT-ENTITY-BOUNDARY-001.
    expect(screen.getByText(/Meta/)).toBeInTheDocument()
    // The ranker's own sentence travels with the ad — «best» is stated, not implied by position.
    expect(screen.getByText(/Highest return on spend/)).toBeInTheDocument()
  })

  it('still renders the ad when the platform never sent its file, and says so', () => {
    const withheld = ad({
      preview: preview({ state: 'withheld', image_url: null, thumbnail_url: null, note_en: 'The platform’s preview link carries a credential.' }),
    })

    renderWithProviders(<ReportAdsSection ads={[withheld]} locale="en" />, { locale: 'en' })

    expect(screen.getByText('Eid film')).toBeInTheDocument()
    // Said to the client in their words: the link does not show it — not how our link carries a credential.
    expect(screen.getByTestId('report-ad-poster-0-absent')).toHaveTextContent('Not shown on this link')
    expect(screen.getByTestId('report-ad-poster-0-absent')).not.toHaveTextContent(/credential/i)
    // Nothing invented a picture in its place.
    expect(screen.queryByTestId('report-ad-poster-0')).not.toBeInTheDocument()
  })

  /** A sales ad leads with return; the figures are the ones the platform actually reported. */
  it('shows the figures the ad reported, not a fixed four', () => {
    const brand = ad({ roas: null, cpa: null, conversions: null, ctr: null, impressions: 900_000 })

    renderWithProviders(<ReportAdsSection ads={[brand]} locale="en" />, { locale: 'en' })

    expect(screen.getByText('Impressions')).toBeInTheDocument()
    expect(screen.queryByText('ROAS')).not.toBeInTheDocument()
    expect(screen.queryByText('CPA')).not.toBeInTheDocument()
  })

  it('says why the section is empty rather than showing an empty grid', () => {
    renderWithProviders(
      <ReportAdsSection ads={[]} absentReason="no_rankable_metric_for_this_objective" locale="en" />,
      { locale: 'en' },
    )

    expect(screen.getByTestId('report-ads')).toHaveAttribute('data-state', 'absent')
    expect(screen.getByTestId('report-ads-absent')).toHaveTextContent(/no metric this objective can be ranked on/i)
  })

  it('falls back to a plain reason when the generator sent none', () => {
    renderWithProviders(<ReportAdsSection ads={undefined} locale="ar" />, { locale: 'ar' })

    expect(screen.getByTestId('report-ads-absent')).toHaveTextContent('لا إعلانات لعرضها في هذه الفترة.')
  })
})

/**
 * FUNNEL-ANALYTICAL-PATTERN-001 — the reading above the grid, and the silence where there is no range.
 */
describe('the reading of the ads grid', () => {
  it('names both ends and the action the evidence supports', () => {
    renderWithProviders(
      <ReportAdsSection
        ads={[ad()]}
        locale="en"
        reading={{
          signal: { metric: 'roas', best: { ad: 'Eid film', value: 6.2 }, worst: { ad: 'Old banner', value: 0.8 } },
          explanation: { ar: '…', en: 'Both ads were bought for the same objective' },
          action: { ar: '…', en: 'Compare «Old banner» against «Eid film»' },
          silent_reason: null,
        }}
      />,
      { locale: 'en' },
    )

    expect(screen.getByTestId('report-ads-reading')).toHaveTextContent('Eid film')
    expect(screen.getByTestId('report-ads-reading')).toHaveTextContent('Old banner')
    expect(screen.getByTestId('report-ads-action')).toHaveTextContent(/Compare/)
  })

  /** One ad is not two ends, and the section shows the grid without inventing a range over it. */
  it('shows the grid and no reading when the server could not read a range', () => {
    renderWithProviders(
      <ReportAdsSection
        ads={[ad()]}
        locale="en"
        reading={{ signal: null, explanation: null, action: null, silent_reason: 'only_one_ad_is_comparable' }}
      />,
      { locale: 'en' },
    )

    expect(screen.getByTestId('report-ads')).toHaveAttribute('data-state', 'present')
    expect(screen.queryByTestId('report-ads-reading')).not.toBeInTheDocument()
  })

  /**
   * CONTENT-PREVIEW-SHAPES-001 — the card that was an empty grey square in a client's report.
   *
   * A Snapchat or TikTok video ad whose file resolved but whose thumbnail never arrived produced a
   * reading that is not an absence — so the absence sentence was empty, and the card drew a blank
   * box with nothing written in it. In a document a client keeps, that reads as a broken report.
   */
  it('says a video has no cover frame instead of drawing an empty box', () => {
    const film = ad({
      preview: preview({ kind: 'video', video_url: 'https://cdn/ad.mp4', image_url: null, thumbnail_url: null }),
    })

    renderWithProviders(<ReportAdsSection ads={[film]} locale="en" onOpen={() => {}} />, { locale: 'en' })

    const absent = screen.getByTestId('report-ad-poster-0-absent')
    expect(absent).toHaveTextContent(/no cover frame/i)
    expect(absent).toHaveAttribute('data-absence', 'video-no-cover')
    // And the card still opens, because the film itself is there to be played.
    expect(screen.getByTestId('report-ad-card')).toHaveAttribute('data-openable', 'true')
  })

})

/**
 * Owner row 97 — a deck SLIDE cannot grow, and what it could not hold must be stated.
 *
 * The continuous report scrolls; a slide is a fixed A4 landscape page, and the layout gate refuses
 * to ship one whose content had to be shrunk below 0.85 to fit. On an estate with five objective
 * groups the ads slide measured 0.355 with 108 clipped elements — unreadable, and it BLOCKED the
 * export of the client PDF entirely, on both forms identically.
 *
 * The bound is on GROUPS rather than on cards, and that was settled by measurement rather than by
 * argument: two groups of three and two groups of two both measured 0.80, because three cards and
 * two occupy the same single row of a three-column grid. Height comes from the group blocks.
 */
describe('the ads slide of a paged deck', () => {
  const group = (family: string): AdGroup => ({
    family,
    label_ar: family,
    label_en: family,
    metric: 'roas',
    metric_label_ar: 'العائد',
    metric_label_en: 'ROAS',
    ads: [ad(), ad()],
    ranked: true,
    candidates: 2,
    shown: 2,
  })

  const five = ['sales', 'leads', 'awareness', 'traffic', 'app'].map(group)

  it('renders every objective group in the continuous report', () => {
    renderWithProviders(<ReportAdsSection ads={[ad()]} groups={five} locale="en" />, { locale: 'en' })

    for (const g of five) expect(screen.getByTestId(`report-ads-group-${g.family}`)).toBeInTheDocument()
    expect(screen.queryByTestId('report-ads-groups-withheld')).not.toBeInTheDocument()
  })

  it('bounds the groups on a slide, because the page cannot grow to hold them', () => {
    renderWithProviders(<ReportAdsSection ads={[ad()]} groups={five} locale="en" paged />, { locale: 'en' })

    expect(screen.getByTestId('report-ads-group-sales')).toBeInTheDocument()
    expect(screen.queryByTestId('report-ads-group-app')).not.toBeInTheDocument()
  })

  /*
   * The count is the whole point of the bound. «The objectives this page had room for» read as
   * «the objectives that performed» is a claim about the client's advertising made by our page
   * size — the same defect REPORT-CREATIVE-TRUTH-001 was opened for, one element along.
   */
  it('states how many objectives the slide could not hold, and where they are', () => {
    renderWithProviders(<ReportAdsSection ads={[ad()]} groups={five} locale="en" paged />, { locale: 'en' })

    const note = screen.getByTestId('report-ads-groups-withheld')
    expect(note).toHaveTextContent('4')
    expect(note).toHaveTextContent(/interactive report/i)
  })

  /** A deck that fits says nothing, because a notice printed every time is one nobody reads. */
  it('says nothing when every group fitted', () => {
    renderWithProviders(<ReportAdsSection ads={[ad()]} groups={[group('sales')]} locale="en" paged />, { locale: 'en' })

    expect(screen.queryByTestId('report-ads-groups-withheld')).not.toBeInTheDocument()
  })
})

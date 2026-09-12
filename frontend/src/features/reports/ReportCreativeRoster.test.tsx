import { describe, expect, it, vi } from 'vitest'
import { fireEvent, screen } from '@testing-library/react'
import { ReportCreativeRoster, type RosterRow } from './ReportCreativeRoster'
import { renderWithProviders } from '@/test/utils'

/**
 * REPORT-CREATIVE-TRUTH-001 §B — «what did we run» is a question the report has to answer.
 *
 * The ads section ranks three ways over and answers «which of these worked». A client paying for
 * sixty-five creatives who can see six cannot tell from any of it whether the other fifty-nine
 * exist, and a report that shows only its winners is not wrong about a single figure and is still
 * the wrong document.
 *
 * What is pinned here is the part that can silently stop being true: the SCOPE count, which comes
 * from the server and must never be re-derived from the rows on the page — the moment it is, a
 * capped list starts reporting its own length as the whole account, which is the exact defect.
 */
const row = (over: Partial<RosterRow> = {}): RosterRow => ({
  id: 'c1',
  name: 'Eid film',
  provider: 'meta',
  preview: null,
  format: 'video',
  objective: 'sales',
  metrics: { spend: 3000, impressions: 120_000, clicks: 3400, conversions: 88, ctr: 0.0283 },
  ...over,
})

const many = (n: number): RosterRow[] =>
  Array.from({ length: n }, (_, i) => row({ id: `c${i}`, name: `Creative ${i}` }))

describe('the roster of everything that ran', () => {
  it('states the scope it was taken from, not the length of its own list', () => {
    renderWithProviders(
      <ReportCreativeRoster roster={many(60)} inScope={65} withheld={5} currency="USD" locale="en" form="detailed" />,
      { locale: 'en' },
    )

    expect(screen.getByTestId('report-roster-scope')).toHaveTextContent('65 creatives')
    expect(screen.getByTestId('report-roster-withheld')).toHaveTextContent('60 listed here, 5 not shown')
    /*
      A PAGE of the sixty, not all sixty — §D renders the roster fifty at a time and offers the rest.
      What this case is about is the SCOPE sentence, which must keep describing the sixty-five that
      ran whatever the table happens to have drawn so far.
    */
    expect(screen.getAllByTestId('report-roster-row')).toHaveLength(50)
    expect(screen.getByTestId('report-roster-more')).toHaveTextContent('10 remaining')
  })

  /* Nothing withheld says nothing about withholding — a «0 not shown» is noise on every full report. */
  it('says nothing about withholding when nothing was withheld', () => {
    renderWithProviders(
      <ReportCreativeRoster roster={many(4)} inScope={4} withheld={0} locale="en" form="detailed" />,
      { locale: 'en' },
    )

    expect(screen.getByTestId('report-roster-scope')).toHaveTextContent('4 creatives')
    expect(screen.queryByTestId('report-roster-withheld')).toBeNull()
  })

  /**
   * A summary states the count and withholds the LIST — never the count.
   *
   * Dropping the sentence with the table is the version that reintroduces the defect: six ads with
   * no number beside them is precisely what reads as «these are the ads that ran».
   */
  it('a summary keeps the count and drops the table', () => {
    renderWithProviders(
      <ReportCreativeRoster roster={many(60)} inScope={65} withheld={5} locale="en" form="executive_summary" />,
      { locale: 'en' },
    )

    expect(screen.getByTestId('report-roster')).toHaveAttribute('data-state', 'counted')
    expect(screen.getByTestId('report-roster-scope')).toHaveTextContent('65 creatives')
    expect(screen.getByTestId('report-roster-summary-note')).toBeInTheDocument()
    expect(screen.queryAllByTestId('report-roster-row')).toHaveLength(0)
  })

  /* An empty scope is the ads section's sentence to say; a second empty heading says it twice. */
  it('draws nothing at all when nothing ran', () => {
    const { container } = renderWithProviders(
      <ReportCreativeRoster roster={[]} inScope={0} withheld={0} locale="en" form="detailed" />,
      { locale: 'en' },
    )

    expect(container.querySelector('[data-testid="report-roster"]')).toBeNull()
  })

  /**
   * A figure the platform never reported is a dash, never a zero.
   *
   * «0 clicks» on a creative nobody measured is a claim that it was seen and ignored — the coalesced
   * zero, in the one table a client scans for the thing they paid for.
   */
  it('prints an unmeasured figure as absent rather than as nothing happening', () => {
    renderWithProviders(
      <ReportCreativeRoster
        roster={[row({ metrics: { spend: 500, impressions: null, clicks: null, conversions: null, ctr: null } })]}
        inScope={1}
        withheld={0}
        currency="USD"
        locale="en"
        form="detailed"
      />,
      { locale: 'en' },
    )

    const cells = screen.getByTestId('report-roster-row').closest('tr')!.querySelectorAll('td, th')
    expect(cells[2]).toHaveTextContent('500 USD')
    expect(cells[3]).toHaveTextContent('—')
    expect(cells[4]).toHaveTextContent('—')
    expect(cells[6]).toHaveTextContent('—')
  })

  /**
   * Money carries its scope's currency, or no currency word at all — never a guessed one.
   *
   * Five hundred rather than the three thousand the other cases use: the primitive abbreviates a
   * larger figure to «3K» with the exact value a hover away, and an assertion that has to know which
   * side of that threshold it is on tests the formatter instead of the rule.
   */
  it('prints no currency word when the scope could not name one', () => {
    renderWithProviders(
      <ReportCreativeRoster
        roster={[row({ metrics: { spend: 500, impressions: 10, clicks: 1, conversions: 1, ctr: 0.1 } })]}
        inScope={1}
        withheld={0}
        currency={null}
        locale="en"
        form="detailed"
      />,
      { locale: 'en' },
    )

    const cells = screen.getByTestId('report-roster-row').closest('tr')!.querySelectorAll('td, th')
    expect(cells[2]).toHaveTextContent('500')
    expect(cells[2]).not.toHaveTextContent('USD')
  })

  /**
   * The row opens the SAME detail the ranked cards open, with the figures flattened on the way.
   *
   * `present()` nests them under `metrics` and `ReportAdDetail` reads them flat, so a roster row
   * handed over unflattened opens a modal with every figure blank — a detail that silently disagrees
   * with the row that opened it.
   */
  it('opens one creative with its figures, in the shape the detail reads', () => {
    const onOpen = vi.fn()
    renderWithProviders(
      <ReportCreativeRoster roster={[row()]} inScope={1} withheld={0} locale="en" form="detailed" onOpen={onOpen} />,
      { locale: 'en' },
    )

    fireEvent.click(screen.getByTestId('report-roster-open'))

    expect(onOpen).toHaveBeenCalledWith(expect.objectContaining({
      name: 'Eid film', spend: 3000, clicks: 3400, conversions: 88,
    }))
  })

  /* A surface that cannot open one must not invite the press — the printed page is the case. */
  it('is not a control where there is nowhere to open', () => {
    renderWithProviders(
      <ReportCreativeRoster roster={[row()]} inScope={1} withheld={0} locale="en" form="detailed" />,
      { locale: 'en' },
    )

    expect(screen.queryByTestId('report-roster-open')).toBeNull()
  })

  /**
   * REPORT-CREATIVE-TRUTH-001 §D — every creative is REACHABLE, a page at a time.
   *
   * «A disclosed 65 ran / 60 listed cap alone is not completion.» The payload carries them all now;
   * what the table must not do is draw four thousand rows in one pass. The button is the difference
   * between a bound and a page: the rows are here, it says how many are left, and nothing is
   * withheld behind a request.
   */
  it('draws a first page and offers the rest, saying how many', () => {
    renderWithProviders(
      <ReportCreativeRoster roster={many(120)} inScope={120} withheld={0} locale="en" form="detailed" />,
      { locale: 'en' },
    )

    expect(screen.getAllByTestId('report-roster-row')).toHaveLength(50)
    expect(screen.getByTestId('report-roster-more')).toHaveTextContent('70 remaining')
  })

  it('reaches the whole estate by pressing on', () => {
    renderWithProviders(
      <ReportCreativeRoster roster={many(120)} inScope={120} withheld={0} locale="en" form="detailed" />,
      { locale: 'en' },
    )

    fireEvent.click(screen.getByTestId('report-roster-more'))
    expect(screen.getAllByTestId('report-roster-row')).toHaveLength(100)

    fireEvent.click(screen.getByTestId('report-roster-more'))
    expect(screen.getAllByTestId('report-roster-row')).toHaveLength(120)
    expect(screen.queryByTestId('report-roster-more'), 'it offered more when there was none').toBeNull()
  })

  /** A list that fits needs no button at all. */
  it('offers nothing more when everything is already drawn', () => {
    renderWithProviders(
      <ReportCreativeRoster roster={many(4)} inScope={4} withheld={0} locale="en" form="detailed" />,
      { locale: 'en' },
    )

    expect(screen.queryByTestId('report-roster-more')).toBeNull()
  })

  /** Arabic counts its noun — «65 مادة إعلانية», not «65 مواد إعلانية». */
  it('counts in the reader’s own grammar', () => {
    const { unmount } = renderWithProviders(
      <ReportCreativeRoster roster={many(3)} inScope={3} withheld={0} locale="ar" form="detailed" />,
      { locale: 'ar' },
    )
    expect(screen.getByTestId('report-roster-scope')).toHaveTextContent('3 مواد إعلانية')
    unmount()

    renderWithProviders(
      <ReportCreativeRoster roster={many(11)} inScope={65} withheld={0} locale="ar" form="detailed" />,
      { locale: 'ar' },
    )
    expect(screen.getByTestId('report-roster-scope')).toHaveTextContent('65 مادة إعلانية')
  })

  /**
   * REPORT-CREATIVE-MEDIA-001 — the picture arrives, and the row draws it.
   *
   * The owner opened a production Detailed Report and found rows reading «لا يوجد غلاف» for
   * creatives the Content library shows. The renderer was never the fault: it draws
   * `<AdPoster preview={row.preview ?? null} />`, and `AdPoster` reads the envelope through
   * `readPreview` — the same reader the library uses, which is what makes the two agree.
   *
   * The fault was that `preview` never arrived. `CreativeRows::lean()` does not write one (rightly:
   * a stored snapshot outlives a signed media URL), and `RosterRow.preview` is OPTIONAL, so the
   * absent field type-checked, the poster was handed `null`, and every row claimed no cover. The
   * server now resolves the media when the report is READ.
   *
   * This pins the renderer's half of that contract: given the envelope, the picture is drawn.
   */
  it('draws the creative’s media when the preview arrives', () => {
    renderWithProviders(
      <ReportCreativeRoster
        roster={[row({
          format: 'image',
          preview: {
            state: 'available',
            kind: 'image',
            aspect: 'square',
            image_url: 'https://cdn.test/hero.jpg',
            video_url: null,
            thumbnail_url: null,
            expires_at: null,
            note_ar: null,
            note_en: null,
          },
        })]}
        inScope={1}
        withheld={0}
        currency="USD"
        locale="en"
        form="detailed"
      />,
      { locale: 'en' },
    )

    const poster = screen.getByTestId('report-roster-poster-0')

    expect(poster.tagName, 'the row drew no image for a creative whose media the server resolved').toBe('IMG')
    expect(poster).toHaveAttribute('src', 'https://cdn.test/hero.jpg')
    expect(screen.queryByTestId('report-roster-poster-0-absent')).toBeNull()
  })

  /**
   * And a creative with genuinely no media still says so, compactly.
   *
   * The fix must not be «always draw something»: an absence the product cannot resolve is a fact
   * the reader needs, and a blank rectangle in its place is what the compact absence state exists
   * to replace.
   */
  it('keeps the compact absence state when there is truly no media', () => {
    renderWithProviders(
      <ReportCreativeRoster
        roster={[row({ preview: null })]}
        inScope={1}
        withheld={0}
        currency="USD"
        locale="en"
        form="detailed"
      />,
      { locale: 'en' },
    )

    expect(screen.getByTestId('report-roster-poster-0-absent')).toBeInTheDocument()
    expect(screen.queryByTestId('report-roster-poster-0')).toBeNull()
  })

  /**
   * Pressing a roster row opens THAT creative — the owner's «clicking report/content preview opens
   * the correct creative».
   *
   * The row hands `asReportAd(row)` to the caller, which flattens the figures into the shape
   * `ReportAdDetail` reads, and the risk in a flattener is that it drops or crosses a field: a panel
   * opening on the right name with the wrong picture, or with the row above's numbers, is worse than
   * one that fails to open, because nothing on screen says it is wrong.
   */
  it('opens the creative that was pressed, with its own media and figures', () => {
    const opened: Array<Record<string, unknown>> = []

    renderWithProviders(
      <ReportCreativeRoster
        roster={[
          row({ id: 'first', name: 'First film' }),
          row({
            id: 'second',
            name: 'Second film',
            preview: {
              state: 'available',
              kind: 'image',
              aspect: 'square',
              image_url: 'https://cdn.test/second.jpg',
              video_url: null,
              thumbnail_url: null,
              expires_at: null,
              note_ar: null,
              note_en: null,
            },
            metrics: { spend: 11, impressions: 22, clicks: 33, conversions: 44, ctr: 0.55 },
          }),
        ]}
        inScope={2}
        withheld={0}
        currency="USD"
        locale="en"
        form="detailed"
        onOpen={(ad) => opened.push(ad as unknown as Record<string, unknown>)}
      />,
      { locale: 'en' },
    )

    fireEvent.click(screen.getAllByTestId('report-roster-open')[1])

    expect(opened).toHaveLength(1)
    expect(opened[0]).toMatchObject({
      id: 'second',
      name: 'Second film',
      spend: 11,
      impressions: 22,
      clicks: 33,
      conversions: 44,
    })
    expect((opened[0].preview as { image_url?: string }).image_url).toBe('https://cdn.test/second.jpg')
  })
})

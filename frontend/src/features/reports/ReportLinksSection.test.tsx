import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { ReportLinksSection, type ReportLinkRow } from './ReportLinksSection'
import { renderWithProviders } from '@/test/utils'

/**
 * REPORT-LINK-SECTION-001 — a report can finally name the address the money pointed at.
 *
 * The two failures worth guarding are both about counting:
 *
 *  1. summing the window's follows with the link's lifetime counter, which would count a year of
 *     clicks into a month;
 *  2. printing a column of zeroes for a window that closed before any follow was timed, which a
 *     client reads as «nobody clicked» over a period that may have been their best.
 *
 * The second is the one the data makes easy to get wrong, because the rows exist and the figure is
 * genuinely zero — it is the MEANING that is unavailable, not the number.
 */
const row = (slug: string, follows: number, allTime: number): ReportLinkRow => ({
  slug,
  short_url: `https://campaignshub.io/l/${slug}`,
  destination: `https://client.test/${slug}`,
  follows,
  clicks_all_time: allTime,
  is_active: true,
})

describe('the report’s short links section', () => {
  it('reports the window’s follows and the lifetime counter as separate figures', () => {
    renderWithProviders(
      <ReportLinksSection links={[row('aaa1111', 4, 400)]} locale="en" recordingSince="2026-10-01T00:00:00+00:00" />,
      { locale: 'en' },
    )

    /* The testid marks the link's own cell; the claim is about the ROW it sits in. */
    const cells = screen.getByTestId('report-link-aaa1111').closest('tr')?.textContent ?? ''

    expect(cells).toContain('4')
    expect(cells).toContain('400')
    /* …and never their sum, which is the mistake this separation exists to prevent. */
    expect(cells).not.toContain('404')
  })

  it('says a window that closed before recording cannot be spoken for', () => {
    renderWithProviders(
      <ReportLinksSection links={[]} absentReason="links_not_recorded_in_this_window" locale="en" />,
      { locale: 'en' },
    )

    const note = screen.getByTestId('report-links-absent')

    expect(note).toHaveTextContent('not the same as zero')
    /* No table, so no column of zeroes to be misread. */
    expect(screen.queryByTestId('report-links-section')).not.toBeInTheDocument()
  })

  it('distinguishes «none in this scope» from «not recorded»', () => {
    renderWithProviders(
      <ReportLinksSection links={[]} absentReason="no_short_link_in_this_scope" locale="en" />,
      { locale: 'en' },
    )

    expect(screen.getByTestId('report-links-absent')).toHaveTextContent('no short links')
  })

  it('states where the counting begins, so a small figure is readable', () => {
    renderWithProviders(
      <ReportLinksSection links={[row('bbb2222', 2, 90)]} locale="en" recordingSince="2026-10-01T00:00:00+00:00" />,
      { locale: 'en' },
    )

    expect(screen.getByTestId('report-links-since')).toHaveTextContent('2026-10-01')
  })

  /* A measured, quiet period is a real answer — the table stays and the chart does not pretend. */
  it('keeps the table but draws no ranking when nothing was followed', () => {
    renderWithProviders(
      <ReportLinksSection
        links={[row('ccc3333', 0, 10), row('ddd4444', 0, 4)]}
        locale="en"
        recordingSince="2026-10-01T00:00:00+00:00"
      />,
      { locale: 'en' },
    )

    expect(screen.getByTestId('report-links-section')).toBeInTheDocument()
    expect(screen.queryByTestId('report-links-chart')).not.toBeInTheDocument()
  })

  it('ranks on the window’s follows once there is a comparison to make', () => {
    renderWithProviders(
      <ReportLinksSection
        links={[row('eee5555', 3, 3), row('fff6666', 11, 11)]}
        locale="en"
        recordingSince="2026-10-01T00:00:00+00:00"
      />,
      { locale: 'en' },
    )

    expect(screen.getByTestId('report-links-chart')).toBeInTheDocument()
  })

  /* One link is not a ranking — it is the figure the row already states. */
  it('draws no ranking for a single link', () => {
    renderWithProviders(
      <ReportLinksSection links={[row('ggg7777', 9, 9)]} locale="en" recordingSince="2026-10-01T00:00:00+00:00" />,
      { locale: 'en' },
    )

    expect(screen.queryByTestId('report-links-chart')).not.toBeInTheDocument()
  })

  it('reads in Arabic without leaving an English word behind', () => {
    renderWithProviders(
      <ReportLinksSection links={[row('hhh8888', 5, 50)]} locale="ar" recordingSince="2026-10-01T00:00:00+00:00" />,
      { locale: 'ar' },
    )

    const section = screen.getByTestId('report-links-section')

    expect(section).toHaveTextContent('الوجهة')
    expect(section).toHaveTextContent('متابعات الفترة')
    expect(screen.getByTestId('report-links-since')).toHaveTextContent('غير مُسجَّل')
  })
})

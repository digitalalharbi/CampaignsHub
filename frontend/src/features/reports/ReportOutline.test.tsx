import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { ReportOutline } from './ReportOutline'
import { renderWithProviders } from '@/test/utils'
import type { ReportSection } from './InteractiveReport'

/**
 * REPORT-ANALYTICAL-DEPTH-001 — the contents page, including what the report does not contain.
 *
 * A client who opens a report and finds no objective breakdown has two readings available: the
 * agency did not do that analysis, or there was nothing to break down. Only one is true, and the
 * report has to say which — the same obligation as refusing a «Findings» heading over an empty
 * state, pointing the other way.
 */
const section = (over: Partial<ReportSection> = {}): ReportSection => ({
  key: 'platforms',
  title_ar: 'تفصيل المنصات',
  title_en: 'Platform breakdown',
  present: true,
  figures: ['spend'],
  ...over,
})

describe('the report says what is in it', () => {
  it('numbers the sections it contains', () => {
    renderWithProviders(
      <ReportOutline
        ar={false}
        outline={[
          section({ key: 'executive_summary', title_en: 'Executive summary' }),
          section({ key: 'platforms' }),
        ]}
      />,
      { locale: 'en' },
    )

    expect(screen.getByTestId('report-outline-executive_summary')).toHaveTextContent('1.')
    expect(screen.getByTestId('report-outline-platforms')).toHaveTextContent('Platform breakdown')
  })

  /** The whole point: an absent section is listed with the reason, not silently missing. */
  it('lists a missing section with the reason it is missing', () => {
    renderWithProviders(
      <ReportOutline
        ar={false}
        outline={[
          section(),
          section({
            key: 'objectives',
            title_en: 'Breakdown by objective',
            present: false,
            figures: undefined,
            absent_reason: 'one_objective_only',
            absent_reason_en: 'One objective only — a breakdown by objective would be a single row under a comparison heading.',
            absent_reason_ar: 'هدف واحد فقط.',
          }),
        ]}
      />,
      { locale: 'en' },
    )

    expect(screen.getByTestId('report-outline-absent')).toBeInTheDocument()
    expect(screen.getByTestId('report-outline-objectives')).toHaveTextContent('One objective only')
  })

  /** Nothing absent, nothing to explain — the strip does not print an empty explanation block. */
  it('shows no absence list when every section is present', () => {
    renderWithProviders(<ReportOutline ar={false} outline={[section()]} />, { locale: 'en' })

    expect(screen.queryByTestId('report-outline-absent')).not.toBeInTheDocument()
  })

  /**
   * A snapshot generated before this existed carries no outline.
   *
   * Those links are already in clients' inboxes and must keep rendering exactly as they did, so the
   * strip is absent rather than an empty box asking what happened to the contents.
   */
  it('renders nothing at all for a snapshot that has no outline', () => {
    renderWithProviders(<ReportOutline ar={false} outline={undefined} />, { locale: 'en' })

    expect(screen.queryByTestId('report-outline')).not.toBeInTheDocument()
  })

  it('reads in Arabic without falling back to the English copy', () => {
    renderWithProviders(
      <ReportOutline
        ar
        outline={[
          section({
            key: 'findings',
            title_ar: 'النتائج والتوصيات',
            title_en: 'Findings and recommendations',
            present: false,
            figures: undefined,
            absent_reason: 'nothing_supported_by_evidence',
            absent_reason_ar: 'لا نتيجة تدعمها الأرقام في هذه الفترة.',
            absent_reason_en: 'No finding is supported by the figures in this period.',
          }),
        ]}
      />,
      { locale: 'ar' },
    )

    const row = screen.getByTestId('report-outline-findings')
    expect(row).toHaveTextContent('لا نتيجة تدعمها الأرقام')
    expect(row).not.toHaveTextContent('No finding is supported')
  })
})

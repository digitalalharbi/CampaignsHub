import { beforeAll, describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { PrintDocument } from './PrintDocument'
import { DAILY_ROWS_PER_PAGE, dailyRecordsChosen, withDailyRecordsSlides } from './reportSections'

/**
 * REPORT-COMPOSITION-CONTROL-001 (Owner defect 106) — «إظهار السجل اليومي» reaches the PDF too.
 *
 * The ledger prints only when the server's section list NAMES `daily_records`: a document resolved
 * before the component existed (no list at all) never gains a page, and a link that switched it off
 * prints none — the same rule the link, the CSV and the workbook follow.
 */
beforeAll(() => {
  if (!('fonts' in document)) {
    Object.defineProperty(document, 'fonts', { value: { ready: Promise.resolve() }, configurable: true })
  }
})

const days = Array.from({ length: 20 }, (_, i) => ({ date: `2026-08-${String(i + 1).padStart(2, '0')}`, impressions: 1000 + i, clicks: 20, conversions: 2 }))

const doc = (reportSections: string[] | undefined, timeseries: unknown[] = days) =>
  render(<PrintDocument data={{ period: { from: '2026-08-01', to: '2026-08-20' }, kpis: {}, summary: [], platforms: [], recommendations: [], outline: [], timeseries, ...(reportSections ? { report_sections: reportSections } : {}) } as never} currency="SAR" reportName="R" clientName="C" />)

type Page = { id: string; type: string; order: number; visible: boolean; range?: [number, number] }

describe('the printed daily records', () => {
  it('is printed only when the section list names it', () => {
    expect(dailyRecordsChosen({ report_sections: ['kpis', 'daily_records'] })).toBe(true)
    expect(dailyRecordsChosen({ report_sections: ['kpis', 'trends'] })).toBe(false)
    expect(dailyRecordsChosen({})).toBe(false)
  })

  it('prints every day, without a spend column when the document carries no spend', () => {
    doc(['kpis', 'daily_records'])

    const section = screen.getByTestId('print-daily-records')
    expect(section.querySelectorAll('tbody tr')).toHaveLength(20)
    expect(section.textContent).not.toContain('Spend')
  })

  it('prints nothing when daily records are off, or when the document predates the switch', () => {
    doc(['kpis', 'trends'])
    expect(screen.queryByTestId('print-daily-records')).toBeNull()
  })

  it('a document with no section list gains no ledger', () => {
    doc(undefined)
    expect(screen.queryByTestId('print-daily-records')).toBeNull()
  })

  it('splits the deck into fixed pages of fourteen days', () => {
    const slides = withDailyRecordsSlides<Page>([], { report_sections: ['daily_records'], timeseries: days })
    expect(slides.map((s) => s.range)).toEqual([[0, DAILY_ROWS_PER_PAGE], [DAILY_ROWS_PER_PAGE, DAILY_ROWS_PER_PAGE * 2]])
    expect(withDailyRecordsSlides<Page>([], { report_sections: ['kpis'], timeseries: days })).toEqual([])
  })
})

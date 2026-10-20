import { beforeAll, describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { PrintDocument } from './PrintDocument'
import { selectColumns } from './reportColumns'

/*
 * REPORT-TABLES-OWNER-COLUMNS-001 — the Owner's column choice reaches the PDF's tables: the daily ledger, and
 * the helper every table goes through keeps the row's name, leaves out columns the Owner cannot choose when
 * the table is restricted, and changes nothing when the table is not.
 */
beforeAll(() => {
  if (!('fonts' in document)) {
    Object.defineProperty(document, 'fonts', { value: { ready: Promise.resolve() }, configurable: true })
  }
})

const days = [{ date: '2026-08-01', impressions: 1000, clicks: 20, conversions: 2, spend: 50 }, { date: '2026-08-02', impressions: 900, clicks: 18, conversions: 1, spend: 40 }]

const doc = (reportColumns?: Record<string, string[]>) =>
  render(<PrintDocument data={{ period: { from: '2026-08-01', to: '2026-08-02' }, kpis: {}, summary: [], platforms: [], recommendations: [], outline: [], timeseries: days, report_sections: ['kpis', 'daily_records'], ...(reportColumns ? { report_columns: reportColumns } : {}) } as never} currency="SAR" reportName="R" clientName="C" />)

describe('printed table columns', () => {
  it('keeps every column when the Owner chose none', () => {
    doc()
    const heads = [...screen.getByTestId('print-daily-records').querySelectorAll('th')].map((h) => h.textContent)
    expect(heads).toEqual(['Day', 'Impressions', 'Clicks', 'Results', 'Spend'])
  })

  it('prints only the chosen daily columns, the day always first', () => {
    doc({ days: ['clicks', 'spend'] })
    const section = screen.getByTestId('print-daily-records')
    expect([...section.querySelectorAll('th')].map((h) => h.textContent)).toEqual(['Day', 'Clicks', 'Spend'])
    expect(section.querySelectorAll('tbody tr')[0].textContent).toContain('2026-08-01')
    expect(section.querySelectorAll('tbody tr')[0].textContent).not.toContain('1,000')
  })

  it('cuts a table to its choice and drops columns the Owner cannot choose', () => {
    const out = selectColumns(['spend', 'results'], ['', 'spend', '', 'results', ''], ['Platform', 'Spend', 'Revenue', 'Results', 'ROAS'], [['Meta', '1', '2', '3', '4']])
    expect(out.head).toEqual(['Platform', 'Spend', 'Results'])
    expect(out.rows).toEqual([['Meta', '1', '3']])
  })

  it('leaves a table alone when no choice was made', () => {
    const head = ['A', 'B']
    const rows = [['1', '2']]
    expect(selectColumns(undefined, ['', 'b'], head, rows)).toEqual({ head, rows })
  })
})

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { cutColumns } from './reportColumns'
import { ReportCreativeRoster } from './ReportCreativeRoster'
import { ReportsPage } from './ReportsPage'
import type { Option } from '@/components/forms'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'

const TAX: Record<string, Option[]> = {
  'report.type': [{ value: 'executive', label_en: 'Executive', label_ar: 'تنفيذي' }],
  'report.audience': [{ value: 'client', label_en: 'Client', label_ar: 'العميل' }],
}

vi.mock('@/features/taxonomy/taxonomyApi', () => ({
  useTaxonomyOptions: (key: string) => ({ options: TAX[key] ?? [], isPending: false, isError: false, refetch: vi.fn() }),
}))

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, listReports: vi.fn(), createReport: vi.fn(), listReportSections: vi.fn() }
})

vi.mock('./ReportScopePicker', async () => {
  const React = await import('react')
  return {
    ReportScopePicker: ({ onChange }: { onChange: (v: { account_ids: string[] }) => void }) => {
      React.useEffect(() => { onChange({ account_ids: ['a1'] }) }, [])
      return null
    },
  }
})

import { createReport, listReports } from './api'

/**
 * REPORT-TABLES-OWNER-COLUMNS-001 — the SAVED report carries the Owner's column choice itself.
 *
 * The link builder had the picker and the saved report did not, so a snapshot's page, PDF and files
 * were the surfaces the Owner could not restrict. The builder now saves the choice under
 * `config.columns`, and the saved page's tables cut themselves by it the way the print does.
 */
describe('cutColumns', () => {
  const columns = [
    { key: 'name', label: 'Name', kind: 'text' as const },
    { key: 'spend', label: 'Spend', kind: 'number' as const },
    { key: 'conversions', label: 'Results', kind: 'number' as const },
    { key: 'roas', label: 'ROAS', kind: 'ratio' as const },
  ]
  const keys = { spend: 'spend', conversions: 'results' }

  it('keeps every column when nothing was chosen', () => {
    expect(cutColumns(undefined, columns, keys).map((c) => c.key)).toEqual(['name', 'spend', 'conversions', 'roas'])
  })

  it('keeps the name, the chosen columns, and drops what the Owner cannot choose on a restricted table', () => {
    expect(cutColumns(['results'], columns, keys).map((c) => c.key)).toEqual(['name', 'conversions'])
  })
})

describe('the saved report page tables honour the choice', () => {
  it('the content roster shows the name and the chosen columns only', () => {
    const roster = [{ id: 'c1', name: 'Summer hero', provider: 'meta', spend: 120, impressions: 5000, clicks: 80, conversions: 4, ctr: 0.016 }]
    renderWithProviders(
      <ReportCreativeRoster roster={roster as never} inScope={1} withheld={0} currency="SAR" locale="en" form="detailed" chosen={['spend']} />,
      { locale: 'en' },
    )
    const headers = screen.getAllByRole('columnheader').map((h) => h.textContent?.replace('↕', '').trim())
    expect(headers).toEqual(['Creative', 'Spend'])
  })
})

describe('the saved-report builder saves the choice', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useProject.setState({ currentProjectId: 'p1' })
    vi.mocked(listReports).mockResolvedValue({ reports: [], summary: { total: 0, completed: 0, processing: 0, failed: 0 } })
    vi.mocked(createReport).mockResolvedValue({} as never)
    signInWith(['reports.view', 'reports.create'])
  })
  afterEach(() => signOut())

  it('sends config.columns for the restricted tables only, and nothing when every column is kept', async () => {
    renderWithProviders(<ReportsPage />, { locale: 'en' })
    fireEvent.click(screen.getByText(/تقرير محفوظ|Saved report/))
    await screen.findByRole('combobox', { name: /نوع التقرير|Report type/ })
    fireEvent.change(screen.getByLabelText(/اسم التقرير|Report name/), { target: { value: 'Q3 report' } })

    // Untick one platform column; the other tables stay unrestricted and do not travel.
    fireEvent.click(screen.getByTestId('rb-columns').querySelector('summary') as HTMLElement)
    fireEvent.click(screen.getByTestId('column-spend'))
    fireEvent.click(screen.getByText(/إنشاء وتوليد|Create and generate/))

    await waitFor(() => expect(createReport).toHaveBeenCalledWith('p1', expect.objectContaining({ config: { columns: { platforms: ['results', 'cost', 'impressions', 'clicks', 'ctr'] } } })))
  })

  it('sends no config when nothing was restricted', async () => {
    renderWithProviders(<ReportsPage />, { locale: 'en' })
    fireEvent.click(screen.getByText(/تقرير محفوظ|Saved report/))
    await screen.findByRole('combobox', { name: /نوع التقرير|Report type/ })
    fireEvent.change(screen.getByLabelText(/اسم التقرير|Report name/), { target: { value: 'Q3 report' } })
    fireEvent.click(screen.getByText(/إنشاء وتوليد|Create and generate/))

    await waitFor(() => expect(createReport).toHaveBeenCalled())
    expect(vi.mocked(createReport).mock.calls[0]?.[1]).not.toHaveProperty('config')
  })
})

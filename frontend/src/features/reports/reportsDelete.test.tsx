import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { ReportsPage } from './ReportsPage'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'

vi.mock('@/features/taxonomy/taxonomyApi', () => ({
  useTaxonomyOptions: () => ({ options: [], isPending: false, isError: false, refetch: vi.fn() }),
}))
vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, listReports: vi.fn(), deleteReport: vi.fn(), listReportSections: vi.fn() }
})

import { deleteReport, listReports } from './api'

const row = (id: string, name: string) => ({
  id, name, description: null, type: 'live', form: 'detailed', mode: 'live', status: 'completed', audience: 'client', version: 1,
  period: { from: '2026-10-01', to: '2026-10-29' }, currency: 'USD', config: { live: true }, exports: [], shares_count: 0, created_at: '2026-10-29T08:00:00+00:00',
})

/** NASEL 2026-10-29 — deleting a report asks first, names it, says its link stops, and several go with one confirmation. */
describe('deleting reports', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useProject.setState({ currentProjectId: 'p1' })
    vi.mocked(listReports).mockResolvedValue({ reports: [row('r1', 'رواق — أكتوبر'), row('r2', 'رواق — اختبار')], summary: { total: 2, completed: 2, processing: 0, failed: 0 } } as never)
    vi.mocked(deleteReport).mockResolvedValue({} as never)
    signInWith(['reports.view', 'reports.create', 'reports.manage', 'reports.delete'])
  })
  afterEach(() => signOut())

  it('never deletes on the first click: a dialog names the report and says its link stops', async () => {
    renderWithProviders(<ReportsPage />)
    await screen.findAllByText('رواق — أكتوبر')
    const del = (await screen.findAllByRole('button', { name: /حذف|Delete/ }))[0]
    fireEvent.click(del)

    const dialog = await screen.findByTestId('reports-delete-dialog')
    expect(within(dialog).getByText('رواق — أكتوبر')).toBeInTheDocument()
    expect(dialog).toHaveTextContent(/سيتوقف رابط العميل|client link stops working/)
    expect(deleteReport).not.toHaveBeenCalled()

    fireEvent.click(screen.getByTestId('reports-delete-confirm'))
    await waitFor(() => expect(deleteReport).toHaveBeenCalledWith('p1', 'r1'))
    expect(deleteReport).toHaveBeenCalledTimes(1)
  })

  it('deletes several selected reports with one confirmation', async () => {
    renderWithProviders(<ReportsPage />)
    await screen.findAllByText('رواق — اختبار')
    fireEvent.click(screen.getByTestId('reports-pick-all'))
    fireEvent.click(screen.getByTestId('reports-bulk-delete'))

    const dialog = await screen.findByTestId('reports-delete-dialog')
    expect(dialog).toHaveTextContent('رواق — أكتوبر')
    expect(dialog).toHaveTextContent('رواق — اختبار')
    fireEvent.click(screen.getByTestId('reports-delete-confirm'))
    await waitFor(() => expect(deleteReport).toHaveBeenCalledTimes(2))
  })
})

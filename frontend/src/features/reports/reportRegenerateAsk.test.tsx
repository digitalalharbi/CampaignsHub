import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { ReportsPage } from './ReportsPage'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'

vi.mock('@/features/taxonomy/taxonomyApi', () => ({
  useTaxonomyOptions: () => ({ options: [], isPending: false, isError: false, refetch: vi.fn() }),
}))

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, listReports: vi.fn(), listShares: vi.fn(), regenerateReport: vi.fn() }
})

import { listReports, listShares, regenerateReport } from './api'

const report = (status: string) => ({
  id: 'r1', name: 'Report r1', type: 'executive', form: 'detailed', mode: 'project',
  campaign_objective: null, version: 1, status, audience: 'client',
  period: { from: '2026-07-01', to: '2026-07-31' }, currency: 'SAR', config: {},
  scope: null, is_demo: false, generated_at: null, last_sent_at: null,
  created_at: '2026-08-01T00:00:00+00:00', error: null, exports: [],
})

const listing = (status: string) => ({
  reports: [report(status)],
  summary: { total: 1, completed: 1, processing: 0, failed: 0 },
})

/** REPORT-SNAPSHOT-PROTECT-001 — the row asks before it replaces a stored copy. */
describe('the row action for rebuilding a report', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useProject.setState({ currentProjectId: 'p1' })
    signInWith(['reports.view', 'reports.manage', 'reports.share'])
    vi.mocked(listShares).mockResolvedValue([] as never)
    vi.mocked(regenerateReport).mockResolvedValue({} as never)
  })
  afterEach(() => {
    signOut()
    useProject.setState({ currentProjectId: null })
  })

  const rebuild = async () => fireEvent.click(await screen.findByRole('button', { name: /إعادة التوليد|إعادة الإنشاء|Regenerate|Rebuild/ }))

  it('asks first on a completed report and posts nothing until confirmed', async () => {
    vi.mocked(listReports).mockResolvedValue(listing('completed') as never)
    renderWithProviders(<ReportsPage />)
    await rebuild()
    expect(await screen.findByTestId('report-regenerate-ask')).toHaveTextContent(/سجل التقرير|report's history/)
    expect(regenerateReport).not.toHaveBeenCalled()
    fireEvent.click(screen.getByTestId('report-regenerate-confirm'))
    await waitFor(() => expect(regenerateReport).toHaveBeenCalledWith('p1', 'r1'))
  })

  it('does not ask on a report with nothing stored', async () => {
    vi.mocked(listReports).mockResolvedValue(listing('failed') as never)
    renderWithProviders(<ReportsPage />)
    await rebuild()
    await waitFor(() => expect(regenerateReport).toHaveBeenCalledWith('p1', 'r1'))
    expect(screen.queryByTestId('report-regenerate-ask')).toBeNull()
  })
})

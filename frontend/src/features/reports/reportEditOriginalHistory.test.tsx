import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { ReportsPage } from './ReportsPage'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'
import type { ReportRow } from './api'

/*
 * REPORT-SNAPSHOT-PROTECT-001 — the copy history is one of the things «تعديل الأصل» offers.
 *
 * Restoring an earlier copy changes THIS report and keeps its link, which is exactly what that dialog is for, so the history
 * sits there after «الأقسام», opened in place: a dialog inside a dialog closes both on Escape.
 */
vi.mock('@/features/taxonomy/taxonomyApi', () => ({
  useTaxonomyOptions: () => ({ options: [{ value: 'monthly', label_en: 'Monthly', label_ar: 'شهري' }], isPending: false, isError: false, refetch: vi.fn() }),
}))
vi.mock('@/features/projects/capabilities', () => ({
  useProjectCapabilities: () => ({ capabilities: ['reports.view', 'reports.manage'], can: () => true }),
}))
vi.mock('./versionsApi', () => ({ listReportVersions: vi.fn(), restoreReportVersion: vi.fn() }))
vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, listReports: vi.fn(), listReportSections: vi.fn(), startDuplicate: vi.fn(), createShare: vi.fn(), regenerateReport: vi.fn() }
})

import { createShare, listReports, listReportSections, regenerateReport, startDuplicate } from './api'
import { listReportVersions, restoreReportVersion } from './versionsApi'

const row: ReportRow = {
  id: 'r1', name: 'August', type: 'monthly', status: 'completed', audience: 'client', period: { from: '2026-08-01', to: '2026-08-31' },
  currency: 'SAR', is_demo: false, generated_at: '2026-10-05T09:30:00+00:00', last_sent_at: null, created_at: '2026-09-01T10:00:00Z', error: null, exports: [],
}
const versions = [
  { id: 'v2', generated_at: '2026-10-01T08:15:00+00:00', kept_at: '2026-10-05T09:30:00+00:00' },
  { id: 'v1', generated_at: '2026-09-01T10:00:00+00:00', kept_at: '2026-10-01T08:15:00+00:00' },
]

describe('«تعديل الأصل» offers the copy history', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useProject.setState({ currentProjectId: 'p1' })
    vi.mocked(listReports).mockResolvedValue({ reports: [row], summary: { total: 1, completed: 1, processing: 0, failed: 0, draft: 0 } })
    vi.mocked(listReportSections).mockResolvedValue({ sections: [] })
    vi.mocked(listReportVersions).mockResolvedValue(versions as never)
    vi.mocked(restoreReportVersion).mockResolvedValue({ generated_at: versions[1].generated_at } as never)
    signInWith(['reports.view', 'reports.create'])
  })
  afterEach(() => {
    signOut()
    useProject.setState({ currentProjectId: null })
  })

  for (const [locale, label] of [['ar', 'سجل النسخ واستعادة نسخة سابقة'], ['en', 'Copy history and restoring an earlier copy']] as const) {
    it(`right after the sections item, opened in place, and a restore is asked for and confirmed there (${locale})`, async () => {
      renderWithProviders(<ReportsPage />, { locale })
      fireEvent.click(await screen.findByTestId('report-edit-original-r1'))
      const dialog = within(await screen.findByTestId('edit-original-dialog'))

      const trigger = dialog.getByTestId('report-history-open-r1')
      expect(trigger).toHaveTextContent(label)
      expect(trigger).toHaveAttribute('aria-expanded', 'false')
      // Its place: the element after «الأقسام» is the history, not something further down the dialog.
      expect(dialog.getByTestId('edit-original-sections').nextElementSibling).toContainElement(trigger)
      expect(listReportVersions).not.toHaveBeenCalled()

      fireEvent.click(trigger)
      expect(trigger).toHaveAttribute('aria-expanded', 'true')
      expect(await dialog.findAllByTestId(/^report-version-v\d$/)).toHaveLength(2)
      expect(listReportVersions).toHaveBeenCalledWith('p1', 'r1')
      // In place: the one dialog on screen is still «تعديل الأصل».
      expect(screen.getAllByTestId('report-history')).toHaveLength(1)
      expect(screen.getByTestId('edit-original-dialog')).toContainElement(screen.getByTestId('report-history'))

      // Nothing is restored on the first press; the question is asked on the copy, and only the confirmation restores.
      fireEvent.click(dialog.getByTestId('report-version-restore-v1'))
      expect(restoreReportVersion).not.toHaveBeenCalled()
      expect(dialog.getByTestId('report-version-confirm')).toBeInTheDocument()
      fireEvent.click(dialog.getByTestId('report-version-confirm-yes'))
      await waitFor(() => expect(restoreReportVersion).toHaveBeenCalledWith('p1', 'r1', 'v1'))
      expect(await dialog.findByTestId('report-version-restored')).toBeInTheDocument()

      // A restore is not a rebuild, a copy or a link.
      expect(regenerateReport).not.toHaveBeenCalled()
      expect(startDuplicate).not.toHaveBeenCalled()
      expect(createShare).not.toHaveBeenCalled()
    })
  }
})

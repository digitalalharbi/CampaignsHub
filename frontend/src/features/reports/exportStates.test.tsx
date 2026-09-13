import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { ReportsPage } from './ReportsPage'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'
import type { Option } from '@/components/forms'

const TAX: Record<string, Option[]> = {
  'report.type': [{ value: 'executive', label_en: 'Executive', label_ar: 'تنفيذي' }],
  'report.audience': [{ value: 'client', label_en: 'Client', label_ar: 'العميل' }],
}

vi.mock('@/features/taxonomy/taxonomyApi', () => ({
  useTaxonomyOptions: (key: string) => ({
    options: TAX[key] ?? [],
    isPending: false,
    isError: false,
    refetch: vi.fn(),
  }),
}))

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, listReports: vi.fn(), createReport: vi.fn() }
})

import { listReports } from './api'

/**
 * REPORT-EXPORT-FUNCTIONAL-001 — an export that failed or is still running looks like neither a
 * finished download nor an untouched button.
 *
 * The owner clicked PDF in Production and nothing arrived. The row only ever asked for exports whose
 * status was `completed`, so a render still in flight and a render that FAILED both fell through to
 * the same «PDF» button — and the reason, already stored on the export, was never shown to anyone.
 *
 * These mount the real page against a mocked list, because the defect is in what the row DRAWS for
 * each state, and nothing about it needs a server.
 */
const exportRow = (over: Record<string, unknown> = {}) => ({
  id: 'e1', format: 'pdf', status: 'completed', size: 2048, token: 'tok', failure_reason: null, ...over,
})

const report = (exports: ReturnType<typeof exportRow>[]) => ({
  id: 'r1', name: 'Report r1', type: 'executive', form: 'detailed', mode: 'project',
  campaign_objective: null, version: 1, status: 'completed', audience: 'client',
  period: { from: '2026-07-01', to: '2026-07-31' }, currency: 'SAR', config: {},
  scope: null, is_demo: false, generated_at: null, last_sent_at: null,
  created_at: '2026-08-01T00:00:00+00:00', error: null, exports,
})

const listing = (exports: ReturnType<typeof exportRow>[]) => ({
  reports: [report(exports)],
  summary: { total: 1, completed: 1, processing: 0, failed: 0 },
})

describe('the reports table tells the truth about an export', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useProject.setState({ currentProjectId: 'p1' })
    signInWith(['reports.view', 'reports.export'])
  })
  afterEach(() => signOut())

  it('says WHY a failed PDF failed, in words an operator can act on', async () => {
    vi.mocked(listReports).mockResolvedValue(
      listing([exportRow({ status: 'failed', token: null, size: null, failure_reason: 'renderer_disabled' })]) as never,
    )

    renderWithProviders(<ReportsPage />, { locale: 'en' })
    await screen.findAllByText('Report r1')

    // The button is visibly a failure and still offers the retry.
    expect(await screen.findAllByTestId('export-failed-pdf-r1')).not.toHaveLength(0)
    // And the reason is VISIBLE, not hidden in a title attribute.
    const notes = await screen.findAllByTestId('export-failure-note-r1')
    expect(notes[0]).toHaveTextContent(/PDF/)
    expect(notes[0]).toHaveTextContent(/not enabled on this server/i)
    // The failed format must not also be offered as a finished download.
    expect(screen.queryByTestId('download-pdf-r1')).not.toBeInTheDocument()
  })

  it('does not blame the renderer for a file that is still being made', async () => {
    vi.mocked(listReports).mockResolvedValue(
      listing([exportRow({ status: 'processing', token: null, size: null })]) as never,
    )

    renderWithProviders(<ReportsPage />, { locale: 'en' })
    await screen.findAllByText('Report r1')

    expect(await screen.findAllByTestId('export-running-pdf-r1')).not.toHaveLength(0)
    expect(screen.queryByTestId('export-failed-pdf-r1')).not.toBeInTheDocument()
    expect(screen.queryByTestId('export-failure-note-r1')).not.toBeInTheDocument()
  })

  it('offers the download once it exists, and says nothing about failure', async () => {
    vi.mocked(listReports).mockResolvedValue(listing([exportRow()]) as never)

    renderWithProviders(<ReportsPage />, { locale: 'en' })
    await screen.findAllByText('Report r1')

    expect(await screen.findAllByTestId('download-pdf-r1')).not.toHaveLength(0)
    expect(screen.queryByTestId('export-failed-pdf-r1')).not.toBeInTheDocument()
    expect(screen.queryByTestId('export-failure-note-r1')).not.toBeInTheDocument()
  })

  /*
   * A format tried twice is described by its LATEST attempt: a retry that worked must not keep
   * wearing the error from the attempt before it.
   */
  it('lets a successful retry replace the earlier failure', async () => {
    vi.mocked(listReports).mockResolvedValue(
      listing([
        exportRow({ id: 'old', status: 'failed', token: null, size: null, failure_reason: 'timed_out' }),
        exportRow({ id: 'new', status: 'completed', token: 'tok2' }),
      ]) as never,
    )

    renderWithProviders(<ReportsPage />, { locale: 'en' })
    await screen.findAllByText('Report r1')

    expect(await screen.findAllByTestId('download-pdf-r1')).not.toHaveLength(0)
    expect(screen.queryByTestId('export-failed-pdf-r1')).not.toBeInTheDocument()
  })

  it('reads the failure in Arabic too', async () => {
    vi.mocked(listReports).mockResolvedValue(
      listing([exportRow({ status: 'failed', token: null, size: null, failure_reason: 'renderer_disabled' })]) as never,
    )

    renderWithProviders(<ReportsPage />, { locale: 'ar' })
    await screen.findAllByText('Report r1')

    const notes = await screen.findAllByTestId('export-failure-note-r1')
    expect(notes[0]).toHaveTextContent(/غير مُفعَّل على هذا الخادم/)
  })
})

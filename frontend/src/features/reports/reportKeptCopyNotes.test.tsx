import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, within } from '@testing-library/react'
import { ReportsPage } from './ReportsPage'
import { ReportSectionControls } from './ReportSectionControls'
import { AnnotationsPanel } from './AnnotationsPanel'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'

vi.mock('@/features/taxonomy/taxonomyApi', () => ({
  useTaxonomyOptions: () => ({ options: [], isPending: false, isError: false, refetch: vi.fn() }),
}))

const held = vi.hoisted(() => ({ list: ['reports.view', 'reports.manage'] as string[] | undefined }))
vi.mock('@/features/projects/capabilities', () => ({
  useProjectCapabilities: () => ({ capabilities: held.list, can: (c: string) => held.list === undefined || held.list.includes(c) }),
}))

vi.mock('./versionsApi', () => ({ listReportVersions: vi.fn(), restoreReportVersion: vi.fn() }))

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return {
    ...actual,
    listReports: vi.fn(), listShares: vi.fn(), getReportScope: vi.fn(), scopeOptions: vi.fn(), listScopeTemplates: vi.fn(),
    getReportSections: vi.fn(), listAnnotations: vi.fn(),
  }
})

import { getReportScope, getReportSections, listAnnotations, listReports, listScopeTemplates, listShares, scopeOptions } from './api'
import { listReportVersions } from './versionsApi'

const report = {
  id: 'r1', name: 'Report r1', type: 'executive', form: 'detailed', mode: 'project',
  campaign_objective: null, version: 1, status: 'completed', audience: 'client',
  period: { from: '2026-07-01', to: '2026-07-31' }, currency: 'SAR', config: {},
  scope: null, is_demo: false, generated_at: '2026-08-01T00:00:00+00:00', last_sent_at: null,
  created_at: '2026-08-01T00:00:00+00:00', error: null, exports: [],
}

/**
 * REPORT-SNAPSHOT-PROTECT-001 — the history is reachable from the report's row, and the three editors whose save rebuilds a
 * report say, where they save, that the copy it replaces is kept.
 */
describe('where the copy history is mounted, and what the editors say where they save', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useProject.setState({ currentProjectId: 'p1' })
    signInWith(['reports.view', 'reports.manage', 'reports.share', 'reports.approve'])
    vi.mocked(listReports).mockResolvedValue({ reports: [report], summary: { total: 1, completed: 1, processing: 0, failed: 0 } } as never)
    vi.mocked(listShares).mockResolvedValue([] as never)
    vi.mocked(listScopeTemplates).mockResolvedValue({ templates: [] } as never)
    vi.mocked(scopeOptions).mockResolvedValue({ providers: ['meta'], accounts: [] } as never)
    vi.mocked(listReportVersions).mockResolvedValue([{ id: 'v1', generated_at: '2026-07-20T10:00:00+00:00', kept_at: '2026-08-01T00:00:00+00:00' }] as never)
  })
  afterEach(() => {
    signOut()
    useProject.setState({ currentProjectId: null })
  })

  it('the report’s row opens its history in a dialog, for this report', async () => {
    renderWithProviders(<ReportsPage />, { locale: 'ar' })
    fireEvent.click(await screen.findByTestId('report-history-open-r1'))

    const dialog = await screen.findByRole('dialog')
    expect(dialog).toHaveTextContent('سجل النسخ: Report r1')
    expect(await within(dialog).findByTestId('report-version-v1')).toBeInTheDocument()
    expect(listReportVersions).toHaveBeenCalledWith('p1', 'r1')
  })

  it('the scope editor says the current copy is kept, beside its save', async () => {
    vi.mocked(getReportScope).mockResolvedValue({ scope: { account_ids: ['a1'] }, audience: 'client' } as never)
    renderWithProviders(<ReportsPage />, { locale: 'ar' })
    fireEvent.click(await screen.findByRole('button', { name: 'نطاق التقرير' }))

    const dialog = await screen.findByRole('dialog')
    expect(await within(dialog).findByTestId('kept-copy-note')).toHaveTextContent('تبقى النسخة الحالية في «سجل النسخ»')
    expect(within(dialog).getByTestId('scope-save')).toBeInTheDocument()
  })

  it('the streams editor, the one section save that rebuilds, says it beside its save', async () => {
    vi.mocked(getReportSections).mockResolvedValue({
      report_id: 'r1', audience: 'client', chosen: {}, streams: [],
      effective: { advanced_segmentation: true },
      resolved: [{ key: 'advanced_segmentation', title_ar: 'x', title_en: 'x', breakdown: true, visible: true, reason: null, because: null }],
      visible: ['advanced_segmentation'], availability_judged: true,
    } as never)
    renderWithProviders(<ReportSectionControls projectId="p1" reportId="r1" />, { locale: 'en' })

    const editor = await screen.findByTestId('streams-editor')
    expect(within(editor).getByTestId('stream-save')).toBeInTheDocument()
    expect(within(editor).getByTestId('kept-copy-note')).toHaveTextContent('the current copy is kept in “Copy history”')
  })

  it('the recommendations panel says it where a decision is taken', async () => {
    vi.mocked(listAnnotations).mockResolvedValue({ annotations: [{ id: 'a1', type: 'recommendation', text_ar: 'زيادة الميزانية', status: 'draft', platform: null, kpi: null }] } as never)
    renderWithProviders(<AnnotationsPanel projectId="p1" reportId="r1" />, { locale: 'ar' })

    expect(await screen.findByTitle('اعتماد')).toBeInTheDocument()
    expect(screen.getByTestId('kept-copy-note')).toHaveTextContent('تبقى النسخة الحالية في «سجل النسخ»')
  })
})

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { ReportsPage } from './ReportsPage'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'
import type { ReportEditorState, ReportRow } from './api'

/*
 * Owner correction 2026-11-04, §8.3 — «تكرار» opens the report EDITOR on an independent draft.
 *
 * It used to ask for a name in a browser prompt and make a report and a link at once. These are the screen's half of the
 * correction: pressing the action stores nothing, the editor is the existing builder filled from the original, and the
 * three ways out («إلغاء», «حفظ كمسودة», «نشر التقرير») call three different things.
 */
vi.mock('@/features/taxonomy/taxonomyApi', () => ({
  useTaxonomyOptions: () => ({ options: [{ value: 'monthly', label_en: 'Monthly', label_ar: 'شهري' }], isPending: false, isError: false, refetch: vi.fn() }),
}))
vi.mock('./ReportScopePicker', () => ({ ReportScopePicker: () => null }))
vi.mock('./ReportIdentityCards', () => ({ ReportIdentityCards: () => null }))
vi.mock('./InteractiveReport', () => ({ InteractiveReport: ({ meta }: { meta: { reportName: string } }) => <div data-testid="interactive-report">{meta.reportName}</div> }))
vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return {
    ...actual,
    listReports: vi.fn(), listReportSections: vi.fn(), createReport: vi.fn(), startDuplicate: vi.fn(), getReportDraft: vi.fn(),
    saveReportDraft: vi.fn(), publishReportDraft: vi.fn(), previewReportDraft: vi.fn(), listShares: vi.fn(), getCurrentShareLink: vi.fn(),
    createShare: vi.fn(), regenerateReport: vi.fn(),
  }
})

import {
  createReport, createShare, getCurrentShareLink, getReportDraft, listReports, listReportSections, listShares, previewReportDraft,
  publishReportDraft, regenerateReport, saveReportDraft, startDuplicate,
} from './api'

const row = (over: Partial<ReportRow> = {}): ReportRow => ({
  id: 'r1', name: 'August', type: 'monthly', status: 'completed', audience: 'client', period: { from: '2026-08-01', to: '2026-08-31' },
  currency: 'SAR', is_demo: false, generated_at: '2026-09-01T10:00:00Z', last_sent_at: null, created_at: '2026-09-01T10:00:00Z', error: null, exports: [],
  ...over,
})
const state = (over: Partial<ReportEditorState> = {}): ReportEditorState => ({
  id: null, name: null, description: null, type: 'monthly', mode: 'snapshot', form: 'detailed', detail_level: 'detailed', audience: 'client',
  campaign_objective: 'sales', period_start: '2026-08-01', period_end: '2026-08-31', period_mode: 'fixed', period_kind: 'custom',
  branding_identity: 'agency', prepared_for_name: null, prepared_by_name: null, columns: {}, sections: { trends: false }, scope: { account_ids: ['acc-1'] },
  ...over,
})
const started = { source: { id: 'r1', name: 'August', status: 'completed' as const }, suggested_name: { ar: 'نسخة من August', en: 'Copy of August' }, draft: state() }

const body = (call: unknown[]) => call[call.length - 1] as Record<string, unknown>
const nameField = () => screen.getByLabelText(/اسم التقرير|Report name/) as HTMLInputElement

describe('«تكرار» opens the editor on an independent draft', () => {
  const prompt = vi.spyOn(window, 'prompt')
  beforeEach(() => {
    vi.clearAllMocks()
    useProject.setState({ currentProjectId: 'p1' })
    vi.mocked(listReports).mockResolvedValue({ reports: [row(), row({ id: 'd1', name: 'Saved draft', status: 'draft', is_draft: true, generated_at: null })], summary: { total: 2, completed: 1, processing: 0, failed: 0, draft: 1 } })
    vi.mocked(listReportSections).mockResolvedValue({ sections: [{ key: 'trends', title_ar: 'الاتجاهات', title_en: 'Trends', default_client: true, default_internal: true }] })
    vi.mocked(startDuplicate).mockResolvedValue(started)
    signInWith(['reports.view', 'reports.create'])
  })
  afterEach(() => signOut())

  it('pressing Duplicate asks the server for a prefill, makes nothing, and opens the builder filled from the original', async () => {
    renderWithProviders(<ReportsPage />, { locale: 'ar' })
    fireEvent.click(await screen.findByTestId('report-duplicate-r1'))

    expect(await screen.findByTestId('rb-draft-banner')).toHaveTextContent('لم يُنشأ شيء بعد')
    expect(screen.getByTestId('rb-draft-banner')).toHaveTextContent('August')
    expect(startDuplicate).toHaveBeenCalledWith('p1', 'r1')
    expect(nameField().value).toBe('نسخة من August')
    expect(screen.getByTestId('rb-mode-snapshot')).toHaveAttribute('aria-pressed', 'true')
    expect(screen.getByTestId('rb-level-detailed')).toHaveAttribute('aria-pressed', 'true')
    expect(screen.getByTestId('rb-identity-agency')).toHaveAttribute('aria-pressed', 'true')
    expect(await screen.findByTestId('rb-section-trends')).not.toBeChecked()
    // The three ways out are three buttons, in words.
    expect(screen.getByTestId('rb-cancel')).toHaveTextContent('إلغاء')
    expect(screen.getByTestId('rb-save-draft')).toHaveTextContent('حفظ كمسودة')
    expect(screen.getByTestId('rb-publish')).toHaveTextContent('نشر التقرير')

    expect(prompt).not.toHaveBeenCalled()
    expect(createReport).not.toHaveBeenCalled()
    expect(createShare).not.toHaveBeenCalled()
    expect(regenerateReport).not.toHaveBeenCalled()
  })

  it('reads in English with the English suggested name', async () => {
    renderWithProviders(<ReportsPage />, { locale: 'en' })
    fireEvent.click(await screen.findByTestId('report-duplicate-r1'))
    expect(await screen.findByTestId('rb-draft-banner')).toHaveTextContent('Nothing has been created yet')
    expect(nameField().value).toBe('Copy of August')
    expect(screen.getByTestId('rb-save-draft')).toHaveTextContent('Save as draft')
    expect(screen.getByTestId('rb-publish')).toHaveTextContent('Publish report')
  })

  it('Cancel on an untouched copy closes and calls nothing; with edits it warns first and still creates nothing', async () => {
    renderWithProviders(<ReportsPage />, { locale: 'ar' })
    fireEvent.click(await screen.findByTestId('report-duplicate-r1'))
    fireEvent.click(await screen.findByTestId('rb-cancel'))
    await waitFor(() => expect(screen.queryByTestId('rb-draft-banner')).not.toBeInTheDocument())

    fireEvent.click(screen.getByTestId('report-duplicate-r1'))
    await screen.findByTestId('rb-draft-banner')
    fireEvent.change(nameField(), { target: { value: 'سبتمبر' } })
    fireEvent.click(screen.getByTestId('rb-cancel'))
    expect(screen.getByTestId('rb-unsaved')).toHaveTextContent('تعديلات غير محفوظة')
    // Staying keeps the edit.
    fireEvent.click(screen.getByTestId('rb-unsaved-stay'))
    expect(nameField().value).toBe('سبتمبر')
    fireEvent.click(screen.getByTestId('rb-cancel'))
    fireEvent.click(screen.getByTestId('rb-unsaved-discard'))
    await waitFor(() => expect(screen.queryByTestId('rb-draft-banner')).not.toBeInTheDocument())

    expect(createReport).not.toHaveBeenCalled()
    expect(saveReportDraft).not.toHaveBeenCalled()
    expect(publishReportDraft).not.toHaveBeenCalled()
  })

  it('«حفظ كمسودة» stores a draft with exactly what the editor shows, and a second save edits that same draft', async () => {
    vi.mocked(createReport).mockResolvedValue(row({ id: 'd9', status: 'draft' }))
    vi.mocked(saveReportDraft).mockResolvedValue(row({ id: 'd9', status: 'draft' }))
    renderWithProviders(<ReportsPage />, { locale: 'ar' })
    fireEvent.click(await screen.findByTestId('report-duplicate-r1'))
    await screen.findByTestId('rb-draft-banner')
    fireEvent.click(screen.getByTestId('rb-mode-live'))
    fireEvent.click(screen.getByTestId('rb-identity-auto'))
    fireEvent.click(screen.getByTestId('rb-save-draft'))

    await waitFor(() => expect(createReport).toHaveBeenCalledTimes(1))
    const first = body(vi.mocked(createReport).mock.calls[0])
    expect(first).toMatchObject({
      draft: true, source_report_id: 'r1', name: 'نسخة من August', mode: 'live', type: 'monthly', detail_level: 'detailed',
      period_start: '2026-08-01', period_end: '2026-08-31', period_mode: 'fixed', period_kind: 'custom',
      scope: { account_ids: ['acc-1'] }, section_settings: { sections: { trends: false } }, config: { columns: {} },
    })
    // «تلقائي» is sent as an explicit null, so the identity choice the copy began with is cleared rather than kept silently.
    expect(first).toHaveProperty('branding_identity', null)
    expect(await screen.findByTestId('rb-draft-banner')).toHaveTextContent('مسودة محفوظة وغير منشورة')

    fireEvent.change(nameField(), { target: { value: 'August v2' } })
    expect(screen.getByTestId('rb-draft-banner')).toHaveTextContent('تعديلات لم تُحفظ بعد')
    fireEvent.click(screen.getByTestId('rb-save-draft'))
    await waitFor(() => expect(saveReportDraft).toHaveBeenCalledTimes(1))
    expect(vi.mocked(saveReportDraft).mock.calls[0].slice(0, 2)).toEqual(['p1', 'd9'])
    expect(body(vi.mocked(saveReportDraft).mock.calls[0])).toMatchObject({ name: 'August v2' })
    expect(createReport).toHaveBeenCalledTimes(1)

    // Publishing the saved draft publishes THAT row; it does not create another report.
    vi.mocked(publishReportDraft).mockResolvedValue(row({ id: 'd9', status: 'processing' }))
    fireEvent.click(screen.getByTestId('rb-publish'))
    await waitFor(() => expect(publishReportDraft).toHaveBeenCalledTimes(1))
    expect(vi.mocked(publishReportDraft).mock.calls[0].slice(0, 2)).toEqual(['p1', 'd9'])
    expect(createReport).toHaveBeenCalledTimes(1)
    expect(createShare).not.toHaveBeenCalled()
    expect(await screen.findByTestId('reports-row-note')).toHaveTextContent('لم يُنشأ له رابط')
  })

  it('«نشر التقرير» straight from the editor creates the independent report, not a draft, and no link', async () => {
    vi.mocked(createReport).mockResolvedValue(row({ id: 'n1', status: 'processing' }))
    renderWithProviders(<ReportsPage />, { locale: 'ar' })
    fireEvent.click(await screen.findByTestId('report-duplicate-r1'))
    fireEvent.click(await screen.findByTestId('rb-publish'))
    await waitFor(() => expect(createReport).toHaveBeenCalledTimes(1))
    const sent = body(vi.mocked(createReport).mock.calls[0])
    expect(sent).toMatchObject({ source_report_id: 'r1', scope: { account_ids: ['acc-1'] } })
    expect(sent).not.toHaveProperty('draft')
    expect(createShare).not.toHaveBeenCalled()
  })

  it('a refused save says why and keeps the editor open with everything in it', async () => {
    vi.mocked(createReport).mockRejectedValue({ response: { status: 422, data: { message: 'An ad account is not part of this project.' } } })
    renderWithProviders(<ReportsPage />, { locale: 'ar' })
    fireEvent.click(await screen.findByTestId('report-duplicate-r1'))
    await screen.findByTestId('rb-draft-banner')
    fireEvent.change(nameField(), { target: { value: 'مسودتي' } })
    fireEvent.click(screen.getByTestId('rb-save-draft'))

    expect(await screen.findByTestId('rb-refusal')).toBeInTheDocument()
    expect(nameField().value).toBe('مسودتي')
    expect(screen.getByTestId('rb-draft-banner')).toHaveTextContent('لم يُنشأ شيء بعد')
    expect(publishReportDraft).not.toHaveBeenCalled()
  })

  it('«معاينة» asks the server for the published engine’s document and stores nothing', async () => {
    vi.mocked(previewReportDraft).mockResolvedValue({ name: 'نسخة من August', data: { kpis: {} }, platforms: ['meta'] })
    renderWithProviders(<ReportsPage />, { locale: 'ar' })
    fireEvent.click(await screen.findByTestId('report-duplicate-r1'))
    fireEvent.click(await screen.findByTestId('rb-preview'))

    const shown = await screen.findByTestId('rb-preview-body')
    expect(within(shown).getByTestId('interactive-report')).toHaveTextContent('نسخة من August')
    expect(shown).toHaveTextContent('لم يُحفظ شيء، ولا يوجد رابط')
    expect(body(vi.mocked(previewReportDraft).mock.calls[0])).toMatchObject({ source_report_id: 'r1', scope: { account_ids: ['acc-1'] } })
    expect(createReport).not.toHaveBeenCalled()
    expect(createShare).not.toHaveBeenCalled()
  })

  it('a saved draft reopens in the editor from its row, and offers none of a published report’s actions', async () => {
    vi.mocked(getReportDraft).mockResolvedValue({ draft: state({ id: 'd1', name: 'Saved draft', mode: 'live' }) })
    renderWithProviders(<ReportsPage />, { locale: 'ar' })
    const again = await screen.findByTestId('report-draft-continue-d1')
    expect(again).toHaveTextContent('متابعة تحرير المسودة')
    expect(screen.queryByTestId('report-duplicate-d1')).not.toBeInTheDocument()
    expect(screen.queryByTestId('report-copy-link-d1')).not.toBeInTheDocument()

    fireEvent.click(again)
    expect(await screen.findByTestId('rb-draft-banner')).toHaveTextContent('مسودة محفوظة وغير منشورة')
    expect(getReportDraft).toHaveBeenCalledWith('p1', 'd1')
    expect(nameField().value).toBe('Saved draft')
    expect(screen.getByTestId('rb-mode-live')).toHaveAttribute('aria-pressed', 'true')
    expect(startDuplicate).not.toHaveBeenCalled()
  })

  it('names the three actions apart on the row and on the phone card, in both languages', async () => {
    const { unmount } = renderWithProviders(<ReportsPage />, { locale: 'ar' })
    expect(await screen.findByTestId('report-edit-original-r1')).toHaveTextContent('تعديل الأصل')
    expect(screen.getByTestId('report-duplicate-r1')).toHaveTextContent('تكرار')
    expect(screen.getByTestId('report-copy-link-r1')).toHaveTextContent('نسخ الرابط')
    const card = within(screen.getByTestId('reports-phone-cards'))
    expect(card.getByTestId('report-edit-original-card-r1')).toHaveTextContent('تعديل الأصل')
    expect(card.getByTestId('report-duplicate-card-r1')).toHaveTextContent('تكرار')
    expect(card.getByTestId('report-copy-link-card-r1')).toHaveTextContent('نسخ الرابط')
    expect(card.queryByTestId('report-card-actions-d1')).not.toBeInTheDocument()

    fireEvent.click(screen.getByTestId('report-edit-original-r1'))
    const dialog = await screen.findByTestId('edit-original-dialog')
    expect(dialog).toHaveTextContent('رابطه المُرسَل يبقى كما هو')
    for (const id of ['rename', 'names', 'scope', 'sections']) expect(within(dialog).getByTestId(`edit-original-${id}`)).toBeInTheDocument()
    expect(startDuplicate).not.toHaveBeenCalled()
    unmount()

    renderWithProviders(<ReportsPage />, { locale: 'en' })
    expect(await screen.findByTestId('report-edit-original-r1')).toHaveTextContent('Edit original')
    expect(screen.getByTestId('report-duplicate-r1')).toHaveTextContent('Duplicate')
    expect(screen.getByTestId('report-copy-link-r1')).toHaveTextContent('Copy link')
  })

  it('«نسخ الرابط» copies the current link as it is and creates nothing', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined)
    Object.assign(navigator, { clipboard: { writeText } })
    vi.mocked(listShares).mockResolvedValue([{ id: 's1', revoked_at: null } as never])
    vi.mocked(getCurrentShareLink).mockResolvedValue({ available: true, reason: null, url: 'https://campaignshub.io/r/abc', path: '/r/abc', mode: 'snapshot', active: true })
    renderWithProviders(<ReportsPage />, { locale: 'ar' })
    fireEvent.click(await screen.findByTestId('report-copy-link-r1'))

    await waitFor(() => expect(writeText).toHaveBeenCalledWith('https://campaignshub.io/r/abc'))
    expect(await screen.findByTestId('reports-row-note')).toHaveTextContent('لم يُنشأ رابط جديد')
    expect(createShare).not.toHaveBeenCalled()
    expect(startDuplicate).not.toHaveBeenCalled()
    expect(createReport).not.toHaveBeenCalled()
  })
})

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { renderWithProviders } from '@/test/utils'
import { ReportSectionControls } from './ReportSectionControls'
import * as api from './api'

/**
 * REPORT-SECTION-MODEL-001 — the operator's switches save to the server, and the preview is the
 * server's resolution rather than a local guess.
 */
vi.mock('./api', async () => {
  const actual = await vi.importActual<typeof api>('./api')

  return { ...actual, getReportSections: vi.fn(), updateReportSections: vi.fn(), updateTemplateSections: vi.fn(), listScopeTemplates: vi.fn(), listShares: vi.fn(), getShareSections: vi.fn(), updateShareSections: vi.fn() }
})

const row = (key: string, visible: boolean, reason: api.SectionReason | null, breakdown = false): api.ResolvedSectionRow => ({
  key, title_ar: key, title_en: key, breakdown, visible, reason, because: null,
})

const state = (overrides: Partial<api.ReportSectionsState> = {}): api.ReportSectionsState => ({
  report_id: 'r1',
  audience: 'client',
  chosen: {},
  effective: { kpis: true, budget_pacing: true, detailed_tables: false, advanced_segmentation: false },
  resolved: [
    row('kpis', true, null),
    row('budget_pacing', false, 'data_unavailable'),
    row('detailed_tables', false, 'disabled_by_operator'),
    row('advanced_segmentation', false, 'disabled_by_operator', true),
  ],
  visible: ['kpis'],
  availability_judged: true,
  ...overrides,
})

describe('ReportSectionControls', () => {
  beforeEach(() => {
    vi.mocked(api.getReportSections).mockResolvedValue(state())
    vi.mocked(api.listScopeTemplates).mockResolvedValue({ templates: [] })
    vi.mocked(api.listShares).mockResolvedValue([])
  })

  it('shows each switch at its effective state and the preview with the server’s reasons', async () => {
    renderWithProviders(<ReportSectionControls projectId="p1" reportId="r1" />, { locale: 'en' })

    expect(await screen.findByTestId('report-section-controls')).toBeInTheDocument()
    expect(screen.getByTestId('section-toggle-kpis')).toHaveAttribute('aria-checked', 'true')
    expect(screen.getByTestId('section-toggle-advanced_segmentation')).toHaveAttribute('aria-checked', 'false')
    // On, but this period has no budget — said once, not as a card.
    expect(screen.getByTestId('section-reason-budget_pacing')).toHaveTextContent('No data in this period')
    expect(screen.queryByTestId('section-reason-detailed_tables')).toBeNull()
    expect(screen.getByTestId('section-preview-kpis')).toHaveAttribute('data-visible', 'true')
    expect(screen.getByTestId('section-preview-budget_pacing')).toHaveAttribute('data-visible', 'false')
  })

  it('a switch saves that one section and redraws from the server’s answer', async () => {
    vi.mocked(api.updateReportSections).mockResolvedValue(state({
      effective: { ...state().effective, advanced_segmentation: true },
      resolved: [...state().resolved.slice(0, 3), row('advanced_segmentation', true, null, true)],
    }))
    renderWithProviders(<ReportSectionControls projectId="p1" reportId="r1" />, { locale: 'en' })

    fireEvent.click(await screen.findByTestId('section-toggle-advanced_segmentation'))

    await waitFor(() => expect(api.updateReportSections).toHaveBeenCalledWith('p1', 'r1', { sections: { advanced_segmentation: true } }))
    await waitFor(() => expect(screen.getByTestId('section-preview-advanced_segmentation')).toHaveAttribute('data-visible', 'true'))
  })

  it('a refusal from the server is shown and the switch keeps the saved state', async () => {
    vi.mocked(api.updateReportSections).mockRejectedValue(Object.assign(new Error('You do not have this permission on this project.'), {
      isAxiosError: true,
      response: { status: 403, data: { message: 'You do not have this permission on this project.' } },
    }))
    renderWithProviders(<ReportSectionControls projectId="p1" reportId="r1" />, { locale: 'en' })

    fireEvent.click(await screen.findByTestId('section-toggle-detailed_tables'))

    expect(await screen.findByTestId('section-controls-error')).toBeInTheDocument()
    expect(screen.getByTestId('section-toggle-detailed_tables')).toHaveAttribute('aria-checked', 'false')
  })

  it('a link’s switches are the same list, off-only, and say what the report already hides', async () => {
    vi.mocked(api.listShares).mockResolvedValue([{ id: 'sh1', active: true, mode: 'live', created_at: '2026-09-01T00:00:00Z' }] as never)
    const link = (budget: 'shown' | 'hidden_by_link'): api.ShareSectionsState => ({
      share_id: 'sh1',
      sections: [
        { key: 'kpis', title_ar: 'kpis', title_en: 'kpis', breakdown: false, state: 'shown' },
        { key: 'budget_pacing', title_ar: 'budget_pacing', title_en: 'budget_pacing', breakdown: false, state: budget },
        { key: 'detailed_tables', title_ar: 'detailed_tables', title_en: 'detailed_tables', breakdown: false, state: 'hidden_by_report' },
      ],
      resolved: [row('kpis', true, null)],
      visible: ['kpis'],
      availability_judged: false,
    })
    vi.mocked(api.getShareSections).mockResolvedValue(link('shown'))
    vi.mocked(api.updateShareSections).mockResolvedValue(link('hidden_by_link'))
    renderWithProviders(<ReportSectionControls projectId="p1" reportId="r1" />, { locale: 'en' })

    fireEvent.change(await screen.findByTestId('section-level'), { target: { value: 'sh1' } })

    expect(await screen.findByTestId('section-link-hidden-by-report-detailed_tables')).toHaveTextContent('Hidden by the report')
    expect(screen.queryByTestId('section-toggle-detailed_tables')).toBeNull()

    fireEvent.click(screen.getByTestId('section-toggle-budget_pacing'))
    await waitFor(() => expect(api.updateShareSections).toHaveBeenCalledWith('p1', 'r1', 'sh1', { budget_pacing: false }))
    await waitFor(() => expect(screen.getByTestId('section-toggle-budget_pacing')).toHaveAttribute('aria-checked', 'false'))
  })
})

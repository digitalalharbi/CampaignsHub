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

  return { ...actual, getReportSections: vi.fn(), updateReportSections: vi.fn(), updateTemplateSections: vi.fn(), listScopeTemplates: vi.fn() }
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
})

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { renderWithProviders } from '@/test/utils'
import { ReportSectionControls } from './ReportSectionControls'
import { LiveCampaignBreakdown } from './live/LiveCampaignBreakdown'
import * as api from './api'

/**
 * REPORT-AUTO-COMPOSE-001 and REPORT-GENERIC-LABELS-001 — the operator's two new controls. What each one MEANS is held by the
 * backend tests; these hold that the page asks the server, sends the choice, and says what it was told.
 */
vi.mock('./api', async () => {
  const actual = await vi.importActual<typeof api>('./api')

  return {
    ...actual,
    getReportSections: vi.fn(),
    updateReportSections: vi.fn(),
    listScopeTemplates: vi.fn(),
    scopeOptions: vi.fn(),
    listShares: vi.fn(),
    getShareSections: vi.fn(),
    updateShareSections: vi.fn(),
    getAutoCompose: vi.fn(),
    applyAutoCompose: vi.fn(),
    revertAutoCompose: vi.fn(),
  }
})

const row = (key: string, visible: boolean, reason: api.SectionReason | null): api.ResolvedSectionRow => ({
  key, title_ar: `ar-${key}`, title_en: `en-${key}`, breakdown: false, visible, reason, because: null,
})

const state = (overrides: Partial<api.ReportSectionsState> = {}): api.ReportSectionsState => ({
  report_id: 'r1',
  audience: 'client',
  chosen: {},
  streams: [],
  effective: { kpis: true, funnel: true, budget_pacing: true, detailed_tables: false },
  resolved: [row('kpis', true, null), row('funnel', true, null), row('budget_pacing', false, 'data_unavailable'), row('detailed_tables', false, 'disabled_by_operator')],
  visible: ['kpis', 'funnel'],
  availability_judged: true,
  generic_names: [],
  ...overrides,
})

const proposal = (overrides: Partial<api.AutoComposeProposal> = {}): api.AutoComposeProposal => ({
  level: 'report',
  share_id: null,
  objective: 'awareness',
  objective_source: 'report',
  availability_judged: true,
  sections: [
    { key: 'kpis', title_ar: 'المؤشرات', title_en: 'Key metrics', decision: 'show', reason: null },
    { key: 'funnel', title_ar: 'المسار', title_en: 'Funnel', decision: 'hide', reason: 'objective' },
    { key: 'budget_pacing', title_ar: 'الميزانية', title_en: 'Budget', decision: 'unavailable', reason: 'no_data' },
    { key: 'detailed_tables', title_ar: 'الجداول', title_en: 'Tables', decision: 'off', reason: 'turned_off' },
  ],
  metrics: ['impressions', 'reach'],
  metrics_left_out: [{ key: 'frequency', reason: 'not_reported' }, { key: 'cpm', reason: 'hidden_by_report' }],
  metrics_apply: true,
  applied: false,
  applied_at: null,
  ...overrides,
})

describe('automatic composition', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(api.getReportSections).mockResolvedValue(state())
    vi.mocked(api.listScopeTemplates).mockResolvedValue({ templates: [] })
    vi.mocked(api.listShares).mockResolvedValue([])
    vi.mocked(api.getAutoCompose).mockResolvedValue(proposal())
    vi.mocked(api.applyAutoCompose).mockResolvedValue(proposal({ applied: true, applied_at: '2026-11-04T10:00:00+03:00' }))
    vi.mocked(api.revertAutoCompose).mockResolvedValue(proposal())
  })

  it('asks for nothing and changes nothing until somebody opens it', async () => {
    renderWithProviders(<ReportSectionControls projectId="p1" reportId="r1" />, { locale: 'en' })

    expect(await screen.findByTestId('auto-compose-open')).toBeVisible()
    expect(api.getAutoCompose).not.toHaveBeenCalled()
    expect(api.applyAutoCompose).not.toHaveBeenCalled()
  })

  it('states the objective and why each section is in or out, before anything is saved', async () => {
    renderWithProviders(<ReportSectionControls projectId="p1" reportId="r1" />, { locale: 'en' })

    fireEvent.click(await screen.findByTestId('auto-compose-open'))

    expect(await screen.findByTestId('auto-compose-objective')).toHaveTextContent('Awareness')
    expect(screen.getByTestId('auto-compose-section-funnel')).toHaveTextContent('Not suited to this objective')
    expect(screen.getByTestId('auto-compose-section-budget_pacing')).toHaveTextContent('No data in this period')
    expect(screen.getByTestId('auto-compose-section-detailed_tables')).toHaveTextContent('Stays off')
    expect(screen.getByTestId('auto-compose-section-kpis')).toHaveTextContent('Shown')
    expect(api.applyAutoCompose).not.toHaveBeenCalled()
  })

  it('names the indicators it picked and the ones it left out, with no figure beside them', async () => {
    renderWithProviders(<ReportSectionControls projectId="p1" reportId="r1" />, { locale: 'en' })

    fireEvent.click(await screen.findByTestId('auto-compose-open'))

    const picked = await screen.findByTestId('auto-compose-metrics')
    expect(within(picked).getByText('Reach')).toBeVisible()
    const left = screen.getByTestId('auto-compose-left-out')
    expect(left).toHaveTextContent('Frequency')
    expect(left).toHaveTextContent('Not reported')
    expect(left).toHaveTextContent('Hidden on this report')
    expect(left.textContent).not.toMatch(/\b0\b/)
  })

  it('applies only on the explicit action and redraws the switches from the server', async () => {
    renderWithProviders(<ReportSectionControls projectId="p1" reportId="r1" />, { locale: 'en' })
    fireEvent.click(await screen.findByTestId('auto-compose-open'))
    fireEvent.click(await screen.findByTestId('auto-compose-apply'))

    await waitFor(() => expect(api.applyAutoCompose).toHaveBeenCalledWith('p1', 'r1', undefined))
    await waitFor(() => expect(api.getReportSections).toHaveBeenCalledTimes(2))
    expect(await screen.findByTestId('auto-compose-revert')).toBeVisible()
  })

  it('goes back on request', async () => {
    vi.mocked(api.getAutoCompose).mockResolvedValue(proposal({ applied: true }))
    renderWithProviders(<ReportSectionControls projectId="p1" reportId="r1" />, { locale: 'en' })
    fireEvent.click(await screen.findByTestId('auto-compose-open'))
    fireEvent.click(await screen.findByTestId('auto-compose-revert'))

    await waitFor(() => expect(api.revertAutoCompose).toHaveBeenCalledWith('p1', 'r1', undefined))
  })

  it('on a link it composes that link', async () => {
    vi.mocked(api.listShares).mockResolvedValue([{ id: 's1', active: true } as api.ShareRow])
    vi.mocked(api.getShareSections).mockResolvedValue({ share_id: 's1', sections: [], resolved: state().resolved, visible: [], availability_judged: true, generic_names: [], generic_names_of_report: [] })
    renderWithProviders(<ReportSectionControls projectId="p1" reportId="r1" />, { locale: 'en' })

    fireEvent.change(await screen.findByTestId('section-level'), { target: { value: 's1' } })
    fireEvent.click(await screen.findByTestId('auto-compose-open'))
    await waitFor(() => expect(api.getAutoCompose).toHaveBeenCalledWith('p1', 'r1', 's1'))
    fireEvent.click(await screen.findByTestId('auto-compose-apply'))

    await waitFor(() => expect(api.applyAutoCompose).toHaveBeenCalledWith('p1', 'r1', 's1'))
  })

  it('says when a live report has no figures to judge yet, and when indicators belong to its links', async () => {
    vi.mocked(api.getAutoCompose).mockResolvedValue(proposal({ availability_judged: false, metrics_apply: false }))
    renderWithProviders(<ReportSectionControls projectId="p1" reportId="r1" />, { locale: 'en' })
    fireEvent.click(await screen.findByTestId('auto-compose-open'))

    expect(await screen.findByTestId('auto-compose-not-judged')).toBeVisible()
    expect(screen.getByTestId('auto-compose-metrics-on-links')).toBeVisible()
  })

  it('is written in Arabic with Latin digits by default', async () => {
    renderWithProviders(<ReportSectionControls projectId="p1" reportId="r1" />, { locale: 'ar' })
    fireEvent.click(await screen.findByTestId('auto-compose-open'))

    expect(await screen.findByTestId('auto-compose-section-funnel')).toHaveTextContent('لا يناسب هذا الهدف')
    expect(screen.getByTestId('auto-compose-objective')).toHaveTextContent('الوعي')
    expect(screen.getByTestId('auto-compose-panel').textContent).not.toMatch(/[٠-٩]/)
  })
})

describe('generic names', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(api.getReportSections).mockResolvedValue(state())
    vi.mocked(api.listScopeTemplates).mockResolvedValue({ templates: [] })
    vi.mocked(api.listShares).mockResolvedValue([])
  })

  it('offers one independent switch per kind of name, all off by default', async () => {
    renderWithProviders(<ReportSectionControls projectId="p1" reportId="r1" />, { locale: 'en' })

    for (const kind of ['campaign', 'ad_set', 'account', 'content']) {
      expect(await screen.findByTestId(`generic-name-${kind}`)).toHaveAttribute('aria-checked', 'false')
    }
  })

  it('saves one switch without touching the others', async () => {
    vi.mocked(api.getReportSections).mockResolvedValue(state({ generic_names: ['account'] }))
    vi.mocked(api.updateReportSections).mockResolvedValue(state({ generic_names: ['campaign', 'account'] }))
    renderWithProviders(<ReportSectionControls projectId="p1" reportId="r1" />, { locale: 'en' })

    fireEvent.click(await screen.findByTestId('generic-name-campaign'))

    await waitFor(() => expect(api.updateReportSections).toHaveBeenCalledWith('p1', 'r1', { generic_names: ['campaign', 'account'] }))
  })

  it('on a link a name the report hides is locked, and the link cannot show it', async () => {
    vi.mocked(api.listShares).mockResolvedValue([{ id: 's1', active: true } as api.ShareRow])
    vi.mocked(api.getShareSections).mockResolvedValue({ share_id: 's1', sections: [], resolved: state().resolved, visible: [], availability_judged: true, generic_names: [], generic_names_of_report: ['content'] })
    vi.mocked(api.updateShareSections).mockResolvedValue({ share_id: 's1', sections: [], resolved: state().resolved, visible: [], availability_judged: true, generic_names: ['campaign'], generic_names_of_report: ['content'] })
    renderWithProviders(<ReportSectionControls projectId="p1" reportId="r1" />, { locale: 'en' })

    fireEvent.change(await screen.findByTestId('section-level'), { target: { value: 's1' } })

    expect(await screen.findByTestId('generic-name-locked-content')).toHaveTextContent('Hidden by the report')
    expect(screen.queryByTestId('generic-name-content')).toBeNull()

    fireEvent.click(screen.getByTestId('generic-name-campaign'))
    await waitFor(() => expect(api.updateShareSections).toHaveBeenCalledWith('p1', 'r1', 's1', {}, {}, undefined, ['campaign']))
  })
})

describe('a row under a generic label', () => {
  const row = (name: string): api.LiveCampaignRow => ({
    name, platform: 'meta', status: 'active', objective: 'sales', data_state: 'reported', spend: null, impressions: 1200, clicks: 40, native_leads: 3, landing_leads: null,
  } as api.LiveCampaignRow)

  it('stays in the comparison with its figures, and its tooltip says only the label', () => {
    const payload = { campaign_rows: [row('حملة 1'), row('حملة 2')], kpi_labels: {} } as unknown as api.LivePayload
    renderWithProviders(<LiveCampaignBreakdown payload={payload} currency="SAR" ar />, { locale: 'ar' })

    const names = screen.getAllByTestId('live-campaign-row').filter((el) => el.closest('table') !== null || el.offsetParent !== undefined)
    expect(names.map((el) => el.textContent)).toEqual(expect.arrayContaining(['حملة 1', 'حملة 2']))
    for (const el of names) expect(['حملة 1', 'حملة 2']).toContain(el.getAttribute('title'))
    expect(screen.getByTestId('live-campaign-breakdown')).toHaveTextContent('1.2K')
  })
})

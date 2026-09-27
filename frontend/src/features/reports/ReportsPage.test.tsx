import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { ReportsPage } from './ReportsPage'
import type { Option } from '@/components/forms'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'

// The report builder's type & audience selects must be fed by the taxonomy engine, not a hardcoded list.
const TAX: Record<string, Option[]> = {
  'report.type': [
    { value: 'executive', label_en: 'Executive', label_ar: 'تنفيذي' },
    { value: 'monthly', label_en: 'Monthly', label_ar: 'شهري' },
  ],
  'report.audience': [
    { value: 'client', label_en: 'Client', label_ar: 'العميل' },
    { value: 'internal', label_en: 'Internal', label_ar: 'داخلي' },
  ],
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
  return { ...actual, listReports: vi.fn(), createReport: vi.fn(), listReportSections: vi.fn() }
})

import { createReport, listReportSections, listReports } from './api'

describe('ReportsPage — engine-fed builder', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useProject.setState({ currentProjectId: 'p1' })
    vi.mocked(listReports).mockResolvedValue({
      reports: [],
      summary: { total: 0, completed: 0, processing: 0, failed: 0 },
    })
    signInWith(['reports.view', 'reports.create'])
  })
  afterEach(() => signOut())

  it('feeds the type & audience selects from the taxonomy engine (report.type / report.audience)', async () => {
    renderWithProviders(<ReportsPage />, { locale: 'en' })

    fireEvent.click(screen.getByText(/تقرير محفوظ|Saved report/))
    // Default keys come through the engine option labels — a value only present in the mocked hook.
    const typeSelect = await screen.findByRole('combobox', { name: /نوع التقرير|Report type/ })
    expect(typeSelect).toHaveTextContent('Executive')
    expect(screen.getByRole('combobox', { name: /هذا التقرير موجّه إلى|This report is for/ })).toHaveTextContent('Client')

    // The engine's second option is selectable (proves the list is the mocked engine set).
    fireEvent.click(typeSelect)
    fireEvent.mouseDown(await screen.findByText('Monthly'))
    await waitFor(() => expect(typeSelect).toHaveTextContent('Monthly'))
  })

  it('submits the engine option KEYS unchanged (no 422)', async () => {
    vi.mocked(createReport).mockResolvedValue({} as never)
    renderWithProviders(<ReportsPage />, { locale: 'en' })

    fireEvent.click(screen.getByText(/تقرير محفوظ|Saved report/))
    await screen.findByRole('combobox', { name: /نوع التقرير|Report type/ })
    fireEvent.click(screen.getByText(/إنشاء وتوليد|Create and generate/))

    await waitFor(() =>
      expect(createReport).toHaveBeenCalledWith(
        'p1',
        expect.objectContaining({ type: 'executive', audience: 'client' }),
      ),
    )
  })

  /**
   * REPORT-PRODUCT-MODEL-001 — the product is mode × form, and the builder asked only one of them.
   *
   * The backend has accepted `mode` since the model was written and defaults it to `snapshot`. The
   * builder never offered it, so every report an operator made was a snapshot and the live link —
   * the dashboard a client filters, which the product sells as its own mode — could not be reached
   * from the one screen that makes reports.
   */
  it('offers the report mode and sends the chosen one', async () => {
    vi.mocked(createReport).mockResolvedValue({} as never)
    renderWithProviders(<ReportsPage />, { locale: 'en' })

    fireEvent.click(screen.getByText(/تقرير محفوظ|Saved report/))
    await screen.findByRole('combobox', { name: /نوع التقرير|Report type/ })

    // Snapshot is pressed to begin with: it is what every report made before this chooser existed was.
    expect(screen.getByTestId('rb-mode-snapshot')).toHaveAttribute('aria-pressed', 'true')
    expect(screen.getByTestId('rb-mode-live')).toHaveAttribute('aria-pressed', 'false')

    fireEvent.click(screen.getByTestId('rb-mode-live'))
    expect(screen.getByTestId('rb-mode-live')).toHaveAttribute('aria-pressed', 'true')

    fireEvent.click(screen.getByText(/إنشاء وتوليد|Create and generate/))

    await waitFor(() =>
      expect(createReport).toHaveBeenCalledWith('p1', expect.objectContaining({ mode: 'live' })),
    )
  })

  /**
   * WHOSE report this is, chosen at creation — REPORT-CREATION-UX-001's last clause.
   *
   * A report about a client resolves at the client layer: the client's name leads and the client's
   * mark is drawn, with the agency underneath. That is right for most client reports and wrong for
   * an agency that wants its own mark on the document it issued, and nothing in this screen ever
   * wrote `config['branding']`, which every surface already reads.
   *
   * What travels is a LAYER, never an identity: a screen that could post a `logo_url` could put any
   * agency's mark on any client's report, so the server resolves what that layer actually holds.
   */
  it('offers whose identity the report carries and sends the chosen layer', async () => {
    vi.mocked(createReport).mockResolvedValue({} as never)
    renderWithProviders(<ReportsPage />, { locale: 'en' })

    fireEvent.click(screen.getByText(/تقرير محفوظ|Saved report/))
    await screen.findByRole('combobox', { name: /نوع التقرير|Report type/ })

    expect(screen.getByTestId('rb-identity-auto')).toHaveAttribute('aria-pressed', 'true')

    fireEvent.click(screen.getByTestId('rb-identity-agency'))
    expect(screen.getByTestId('rb-identity-agency')).toHaveAttribute('aria-pressed', 'true')

    fireEvent.click(screen.getByText(/إنشاء وتوليد|Create and generate/))

    await waitFor(() =>
      expect(createReport).toHaveBeenCalledWith('p1', expect.objectContaining({ branding_identity: 'agency' })),
    )
  })

  /**
   * «Automatic» sends NOTHING, which is the whole point of it being a third answer.
   *
   * Posting a default would freeze a decision on every report nobody made, and every report created
   * before this chooser existed would then mean something different from one created after.
   */
  it('sends no identity at all when the operator leaves it automatic', async () => {
    vi.mocked(createReport).mockResolvedValue({} as never)
    renderWithProviders(<ReportsPage />, { locale: 'en' })

    fireEvent.click(screen.getByText(/تقرير محفوظ|Saved report/))
    await screen.findByRole('combobox', { name: /نوع التقرير|Report type/ })
    fireEvent.click(screen.getByText(/إنشاء وتوليد|Create and generate/))

    await waitFor(() => expect(createReport).toHaveBeenCalled())
    expect(vi.mocked(createReport).mock.calls[0]![1]).not.toHaveProperty('branding_identity')
  })

  /** And the default still travels, so nothing an operator does not touch changes meaning. */
  it('sends snapshot when the operator chooses nothing', async () => {
    vi.mocked(createReport).mockResolvedValue({} as never)
    renderWithProviders(<ReportsPage />, { locale: 'en' })

    fireEvent.click(screen.getByText(/تقرير محفوظ|Saved report/))
    await screen.findByRole('combobox', { name: /نوع التقرير|Report type/ })
    fireEvent.click(screen.getByText(/إنشاء وتوليد|Create and generate/))

    await waitFor(() =>
      expect(createReport).toHaveBeenCalledWith('p1', expect.objectContaining({ mode: 'snapshot' })),
    )
  })

  /**
   * REPORT-CREATION-UX-001 — the builder says what it is about to make.
   *
   * The audience already decides which sections a report carries, and the builder chose one without
   * ever naming the consequence: an operator picked «client» and found out what that meant by
   * opening the finished report.
   */
  it('names the sections the chosen audience implies', async () => {
    vi.mocked(listReportSections).mockResolvedValue({
      sections: [
        { key: 'kpis', title_ar: 'المؤشرات', title_en: 'KPIs', default_client: true, default_internal: true },
        { key: 'detailed_tables', title_ar: 'جداول تفصيلية', title_en: 'Detailed tables', default_client: false, default_internal: true },
      ],
    } as never)
    renderWithProviders(<ReportsPage />, { locale: 'en' })

    fireEvent.click(screen.getByText(/تقرير محفوظ|Saved report/))

    const preview = await screen.findByTestId('rb-section-preview')
    // A client report carries the KPIs and not the internal-only tables.
    expect(preview).toHaveTextContent('KPIs')
    expect(preview).not.toHaveTextContent('Detailed tables')
    // And it does not pretend this is the last chance to decide.
    expect(preview).toHaveTextContent(/changed after/i)
  })

  /**
   * It fails QUIET. A creation screen that blocked on a description of ITSELF would be worse than
   * one that says nothing, so a registry that will not load leaves the builder as it was.
   */
  it('still builds a report when the section registry will not load', async () => {
    vi.mocked(listReportSections).mockRejectedValue(new Error('nope'))
    vi.mocked(createReport).mockResolvedValue({} as never)
    renderWithProviders(<ReportsPage />, { locale: 'en' })

    fireEvent.click(screen.getByText(/تقرير محفوظ|Saved report/))
    await screen.findByRole('combobox', { name: /نوع التقرير|Report type/ })
    expect(screen.queryByTestId('rb-section-preview')).not.toBeInTheDocument()

    fireEvent.click(screen.getByText(/إنشاء وتوليد|Create and generate/))
    await waitFor(() => expect(createReport).toHaveBeenCalled())
  })

  it('surfaces a server validation error in the builder ErrorSummary', async () => {
    vi.mocked(createReport).mockRejectedValue({
      response: { status: 422, data: { message: 'Validation failed', errors: { period_end: ['The end date is invalid.'] } } },
    })
    renderWithProviders(<ReportsPage />, { locale: 'en' })

    fireEvent.click(screen.getByText(/تقرير محفوظ|Saved report/))
    await screen.findByRole('combobox', { name: /نوع التقرير|Report type/ })
    fireEvent.click(screen.getByText(/إنشاء وتوليد|Create and generate/))

    const summary = await screen.findByTestId('error-summary')
    expect(summary).toHaveTextContent('The end date is invalid.')
  })

  /**
   * SCOPE-BUILDER-001 — the project can disappear WHILE the builder is open.
   *
   * `AgencyScopeSwitcher` clears `currentProjectId` whenever a client is selected and the chosen
   * project belongs to a different one — correct on its own, and it runs asynchronously, after the
   * clients and projects queries settle. So an operator can open the builder on a valid project and
   * have the project cleared underneath the dialog a moment later.
   *
   * The page went on rendering `<ReportBuilder projectId={currentProjectId!} />`, and the `!` was a
   * lie: the builder held a null. The gate met it as a three-minute Firefox timeout waiting for a
   * POST that was never going to come.
   *
   * What must happen instead is not «disable the button». A disabled control with no sentence is a
   * dead control; the dialog has to SAY the project went away and offer the way back.
   */
  it('says the project went away rather than offering a create button that cannot work', async () => {
    vi.mocked(createReport).mockResolvedValue({} as never)
    renderWithProviders(<ReportsPage />, { locale: 'en' })

    fireEvent.click(screen.getByText(/تقرير محفوظ|Saved report/))
    await screen.findByRole('combobox', { name: /نوع التقرير|Report type/ })

    // The scope switcher clears the project under the open dialog.
    useProject.setState({ currentProjectId: null })

    const notice = await screen.findByTestId('builder-no-project')
    expect(notice).toHaveTextContent(/project/i)

    // And the control that cannot work is GONE, not merely greyed out.
    expect(screen.queryByText(/إنشاء وتوليد|Create and generate/)).not.toBeInTheDocument()
  })

  /**
   * Fail-closed: with no project, nothing is posted — least of all to a guessed one.
   *
   * The dangerous repair here would be «fall back to the first project the operator can reach»,
   * which silently writes a client's report into another client's project. The builder refuses.
   */
  it('never posts a report when the project is gone', async () => {
    vi.mocked(createReport).mockResolvedValue({} as never)
    renderWithProviders(<ReportsPage />, { locale: 'en' })

    fireEvent.click(screen.getByText(/تقرير محفوظ|Saved report/))
    await screen.findByRole('combobox', { name: /نوع التقرير|Report type/ })

    useProject.setState({ currentProjectId: null })
    // Wait for the page to react before looking — the probe that reproduced this defect found the
    // button still mounted and still live one render later, which is exactly when a person clicks it.
    await waitFor(() => expect(screen.queryByTestId('reports-need-project')).toBeInTheDocument())

    const create = screen.queryByText(/إنشاء وتوليد|Create and generate/)
    if (create) fireEvent.click(create)

    /*
     * Settled, then asserted — NOT `waitFor(() => expect(…).not.toHaveBeenCalled())`.
     *
     * A negative assertion inside `waitFor` passes on its first attempt, before the mutation it is
     * supposed to catch has even been scheduled. Written that way this test passed against the
     * unfixed page, which posts `createReport(null, …)`. Giving the click a moment to land is the
     * difference between covering the claim and appearing to.
     */
    await new Promise((resolve) => setTimeout(resolve, 200))
    expect(createReport).not.toHaveBeenCalled()
  })
})

/**
 * ANALYTICS-PROVENANCE-001 — «Demo» beside the Reports title was a constant, not a fact.
 *
 * Each report already carries `is_demo`, so the page can simply read its own rows. These assert the
 * badge follows them — including the case the constant got wrong: a project of live reports.
 */
describe('ReportsPage — provenance', () => {
  const report = (id: string, isDemo: boolean) => ({
    id, name: `Report ${id}`, type: 'executive', form: 'detailed', mode: 'project',
    campaign_objective: null, version: 1, status: 'completed', audience: 'client',
    period: { from: '2026-07-01', to: '2026-07-31' }, currency: 'SAR', config: {},
    scope: null, is_demo: isDemo, generated_at: null, last_sent_at: null,
    created_at: '2026-08-01T00:00:00+00:00', error: null, exports: [],
  })

  const listing = (rows: ReturnType<typeof report>[]) => ({
    reports: rows,
    summary: { total: rows.length, completed: rows.length, processing: 0, failed: 0 },
  })

  beforeEach(() => {
    vi.clearAllMocks()
    useProject.setState({ currentProjectId: 'p1' })
    signInWith(['reports.view'])
  })
  afterEach(() => signOut())

  it('does not call a project of live reports «Demo»', async () => {
    vi.mocked(listReports).mockResolvedValue(
      listing([report('r1', false), report('r2', false)]) as never,
    )

    renderWithProviders(<ReportsPage />, { locale: 'en' })

    /*
      The list renders twice by design — cards for a phone, the table above `sm` — and jsdom applies
      no CSS, so both are in the document here. The provenance chip is what these tests are about;
      the name is only how they wait for the load.
    */
    await screen.findAllByText('Report r1')
    expect(screen.queryByText(/Demo|تجريبية/)).not.toBeInTheDocument()
  })

  it('still says «Demo» when every report is seeded', async () => {
    vi.mocked(listReports).mockResolvedValue(listing([report('r1', true)]) as never)

    renderWithProviders(<ReportsPage />, { locale: 'en' })

    /*
      The list renders twice by design — cards for a phone, the table above `sm` — and jsdom applies
      no CSS, so both are in the document here. The provenance chip is what these tests are about;
      the name is only how they wait for the load.
    */
    await screen.findAllByText('Report r1')
    expect(screen.getByText(/Demo|تجريبية/)).toBeInTheDocument()
  })

  it('names the mixed case instead of choosing a side', async () => {
    vi.mocked(listReports).mockResolvedValue(
      listing([report('r1', true), report('r2', false)]) as never,
    )

    renderWithProviders(<ReportsPage />, { locale: 'en' })

    /*
      The list renders twice by design — cards for a phone, the table above `sm` — and jsdom applies
      no CSS, so both are in the document here. The provenance chip is what these tests are about;
      the name is only how they wait for the load.
    */
    await screen.findAllByText('Report r1')
    expect(screen.getByText(/Mixed|مختلطة/)).toBeInTheDocument()
  })
})

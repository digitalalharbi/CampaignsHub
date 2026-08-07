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
  return { ...actual, listReports: vi.fn(), createReport: vi.fn() }
})

import { createReport, listReports } from './api'

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

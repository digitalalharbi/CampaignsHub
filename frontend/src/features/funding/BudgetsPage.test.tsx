import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { BudgetsPage } from './BudgetsPage'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

vi.mock('@/lib/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api/client')>()),
  getEnvelope: vi.fn(),
  getData: vi.fn(),
  postData: vi.fn(),
  ensureCsrfCookie: vi.fn().mockResolvedValue(undefined),
}))
vi.mock('@/features/clients/api', () => ({ listClients: vi.fn().mockResolvedValue({ data: [{ id: 'c1', name: 'Club' }], meta: {} }) }))
vi.mock('@/features/projects/api', () => ({ listProjects: vi.fn().mockResolvedValue([{ id: 'p1', client_workspace_id: 'c1', name: 'Launch' }]) }))

import { getData, getEnvelope, postData } from '@/lib/api/client'

/** BUDGET-BOARD-001 — one board: totals line, one dense row per project, a drawer, and edit / delete / undo as entries. */
const row = (over: Record<string, unknown> = {}) => ({
  project_id: 'p1', client_workspace_id: 'c1', project_name: 'Launch', client_name: 'Club', payment_method: 'prepaid',
  counts_from: '2026-10-01', period_start: '2026-10-01', period_end: null, period_start_stated: false, voided_at: null, void_reason: null,
  alert_floor: 2000, currency: 'SAR', approved: 41_250, payments: 4, spent: 30_711, remaining: 10_539,
  used_ratio: 0.7445, pace_per_day: 1_200, days_left: 8, spend_without_rate: 0, complete: true, last_spend_day: '2026-11-12',
  by_platform: [{ provider: 'snapchat', spent: 20_000, spent_usd: 5_333 }, { provider: 'tiktok', spent: 10_711, spent_usd: 2_856 }], status: 'ok',
  payment_mode: 'direct', to_date: null, this_month: null, usd_rate: 3.75, ceiling: null, ceiling_ratio: null, to_invoice: null, planned: false, platform_plan: null, plan: null, plan_alerts: 0, ...over,
})

const entry = (over: Record<string, unknown> = {}) => ({
  id: 'x', kind: 'top_up', amount: 20_000, currency: 'SAR', amount_sar: 20_000, paid_on: '2026-11-01', reference: 'TRX-9', note: null,
  reason: null, reverses_id: null, reversed: false, can_undo: false, remaining_before: 1, remaining_after: 2, added_by: 'Finance', added_at: '2026-11-01T08:00:00Z', ...over,
})

function serve(rows: unknown[], canManage = true, history: unknown[] = [entry({ can_undo: true })], deletedCount = 0) {
  vi.mocked(getEnvelope).mockResolvedValue({ data: rows, meta: { totals: { approved: 43_250, spent: 32_711, remaining: 10_539, needs_attention: 1, currency: 'SAR' }, can_manage: canManage, deleted_count: deletedCount } } as never)
  vi.mocked(getData).mockImplementation(async (url: string) => {
    if (url.includes('/months/')) {
      return { month: '2026-11', from: '2026-11-01', to: '2026-11-30', remaining_start: 15_000, added: 0, spent: 4_461, remaining_end: 10_539,
        approved_total: 41_250, by_platform: [], movements: [], spend_without_rate: 0, fixed: false, fixed_at: null } as never
    }

    return { ...row(), history, undo_entry_id: 'x',
      series: [{ date: '2026-11-01', approved: 41_250, spent: 26_250, remaining: 15_000, added: 20_000 }, { date: '2026-11-02', approved: 41_250, spent: 30_711, remaining: 10_539, added: 0 }],
      months: ['2026-11', '2026-10'], invoice_months: [] } as never
  })
}

const openDrawer = async () => {
  fireEvent.click(await screen.findByTestId('budget-row-p1'))
  return screen.findByTestId('budget-drawer')
}

describe('the budgets board', () => {
  beforeEach(() => { vi.clearAllMocks(); signInWith(['funding.manage']) })
  afterEach(() => signOut())

  it('shows the totals line and one row per project, in the order the server sent', async () => {
    serve([row({ project_id: 'p2', client_name: 'Beta', status: 'low', remaining: 900 }), row()])
    renderWithProviders(<BudgetsPage />, { locale: 'ar' })

    await waitFor(() => expect(screen.getByTestId('tile-approved')).toHaveTextContent('43,250 ر.س'))
    expect(screen.getByTestId('tile-remaining')).toHaveTextContent('10,539 ر.س')
    expect(screen.getByTestId('tile-alerts')).toHaveTextContent('1')
    const items = within(screen.getByTestId('budgets-list')).getAllByRole('button')
    expect(items[0]).toHaveTextContent('Beta')
    expect(items[0]).toHaveTextContent('قرّبت تخلص')
    expect(items[1]).toHaveTextContent('74%')
  })

  it('a row carries its period, its percent and a tooltip for every platform of its split', async () => {
    serve([row({ period_start: '2026-11-06', period_start_stated: true, period_end: '2026-12-05' })])
    renderWithProviders(<BudgetsPage />, { locale: 'ar' })

    const item = await screen.findByTestId('budget-row-p1')
    expect(within(item).getByTestId('period-chip')).toHaveAttribute('title', '2026-11-06 → 2026-12-05')
    expect(within(item).getByTestId('used-percent')).toHaveTextContent('74%')
    expect(within(item).getByTestId('split-snapchat').getAttribute('title')).toMatch(/5,333 \$/)
    expect(within(item).getByTestId('split-tiktok').getAttribute('title')).toMatch(/2,856 \$/)
  })

  it('an open period reads as open, and a platform without a rate says so in its tooltip', async () => {
    serve([row({ by_platform: [{ provider: 'snapchat', spent: 0, spent_usd: 0, unconverted_days: 2, unconverted: [{ currency: 'EUR', amount: 100 }] }] })])
    renderWithProviders(<BudgetsPage />, { locale: 'ar' })

    const item = await screen.findByTestId('budget-row-p1')
    expect(within(item).getByTestId('period-chip')).toHaveTextContent('∞')
    expect(within(item).getByTestId('split-snapchat').getAttribute('title')).toMatch(/100 € · بدون سعر صرف/)
  })

  it('a row opens the drawer with payments, platforms and the month — the page does not move', async () => {
    serve([row()])
    renderWithProviders(<BudgetsPage />, { locale: 'ar' })

    const drawer = await openDrawer()
    expect(await within(drawer).findByTestId('budget-remaining')).toHaveTextContent('10,539 ر.س')
    expect(within(drawer).getByTestId('budget-payments')).toHaveTextContent('TRX-9')
    expect(within(drawer).getByTestId('budget-platforms')).toHaveTextContent('5,333 $')
    await waitFor(() => expect(within(drawer).getByTestId('budget-month')).toHaveTextContent('مباشر'))
    fireEvent.keyDown(document, { key: 'Escape' })
    await waitFor(() => expect(screen.queryByTestId('budget-drawer')).toBeNull())
    expect(screen.getByTestId('budgets-list')).toBeInTheDocument()
  })

  it('a negative remaining reads as over, never as money left', async () => {
    serve([row({ remaining: -1_200, status: 'over', used_ratio: 1.03 })])
    renderWithProviders(<BudgetsPage />, { locale: 'ar' })

    const item = await screen.findByTestId('budget-row-p1')
    expect(item).toHaveTextContent('−1,200 ر.س')
    expect(item).toHaveTextContent('تجاوزت الميزانية')
  })

  it('adds a budget in one step; the toast offers «تراجع» and it undoes exactly that entry', async () => {
    serve([row()])
    vi.mocked(postData).mockImplementation(async (url: string) => (url.endsWith('/undo')
      ? { ...row(), entry_id: 'rev' }
      : { ...row(), payment_id: 'pay', created: true }) as never)
    renderWithProviders(<BudgetsPage />, { locale: 'ar' })

    fireEvent.click(await screen.findByTestId('budgets-add'))
    fireEvent.change(screen.getByLabelText(/العميل/), { target: { value: 'Club' } })
    fireEvent.change(screen.getByLabelText(/المشروع/), { target: { value: 'Launch' } })
    fireEvent.click(screen.getByRole('button', { name: '20,000' }))
    const submit = screen.getByTestId('budget-submit')
    expect(submit).toHaveTextContent('أضف 20,000 ر.س')
    fireEvent.click(submit)

    await waitFor(() => expect(postData).toHaveBeenCalledTimes(1))
    const [url, body] = vi.mocked(postData).mock.calls[0] as [string, Record<string, unknown>]
    expect(url).toBe('/funding/payments')
    expect(body).toMatchObject({ client_name: 'Club', project_name: 'Launch', amount: 20_000, period_start: null, period_end: null })
    expect(typeof body.idempotency_key).toBe('string')

    const toast = await screen.findByTestId('undo-toast')
    expect(toast).toHaveTextContent('أُضيفت الميزانية')
    fireEvent.click(within(toast).getByTestId('undo-toast-undo'))
    await waitFor(() => expect(postData).toHaveBeenCalledTimes(2))
    expect(vi.mocked(postData).mock.calls[1]).toEqual(['/funding/projects/p1/undo', { entry_id: 'pay' }])
    await waitFor(() => expect(screen.getByTestId('undo-toast')).toHaveTextContent('تم التراجع'))
    expect(screen.queryByTestId('undo-toast-undo')).toBeNull()
  })

  it('edit sends only what changed, and the toast can undo it', async () => {
    serve([row()])
    vi.mocked(postData).mockResolvedValue({ ...row(), entry_id: 'adj' } as never)
    renderWithProviders(<BudgetsPage />, { locale: 'ar' })

    const drawer = await openDrawer()
    fireEvent.click(await within(drawer).findByTestId('budget-edit'))
    const submit = screen.getByTestId('budget-edit-submit')
    expect(submit).toBeDisabled()
    fireEvent.change(screen.getByLabelText(/الميزانية/), { target: { value: '50000' } })
    expect(submit).toBeEnabled()
    fireEvent.click(submit)

    await waitFor(() => expect(postData).toHaveBeenCalledTimes(1))
    expect(vi.mocked(postData).mock.calls[0]).toEqual(['/funding/projects/p1/adjust', { amount: 50_000 }])
    expect(await screen.findByTestId('undo-toast')).toHaveTextContent('تم التعديل')
  })

  it('delete needs a reason, then hides the budget and offers undo', async () => {
    serve([row()])
    vi.mocked(postData).mockResolvedValue({ ...row(), entry_id: 'void1' } as never)
    renderWithProviders(<BudgetsPage />, { locale: 'ar' })

    const drawer = await openDrawer()
    fireEvent.click(await within(drawer).findByTestId('budget-delete'))
    const submit = screen.getByTestId('budget-delete-submit')
    expect(submit).toBeDisabled()
    fireEvent.change(screen.getByLabelText(/السبب/), { target: { value: 'أُضيفت بالخطأ' } })
    expect(submit).toBeEnabled()
    fireEvent.click(submit)

    await waitFor(() => expect(postData).toHaveBeenCalledTimes(1))
    expect(vi.mocked(postData).mock.calls[0]).toEqual(['/funding/projects/p1/void', { reason: 'أُضيفت بالخطأ' }])
    expect(await screen.findByTestId('undo-toast')).toHaveTextContent('حُذفت الميزانية')
    await waitFor(() => expect(screen.queryByTestId('budget-drawer')).toBeNull())
  })

  it('the history offers «تراجع» on the latest entry only, and an undone entry is marked', async () => {
    serve([row()], true, [
      entry({ id: 'rev', kind: 'reversal', amount: -500, amount_sar: -500, reverses_id: 'old', can_undo: false }),
      entry({ id: 'new', kind: 'adjustment', amount: 2_000, amount_sar: 2_000, can_undo: true }),
      entry({ id: 'old', kind: 'adjustment', amount: 500, amount_sar: 500, reversed: true }),
      entry({ id: 'first', kind: 'initial' }),
    ])
    vi.mocked(postData).mockResolvedValue({ ...row(), entry_id: 'r2' } as never)
    renderWithProviders(<BudgetsPage />, { locale: 'ar' })

    const drawer = await openDrawer()
    const buttons = await within(drawer).findAllByTestId('history-undo')
    expect(buttons).toHaveLength(1)
    fireEvent.click(buttons[0])
    await waitFor(() => expect(postData).toHaveBeenCalledWith('/funding/projects/p1/undo', { entry_id: 'new' }))
  })

  it('a reader without the finance permission sees the figures but no add, edit, renew, delete or undo', async () => {
    serve([row()], false)
    renderWithProviders(<BudgetsPage />, { locale: 'ar' })

    const drawer = await openDrawer()
    await within(drawer).findByTestId('budget-remaining')
    expect(screen.queryByTestId('budgets-add')).toBeNull()
    for (const id of ['budget-renew', 'budget-edit', 'budget-delete', 'history-undo']) expect(screen.queryByTestId(id)).toBeNull()
  })

  it('deleted budgets are one click away and read from the deleted view', async () => {
    serve([row({ status: 'void', voided_at: '2026-11-13T10:00:00Z', void_reason: 'خطأ' })], true, [entry()], 1)
    renderWithProviders(<BudgetsPage />, { locale: 'ar' })

    fireEvent.click(await screen.findByTestId('budgets-deleted-toggle'))
    await waitFor(() => expect(getEnvelope).toHaveBeenCalledWith('/funding?deleted=1'))
    expect(await screen.findByTestId('budget-row-p1')).toHaveTextContent('محذوفة')
    expect(screen.queryByTestId('budgets-add')).toBeNull()
  })

  it('PDF sits in the export menu next to XLSX and CSV, with and without the history', async () => {
    serve([row()])
    renderWithProviders(<BudgetsPage />, { locale: 'ar' })

    await screen.findByTestId('budgets-export')
    expect(screen.getByTestId('budgets-export-pdf')).toHaveTextContent('PDF')
    expect(screen.getByTestId('budgets-export-pdf-history')).toHaveTextContent('مع السجل')
    expect(screen.getByTestId('budgets-export-xlsx')).toBeInTheDocument()
    expect(screen.getByTestId('budgets-export-csv')).toBeInTheDocument()
  })

  it('a reader the server refuses sees that, not an empty list', async () => {
    vi.mocked(getEnvelope).mockRejectedValue(Object.assign(new Error('Forbidden'), { isAxiosError: true, response: { status: 403, data: { message: 'Forbidden' } } }))
    renderWithProviders(<BudgetsPage />, { locale: 'ar' })

    await waitFor(() => expect(screen.queryByTestId('budgets-list')).toBeNull())
    expect(screen.queryByText('ما فيه ميزانيات بعد')).toBeNull()
  })
})

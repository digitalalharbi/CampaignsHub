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

/** FIN-LOOP-001 — the budgets page: four figures, projects by need, the chosen one beside them, one-step add. */
const row = (over: Record<string, unknown> = {}) => ({
  project_id: 'p1', client_workspace_id: 'c1', project_name: 'Launch', client_name: 'Club', payment_method: 'prepaid',
  counts_from: '2026-10-01', alert_floor: 2000, currency: 'SAR', approved: 41_250, payments: 4, spent: 30_711, remaining: 10_539,
  used_ratio: 0.7445, pace_per_day: 1_200, days_left: 8, spend_without_rate: 0, complete: true, last_spend_day: '2026-11-12',
  by_platform: [{ provider: 'snapchat', spent: 20_000 }, { provider: 'tiktok', spent: 10_711 }], status: 'ok', ...over,
})

function serve(rows: unknown[], canManage = true) {
  vi.mocked(getEnvelope).mockResolvedValue({ data: rows, meta: { totals: { approved: 43_250, spent: 32_711, remaining: 10_539, needs_attention: 1, currency: 'SAR' }, can_manage: canManage } } as never)
  vi.mocked(getData).mockImplementation(async (url: string) => {
    if (url.includes('/months/')) {
      return { month: '2026-11', from: '2026-11-01', to: '2026-11-30', remaining_start: 15_000, added: 0, spent: 4_461, remaining_end: 10_539,
        approved_total: 41_250, by_platform: [], movements: [], spend_without_rate: 0, fixed: false, fixed_at: null } as never
    }

    return { ...row(), history: [{ id: 'x', kind: 'top_up', amount: 20_000, currency: 'SAR', amount_sar: 20_000, paid_on: '2026-11-01', reference: 'TRX-9',
      note: null, remaining_before: 1, remaining_after: 2, added_by: 'Finance', added_at: '2026-11-01T08:00:00Z' }],
    series: [{ date: '2026-11-01', approved: 41_250, spent: 26_250, remaining: 15_000, added: 20_000 }, { date: '2026-11-02', approved: 41_250, spent: 30_711, remaining: 10_539, added: 0 }],
    months: ['2026-11', '2026-10'] } as never
  })
}

describe('the budgets page', () => {
  beforeEach(() => { vi.clearAllMocks(); signInWith(['funding.manage']) })
  afterEach(() => signOut())

  it('shows the four figures and the projects ordered as the server sent them', async () => {
    serve([row({ project_id: 'p2', client_name: 'Beta', status: 'low', remaining: 900 }), row()])
    renderWithProviders(<BudgetsPage />, { locale: 'ar' })

    await waitFor(() => expect(screen.getByTestId('tile-approved')).toHaveTextContent('43,250 ر.س'))
    expect(screen.getByTestId('tile-remaining')).toHaveTextContent('10,539 ر.س')
    expect(screen.getByTestId('tile-alerts')).toHaveTextContent('1')
    const list = screen.getByTestId('budgets-list')
    const items = within(list).getAllByRole('button')
    expect(items[0]).toHaveTextContent('Beta')
    expect(items[0]).toHaveTextContent('قرّبت تخلص')
    expect(items[1]).toHaveTextContent('يكفي تقريبًا 8 يوم')
  })

  it('opens the chosen project beside the list with its payments, platforms and month', async () => {
    serve([row()])
    renderWithProviders(<BudgetsPage />, { locale: 'ar' })

    expect(await screen.findByTestId('budget-remaining')).toHaveTextContent('10,539 ر.س')
    expect(screen.getByTestId('budget-payments')).toHaveTextContent('TRX-9')
    expect(screen.getByTestId('budget-platforms')).toHaveTextContent('20,000')
    await waitFor(() => expect(screen.getByTestId('budget-month')).toHaveTextContent('مباشر'))
  })

  it('a negative remaining reads as over, never as money left', async () => {
    serve([row({ remaining: -1_200, status: 'over', used_ratio: 1.03 })])
    renderWithProviders(<BudgetsPage />, { locale: 'ar' })

    const item = await screen.findByTestId('budget-row-p1')
    expect(item).toHaveTextContent('−1,200 ر.س')
    expect(item).toHaveTextContent('تجاوزت الميزانية')
  })

  it('adds a budget in one step and the button says what it will add', async () => {
    serve([row()])
    vi.mocked(postData).mockResolvedValue({ ...row(), payment_id: 'pay', created: true } as never)
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
    expect(body).toMatchObject({ client_name: 'Club', project_name: 'Launch', amount: 20_000 })
    expect(typeof body.idempotency_key).toBe('string')
  })

  it('a reader without the finance permission sees the figures but no add or renew', async () => {
    serve([row()], false)
    renderWithProviders(<BudgetsPage />, { locale: 'ar' })

    await screen.findByTestId('budget-remaining')
    expect(screen.queryByTestId('budgets-add')).toBeNull()
    expect(screen.queryByTestId('budget-renew')).toBeNull()
  })

  it('a reader the server refuses sees that, not an empty list', async () => {
    vi.mocked(getEnvelope).mockRejectedValue(Object.assign(new Error('Forbidden'), { isAxiosError: true, response: { status: 403, data: { message: 'Forbidden' } } }))
    renderWithProviders(<BudgetsPage />, { locale: 'ar' })

    await waitFor(() => expect(screen.queryByTestId('budgets-list')).toBeNull())
    expect(screen.queryByText('ما فيه ميزانيات بعد')).toBeNull()
  })
})

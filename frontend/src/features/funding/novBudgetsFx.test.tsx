import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { BudgetsPage } from './BudgetsPage'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

vi.mock('@/lib/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api/client')>()),
  getEnvelope: vi.fn(),
  getData: vi.fn(),
  postData: vi.fn(),
  putData: vi.fn(),
  ensureCsrfCookie: vi.fn().mockResolvedValue(undefined),
}))
vi.mock('@/features/clients/api', () => ({ listClients: vi.fn().mockResolvedValue({ data: [], meta: {} }) }))
vi.mock('@/features/projects/api', () => ({ listProjects: vi.fn().mockResolvedValue([]) }))

import { getData, getEnvelope, putData } from '@/lib/api/client'

/** Nov overhaul, task 6 — the default dollar rate is visible and editable, and the panel never covers «أضف ميزانية». */
const row = {
  project_id: 'p1', client_workspace_id: 'c1', project_name: 'Launch', client_name: 'Club', payment_method: 'prepaid', payment_mode: 'direct',
  counts_from: '2026-10-01', period_start: '2026-10-01', period_end: null, period_start_stated: false, voided_at: null, void_reason: null,
  alert_floor: 2000, currency: 'SAR', approved: 1000, payments: 1, spent: 0, remaining: 1000, used_ratio: 0, pace_per_day: null, days_left: null,
  spend_without_rate: 0, complete: true, last_spend_day: null, by_platform: [], status: 'ok', to_date: null, this_month: null, usd_rate: 3.75,
  ceiling: null, ceiling_ratio: null, to_invoice: null, planned: false, platform_plan: null, plan: null, plan_alerts: 0,
}

function serve(rate = { usd_sar: 3.75, custom: false, peg: 3.75, can_manage: true }) {
  vi.mocked(getEnvelope).mockResolvedValue({ data: [row], meta: { totals: { approved: 1000, spent: 0, remaining: 1000, needs_attention: 0, currency: 'SAR' }, can_manage: true, deleted_count: 0 } } as never)
  vi.mocked(getData).mockImplementation(async (url: string) => {
    if (url === '/funding/rate') return rate as never
    if (url.includes('/months/')) return { month: '2026-11', from: '2026-11-01', to: '2026-11-30', remaining_start: 0, added: 0, spent: 0, remaining_end: 0, approved_total: 1000, by_platform: [], movements: [], spend_without_rate: 0, fixed: false, fixed_at: null } as never

    return { ...row, history: [], undo_entry_id: null, series: [], months: [], invoice_months: [] } as never
  })
}

describe('budgets: default dollar rate', () => {
  beforeEach(() => { vi.clearAllMocks(); signInWith(['funding.manage']) })
  afterEach(() => signOut())

  it('shows the default rate on the page and lets finance change it', async () => {
    serve()
    vi.mocked(putData).mockResolvedValue({ usd_sar: 3.8, custom: true, peg: 3.75, can_manage: true } as never)
    renderWithProviders(<BudgetsPage />, { locale: 'ar' })

    const bar = await screen.findByTestId('fx-rate-bar')
    expect(within(bar).getByTestId('fx-rate-value')).toHaveTextContent('3.75 ر.س')
    expect(within(bar).getByTestId('fx-rate-default')).toHaveTextContent('سعر افتراضي')

    fireEvent.click(within(bar).getByTestId('fx-rate-edit'))
    fireEvent.change(within(bar).getByTestId('fx-rate-input'), { target: { value: '3.8' } })
    fireEvent.click(within(bar).getByTestId('fx-rate-save'))

    await waitFor(() => expect(putData).toHaveBeenCalledWith('/funding/rate', { usd_sar: 3.8 }))
  })

  it('refuses a rate outside 1 to 10 without calling the server', async () => {
    serve()
    renderWithProviders(<BudgetsPage />, { locale: 'ar' })

    const bar = await screen.findByTestId('fx-rate-bar')
    fireEvent.click(within(bar).getByTestId('fx-rate-edit'))
    fireEvent.change(within(bar).getByTestId('fx-rate-input'), { target: { value: '0' } })
    fireEvent.click(within(bar).getByTestId('fx-rate-save'))

    expect(await within(bar).findByRole('status')).toHaveTextContent('أدخل رقمًا بين 1 و10')
    expect(putData).not.toHaveBeenCalled()
  })

  it('a viewer sees the rate but has no edit button', async () => {
    serve({ usd_sar: 3.75, custom: false, peg: 3.75, can_manage: false })
    renderWithProviders(<BudgetsPage />, { locale: 'ar' })

    const bar = await screen.findByTestId('fx-rate-bar')
    expect(within(bar).queryByTestId('fx-rate-edit')).toBeNull()
  })

  it('the side panel starts below the page header, so «أضف ميزانية» is never covered', async () => {
    serve()
    renderWithProviders(<BudgetsPage />, { locale: 'ar' })
    const header = (await screen.findByTestId('budgets-intro')).closest('[data-slideover-avoid]') as HTMLElement
    header.getBoundingClientRect = () => ({ bottom: 80, top: 0, left: 0, right: 0, width: 0, height: 80, x: 0, y: 0, toJSON: () => ({}) })

    fireEvent.click(await screen.findByTestId('budget-row-p1'))
    const panel = await screen.findByTestId('budget-drawer')
    const overlay = panel.closest('div.fixed') as HTMLElement
    expect(overlay.style.top).toBe('88px')
    expect(overlay.contains(screen.getByTestId('budgets-add'))).toBe(false)
  })
})

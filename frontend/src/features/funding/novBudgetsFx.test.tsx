import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, within } from '@testing-library/react'
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

/** Nov overhaul, task 6 as superseded by FX-ENGINE-001 — the dollar rate is visible, READ-ONLY (platform-managed), and the panel never covers «أضف ميزانية». */
const row = {
  project_id: 'p1', client_workspace_id: 'c1', project_name: 'Launch', client_name: 'Club', payment_method: 'prepaid', payment_mode: 'direct',
  counts_from: '2026-10-01', period_start: '2026-10-01', period_end: null, period_start_stated: false, voided_at: null, void_reason: null,
  alert_floor: 2000, currency: 'SAR', approved: 1000, payments: 1, spent: 0, remaining: 1000, used_ratio: 0, pace_per_day: null, days_left: null,
  spend_without_rate: 0, complete: true, last_spend_day: null, by_platform: [], status: 'ok', to_date: null, this_month: null, usd_rate: 3.75,
  ceiling: null, ceiling_ratio: null, to_invoice: null, planned: false, platform_plan: null, plan: null, plan_alerts: 0,
}

function serve(rate: Record<string, unknown> = { usd_sar: 3.75, custom: false, peg: 3.75, can_manage: false, managed_by: 'platform', rates_page: '/admin/settings/currency-rates', kind: 'pegged', source: 'official-peg:SAMA', rate_date: '1986-06-01', stale: false, withheld: false }) {
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

  it('shows the platform rate read-only, with where it comes from — even to finance', async () => {
    serve()
    renderWithProviders(<BudgetsPage />, { locale: 'ar' })

    const bar = await screen.findByTestId('fx-rate-bar')
    expect(within(bar).getByTestId('fx-rate-value')).toHaveTextContent('3.75 ر.س')
    expect(within(bar).getByTestId('fx-rate-managed')).toHaveTextContent('من إعدادات المنصة')
    expect(within(bar).queryByRole('button')).toBeNull()
    expect(within(bar).queryByRole('textbox')).toBeNull()
    expect(putData).not.toHaveBeenCalled()
  })

  it('a stale or missing platform rate is said, never filled in', async () => {
    serve({ usd_sar: null, custom: false, peg: 3.75, can_manage: false, managed_by: 'platform', rates_page: '/admin/settings/currency-rates', kind: null, source: null, rate_date: null, stale: false, withheld: true })
    renderWithProviders(<BudgetsPage />, { locale: 'ar' })

    const bar = await screen.findByTestId('fx-rate-bar')
    expect(within(bar).getByTestId('fx-rate-value')).toHaveTextContent('بانتظار سعر صرف')
    expect(within(bar).getByTestId('fx-rate-value').textContent).not.toMatch(/\d/)
  })

  it('BUDGET-POPUP-001: a budget opens as a window in the middle, closable by ✕', async () => {
    serve()
    renderWithProviders(<BudgetsPage />, { locale: 'ar' })

    fireEvent.click(await screen.findByTestId('budget-row-p1'))
    const overlay = await screen.findByTestId('budget-drawer')
    expect(overlay.className).toContain('md:items-center')

    fireEvent.click(within(overlay).getByTestId('slideover-close'))
    expect(screen.queryByTestId('budget-drawer')).toBeNull()
  })
})

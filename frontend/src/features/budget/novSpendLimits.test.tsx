import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { SpendLimitsPage } from './SpendLimitsPage'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'

vi.mock('@/lib/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api/client')>()),
  getEnvelope: vi.fn(),
  getData: vi.fn(),
  ensureCsrfCookie: vi.fn().mockResolvedValue(undefined),
  postData: vi.fn(),
  patchData: vi.fn(),
  deleteData: vi.fn(),
}))

import { getData, getEnvelope, patchData } from '@/lib/api/client'

/** Nov overhaul, task 6 — edit in place, project currency default, run-out date, converted note, stale marker, renew-all preview. */
const reading = (over: Record<string, unknown> = {}) => ({
  id: 'l1', scope: 'project', scope_id: null, enforcement: 'internal_monitoring', amount: 10_000, currency: 'SAR',
  period: { from: '2026-11-01', to: '2026-11-30', days: 30 }, elapsed_days: 15, consumed: 4_500, consumed_currency: 'SAR', remaining: 5_500,
  utilisation: 0.45, pace: 0.93, projected_period_spend: 9_300, projected_exhaustion: { date: null, reason: 'not_within_period' },
  thresholds: [75, 100], state: 'ok', basis: 'comparable', converted: null, run_out: { date: null, reason: 'no_spend_rate', per_day: null },
  freshness: { stale: false, items: [] }, ...over,
})

function route(limits: unknown[], projectCurrency = 'SAR') {
  vi.mocked(getEnvelope).mockResolvedValue({ data: limits, meta: { enforcement: 'internal_monitoring', enforcement_note_en: 'note', enforcement_note_ar: 'ملاحظة', today: '2026-11-15', project_currency: projectCurrency } } as never)
  vi.mocked(getData).mockImplementation(async (url: string) => {
    if (url === '/funding/rate') return { usd_sar: 3.75, custom: false, peg: 3.75, can_manage: true } as never
    if (url === '/funding/renew-next-month') {
      return { month: '2026-12', from: '2026-12-01', to: '2026-12-31',
        budgets: [{ project_id: 'p1', project_name: 'Launch', client_name: 'Club', amount: 5000, currency: 'SAR', mode: 'direct', already_renewed: false }],
        limits: [{ id: 'l1', project_id: 'p1', scope: 'project', scope_id: null, amount: 10_000, currency: 'SAR', thresholds: [75, 100], starts_on: '2026-12-01', ends_on: '2026-12-31', already_renewed: false }] } as never
    }
    throw new Error(`unexpected ${url}`)
  })
}

describe('spend limits — November additions', () => {
  beforeEach(() => { vi.clearAllMocks(); useProject.setState({ currentProjectId: 'p1' }); signInWith(['campaigns.view', 'campaigns.budget.change', 'funding.manage']) })
  afterEach(() => signOut())

  it('a limit has an «تعديل» button that saves amount, period and alerts in place', async () => {
    route([reading()])
    vi.mocked(patchData).mockResolvedValue(reading({ amount: 12_000 }) as never)
    renderWithProviders(<SpendLimitsPage />, { locale: 'ar' })

    fireEvent.click(await screen.findByTestId('spend-limit-l1-edit'))
    const form = await screen.findByTestId('edit-limit-form')
    fireEvent.change(within(form).getByTestId('edit-limit-amount'), { target: { value: '12000' } })
    fireEvent.click(within(form).getByTestId('edit-limit-threshold-90'))
    fireEvent.click(screen.getByTestId('edit-limit-save'))

    await waitFor(() => expect(patchData).toHaveBeenCalledWith('/projects/p1/spend-limits/l1', { amount: 12_000, starts_on: '2026-11-01', ends_on: '2026-11-30', thresholds: [75, 90] }))
  })

  it('«حد جديد» starts in the project currency, not the first limit’s', async () => {
    route([reading({ currency: 'USD' })], 'SAR')
    renderWithProviders(<SpendLimitsPage />, { locale: 'ar' })

    fireEvent.click(await screen.findByTestId('spend-limit-new'))
    expect(await screen.findByTestId('spend-limit-currency')).toHaveValue('SAR')
  })

  it('shows the run-out date, the conversion and the stale-data marker with the platform and the plain reason', async () => {
    route([reading({
      currency: 'USD', converted: { from: 'SAR', to: 'USD', rate: 0.266667, source: 'workspace_default' },
      run_out: { date: '2026-11-22', reason: 'projected', per_day: 100 },
      freshness: { stale: true, items: [{ provider: 'meta', account: 'Main', reason: 'failed', error_category: 'token_invalidated', last_synced_at: null }] },
    })])
    renderWithProviders(<SpendLimitsPage />, { locale: 'ar' })

    expect(await screen.findByTestId('spend-limit-l1-run-out')).toHaveTextContent('2026-11-22')
    expect(screen.getByTestId('spend-limit-l1-converted')).toHaveTextContent('من SAR إلى USD')
    const chip = screen.getByTestId('spend-limit-l1-freshness')
    expect(chip).toHaveTextContent('بيانات قديمة')
    expect(chip).toHaveTextContent('لم تعد المنصة تقبل التفويض')
  })

  it('a fresh limit carries no marker and no run-out line', async () => {
    route([reading()])
    renderWithProviders(<SpendLimitsPage />, { locale: 'ar' })

    await screen.findByTestId('spend-limit-l1')
    expect(screen.queryByTestId('spend-limit-l1-freshness')).toBeNull()
    expect(screen.queryByTestId('spend-limit-l1-run-out')).toBeNull()
  })

  it('renew-all shows a preview with editable amounts and sends nothing until «تأكيد»', async () => {
    route([reading()])
    const { postData } = await import('@/lib/api/client')
    vi.mocked(postData).mockResolvedValue({ budgets: 1, limits: 1, skipped: 0 } as never)
    renderWithProviders(<SpendLimitsPage />, { locale: 'ar' })

    fireEvent.click(await screen.findByTestId('renew-all-open'))
    const preview = await screen.findByTestId('renew-all-preview')
    expect(postData).not.toHaveBeenCalled()
    fireEvent.change(within(preview).getByTestId('renew-amount-b:p1'), { target: { value: '6000' } })
    fireEvent.click(within(preview).getByTestId('renew-all-confirm'))

    await waitFor(() => expect(postData).toHaveBeenCalledWith('/funding/renew-next-month', {
      confirmed: true, budgets: [{ project_id: 'p1', amount: 6000 }], limits: [{ id: 'l1', amount: 10_000 }],
    }))
    expect(await screen.findByTestId('renew-all-done')).toBeInTheDocument()
  })
})

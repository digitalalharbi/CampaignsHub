import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { BudgetsPage } from './BudgetsPage'
import { AddBudgetDialog } from './AddBudgetDialog'
import { FinancialDisclaimer } from './FinancialDisclaimer'
import { ModeChips, PeriodChoice, PlanEditor, emptyPlan, planPayload, planState, presetOf, presetRange } from './BudgetOptions'
import { Money, currencyMark, formatMoney } from './money'
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

const DISCLAIMER = { ar: 'الأرقام هنا للمتابعة التشغيلية فقط.', en: 'Figures here are for operational follow-up only.' }

const base = (over: Record<string, unknown> = {}) => ({
  project_id: 'p1', client_workspace_id: 'c1', project_name: 'Launch', client_name: 'Club', payment_method: 'prepaid', payment_mode: 'direct',
  counts_from: '2026-10-01', period_start: '2026-10-01', period_end: null, period_start_stated: false, voided_at: null, void_reason: null,
  alert_floor: 2000, currency: 'SAR', approved: 10_000, payments: 1, spent: 4_000, remaining: 6_000, used_ratio: 0.4, pace_per_day: null, days_left: null,
  spend_without_rate: 0, complete: true, last_spend_day: '2026-11-12', by_platform: [{ provider: 'snapchat', spent: 3_750, spent_usd: 1_000 }],
  status: 'ok', to_date: null, this_month: null, usd_rate: 3.75, ceiling: null, ceiling_ratio: null, to_invoice: null, planned: false, platform_plan: null,
  plan: null, plan_alerts: 0, ...over,
})
const credit = (over: Record<string, unknown> = {}) => base({ payment_mode: 'credit', approved: 0, remaining: null, used_ratio: null, status: 'credit', spent: 3_750, to_date: 3_750, this_month: 1_200, to_invoice: 2_550, ...over })
const planRow = (over: Record<string, unknown> = {}) => ({ provider: 'snapchat', percent: 60, planned: 6_000, planned_usd: 1_600, spent: 3_750, spent_usd: 1_000, share_used: 0.625, pace_per_day: 100, projected: 9_000, expected_to_date: 3_000, variance: 750, flag: null, unconverted_days: 0, ...over })

function serve(rows: unknown[], detail: Record<string, unknown>) {
  vi.mocked(getEnvelope).mockResolvedValue({ data: rows, meta: { totals: { approved: 10_000, spent: 4_000, remaining: 6_000, needs_attention: 0, credit_count: 1, credit_spent: 3_750, currency: 'SAR' }, can_manage: true, deleted_count: 0 } } as never)
  vi.mocked(getData).mockImplementation(async (url: string) => {
    if (url.includes('/disclaimer')) return DISCLAIMER as never
    if (url.includes('/months/')) return { month: '2026-11', from: '2026-11-01', to: '2026-11-30', remaining_start: null, added: 0, spent: 0, remaining_end: null, approved_total: 0, by_platform: [], movements: [], spend_without_rate: 0, fixed: false, fixed_at: null } as never

    return { ...detail, history: [], undo_entry_id: null, series: [], months: [], invoice_months: (detail.invoice_months as unknown[]) ?? [] } as never
  })
}

describe('money: one helper, a short mark beside every figure', () => {
  it('marks SAR ر.س, USD $, EUR €, and any other code as itself', () => {
    expect(currencyMark('SAR')).toBe('ر.س')
    expect(currencyMark('usd')).toBe('$')
    expect(currencyMark('EUR')).toBe('€')
    expect(currencyMark('aed')).toBe('AED')
    expect(formatMoney(1234.4, 'SAR')).toBe('1,234 ر.س')
    expect(formatMoney(-50, 'USD')).toBe('−50 $')
    expect(formatMoney(null)).toBe('—')
  })

  it('renders the number as a left-to-right island with its mark', () => {
    render(<Money n={1500} currency="USD" />)
    expect(screen.getByText('1,500').closest('bdi')).toHaveAttribute('dir', 'ltr')
    expect(screen.getByText('$')).toBeInTheDocument()
  })
})

describe('the financial disclaimer', () => {
  beforeEach(() => { vi.clearAllMocks(); signInWith(['funding.manage']) })
  afterEach(() => signOut())

  it('reads the one text from the server, in the reader language, on one line', async () => {
    vi.mocked(getData).mockResolvedValue(DISCLAIMER as never)
    renderWithProviders(<FinancialDisclaimer />, { locale: 'ar' })
    expect(await screen.findByTestId('financial-disclaimer')).toHaveTextContent(DISCLAIMER.ar)
  })

  it('has no wording of its own: nothing is drawn until the server answered', () => {
    vi.mocked(getData).mockReturnValue(new Promise(() => undefined) as never)
    renderWithProviders(<FinancialDisclaimer />, { locale: 'en' })
    expect(screen.queryByTestId('financial-disclaimer')).toBeNull()
  })
})

describe('the board follows the mode and shows the minimum', () => {
  beforeEach(() => { vi.clearAllMocks(); signInWith(['funding.manage']) })
  afterEach(() => signOut())

  it('a direct row: الميزانية · الإنفاق · المتبقي, a ring and a bar, with the mode chip', async () => {
    serve([base()], base())
    renderWithProviders(<BudgetsPage />, { locale: 'ar' })
    const item = await screen.findByTestId('budget-row-p1')
    expect(within(item).getByTestId('mode-chip-direct')).toHaveTextContent('مباشر')
    const figs = within(item).getByTestId('row-figures')
    expect(figs).toHaveTextContent('الميزانية')
    expect(figs).toHaveTextContent('الإنفاق')
    expect(figs).toHaveTextContent('المتبقي')
    expect(figs).toHaveTextContent('6,000 ر.س')
    expect(within(item).getByTestId('budget-ring')).toBeInTheDocument()
    expect(within(item).getByTestId('used-percent')).toHaveTextContent('40%')
    expect(await screen.findByTestId('financial-disclaimer')).toHaveTextContent(DISCLAIMER.ar)
    expect(screen.getByTestId('board-header')).toBeInTheDocument()
  })

  it('a credit row: الإنفاق حتى الآن · هذا الشهر (للفوترة), no remaining, and no bar without a ceiling', async () => {
    serve([credit()], credit())
    renderWithProviders(<BudgetsPage />, { locale: 'ar' })
    const item = await screen.findByTestId('budget-row-p1')
    expect(within(item).getByTestId('mode-chip-credit')).toHaveTextContent('بالأجل')
    const figs = within(item).getByTestId('row-figures')
    expect(figs).toHaveTextContent('الإنفاق حتى الآن')
    expect(figs).toHaveTextContent('هذا الشهر (للفوترة)')
    expect(figs).toHaveTextContent('1,200 ر.س')
    expect(figs).not.toHaveTextContent('المتبقي')
    expect(within(item).queryByTestId('used-percent')).toBeNull()
  })

  it('a credit row with a ceiling shows its bar and the ceiling', async () => {
    const r = credit({ ceiling: 5_000, ceiling_ratio: 0.75, used_ratio: 0.75, status: 'credit' })
    serve([r], r)
    renderWithProviders(<BudgetsPage />, { locale: 'ar' })
    const item = await screen.findByTestId('budget-row-p1')
    expect(within(item).getByTestId('used-percent')).toHaveTextContent('75%')
    expect(item).toHaveTextContent('سقف')
  })

  it('a planned row has the planned chip and at most ONE «فرق عن الخطة» chip', async () => {
    const r = base({ planned: true, plan_alerts: 1, platform_plan: [{ provider: 'snapchat', percent: 100, amount: 10_000 }],
      plan: [planRow({ flag: 'near', share_used: 0.93 }), planRow({ provider: 'meta', flag: 'behind' })] })
    serve([r], r)
    renderWithProviders(<BudgetsPage />, { locale: 'ar' })
    const item = await screen.findByTestId('budget-row-p1')
    expect(within(item).getByTestId('mode-chip-planned')).toHaveTextContent('مخطّطة')
    expect(within(item).getAllByTestId('plan-diff-chip')).toHaveLength(1)
    expect(within(item).getByTestId('plan-diff-chip')).toHaveTextContent('فرق عن الخطة')
  })

  it('the platform tooltip is in USD, and a day with no rate keeps its own currency beside «بدون سعر صرف»', async () => {
    const r = base({ by_platform: [{ provider: 'snapchat', spent: 3_750, spent_usd: 1_000 }, { provider: 'meta', spent: 0, spent_usd: 0, unconverted_days: 1, unconverted: [{ currency: 'EUR', amount: 100 }] }] })
    serve([r], r)
    renderWithProviders(<BudgetsPage />, { locale: 'ar' })
    const item = await screen.findByTestId('budget-row-p1')
    expect(within(item).getByTestId('split-snapchat').getAttribute('title')).toMatch(/1,000 \$/)
    expect(within(item).getByTestId('split-meta').getAttribute('title')).toMatch(/0 \$ \+ 100 € · بدون سعر صرف|100 € · بدون سعر صرف/)
  })
})

describe('the drawer', () => {
  beforeEach(() => { vi.clearAllMocks(); signInWith(['funding.manage']) })
  afterEach(() => signOut())

  it('a credit budget lists its months (running marked) and carries the disclaimer', async () => {
    const r = credit({ invoice_months: [
      { month: '2026-10', from: '2026-10-01', to: '2026-10-31', closed: true, spent: 2_550, unconverted_days: 0, last_spend_day: '2026-10-30', by_platform: [] },
      { month: '2026-11', from: '2026-11-01', to: '2026-11-30', closed: false, spent: 1_200, unconverted_days: 0, last_spend_day: '2026-11-12', by_platform: [] },
    ] })
    serve([r], r)
    renderWithProviders(<BudgetsPage />, { locale: 'ar' })
    fireEvent.click(await screen.findByTestId('budget-row-p1'))
    const drawer = await screen.findByTestId('budget-drawer')
    const months = await within(drawer).findByTestId('budget-invoice-months')
    expect(within(months).getByTestId('invoice-month-2026-10')).toHaveTextContent('مكتمل')
    expect(within(months).getByTestId('invoice-month-2026-11')).toHaveTextContent('جارٍ')
    expect(within(months).getByTestId('invoice-month-2026-10')).toHaveTextContent('2,550 ر.س')
    expect(within(drawer).getByTestId('budget-this-month')).toHaveTextContent('1,200 ر.س')
    expect(within(drawer).getByTestId('financial-disclaimer')).toBeInTheDocument()
  })

  it('a planned budget shows one bar per platform with its %, and «أعد التوزيع» is one click', async () => {
    const r = base({ planned: true, plan_alerts: 0, platform_plan: [{ provider: 'snapchat', percent: 100, amount: 10_000 }], plan: [planRow({ flag: 'ahead' })] })
    serve([r], r)
    vi.mocked(postData).mockResolvedValue({ entry_id: 'e1', ...r } as never)
    renderWithProviders(<BudgetsPage />, { locale: 'ar' })
    fireEvent.click(await screen.findByTestId('budget-row-p1'))
    const plan = await screen.findByTestId('budget-plan')
    const bar = within(plan).getByTestId('plan-bar-snapchat')
    expect(bar).toHaveTextContent('63%')
    expect(bar).toHaveTextContent('$')
    expect(within(plan).getByTestId('plan-diff-chip')).toHaveTextContent('فرق عن الخطة')
    expect(plan).not.toHaveTextContent(/projected|pace|المتوقع|الإيقاع/i)
    fireEvent.click(within(plan).getByTestId('budget-reallocate'))
    await waitFor(() => expect(postData).toHaveBeenCalledWith('/funding/projects/p1/reallocate', expect.objectContaining({ reason: expect.any(String) })))
  })
})

describe('the add dialog stays one step', () => {
  beforeEach(() => { vi.clearAllMocks(); signInWith(['funding.manage']) })
  afterEach(() => signOut())

  it('offers the mode as 3 chips, an amount for direct, and no ceiling field until «+ سقف»', async () => {
    renderWithProviders(<AddBudgetDialog open onClose={() => undefined} />, { locale: 'ar' })
    const chips = await screen.findByTestId('budget-mode')
    expect(within(chips).getByTestId('mode-direct')).toHaveAttribute('aria-checked', 'true')
    expect(screen.getByLabelText(/المبلغ/)).toBeInTheDocument()

    fireEvent.click(within(chips).getByTestId('mode-credit'))
    expect(screen.queryByLabelText(/المبلغ/)).toBeNull()
    expect(screen.queryByLabelText(/^سقف/)).toBeNull()
    fireEvent.click(screen.getByTestId('ceiling-chip'))
    expect(screen.getByLabelText(/^سقف/)).toBeInTheDocument()
    expect(screen.queryByTestId('plan-editor')).toBeNull()

    fireEvent.click(within(chips).getByTestId('mode-planned'))
    expect(screen.getByTestId('plan-editor')).toBeInTheDocument()
  })

  it('sends a credit budget with no amount, the default period, and nothing the user did not ask for', async () => {
    vi.mocked(postData).mockResolvedValue({ payment_id: 'e1', project_id: 'p1' } as never)
    renderWithProviders(<AddBudgetDialog open onClose={() => undefined} />, { locale: 'ar' })
    fireEvent.change(await screen.findByLabelText(/العميل/), { target: { value: 'Club' } })
    fireEvent.change(screen.getByLabelText(/المشروع/), { target: { value: 'Launch' } })
    fireEvent.click(screen.getByTestId('mode-credit'))
    fireEvent.click(screen.getByTestId('budget-submit'))
    await waitFor(() => expect(postData).toHaveBeenCalled())
    const body = vi.mocked(postData).mock.calls[0]![1] as Record<string, unknown>
    expect(body.payment_mode).toBe('credit')
    expect('amount' in body).toBe(false)
    expect(body.period_start).toBeNull()
    expect(body.period_end).toBeNull()
    expect('platform_plan' in body).toBe(false)
  })

  it('the period is one collapsed chip; its quick choices open on demand', async () => {
    renderWithProviders(<AddBudgetDialog open onClose={() => undefined} />, { locale: 'ar' })
    const toggle = await screen.findByTestId('period-toggle')
    expect(toggle).toHaveTextContent('مفتوحة')
    expect(screen.queryByTestId('period-this_month')).toBeNull()
    fireEvent.click(toggle)
    expect(screen.getByTestId('period-next_month')).toBeInTheDocument()
    expect(screen.getByTestId('period-custom')).toBeInTheDocument()
  })
})

describe('mode, period and split helpers', () => {
  it('the presets are exact ranges; open is the smart default (first spend, no end)', () => {
    const now = new Date(2026, 10, 13)
    expect(presetRange('open', now)).toEqual({ start: '', end: '' })
    expect(presetRange('this_month', now)).toEqual({ start: '2026-11-01', end: '2026-11-30' })
    expect(presetRange('next_month', now)).toEqual({ start: '2026-12-01', end: '2026-12-31' })
    expect(presetRange('from_today', now)).toEqual({ start: '2026-11-13', end: '' })
    expect(presetRange('custom', now)).toBeNull()
    expect(presetOf('2026-11-01', '2026-11-30', now)).toBe('this_month')
    expect(presetOf('2026-11-05', '2026-11-20', now)).toBe('custom')
  })

  it('the split adds up to 100 with each platform once; the payload is percentages', () => {
    expect(planState(emptyPlan()).ok).toBe(false)
    const ok = [{ provider: 'snapchat', value: '60' }, { provider: 'meta', value: '40' }]
    expect(planState(ok)).toEqual({ sum: 100, ok: true })
    expect(planState([{ provider: 'snapchat', value: '60' }, { provider: 'snapchat', value: '40' }]).ok).toBe(false)
    expect(planState([{ provider: 'snapchat', value: '60' }, { provider: 'meta', value: '30' }]).ok).toBe(false)
    expect(planPayload(ok)).toEqual([{ provider: 'snapchat', percent: 60 }, { provider: 'meta', percent: 40 }])
  })

  it('the chips and the split editor are controlled', () => {
    const onMode = vi.fn(); const onPlanned = vi.fn(); const onRows = vi.fn(); const onPreset = vi.fn()
    render(<ModeChips mode="direct" planned={false} onMode={onMode} onPlanned={onPlanned} ar />)
    fireEvent.click(screen.getByTestId('mode-credit')); fireEvent.click(screen.getByTestId('mode-planned'))
    expect(onMode).toHaveBeenCalledWith('credit'); expect(onPlanned).toHaveBeenCalledWith(true)

    render(<PlanEditor rows={[{ provider: 'snapchat', value: '50' }]} onRows={onRows} ar />)
    fireEvent.click(screen.getByTestId('plan-add'))
    expect(onRows).toHaveBeenCalled()
    expect(screen.getByTestId('plan-sum')).toHaveAttribute('data-ok', 'false')

    render(<PeriodChoice preset="open" start="" end="" onPreset={onPreset} onStart={vi.fn()} onEnd={vi.fn()} ar idPrefix="t" />)
    fireEvent.click(screen.getByTestId('period-toggle')); fireEvent.click(screen.getByTestId('period-from_today'))
    expect(onPreset).toHaveBeenCalledWith('from_today', expect.objectContaining({ end: '' }))
  })
})

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { AutomationPage } from './AutomationPage'
import type { AutomationRule, Catalog } from './api'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'

vi.mock('./api', () => ({
  getCatalog: vi.fn(), getSummary: vi.fn(), getTargets: vi.fn(), listRules: vi.fn(), listActions: vi.fn(), listChanges: vi.fn(),
  saveRule: vi.fn(), toggleRule: vi.fn(), deleteRule: vi.fn(), dryRunRule: vi.fn(), runRuleNow: vi.fn(),
}))
import { dryRunRule, getCatalog, getSummary, getTargets, listActions, listRules, saveRule, toggleRule } from './api'

const catalog: Catalog = {
  metrics: [
    { key: 'spend', ar: 'الإنفاق', en: 'Spend', kind: 'money' }, { key: 'roas', ar: 'العائد', en: 'ROAS', kind: 'ratio' },
    { key: 'cpa', ar: 'تكلفة النتيجة', en: 'Cost per result', kind: 'money' }, { key: 'delivery_status', ar: 'حالة العرض', en: 'Delivery status', kind: 'status' },
  ],
  operators: ['gt', 'gte', 'lt', 'lte', 'eq'], windows: [1, 3, 7, 14, 30],
  actions: [{ key: 'notify', ar: 'تنبيه', en: 'Notify', executable: false }, { key: 'pause', ar: 'إيقاف', en: 'Turn off', executable: true }, { key: 'budget_decrease', ar: 'خفض', en: 'Decrease daily budget', executable: true }, { key: 'bid_adjust', ar: 'مزايدة', en: 'Adjust bid', executable: false }],
  frequencies: ['hourly', 'daily'], modes: ['monitor', 'recommend', 'auto'], levels: ['campaign', 'ad_set', 'ad'],
  providers: {}, autonomy: { pause: false, budget_decrease: false },
}

const rule: AutomationRule = {
  id: 'r1', name: 'Cut losers', enabled: true, mode: 'recommend', level: 'campaign', logic: 'all', scope: { platforms: ['tiktok'] },
  conditions: [{ metric: 'spend', operator: 'gt', value: 500, window_days: 3 }, { metric: 'roas', operator: 'lt', value: 3, window_days: 3 }],
  action: { type: 'pause' }, schedule: { frequency: 'hourly', timezone: 'Asia/Riyadh' }, cooldown_hours: 24, min_spend: null,
  last_run_at: null, next_run_at: null, last_result: null,
}

/** AUTO-RULES-UI-010 — the rules dashboard: what the rules did, the rules, and the builder. */
describe('the automated rules page', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useProject.setState({ currentProjectId: 'proj' })
    signInWith(['campaigns.view', 'campaigns.pause', 'campaigns.budget.change', 'ai.manage'])
    vi.mocked(getCatalog).mockResolvedValue(catalog)
    vi.mocked(getSummary).mockResolvedValue({ days: 7, all: 263, turn_on: 0, turn_off: 3, budget: 260, bid: 0, notified: 0, failed: 1, awaiting_approval: 4, rules_enabled: 5, rules_disabled: 0, rules_idle_30d: 0 })
    vi.mocked(listRules).mockResolvedValue({ rules: [rule] })
    vi.mocked(listActions).mockResolvedValue({ actions: [] })
    vi.mocked(getTargets).mockResolvedValue({ accounts: [{ id: 'a1', provider: 'tiktok', name: 'Jood' }], campaigns: [{ id: 'c1', provider: 'tiktok', name: 'Spring', status: 'active', account_id: 'a1', daily_budget: 100, currency: 'SAR' }], ad_sets: [] })
  })
  afterEach(() => signOut())

  it('shows the summary and each rule as a sentence with its last result', async () => {
    renderWithProviders(<AutomationPage />, { locale: 'en' })

    expect(await screen.findByTestId('automation-card-all')).toHaveTextContent('263')
    expect(screen.getByTestId('automation-card-budget')).toHaveTextContent('260')
    expect(await screen.findByTestId('automation-conditions-r1')).toHaveTextContent('If Spend (last 3 days) > 500 AND ROAS (last 3 days) < 3')
    expect(screen.getByTestId('automation-result-r1')).toHaveTextContent("Rule hasn't run yet")
    expect(screen.getByTestId('automation-rule-r1')).toHaveTextContent('Recommend for approval')
  })

  it('turns a rule off and shows a dry run without changing anything', async () => {
    vi.mocked(toggleRule).mockResolvedValue({ ...rule, enabled: false })
    vi.mocked(dryRunRule).mockResolvedValue({ run: {} as never, results: [{ provider: 'tiktok', name: 'Spring', external_id: '9', level: 'campaign', matched: true, status: 'would_pause', metrics: { 'spend@3d': 600, 'roas@3d': 1.5 }, before: null, after: null }] })
    renderWithProviders(<AutomationPage />, { locale: 'en' })

    fireEvent.click(await screen.findByTestId('automation-toggle-r1'))
    await waitFor(() => expect(toggleRule).toHaveBeenCalledWith('proj', 'r1', false))

    fireEvent.click(screen.getByTestId('automation-dry-r1'))
    const row = await screen.findByTestId('automation-dry-row')
    expect(row).toHaveTextContent('Spring')
    expect(row).toHaveTextContent('roas@3d: 1.5')
    expect(row).toHaveTextContent('Would ask: pause')
  })

  it('builds a rule with scope, AND/OR conditions, a budget action and a mode', async () => {
    vi.mocked(saveRule).mockResolvedValue({ ...rule, id: 'r2' })
    renderWithProviders(<AutomationPage />, { locale: 'en' })

    const create = await screen.findByTestId('automation-new')
    await waitFor(() => expect(create).not.toBeDisabled())
    fireEvent.click(create)
    const builder = await screen.findByTestId('rule-builder')
    fireEvent.change(within(builder).getByTestId('rule-name'), { target: { value: 'Budget cut' } })
    fireEvent.click(await within(builder).findByText('Jood', { exact: false }))
    fireEvent.click(within(builder).getByTestId('rule-logic-any'))
    fireEvent.change(within(builder).getByTestId('rule-action'), { target: { value: 'budget_decrease' } })
    fireEvent.change(within(builder).getByTestId('rule-action-percent'), { target: { value: '20' } })
    fireEvent.click(within(builder).getByTestId('rule-mode-monitor'))
    fireEvent.click(within(builder).getByTestId('rule-save'))

    await waitFor(() => expect(saveRule).toHaveBeenCalledWith('proj', expect.objectContaining({
      name: 'Budget cut', logic: 'any', mode: 'monitor', scope: { account_ids: ['a1'] }, action: { type: 'budget_decrease', percent: 20 },
    }), undefined))
  })
})

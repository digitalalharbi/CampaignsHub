import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { LaunchPage } from './LaunchPage'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, useLaunchOptions: vi.fn(), useLaunchPlans: vi.fn(), savePlan: vi.fn(), publishPlan: vi.fn() }
})
import { publishPlan, savePlan, useLaunchOptions, useLaunchPlans } from './api'

const ok = (data: unknown) => ({ data, isPending: false, isLoading: false, isError: false, error: null, refetch: vi.fn() })
const options = (state: 'available' | 'awaiting_credentials' = 'available') => ({
  accounts: [{ id: 'acc1', provider: 'meta', name: 'Razzah Meta', currency: 'SAR', state, launch_permitted: true, objectives: ['OUTCOME_SALES'] }],
  providers: [{ provider: 'meta', objectives: ['OUTCOME_SALES'], optimization_goals: ['OFFSITE_CONVERSIONS'], bid_strategies: [], placement_families: [], ad_set_refusal: null, ad_refusal: null, ad_fields: 'creative', countries_in_ad_set: true, bid_required: false }],
  creatives: { meta: [{ id: 'cr1', name: 'Hero', format: 'video', thumbnail_url: null }] },
  launch_permitted: true,
})
const plan = (over: Record<string, unknown> = {}) => ({
  id: 'pl1', name: 'Ramadan', objective: 'OUTCOME_SALES', status: 'draft', launch: false, unified_campaign_id: null,
  spec: { name: 'Ramadan', objective: 'OUTCOME_SALES', accounts: [{ external_account_id: 'acc1' }], ad_set: null, ad: null, tracking: null, launch: false },
  steps: [{ account_id: 'acc1', provider: 'meta', campaign: { status: 'pending' }, ad_set: null, ad: null, launched: null }],
  published_at: null, created_at: null,
  review: [{ account_id: 'acc1', provider: 'meta', account_name: 'Razzah Meta', currency: 'SAR', problems: [], step: { account_id: 'acc1', provider: 'meta', campaign: { status: 'pending' }, ad_set: null, ad: null, launched: null } }],
  ...over,
})

/** LAUNCH-001 — the builder publishes only after an explicit confirmation, and shows what the platform answered. */
describe('the launch page', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    signInWith(['campaigns.view', 'campaigns.create', 'campaigns.launch'])
    useProject.setState({ currentProjectId: 'p1' } as never)
    vi.mocked(useLaunchOptions).mockReturnValue(ok(options()) as never)
    vi.mocked(useLaunchPlans).mockReturnValue(ok([]) as never)
    vi.mocked(savePlan).mockResolvedValue(plan() as never)
  })
  afterEach(() => { signOut(); useProject.setState({ currentProjectId: null } as never) })

  it('walks account → objective → review, saves the plan, and publishes only through the confirmation', async () => {
    vi.mocked(publishPlan).mockResolvedValue(plan({ status: 'published', unified_campaign_id: 'uc1', steps: [{ account_id: 'acc1', provider: 'meta', campaign: { status: 'done', external_id: '5001' }, ad_set: null, ad: null, launched: null }] }) as never)
    renderWithProviders(<LaunchPage />, { locale: 'ar', route: '/agency/launch' })

    fireEvent.click(await screen.findByTestId('launch-account-acc1'))
    fireEvent.click(screen.getByTestId('launch-next'))
    fireEvent.change(screen.getByTestId('launch-name'), { target: { value: 'Ramadan' } })
    fireEvent.click(screen.getByRole('combobox', { name: /الهدف/ }))
    fireEvent.mouseDown(await screen.findByRole('option', { name: /OUTCOME_SALES|المبيعات/ }))
    fireEvent.click(screen.getByTestId('launch-next'))   // ad set (optional)
    fireEvent.click(screen.getByTestId('launch-next'))   // ad (optional)
    fireEvent.click(screen.getByTestId('launch-next'))   // tracking
    const stepNow = document.querySelector('[data-testid^="launch-step-"]')?.getAttribute('data-testid')
    expect(stepNow, 'the walk should have reached tracking').toBe('launch-step-tracking')
    fireEvent.click(screen.getByTestId('launch-save'))

    await screen.findByTestId('launch-review')
    expect(savePlan).toHaveBeenCalledWith('p1', expect.objectContaining({ name: 'Ramadan', objectives: { meta: 'OUTCOME_SALES' }, accounts: [{ external_account_id: 'acc1', daily_budget: null }] }), null)
    expect(screen.getByText('جاهز للنشر')).toBeInTheDocument()
    expect(publishPlan).not.toHaveBeenCalled()

    fireEvent.click(screen.getByTestId('launch-publish'))
    expect(await screen.findByTestId('launch-confirm-dialog')).toHaveTextContent('تُنشأ موقوفة')
    expect(publishPlan).not.toHaveBeenCalled()
    fireEvent.click(screen.getByTestId('launch-confirm'))
    await waitFor(() => expect(publishPlan).toHaveBeenCalledWith('p1', 'pl1'))
    expect(await screen.findByTestId('launch-results')).toHaveTextContent('5001')
    expect(screen.getByTestId('launch-status-published')).toBeInTheDocument()
    expect(screen.getByTestId('launch-open-campaign')).toHaveAttribute('href', '/agency/campaigns/p1/uc1')
  })

  it('keeps an account awaiting credentials unselectable and says so, and blocks publishing on review problems', async () => {
    vi.mocked(useLaunchOptions).mockReturnValue(ok(options('awaiting_credentials')) as never)
    vi.mocked(useLaunchPlans).mockReturnValue(ok([plan({ review: [{ account_id: 'acc1', provider: 'meta', account_name: 'Razzah Meta', currency: 'SAR', problems: ['awaiting_credentials'], step: plan().steps[0] }] })]) as never)
    renderWithProviders(<LaunchPage />, { locale: 'ar', route: '/app/launch' })

    const box = await screen.findByTestId('launch-account-acc1')
    expect(box).toBeDisabled()
    expect(screen.getByTestId('launch-no-usable')).toBeInTheDocument()
    expect(screen.getByText('المنصة بانتظار بيانات اعتماد التطبيق')).toBeInTheDocument()
    expect(screen.getByTestId('launch-next')).toBeDisabled()

    fireEvent.click(screen.getByText('فتح'))   // the saved draft from history
    await screen.findByTestId('launch-review')
    expect(screen.getByTestId('launch-blocked')).toHaveTextContent('1')
    expect(screen.getByTestId('launch-publish')).toBeDisabled()
  })

  it('shows a partial launch as partial with the provider error and a retry', async () => {
    const partial = plan({ status: 'partial', unified_campaign_id: 'uc1', steps: [{ account_id: 'acc1', provider: 'meta', campaign: { status: 'done', external_id: '5001' }, ad_set: { status: 'failed', error: '(#100) Bad targeting' }, ad: { status: 'pending' }, launched: null }] })
    vi.mocked(useLaunchPlans).mockReturnValue(ok([partial]) as never)
    renderWithProviders(<LaunchPage />, { locale: 'en', route: '/agency/launch' })

    fireEvent.click(await screen.findByText('Open'))
    const results = await screen.findByTestId('launch-results')
    expect(results).toHaveTextContent('Bad targeting')
    expect(within(results).getByTestId('launch-status-partial')).toBeInTheDocument()
    expect(screen.getByTestId('launch-retry')).toBeInTheDocument()
  })
})

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { ApprovalsPage } from './ApprovalsPage'
import type { Proposal } from './api'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'

vi.mock('./api', () => ({ listProposals: vi.fn(), decideProposal: vi.fn(), getAutonomy: vi.fn(), setAutonomy: vi.fn() }))
import { decideProposal, getAutonomy, listProposals, setAutonomy } from './api'

const proposal = (over: Partial<Proposal> = {}): Proposal => ({
  id: 'p1', agent: 'campaign', provider: 'meta', external_account_id: 'a1', target_type: 'campaign', target_external_id: 'cmp_1',
  action: 'set_daily_budget', payload: { daily_budget: 110 }, currency: 'USD', reason: 'ROAS is twice the target', status: 'pending',
  violations: [], decided_at: null, decision_note: null, expires_at: null, executed_at: null, created_at: null, ...over,
})

const LIMITS = { max_increase_percent: 20, daily_budget_ceiling: null }

const ladder = (cls: 'pause' | 'budget_decrease', over = {}) => ({
  class: cls, eligible: false, decided: 3, approved: 3, rate: 1, first_decided_at: null, days: 8, failed_recent: 0,
  needs: ['history_days', 'decided_count'], granted: false, ...over,
})
const AUTONOMY = { classes: [ladder('pause'), ladder('budget_decrease')], requirements: { min_days: 30, min_decided: 10, min_approval_rate: 0.9 } }

/** AGENTS-APPROVAL-GATEWAY-001 — the queue only asks; it never claims what the server did not do. */
describe('the approvals queue', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useProject.setState({ currentProjectId: 'proj' })
    signInWith(['campaigns.pause', 'campaigns.budget.change', 'ai.view'])
    vi.mocked(getAutonomy).mockResolvedValue(AUTONOMY)
  })
  afterEach(() => signOut())

  it('shows the request, its reason and the standing limits, and asks the server to approve', async () => {
    vi.mocked(listProposals).mockResolvedValue({ proposals: [proposal()], limits: LIMITS })
    vi.mocked(decideProposal).mockResolvedValue(proposal({ status: 'blocked', violations: ['execution_not_enabled_for_provider'] }))

    renderWithProviders(<ApprovalsPage />, { locale: 'en' })

    expect(await screen.findByTestId('proposal-p1')).toHaveTextContent('Daily budget 110 USD')
    expect(screen.getByTestId('proposal-p1')).toHaveTextContent('ROAS is twice the target')
    expect(screen.getByTestId('approvals-limits')).toHaveTextContent('capped at 20%')
    expect(screen.getByTestId('approvals-limits')).toHaveTextContent('never a delete')

    fireEvent.click(screen.getByTestId('proposal-p1-approve'))
    await waitFor(() => expect(decideProposal).toHaveBeenCalledWith('proj', 'p1', 'approve'))
  })

  it('says why a blocked request was stopped, in words, and offers no decision on it', async () => {
    vi.mocked(listProposals).mockResolvedValue({
      proposals: [proposal({ status: 'blocked', violations: ['increase_exceeds_cap'] })],
      limits: LIMITS,
    })

    renderWithProviders(<ApprovalsPage />, { locale: 'en' })

    expect(await screen.findByTestId('proposal-p1-violations')).toHaveTextContent('larger than the single-change cap')
    expect(screen.queryByTestId('proposal-p1-approve')).not.toBeInTheDocument()
    expect(screen.getByTestId('proposal-p1')).toHaveTextContent('Blocked')
  })

  it('offers no decision to someone who may not make one', async () => {
    signInWith(['ai.view'])
    vi.mocked(listProposals).mockResolvedValue({ proposals: [proposal()], limits: LIMITS })

    renderWithProviders(<ApprovalsPage />, { locale: 'ar' })

    expect(await screen.findByTestId('proposal-p1')).toBeInTheDocument()
    expect(screen.queryByTestId('proposal-p1-approve')).not.toBeInTheDocument()
  })

  it('says «blocked — nothing was sent» for an unenabled platform instead of «approved»', async () => {
    vi.mocked(listProposals).mockResolvedValue({
      proposals: [proposal({ status: 'blocked', violations: ['execution_not_enabled_for_provider'] })],
      limits: LIMITS,
    })

    renderWithProviders(<ApprovalsPage />, { locale: 'en' })

    expect(await screen.findByTestId('proposal-p1-violations')).toHaveTextContent('nothing was sent to the platform')
  })

  it('shows what the record still lacks, and offers no switch until it qualifies', async () => {
    signInWith(['campaigns.pause', 'campaigns.budget.change', 'ai.view', 'ai.manage'])
    vi.mocked(listProposals).mockResolvedValue({ proposals: [], limits: LIMITS })

    renderWithProviders(<ApprovalsPage />, { locale: 'en' })

    expect(await screen.findByTestId('autonomy-pause')).toHaveTextContent('history too short')
    expect(screen.queryByTestId('autonomy-pause-toggle')).not.toBeInTheDocument()
  })

  it('lets an owner switch on a class that qualifies', async () => {
    signInWith(['campaigns.pause', 'campaigns.budget.change', 'ai.view', 'ai.manage'])
    vi.mocked(listProposals).mockResolvedValue({ proposals: [], limits: LIMITS })
    vi.mocked(getAutonomy).mockResolvedValue({ ...AUTONOMY, classes: [ladder('pause', { eligible: true, needs: [] }), ladder('budget_decrease')] })
    vi.mocked(setAutonomy).mockResolvedValue({ ...AUTONOMY, classes: [ladder('pause', { eligible: true, needs: [], granted: true }), ladder('budget_decrease')] })

    renderWithProviders(<ApprovalsPage />, { locale: 'en' })
    fireEvent.click(await screen.findByTestId('autonomy-pause-toggle'))

    await waitFor(() => expect(setAutonomy).toHaveBeenCalledWith('proj', 'pause', true))
    expect(await screen.findByTestId('autonomy-pause')).toHaveTextContent('On')
  })
})

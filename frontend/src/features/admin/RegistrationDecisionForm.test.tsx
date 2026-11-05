import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { RegistrationDecisionForm } from './RegistrationDecisionForm'
import type { AdminRegistration, RegistrationDecisionPreview } from './api'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return {
    ...actual,
    approveRegistration: vi.fn(), decideRegistration: vi.fn(), previewRegistrationDecision: vi.fn(),
    verifyRegistrationSettlement: vi.fn(), fetchTenants: vi.fn(), fetchTenantDecisionTargets: vi.fn(),
  }
})

import {
  approveRegistration, decideRegistration, fetchTenantDecisionTargets, fetchTenants, previewRegistrationDecision, verifyRegistrationSettlement,
} from './api'

const registration = (over: Partial<AdminRegistration> = {}): AdminRegistration => ({
  id: 'reg-1', state: 'approved_awaiting_payment', label: 'Awaiting payment', email: 'a@a.test', name: 'A', tenant_name: 'A Co',
  account_type: 'brand', phone: null, requested_portal: 'app', plan_code: 'solo', email_verified: true, mobile_verified: true,
  next_step: null, reason: null, provisioned: false, review_note: null, info_requested: false, reviewed_at: null, reviewed_by: null,
  concessions: null, created_at: null, tenant_id: null, decisions: [],
  ...over,
})

const ownAccess = {
  mode: 'new_workspace', portal: 'app', role: 'owner', permissions: 'all_in_own_workspace' as const,
  creates: { tenant: 1, workspace: 1, membership: 1, project: 1 }, who_has_access: ['a@a.test'],
}

const waiverPreview: RegistrationDecisionPreview = {
  outcome: 'approve_waiver', payment_provider_ready: false, access: ownAccess,
  terms: { fee: '990.00', amount_due: '0.00', currency: 'SAR', list_amount: '990.00', renewal_amount: '990.00', renewal_on: '2027-11-03' },
}

const render = (r = registration(), locale: 'ar' | 'en' = 'en') => {
  const onDecided = vi.fn()
  renderWithProviders(<RegistrationDecisionForm registration={r} ar={locale === 'ar'} onDecided={onDecided} />, { locale })
  return onDecided
}

/** SIGNUP-DECISION-001 — the decision is read before it is taken, and a waiver is confirmed apart from everything else. */
describe('RegistrationDecisionForm', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(decideRegistration).mockResolvedValue({ registration: registration() })
    vi.mocked(approveRegistration).mockResolvedValue({ registration: registration() })
  })

  it('defaults to approval with payment still owed, which concedes nothing', async () => {
    render()

    expect(screen.getByTestId('decision-approve_payment_required')).toBeChecked()
    expect(screen.getByTestId('decision-hint')).toHaveTextContent(/stays closed/)
    fireEvent.click(screen.getByTestId('registration-approve'))

    await waitFor(() => expect(approveRegistration).toHaveBeenCalled())
    expect(vi.mocked(approveRegistration).mock.calls[0][0]).toBe('reg-1')
    expect(decideRegistration).not.toHaveBeenCalled()
  })

  it('offers no way to mark something paid', () => {
    render()

    expect(screen.queryByRole('button', { name: /mark.*paid/i })).not.toBeInTheDocument()
    expect(screen.getByTestId('decision-approve_external_settlement')).toBeInTheDocument()
  })

  it('will not take a waiver before the preview, the reason and the explicit yes', async () => {
    vi.mocked(previewRegistrationDecision).mockResolvedValue(waiverPreview)
    render()

    fireEvent.click(screen.getByTestId('decision-approve_waiver'))
    expect(screen.getByTestId('decision-submit')).toBeDisabled()

    fireEvent.click(screen.getByTestId('decision-preview'))
    expect(await screen.findByTestId('preview-waived')).toHaveTextContent('990.00 SAR')
    expect(screen.getByTestId('preview-due')).toHaveTextContent('0.00 SAR')
    expect(screen.getByTestId('preview-renewal-on')).toHaveTextContent('2027-11-03')
    expect(screen.getByTestId('decision-submit')).toBeDisabled()

    fireEvent.change(screen.getByTestId('decision-reason'), { target: { value: 'Pilot customer, owner agreed' } })
    expect(screen.getByTestId('decision-submit')).toBeDisabled()

    fireEvent.click(screen.getByTestId('decision-confirm-waiver'))
    expect(screen.getByTestId('decision-submit')).toBeEnabled()
    fireEvent.click(screen.getByTestId('decision-submit'))

    await waitFor(() => expect(decideRegistration).toHaveBeenCalled())
    expect(vi.mocked(decideRegistration).mock.calls[0][1]).toMatchObject({
      outcome: 'approve_waiver', confirm_waiver: true, confirm_amount: '990.00', reason: 'Pilot customer, owner agreed',
    })
  })

  it('throws the preview away when the decision changes', async () => {
    vi.mocked(previewRegistrationDecision).mockResolvedValue(waiverPreview)
    render()

    fireEvent.click(screen.getByTestId('decision-approve_waiver'))
    fireEvent.click(screen.getByTestId('decision-preview'))
    await screen.findByTestId('decision-preview-panel')

    fireEvent.click(screen.getByTestId('decision-approve_discount'))

    await waitFor(() => expect(screen.queryByTestId('decision-preview-panel')).not.toBeInTheDocument())
    expect(screen.getByTestId('decision-submit')).toBeDisabled()
  })

  it('says plainly when no payment provider is configured', async () => {
    vi.mocked(previewRegistrationDecision).mockResolvedValue({
      ...waiverPreview, outcome: 'approve_discount',
      terms: { list_amount: '100.00', amount_due: '75.00', currency: 'SAR', renewal_amount: '100.00', vat: { treatment: 'basic_15', rate: 0.15, tax: '11.25' } },
    })
    render()

    fireEvent.click(screen.getByTestId('decision-approve_discount'))
    fireEvent.change(screen.getByTestId('decision-discount'), { target: { value: '25' } })
    fireEvent.click(screen.getByTestId('decision-preview'))

    expect(await screen.findByTestId('preview-no-provider')).toHaveTextContent(/Awaiting payment provider configuration/)
    expect(screen.getByTestId('preview-due')).toHaveTextContent('75.00 SAR')
    expect(screen.getByTestId('preview-vat')).toHaveTextContent('11.25 SAR')
    expect(screen.queryByTestId('decision-confirm-waiver')).not.toBeInTheDocument()
  })

  it('shows what joining really grants before it is decided', async () => {
    vi.mocked(fetchTenants).mockResolvedValue({ tenants: [{ id: 't-1', name: 'Agency Co' }] } as never)
    vi.mocked(fetchTenantDecisionTargets).mockResolvedValue({
      roles: [{ slug: 'viewer', name: 'Viewer' }], projects: [{ id: 'p-1', name: 'Allowed' }], clients: [],
    })
    vi.mocked(previewRegistrationDecision).mockResolvedValue({
      outcome: 'approve_join_existing', payment_provider_ready: true, terms: { covered_by: 'existing_subscription' },
      access: {
        mode: 'join_existing', portal: 'agency', role: 'viewer', permissions: ['projects.view'], projects: ['Allowed'],
        creates: { tenant: 0, workspace: 0, membership: 1, project: 0 }, who_has_access: ['a@a.test'],
      },
    })
    render()

    fireEvent.click(screen.getByTestId('decision-approve_join_existing'))
    await screen.findByRole('option', { name: 'Agency Co' })
    fireEvent.change(screen.getByTestId('decision-tenant'), { target: { value: 't-1' } })
    await screen.findByRole('option', { name: 'Viewer' })
    fireEvent.change(screen.getByTestId('decision-role'), { target: { value: 'viewer' } })
    fireEvent.click(screen.getByLabelText('Allowed'))
    fireEvent.click(screen.getByTestId('decision-preview'))

    expect(await screen.findByTestId('preview-creates')).toHaveTextContent('membership')
    expect(screen.getByTestId('preview-creates')).not.toHaveTextContent('workspace')
    expect(screen.getByTestId('preview-permissions')).toHaveTextContent('projects.view')
    expect(screen.getByTestId('preview-projects')).toHaveTextContent('Allowed')
    expect(vi.mocked(previewRegistrationDecision).mock.calls[0][1].access).toMatchObject({ tenant_id: 't-1', role: 'viewer', project_ids: ['p-1'] })
  })

  it('lets a second administrator confirm a settlement, with a reason', async () => {
    vi.mocked(verifyRegistrationSettlement).mockResolvedValue({ registration: registration() })
    const onDecided = render(registration({
      decisions: [{
        id: 'd-1', outcome: 'approve_external_settlement', status: 'awaiting_finance', actor_id: 2, reason: 'x', access: null,
        terms: { evidence: { reference: 'BANK-77', amount: '100.00', currency: 'SAR', paid_on: '2026-11-01' } }, verified_by: null, created_at: null,
      }],
    }))

    expect(screen.getByTestId('decision-awaiting-finance')).toHaveTextContent('BANK-77')
    expect(screen.getByTestId('decision-verify')).toBeDisabled()
    fireEvent.change(screen.getByTestId('decision-verify-reason'), { target: { value: 'Matched on the statement' } })
    fireEvent.click(screen.getByTestId('decision-verify'))

    await waitFor(() => expect(verifyRegistrationSettlement).toHaveBeenCalledWith('reg-1', 'd-1', 'Matched on the statement'))
    await waitFor(() => expect(onDecided).toHaveBeenCalled())
  })

  it('is complete in Arabic, with Latin digits', async () => {
    vi.mocked(previewRegistrationDecision).mockResolvedValue(waiverPreview)
    render(registration(), 'ar')

    fireEvent.click(screen.getByTestId('decision-approve_waiver'))
    expect(screen.getByTestId('decision-hint')).toHaveTextContent('قرار مالي منفصل')
    fireEvent.click(screen.getByTestId('decision-preview'))

    const panel = await screen.findByTestId('decision-preview-panel')
    expect(panel.textContent).toContain('990.00')
    expect(panel.textContent).not.toMatch(/[٠-٩]/)
  })
})

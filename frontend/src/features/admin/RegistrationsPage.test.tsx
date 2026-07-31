import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { RegistrationsPage } from './RegistrationsPage'
import type { AdminRegistration } from './api'
import { renderWithProviders, signOut } from '@/test/utils'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return {
    ...actual,
    fetchRegistrations: vi.fn(),
    fetchRegistration: vi.fn(),
    approveRegistration: vi.fn(),
    rejectRegistration: vi.fn(),
    requestRegistrationInfo: vi.fn(),
    updateRegistrationTerms: vi.fn(),
  }
})

import {
  approveRegistration, fetchRegistration, fetchRegistrations, rejectRegistration, updateRegistrationTerms,
} from './api'

const application = (over: Partial<AdminRegistration> = {}): AdminRegistration => ({
  id: 'reg-1', state: 'pending_approval', label: 'Awaiting review',
  email: 'applicant@a.test', name: 'Applicant', tenant_name: 'Applicant Co',
  account_type: 'agency', phone: null, requested_portal: 'agency', plan_code: 'growth',
  email_verified: true, mobile_verified: false, next_step: null, reason: null, provisioned: false,
  review_note: null, info_requested: false, reviewed_at: null, reviewed_by: null,
  concessions: null, created_at: '2026-07-30T10:00:00+00:00', tenant_id: null,
  ...over,
})

const detail = (over: Partial<AdminRegistration> = {}, gates = {}) => ({
  registration: application(over),
  policy: { requires_mobile: false, requires_approval: true, requires_payment: false, ...gates },
  transitions: [
    { action: 'registration.started', at: '2026-07-30T10:00:00+00:00', user_id: null, reason: null, detail: null },
  ],
})

/**
 * The registration review queue, from the console (SIGNUP-003).
 *
 * The claim worth holding onto here is a negative one: there is no button on this screen that
 * activates an account. Approving clears one gate and the server says what happens next.
 */
describe('RegistrationsPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(fetchRegistrations).mockResolvedValue({
      registrations: [application()],
      meta: { total: 1, per_page: 25, current_page: 1 },
      counts: { pending_approval: 1 },
    })
    vi.mocked(fetchRegistration).mockResolvedValue(detail())
  })
  afterEach(() => signOut())

  it('lists what is waiting and where each application is held', async () => {
    renderWithProviders(<RegistrationsPage />, { route: '/admin/registrations', locale: 'en' })

    const row = await screen.findByTestId('registration-row')
    expect(row).toHaveAttribute('data-state', 'pending_approval')
    expect(screen.getByTestId('registrations-count-pending_approval')).toHaveTextContent('1')
  })

  /** The negative claim: nothing here says "activate". */
  it('offers no way to activate an account directly', async () => {
    renderWithProviders(<RegistrationsPage />, { route: '/admin/registrations', locale: 'en' })

    fireEvent.click(await screen.findByTestId('registration-row'))
    await screen.findByTestId('registration-detail')

    expect(screen.queryByRole('button', { name: /activate/i })).not.toBeInTheDocument()
    expect(screen.getByTestId('registration-approve')).toBeInTheDocument()
  })

  /**
   * Approving an application that also owes money leaves it owing money.
   *
   * The server decides that, so what this proves is that the console renders the answer it is given
   * rather than assuming approval means activation.
   */
  it('shows an approved application still waiting on payment', async () => {
    vi.mocked(fetchRegistration).mockResolvedValue(detail({}, { requires_payment: true }))
    vi.mocked(approveRegistration).mockResolvedValue({
      registration: application({ state: 'approved_awaiting_payment', label: 'Awaiting payment' }),
    })
    vi.mocked(fetchRegistration).mockResolvedValueOnce(detail({}, { requires_payment: true }))

    renderWithProviders(<RegistrationsPage />, { route: '/admin/registrations', locale: 'en' })

    fireEvent.click(await screen.findByTestId('registration-row'))
    fireEvent.click(await screen.findByTestId('registration-approve'))

    await waitFor(() => expect(approveRegistration).toHaveBeenCalledWith('reg-1'))
  })

  it('will not reject without a reason the applicant can be shown', async () => {
    renderWithProviders(<RegistrationsPage />, { route: '/admin/registrations', locale: 'en' })

    fireEvent.click(await screen.findByTestId('registration-row'))
    fireEvent.click(await screen.findByTestId('registration-reject-open'))

    // The reason field is required, so submitting empty never reaches the API.
    fireEvent.click(screen.getByTestId('registration-reject-confirm'))
    await waitFor(() => expect(rejectRegistration).not.toHaveBeenCalled())

    fireEvent.change(screen.getByLabelText(/Reason/i), { target: { value: 'Could not verify the company.' } })
    fireEvent.click(screen.getByTestId('registration-reject-confirm'))

    await waitFor(() => expect(rejectRegistration).toHaveBeenCalledWith('reg-1', 'Could not verify the company.'))
  })

  /** A waived gate without a justification is a hole in the record, so the change is abandoned. */
  it('abandons a gate change when the reviewer gives no reason', async () => {
    const prompt = vi.spyOn(window, 'prompt').mockReturnValue('')

    renderWithProviders(<RegistrationsPage />, { route: '/admin/registrations', locale: 'en' })

    fireEvent.click(await screen.findByTestId('registration-row'))
    fireEvent.click(await screen.findByTestId('registration-gate-requires_approval'))

    await waitFor(() => expect(prompt).toHaveBeenCalled())
    expect(updateRegistrationTerms).not.toHaveBeenCalled()

    prompt.mockRestore()
  })

  it('carries a reason with a gate change that does go through', async () => {
    const prompt = vi.spyOn(window, 'prompt').mockReturnValue('Paid off-platform under an annual contract.')
    vi.mocked(updateRegistrationTerms).mockResolvedValue({
      registration: application(),
      policy: { requires_mobile: false, requires_approval: false, requires_payment: false },
    })

    renderWithProviders(<RegistrationsPage />, { route: '/admin/registrations', locale: 'en' })

    fireEvent.click(await screen.findByTestId('registration-row'))
    fireEvent.click(await screen.findByTestId('registration-gate-requires_approval'))

    await waitFor(() => expect(updateRegistrationTerms).toHaveBeenCalledWith('reg-1', {
      requires_approval: false,
      reason: 'Paid off-platform under an annual contract.',
    }))

    prompt.mockRestore()
  })

  /** A decided application offers no actions — the decision has already been made. */
  it('offers no actions on an application that already became a workspace', async () => {
    vi.mocked(fetchRegistration).mockResolvedValue(detail({ state: 'active', provisioned: true, tenant_id: 't-1' }))

    renderWithProviders(<RegistrationsPage />, { route: '/admin/registrations', locale: 'en' })

    fireEvent.click(await screen.findByTestId('registration-row'))
    await screen.findByTestId('registration-detail')

    expect(screen.queryByTestId('registration-approve')).not.toBeInTheDocument()
    expect(screen.queryByTestId('registration-reject-open')).not.toBeInTheDocument()
  })

  it('shows the decision history rather than a bare state', async () => {
    renderWithProviders(<RegistrationsPage />, { route: '/admin/registrations', locale: 'en' })

    fireEvent.click(await screen.findByTestId('registration-row'))

    expect(await screen.findByTestId('registration-history')).toHaveTextContent('registration.started')
  })
})

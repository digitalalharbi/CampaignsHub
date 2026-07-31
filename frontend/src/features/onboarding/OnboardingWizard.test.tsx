import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen } from '@testing-library/react'
import { OnboardingWizard } from './OnboardingWizard'
import { renderWithProviders, signOut } from '@/test/utils'
import { useAuth } from '@/stores/auth'
import type { AuthUser } from '@/lib/api/types'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, setWorkspace: vi.fn() }
})
vi.mock('@/features/auth/api', () => ({ fetchCurrentUser: vi.fn() }))

import { setWorkspace } from './api'

/** Sign in a user whose server-driven onboarding step is `step` (+ optional account facts). */
function signInAtStep(step: string, extra: Partial<AuthUser['account'] & object> = {}) {
  const user: AuthUser = {
    id: 'u1', name: 'Tester', email: 't@test.dev', tenant_id: 'tenant-1',
    is_platform_admin: false, permissions: [], created_at: null,
    account: {
      account_type: 'agency', workspace_kind: 'company', enabled_modules: ['clients', 'requests'],
      // The portal these entitlements describe (REG-001) — an agency tenant, in the agency portal.
      portal: 'agency', module_switcher: false, nav: [], subscription_plan: 'growth',
      onboarding: { completed: false, step },
      ...extra,
    },
  }
  useAuth.getState().setUser(user)
}

describe('OnboardingWizard — shared form primitives', () => {
  beforeEach(() => vi.clearAllMocks())
  afterEach(() => signOut())

  it('renders the FormStepper and marks the current step', () => {
    signInAtStep('account_type')
    renderWithProviders(<OnboardingWizard />, { locale: 'en' })
    const current = document.querySelector('[aria-current="step"]')
    expect(current).toBeTruthy()
    expect(current).toHaveTextContent('Account type')
  })

  it('shows an ErrorSummary from a server validation error on the workspace step and focuses the field', async () => {
    signInAtStep('workspace')
    vi.mocked(setWorkspace).mockRejectedValue({
      response: { status: 422, data: { message: 'Validation failed', errors: { name: ['The name is too short.'] } } },
    })
    renderWithProviders(<OnboardingWizard />, { locale: 'en' })

    fireEvent.change(screen.getByLabelText(/Workspace name/i), { target: { value: 'My Workspace' } })
    fireEvent.click(screen.getByRole('button', { name: /Continue/i }))

    const summary = await screen.findByTestId('error-summary')
    expect(summary).toHaveTextContent('The name is too short.')
    fireEvent.click(screen.getByRole('button', { name: 'The name is too short.' }))
    expect(screen.getByLabelText(/Workspace name/i)).toHaveFocus()
  })

  it('renders the final ReviewList on the completion step', () => {
    signInAtStep('data_source')
    renderWithProviders(<OnboardingWizard />, { locale: 'en' })
    expect(screen.getByText('Review your setup')).toBeInTheDocument()
    // The account facts are echoed back in the review list.
    expect(screen.getByText('agency')).toBeInTheDocument()
    expect(screen.getByText('clients, requests')).toBeInTheDocument()
  })
})

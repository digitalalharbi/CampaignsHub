import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { RegisterPage } from './RegisterPage'
import { renderWithProviders, signOut } from '@/test/utils'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, register: vi.fn() }
})

import { register } from './api'

describe('RegisterPage — journey handoff', () => {
  afterEach(() => { signOut(); localStorage.clear() })

  it('presets the agency path from ?journey=multi-client and keeps it editable', () => {
    renderWithProviders(<RegisterPage />, { route: '/register?journey=multi-client', locale: 'en' })
    expect(screen.getByText('Your selected path')).toBeInTheDocument()
    // Agency card is the selected path (not a forced re-pick).
    expect(screen.getByRole('button', { name: /I manage several clients/i })).toHaveAttribute('aria-pressed', 'true')
    expect(screen.getByRole('button', { name: /I run my own campaigns/i })).toHaveAttribute('aria-pressed', 'false')
    expect(screen.getByText(/Clients and requests enabled for the agency workspace/i)).toBeInTheDocument()
  })

  it('presets the self-managed path from ?journey=self-service with an editable account type', () => {
    renderWithProviders(<RegisterPage />, { route: '/register?journey=self-service', locale: 'en' })
    expect(screen.getByRole('button', { name: /I run my own campaigns/i })).toHaveAttribute('aria-pressed', 'true')
    // Account-type select is offered (freelancer/brand/in-house), defaulting to freelancer.
    const select = screen.getByRole('combobox') as HTMLSelectElement
    expect(select.value).toBe('freelancer')
    expect(screen.getByRole('option', { name: 'In-house team' })).toBeInTheDocument()
  })

  it('behaves as today when no ?journey param is present (no preset panel)', () => {
    renderWithProviders(<RegisterPage />, { route: '/register', locale: 'en' })
    expect(screen.queryByText('Your selected path')).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /I run my own campaigns/i })).not.toBeInTheDocument()
  })

  /** A public page shows the service by name; `paid-media` is a slug for us, not language for a visitor. */
  it('names the chosen service instead of echoing the raw slug', () => {
    renderWithProviders(<RegisterPage />, { route: '/register?journey=multi-client&module=paid-media', locale: 'en' })
    expect(screen.getByText(/Selected service/i)).toBeInTheDocument()
    expect(screen.getByText('Paid advertising management')).toBeInTheDocument()
    expect(screen.queryByText('paid-media')).not.toBeInTheDocument()
  })

  it('carries the influencer module through as its own service', () => {
    renderWithProviders(<RegisterPage />, { route: '/register?journey=self-service&module=influencer-marketing', locale: 'en' })
    expect(screen.getByText('Influencer & content campaigns')).toBeInTheDocument()
  })
})

/**
 * The chosen path must reach the backend with the registration itself. Router state would be lost on a
 * refresh, and the onboarding wizard would then ask the visitor to pick the same path a second time.
 */
describe('RegisterPage — the journey is submitted, not just displayed', () => {
  beforeEach(() => { vi.clearAllMocks(); localStorage.clear() })
  afterEach(() => { signOut(); localStorage.clear() })

  const fill = () => {
    fireEvent.change(screen.getByLabelText(/Organization|Org/i), { target: { value: 'Acme' } })
    fireEvent.change(screen.getByLabelText(/Full name/i), { target: { value: 'Tester' } })
    fireEvent.change(screen.getByLabelText(/Email/i), { target: { value: 'new@test.dev' } })
    fireEvent.change(screen.getByLabelText(/^Password/i), { target: { value: 'secret123' } })
    fireEvent.change(screen.getByLabelText(/Confirm password/i), { target: { value: 'secret123' } })
    fireEvent.click(screen.getByRole('button', { name: /Create account/i }))
  }

  it('submits the agency path as account_type + service', async () => {
    vi.mocked(register).mockResolvedValue({ id: '1', name: 'T', email: 'new@test.dev' } as never)
    renderWithProviders(<RegisterPage />, { route: '/register?journey=multi-client&module=paid-media', locale: 'en' })
    fill()
    await waitFor(() => expect(register).toHaveBeenCalled())
    expect(vi.mocked(register).mock.calls[0][0]).toMatchObject({ account_type: 'agency', service: 'paid_media' })
  })

  it('submits the self-managed path with the selected account type', async () => {
    vi.mocked(register).mockResolvedValue({ id: '1', name: 'T', email: 'new@test.dev' } as never)
    renderWithProviders(<RegisterPage />, { route: '/register?journey=self-service&module=paid-media', locale: 'en' })
    fireEvent.change(screen.getByRole('combobox'), { target: { value: 'brand' } })
    fill()
    await waitFor(() => expect(register).toHaveBeenCalled())
    expect(vi.mocked(register).mock.calls[0][0]).toMatchObject({ account_type: 'brand', service: 'paid_media' })
  })

  it('presumes nothing when the visitor arrived without a journey', async () => {
    vi.mocked(register).mockResolvedValue({ id: '1', name: 'T', email: 'new@test.dev' } as never)
    renderWithProviders(<RegisterPage />, { route: '/register', locale: 'en' })
    fill()
    await waitFor(() => expect(register).toHaveBeenCalled())
    const payload = vi.mocked(register).mock.calls[0][0] as unknown as Record<string, unknown>
    expect(payload).not.toHaveProperty('account_type')
    expect(payload).not.toHaveProperty('service')
  })
})

describe('RegisterPage — error summary + draft', () => {
  beforeEach(() => { vi.clearAllMocks(); localStorage.clear() })
  afterEach(() => { signOut(); localStorage.clear() })

  it('shows an ErrorSummary on a failed submit and focuses the field on click', async () => {
    vi.mocked(register).mockRejectedValue({
      response: { status: 422, data: { message: 'Validation failed', errors: { email: ['The email has already been taken.'] } } },
    })
    renderWithProviders(<RegisterPage />, { route: '/register', locale: 'en' })

    fireEvent.change(screen.getByLabelText(/Organization|Org/i), { target: { value: 'Acme' } })
    fireEvent.change(screen.getByLabelText(/Full name/i), { target: { value: 'Tester' } })
    fireEvent.change(screen.getByLabelText(/Email/i), { target: { value: 'dup@test.dev' } })
    fireEvent.change(screen.getByLabelText(/^Password/i), { target: { value: 'secret123' } })
    fireEvent.change(screen.getByLabelText(/Confirm password/i), { target: { value: 'secret123' } })
    fireEvent.click(screen.getByRole('button', { name: /Create account/i }))

    const summary = await screen.findByTestId('error-summary')
    expect(summary).toHaveTextContent('The email has already been taken.')

    fireEvent.click(screen.getByRole('button', { name: 'The email has already been taken.' }))
    expect(screen.getByLabelText(/Email/i)).toHaveFocus()
  })

  it('restores a saved draft (non-secret fields) on mount', () => {
    localStorage.setItem('chub:draft:register', JSON.stringify({ tenant_name: 'Restored Co', name: 'Draft User', email: 'draft@test.dev' }))
    renderWithProviders(<RegisterPage />, { route: '/register', locale: 'en' })
    expect((screen.getByLabelText(/Organization|Org/i) as HTMLInputElement).value).toBe('Restored Co')
    expect((screen.getByLabelText(/Email/i) as HTMLInputElement).value).toBe('draft@test.dev')
  })
})

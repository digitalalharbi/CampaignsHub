import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen } from '@testing-library/react'
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
    expect(screen.getByText(/Paid Advertising module enabled by default/i)).toBeInTheDocument()
  })

  it('behaves as today when no ?journey param is present (no preset panel)', () => {
    renderWithProviders(<RegisterPage />, { route: '/register', locale: 'en' })
    expect(screen.queryByText('Your selected path')).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /I run my own campaigns/i })).not.toBeInTheDocument()
  })

  it('surfaces ?module= read-only, carried through unchanged', () => {
    renderWithProviders(<RegisterPage />, { route: '/register?journey=multi-client&module=paid_media', locale: 'en' })
    expect(screen.getByText(/Requested module/i)).toBeInTheDocument()
    expect(screen.getByText('paid_media')).toBeInTheDocument()
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

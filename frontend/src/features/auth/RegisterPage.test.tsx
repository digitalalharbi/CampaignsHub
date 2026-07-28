import { afterEach, describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { RegisterPage } from './RegisterPage'
import { renderWithProviders, signOut } from '@/test/utils'

describe('RegisterPage — journey handoff', () => {
  afterEach(() => signOut())

  it('presets the agency path from ?journey=agency and keeps it editable', () => {
    renderWithProviders(<RegisterPage />, { route: '/register?journey=agency', locale: 'en' })
    expect(screen.getByText('Your selected path')).toBeInTheDocument()
    // Agency card is the selected path (not a forced re-pick).
    expect(screen.getByRole('button', { name: /I manage several clients/i })).toHaveAttribute('aria-pressed', 'true')
    expect(screen.getByRole('button', { name: /I run my own campaigns/i })).toHaveAttribute('aria-pressed', 'false')
    expect(screen.getByText(/Clients and requests enabled for the agency workspace/i)).toBeInTheDocument()
  })

  it('presets the self-managed path from ?journey=self-managed with an editable account type', () => {
    renderWithProviders(<RegisterPage />, { route: '/register?journey=self-managed', locale: 'en' })
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
})

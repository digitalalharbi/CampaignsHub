import { render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, describe, expect, it } from 'vitest'
import { RequireAuth } from './RequireAuth'
import { useAuth } from '@/stores/auth'

const at = (status: 'loading' | 'authenticated' | 'guest' | 'unreachable') => {
  useAuth.setState({ status, user: null })
  render(
    <MemoryRouter initialEntries={['/switch']}>
      <Routes>
        <Route path="/login" element={<p>sign in</p>} />
        <Route element={<RequireAuth />}><Route path="/switch" element={<p>inside</p>} /></Route>
      </Routes>
    </MemoryRouter>,
  )
}

/** WORKSPACE-SWITCH-001 — hydrating, signed out and «could not tell» are three states, with three screens. */
describe('RequireAuth', () => {
  afterEach(() => useAuth.setState({ status: 'loading', user: null }))

  it('does not call an unanswered probe a sign-out', () => {
    at('unreachable')
    expect(screen.getByTestId('session-unreachable')).toBeInTheDocument()
    expect(screen.queryByText('sign in')).toBeNull()
    expect(screen.queryByText('inside')).toBeNull()
  })

  it('sends a guest to sign-in, and lets the authenticated through', () => {
    at('guest')
    expect(screen.getByText('sign in')).toBeInTheDocument()
  })

  it('waits while the probe is out', () => {
    at('loading')
    expect(screen.queryByText('sign in')).toBeNull()
    expect(screen.queryByText('inside')).toBeNull()
  })
})

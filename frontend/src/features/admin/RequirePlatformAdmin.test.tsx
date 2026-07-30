import { beforeEach, describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { Route, Routes } from 'react-router-dom'
import { RequirePlatformAdmin } from './RequirePlatformAdmin'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useAuth } from '@/stores/auth'

function renderGate(route = '/admin') {
  return renderWithProviders(
    <Routes>
      <Route element={<RequirePlatformAdmin />}>
        <Route path="/admin" element={<p>console</p>} />
      </Route>
      <Route path="/app/dashboard" element={<p>advertiser dashboard</p>} />
    </Routes>,
    { route, locale: 'en' },
  )
}

/**
 * A courtesy gate, not the boundary — every `/api/v1/admin/*` endpoint is gated server-side on
 * `is_platform_admin`, so editing the store here changes nothing about what the API will answer.
 * What it must get right is not showing a customer a console they cannot use.
 */
describe('RequirePlatformAdmin', () => {
  beforeEach(() => signOut())

  it('lets the platform owner through', () => {
    signInWith([])
    useAuth.setState((s) => ({ user: { ...s.user!, is_platform_admin: true } }))
    renderGate()

    expect(screen.getByText('console')).toBeInTheDocument()
  })

  /**
   * A tenant administrator holding every permission their workspace can grant is still not the
   * platform owner — otherwise the console would be one role edit away from any customer.
   */
  it('turns away a tenant administrator with every permission', () => {
    signInWith(['settings.manage', 'users.invite', 'billing.manage', 'clients.view_all'])
    renderGate()

    expect(screen.queryByText('console')).not.toBeInTheDocument()
    expect(screen.getByText('advertiser dashboard')).toBeInTheDocument()
  })

  /** Nothing is rendered while the session probe is still in flight — no flash of either answer. */
  it('renders nothing until the session is known', () => {
    useAuth.setState({ user: null, status: 'loading' })
    const { container } = renderGate()

    expect(container.textContent).toBe('')
  })
})

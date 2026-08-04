import { afterEach, describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { Route, Routes } from 'react-router-dom'
import { OnboardingGate } from './OnboardingGate'
import type { AuthUser } from '@/lib/api/types'
import { useAuth } from '@/stores/auth'
import { renderWithProviders, signOut } from '@/test/utils'

/**
 * The gate decides where an authenticated person may be, and a guard that cannot answer must not
 * answer "yes".
 *
 * The case these tests exist for: `account` is null. It is null for the platform owner BY DESIGN —
 * they hold no membership and have no workspace to onboard — and it is null when the workspace could
 * not be resolved for that payload at all. Reading both as "onboarded" put a brand-new customer on a
 * portal home for a workspace the payload could not even name, and left them there, because nothing
 * re-decides once the navigation has happened.
 */
function signIn(over: Partial<AuthUser> = {}) {
  useAuth.getState().setUser({
    id: 'u1', name: 'Tester', email: 't@test.dev', tenant_id: 'tenant-1',
    is_platform_admin: false, permissions: [], created_at: null,
    account: {
      account_type: 'brand', workspace_kind: 'company', enabled_modules: ['paid_media'],
      portal: 'app', module_switcher: false, nav: [], subscription_plan: 'starter',
      onboarding: { completed: true, step: 'done' },
    },
    ...over,
  } as AuthUser)
}

/** Render the gate over a couple of destinations so "where did it send them?" is readable. */
function gateAt(route: string) {
  renderWithProviders(
    <Routes>
      <Route element={<OnboardingGate />}>
        <Route path="/app/dashboard" element={<p>dashboard</p>} />
        <Route path="/switch" element={<p>switcher</p>} />
      </Route>
      <Route path="/onboarding" element={<p>onboarding</p>} />
      <Route path="/verify-email" element={<p>verify</p>} />
    </Routes>,
    { route, locale: 'en' },
  )
}

describe('OnboardingGate', () => {
  afterEach(() => signOut())

  it('lets a fully onboarded customer through', () => {
    signIn()
    gateAt('/app/dashboard')

    expect(screen.getByText('dashboard')).toBeInTheDocument()
  })

  it('sends a customer who has not finished onboarding to the wizard', () => {
    signIn({
      account: {
        account_type: 'brand', workspace_kind: 'company', enabled_modules: ['paid_media'],
        portal: 'app', module_switcher: false, nav: [], subscription_plan: 'starter',
        onboarding: { completed: false, step: 'account_type' },
      },
    } as Partial<AuthUser>)
    gateAt('/app/dashboard')

    expect(screen.getByText('onboarding')).toBeInTheDocument()
  })

  it('sends an unverified customer to verification first', () => {
    signIn({ email_verified: false } as Partial<AuthUser>)
    gateAt('/app/dashboard')

    expect(screen.getByText('verify')).toBeInTheDocument()
  })

  /**
   * The fail-open case. A payload with no account is not an onboarded customer — it is a payload
   * whose workspace could not be resolved, and `/switch` is where this codebase already sends that.
   */
  it('does not wave a customer through when the workspace could not be resolved', () => {
    signIn({ account: null } as Partial<AuthUser>)
    gateAt('/app/dashboard')

    expect(screen.queryByText('dashboard')).not.toBeInTheDocument()
    expect(screen.getByText('switcher')).toBeInTheDocument()
  })

  /** …and the switcher itself stays reachable, or that redirect would be a loop. */
  it('lets the switcher render for the same payload', () => {
    signIn({ account: null } as Partial<AuthUser>)
    gateAt('/switch')

    expect(screen.getByText('switcher')).toBeInTheDocument()
  })

  /** The platform owner holds no membership by design and has nothing to onboard. */
  it('lets the platform owner through with no account at all', () => {
    signIn({ account: null, is_platform_admin: true } as Partial<AuthUser>)
    gateAt('/app/dashboard')

    expect(screen.getByText('dashboard')).toBeInTheDocument()
  })
})

import { afterEach, describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { ReviewBanner } from './ReviewBanner'
import type { AuthUser } from '@/lib/api/types'
import { useAuth } from '@/stores/auth'
import { renderWithProviders, signOut } from '@/test/utils'

/*
 * SALLA-REVIEW-001 — the banner appears for the review workspace and for nobody else.
 */
const user = (review?: { label: string; active: boolean; expires_at: string | null } | null) =>
  useAuth.getState().setUser({
    id: 'u1', name: 'Reviewer', email: 'review@campaignshub.io', tenant_id: 't1', is_platform_admin: false, permissions: [], created_at: null,
    account: { account_type: 'self_serve_company', workspace_kind: 'company', enabled_modules: [], portal: 'app', module_switcher: false, nav: [], subscription_plan: 'salla-trial', ...(review === undefined ? {} : { review }), onboarding: { completed: true, step: 'done' } },
  } as unknown as AuthUser)

describe('the Salla Review banner', () => {
  afterEach(() => signOut())

  it('names the workspace and when it ends, as a plain status line', () => {
    user({ label: 'Salla Review', active: true, expires_at: '2026-11-03T10:00:00+00:00' })
    renderWithProviders(<ReviewBanner />, { locale: 'en' })

    const banner = screen.getByTestId('review-banner')
    expect(banner).toHaveAttribute('role', 'status')
    expect(banner).toHaveTextContent('Salla Review')
    expect(banner).toHaveTextContent('ends 2026-11-03')
  })

  it('speaks Arabic to an Arabic reader', () => {
    user({ label: 'Salla Review', active: true, expires_at: '2026-11-03T10:00:00+00:00' })
    renderWithProviders(<ReviewBanner />, { locale: 'ar' })

    expect(screen.getByTestId('review-banner')).toHaveTextContent('تنتهي 2026-11-03')
  })

  it('is absent for an ordinary workspace and when signed out', () => {
    user(null)
    const { unmount } = renderWithProviders(<ReviewBanner />, { locale: 'en' })
    expect(screen.queryByTestId('review-banner')).toBeNull()
    unmount()

    user()
    renderWithProviders(<ReviewBanner />, { locale: 'en' })
    expect(screen.queryByTestId('review-banner')).toBeNull()
  })
})

import { afterEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { SupportBanner } from './SupportBanner'
import { renderWithProviders, signOut } from '@/test/utils'
import { useAuth } from '@/stores/auth'

vi.mock('@/features/admin/api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, endSupportSession: vi.fn().mockResolvedValue({ ended: true, return_path: '/admin/tenants' }) }
})

import { endSupportSession } from '@/features/admin/api'

/** SUPERADMIN-SUPPORT-ACCESS-001 — the banner names the workspace, says read-only, and leads back to the console. */
describe('SupportBanner', () => {
  afterEach(() => signOut())

  it('says nothing outside a support visit', () => {
    renderWithProviders(<SupportBanner />, { locale: 'en' })
    expect(screen.queryByTestId('support-banner')).not.toBeInTheDocument()
  })

  it('names the workspace and returns to the console', async () => {
    const assign = vi.fn()
    vi.stubGlobal('location', { ...window.location, assign })
    renderWithProviders(<SupportBanner />, { locale: 'en' })
    const user = useAuth.getState().user
    useAuth.setState({ user: { ...(user ?? {}), support: { session_id: 's', tenant_id: 't', tenant_name: 'Acme', mode: 'read', expires_at: '2026-10-31T12:30:00Z' } } as never })

    const banner = await screen.findByTestId('support-banner')
    expect(banner).toHaveTextContent(/Support mode — Acme/)
    expect(banner).toHaveTextContent(/read-only/)

    fireEvent.click(screen.getByTestId('support-exit'))
    await waitFor(() => expect(endSupportSession).toHaveBeenCalled())
    await waitFor(() => expect(assign).toHaveBeenCalledWith('/admin/tenants'))
    vi.unstubAllGlobals()
  })
})

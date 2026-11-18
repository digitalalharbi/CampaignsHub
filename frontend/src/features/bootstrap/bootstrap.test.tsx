import { useQuery } from '@tanstack/react-query'
import { cleanup, render, waitFor } from '@testing-library/react'
import type { AxiosAdapter, InternalAxiosRequestConfig } from 'axios'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { Providers, queryClient } from '@/app/providers'
import { fetchMemberships } from '@/features/auth/memberships'
import { useFinancialDisclaimer } from '@/features/funding/fundingApi'
import { NotificationCenter } from '@/features/notifications/NotificationCenter'
import { useBranding } from '@/features/settings/api'
import { UsageMeterChip } from '@/features/subscriptions/UsageMeterChip'
import { api } from '@/lib/api/client'
import { useMyAccess } from '@/lib/accessSections'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'
import type { BootstrapPayload } from './bootstrap'

/**
 * PERF-BOOTSTRAP-001 — the first page load of an authenticated surface asks for the seven shared answers ONCE.
 *
 * The count is taken at the lowest level there is — the HTTP adapter under the real axios client — with the real `Providers`
 * boot and the REAL consumers of each answer (the portal guard's memberships query, access, branding, the usage chip, the bell,
 * the disclaimer). That is what keeps the keys `seedFromBootstrap` writes honest: a hook that read another key would ask
 * its own endpoint, and be counted here.
 */
const USER = { id: 'u1', name: 'Owner', email: 'o@t.dev', tenant_id: 't1', is_platform_admin: false, permissions: ['subscriptions.view'], created_at: null }

const FULL: BootstrapPayload = {
  me: { user: USER },
  memberships: { memberships: [], current: null, destination: '/agency/dashboard', needs_switcher: false, requested_portal: null, requested_portal_held: null } as never,
  access: { role: null, is_owner: true, sections: ['campaigns'], all_sections: [], clients: { all: true, ids: [] } },
  subscription: { subscription: null, plan: null, is_default_plan: true, usage: { projects: { limit: 10, used: 8, remaining: 2 } }, renewal: null } as never,
  branding: { branding: { logo_url: null, client_logo_url: null, primary_color: '#0d8a6f', report_accent: '#0d8a6f', portal_name: 'CampaignsHub', report_footer: null } },
  notifications_summary: { unread: 3, attention: [] },
  funding_disclaimer: { ar: 'ar text', en: 'en text' },
}

/** What each endpoint answers when the page has to ask it itself (the same documents the bootstrap carries). */
const ENDPOINT_DATA: Record<string, unknown> = {
  '/auth/me': { user: USER },
  '/auth/memberships': FULL.memberships,
  '/me/access': FULL.access,
  '/subscriptions/current': FULL.subscription,
  '/settings/branding': FULL.branding,
  '/notifications/summary': FULL.notifications_summary,
  '/funding/disclaimer': FULL.funding_disclaimer,
}

/** Every request the client sends, by path; `/bootstrap` answers with `payload`, an endpoint with its own document. */
function serve(payload: BootstrapPayload): string[] {
  const seen: string[] = []
  const adapter: AxiosAdapter = async (config: InternalAxiosRequestConfig) => {
    const path = String(config.url).split('?')[0]
    seen.push(path)
    const data = path === '/bootstrap' ? payload : (ENDPOINT_DATA[path] ?? {})

    return { data: { success: true, message: 'ok', data, meta: {}, errors: null }, status: 200, statusText: 'OK', headers: {}, config }
  }
  api.defaults.adapter = adapter

  return seen
}

/** What a portal page mounts on its first render: the guard's memberships, access, branding, the usage chip, the bell, the disclaimer. */
function Page() {
  const status = useAuth((s) => s.status)
  if (status !== 'authenticated') return <span data-testid="booting" />

  return <PageBody />
}

function PageBody() {
  useQuery({ queryKey: ['memberships'], queryFn: () => fetchMemberships(), staleTime: 60_000 })
  useMyAccess()
  useBranding()
  useFinancialDisclaimer()

  return (
    <MemoryRouter>
      <UsageMeterChip />
      <NotificationCenter />
      <span data-testid="page" />
    </MemoryRouter>
  )
}

function bootAt(path: string) {
  window.history.pushState({}, '', path)

  return render(<Providers><Page /></Providers>)
}

const SHARED = ['/me/access', '/settings/branding', '/subscriptions/current', '/notifications/summary', '/funding/disclaimer', '/auth/me', '/auth/memberships']

describe('the first load of an authenticated page', () => {
  beforeEach(() => {
    useUi.setState({ locale: 'en' })
    useAuth.setState({ status: 'loading', user: null })
    queryClient.clear()
  })
  afterEach(() => cleanup())

  it('sends one request for the seven shared answers, and none of the seven endpoints', async () => {
    const seen = serve(FULL)
    const view = bootAt('/agency/dashboard')
    await waitFor(() => expect(view.getByTestId('page')).toBeInTheDocument())
    // Let every mount-time query settle, so a late duplicate would be counted.
    await new Promise((resolve) => setTimeout(resolve, 150))

    expect(seen.filter((u) => u === '/bootstrap')).toHaveLength(1)
    for (const endpoint of SHARED) expect(seen, `${endpoint} must not be requested on load`).not.toContain(endpoint)
    expect(seen).toEqual(['/bootstrap'])
  })

  it('files the answers under the keys the hooks read', async () => {
    serve(FULL)
    const view = bootAt('/agency/dashboard')
    await waitFor(() => expect(view.getByTestId('page')).toBeInTheDocument())

    expect(queryClient.getQueryData(['me', 'access'])).toEqual(FULL.access)
    expect(queryClient.getQueryData(['settings', 'branding'])).toEqual(FULL.branding)
    expect(queryClient.getQueryData(['subscriptions', 'current'])).toEqual(FULL.subscription)
    expect(queryClient.getQueryData(['funding', 'disclaimer'])).toEqual(FULL.funding_disclaimer)
    expect(queryClient.getQueryData(['memberships'])).toEqual(FULL.memberships)
    expect(queryClient.getQueryData(['notifications', 'summary'])).toEqual({ unread: 3, attention: [], sound: undefined })
  })

  it('asks its own endpoint for a section the server withheld, and only for that one', async () => {
    const seen = serve({ ...FULL, subscription: null, funding_disclaimer: null })
    const view = bootAt('/agency/dashboard')
    await waitFor(() => expect(view.getByTestId('page')).toBeInTheDocument())
    await new Promise((resolve) => setTimeout(resolve, 150))

    expect(seen).toContain('/subscriptions/current')
    expect(seen).toContain('/funding/disclaimer')
    for (const endpoint of ['/me/access', '/settings/branding', '/notifications/summary', '/auth/me', '/auth/memberships']) {
      expect(seen, `${endpoint} was in the bootstrap`).not.toContain(endpoint)
    }
  })

  it('falls back to the old two requests when the bootstrap cannot be built', async () => {
    const seen = serve({ ...FULL, me: null })
    bootAt('/agency/dashboard')
    await waitFor(() => expect(useAuth.getState().status).toBe('authenticated'))

    expect(seen).toContain('/bootstrap')
    expect(seen).toContain('/auth/me')
    expect(seen).toContain('/auth/memberships')
  })

  it('does not use the bootstrap where there is nothing to file', async () => {
    const seen = serve(FULL)
    bootAt('/login')
    await waitFor(() => expect(useAuth.getState().status).toBe('authenticated'))

    expect(seen).not.toContain('/bootstrap')
    expect(seen).toContain('/auth/me')
  })
})

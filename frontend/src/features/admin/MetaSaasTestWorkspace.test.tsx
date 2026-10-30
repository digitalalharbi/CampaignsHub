import { describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { MetaSaasTestWorkspace } from './MetaSaasTestWorkspace'
import type { MetaSaasTestWorkspaceState } from './api'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, createMetaSaasTestWorkspace: vi.fn(), closeMetaSaasTestWorkspace: vi.fn() }
})

import { closeMetaSaasTestWorkspace, createMetaSaasTestWorkspace } from './api'

const EMAIL = 'owner-test@example.test'
const none: MetaSaasTestWorkspaceState = { exists: false, active: false, tenant_id: null, login_email: null, steps: {} }

/** META-SAAS-APP-001 — the test workspace section: created with a new address, and a journey read from the server, never assumed. */
describe('SaaS test workspace', () => {
  it('creates the workspace with the address typed, and asks for no password', async () => {
    vi.mocked(createMetaSaasTestWorkspace).mockResolvedValue({} as never)
    const { container } = renderWithProviders(<MetaSaasTestWorkspace state={none} ar={false} />, { locale: 'en' })

    expect(container.querySelector('input[type="password"]')).toBeNull()
    fireEvent.change(screen.getByTestId('meta-saas-test-workspace-email'), { target: { value: EMAIL } })
    fireEvent.click(screen.getByTestId('meta-saas-test-workspace-create'))
    await waitFor(() => expect(createMetaSaasTestWorkspace).toHaveBeenCalledWith(EMAIL))
  })

  it.each([['ar', true], ['en', false]] as const)('ticks only the journey steps the server read from rows (%s)', (_l, ar) => {
    const steps = { oauth: true, discovery: true, binding: false, sync: false }
    renderWithProviders(<MetaSaasTestWorkspace state={{ exists: true, active: true, tenant_id: 't', login_email: EMAIL, steps }} ar={ar} />, { locale: ar ? 'ar' : 'en' })

    expect(screen.getByTestId('meta-saas-test-workspace-status')).toHaveTextContent(EMAIL)
    for (const [step, done] of Object.entries(steps)) {
      expect(screen.getByTestId(`meta-saas-journey-${step}`)).toHaveAttribute('data-done', String(done))
    }
    expect(screen.getByTestId('meta-saas-journey-reports')).toHaveAttribute('data-done', 'false')
  })

  it('closes the workspace to the app when asked', async () => {
    vi.mocked(closeMetaSaasTestWorkspace).mockResolvedValue({} as never)
    renderWithProviders(<MetaSaasTestWorkspace state={{ exists: true, active: true, tenant_id: 't', login_email: EMAIL, steps: {} }} ar={false} />, { locale: 'en' })
    fireEvent.click(screen.getByTestId('meta-saas-test-workspace-close'))
    await waitFor(() => expect(closeMetaSaasTestWorkspace).toHaveBeenCalledTimes(1))
  })
})

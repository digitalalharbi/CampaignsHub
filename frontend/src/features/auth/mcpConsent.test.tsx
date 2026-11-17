import { fireEvent, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { api } from '@/lib/api/client'
import { renderWithProviders, signInWith } from '@/test/utils'
import { McpConsentPage } from './McpConsentPage'

vi.mock('@/lib/api/client', () => ({
  api: {
    get: vi.fn(async () => ({ data: { name: 'Claude' } })),
    post: vi.fn(async () => ({ data: { redirect: 'https://claude.ai/api/mcp/auth_callback?code=abc&state=s1' } })),
  },
}))

const ROUTE = '/oauth/mcp?' + new URLSearchParams({
  response_type: 'code', client_id: 'mcp_x', redirect_uri: 'https://claude.ai/api/mcp/auth_callback',
  code_challenge: 'c'.repeat(43), code_challenge_method: 'S256', state: 's1',
}).toString()

/** MCP-OAUTH-001 — «سماح» says who asks and what it may do, and sends the single-use code back to the app. */
describe('McpConsentPage', () => {
  const assign = vi.fn()
  vi.stubGlobal('location', { ...window.location, assign })

  afterEach(() => vi.clearAllMocks())

  it('names the app, lists what it may do, and «سماح» returns the code to the app', async () => {
    signInWith(['campaigns.view'])
    renderWithProviders(<McpConsentPage />, { route: ROUTE, locale: 'ar' })

    expect(await screen.findByText(/«Claude» يطلب الوصول/)).toBeInTheDocument()
    expect(screen.getByTestId('mcp-consent')).toHaveTextContent('لا يغيّر أي حملة أو ميزانية بنفسه')
    fireEvent.click(screen.getByTestId('mcp-consent-allow'))

    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/mcp/oauth/approve', expect.objectContaining({
      client_id: 'mcp_x', redirect_uri: 'https://claude.ai/api/mcp/auth_callback', code_challenge_method: 'S256', state: 's1',
    })))
    await waitFor(() => expect(assign).toHaveBeenCalledWith('https://claude.ai/api/mcp/auth_callback?code=abc&state=s1'))
  })

  it('«إلغاء» tells the app no', async () => {
    signInWith(['campaigns.view'])
    renderWithProviders(<McpConsentPage />, { route: ROUTE, locale: 'ar' })

    fireEvent.click(await screen.findByTestId('mcp-consent-deny'))
    expect(assign).toHaveBeenCalledWith('https://claude.ai/api/mcp/auth_callback?error=access_denied&state=s1')
    expect(api.post).not.toHaveBeenCalled()
  })

  it('refuses a link without PKCE', async () => {
    signInWith(['campaigns.view'])
    renderWithProviders(<McpConsentPage />, { route: '/oauth/mcp?client_id=mcp_x&redirect_uri=https://a.example/cb', locale: 'ar' })

    expect(await screen.findByTestId('mcp-consent-invalid')).toBeInTheDocument()
    expect(screen.queryByTestId('mcp-consent-allow')).toBeNull()
  })
})

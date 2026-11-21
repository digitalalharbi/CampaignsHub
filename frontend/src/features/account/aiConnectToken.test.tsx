import { fireEvent, screen, waitFor } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { renderWithProviders, signInWith } from '@/test/utils'
import { AiConnectPage } from './AiConnectPage'

const post = vi.fn(async (_u: string, body: { read_only: boolean }) => ({
  token: 'chmcp_secret',
  tokens: [{ id: '1', name: 'Claude', created_at: null, last_used_at: null, read_only: body.read_only }],
}))

vi.mock('@/lib/api/client', () => ({
  getData: vi.fn(async () => ({ tokens: [] })),
  postData: (u: string, b: { read_only: boolean }) => post(u, b),
  deleteData: vi.fn(async () => ({ tokens: [] })),
}))

/** MCP-ANALYTICS-001 — a token is read-only unless proposals are ticked on purpose; the list says which. */
describe('AiConnectPage tokens', () => {
  it('creates a read-only token by default and labels it', async () => {
    signInWith(['campaigns.view'])
    renderWithProviders(<AiConnectPage />, { locale: 'ar' })

    fireEvent.change(await screen.findByTestId('mcp-token-name'), { target: { value: 'Claude' } })
    fireEvent.click(screen.getByTestId('mcp-token-create'))

    await waitFor(() => expect(post).toHaveBeenCalledWith('/me/mcp-tokens', { name: 'Claude', read_only: true }))
    expect(await screen.findByTestId('mcp-token-mode-1')).toHaveTextContent('قراءة فقط')
    expect(screen.getByTestId('mcp-token-fresh')).toBeInTheDocument()
  })
})

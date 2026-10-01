import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import { DashboardContextStrip } from './DashboardContextStrip'
import type { ConnectionHub, HubConnection } from '@/features/integrations/api'
import { renderWithProviders } from '@/test/utils'

/**
 * DASHBOARD-CONTEXT-001 — what is feeding this screen, and whether any of it is unwell.
 *
 * The first screen could say where you are, whose project it is, which period and how fresh the
 * figures were. It could not say which platforms produced them — so a dashboard reading zero for a
 * platform looked identical whether that platform was quiet or had never been connected.
 */

const hub = vi.hoisted(() => ({ data: { connections: [], connectable: [] } as ConnectionHub }))

vi.mock('@/features/integrations/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/features/integrations/api')>()),
  fetchConnectionHub: () => Promise.resolve(hub.data),
}))

function connection(over: Partial<HubConnection> = {}): HubConnection {
  return {
    id: 'c-1',
    provider: 'meta',
    label: 'Meta Ads',
    label_ar: 'ميتا',
    connection_name: 'Meta',
    authorised_by: null,
    authorised_at: null,
    selected_accounts: 1,
    discovered_accounts: 1,
    connection_state: 'CONNECTED',
    sync_state: 'SUCCEEDED',
    needs_attention: false,
    last_success_at: null,
    next_sync_at: null,
    projects: [],
    has_parent: false,
    discovery_blocked_reason: null,
    token_expires_at: null,
    ...over,
  }
}

describe('the dashboard context strip', () => {
  beforeEach(() => { hub.data = { connections: [], connectable: [] } })

  /** It names what is CONNECTED, in the product's order — not the catalogue of what exists. */
  it('names the platforms feeding the page, in the canonical order', async () => {
    hub.data = {
      connections: [
        connection({ id: 'c-1', provider: 'openai_ads' }),
        connection({ id: 'c-2', provider: 'snapchat' }),
      ],
      connectable: [],
    }

    renderWithProviders(<DashboardContextStrip />, { locale: 'ar' })

    const strip = await screen.findByTestId('dashboard-context-strip')
    const chips = [...strip.querySelectorAll('li')].map((li) => li.textContent?.trim())

    // Snapchat precedes ChatGPT Ads in `PLATFORM_ORDER`, whatever order the connections arrived in.
    expect(chips).toEqual(['سناب شات', 'إعلانات ChatGPT'])

    // A platform the product supports and this workspace has not connected is absent: the question
    // is «what is feeding this screen», and a catalogue would be decoration.
    expect(strip.textContent).not.toContain('تيك توك')
  })

  /** The count, and the door — the thing to do about it lives on another page. */
  it('counts what needs attention and offers the way to it', async () => {
    hub.data = {
      connections: [
        connection({ id: 'c-1', provider: 'meta', needs_attention: true }),
        connection({ id: 'c-2', provider: 'snapchat' }),
      ],
      connectable: [],
    }

    renderWithProviders(<DashboardContextStrip />, { locale: 'ar' })

    const link = await screen.findByTestId('dashboard-context-attention')

    expect(link).toHaveTextContent('1')
    expect(link).toHaveAttribute('href', '/app/integrations')
  })

  /** And it says nothing about health when there is nothing wrong. */
  it('stays quiet when every connection is well', async () => {
    hub.data = { connections: [connection()], connectable: [] }

    renderWithProviders(<DashboardContextStrip />, { locale: 'ar' })

    await screen.findByTestId('dashboard-context-strip')
    expect(screen.queryByTestId('dashboard-context-attention')).toBeNull()
  })

  /**
   * **And draws nothing at all when nothing is connected.**
   *
   * A row of grey placeholders above the empty states beneath would be a second, quieter way of
   * saying the same thing — and the empty states are the ones that can offer the action.
   */
  it('draws nothing for a workspace with no connections', async () => {
    renderWithProviders(<DashboardContextStrip />, { locale: 'ar' })

    await waitFor(() => expect(screen.queryByTestId('dashboard-context-strip')).toBeNull())
  })
})

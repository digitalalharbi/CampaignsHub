import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { TabSettings } from './TabSettings'
import type { ClientDetail } from './api'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

vi.mock('./api', async (orig) => ({
  ...(await orig<Record<string, unknown>>()),
  updateSettings: vi.fn(),
  archiveClient: vi.fn(),
  restoreClient: vi.fn(),
}))

import { updateSettings } from './api'

/**
 * SAVE-FAILURE-TRUTH-001 — a save that failed said nothing at all.
 *
 * `setSaved(true)` fires only `onSuccess`, which is right — but there was no `onError` and no error
 * surface anywhere on the form. A refused save re-enabled the button and left the fields exactly as
 * typed, so the screen after a failure is indistinguishable from the screen before the click. The
 * reader's two readings are «nothing happened» and «it saved», and both are wrong.
 *
 * This is the client's own identity — display name, logo, the name and footer their REPORTS carry —
 * so a save silently lost is a client report that keeps going out under the old name.
 */
const client = (): ClientDetail => ({
  id: 'c1', name: 'Acme', slug: 'acme', status: 'active', client_status: 'active',
  mode: 'managed', archived_at: null, settings: {}, branding: {},
  can: { manage_settings: true },
} as unknown as ClientDetail)

describe('the client settings form', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    signInWith(['clients.view', 'clients.manage'])
  })
  afterEach(() => signOut())

  it('says so when the save was refused', async () => {
    vi.mocked(updateSettings).mockRejectedValue(new Error('refused'))
    renderWithProviders(<TabSettings d={client()} />, { locale: 'en' })

    fireEvent.click(screen.getByRole('button', { name: /save/i }))

    expect(await screen.findByTestId('client-settings-failed')).toBeInTheDocument()
  })

  /* And a save that worked still says THAT, rather than both at once. */
  it('says nothing about failure when the save worked', async () => {
    vi.mocked(updateSettings).mockResolvedValue(undefined as never)
    renderWithProviders(<TabSettings d={client()} />, { locale: 'en' })

    fireEvent.click(screen.getByRole('button', { name: /save/i }))

    await waitFor(() => expect(vi.mocked(updateSettings)).toHaveBeenCalled())
    expect(screen.queryByTestId('client-settings-failed')).toBeNull()
  })

  /* A retry clears the previous refusal, so a stale error cannot outlive the attempt it described. */
  it('clears the refusal when the next attempt starts', async () => {
    vi.mocked(updateSettings).mockRejectedValueOnce(new Error('refused')).mockResolvedValue(undefined as never)
    renderWithProviders(<TabSettings d={client()} />, { locale: 'en' })

    fireEvent.click(screen.getByRole('button', { name: /save/i }))
    await screen.findByTestId('client-settings-failed')

    fireEvent.click(screen.getByRole('button', { name: /save/i }))

    await waitFor(() => expect(screen.queryByTestId('client-settings-failed')).toBeNull())
  })
})

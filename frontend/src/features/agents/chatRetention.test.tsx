import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { ChatRetentionCard } from './ChatRetentionCard'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', () => ({ getChatRetention: vi.fn(), setChatRetention: vi.fn() }))
import { getChatRetention, setChatRetention } from './api'

/** AGENT-CHAT-RETENTION-001 — the manager's retention control: a limit between the server's bounds, never «forever». */
beforeEach(() => {
  vi.clearAllMocks()
  vi.mocked(getChatRetention).mockResolvedValue({ days: 90, min: 7, max: 730, default: 90 })
})

describe('ChatRetentionCard', () => {
  it('shows the current retention and states that conversations stay private', async () => {
    renderWithProviders(<ChatRetentionCard ar={false} />, { locale: 'en' })
    expect((await screen.findByTestId('retention-days') as HTMLInputElement).value).toBe('90')
    expect(screen.getByTestId('chat-retention').textContent).toMatch(/private to their authors/)
    expect((screen.getByTestId('retention-save') as HTMLButtonElement).disabled).toBe(true)
  })

  it('saves a preset', async () => {
    vi.mocked(setChatRetention).mockResolvedValue({ days: 30, min: 7, max: 730, default: 90 })
    renderWithProviders(<ChatRetentionCard ar={false} />, { locale: 'en' })
    fireEvent.click(await screen.findByTestId('retention-preset-30'))
    fireEvent.click(screen.getByTestId('retention-save'))
    await waitFor(() => expect(setChatRetention).toHaveBeenCalledWith(30))
  })

  it('refuses a value outside the bounds before sending, and offers no unlimited choice', async () => {
    renderWithProviders(<ChatRetentionCard ar={false} />, { locale: 'en' })
    const input = await screen.findByTestId('retention-days')
    for (const bad of ['3', '800', '']) {
      fireEvent.change(input, { target: { value: bad } })
      expect((screen.getByTestId('retention-save') as HTMLButtonElement).disabled).toBe(true)
      expect(screen.getByRole('alert').textContent).toMatch(/between 7 and 730/)
    }
    expect(setChatRetention).not.toHaveBeenCalled()
    expect(screen.queryByText(/forever|unlimited/i)).toBeNull()
  })
})

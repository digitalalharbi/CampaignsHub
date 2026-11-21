import { describe, expect, it, vi, beforeEach } from 'vitest'
import { screen } from '@testing-library/react'
import { renderWithProviders } from '@/test/utils'
import { NotificationTemplates } from './NotificationTemplates'

vi.mock('../api', async () => {
  const actual = await vi.importActual<typeof import('../api')>('../api')
  return { ...actual, fetchMailPreviews: vi.fn(), fetchMailPreview: vi.fn() }
})

import { fetchMailPreview, fetchMailPreviews } from '../api'

describe('message templates', () => {
  beforeEach(() => vi.clearAllMocks())

  it('does not appear for somebody the server refuses', async () => {
    vi.mocked(fetchMailPreviews).mockRejectedValue(new Error('403'))
    const { container } = renderWithProviders(<NotificationTemplates />, { locale: 'en' })

    await vi.waitFor(() => expect(fetchMailPreviews).toHaveBeenCalled())
    await vi.waitFor(() => expect(container.querySelector('[data-testid="notification-templates"]')).toBeNull())
  })

  /** EMAIL-TEST-SEND-001 — reviewing is a manager's; sending a test is the platform admin's alone. */
  it('previews in a sandboxed frame, names the template in words, and cannot send', async () => {
    vi.mocked(fetchMailPreviews).mockResolvedValue({ keys: ['alerts-one'], locales: ['ar', 'en'] })
    vi.mocked(fetchMailPreview).mockResolvedValue({ key: 'alerts-one', locale: 'en', html: '<p>x</p>' })
    renderWithProviders(<NotificationTemplates />, { locale: 'en' })

    const frame = await screen.findByTitle('alerts-one.en')
    expect(frame).toHaveAttribute('sandbox', '')
    expect(screen.getByRole('option', { name: 'Single alert' })).toBeInTheDocument()
    expect(screen.queryByTestId('template-test-send')).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /send/i })).not.toBeInTheDocument()
  })
})

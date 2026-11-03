import { describe, expect, it, vi, beforeEach } from 'vitest'
import { fireEvent, screen } from '@testing-library/react'
import { renderWithProviders } from '@/test/utils'
import { NotificationTemplates } from './NotificationTemplates'

vi.mock('../api', async () => {
  const actual = await vi.importActual<typeof import('../api')>('../api')
  return { ...actual, fetchMailPreviews: vi.fn(), fetchMailPreview: vi.fn(), sendMyTestEmail: vi.fn() }
})

import { fetchMailPreview, fetchMailPreviews, sendMyTestEmail } from '../api'

describe('message templates', () => {
  beforeEach(() => vi.clearAllMocks())

  it('does not appear for somebody the server refuses', async () => {
    vi.mocked(fetchMailPreviews).mockRejectedValue(new Error('403'))
    const { container } = renderWithProviders(<NotificationTemplates />, { locale: 'en' })

    await vi.waitFor(() => expect(fetchMailPreviews).toHaveBeenCalled())
    await vi.waitFor(() => expect(container.querySelector('[data-testid="notification-templates"]')).toBeNull())
    expect(sendMyTestEmail).not.toHaveBeenCalled()
  })

  it('previews in a sandboxed frame and says accepted, not sent', async () => {
    vi.mocked(fetchMailPreviews).mockResolvedValue({ keys: ['alerts-one'], locales: ['ar', 'en'] })
    vi.mocked(fetchMailPreview).mockResolvedValue({ key: 'alerts-one', locale: 'en', html: '<p>x</p>' })
    vi.mocked(sendMyTestEmail).mockResolvedValue({ state: 'sent', delivery_state: 'accepted', recipient: 'me@example.test' })
    renderWithProviders(<NotificationTemplates />, { locale: 'en' })

    const frame = await screen.findByTitle('alerts-one.en')
    expect(frame).toHaveAttribute('sandbox', '')

    fireEvent.click(screen.getByTestId('template-test-send'))
    expect(await screen.findByTestId('template-test-result')).toHaveTextContent('Accepted by the mail server')
    expect(sendMyTestEmail).toHaveBeenCalledWith('en', 'alerts-one')
  })
})

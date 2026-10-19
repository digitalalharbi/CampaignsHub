import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, within } from '@testing-library/react'
import { ClientFilesPage } from './ClientFilesPage'
import type { PortalFile } from './portalAccountApi'
import { renderWithProviders } from '@/test/utils'

vi.mock('./portalAccountApi', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./portalAccountApi')>()
  return { ...actual, listClientFiles: vi.fn() }
})

import { listClientFiles } from './portalAccountApi'

const file = (id: string, over: Partial<PortalFile> = {}): PortalFile => ({
  id, source: 'request', name: `ملف طويل الاسم جدًا للتأكد من القص على شاشة الهاتف ${id}.pdf`, mime: 'application/pdf', size: 1000,
  request_reference: 'REQ-2026-0042', web_view_link: `https://drive.example/${id}`, created_at: '2026-10-01T09:00:00Z', ...over,
})

/**
 * CP-MOBILE-001 — on a phone each file is a card, not a 560px table scrolled sideways: the name is
 * truncated inside the card, and the open button stays on the screen.
 */
describe('ClientFilesPage on a phone', () => {
  beforeEach(() => vi.clearAllMocks())

  it('lists every file as a card with its type, reference and open button, beside the wide table', async () => {
    vi.mocked(listClientFiles).mockResolvedValue([file('f1'), file('f2', { source: 'drive', web_view_link: null, request_reference: null })])
    renderWithProviders(<ClientFilesPage />, { locale: 'ar' })

    const cards = await screen.findByTestId('portal-files-cards')
    expect(cards.className).toMatch(/sm:hidden/)
    const first = within(cards).getByTestId('portal-file-card-f1')
    expect(first).toHaveTextContent('PDF')
    expect(first).toHaveTextContent('REQ-2026-0042')
    expect(within(first).getByRole('link', { name: /فتح/ })).toHaveAttribute('href', 'https://drive.example/f1')
    expect(within(cards).getByTestId('portal-file-card-f2').querySelector('a')).toBeNull()
    expect(first.querySelector('[dir="ltr"]')).toBeNull()
  })
})

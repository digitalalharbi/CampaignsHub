import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { ShortLinksPage } from './ShortLinksPage'
import type { ShortLink } from './api'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

vi.mock('./api', async (orig) => ({
  ...(await orig<Record<string, unknown>>()),
  listShortLinks: vi.fn(),
  createShortLink: vi.fn(),
  disableShortLink: vi.fn(),
  deleteShortLink: vi.fn(),
}))

import { createShortLink, deleteShortLink, listShortLinks } from './api'

/**
 * SHORT-LINKS-001, Owner corrections observed in Production 2026-09-11.
 *
 * Two of the three defects are on this page. The creation card sat behind a «+ إنشاء رابط مختصر»
 * button, so making a link — the only thing the page is for — cost a click before the first field.
 * And a link could be disabled but never removed: the library grew forever, and «disable» is not the
 * answer to «I made that by mistake».
 */
const link = (over: Partial<ShortLink> = {}): ShortLink => ({
  id: 's1', kind: 'link', slug: 'g6wsa7k', short_url: 'https://campaignshub.io/l/g6wsa7k',
  destination_label: 'https://campaignshub.io/', clicks: 0, is_active: true, created_at: null,
  ...over,
} as ShortLink)

describe('the short links page', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(listShortLinks).mockResolvedValue([link()])
    vi.mocked(createShortLink).mockResolvedValue(link({ id: 's2', slug: 'jm4bf2p' }) as never)
    vi.mocked(deleteShortLink).mockResolvedValue(undefined as never)
    signInWith(['campaigns.view', 'campaigns.manage'])
  })
  afterEach(() => signOut())

  /* The page exists to make a link. Asking for a click before the first field is a step for nothing. */
  it('opens with the creation form already visible', async () => {
    renderWithProviders(<ShortLinksPage />, { locale: 'ar' })

    expect(await screen.findByTestId('short-link-submit')).toBeInTheDocument()
    expect(screen.queryByTestId('short-link-create')).toBeNull()
  })

  it('creates a link from a URL without any step before it', async () => {
    renderWithProviders(<ShortLinksPage />, { locale: 'ar' })

    /* Addressed by testid, like the sibling spec: «رابط» matches both the kind and the submit. */
    fireEvent.click(await screen.findByTestId('short-link-kind-link'))
    fireEvent.change(screen.getByLabelText(/رابط/, { selector: 'input' }), { target: { value: 'https://example.com/a' } })
    fireEvent.click(screen.getByTestId('short-link-submit'))

    await waitFor(() => expect(vi.mocked(createShortLink)).toHaveBeenCalledWith('link', 'https://example.com/a'))
  })

  /* «Disable» is not «delete». A mistake has to be removable from the library. */
  it('offers delete on every row, behind a confirmation', async () => {
    renderWithProviders(<ShortLinksPage />, { locale: 'ar' })

    fireEvent.click(await screen.findByTestId('short-link-delete-s1'))

    /* Nothing is sent until the confirmation is answered. */
    expect(vi.mocked(deleteShortLink)).not.toHaveBeenCalled()

    fireEvent.click(await screen.findByTestId('short-link-delete-confirm'))

    await waitFor(() => expect(vi.mocked(deleteShortLink)).toHaveBeenCalledWith('s1'))
  })

  it('says so when a delete is refused', async () => {
    vi.mocked(deleteShortLink).mockRejectedValue(new Error('refused'))
    renderWithProviders(<ShortLinksPage />, { locale: 'ar' })

    fireEvent.click(await screen.findByTestId('short-link-delete-s1'))
    fireEvent.click(await screen.findByTestId('short-link-delete-confirm'))

    expect(await screen.findByTestId('short-link-delete-failed')).toBeInTheDocument()
  })
})

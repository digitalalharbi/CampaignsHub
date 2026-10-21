import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { ShortLinksPage } from './ShortLinksPage'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

vi.mock('./api', async (orig) => ({
  ...(await (orig() as Promise<Record<string, unknown>>)),
  listShortLinks: vi.fn(), listShortLinksWithMeta: vi.fn(),
  createShortLink: vi.fn(),
  disableShortLink: vi.fn(),
  enableShortLink: vi.fn(),
}))

import { createShortLink, enableShortLink, listShortLinksWithMeta } from './api'

/**
 * SHORT-LINKS-001 — two fields, one action, and a link to take away.
 *
 * The owner's requirement is mostly a constraint on what must NOT be here: no redirect type, no URL
 * parameters, no custom slug, no tracking settings. Those are asserted as absences, because the way
 * a simple feature stops being simple is one helpful addition at a time.
 *
 * The phone numbers below are invented. The owner gave one as an example for the placeholder, and it
 * is hardcoded nowhere — a fixture carrying a real number is how a real number reaches a screenshot.
 */
const LINK = {
  id: 'sl1',
  slug: 'k7m2ph4',
  kind: 'link' as const,
  short_url: 'https://campaignshub.io/l/k7m2ph4',
  shows: 'https://example.com/offer',
  clicks: 12,
  recorded_follows: null,
  last_clicked_at: null,
  is_active: true,
  created_at: null,
}

describe('the short-link utility', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(listShortLinksWithMeta).mockResolvedValue({ data: [], meta: {} } as never)
    signInWith(['campaigns.view', 'campaigns.update'])
  })
  afterEach(() => signOut())

  /** SHORT-LINK-RESUME-001 — a disabled link offers «تفعيل», which resumes the same address; an active one offers «إيقاف». */
  it('a disabled link is named as such and resumes at the same address', async () => {
    vi.mocked(listShortLinksWithMeta).mockResolvedValue({ data: [{ ...LINK, is_active: false }], meta: {} } as never)
    vi.mocked(enableShortLink).mockResolvedValue({ ...LINK, is_active: true } as never)
    renderWithProviders(<ShortLinksPage />, { locale: 'ar' })

    expect(await screen.findByTestId('short-link-state-sl1')).toHaveTextContent('موقوف')
    expect(screen.queryByTestId('short-link-disable-sl1')).toBeNull()
    fireEvent.click(screen.getByTestId('short-link-enable-sl1'))
    await waitFor(() => expect(enableShortLink).toHaveBeenCalledWith('sl1'))
  })

  /** SMART-LINK-001 — the extras are there when asked for, and nowhere until then. */
  it('offers a chosen slug, an expiry and UTM tags behind a closed disclosure, and sends only what was filled', async () => {
    vi.mocked(createShortLink).mockResolvedValue({ ...LINK, slug: 'summer-sale', custom_slug: true } as never)
    renderWithProviders(<ShortLinksPage />, { locale: 'en' })
    await screen.findByTestId('short-link-advanced-toggle')
    expect(screen.queryByTestId('short-link-slug')).toBeNull()

    fireEvent.click(screen.getByTestId('short-link-kind-link'))
    fireEvent.click(screen.getByTestId('short-link-advanced-toggle'))
    fireEvent.change(screen.getByTestId('short-link-slug'), { target: { value: 'Summer Sale!' } })
    expect(screen.getByTestId('short-link-slug')).toHaveValue('summersale')
    expect(screen.getByTestId('short-link-slug-preview')).toHaveTextContent('/l/summersale')
    fireEvent.change(screen.getByTestId('short-link-utm_source'), { target: { value: 'tiktok' } })
    fireEvent.change(screen.getByLabelText(/Link/, { selector: 'input' }), { target: { value: 'https://example.com/offer' } })
    fireEvent.click(screen.getByTestId('short-link-submit'))

    await waitFor(() => expect(createShortLink).toHaveBeenCalledWith('link', 'https://example.com/offer', undefined, { slug: 'summersale', utm: { utm_source: 'tiktok' } }))
  })

  it('names an expired link and shows a coming expiry on a live one', async () => {
    vi.mocked(listShortLinksWithMeta).mockResolvedValue({ data: [
      { ...LINK, id: 'a', slug: 'aaaaaaa', state: 'expired', expires_at: '2026-10-01T23:59:59+03:00' },
      { ...LINK, id: 'b', slug: 'bbbbbbb', state: 'active', expires_at: '2026-12-31T23:59:59+03:00' },
    ], meta: {} } as never)
    renderWithProviders(<ShortLinksPage />, { locale: 'ar' })
    expect(await screen.findByTestId('short-link-expired-a')).toHaveTextContent('منتهٍ')
    expect(screen.getByTestId('short-link-expiry-b')).toHaveTextContent('2026-12-31')
  })

  it('offers exactly two kinds, and nothing technical', async () => {
    renderWithProviders(<ShortLinksPage />, { locale: 'en' })

    /* The form is open on arrival — the Owner removed the step that used to gate it. */
    await screen.findByRole('button', { name: /إنشاء الرابط|Create the link/ })

    expect(await screen.findByTestId('short-link-kind-whatsapp')).toBeVisible()
    expect(screen.getByTestId('short-link-kind-link')).toBeVisible()

    /* The settings the owner ruled out, asserted as absent rather than trusted to stay away. */
    for (const banned of [/slug/i, /redirect/i, /utm/i, /parameter/i, /expiry/i, /tracking/i]) {
      expect(screen.queryByText(banned)).toBeNull()
    }
  })

  it('sends the pasted address and shows the link to copy', async () => {
    vi.mocked(createShortLink).mockResolvedValue(LINK)
    renderWithProviders(<ShortLinksPage />, { locale: 'en' })

    /* The form is open on arrival — the Owner removed the step that used to gate it. */
    await screen.findByRole('button', { name: /إنشاء الرابط|Create the link/ })
    fireEvent.click(await screen.findByTestId('short-link-kind-link'))
    fireEvent.change(screen.getByLabelText(/Link/i, { selector: 'input' }), { target: { value: 'https://example.com/offer' } })
    fireEvent.click(screen.getByTestId('short-link-submit'))

    await waitFor(() => expect(createShortLink).toHaveBeenCalledWith('link', 'https://example.com/offer'))

    const result = await screen.findByTestId('short-link-result')
    expect(result).toHaveTextContent('https://campaignshub.io/l/k7m2ph4')
    expect(screen.getByTestId('short-link-copy')).toBeVisible()
  })

  /**
   * A WhatsApp link is built from a phone number and nothing else.
   *
   * The person never sees `wa.me`: they choose WhatsApp, type a number, and the system owns the
   * destination. Asserted on what is SENT, because that is where a leaked technical decision would
   * show up first.
   */
  it('sends a phone number for WhatsApp, never a wa.me address', async () => {
    vi.mocked(createShortLink).mockResolvedValue({ ...LINK, kind: 'whatsapp', shows: '966500000009' })
    renderWithProviders(<ShortLinksPage />, { locale: 'en' })

    /* The form is open on arrival — the Owner removed the step that used to gate it. */
    await screen.findByRole('button', { name: /إنشاء الرابط|Create the link/ })
    fireEvent.change(screen.getByLabelText(/Phone number/i), { target: { value: '500000009' } })
    fireEvent.click(screen.getByTestId('short-link-submit'))

    await waitFor(() => expect(createShortLink).toHaveBeenCalled())

    const [kind, value] = vi.mocked(createShortLink).mock.calls[0]!
    expect(kind).toBe('whatsapp')
    expect(String(value)).not.toContain('wa.me')
    expect(String(value)).toContain('500000009')
  })

  /**
   * A refusal from the server is shown against the field the person typed into.
   *
   * The server decides what is safe to redirect to, so its sentence is the one that must reach the
   * screen — a generic «something went wrong» would send somebody to support over a typo.
   */
  it('shows the server’s reason on the field', async () => {
    vi.mocked(createShortLink).mockRejectedValue({
      response: { data: { errors: { value: ['The address must start with https://'] } } },
    })
    renderWithProviders(<ShortLinksPage />, { locale: 'en' })

    /* The form is open on arrival — the Owner removed the step that used to gate it. */
    await screen.findByRole('button', { name: /إنشاء الرابط|Create the link/ })
    fireEvent.click(await screen.findByTestId('short-link-kind-link'))
    fireEvent.change(screen.getByLabelText(/Link/i, { selector: 'input' }), { target: { value: 'http://example.com' } })
    fireEvent.click(screen.getByTestId('short-link-submit'))

    expect(await screen.findByText('The address must start with https://')).toBeVisible()
  })

  it('lists what a person acts on, and what they typed', async () => {
    vi.mocked(listShortLinksWithMeta).mockResolvedValue({ data: [LINK], meta: {} } as never)
    renderWithProviders(<ShortLinksPage />, { locale: 'en' })

    const row = await screen.findByTestId('short-link-k7m2ph4')
    expect(row).toHaveTextContent('https://campaignshub.io/l/k7m2ph4')
    expect(row).toHaveTextContent('https://example.com/offer')
    expect(row).toHaveTextContent('12')
  })
})

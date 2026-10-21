import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { BioPageEditor } from './BioPageEditor'
import type { ShortLink } from './api'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', async (orig) => ({ ...(await (orig() as Promise<Record<string, unknown>>)), createBioPage: vi.fn(), updateBioPage: vi.fn() }))
import { createBioPage, updateBioPage } from './api'

const link = (id: string, slug: string, kind: 'link' | 'whatsapp' | 'bio' = 'link', extra: Partial<ShortLink> = {}): ShortLink => ({
  id, slug, kind, short_url: `https://campaignshub.io/l/${slug}`, shows: `https://example.com/${slug}`, clicks: 3, recorded_follows: null, last_clicked_at: null, is_active: true, created_at: null, ...extra,
})

/** BIO-PAGE-001 — a page built from the links already in the library; every button is one of them. */
describe('the bio page editor', () => {
  beforeEach(() => vi.clearAllMocks())

  it('creates a page from chosen links with their labels and a chosen slug', async () => {
    vi.mocked(createBioPage).mockResolvedValue(link('p1', 'my-links', 'bio') as never)
    renderWithProviders(<BioPageEditor links={[link('a', 'aaaaaaa'), link('b', 'bbbbbbb', 'whatsapp')]} ar />, { locale: 'ar' })
    fireEvent.click(screen.getByTestId('bio-new'))
    fireEvent.change(screen.getByTestId('bio-title'), { target: { value: 'روابط ريسانو' } })
    fireEvent.change(screen.getByTestId('bio-slug'), { target: { value: 'My Links' } })
    fireEvent.click(screen.getByTestId('bio-pick-b'))
    fireEvent.change(screen.getByTestId('bio-label-b'), { target: { value: 'واتساب' } })
    fireEvent.click(screen.getByTestId('bio-save'))
    await waitFor(() => expect(createBioPage).toHaveBeenCalledWith({ title: 'روابط ريسانو', subtitle: null, buttons: [{ short_link_id: 'b', label: 'واتساب' }], slug: 'mylinks' }))
  })

  it('refuses an empty selection before sending, and edits an existing page without touching its slug', async () => {
    const page = link('p1', 'my-links', 'bio', { bio: { title: 'صفحة', subtitle: null, buttons: [{ short_link_id: 'a', label: null }] } })
    vi.mocked(updateBioPage).mockResolvedValue(page as never)
    renderWithProviders(<BioPageEditor links={[link('a', 'aaaaaaa'), page]} ar />, { locale: 'ar' })
    expect(screen.getByTestId('bio-page-my-links')).toHaveTextContent('صفحة')
    fireEvent.click(screen.getByTestId('bio-edit-p1'))
    expect(screen.queryByTestId('bio-slug')).toBeNull()
    fireEvent.click(screen.getByTestId('bio-pick-a'))
    fireEvent.click(screen.getByTestId('bio-save'))
    expect(screen.getByText('اختر رابطًا واحدًا على الأقل.')).toBeInTheDocument()
    expect(updateBioPage).not.toHaveBeenCalled()
    fireEvent.click(screen.getByTestId('bio-pick-a'))
    fireEvent.click(screen.getByTestId('bio-save'))
    await waitFor(() => expect(updateBioPage).toHaveBeenCalledWith('p1', { title: 'صفحة', subtitle: null, buttons: [{ short_link_id: 'a' }] }))
  })

  it('says to create a link first when the library is empty', () => {
    renderWithProviders(<BioPageEditor links={[]} ar={false} />, { locale: 'en' })
    expect(screen.getByTestId('bio-no-links')).toHaveTextContent('Create a short link first')
  })
})

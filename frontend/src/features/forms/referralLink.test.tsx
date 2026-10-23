import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { ReferralLinkModal } from './ReferralLinkModal'
import { renderWithProviders } from '@/test/utils'

vi.mock('@/lib/api/client', async (orig) => ({ ...(await orig<typeof import('@/lib/api/client')>()), getData: vi.fn() }))
import { getData } from '@/lib/api/client'

const form = (id: string, status: string) => ({ id, name: `Form ${id}`, status, slug: 's', public_url: 'u', default_owner_id: null, retention_days: null, published_version_id: null, created_at: null })

describe('the referral link', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(getData).mockImplementation((path: string) => {
      if (path.includes('/referral-link')) return Promise.resolve({ url: 'https://app.test/f/abc?ref=aaaa.bbbb' })
      return Promise.resolve([form('f1', 'published'), form('f2', 'draft')])
    })
  })

  it('offers only the project’s published forms and shows the link with the signed code for the chosen one', async () => {
    renderWithProviders(<ReferralLinkModal projectId="p1" leadId="l1" onClose={() => undefined} />, { locale: 'en' })
    const select = await screen.findByTestId('referral-form')
    expect(select).toHaveTextContent('Form f1')
    expect(select).not.toHaveTextContent('Form f2')

    fireEvent.change(select, { target: { value: 'f1' } })
    expect(await screen.findByTestId('referral-url')).toHaveTextContent('?ref=aaaa.bbbb')
    await waitFor(() => expect(vi.mocked(getData)).toHaveBeenCalledWith('/projects/p1/leads/l1/referral-link?form=f1'))
  })

  it('says so when the project has no published form', async () => {
    vi.mocked(getData).mockResolvedValue([form('f2', 'draft')])
    renderWithProviders(<ReferralLinkModal projectId="p1" leadId="l1" onClose={() => undefined} />, { locale: 'en' })
    expect(await screen.findByTestId('referral-no-forms')).toBeInTheDocument()
    expect(screen.queryByTestId('referral-form')).toBeNull()
  })
})

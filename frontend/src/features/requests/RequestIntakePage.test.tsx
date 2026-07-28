import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen } from '@testing-library/react'
import { RequestIntakePage } from './RequestIntakePage'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./api')>()
  return { ...actual, getRequestMeta: vi.fn() }
})

import { getRequestMeta } from './api'

const TYPES = [
  { key: 'paid_media_basic', module: 'paid_media', name_ar: 'إدارة إعلانات مدفوعة', name_en: 'Paid advertising management' },
  { key: 'influencer_ugc', module: 'influencer_marketing', name_ar: 'مؤثرين وUGC', name_en: 'Influencers & UGC' },
]

describe('RequestIntakePage — service handoff', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    try { localStorage.clear() } catch { /* noop */ }
    vi.mocked(getRequestMeta).mockResolvedValue({ types: TYPES })
  })
  afterEach(() => { try { localStorage.clear() } catch { /* noop */ } })

  it('preselects the influencer service from ?service=influencer-marketing and skips the service step', async () => {
    renderWithProviders(<RequestIntakePage />, { route: '/requests/new?service=influencer-marketing', locale: 'en' })
    // Service step (0) is skipped — the applicant step is shown once meta resolves.
    expect(await screen.findByText('Applicant information')).toBeInTheDocument()
    // The choice stays editable: going Back reveals step 0 with the influencer service selected.
    fireEvent.click(screen.getByRole('button', { name: /Back/i }))
    expect(await screen.findByText('Choose a service')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Influencers & UGC/i })).toHaveAttribute('aria-pressed', 'true')
    expect(screen.getByRole('button', { name: /Paid advertising management/i })).toHaveAttribute('aria-pressed', 'false')
  })

  it('preselects the paid-media service from ?service=paid-media and skips the service step', async () => {
    renderWithProviders(<RequestIntakePage />, { route: '/requests/new?service=paid-media', locale: 'en' })
    expect(await screen.findByText('Applicant information')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: /Back/i }))
    expect(await screen.findByText('Choose a service')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Paid advertising management/i })).toHaveAttribute('aria-pressed', 'true')
  })

  it('behaves as today when no ?service param is present (starts on the service step)', async () => {
    renderWithProviders(<RequestIntakePage />, { route: '/requests/new', locale: 'en' })
    expect(await screen.findByText('Choose a service')).toBeInTheDocument()
    // Service step is shown with nothing preselected (buttons render once meta resolves).
    expect(await screen.findByRole('button', { name: /Influencers & UGC/i })).toHaveAttribute('aria-pressed', 'false')
    expect(screen.getByRole('button', { name: /Paid advertising management/i })).toHaveAttribute('aria-pressed', 'false')
  })
})

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'

import { LeadsPage } from './LeadsPage'
import type { Lead } from './types'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./api')>()
  return { ...actual, listLeads: vi.fn(), convertLead: vi.fn() }
})

import { listLeads } from './api'

/**
 * LEAD-DEDUP-001 where a person can see it.
 *
 * The dedup work has been writing `canonical_lead_id` and `duplicate_reason` since it shipped, and
 * no screen ever read them — so every repeat submission looked like a separate person to anybody
 * actually using the product. «Recorded twice, counted once» is a claim a reader has to be able to
 * SEE, or it is only a claim about the database.
 *
 * The state that needs the most care is the third one. `ambiguous` is NOT a kind of duplicate: the
 * email says one person and the phone says another, so the lead was deliberately linked to neither.
 * Rendered as a duplicate it would present a refusal to guess as a resolved match; rendered as an
 * ordinary lead it would hide the one row a human should actually look at.
 */
const lead = (over: Partial<Lead>): Lead => ({
  id: 'l1', name: 'نورة', email: null, phone: null, source: 'provider', status: 'new',
  estimated_value: 0, currency: 'SAR', notes: null, tags: [], company_id: null,
  is_converted: false, converted_opportunity_id: null, converted_at: null, created_at: null,
  ...over,
})

const result = (leads: Lead[], counts: { received: number; unique: number } | null) => ({
  leads,
  counts,
  pagination: { total: leads.length, per_page: 100, current_page: 1, last_page: 1 },
})

describe('the leads list and its duplicates', () => {
  beforeEach(() => vi.clearAllMocks())

  it('states arrivals and people as two numbers', async () => {
    vi.mocked(listLeads).mockResolvedValue(result([lead({ id: 'a' })], { received: 412, unique: 389 }) as never)
    renderWithProviders(<LeadsPage />, { locale: 'en' })

    expect(await screen.findByTestId('lead-counts')).toHaveTextContent('412 received')
    expect(screen.getByTestId('lead-counts')).toHaveTextContent('389 distinct people')
  })

  /* A server that never sent the counts must not be quoted as having sent zeroes. */
  it('says nothing about counts the server did not send', async () => {
    vi.mocked(listLeads).mockResolvedValue(result([lead({ id: 'a' })], null) as never)
    renderWithProviders(<LeadsPage />, { locale: 'en' })

    await screen.findByText('نورة')
    expect(screen.queryByTestId('lead-counts')).not.toBeInTheDocument()
  })

  it('marks a duplicate as a duplicate', async () => {
    vi.mocked(listLeads).mockResolvedValue(
      result([lead({ id: 'dup', canonical_lead_id: 'orig', duplicate_reason: 'email' })], { received: 2, unique: 1 }) as never,
    )
    renderWithProviders(<LeadsPage />, { locale: 'en' })

    expect(await screen.findByTestId('lead-duplicate-dup')).toBeInTheDocument()
  })

  it('marks a conflicting identity as its own state, not as a duplicate', async () => {
    vi.mocked(listLeads).mockResolvedValue(
      result([lead({ id: 'amb', canonical_lead_id: null, duplicate_reason: 'ambiguous' })], { received: 3, unique: 3 }) as never,
    )
    renderWithProviders(<LeadsPage />, { locale: 'en' })

    expect(await screen.findByTestId('lead-ambiguous-amb')).toHaveTextContent('Conflicting identity')
    expect(screen.queryByTestId('lead-duplicate-amb')).not.toBeInTheDocument()
  })

  it('shows a canonical what it absorbed', async () => {
    vi.mocked(listLeads).mockResolvedValue(
      result([lead({ id: 'orig', duplicate_count: 3 })], { received: 4, unique: 1 }) as never,
    )
    renderWithProviders(<LeadsPage />, { locale: 'en' })

    expect(await screen.findByTestId('lead-absorbed-orig')).toHaveTextContent('+3 arrivals')
  })

  /*
   * The narrowing is a REQUEST, not a hidden row. A view that filtered the fetched page would
   * disagree with the counts beside it the moment the list is longer than one page.
   */
  it('asks the server for the distinct-people view', async () => {
    vi.mocked(listLeads).mockResolvedValue(result([lead({ id: 'a' })], { received: 2, unique: 1 }) as never)
    renderWithProviders(<LeadsPage />, { locale: 'en' })

    await screen.findByText('نورة')
    fireEvent.change(screen.getByTestId('lead-dedup-view'), { target: { value: 'unique' } })

    await waitFor(() =>
      expect(vi.mocked(listLeads)).toHaveBeenCalledWith(expect.objectContaining({ unique: 1 })),
    )
  })

  it('keeps both counts visible in the distinct-people view', async () => {
    vi.mocked(listLeads).mockResolvedValue(result([lead({ id: 'a' })], { received: 2, unique: 1 }) as never)
    renderWithProviders(<LeadsPage />, { locale: 'en' })

    await screen.findByText('نورة')
    fireEvent.change(screen.getByTestId('lead-dedup-view'), { target: { value: 'unique' } })

    await waitFor(() => expect(screen.getByTestId('lead-counts')).toHaveTextContent('2 received'))
    expect(screen.getByTestId('lead-counts')).toHaveTextContent('1 distinct people')
  })
})

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'

import { LeadsPage } from './LeadsPage'
import type { Lead } from './types'
import { renderWithProviders, signInWith } from '@/test/utils'

vi.mock('./api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./api')>()
  return { ...actual, listLeads: vi.fn(), convertLead: vi.fn() }
})

import { listLeads } from './api'

/**
 * TABLE-SERVER-PAGING-001 — the leads list asked for the first 100 rows and paged those in the browser, so a
 * project's 101st lead could not be reached and the table said «100 total». The server now owns the page.
 */
const lead = (i: number): Lead => ({
  id: `l${i}`, name: `Lead ${i}`, email: null, phone: null, source: 'provider', status: 'new', estimated_value: 0, currency: 'SAR',
  notes: null, tags: [], company_id: null, is_converted: false, converted_opportunity_id: null, converted_at: null, created_at: null,
})
const page = (rows: Lead[], total: number, current = 1, perPage = 10) => ({
  leads: rows, counts: { received: total, unique: total }, stages: null, stagesIgnoreStatusFilter: false, inflow: null,
  pagination: { total, per_page: perPage, current_page: current, last_page: Math.ceil(total / perPage) },
})

describe('the leads table', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    signInWith(['leads.view', 'leads.pii.view'])
    vi.mocked(listLeads).mockImplementation(async (p) => page(Array.from({ length: 10 }, (_, i) => lead(i + 1 + ((p.page ?? 1) - 1) * 10)), 250, p.page ?? 1, p.per_page ?? 10))
  })

  it('asks the server for one page of 10 and states the whole total', async () => {
    renderWithProviders(<LeadsPage />, { route: '/agency/leads', locale: 'en' })
    expect(await screen.findByText('Lead 1')).toBeInTheDocument()
    expect(listLeads).toHaveBeenLastCalledWith(expect.objectContaining({ page: 1, per_page: 10 }))
    expect(screen.getByTestId('table-total').textContent).toContain('250')
  })

  it('reads a row past the old 100-row wall from the server', async () => {
    renderWithProviders(<LeadsPage />, { route: '/agency/leads', locale: 'en' })
    await screen.findByText('Lead 1')
    fireEvent.click(screen.getByRole('button', { name: /25$/ }))
    expect(await screen.findByText('Lead 241')).toBeInTheDocument()
    expect(listLeads).toHaveBeenLastCalledWith(expect.objectContaining({ page: 25 }))
  })

  it('sends a sort and a search to the server and goes back to page one', async () => {
    renderWithProviders(<LeadsPage />, { route: '/agency/leads', locale: 'en' })
    await screen.findByText('Lead 1')
    fireEvent.click(screen.getByRole('button', { name: /25$/ }))
    await screen.findByText('Lead 241')
    const search = screen.getAllByRole('textbox').find((el) => el.getAttribute('aria-label')?.toLowerCase().includes('search'))!
    fireEvent.change(search, { target: { value: 'nora' } })
    await waitFor(() => expect(listLeads).toHaveBeenLastCalledWith(expect.objectContaining({ search: 'nora', page: 1 })))
  })

  it('does not offer a search the server would refuse a reader who may not see identities', async () => {
    signInWith(['leads.view'])
    renderWithProviders(<LeadsPage />, { route: '/agency/leads', locale: 'en' })
    await screen.findByText('Lead 1')
    expect(screen.queryAllByRole('textbox').filter((el) => el.getAttribute('aria-label')?.toLowerCase().includes('search'))).toHaveLength(0)
  })
})

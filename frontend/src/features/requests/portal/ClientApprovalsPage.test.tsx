import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { ClientApprovalsPage } from './ClientApprovalsPage'
import type { PortalContentApproval } from './portalAccountApi'
import { renderWithProviders } from '@/test/utils'

vi.mock('./portalAccountApi', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./portalAccountApi')>()
  return {
    ...actual,
    listPortalContentApprovals: vi.fn(),
    listPortalQuotes: vi.fn(),
    approvePortalContent: vi.fn(),
    requestPortalContentChanges: vi.fn(),
  }
})
vi.mock('./portalOverviewApi', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./portalOverviewApi')>()
  return { ...actual, fetchPortalOverview: vi.fn().mockResolvedValue(null), fetchPortalVisibility: vi.fn().mockResolvedValue([]) }
})

import { approvePortalContent, listPortalContentApprovals, listPortalQuotes, requestPortalContentChanges } from './portalAccountApi'

const ask = (over: Partial<PortalContentApproval> = {}): PortalContentApproval => ({
  id: 'a1', title: 'Ramadan story v2', caption: 'Offer copy', status: 'pending', format: 'image', thumbnail_url: null,
  decision_note: null, decided_at: null, created_at: '2026-11-12T09:00:00Z', can_decide: true, ...over,
})

/**
 * CLIENT-PORTAL-THREE-SECTIONS-001 / CLIENT-CONTENT-APPROVAL-001 — «بانتظار اعتمادك»: content and open quotes on
 * one screen; the buttons follow the server's `can_decide`; asking for a change needs a note.
 */
describe('ClientApprovalsPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(listPortalQuotes).mockResolvedValue({ items: [
      { id: '7', number: 'QUO-7', currency: 'SAR', subtotal: '100', tax: '15', tax_treatment: 'basic_15', discount: '0', total: '115', line_items: [], status: 'sent', valid_until: null, created_at: null },
      { id: '8', number: 'QUO-8', currency: 'SAR', subtotal: '100', tax: '15', tax_treatment: 'basic_15', discount: '0', total: '115', line_items: [], status: 'approved', valid_until: null, created_at: null },
    ] } as never)
  })

  it('lists open content and only the quotes still waiting, each quote opening its existing page', async () => {
    vi.mocked(listPortalContentApprovals).mockResolvedValue({ items: [ask()] })
    renderWithProviders(<ClientApprovalsPage />, { locale: 'en', route: '/portal/razzah/approvals' })

    expect(await screen.findByText('Ramadan story v2')).toBeInTheDocument()
    const quotes = await screen.findAllByTestId('approvals-quote')
    expect(quotes).toHaveLength(1)
    expect(quotes[0]).toHaveAttribute('href', '/portal/razzah/quotes/7')
  })

  it('offers no buttons where the server says this reader cannot decide', async () => {
    vi.mocked(listPortalContentApprovals).mockResolvedValue({ items: [ask({ can_decide: false })] })
    renderWithProviders(<ClientApprovalsPage />, { locale: 'en' })

    expect(await screen.findByText('Ramadan story v2')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Approve/ })).toBeNull()
    expect(screen.queryByRole('button', { name: /Request changes/ })).toBeNull()
  })

  it('approves, and asks for a change only with a note', async () => {
    vi.mocked(listPortalContentApprovals).mockResolvedValue({ items: [ask()] })
    vi.mocked(approvePortalContent).mockResolvedValue(ask({ status: 'approved', can_decide: false }))
    vi.mocked(requestPortalContentChanges).mockResolvedValue(ask({ status: 'changes_requested', can_decide: false }))
    renderWithProviders(<ClientApprovalsPage />, { locale: 'en' })

    fireEvent.click(await screen.findByRole('button', { name: /Approve/ }))
    await waitFor(() => expect(approvePortalContent).toHaveBeenCalledWith('a1'))

    fireEvent.click(screen.getByRole('button', { name: /Request changes/ }))
    const send = screen.getByRole('button', { name: /Send/ })
    expect(send).toBeDisabled()
    fireEvent.change(screen.getByLabelText('What should change?'), { target: { value: 'Use the new logo' } })
    fireEvent.click(send)
    await waitFor(() => expect(requestPortalContentChanges).toHaveBeenCalledWith('a1', 'Use the new logo'))
  })

  it('says so plainly when nothing is waiting', async () => {
    vi.mocked(listPortalContentApprovals).mockResolvedValue({ items: [] })
    vi.mocked(listPortalQuotes).mockResolvedValue({ items: [] })
    renderWithProviders(<ClientApprovalsPage />, { locale: 'ar' })

    expect(await screen.findByTestId('approvals-empty')).toHaveTextContent('لا شيء بانتظارك الآن.')
  })
})

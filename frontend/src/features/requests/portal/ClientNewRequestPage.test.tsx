import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { ClientNewRequestPage } from './ClientNewRequestPage'
import { renderWithProviders } from '@/test/utils'

vi.mock('../clientPortalApi', async (orig) => ({ ...(await orig<typeof import('../clientPortalApi')>()), createPortalRequest: vi.fn(), portalStartUpload: vi.fn(), portalUploadFile: vi.fn() }))
vi.mock('../api', async (orig) => ({ ...(await orig<typeof import('../api')>()), getRequestMeta: vi.fn() }))
vi.mock('./portalOverviewApi', async (orig) => ({
  ...(await orig<typeof import('./portalOverviewApi')>()),
  fetchPortalVisibility: vi.fn(),
  fetchPortalOverview: vi.fn(),
}))

vi.mock('./portalAccountApi', async (orig) => ({
  ...(await orig<typeof import('./portalAccountApi')>()),
  listClientReports: vi.fn(),
  listPortalInvoices: vi.fn(),
}))

import { createPortalRequest, portalStartUpload, portalUploadFile } from '../clientPortalApi'
import { getRequestMeta } from '../api'
import { fetchPortalOverview, fetchPortalVisibility } from './portalOverviewApi'
import { listClientReports, listPortalInvoices } from './portalAccountApi'

const space = (create_requests: boolean) => [{ slug: 'razzah', mode: 'managed', visibility: {}, capabilities: { create_requests } }]

describe('ClientNewRequestPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(getRequestMeta).mockResolvedValue({ types: [{ key: 'consulting', module: 'consulting', name_ar: 'استشارة', name_en: 'Consulting' }] })
    vi.mocked(fetchPortalOverview).mockResolvedValue({
      space: { slug: 'razzah', name: 'Razzah', mode: 'managed' }, period: { from: '', to: '', days: 30 }, currency: null,
      freshness: { state: null, last_sync_at: null }, kpis: {}, platforms: [], campaigns: null,
      projects: [{ id: 'p1', name: 'Alpha', status: 'active' }],
    })
  })

  it('sends the type, project, priority and details, and nothing that names a client', async () => {
    vi.mocked(fetchPortalVisibility).mockResolvedValue(space(true))
    vi.mocked(createPortalRequest).mockResolvedValue({ reference: 'REQ-2026-ABC123', status: 'new', submitted_at: null })
    renderWithProviders(<ClientNewRequestPage />, { locale: 'en', route: '/portal/razzah/requests/new' })

    const submit = await screen.findByTestId('new-request-submit')
    expect(submit).toBeDisabled()

    fireEvent.change(await screen.findByTestId('new-request-type'), { target: { value: 'consulting' } })
    fireEvent.change(await screen.findByTestId('new-request-project'), { target: { value: 'p1' } })
    fireEvent.change(screen.getByTestId('new-request-message'), { target: { value: 'Please review the June plan.' } })
    expect(submit).toBeEnabled()
    fireEvent.click(submit)

    await waitFor(() => expect(createPortalRequest).toHaveBeenCalledTimes(1))
    expect(vi.mocked(createPortalRequest).mock.calls[0][0]).toEqual({
      type: 'consulting', message: 'Please review the June plan.', priority: 'medium', project_id: 'p1',
    })
  })

  /** CP-REQ-ONE-001 — a file goes with the new request: uploaded first, claimed by the request through its session token. */
  it('attaches a file to the new request', async () => {
    vi.mocked(fetchPortalVisibility).mockResolvedValue(space(true))
    vi.mocked(portalStartUpload).mockResolvedValue({ upload_token: 'tok-1', expires_at: '2026-11-02T00:00:00Z' })
    vi.mocked(portalUploadFile).mockResolvedValue({ id: 7, original_name: 'brief.pdf' })
    vi.mocked(createPortalRequest).mockResolvedValue({ reference: 'REQ-2026-ABC124', status: 'new', submitted_at: null })
    renderWithProviders(<ClientNewRequestPage />, { locale: 'en', route: '/portal/razzah/requests/new' })

    await screen.findByRole('option', { name: 'Consulting' })
    fireEvent.change(screen.getByTestId('new-request-type'), { target: { value: 'consulting' } })
    fireEvent.change(screen.getByTestId('new-request-message'), { target: { value: 'Brief attached for June.' } })
    fireEvent.change(screen.getByTestId('new-request-attach'), { target: { files: [new File(['x'], 'brief.pdf', { type: 'application/pdf' })] } })

    expect(await screen.findByTestId('new-request-attached')).toHaveTextContent('brief.pdf')
    // Sending waits for the upload to finish — a request must never leave without the file the client chose.
    await waitFor(() => expect(screen.getByTestId('new-request-submit')).toBeEnabled())
    fireEvent.click(screen.getByTestId('new-request-submit'))
    await waitFor(() => expect(createPortalRequest).toHaveBeenCalledTimes(1))
    expect(vi.mocked(createPortalRequest).mock.calls[0][0]).toMatchObject({ upload_token: 'tok-1', message: 'Brief attached for June.' })
  })

  it('attaches the report or invoice the request is about, and offers no campaign picker', async () => {
    vi.mocked(fetchPortalVisibility).mockResolvedValue(space(true))
    vi.mocked(listClientReports).mockResolvedValue([{ id: 'r1', name: 'June performance' }] as never)
    vi.mocked(listPortalInvoices).mockResolvedValue({ items: [{ id: 'i1', number: 'INV-2026-014' }] } as never)
    vi.mocked(createPortalRequest).mockResolvedValue({ reference: 'REQ-2026-DEF456', status: 'new', submitted_at: null })
    renderWithProviders(<ClientNewRequestPage />, { locale: 'en', route: '/portal/razzah/requests/new' })

    await screen.findByRole('option', { name: 'Consulting' })
    fireEvent.change(screen.getByTestId('new-request-type'), { target: { value: 'consulting' } })
    fireEvent.change(screen.getByTestId('new-request-message'), { target: { value: 'The June report looks off.' } })
    const kind = screen.getByTestId('new-request-related-kind')
    expect(Array.from((kind as HTMLSelectElement).options).map((o) => o.value)).toEqual(['', 'report', 'invoice'])
    fireEvent.change(kind, { target: { value: 'report' } })
    await screen.findByRole('option', { name: 'June performance' })
    fireEvent.change(screen.getByTestId('new-request-related'), { target: { value: 'r1' } })
    await waitFor(() => expect(screen.getByTestId('new-request-submit')).toBeEnabled())
    fireEvent.click(screen.getByTestId('new-request-submit'))

    await waitFor(() => expect(createPortalRequest).toHaveBeenCalledTimes(1))
    expect(vi.mocked(createPortalRequest).mock.calls[0][0]).toEqual({
      type: 'consulting', message: 'The June report looks off.', priority: 'medium', related: { kind: 'report', id: 'r1' },
    })
  })

  it('says so, and offers no form, when the agency has switched new requests off', async () => {
    vi.mocked(fetchPortalVisibility).mockResolvedValue(space(false))
    renderWithProviders(<ClientNewRequestPage />, { locale: 'en', route: '/portal/razzah/requests/new' })

    expect(await screen.findByTestId('requests-disabled')).toBeInTheDocument()
    expect(screen.queryByTestId('new-request-submit')).not.toBeInTheDocument()
  })
})

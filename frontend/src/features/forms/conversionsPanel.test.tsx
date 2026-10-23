import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { ConversionsPanel } from './ConversionsPanel'
import { renderWithProviders } from '@/test/utils'

vi.mock('@/lib/api/client', async (orig) => ({ ...(await orig<typeof import('@/lib/api/client')>()), getData: vi.fn(), getEnvelope: vi.fn(), postData: vi.fn(), patchData: vi.fn(), deleteData: vi.fn() }))
import { deleteData, getData, getEnvelope, patchData, postData } from '@/lib/api/client'

const destination = (over: Record<string, unknown> = {}) => ({
  id: 'd1', kind: 'ga4', name: 'GA4 main', status: 'test', lead_form_id: null, config: { measurement_id: 'G-ABC123XYZ9', event_name: 'generate_lead' },
  secrets_set: { api_secret: true }, missing_for_live: [], went_live_at: null, last_7_days: { sent: 4, failed: 1, skipped_consent: 2, test: 3 }, last_error: { error: 'HTTP 403 ***', at: '2026-10-23T10:00:00Z' }, ...over,
})

describe('the conversion destinations screen', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(getEnvelope).mockResolvedValue({ success: true, message: '', data: [destination()], meta: {} } as never)
    vi.mocked(getData).mockImplementation((path: string) => {
      if (path.includes('conversion-deliveries')) return Promise.resolve([{ id: 'x1', destination_id: 'd1', event_name: 'generate_lead', event_id: 'e', status: 'failed', attempts: 5, response_code: 403, error: 'HTTP 403 ***', created_at: null }])
      if (path.endsWith('/forms')) return Promise.resolve([{ id: 'f1', name: 'Villa enquiry', status: 'published', slug: 's', public_url: 'u', default_owner_id: null, retention_days: null, published_version_id: null, created_at: null }])
      return Promise.resolve([])
    })
  })

  it('says a destination in test sends nothing, shows the last week by outcome and the last error, and never a secret', async () => {
    renderWithProviders(<ConversionsPanel projectId="p1" />)
    expect(await screen.findByTestId('destination-status-d1')).toHaveTextContent('Test — sends nothing')
    expect(screen.getByTestId('destination-counts-d1')).toHaveTextContent('Last 7 days: sent 4 · failed 1 · no consent 2 · test 3')
    expect(screen.getByTestId('destination-last-error-d1')).toHaveTextContent('HTTP 403 ***')
    expect(screen.getByTestId('planned-kinds')).toHaveTextContent('TikTok Events API')
    expect(document.body.textContent).not.toMatch(/SECRET/)
  })

  it('goes live only after an explicit confirmation, and sends confirm_live with it', async () => {
    vi.mocked(patchData).mockResolvedValue(destination({ status: 'live' }))
    renderWithProviders(<ConversionsPanel projectId="p1" />)
    fireEvent.click(await screen.findByTestId('destination-golive-d1'))
    const go = screen.getByTestId('destination-live-d1') as HTMLButtonElement
    expect(go.disabled).toBe(true)
    fireEvent.click(screen.getByTestId('destination-confirm-box-d1'))
    fireEvent.click(go)
    await waitFor(() => expect(patchData).toHaveBeenCalledWith('/projects/p1/conversion-destinations/d1', { status: 'live', confirm_live: true }))
  })

  it('shows the server’s reason when going live is refused', async () => {
    vi.mocked(patchData).mockRejectedValue({ isAxiosError: true, response: { data: { message: 'Add the missing credential before going live: api_secret.' } } })
    renderWithProviders(<ConversionsPanel projectId="p1" />)
    fireEvent.click(await screen.findByTestId('destination-golive-d1'))
    fireEvent.click(screen.getByTestId('destination-confirm-box-d1'))
    fireEvent.click(screen.getByTestId('destination-live-d1'))
    expect(await screen.findByTestId('destination-action-error-d1')).toHaveTextContent('missing credential')
  })

  it('diagnoses from a synthetic lead and shows the payload and what was or was not sent', async () => {
    vi.mocked(postData).mockResolvedValue({ synthetic: true, missing_credentials: [], validation: null, note: 'Nothing was sent. The payload above is what would be built.', payload: { url: 'u', body: { events: [{ name: 'generate_lead' }] } } })
    renderWithProviders(<ConversionsPanel projectId="p1" />)
    fireEvent.click(await screen.findByTestId('destination-diagnose-d1'))
    const box = await screen.findByTestId('destination-diagnosis-d1')
    expect(box).toHaveTextContent('Nothing was sent. The payload below')
    expect(box).toHaveTextContent('generate_lead')
  })

  it('creates a destination in test mode with its settings and secret, and lets a failed delivery be retried', async () => {
    vi.mocked(postData).mockResolvedValue(destination())
    renderWithProviders(<ConversionsPanel projectId="p1" />)
    fireEvent.click(await screen.findByTestId('destination-add'))
    fireEvent.change(screen.getByTestId('destination-name'), { target: { value: 'GA4 main' } })
    fireEvent.change(screen.getByTestId('destination-config-measurement_id'), { target: { value: 'G-ABC123XYZ9' } })
    fireEvent.change(screen.getByTestId('destination-secret-api_secret'), { target: { value: 'shh' } })
    fireEvent.click(screen.getByTestId('destination-save'))
    await waitFor(() => expect(postData).toHaveBeenCalledWith('/projects/p1/conversion-destinations', { kind: 'ga4', name: 'GA4 main', lead_form_id: null, config: { measurement_id: 'G-ABC123XYZ9' }, secrets: { api_secret: 'shh' } }))

    vi.mocked(postData).mockResolvedValue({})
    fireEvent.click(await screen.findByTestId('delivery-retry-x1'))
    await waitFor(() => expect(postData).toHaveBeenCalledWith('/projects/p1/conversion-deliveries/x1/retry', {}))
  })

  it('deletes a destination', async () => {
    vi.mocked(deleteData).mockResolvedValue(null as never)
    renderWithProviders(<ConversionsPanel projectId="p1" />)
    fireEvent.click(await screen.findByTestId('destination-delete-d1'))
    await waitFor(() => expect(deleteData).toHaveBeenCalledWith('/projects/p1/conversion-destinations/d1'))
  })

  it('a web GTM destination has nothing to deliver, so it shows no delivery counts or diagnose', async () => {
    vi.mocked(getEnvelope).mockResolvedValue({ success: true, message: '', data: [destination({ id: 'g1', kind: 'gtm_web', name: 'Web', config: { container_id: 'GTM-ABC1234' }, secrets_set: {}, last_error: null })], meta: {} } as never)
    renderWithProviders(<ConversionsPanel projectId="p1" />)
    await screen.findByTestId('destination-g1')
    expect(screen.queryByTestId('destination-counts-g1')).toBeNull()
    expect(screen.queryByTestId('destination-diagnose-g1')).toBeNull()
  })
})

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { PublicFormPage } from './PublicFormPage'
import { starterDefinition } from './builderState'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', async (orig) => ({ ...(await orig<Record<string, unknown>>()), fetchPublicForm: vi.fn(), submitPublicForm: vi.fn(), sendFunnelBeacon: vi.fn() }))
import { fetchPublicForm, sendFunnelBeacon, submitPublicForm } from './api'

const fill = async () => {
  fireEvent.change(await screen.findByTestId('field-full_name'), { target: { value: 'Sara' } })
  fireEvent.change(screen.getByTestId('field-mobile'), { target: { value: '0500000001' } })
  fireEvent.click(screen.getByTestId('form-consent'))
}

describe('the public form page', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(fetchPublicForm).mockResolvedValue({ name: 'Villa enquiry', version: 1, definition: starterDefinition() })
  })

  it('sends one submission carrying the attribution it was opened with and thanks the person', async () => {
    vi.mocked(submitPublicForm).mockResolvedValue({ accepted: true, duplicate: false })
    // The page reads the address the browser actually has, so that is what the test sets.
    window.history.pushState({}, '', '/f/abcdefgh1234?utm_source=meta&gclid=Cj0KCQiA-1&secret=1')
    renderWithProviders(<PublicFormPage />, { route: '/f/abcdefgh1234', path: '/f/:slug' })
    await fill()
    fireEvent.click(screen.getByTestId('form-next'))

    await screen.findByTestId('public-form-thanks')
    const [slug, payload] = vi.mocked(submitPublicForm).mock.calls[0]
    expect(slug).toBe('abcdefgh1234')
    expect(payload.answers).toEqual({ full_name: 'Sara', mobile: '0500000001' })
    expect(payload.consent).toEqual({ accepted: true })
    expect(payload.attribution).toMatchObject({ utm_source: 'meta', gclid: 'Cj0KCQiA-1', landing_path: '/f/abcdefgh1234' })
    expect(payload.attribution).not.toHaveProperty('secret')
    expect(payload.submission_id).toMatch(/^[a-f0-9]{32}$/)
    expect(payload.website).toBe('')
  })

  it('keeps the same submission id when sending again after a failure', async () => {
    vi.mocked(submitPublicForm).mockRejectedValueOnce(new Error('network')).mockResolvedValueOnce({ accepted: true, duplicate: false })
    renderWithProviders(<PublicFormPage />, { route: '/f/abcdefgh1234', path: '/f/:slug' })
    await fill()
    fireEvent.click(screen.getByTestId('form-next'))
    await screen.findByTestId('form-server-error')
    fireEvent.click(screen.getByTestId('form-next'))
    await waitFor(() => expect(submitPublicForm).toHaveBeenCalledTimes(2))

    const [a, b] = vi.mocked(submitPublicForm).mock.calls.map((c) => c[1].submission_id)
    expect(a).toBe(b)
  })

  it('passes a filled honeypot through for the server to refuse', async () => {
    vi.mocked(submitPublicForm).mockResolvedValue({ accepted: true, duplicate: false })
    renderWithProviders(<PublicFormPage />, { route: '/f/abcdefgh1234', path: '/f/:slug' })
    await fill()
    fireEvent.change(screen.getByTestId('honeypot'), { target: { value: 'http://spam.test' } })
    fireEvent.click(screen.getByTestId('form-next'))
    await waitFor(() => expect(submitPublicForm).toHaveBeenCalled())
    expect(vi.mocked(submitPublicForm).mock.calls[0][1].website).toBe('http://spam.test')
  })

  it('says the form is not available when the server does not know it', async () => {
    vi.mocked(fetchPublicForm).mockRejectedValue(new Error('404'))
    renderWithProviders(<PublicFormPage />, { route: '/f/abcdefgh1234', path: '/f/:slug' })
    expect(await screen.findByTestId('public-form-missing')).toBeInTheDocument()
  })

  it('reports the visit as it happens: viewed, started, step done, tried to send — one session, consent only once given', async () => {
    vi.mocked(submitPublicForm).mockResolvedValue({ accepted: true, duplicate: false })
    window.history.pushState({}, '', '/f/abcdefgh1234?utm_source=meta&gclid=Cj0KCQiA-1')
    renderWithProviders(<PublicFormPage />, { route: '/f/abcdefgh1234', path: '/f/:slug' })
    await fill()
    fireEvent.click(screen.getByTestId('form-next'))
    await screen.findByTestId('public-form-thanks')

    const beacons = vi.mocked(sendFunnelBeacon).mock.calls.map((c) => c[1])
    expect(beacons.map((b) => b.event)).toEqual(['form_view', 'form_start', 'step_completed', 'submit_attempt'])
    expect(new Set(beacons.map((b) => b.session_id)).size).toBe(1)
    expect(new Set(beacons.map((b) => b.event_id)).size).toBe(4)
    // The page was viewed and the form started before the box was ticked; the step and the attempt came after.
    expect(beacons.map((b) => b.consent)).toEqual(['unknown', 'unknown', 'granted', 'granted'])
    expect(beacons[2].step).toBe(1)
    expect(beacons[0].attribution).toMatchObject({ utm_source: 'meta', gclid: 'Cj0KCQiA-1' })
  })
})

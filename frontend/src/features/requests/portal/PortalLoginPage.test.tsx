import axios from 'axios'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { PortalLoginPage } from './PortalLoginPage'
import { renderWithProviders } from '@/test/utils'

vi.mock('@/features/requests/clientPortalApi', async (orig) => ({
  ...(await (orig() as Promise<Record<string, unknown>>)),
  portalLoginStart: vi.fn(),
  portalLoginVerify: vi.fn(),
}))

import { portalLoginStart, portalLoginVerify } from '@/features/requests/clientPortalApi'

const QUEUED = { verification_id: 'v1', delivery_status: 'queued', resend_after: 60, dev_code: null }

async function ask(address: string) {
  fireEvent.change(screen.getByLabelText(/Email address/i), { target: { value: address } })
  fireEvent.click(screen.getByTestId('portal-login-send'))
  await screen.findByTestId('portal-login-code')
}

const typeCode = () => fireEvent.paste(screen.getByTestId('login-otp-0'), { clipboardData: { getData: () => '424242' } })

/** LOGIN-NO-EMAIL-CODE-001 — the client contact's door; who is actually mailed is the server's decision (`PortalEmailSignInTest`). */
describe('PortalLoginPage', () => {
  afterEach(() => vi.clearAllMocks())

  it('sends a contact a code through the portal engine', async () => {
    vi.mocked(portalLoginStart).mockResolvedValue(QUEUED)
    renderWithProviders(<PortalLoginPage />, { route: '/portal/login', locale: 'en' })

    await ask('client@example.test')

    expect(portalLoginStart).toHaveBeenCalledWith('email', 'client@example.test')
    expect(screen.getByTestId('portal-login-destination')).toHaveTextContent('client@example.test')
    expect(screen.queryByTestId('portal-login-undelivered')).not.toBeInTheDocument()
  })

  it('states a refusal instead of looking like a button that did nothing', async () => {
    vi.mocked(portalLoginStart).mockRejectedValue(new axios.AxiosError('refused', undefined, undefined, undefined, { status: 422, statusText: '', headers: {}, config: {} as never, data: { message: 'refused', errors: { channel: ['That is not available.'] } } }))
    renderWithProviders(<PortalLoginPage />, { route: '/portal/login', locale: 'en' })

    fireEvent.change(screen.getByLabelText(/Email address/i), { target: { value: 'client@example.test' } })
    fireEvent.click(screen.getByTestId('portal-login-send'))

    expect(await screen.findByTestId('login-error')).toHaveTextContent('That is not available.')
  })

  it('says plainly when no code was actually sent', async () => {
    vi.mocked(portalLoginStart).mockResolvedValue({ ...QUEUED, delivery_status: 'awaiting_provider_credentials' })
    renderWithProviders(<PortalLoginPage />, { route: '/portal/login', locale: 'en' })

    await ask('client@example.test')

    expect(screen.getByTestId('portal-login-undelivered')).toBeInTheDocument()
  })

  it('closes the resend until the cooldown has run, and goes back on request', async () => {
    vi.mocked(portalLoginStart).mockResolvedValue(QUEUED)
    renderWithProviders(<PortalLoginPage />, { route: '/portal/login', locale: 'en' })

    await ask('client@example.test')
    expect(screen.getByTestId('portal-login-resend')).toBeDisabled()

    fireEvent.click(screen.getByTestId('portal-login-change'))
    expect(screen.getByTestId('portal-login-email-form')).toBeInTheDocument()
    expect(screen.getByLabelText(/Email address/i)).toHaveValue('client@example.test')
  })

  it('verifies the code it was given', async () => {
    vi.mocked(portalLoginStart).mockResolvedValue(QUEUED)
    vi.mocked(portalLoginVerify).mockResolvedValue({ authenticated: true, contact: 'client@example.test', dev_token: null })
    renderWithProviders(<PortalLoginPage />, { route: '/portal/login?redirect=https%3A%2F%2Fevil.example', locale: 'en' })

    await ask('client@example.test')
    typeCode()

    await waitFor(() => expect(portalLoginVerify).toHaveBeenCalledWith('v1', '424242'))
  })
})

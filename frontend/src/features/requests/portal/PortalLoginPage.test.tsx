import { afterEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { PortalLoginPage } from './PortalLoginPage'
import { renderWithProviders } from '@/test/utils'

vi.mock('@/features/auth/api', async (orig) => ({ ...(await (orig() as Promise<Record<string, unknown>>)), signInMethod: vi.fn() }))
vi.mock('@/features/requests/clientPortalApi', async (orig) => ({
  ...(await (orig() as Promise<Record<string, unknown>>)),
  portalLoginStart: vi.fn(),
  portalLoginVerify: vi.fn(),
}))

import { signInMethod } from '@/features/auth/api'
import { portalLoginStart, portalLoginVerify } from '@/features/requests/clientPortalApi'

const QUEUED = { verification_id: 'v1', delivery_status: 'queued', resend_after: 60, dev_code: null }

async function ask(address: string) {
  fireEvent.change(screen.getByLabelText(/Email address/i), { target: { value: address } })
  fireEvent.click(screen.getByTestId('portal-login-send'))
  await screen.findByTestId('portal-login-code')
}

const typeCode = () => fireEvent.paste(screen.getByTestId('login-otp-0'), { clipboardData: { getData: () => '424242' } })

/** LOGIN-NO-EMAIL-CODE-001 — the client contact's door: a code for a contact, and the same page and nothing sent for anybody else. */
describe('PortalLoginPage', () => {
  afterEach(() => vi.clearAllMocks())

  it('sends a contact a code through the portal engine', async () => {
    vi.mocked(signInMethod).mockResolvedValue({ method: 'code', channel: 'email' })
    vi.mocked(portalLoginStart).mockResolvedValue(QUEUED)
    renderWithProviders(<PortalLoginPage />, { route: '/portal/login', locale: 'en' })

    await ask('client@example.test')

    expect(portalLoginStart).toHaveBeenCalledWith('email', 'client@example.test')
    expect(screen.getByTestId('portal-login-destination')).toHaveTextContent('client@example.test')
    expect(screen.queryByTestId('portal-login-undelivered')).not.toBeInTheDocument()
  })

  it('shows anybody else the same step, sends nothing, and refuses like a wrong code', async () => {
    vi.mocked(signInMethod).mockResolvedValue({ method: 'password', channel: 'email' })
    renderWithProviders(<PortalLoginPage />, { route: '/portal/login', locale: 'en' })

    await ask('stranger@example.test')
    expect(portalLoginStart).not.toHaveBeenCalled()

    typeCode()
    fireEvent.click(screen.getByTestId('portal-login-confirm'))

    expect(await screen.findByTestId('portal-login-refused')).toBeInTheDocument()
    expect(portalLoginVerify).not.toHaveBeenCalled()
  })

  it('says plainly when no code was actually sent', async () => {
    vi.mocked(signInMethod).mockResolvedValue({ method: 'code', channel: 'email' })
    vi.mocked(portalLoginStart).mockResolvedValue({ ...QUEUED, delivery_status: 'awaiting_provider_credentials' })
    renderWithProviders(<PortalLoginPage />, { route: '/portal/login', locale: 'en' })

    await ask('client@example.test')

    expect(screen.getByTestId('portal-login-undelivered')).toBeInTheDocument()
  })

  it('closes the resend until the cooldown has run, and goes back on request', async () => {
    vi.mocked(signInMethod).mockResolvedValue({ method: 'code', channel: 'email' })
    vi.mocked(portalLoginStart).mockResolvedValue(QUEUED)
    renderWithProviders(<PortalLoginPage />, { route: '/portal/login', locale: 'en' })

    await ask('client@example.test')
    expect(screen.getByTestId('portal-login-resend')).toBeDisabled()

    fireEvent.click(screen.getByTestId('portal-login-change'))
    expect(screen.getByTestId('portal-login-email-form')).toBeInTheDocument()
    expect(screen.getByLabelText(/Email address/i)).toHaveValue('client@example.test')
  })

  it('verifies the code it was given', async () => {
    vi.mocked(signInMethod).mockResolvedValue({ method: 'code', channel: 'email' })
    vi.mocked(portalLoginStart).mockResolvedValue(QUEUED)
    vi.mocked(portalLoginVerify).mockResolvedValue({ authenticated: true, contact: 'client@example.test', dev_token: null })
    renderWithProviders(<PortalLoginPage />, { route: '/portal/login?redirect=https%3A%2F%2Fevil.example', locale: 'en' })

    await ask('client@example.test')
    typeCode()

    await waitFor(() => expect(portalLoginVerify).toHaveBeenCalledWith('v1', '424242'))
  })
})

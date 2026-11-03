import axios from 'axios'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { EmailCodeSignIn } from './EmailCodeSignIn'
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
  fireEvent.click(screen.getByTestId('email-code-send'))
  await screen.findByTestId('email-code-step')
}

const page = (props: { redirect?: string | null; onSignedIn?: (d: string) => void } = {}) => (
  <EmailCodeSignIn redirect={props.redirect ?? null} onBack={() => undefined} onSignedIn={props.onSignedIn ?? (() => undefined)} />
)

const typeCode = () => fireEvent.paste(screen.getByTestId('login-otp-0'), { clipboardData: { getData: () => '424242' } })

/** LOGIN-SINGLE-DOOR-001 — the code step of `/login`; who is actually mailed is the server's decision (`PortalEmailSignInTest`). */
describe('EmailCodeSignIn', () => {
  afterEach(() => vi.clearAllMocks())

  it('sends a contact a code through the portal engine', async () => {
    vi.mocked(portalLoginStart).mockResolvedValue(QUEUED)
    renderWithProviders(page(), { route: '/login', locale: 'en' })

    await ask('client@example.test')

    expect(portalLoginStart).toHaveBeenCalledWith('email', 'client@example.test')
    expect(screen.getByTestId('email-code-destination')).toHaveTextContent('client@example.test')
    expect(screen.queryByTestId('email-code-undelivered')).not.toBeInTheDocument()
  })

  it('states a refusal instead of looking like a button that did nothing', async () => {
    vi.mocked(portalLoginStart).mockRejectedValue(new axios.AxiosError('refused', undefined, undefined, undefined, { status: 422, statusText: '', headers: {}, config: {} as never, data: { message: 'refused', errors: { channel: ['That is not available.'] } } }))
    renderWithProviders(page(), { route: '/login', locale: 'en' })

    fireEvent.change(screen.getByLabelText(/Email address/i), { target: { value: 'client@example.test' } })
    fireEvent.click(screen.getByTestId('email-code-send'))

    expect(await screen.findByTestId('login-error')).toHaveTextContent('That is not available.')
  })

  it('says plainly when no code was actually sent', async () => {
    vi.mocked(portalLoginStart).mockResolvedValue({ ...QUEUED, delivery_status: 'awaiting_provider_credentials' })
    renderWithProviders(page(), { route: '/login', locale: 'en' })

    await ask('client@example.test')

    expect(screen.getByTestId('email-code-undelivered')).toBeInTheDocument()
  })

  it('closes the resend until the cooldown has run, and goes back on request', async () => {
    vi.mocked(portalLoginStart).mockResolvedValue(QUEUED)
    renderWithProviders(page(), { route: '/login', locale: 'en' })

    await ask('client@example.test')
    expect(screen.getByTestId('email-code-resend')).toBeDisabled()

    fireEvent.click(screen.getByTestId('email-code-change'))
    expect(screen.getByTestId('email-code-form')).toBeInTheDocument()
    expect(screen.getByLabelText(/Email address/i)).toHaveValue('client@example.test')
  })

  it('verifies the code it was given, and goes where the server says', async () => {
    vi.mocked(portalLoginStart).mockResolvedValue(QUEUED)
    vi.mocked(portalLoginVerify).mockResolvedValue({ authenticated: true, contact: 'client@example.test', dev_token: null, destination: '/portal/requests' })
    const onSignedIn = vi.fn()
    renderWithProviders(page({ redirect: '/portal/requests', onSignedIn }), { route: '/login', locale: 'en' })

    await ask('client@example.test')
    typeCode()

    await waitFor(() => expect(portalLoginVerify).toHaveBeenCalledWith('v1', '424242', '/portal/requests'))
    // Where it goes is the server's answer, not a rule in this component.
    await waitFor(() => expect(onSignedIn).toHaveBeenCalledWith('/portal/requests'))
  })
})

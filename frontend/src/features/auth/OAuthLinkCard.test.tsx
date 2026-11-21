import { afterEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { OAuthLinkCard } from './OAuthLinkCard'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', async (orig) => ({
  ...(await orig<typeof import('./api')>()),
  fetchPendingLink: vi.fn(),
  confirmLinkWithPassword: vi.fn(),
  sendLinkEmail: vi.fn(),
  confirmLinkWithEmail: vi.fn(),
  cancelPendingLink: vi.fn(),
}))

import { cancelPendingLink, confirmLinkWithEmail, confirmLinkWithPassword, fetchPendingLink, sendLinkEmail } from './api'

const card = { provider: 'google', name: 'Mohammed G', email: 'owner@example.com', avatar: null, local_email: 'ow•••@example.com', expires_in: 600, email_sent: false, can_email: true }
const signedIn = { user: { id: 'u1' } as never, destination: '/app/campaigns' }

/** What axios throws for a refused request: the envelope under `response.data`. */
const refused = (status: number, body: { message?: string; errors?: Record<string, string[]>; meta?: Record<string, unknown> }) => ({
  isAxiosError: true,
  response: { status, data: { success: false, message: 'x', errors: null, meta: {}, ...body } },
})

function open(props: { confirmToken?: string | null; locale?: 'ar' | 'en' } = {}) {
  const onSignedIn = vi.fn()
  const onLeave = vi.fn()
  renderWithProviders(
    <OAuthLinkCard locale={props.locale ?? 'en'} confirmToken={props.confirmToken ?? null} onSignedIn={onSignedIn} onLeave={onLeave} />,
    { locale: props.locale ?? 'en' },
  )

  return { onSignedIn, onLeave }
}

describe('OAuthLinkCard — «ربط Google» (LOGIN-004)', () => {
  afterEach(() => vi.clearAllMocks())

  it('idle: shows who Google is, which account it matches, a password field and a mail link', async () => {
    vi.mocked(fetchPendingLink).mockResolvedValue(card)
    open()

    await screen.findByText('ow•••@example.com')
    expect(screen.getByTestId('oauth-link-google')).toHaveTextContent('Mohammed G')
    expect(screen.getByTestId('oauth-link-google')).toHaveTextContent('owner@example.com')
    expect(screen.getByTestId('oauth-link-local')).toHaveTextContent('ow•••@example.com')
    expect(screen.getByLabelText('Password', { selector: 'input' })).toBeInTheDocument()
    expect(screen.getByTestId('oauth-link-send')).toBeEnabled()
    expect(screen.getByTestId('oauth-link-submit')).toBeDisabled()
  })

  it('links with the right password and hands the server destination to the page', async () => {
    vi.mocked(fetchPendingLink).mockResolvedValue(card)
    vi.mocked(confirmLinkWithPassword).mockResolvedValue(signedIn)
    const { onSignedIn } = open()

    fireEvent.change(await screen.findByLabelText('Password', { selector: 'input' }), { target: { value: 'correct-horse' } })
    fireEvent.click(screen.getByTestId('oauth-link-submit'))

    await waitFor(() => expect(onSignedIn).toHaveBeenCalledWith(signedIn))
    expect(confirmLinkWithPassword).toHaveBeenCalledWith('correct-horse')
  })

  it('wrong password: says so on the field and stays', async () => {
    vi.mocked(fetchPendingLink).mockResolvedValue(card)
    vi.mocked(confirmLinkWithPassword).mockRejectedValue(refused(422, { errors: { password: ['These credentials do not match.'] } }))
    const { onSignedIn } = open()

    fireEvent.change(await screen.findByLabelText('Password', { selector: 'input' }), { target: { value: 'nope' } })
    fireEvent.click(screen.getByTestId('oauth-link-submit'))

    expect(await screen.findByText('Wrong password.')).toBeInTheDocument()
    expect(onSignedIn).not.toHaveBeenCalled()
    expect(screen.getByTestId('oauth-link-card')).toBeInTheDocument()
  })

  it('throttled: asks to wait, and links nothing', async () => {
    vi.mocked(fetchPendingLink).mockResolvedValue(card)
    vi.mocked(confirmLinkWithPassword).mockRejectedValue(refused(429, { message: 'Too Many Attempts.' }))
    const { onSignedIn } = open()

    fireEvent.change(await screen.findByLabelText('Password', { selector: 'input' }), { target: { value: 'whatever' } })
    fireEvent.click(screen.getByTestId('oauth-link-submit'))

    expect(await screen.findByTestId('oauth-link-error')).toHaveTextContent('Too many attempts')
    expect(onSignedIn).not.toHaveBeenCalled()
  })

  it('link sent: names the address, says to open it here, counts down before another', async () => {
    vi.mocked(fetchPendingLink).mockResolvedValue(card)
    vi.mocked(sendLinkEmail).mockResolvedValue({ delivery: 'sent', resend_after: 60 })
    open()

    fireEvent.click(await screen.findByTestId('oauth-link-send'))

    const sent = await screen.findByTestId('oauth-link-sent')
    expect(sent).toHaveTextContent('ow•••@example.com')
    expect(sent).toHaveTextContent('Open it in this browser.')
    expect(screen.getByTestId('oauth-link-resend')).toBeDisabled()
    expect(screen.queryByTestId('oauth-link-undelivered')).not.toBeInTheDocument()
  })

  it('link sent but not delivered: says nothing left (an allow-list of delivered states)', async () => {
    vi.mocked(fetchPendingLink).mockResolvedValue(card)
    vi.mocked(sendLinkEmail).mockResolvedValue({ delivery: 'awaiting_credentials', resend_after: 60 })
    open()

    fireEvent.click(await screen.findByTestId('oauth-link-send'))

    expect(await screen.findByTestId('oauth-link-undelivered')).toHaveTextContent('not sent')
  })

  it('an account with no usable address offers no mail link', async () => {
    vi.mocked(fetchPendingLink).mockResolvedValue({ ...card, can_email: false })
    open()

    await screen.findByText('ow•••@example.com')
    expect(screen.queryByTestId('oauth-link-send')).not.toBeInTheDocument()
  })

  it('expired: nothing to confirm, a way back, and no password field', async () => {
    vi.mocked(fetchPendingLink).mockRejectedValue(refused(410, { meta: { state: 'expired' } }))
    const { onLeave } = open()

    expect(await screen.findByTestId('oauth-link-expired')).toHaveTextContent('The connection expired')
    expect(screen.queryByTestId('oauth-link-password-form')).not.toBeInTheDocument()
    fireEvent.click(screen.getByTestId('oauth-link-back'))
    expect(onLeave).toHaveBeenCalled()
  })

  it('expires on its own clock when the ten minutes run out on the page', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    try {
      vi.mocked(fetchPendingLink).mockResolvedValue({ ...card, expires_in: 5 })
      open()
      await screen.findByTestId('oauth-link-card')

      await vi.advanceTimersByTimeAsync(5_100)

      expect(await screen.findByTestId('oauth-link-expired')).toBeInTheDocument()
    } finally {
      vi.useRealTimers()
    }
  })

  it('the server saying expired while confirming ends in the expired state', async () => {
    vi.mocked(fetchPendingLink).mockResolvedValue(card)
    vi.mocked(confirmLinkWithPassword).mockRejectedValue(refused(410, { meta: { state: 'expired' } }))
    open()

    fireEvent.change(await screen.findByLabelText('Password', { selector: 'input' }), { target: { value: 'x' } })
    fireEvent.click(screen.getByTestId('oauth-link-submit'))

    expect(await screen.findByTestId('oauth-link-expired')).toBeInTheDocument()
  })

  it('cancel discards the pending link on the server and leaves', async () => {
    vi.mocked(fetchPendingLink).mockResolvedValue(card)
    vi.mocked(cancelPendingLink).mockResolvedValue(undefined)
    const { onLeave } = open()

    fireEvent.click(await screen.findByTestId('oauth-link-cancel'))

    await waitFor(() => expect(cancelPendingLink).toHaveBeenCalled())
    await waitFor(() => expect(onLeave).toHaveBeenCalled())
  })

  it('a mailed link is spent exactly once and signs in', async () => {
    vi.mocked(fetchPendingLink).mockResolvedValue(card)
    vi.mocked(confirmLinkWithEmail).mockResolvedValue(signedIn)
    const { onSignedIn } = open({ confirmToken: 'secret-from-mail' })

    await waitFor(() => expect(onSignedIn).toHaveBeenCalledWith(signedIn))
    expect(confirmLinkWithEmail).toHaveBeenCalledTimes(1)
    expect(confirmLinkWithEmail).toHaveBeenCalledWith('secret-from-mail')
  })

  it('a mailed link opened in another browser fails closed with the expired state', async () => {
    vi.mocked(fetchPendingLink).mockRejectedValue(refused(410, { meta: { state: 'expired' } }))
    vi.mocked(confirmLinkWithEmail).mockRejectedValue(refused(410, { meta: { state: 'expired' } }))
    const { onSignedIn } = open({ confirmToken: 'secret-from-mail' })

    expect(await screen.findByTestId('oauth-link-expired')).toBeInTheDocument()
    expect(onSignedIn).not.toHaveBeenCalled()
  })

  it('speaks Arabic with short labels', async () => {
    vi.mocked(fetchPendingLink).mockResolvedValue(card)
    open({ locale: 'ar' })

    expect(await screen.findByText('ربط Google')).toBeInTheDocument()
    await screen.findByText('ow•••@example.com')
    expect(screen.getByTestId('oauth-link-submit')).toHaveTextContent('ربط ودخول')
    expect(screen.getByTestId('oauth-link-send')).toHaveTextContent('إرسال رابط')
    expect(screen.getByTestId('oauth-link-cancel')).toHaveTextContent('إلغاء')
  })
})

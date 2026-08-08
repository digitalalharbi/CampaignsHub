import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { ResetPasswordPage } from './ResetPasswordPage'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, resetPassword: vi.fn() }
})

import { resetPassword } from './api'

const TOKEN = 'k7Qm3xZa'.repeat(8)

/**
 * Arabic, explicitly — the helper defaults to English and the product defaults to Arabic.
 *
 * Asserting the Arabic strings is the point: this page is read by somebody locked out, and «اطلب
 * رابطًا جديدًا» either reaches them or the page has failed at the only job it has.
 */
function openWith(query: string) {
  return renderWithProviders(<ResetPasswordPage />, { route: `/reset-password${query}`, locale: 'ar' })
}

/**
 * MAIL-009 — the page the reset link opens.
 *
 * `/forgot-password` had existed for months with nothing on the other side of it: no token was
 * issued and no page consumed one, so «تحقق من بريدك» pointed at an email that was never sent. These
 * tests are about the two things that make this page correct rather than merely present — that it
 * refuses a link it cannot use, and that it sends back exactly what arrived in the URL.
 */
describe('the reset-password page', () => {
  beforeEach(() => {
    vi.mocked(resetPassword).mockReset()
    vi.mocked(resetPassword).mockResolvedValue(undefined)
  })

  /**
   * A half-formed link is refused before anybody types a password.
   *
   * The server would refuse it either way. Refusing it here means somebody does not choose a
   * password, confirm it, submit, and only then learn the link was the problem.
   */
  it.each([
    ['no token', `?email=a%40b.com`],
    ['no email', `?token=${TOKEN}`],
    ['nothing at all', ''],
  ])('refuses a link with %s, and offers no password field', (_label, query) => {
    openWith(query)

    expect(screen.getByText(/اطلب رابطًا جديدًا/)).toBeInTheDocument()
    expect(screen.queryByLabelText(/كلمة المرور الجديدة/)).not.toBeInTheDocument()
  })

  /** What arrived in the URL is what goes back, unchanged. */
  it('sends the token and address exactly as the link carried them', async () => {
    openWith(`?token=${TOKEN}&email=sara%40example.com`)

    fireEvent.change(screen.getByLabelText(/كلمة المرور الجديدة/), { target: { value: 'a-good-password' } })
    fireEvent.change(screen.getByLabelText(/تأكيد كلمة المرور/), { target: { value: 'a-good-password' } })
    fireEvent.click(screen.getByRole('button', { name: 'حفظ كلمة المرور' }))

    // The first argument only: TanStack hands a mutation context as the second, which is its
    // business and not part of what this page promises to send.
    await waitFor(() => expect(vi.mocked(resetPassword).mock.calls[0]?.[0]).toEqual({
      // Decoded from the query string, because that is what the server matches against.
      email: 'sara@example.com',
      token: TOKEN,
      password: 'a-good-password',
      password_confirmation: 'a-good-password',
    }))
  })

  /**
   * A mismatch is caught without a round trip, and nothing is sent.
   *
   * The server checks this too — the browser check is a courtesy, and the assertion that matters is
   * the second one: a mismatched pair must not reach the endpoint at all.
   */
  it('does not submit when the two passwords differ', async () => {
    openWith(`?token=${TOKEN}&email=sara%40example.com`)

    fireEvent.change(screen.getByLabelText(/كلمة المرور الجديدة/), { target: { value: 'a-good-password' } })
    fireEvent.change(screen.getByLabelText(/تأكيد كلمة المرور/), { target: { value: 'something-else' } })
    fireEvent.click(screen.getByRole('button', { name: 'حفظ كلمة المرور' }))

    expect(await screen.findByText('كلمتا المرور غير متطابقتين.')).toBeInTheDocument()
    expect(resetPassword).not.toHaveBeenCalled()
  })

  /** Success sends them to sign in, and does not pretend they are already signed in. */
  it('confirms and offers the way back to sign in', async () => {
    openWith(`?token=${TOKEN}&email=sara%40example.com`)

    fireEvent.change(screen.getByLabelText(/كلمة المرور الجديدة/), { target: { value: 'a-good-password' } })
    fireEvent.change(screen.getByLabelText(/تأكيد كلمة المرور/), { target: { value: 'a-good-password' } })
    fireEvent.click(screen.getByRole('button', { name: 'حفظ كلمة المرور' }))

    expect(await screen.findByText('تم تحديث كلمة المرور')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /العودة لتسجيل الدخول/ })).toHaveAttribute('href', '/login')
  })
})

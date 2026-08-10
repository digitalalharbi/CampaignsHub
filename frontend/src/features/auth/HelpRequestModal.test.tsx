import { afterEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { HelpRequestModal } from './HelpRequestModal'
import { renderWithProviders } from '@/test/utils'

vi.mock('@/features/marketing/publicFormsApi', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, sendContact: vi.fn() }
})

import { sendContact } from '@/features/marketing/publicFormsApi'

const mocked = vi.mocked(sendContact)

function open(locale: 'ar' | 'en' = 'en') {
  renderWithProviders(<HelpRequestModal locale={locale} ar={locale === 'ar'} />, { route: '/login', locale })
  fireEvent.click(screen.getByTestId('login-help-open'))
}

function fillTheMinimum() {
  fireEvent.change(screen.getByLabelText(/^Name/i), { target: { value: 'Sara' } })
  fireEvent.change(screen.getByLabelText(/Work email/i), { target: { value: 'sara@example.test' } })
}

/**
 * LOGIN-HELP-001 — a way to ask for help that is not a way to sign up.
 *
 * The claims worth holding: it never creates an account, the details are genuinely optional, the
 * phone is genuinely optional, and a failed send does not throw away what somebody typed.
 */
describe('HelpRequestModal', () => {
  afterEach(() => vi.clearAllMocks())

  it('sends a topic and the source it was opened from', async () => {
    mocked.mockResolvedValue({ received: true } as never)
    open()
    fillTheMinimum()
    fireEvent.click(screen.getByRole('button', { name: /Send request/i }))

    await waitFor(() => expect(mocked).toHaveBeenCalled())
    expect(mocked.mock.calls[0]![0]).toMatchObject({
      name: 'Sara',
      email: 'sara@example.test',
      topic: 'own_campaigns',
      source: 'login',
    })
  })

  /**
   * A blank optional field is ABSENT, not an empty string.
   *
   * `''` reaches the server as an answered question with no answer, which is a different fact from
   * «they did not say» — and it is the one that ends up printed as a blank line in an operator's
   * queue as though something had been lost.
   */
  it('omits the phone and the details when they were left empty', async () => {
    mocked.mockResolvedValue({ received: true } as never)
    open()
    fillTheMinimum()
    fireEvent.click(screen.getByRole('button', { name: /Send request/i }))

    await waitFor(() => expect(mocked).toHaveBeenCalled())
    const sent = mocked.mock.calls[0]![0]
    expect(sent.phone).toBeUndefined()
    expect(sent.message).toBeUndefined()
  })

  it('carries the details when they were written', async () => {
    mocked.mockResolvedValue({ received: true } as never)
    open()
    fillTheMinimum()
    fireEvent.change(screen.getByLabelText(/^Details/i), { target: { value: 'Two Snapchat accounts.' } })
    fireEvent.click(screen.getByRole('button', { name: /Send request/i }))

    await waitFor(() => expect(mocked).toHaveBeenCalled())
    expect(mocked.mock.calls[0]![0].message).toBe('Two Snapchat accounts.')
  })

  it('carries the chosen need rather than always the first one', async () => {
    mocked.mockResolvedValue({ received: true } as never)
    open()
    fillTheMinimum()
    fireEvent.change(screen.getByLabelText(/What do you need/i), { target: { value: 'plan_choice' } })
    fireEvent.click(screen.getByRole('button', { name: /Send request/i }))

    await waitFor(() => expect(mocked).toHaveBeenCalled())
    expect(mocked.mock.calls[0]![0].topic).toBe('plan_choice')
  })

  it('confirms in the sender\'s own language once it is in', async () => {
    mocked.mockResolvedValue({ received: true } as never)
    open('ar')

    fireEvent.change(screen.getByLabelText(/الاسم/), { target: { value: 'سارة' } })
    fireEvent.change(screen.getByLabelText(/البريد الإلكتروني للعمل/), { target: { value: 'sara@example.test' } })
    fireEvent.click(screen.getByRole('button', { name: 'إرسال الطلب' }))

    await waitFor(() => expect(screen.getByTestId('login-help-success')).toBeInTheDocument())
    expect(screen.getByText('تم استلام طلبك، وسيتواصل معك فريقنا.')).toBeInTheDocument()
  })

  /** A number it cannot read is refused HERE, before a request is spent on it. */
  it('refuses a number it cannot read without sending anything', () => {
    open()
    fillTheMinimum()
    fireEvent.change(screen.getByTestId('help-phone'), { target: { value: 'not a phone' } })
    fireEvent.click(screen.getByRole('button', { name: /Send request/i }))

    expect(mocked).not.toHaveBeenCalled()
    expect(screen.getByText(/valid mobile number/i)).toBeInTheDocument()
  })

  /**
   * A failed send keeps what was typed.
   *
   * The most likely reason for a failure is a network that is about to come back, and a form that
   * empties itself makes somebody retype everything to try the thing that was going to work.
   */
  it('keeps the form filled when the send fails', async () => {
    mocked.mockRejectedValue(new Error('offline'))
    open()
    fillTheMinimum()
    fireEvent.click(screen.getByRole('button', { name: /Send request/i }))

    await waitFor(() => expect(mocked).toHaveBeenCalled())
    expect(screen.getByLabelText(/^Name/i)).toHaveValue('Sara')
  })
})

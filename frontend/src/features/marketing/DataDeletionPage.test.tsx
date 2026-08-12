import { describe, expect, it, vi, beforeEach } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { DataDeletionPage } from './DataDeletionPage'
import { api } from '@/lib/api/client'
import { useUi } from '@/stores/ui'

vi.mock('@/lib/api/client', () => ({ api: { post: vi.fn() } }))

const post = vi.mocked(api.post)

function renderPage(path = '/data-deletion') {
  return render(
    <MemoryRouter initialEntries={[path]}>
      <DataDeletionPage />
    </MemoryRouter>,
  )
}

/**
 * LEGAL-DELETE-001 — the page behind the URL every ad-platform review asks for.
 *
 * What these hold is the SHAPE of the flow rather than its wording: a destructive request does not
 * end at «submitted», it moves to a step that asks for the code, and the page says out loud when the
 * code could not be delivered. A page that said «check your email» after a failed send would be the
 * product lying about the one action the person now has to take.
 */
describe('the public data-deletion page', () => {
  beforeEach(() => {
    post.mockReset()
    useUi.setState({ locale: 'en' })
  })

  it('asks for the code after a deletion request, and shows the reference', async () => {
    post.mockResolvedValueOnce({
      data: { data: { reference: 'AB12CD', status: 'verifying', verification_required: true, delivery: 'sent' } },
    } as never)

    renderPage()

    fireEvent.change(screen.getByTestId('data-deletion-name'), { target: { value: 'Sara' } })
    fireEvent.change(screen.getByTestId('data-deletion-email'), { target: { value: 'sara@example.test' } })
    fireEvent.submit(screen.getByTestId('data-deletion-form'))

    await waitFor(() => expect(screen.getByTestId('data-deletion-verify')).toBeInTheDocument())
    expect(screen.getByTestId('data-deletion-reference')).toHaveTextContent('AB12CD')
  })

  /** No mail provider is configured today, so this is the state a real visitor would meet. */
  it('says the code could not be sent rather than «check your email»', async () => {
    post.mockResolvedValueOnce({
      data: {
        data: {
          reference: 'AB12CD', status: 'verifying', verification_required: true,
          delivery: 'awaiting_credentials',
        },
      },
    } as never)

    renderPage()

    fireEvent.change(screen.getByTestId('data-deletion-name'), { target: { value: 'Sara' } })
    fireEvent.change(screen.getByTestId('data-deletion-email'), { target: { value: 'sara@example.test' } })
    fireEvent.submit(screen.getByTestId('data-deletion-form'))

    await waitFor(() => expect(screen.getByTestId('data-deletion-delivery-warning')).toBeInTheDocument())
  })

  /** A correction needs no code, so the page must not invent a step that does not exist. */
  it('goes straight to the result for a non-destructive request', async () => {
    post.mockResolvedValueOnce({
      data: { data: { reference: 'XY99ZZ', status: 'pending', verification_required: false } },
    } as never)

    renderPage()

    fireEvent.change(screen.getByTestId('data-deletion-type'), { target: { value: 'correction' } })
    fireEvent.change(screen.getByTestId('data-deletion-name'), { target: { value: 'Sara' } })
    fireEvent.change(screen.getByTestId('data-deletion-email'), { target: { value: 'sara@example.test' } })
    fireEvent.submit(screen.getByTestId('data-deletion-form'))

    await waitFor(() => expect(screen.getByTestId('data-deletion-result')).toBeInTheDocument())
    expect(screen.getByTestId('data-deletion-status')).toHaveTextContent('pending')
  })

  /** Why it cannot proceed, in the reader's language — never a bare «blocked». */
  it('names the blockers instead of only the status', async () => {
    post.mockResolvedValueOnce({
      data: {
        data: {
          reference: 'XY99ZZ', status: 'blocked', verification_required: false,
          blockers: [{ code: 'open_invoices', ar: 'فواتير مفتوحة', en: 'There are open invoices' }],
        },
      },
    } as never)

    renderPage()

    fireEvent.change(screen.getByTestId('data-deletion-type'), { target: { value: 'export' } })
    fireEvent.change(screen.getByTestId('data-deletion-name'), { target: { value: 'Sara' } })
    fireEvent.change(screen.getByTestId('data-deletion-email'), { target: { value: 'sara@example.test' } })
    fireEvent.submit(screen.getByTestId('data-deletion-form'))

    await waitFor(() => expect(screen.getByTestId('data-deletion-blockers')).toHaveTextContent('open invoices'))
  })

  /** Somebody arriving from a platform callback lands with their reference already filled in. */
  it('prefills the reference from the query string', () => {
    renderPage('/data-deletion?reference=FROMMETA')

    expect(screen.getByTestId('data-deletion-lookup-reference')).toHaveValue('FROMMETA')
  })

  /** The page reads in Arabic too, and the whole flow is one component in both. */
  it('renders in Arabic', () => {
    useUi.setState({ locale: 'ar' })
    renderPage()

    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('حذف بياناتك')
  })

  /** A refusal is shown to the reader, not swallowed. */
  it('shows the server’s refusal', async () => {
    post.mockRejectedValueOnce({ response: { data: { message: 'That code could not be verified.' } } })

    renderPage('/data-deletion?reference=AB12CD')

    fireEvent.change(screen.getByTestId('data-deletion-lookup-email'), { target: { value: 'sara@example.test' } })
    fireEvent.submit(screen.getByTestId('data-deletion-lookup').querySelector('form') as HTMLFormElement)

    await waitFor(() => expect(screen.getByTestId('data-deletion-error')).toHaveTextContent('could not be verified'))
  })
})

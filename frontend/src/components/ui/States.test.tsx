import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { AxiosError, AxiosHeaders } from 'axios'
import { beforeEach, describe, expect, it } from 'vitest'
import { ErrorState } from './States'
import { useUi } from '@/stores/ui'

/**
 * AGENCY-PERMS-006 — the shared error panel stops guessing when it is handed the error.
 *
 * `ErrorState` is the primitive twenty-odd screens reach for, and it rendered one red box with a
 * Retry button for every failure. Three of the four things that go wrong are not failures at all: a
 * refusal, an expired session and a missing record. On each of them the sentence was wrong and the
 * button could not work — pressing Retry on a 403 repeats the refusal.
 *
 * The `error` prop is optional so the fix was one prop per call site rather than a rewrite of every
 * screen, which is exactly why these tests assert BOTH shapes: the old one still has to work.
 */
function httpError(status: number, message?: string): AxiosError {
  const config = { headers: new AxiosHeaders() }
  const error = new AxiosError('Request failed', 'ERR_BAD_REQUEST', config as never)
  error.response = {
    status, statusText: '', headers: {}, config: config as never,
    data: message ? { success: false, message } : { success: false },
  }
  error.request = {}
  return error
}

const ui = (node: React.ReactNode) => render(<MemoryRouter>{node}</MemoryRouter>)

describe('ErrorState', () => {
  beforeEach(() => useUi.setState({ locale: 'ar' }))

  it('classifies a refusal and withholds Retry, instead of blaming the load', () => {
    ui(<ErrorState error={httpError(403, 'تحتاج صلاحية billing.view')} title="تعذّر تحميل الفواتير." onRetry={() => {}} />)

    expect(screen.getByTestId('query-failure-permission')).toBeInTheDocument()
    // The page's own sentence describes an outage. This was not one, so it must not appear.
    expect(screen.queryByText('تعذّر تحميل الفواتير.')).not.toBeInTheDocument()
    expect(screen.getByText('تحتاج صلاحية billing.view')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /إعادة المحاولة|Retry/ })).not.toBeInTheDocument()
  })

  /** The one case that really is a failed load keeps the surface's own sentence and its Retry. */
  it('keeps the page’s sentence and offers Retry on a genuine server failure', () => {
    ui(<ErrorState error={httpError(500)} title="تعذّر تحميل الفواتير." onRetry={() => {}} />)

    expect(screen.getByTestId('query-failure-retryable')).toBeInTheDocument()
    expect(screen.getByText('تعذّر تحميل الفواتير.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /إعادة المحاولة/ })).toBeInTheDocument()
  })

  /**
   * Call sites that have not been handed their error yet keep the panel they always had — the prop
   * is an upgrade, not a breaking change, and a half-migrated screen must not render nothing.
   */
  it('falls back to the plain panel when no error is supplied', () => {
    ui(<ErrorState title="تعذّر تحميل الفواتير." onRetry={() => {}} />)

    expect(screen.queryByTestId('query-failure-retryable')).not.toBeInTheDocument()
    expect(screen.getByText('تعذّر تحميل الفواتير.')).toBeInTheDocument()
  })

  /**
   * The Retry button read «Retry» on every Arabic page in the product. It now follows the interface
   * language without the caller having to say so, which is what let the fix reach every screen.
   */
  it('labels Retry in the reader’s language, taken from the interface when unsaid', () => {
    const { unmount } = ui(<ErrorState title="فشل" onRetry={() => {}} />)
    expect(screen.getByRole('button', { name: 'إعادة المحاولة' })).toBeInTheDocument()
    unmount()

    useUi.setState({ locale: 'en' })
    ui(<ErrorState title="Failed" onRetry={() => {}} />)
    expect(screen.getByRole('button', { name: 'Retry' })).toBeInTheDocument()
  })

  /** An explicit `ar` still wins — a screen that is deliberately one language keeps saying so. */
  it('lets the caller override the language', () => {
    useUi.setState({ locale: 'en' })
    ui(<ErrorState ar title="فشل" onRetry={() => {}} />)

    expect(screen.getByRole('button', { name: 'إعادة المحاولة' })).toBeInTheDocument()
  })
})

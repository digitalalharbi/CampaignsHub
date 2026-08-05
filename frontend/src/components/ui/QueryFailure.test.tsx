import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { AxiosError, AxiosHeaders } from 'axios'
import { describe, expect, it } from 'vitest'
import { QueryFailure, failureKind } from './QueryFailure'

/**
 * AGENCY-PERMS — four different failures must not produce one sentence.
 *
 * The bug being pinned: `TasksPage`, `ThreadsPage` and `TabMessages` printed «تعذّر تحميل…» for a
 * refusal, an expired session and a dead server alike, so an operator browsing a tenant surface as
 * the platform admin — who holds no tenant, and is therefore refused correctly — reported the
 * product as broken three times running.
 */

/** An axios failure with a real response, shaped the way the API client actually receives one. */
function httpError(status: number, message?: string): AxiosError {
  const config = { headers: new AxiosHeaders() }
  const error = new AxiosError('Request failed', 'ERR_BAD_REQUEST', config as never)
  error.response = {
    status,
    statusText: '',
    headers: {},
    config: config as never,
    data: message ? { success: false, message } : { success: false },
  }
  error.request = {}
  return error
}

/** A request that was sent and never answered — the only case that is really a network problem. */
function offlineError(): AxiosError {
  const config = { headers: new AxiosHeaders() }
  const error = new AxiosError('Network Error', 'ERR_NETWORK', config as never)
  error.request = {}
  return error
}

const view = (error: unknown) =>
  render(
    <MemoryRouter>
      <QueryFailure error={error} ar fallbackTitle="تعذّر تحميل المهام." />
    </MemoryRouter>,
  )

describe('failureKind', () => {
  it('separates a refusal, an ended session, a missing record and a real failure', () => {
    expect(failureKind(httpError(403))).toBe('permission')
    expect(failureKind(httpError(401))).toBe('session')
    // 419 is a stale CSRF token, which is the same problem as an ended session from the reader's side.
    expect(failureKind(httpError(419))).toBe('session')
    expect(failureKind(httpError(404))).toBe('not_found')
    expect(failureKind(httpError(500))).toBe('retryable')
    expect(failureKind(offlineError())).toBe('retryable')
  })
})

describe('QueryFailure', () => {
  /**
   * A 403 must NOT render the surface's own «تعذّر تحميل…» — that sentence claims the system failed
   * when it in fact declined, which is the whole defect.
   */
  it('shows a refusal as a refusal, without the load-failure sentence', () => {
    view(httpError(403, 'ليس لديك صلاحية لتنفيذ هذا الإجراء.'))

    expect(screen.getByTestId('query-failure-permission')).toBeInTheDocument()
    expect(screen.getByText('ليس لديك صلاحية لتنفيذ هذا الإجراء.')).toBeInTheDocument()
    expect(screen.queryByText('تعذّر تحميل المهام.')).not.toBeInTheDocument()
  })

  /** Retrying a refusal produces the same refusal, so the button must not be offered. */
  it('offers no retry on a refusal or an ended session', () => {
    const denied = view(httpError(403))
    expect(denied.queryByRole('button')).not.toBeInTheDocument()
    denied.unmount()

    view(httpError(401))
    expect(screen.getByTestId('query-failure-session')).toBeInTheDocument()
    expect(screen.queryByRole('button')).not.toBeInTheDocument()
    // An ended session has exactly one useful action, and it is not "try again".
    expect(screen.getByRole('link', { name: 'تسجيل الدخول' })).toHaveAttribute('href', '/login')
  })

  /** The one case the surface really did fail to load — its own sentence, and a Retry that works. */
  it('keeps the page’s own sentence and a retry for a genuine failure', () => {
    render(
      <MemoryRouter>
        <QueryFailure error={httpError(500)} ar fallbackTitle="تعذّر تحميل المهام." onRetry={() => {}} />
      </MemoryRouter>,
    )

    expect(screen.getByTestId('query-failure-retryable')).toBeInTheDocument()
    expect(screen.getByText('تعذّر تحميل المهام.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /إعادة المحاولة/ })).toBeInTheDocument()
  })

  it('names a missing record rather than calling it a load failure', () => {
    view(httpError(404))
    expect(screen.getByTestId('query-failure-not-found')).toBeInTheDocument()
    expect(screen.queryByText('تعذّر تحميل المهام.')).not.toBeInTheDocument()
  })
})

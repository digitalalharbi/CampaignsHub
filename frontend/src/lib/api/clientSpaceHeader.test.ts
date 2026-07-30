import { beforeEach, describe, expect, it } from 'vitest'
import { AxiosHeaders, type InternalAxiosRequestConfig } from 'axios'
import { api } from './client'

/**
 * The space header is attached in ONE place, because there are more than twenty `/client/*` call
 * sites and the one that forgets it is the one that shows another brand's data.
 *
 * These drive the registered interceptor directly rather than a copy of its logic — a test of a
 * duplicate would keep passing after the real one was removed.
 */
type Interceptor = { fulfilled: (c: InternalAxiosRequestConfig) => InternalAxiosRequestConfig }

function runInterceptors(url: string): InternalAxiosRequestConfig {
  const config = { url, headers: new AxiosHeaders() } as InternalAxiosRequestConfig
  const handlers = (api.interceptors.request as unknown as { handlers: (Interceptor | null)[] }).handlers

  return handlers.filter(Boolean).reduce((c, h) => (h as Interceptor).fulfilled(c), config)
}

function at(pathname: string): void {
  window.history.replaceState({}, '', pathname)
}

describe('the client-space request header', () => {
  beforeEach(() => at('/'))

  it('names the space when the visitor is inside one', () => {
    at('/portal/clients/acme/invoices')
    expect(runInterceptors('/client/invoices').headers.get('X-Client-Space')).toBe('acme')
  })

  /** Outside a space, no header — so `/client/*` keeps meaning "everything this contact reaches". */
  it('sends no header from the shared client view', () => {
    at('/client/invoices')
    expect(runInterceptors('/client/invoices').headers.get('X-Client-Space')).toBeUndefined()
  })

  /**
   * Only portal traffic carries it. An agency operator working in `/portal/clients/...` — which is
   * possible while previewing a client space — must not have their staff API calls silently narrowed
   * by a header meant for a different auth engine.
   */
  it('never attaches it to a non-portal request', () => {
    at('/portal/clients/acme/invoices')
    expect(runInterceptors('/app/clients').headers.get('X-Client-Space')).toBeUndefined()
    expect(runInterceptors('/agency/dashboard').headers.get('X-Client-Space')).toBeUndefined()
  })
})

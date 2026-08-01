import { beforeEach, describe, expect, it } from 'vitest'
import { AxiosHeaders, type InternalAxiosRequestConfig } from 'axios'
import { api, toApiError } from './client'
import { useUi } from '@/stores/ui'

/**
 * Every request says which language the interface is in (I18N-001).
 *
 * The backend answers in the language it is asked for and falls back to Arabic. That is only useful
 * if the browser actually asks — without this header a customer who switched the interface to
 * English would still be told «بيانات الدخول غير صحيحة», and the toggle would be a lie about
 * everything except the labels.
 *
 * Driven through the REGISTERED interceptor rather than a copy of its logic: a test of a duplicate
 * would keep passing after the real one was deleted.
 */
type Interceptor = { fulfilled: (c: InternalAxiosRequestConfig) => InternalAxiosRequestConfig }

function runInterceptors(url: string): InternalAxiosRequestConfig {
  const config = { url, headers: new AxiosHeaders() } as InternalAxiosRequestConfig
  const handlers = (api.interceptors.request as unknown as { handlers: (Interceptor | null)[] }).handlers

  return handlers.filter(Boolean).reduce((c, h) => (h as Interceptor).fulfilled(c), config)
}

/** The store starts in Arabic; `toggleLocale` is the only way it changes, so it is what tests use. */
function setLocale(locale: 'ar' | 'en'): void {
  if (useUi.getState().locale !== locale) useUi.getState().toggleLocale()
}

describe('the language header', () => {
  beforeEach(() => setLocale('ar'))

  it('carries the product default on a fresh tab', () => {
    expect(runInterceptors('/auth/login').headers.get('Accept-Language')).toBe('ar')
  })

  it('follows the language toggle', () => {
    setLocale('en')
    expect(runInterceptors('/auth/login').headers.get('Accept-Language')).toBe('en')
  })

  /**
   * Read at REQUEST time, not once at module load.
   *
   * A header fixed when the client was constructed would keep answering in whichever language the
   * tab happened to open in — so switching to English and then mistyping a password would still
   * produce an Arabic refusal, which is precisely the bug this header exists to prevent.
   */
  it('reflects a switch made after the client was created', () => {
    expect(runInterceptors('/auth/me').headers.get('Accept-Language')).toBe('ar')
    setLocale('en')
    expect(runInterceptors('/auth/me').headers.get('Accept-Language')).toBe('en')
    setLocale('ar')
    expect(runInterceptors('/auth/me').headers.get('Accept-Language')).toBe('ar')
  })

  it('is attached to every request, not only to the auth ones', () => {
    for (const url of ['/app/campaigns', '/agency/dashboard', '/client/invoices', '/admin/plans']) {
      expect(runInterceptors(url).headers.get('Accept-Language')).toBe('ar')
    }
  })
})

describe('an error with no response at all', () => {
  beforeEach(() => setLocale('ar'))

  /**
   * The one message that cannot come from the server, because the request never reached it. It is
   * the likeliest error a customer on a poor connection sees, so leaving it in English would undo
   * the translation exactly where it is needed most.
   */
  it('is written in the interface language', () => {
    expect(toApiError(new Error('Network Error')).message).toBe('تعذّر الاتصال بالخادم. الرجاء المحاولة مرة أخرى.')

    setLocale('en')
    expect(toApiError(new Error('Network Error')).message).toBe('A network error occurred. Please try again.')
  })

  /** When the server DID answer, its message wins — it is already in the requested language. */
  it('never overrides a message the server sent', () => {
    const served = { response: { data: { message: 'بيانات الدخول غير صحيحة.' }, status: 422 } }

    expect(toApiError(served).message).toBe('بيانات الدخول غير صحيحة.')
  })
})

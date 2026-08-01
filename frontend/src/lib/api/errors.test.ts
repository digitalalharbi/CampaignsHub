import { beforeEach, describe, expect, it } from 'vitest'
import { toApiError } from './client'
import { useUi } from '@/stores/ui'

/**
 * A failure is described from what we actually know (LOGIN-FINAL).
 *
 * The defect: the client had two answers — the server's envelope, or «A network error occurred» —
 * so a 502, an HTML error page, a truncated body and a bug in our own code all told the customer
 * their internet was down. Reproduced against the running stack: with the API unreachable the dev
 * proxy answers 502 with an EMPTY `text/plain` body, so axios HAS a response and the envelope
 * lookup finds nothing.
 */
function setLocale(locale: 'ar' | 'en'): void {
  if (useUi.getState().locale !== locale) useUi.getState().toggleLocale()
}

/** Shaped like the axios error for a real HTTP answer. */
const http = (status: number, data: unknown = '') => ({ request: {}, response: { status, data } })

describe('describing a failure', () => {
  beforeEach(() => setLocale('en'))

  /** THE reproduced case. A gateway answered; the customer's connection is fine. */
  it('does not blame the network for an empty 502 from a gateway', () => {
    const error = toApiError(http(502))

    expect(error.kind).toBe('http')
    expect(error.message).toBe('The service is temporarily unavailable. Please try again shortly.')
    expect(error.message).not.toMatch(/connection|network/i)
  })

  /** A 500 is ours, and saying so stops somebody restarting their router over it. */
  it('says a server error was not the customer’s fault', () => {
    expect(toApiError(http(500)).message).toMatch(/on our side/i)
    setLocale('ar')
    expect(toApiError(http(500)).message).toContain('لم يكن السبب من جهتك')
  })

  it('describes each status it can actually distinguish', () => {
    const cases: Array<[number, RegExp]> = [
      [401, /session has ended/i],
      [403, /do not have permission/i],
      [404, /could not be found/i],
      [419, /page has expired/i],
      [422, /not valid/i],
      [429, /too many attempts/i],
      [504, /longer than expected/i],
    ]

    for (const [status, expected] of cases) {
      expect(toApiError(http(status)).message, `status ${status}`).toMatch(expected)
    }
  })

  /**
   * An HTML error page is a response, not an envelope.
   *
   * `message` on it is either absent or not a string, and reading it as an envelope message is how
   * markup ends up rendered at a customer.
   */
  it('never mistakes a non-envelope body for a message', () => {
    const html = toApiError(http(500, '<!doctype html><title>Bad gateway</title>'))
    expect(html.message).toMatch(/on our side/i)

    const notAString = toApiError(http(400, { message: { nested: true } }))
    expect(typeof notAString.message).toBe('string')
    expect(notAString.message).not.toContain('nested')
  })

  /** The server's own message always wins — it knows which field and which limit. */
  it('prefers what the server said', () => {
    const served = toApiError(http(422, { message: 'حقل كلمة المرور مطلوب.', errors: { password: ['x'] } }))

    expect(served.message).toBe('حقل كلمة المرور مطلوب.')
    expect(served.errors?.password).toEqual(['x'])
  })

  /** Only a request that got NO answer is a network problem. */
  it('reserves the network message for a request nothing answered', () => {
    expect(toApiError({ request: {}, response: undefined }).kind).toBe('offline')
    expect(toApiError({ request: {}, response: undefined }).message).toMatch(/could not be reached/i)
  })

  /** Giving up waiting is not the same as being disconnected. */
  it('tells a timeout apart from being offline', () => {
    const timedOut = toApiError({ request: {}, response: undefined, code: 'ECONNABORTED' })

    expect(timedOut.kind).toBe('timeout')
    expect(timedOut.message).toMatch(/longer than expected|could not be reached/i)
  })

  /** A bug in our own code must not be reported to the customer as a network fault. */
  it('does not call a thrown TypeError a network problem', () => {
    const bug = toApiError(new TypeError('undefined is not a function'))

    expect(bug.kind).toBe('unexpected')
    expect(bug.message).not.toMatch(/connection|network/i)
  })
})

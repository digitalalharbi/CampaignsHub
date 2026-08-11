import axios, { AxiosError } from 'axios'
import type { ApiEnvelope } from './types'
import { describeFailure, isTimeout } from './errors'
import { useUi } from '@/stores/ui'

/**
 * Central Axios client for the SPA. Uses Sanctum cookie-session auth (ADR 0001):
 * `withCredentials` sends the session + XSRF cookies, and Axios echoes the XSRF-TOKEN cookie back
 * as the X-XSRF-TOKEN header automatically. No auth token is stored in JS.
 */
export const api = axios.create({
  baseURL: '/api/v1',
  withCredentials: true,
  xsrfCookieName: 'XSRF-TOKEN',
  xsrfHeaderName: 'X-XSRF-TOKEN',
  headers: { Accept: 'application/json' },
})

/**
 * Every request carries the language the interface is currently in (I18N-001).
 *
 * Read from the store AT REQUEST TIME rather than set once on the instance: the language toggle is
 * on every screen, and a header fixed at module load would keep answering in the language the tab
 * happened to open in. That is the failure this is written to avoid — someone switches to English,
 * mistypes their password, and is told «بيانات الدخول غير صحيحة».
 *
 * `Accept-Language` rather than a custom header because it is the standard one, so a browser, a
 * mobile client or a curl gets the same treatment without knowing anything about this application.
 */
api.interceptors.request.use((config) => {
  config.headers.set('Accept-Language', useUi.getState().locale)

  return config
})

/*
 * ACCESS-EXIT-002 — the sign-out barrier.
 *
 * ## The race this closes, measured rather than imagined
 *
 * Signing out used to clear the auth store and the query cache only AFTER `/auth/logout` returned.
 * By then the dashboard had already fired its own burst — `/auth/memberships`, `/projects`,
 * `/notifications`, `/client-workspaces`, `/dashboard/saved-views`, `/creatives/pulse` — and each of
 * those requests had loaded the authenticated session on its way in. Laravel writes the session back
 * when a request finishes, under the id it was loaded with, so one landing after the logout put the
 * payload the logout had just deleted straight back into the store. The cookie in the browser then
 * worked again: `/auth/me` answered 200 with the customer's address after a logout that answered 200.
 *
 * The timeline is in the handoff for 2026-08-11. It reproduced roughly one run in four on chromium.
 * The mechanism was confirmed byte for byte against the running server — restore the deleted
 * payload by hand and the pre-logout cookie jar is signed in again (`ACCESS-EXIT-003`).
 *
 * ## This is necessary and it is NOT sufficient
 *
 * A request that left the browser before the click cannot be recalled from here, so the server also
 * records the signed-out session id outside the session and refuses it however it comes back
 * (`RejectRevokedSessions`). Keep both: this one keeps the traffic down, that one makes it certain.
 *
 * ## Why the barrier lives HERE and not in each caller
 *
 * There are dozens of call sites and more arrive every week. A rule that depends on every component
 * remembering to check a flag is a rule that holds until the next feature. This interceptor is the
 * one place every authenticated request passes through, so «once a sign-out has begun, no further
 * authenticated request is sent» is true by construction.
 *
 * ## What is deliberately still allowed
 *
 * `/auth/logout` itself, and the CSRF prime it needs. Blocking those would block the sign-out.
 * Nothing else goes out — a refused request rejects immediately rather than being queued, because a
 * queue would simply move the race to the other side of the navigation.
 */
let signingOut = false

/** Everything the barrier must let through — the sign-out's own traffic, and nothing else. */
const SIGN_OUT_ALLOWED = ['/auth/logout']

/**
 * Raise the barrier. Called BEFORE `/auth/logout` is sent, never after.
 *
 * Irreversible on purpose: a sign-out ends with a hard navigation that rebuilds every module, so
 * there is no state to restore and nothing legitimate that needs the flag lowered again. `reset` is
 * exported for tests only.
 */
export function beginSignOut(): void {
  signingOut = true
}

/** True once a sign-out has begun. Read by tests and by the query layer. */
export function isSigningOut(): boolean {
  return signingOut
}

/** Tests only — the browser never lowers this, because a real sign-out ends in a full page load. */
export function resetSignOutBarrier(): void {
  signingOut = false
}

api.interceptors.request.use((config) => {
  if (!signingOut) return config

  const url = config.url ?? ''
  if (SIGN_OUT_ALLOWED.some((allowed) => url.startsWith(allowed))) return config

  /*
   * Rejected, not cancelled-and-retried. The caller sees a normal failure and the query layer
   * discards it; what matters is that the request never reaches the server, so it can never come
   * back carrying a session cookie.
   */
  return Promise.reject(new SignedOutError(url))
})

/** The rejection the barrier raises, named so a caller can tell it from a network failure. */
export class SignedOutError extends Error {
  constructor(url: string) {
    super(`Request to ${url} was not sent: a sign-out is in progress.`)
    this.name = 'SignedOutError'
  }
}

/** `/portal/clients/<slug>/...` — the URL segment that names an isolated client space. */
export const CLIENT_SPACE_PREFIX = '/portal/clients/'

/**
 * The client space named in a path, or null when the path is not inside one.
 *
 * Read from the URL rather than held in a store: the URL is what the user shares, bookmarks and
 * reloads, so it is the only thing that cannot drift out of step with the space they are looking at.
 * A cached copy would survive a navigation and address the previous space.
 */
export function clientSpaceSlugOf(pathname: string): string | null {
  if (!pathname.startsWith(CLIENT_SPACE_PREFIX)) return null
  const slug = pathname.slice(CLIENT_SPACE_PREFIX.length).split('/')[0]

  return slug === '' ? null : decodeURIComponent(slug)
}

/**
 * Client-portal requests carry the space the user is currently in (PORTAL-CLIENT-001).
 *
 * Attached here, once, rather than threaded through every `/client/*` call site: there are more than
 * twenty of them, and the one that gets forgotten is the one that shows another brand's data. The
 * server treats the slug as a claim to check, not a fact — it resolves it against the spaces the
 * contact actually owns and refuses anything else with a 404.
 */
api.interceptors.request.use((config) => {
  // The API prefix is still `/client/*`; it is the BROWSER paths that moved to `/portal/*`
  // (ADR 0002). Matching on the API prefix keeps this correct either way.
  if (!config.url?.startsWith('/client/') || typeof window === 'undefined') return config

  const slug = clientSpaceSlugOf(window.location.pathname)
  if (slug !== null) config.headers.set('X-Client-Space', slug)

  return config
})

/** Prime the CSRF cookie before the first unsafe (POST/PUT/DELETE) request. */
export async function ensureCsrfCookie(): Promise<void> {
  await axios.get('/sanctum/csrf-cookie', { withCredentials: true })
}

/** Normalized error surfaced to the UI. */
export interface ApiError {
  message: string
  status?: number
  errors: Record<string, string[]> | null
  /**
   * The envelope's `meta`, carried through (LOGIN-003).
   *
   * Some refusals are not failures the user should merely be told about — they come with the
   * information needed to recover. A portal mismatch is the case that forced this: the response
   * names the portal that was refused AND where this account actually belongs, so the form can
   * offer a way through instead of a dead end. Dropping `meta` here meant the message was all the
   * UI ever saw.
   */
  meta: Record<string, unknown> | null
  /**
   * What KIND of failure this was, so callers can act and not only speak.
   *
   * `offline` is reserved for a request that got no answer at all; `http` means a server replied
   * and the status is meaningful; `timeout` is us giving up waiting, which is not the same as the
   * customer being disconnected.
   */
  kind: 'http' | 'offline' | 'timeout' | 'unexpected'
}

/**
 * Normalise anything that was thrown into something the interface can say out loud.
 *
 * The failure this replaces: the client had two answers — the envelope's message, or «A network
 * error occurred» — so every response it could not parse became a claim about the customer's
 * internet. Verified against the running stack: with the API down the dev proxy returns 502 with an
 * EMPTY `text/plain` body, which is a response, so `response.data` is `''` and the envelope lookup
 * yields nothing. A gateway timeout, an HTML error page and a bug in our own code all landed in the
 * same place.
 *
 * Three cases now, and they are genuinely different:
 *   1. an envelope — the server said something specific, and it is already translated;
 *   2. a status without a usable body — described from the status, because a status is a fact;
 *   3. no response at all — the only thing that is really a network problem.
 */
export function toApiError(error: unknown): ApiError {
  const locale = useUi.getState().locale
  const axiosError = error as AxiosError<ApiEnvelope<unknown>> | undefined
  const response = axiosError?.response
  const envelope = response?.data

  // A body that is not our envelope — an empty proxy response, an HTML error page — must not be
  // read as one. `message` is only trusted when it is actually a string.
  const envelopeMessage = typeof envelope?.message === 'string' && envelope.message !== ''
    ? envelope.message
    : undefined

  /*
   * `request` without `response` is axios saying it sent something and heard nothing back. A
   * timeout is reported separately because "we gave up waiting" is not "you are offline".
   */
  const sentButUnanswered = response === undefined && Boolean(axiosError?.request)
  const timedOut = isTimeout(axiosError?.code)

  return {
    message: describeFailure({
      status: response?.status,
      sentButUnanswered: sentButUnanswered || timedOut,
      envelopeMessage,
    }, locale),
    status: response?.status,
    errors: (envelope?.errors as Record<string, string[]> | undefined) ?? null,
    meta: (envelope as { meta?: Record<string, unknown> } | undefined)?.meta ?? null,
    /*
     * Named so a caller can behave differently rather than only speak differently — a retry button
     * makes sense when nothing answered, and makes no sense on a 403.
     */
    kind: timedOut ? 'timeout' : sentButUnanswered ? 'offline' : response ? 'http' : 'unexpected',
  }
}

export async function getData<T>(url: string): Promise<T> {
  const response = await api.get<ApiEnvelope<T>>(url)
  return response.data.data
}

/**
 * The whole envelope, for the few callers that need `meta` as well as `data`.
 *
 * Most do not: `meta` carries a request id and nothing else worth reading. The exception is a list
 * the server has BOUNDED — the page has to be able to say "the most recent 100 of 316" rather than
 * present a truncated list as the whole set, and `getData` throws the count away.
 */
export async function getEnvelope<T>(url: string): Promise<ApiEnvelope<T>> {
  const response = await api.get<ApiEnvelope<T>>(url)
  return response.data
}

export async function postData<T>(url: string, body?: unknown): Promise<T> {
  const response = await api.post<ApiEnvelope<T>>(url, body)
  return response.data.data
}

export async function putData<T>(url: string, body?: unknown): Promise<T> {
  const response = await api.put<ApiEnvelope<T>>(url, body)
  return response.data.data
}

export async function patchData<T>(url: string, body?: unknown): Promise<T> {
  const response = await api.patch<ApiEnvelope<T>>(url, body)
  return response.data.data
}

export async function deleteData<T>(url: string, body?: unknown): Promise<T> {
  const response = await api.delete<ApiEnvelope<T>>(url, body === undefined ? undefined : { data: body })
  return response.data.data
}

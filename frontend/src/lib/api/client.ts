import axios, { AxiosError } from 'axios'
import type { ApiEnvelope } from './types'

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
}

export function toApiError(error: unknown): ApiError {
  const axiosError = error as AxiosError<ApiEnvelope<unknown>>
  const envelope = axiosError.response?.data
  return {
    message: envelope?.message ?? 'A network error occurred. Please try again.',
    status: axiosError.response?.status,
    errors: envelope?.errors ?? null,
  }
}

export async function getData<T>(url: string): Promise<T> {
  const response = await api.get<ApiEnvelope<T>>(url)
  return response.data.data
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

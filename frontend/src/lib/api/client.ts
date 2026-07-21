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

export async function deleteData<T>(url: string): Promise<T> {
  const response = await api.delete<ApiEnvelope<T>>(url)
  return response.data.data
}

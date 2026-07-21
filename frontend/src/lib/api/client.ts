import axios, { AxiosError } from 'axios'
import type { ApiEnvelope } from './types'

/**
 * Central Axios client. Points at the versioned API and carries credentials so a future migration
 * to Sanctum cookie-session auth needs no call-site changes. A bearer token (when present) is
 * attached from the in-memory auth store.
 */
export const api = axios.create({
  baseURL: '/api/v1',
  withCredentials: true,
  headers: { Accept: 'application/json' },
})

let authToken: string | null = null
export function setAuthToken(token: string | null): void {
  authToken = token
}

api.interceptors.request.use((config) => {
  if (authToken) {
    config.headers.Authorization = `Bearer ${authToken}`
  }
  return config
})

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

/** Unwrap the envelope's `data` for a GET. */
export async function getData<T>(url: string): Promise<T> {
  const response = await api.get<ApiEnvelope<T>>(url)
  return response.data.data
}

/** Unwrap the envelope's `data` for a POST. */
export async function postData<T>(url: string, body?: unknown): Promise<T> {
  const response = await api.post<ApiEnvelope<T>>(url, body)
  return response.data.data
}

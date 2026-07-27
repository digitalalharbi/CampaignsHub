import { api, ensureCsrfCookie, getData } from '@/lib/api/client'
import type { AuthUser } from '@/lib/api/types'

export const previewInvite = (token: string) =>
  getData<{ email: string; workspace_name: string; role_slug: string }>(`/invitations/${encodeURIComponent(token)}`)

export async function acceptInvite(token: string, name: string, password: string): Promise<AuthUser> {
  // Prime the CSRF cookie so the (first unsafe) accept POST is never rejected on a fresh guest session.
  await ensureCsrfCookie()
  // The response returns the fully-formed, logged-in user — callers use it directly rather than making a
  // second /auth/me round-trip, which would race the session cookie the accept response just regenerated.
  const res = await api.post<{ data: { user: AuthUser } }>('/invitations/accept', { token, name, password })
  return res.data.data.user
}

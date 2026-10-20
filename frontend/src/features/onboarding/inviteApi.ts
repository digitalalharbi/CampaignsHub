import { api, ensureCsrfCookie, getData } from '@/lib/api/client'
import type { AuthUser } from '@/lib/api/types'

export const previewInvite = (token: string) =>
  getData<{ email: string; workspace_name: string; role_slug: string; existing_account?: boolean }>(`/invitations/${encodeURIComponent(token)}`)

export async function acceptInvite(token: string, name: string, password: string): Promise<AuthUser> {
  // Prime the CSRF cookie so the (first unsafe) accept POST is never rejected on a fresh guest session.
  await ensureCsrfCookie()
  // The response returns the fully-formed, logged-in user — callers use it directly rather than making a
  // second /auth/me round-trip, which would race the session cookie the accept response just regenerated.
  const res = await api.post<{ data: { user: AuthUser } }>('/invitations/accept', { token, name, password })
  return res.data.data.user
}

/**
 * AGENCY-PROVISION-001 — claim an invitation while signed in as the invited address, then switch into the
 * agency just joined. The server compares the signed-in, verified email with the invited one; the token
 * alone grants nothing.
 */
export async function acceptExistingInvite(token: string): Promise<string> {
  await ensureCsrfCookie()
  const res = await api.post<{ data: { membership_id: string } }>('/invitations/accept-existing', { token })
  const membershipId = res.data.data.membership_id
  await api.post('/auth/memberships/switch', { membership_id: membershipId })

  return membershipId
}

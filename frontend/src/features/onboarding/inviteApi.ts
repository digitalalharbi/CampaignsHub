import { api, getData } from '@/lib/api/client'
import type { AuthUser } from '@/lib/api/types'

export const previewInvite = (token: string) =>
  getData<{ email: string; workspace_name: string; role_slug: string }>(`/invitations/${encodeURIComponent(token)}`)

export async function acceptInvite(token: string, name: string, password: string): Promise<AuthUser> {
  const res = await api.post<{ data: { user: AuthUser } }>('/invitations/accept', { token, name, password })
  return res.data.data.user
}

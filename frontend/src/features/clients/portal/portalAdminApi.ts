import { deleteData, getData, postData } from '@/lib/api/client'

/** CP-USERS-001 — the people who can open one client's portal, as the agency API answers it. */
export interface PortalUser {
  membership_id?: string
  invitation_id?: string
  user_id?: number
  name?: string | null
  email: string | null
  role: string
  projects: string[] | null
  state: 'active' | 'invited'
  expires_at?: string
}

export const PORTAL_ROLES = ['client_viewer', 'client_approver', 'client_admin'] as const

export const listPortalUsers = (clientId: string) => getData<{ users: PortalUser[] }>(`/app/clients/${clientId}/portal-users`).then((d) => d.users)
export const invitePortalUser = (clientId: string, body: { email: string; role: string; project_ids?: string[] }) =>
  postData<{ state: 'granted' | 'invited'; dev_link: string | null }>(`/app/clients/${clientId}/portal-users`, body)
export const revokePortalUser = (clientId: string, membershipId: string) => deleteData(`/app/clients/${clientId}/portal-users/${membershipId}`)

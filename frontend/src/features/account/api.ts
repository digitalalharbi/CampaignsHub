import { deleteData, getData, patchData } from '@/lib/api/client'
import type { AuthUser } from '@/lib/api/types'

export interface ProfileInput {
  name?: string
  first_name?: string | null
  last_name?: string | null
  job_title?: string | null
  phone?: string | null
  bio?: string | null
  locale?: 'ar' | 'en'
  timezone?: string
  date_format?: string
  number_format?: 'latin' | 'arabic'
  theme?: 'light' | 'dark' | 'system'
}

export interface PasswordInput {
  current_password: string
  password: string
  password_confirmation: string
  logout_other_devices?: boolean
}

export interface SessionInfo {
  current: {
    ip: string | null
    user_agent: string
    browser: string
    platform: string
    last_active_at: string
  }
  others_available: boolean
}

/** GET /api/me — full profile + menu-header fields. */
export const getMe = () => getData<AuthUser>('/me')

/** PATCH /api/me/profile — returns the updated user so the UI can refresh topbar/sidebar immediately. */
export const updateProfile = (input: ProfileInput) => patchData<AuthUser>('/me/profile', input)

/** PATCH /api/me/password — returns { status }. */
export const updatePassword = (input: PasswordInput) => patchData<{ status: string }>('/me/password', input)

export const getSessions = () => getData<SessionInfo>('/me/sessions')

export const logoutOtherSessions = (current_password: string) =>
  deleteData<{ status: string }>('/me/sessions/others', { current_password })

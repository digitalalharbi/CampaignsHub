import { deleteData, getData, patchData, postData } from '@/lib/api/client'
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

// ---- The mobile number as a credential (AUTH-PHONE-001) ------------------------------------------

/**
 * What the server says about this account's number, and about the channels that could reach it.
 *
 * `confirmed` is the whole point: a number typed into a profile is a contact detail, and only a code
 * answered from the handset turns it into something that can sign anybody in. `channels` is reported
 * per channel because it decides what the screen may OFFER — with `whatsapp: false` there is no
 * WhatsApp provider wired, and presenting WhatsApp as a way in would be a button that cannot work.
 */
export interface PhoneCredential {
  phone: string | null
  confirmed: boolean
  confirmed_at: string | null
  channels: { sms: boolean; whatsapp: boolean }
}

export interface PhoneChallenge {
  verification_id: string
  /** `queued` · `sent` — or `awaiting_provider_credentials`, meaning NOTHING was sent to anybody. */
  delivery_status: string
  resend_after: number
  /** Non-production only; hard-gated server-side, so this is null in production. */
  dev_code: string | null
}

export const getPhoneCredential = () => getData<PhoneCredential>('/me/phone')

export const startPhoneConfirmation = (phone: string, channel: 'sms' | 'whatsapp') =>
  postData<PhoneChallenge>('/me/phone/start', { phone, channel })

export const confirmPhone = (verification_id: string, code: string) =>
  postData<{ phone: string; confirmed: boolean }>('/me/phone/confirm', { verification_id, code })

export const revokePhoneCredential = () => deleteData<{ confirmed: boolean }>('/me/phone')

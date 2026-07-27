import { api, postData } from '@/lib/api/client'
import type { AccountEntitlements, AuthUser } from '@/lib/api/types'

/** Email verification (verify is public; the link carries the token). */
export async function verifyEmail(token: string): Promise<AuthUser> {
  const res = await api.post<{ data: { user: AuthUser } }>('/auth/email/verify', { token })
  return res.data.data.user
}

export const resendVerification = () =>
  postData<{ email_verification?: { delivery_status: string; dev_link: string | null }; already_verified?: boolean }>('/auth/email/resend')

/** Onboarding steps — each returns the updated account entitlements. */
const account = async (url: string, body?: unknown): Promise<AccountEntitlements> => {
  const res = await api.post<{ data: { account: AccountEntitlements } }>(url, body ?? {})
  return res.data.data.account
}

export const setAccountType = (accountType: string) => account('/onboarding/account-type', { account_type: accountType })
export const setService = (service: 'paid_media' | 'influencer_marketing' | 'combined') => account('/onboarding/service', { service })
export const setWorkspace = (body: { name: string; currency?: string; timezone?: string; language?: string; week_start?: string }) => account('/onboarding/workspace', body)
export const setFirstClient = (name: string) => account('/onboarding/first-client', { name })
export const setFirstProject = (body: { name: string; client_id?: string }) => account('/onboarding/first-project', body)
export const completeOnboarding = () => account('/onboarding/complete')

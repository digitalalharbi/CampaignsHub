import { ensureCsrfCookie, getData, postData } from '@/lib/api/client'
import type { AuthUser } from '@/lib/api/types'

/**
 * The gated registration path (SIGNUP-002).
 *
 * Applying no longer returns a user, because applying no longer creates one. What comes back is an
 * APPLICATION: an id to come back to, the state it is in, and — when there is one — the single thing
 * the applicant can do next. Everything else is waiting on us.
 */

/** The twelve account states, as they reach the browser. Rendered from `label`, never from this. */
export type RegistrationState =
  | 'draft'
  | 'email_verification_required'
  | 'mobile_verification_required'
  | 'pending_approval'
  | 'approved_awaiting_payment'
  | 'payment_pending'
  | 'active'
  | 'past_due'
  | 'suspended'
  | 'rejected'
  | 'cancelled'
  | 'expired'

export interface RegistrationStatus {
  id: string
  state: RegistrationState
  /** Already translated by the server — the browser does not own the vocabulary of account states. */
  label: string
  email: string
  requested_portal: string | null
  plan_code: string | null
  email_verified: boolean
  mobile_verified: boolean
  /** The one thing this applicant can do now, or null when the application is waiting on us. */
  next_step: string | null
  reason: string | null
  provisioned: boolean
}

export interface RegistrationPolicy {
  requires_mobile: boolean
  requires_approval: boolean
  requires_payment: boolean
}

export interface VerificationIssued {
  channel: 'email' | 'mobile'
  /** Never 'sent' while no provider is configured — see RegistrationVerificationService. */
  delivery_status: string
  /** Non-production only. The journey stays walkable without pretending a message was delivered. */
  dev_link: string | null
  dev_code: string | null
  expires_at: string
}

export interface RegistrationEnvelope {
  registration: RegistrationStatus
  policy: RegistrationPolicy
  verification?: VerificationIssued
  /** Present only once the application has become a workspace and a session was opened. */
  user?: AuthUser | null
}

export interface ApplyInput {
  tenant_name: string
  name: string
  email: string
  password: string
  password_confirmation: string
  account_type?: string
  service?: 'paid_media' | 'influencer_marketing' | 'combined'
  requested_portal?: string
  plan_code?: string
  phone?: string
}

export async function apply(input: ApplyInput): Promise<RegistrationEnvelope> {
  await ensureCsrfCookie()
  return postData<RegistrationEnvelope>('/auth/register', input)
}

export function fetchRegistration(id: string): Promise<RegistrationEnvelope> {
  return getData<RegistrationEnvelope>(`/auth/registration/${id}`)
}

export async function verifyRegistrationEmail(token: string): Promise<RegistrationEnvelope> {
  await ensureCsrfCookie()
  return postData<RegistrationEnvelope>('/auth/registration/verify-email', { token })
}

export async function verifyRegistrationMobile(id: string, code: string): Promise<RegistrationEnvelope> {
  await ensureCsrfCookie()
  return postData<RegistrationEnvelope>(`/auth/registration/${id}/verify-mobile`, { code })
}

export async function resendRegistrationChallenge(
  id: string,
  channel: 'email' | 'mobile',
): Promise<RegistrationEnvelope> {
  await ensureCsrfCookie()
  return postData<RegistrationEnvelope>(`/auth/registration/${id}/resend`, { channel })
}

/**
 * Where the applicant left off, so returning to the site does not mean starting again.
 *
 * The id alone — never the email, never anything about the plan. It is the key to a status screen
 * and nothing more, and a browser that has been handed one should not also be holding a copy of the
 * application.
 */
const KEY = 'chub:registration'

export const rememberRegistration = (id: string) => {
  try { localStorage.setItem(KEY, id) } catch { /* private mode; the URL still carries the id */ }
}

export const recallRegistration = (): string | null => {
  try { return localStorage.getItem(KEY) } catch { return null }
}

export const forgetRegistration = () => {
  try { localStorage.removeItem(KEY) } catch { /* nothing to clean up */ }
}

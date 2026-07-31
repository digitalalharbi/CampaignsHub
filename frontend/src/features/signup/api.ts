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
  billing_interval?: BillingInterval
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

/*
 * The plan catalogue, as the sign-up form needs it (PLAN-001).
 *
 * Read from the server rather than kept as a constant here, because the price a visitor is shown and
 * the amount a checkout charges have to be the same statement. A catalogue duplicated into the
 * browser is one that will eventually advertise a price nobody is billed.
 */

export type BillingInterval = 'monthly' | 'annual'

export interface Plan {
  code: string
  name: string
  name_ar: string
  summary_ar: string | null
  summary_en: string | null
  currency: string
  price_monthly: string
  /** Null means the plan is not sold on an annual term — never a reason to show the monthly price. */
  price_annual: string | null
  trial_days: number
  trial_fee: string
  features: Record<string, unknown> | null
  limits: Record<string, number | null> | null
  trial_limits: Record<string, number | null> | null
}

export interface PlanQuote {
  plan_code: string
  currency: string
  interval: BillingInterval
  /** What is taken TODAY — the trial fee when the plan starts with one. */
  due_now: string
  /** What falls due when the trial converts, or null when there is no trial. */
  due_later: string | null
  renews_in_days: number
  trial_days: number
  trial_fee: string | null
}

export function fetchPlans(): Promise<{ plans: Plan[] }> {
  return getData('/plans')
}

export function fetchQuote(code: string, interval: BillingInterval): Promise<{ quote: PlanQuote }> {
  return getData(`/plans/${code}/quote?interval=${interval}`)
}

/*
 * Paying for what was chosen (PAY-002).
 *
 * There is deliberately no function here that reports a payment as made. Returning from the gateway's
 * page proves nothing — only a signed webhook does — so the browser's job ends at opening the
 * checkout and asking the server what happened afterwards.
 */

export interface PaymentProviderState {
  provider: string
  is_default: boolean
  /** `live` or `awaiting_credentials` — reported by the server, never guessed at here. */
  status: string
  available: boolean
}

export interface CheckoutResult {
  payment: { id: string; status: string; amount: string; currency: string; provider: string }
  /** Null whenever no gateway is configured, or the charge is already settled. */
  checkout_url: string | null
  status: 'created' | 'awaiting_credentials' | 'failed' | 'refused' | string
  /** Which identities already had a trial, when one is refused (PAY-004). */
  refused: string[]
}

export function fetchPaymentProviders(): Promise<{ providers: PaymentProviderState[] }> {
  return getData('/payments/providers')
}

export async function startCheckout(registrationId: string): Promise<CheckoutResult> {
  await ensureCsrfCookie()
  return postData<CheckoutResult>(`/auth/registration/${registrationId}/checkout`, {})
}

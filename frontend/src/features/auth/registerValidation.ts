import { normalisePhone } from '@/lib/phone'

/**
 * SIGNUP-STEP-001 — everything about the ACCOUNT is decided on the account step.
 *
 * The form used to check nothing in the browser. A weak password, a malformed address or a
 * confirmation that did not match all travelled through to the final submit, which happens on the
 * PACKAGES step — so the visitor picked a plan, pressed "create account", and was shown «كلمة المرور
 * ضعيفة» beside a price list. The field it referred to was on a screen they had left, and the error
 * summary at the top of the packages step made the packages step look broken.
 *
 * These rules deliberately mirror `RegisterRequest` on the server rather than improving on it. A
 * client rule the server does not share rejects an account the platform would have accepted; a
 * server rule the client does not share is the bug above. Where they must differ — `unique:users`
 * cannot be answered here — the server stays the authority and the form sends the visitor back to
 * the field, which `RegisterPage` does.
 */

export interface AccountFields {
  tenant_name: string
  name: string
  email: string
  /** The national number as typed. `dial_code` says which country to read it in. */
  phone: string
  dial_code: string
  password: string
  password_confirmation: string
}

/** Field name → the message shown beside that field. Empty object means the step is valid. */
export type AccountErrors = Partial<Record<keyof AccountFields, string>>

const COPY = {
  ar: {
    required: 'هذا الحقل مطلوب.',
    tooLong: (n: number) => `الحد الأقصى ${n} حرفًا.`,
    email: 'أدخل بريدًا إلكترونيًا صحيحًا.',
    passwordShort: 'كلمة المرور يجب أن تكون 8 أحرف على الأقل.',
    passwordLetters: 'كلمة المرور يجب أن تحتوي على حرف واحد على الأقل.',
    passwordNumbers: 'كلمة المرور يجب أن تحتوي على رقم واحد على الأقل.',
    mismatch: 'تأكيد كلمة المرور لا يطابق كلمة المرور.',
    phone: 'أدخل رقم جوال صحيح، مثل 0501234567.',
  },
  en: {
    required: 'This field is required.',
    tooLong: (n: number) => `Use at most ${n} characters.`,
    email: 'Enter a valid email address.',
    passwordShort: 'Use at least 8 characters.',
    passwordLetters: 'Include at least one letter.',
    passwordNumbers: 'Include at least one number.',
    mismatch: 'The confirmation does not match the password.',
    phone: 'Enter a valid mobile number, for example 0501234567.',
  },
} as const

/**
 * A deliberately permissive address check.
 *
 * The server validates with `email:rfc`, which accepts more than any regex worth writing. Anything
 * stricter here would refuse addresses the platform is happy to register — and the visitor would
 * have no way to tell that the form, not their address, was wrong.
 */
const LOOKS_LIKE_EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

/** The password rules, as `Password::min(8)->letters()->numbers()` states them. */
export function passwordProblem(password: string, ar: boolean): string | undefined {
  const c = COPY[ar ? 'ar' : 'en']
  if (password.length < 8) return c.passwordShort
  if (!/\p{L}/u.test(password)) return c.passwordLetters
  if (!/\d/.test(password)) return c.passwordNumbers
  return undefined
}

export function validateAccountStep(form: AccountFields, ar: boolean): AccountErrors {
  const c = COPY[ar ? 'ar' : 'en']
  const errors: AccountErrors = {}

  const required = (key: keyof AccountFields, max?: number) => {
    const value = form[key].trim()
    if (value === '') { errors[key] = c.required; return }
    if (max !== undefined && value.length > max) errors[key] = c.tooLong(max)
  }

  required('tenant_name', 120)
  required('name', 120)
  required('email', 190)

  /*
   * The mobile number is required (PHONE-VERIFY-001).
   *
   * No account activates without a verified one, so an application that carries none can never clear
   * the mobile gate — it would sit at a verification step with nothing to verify. Asking here, where
   * the applicant is already filling a form, is the only kind version of that rule.
   */
  if (form.phone.trim() === '') {
    errors.phone = c.required
  } else if (normalisePhone(form.phone, form.dial_code) === null) {
    errors.phone = c.phone
  }

  if (!errors.email && !LOOKS_LIKE_EMAIL.test(form.email.trim())) errors.email = c.email

  if (form.password === '') {
    errors.password = c.required
  } else {
    const problem = passwordProblem(form.password, ar)
    if (problem) errors.password = problem
  }

  if (form.password_confirmation === '') {
    errors.password_confirmation = c.required
  } else if (form.password !== form.password_confirmation) {
    errors.password_confirmation = c.mismatch
  }

  return errors
}

/**
 * Which step a server-side error belongs to.
 *
 * The server can refuse something only it knows — an address already registered, a plan withdrawn
 * between the page loading and the submit. Those two answers belong on different screens, and
 * showing either on the wrong one is the failure this module exists to remove.
 */
export const ACCOUNT_FIELDS: readonly string[] = [
  'tenant_name', 'name', 'email', 'phone', 'password', 'password_confirmation',
]

export function belongsToAccountStep(fields: readonly string[]): boolean {
  return fields.some((f) => ACCOUNT_FIELDS.includes(f))
}

import { useEffect, useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { useMutation } from '@tanstack/react-query'
import { ArrowLeft, KeyRound, Mail, Smartphone } from 'lucide-react'
import { login, phoneSignInStart, phoneSignInVerify, signInMethod } from './api'
import { resolvePostAuthOutcome } from './postAuthDestination'
import { AuthShell } from './AuthShell'
import { Button } from '@/components/ui/Button'
import { EmailInput, PasswordInput } from '@/components/ui/form'
import { PhoneField, phoneFieldValue, DEFAULT_DIAL_CODE } from '@/components/ui/PhoneField'
import { SocialSignIn } from './SocialSignIn'
import { toApiError } from '@/lib/api/client'
import { useT } from '@/lib/i18n'
import { portalLoginStart, portalLoginVerify } from '@/features/requests/clientPortalApi'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'

/**
 * LOGIN-UNIFIED-001 + LOGIN-PATHS-001 — one page, two ways in, and the server decides the rest.
 *
 * ## What was removed, and why it had to be
 *
 * This page used to open with a row of portal tabs: «إدارة الحملات», «وكالة», «متابعة الطلبات». It
 * asked the visitor a question only the system can answer — the right one depends on the account,
 * which is the thing they have not identified yet at the moment of asking. Five other doors existed
 * too (`/admin/login`, `/app/login`, …); those addresses still work and redirect here.
 *
 * ## The shape now: pick your method, not your portal
 *
 * Two paths, side by side inside the box:
 *
 *   1. **البريد الإلكتروني** — email and password.
 *   2. **رقم الجوال** — a phone number and a one-time code.
 *
 * Choosing between them is not choosing a portal. It is saying which credential you actually hold,
 * which is a question the visitor CAN answer — where the previous design, a single identifier field
 * that made the server guess, asked them to trust an invisible rule.
 *
 * **The URL still grants nothing.** `login()` is called with `portal: null` on every path, so there
 * is no preference for the server to honour and no way for a link to request access. The destination
 * comes from `resolvePostAuthOutcome` — from real memberships — every time.
 *
 * ## The email path can still end in a code
 *
 * A client contact has an address and has never had a password. `POST /auth/method` is still asked
 * before the password step renders, so an address that signs in by code gets the code step instead of
 * a password field it could never fill. The visitor is not told a rule; they are shown the right form.
 *
 * ## The layout is `AuthShell`, the same as registration
 *
 * Not a copy of it — the component itself. The two pages sit either side of one panel and were
 * drifting apart in width, padding and header every time either was touched; the sign-in box had
 * become a small framed dialog beside registration's full column.
 */

const COPY = {
  ar: {
    title: 'مرحباً بعودتك',
    subtitle: 'سجّل الدخول للمتابعة إلى مساحة عملك.',
    tabEmail: 'البريد الإلكتروني',
    tabPhone: 'رقم الجوال',
    email: 'البريد الإلكتروني',
    phone: 'رقم الجوال',
    phoneHint: 'سنرسل رمز تحقق إلى هذا الرقم.',
    continue: 'متابعة',
    sendCode: 'إرسال رمز التحقق',
    back: 'استخدام حساب آخر',
    noAccount: 'ليس لديك حساب؟',
    register: 'إنشاء حساب',
    codeTitle: 'أدخل رمز التحقق',
    codeSentEmail: 'أرسلنا رمزًا إلى بريدك الإلكتروني.',
    codeSentSms: 'أرسلنا رمزًا إلى رقم جوالك.',
    code: 'رمز التحقق',
    verify: 'تأكيد الرمز',
    resend: 'إعادة إرسال الرمز',
    phoneInvalid: 'أدخل رقم جوال صحيحًا.',
  },
  en: {
    title: 'Welcome back',
    subtitle: 'Sign in to continue to your workspace.',
    tabEmail: 'Email address',
    tabPhone: 'Mobile number',
    email: 'Email address',
    phone: 'Mobile number',
    phoneHint: 'We will send a verification code to this number.',
    continue: 'Continue',
    sendCode: 'Send verification code',
    back: 'Use a different account',
    noAccount: "Don't have an account?",
    register: 'Create an account',
    codeTitle: 'Enter your verification code',
    codeSentEmail: 'We sent a code to your email address.',
    codeSentSms: 'We sent a code to your mobile number.',
    code: 'Verification code',
    verify: 'Verify code',
    resend: 'Send the code again',
    phoneInvalid: 'Enter a valid mobile number.',
  },
} as const

type Path = 'email' | 'phone'
type Step = 'credentials' | 'password' | 'code'

export function LoginPage() {
  const t = useT()
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const setUser = useAuth((s) => s.setUser)
  const status = useAuth((s) => s.status)
  const { locale } = useUi()
  const c = COPY[locale]
  const ar = locale === 'ar'

  const [path, setPath] = useState<Path>('email')
  const [step, setStep] = useState<Step>('credentials')

  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [remember, setRemember] = useState(true)

  const [phone, setPhone] = useState('')
  const [dialCode, setDialCode] = useState(DEFAULT_DIAL_CODE)
  const [phoneError, setPhoneError] = useState<string | null>(null)

  const [channel, setChannel] = useState<'email' | 'sms'>('email')
  const [verificationId, setVerificationId] = useState<string | null>(null)
  const [code, setCode] = useState('')

  /*
   * Already signed in? Then this page is not for you.
   *
   * Reachable more often than it looks: Back after signing in, a bookmarked `/login`, a second tab,
   * or the brief guest state the session probe passes through on reload. The destination comes from
   * the server, exactly as it does after signing in — the browser never gets a second, different
   * rule for the same question. `null` is passed as the requested portal because this page never
   * requests one.
   */
  useEffect(() => {
    if (status !== 'authenticated') return

    let cancelled = false
    resolvePostAuthOutcome(params, null)
      .then(({ destination }) => { if (!cancelled) navigate(destination, { replace: true }) })
      // A failed probe is not a reason to strand somebody on a form they do not need.
      .catch(() => undefined)

    return () => { cancelled = true }
  }, [status, params, navigate])

  /**
   * Send a one-time code — to a phone or to an email address.
   *
   * Two engines, because they authenticate two different things. `phoneSignInStart` belongs to the
   * platform and ends in a platform session; `portalLoginStart` belongs to the client portal and ends
   * in a portal session for a contact. Using the portal's for a platform user would sign them into
   * `/portal`, where they hold nothing, and the page would look like it had worked.
   */
  const startCode = useMutation({
    mutationFn: ({ via, destination }: { via: 'email' | 'sms'; destination: string }) =>
      via === 'sms' ? phoneSignInStart(destination) : portalLoginStart(via, destination),
    onSuccess: (r) => {
      setVerificationId(r.verification_id)
      // Dev-only: the backend returns `dev_code` ONLY outside production (hard-gated server-side).
      if (r.dev_code) setCode(r.dev_code)
    },
  })

  /**
   * The email path asks the server which form this address uses before showing one.
   *
   * A client contact holds an address and has never held a password. Rendering a password field for
   * them would be offering a credential their account does not have — so the server says, and the
   * matching step renders.
   */
  const identify = useMutation({
    mutationFn: () => signInMethod(email.trim()),
    onSuccess: (result) => {
      if (result.method === 'code') {
        setChannel('email')
        setStep('code')
        startCode.mutate({ via: 'email', destination: email.trim() })
        return
      }
      setStep('password')
    },
  })

  /**
   * The password step.
   *
   * `portal: null`, always. The browser states no preference, so the server picks the destination
   * from memberships alone and no link can claim access it does not have.
   */
  const signIn = useMutation({
    mutationFn: () => login({ email: email.trim(), password, remember, portal: null }),
    onSuccess: async (user) => {
      setUser(user)
      const { destination } = await resolvePostAuthOutcome(params, null)
      navigate(destination, { replace: true })
    },
  })

  /**
   * Check the code, and go wherever that code's session belongs.
   *
   * A phone code signs in a platform USER, so the destination comes from memberships, exactly as it
   * does after a password. An email code signs in a client CONTACT, whose session belongs to the
   * request portal — a `redirect` inside that space is honoured, and anything outside it ignored,
   * because an absolute or protocol-relative URL there would make this an open redirect.
   */
  const verifyCode = useMutation({
    mutationFn: async () => {
      if (channel === 'sms') {
        const user = await phoneSignInVerify(verificationId!, code, remember)
        setUser(user)
        const { destination } = await resolvePostAuthOutcome(params, null)

        return destination
      }

      await portalLoginVerify(verificationId!, code)
      const wanted = params.get('redirect') ?? ''
      const safe = /^\/(portal|client)(\/|$)/.test(wanted) && !wanted.startsWith('//')

      return safe ? wanted : '/portal'
    },
    onSuccess: (destination) => navigate(destination, { replace: true }),
  })

  const activeError = identify.isError ? toApiError(identify.error)
    : signIn.isError ? toApiError(signIn.error)
      : startCode.isError ? toApiError(startCode.error)
        : verifyCode.isError ? toApiError(verifyCode.error)
          : null

  /** Back to the first step. Clears the secrets; keeps the identifier so it need not be retyped. */
  const startOver = () => {
    setStep('credentials')
    setPassword('')
    setCode('')
    setVerificationId(null)
    identify.reset(); signIn.reset(); startCode.reset(); verifyCode.reset()
  }

  /** Switching path is starting again — the two hold different credentials. */
  const choosePath = (next: Path) => {
    if (next === path) return
    setPath(next)
    startOver()
    setPhoneError(null)
  }

  const submitPhone = () => {
    const e164 = phoneFieldValue(phone, dialCode)
    if (e164 === null) {
      setPhoneError(c.phoneInvalid)
      return
    }
    setPhoneError(null)
    setChannel('sms')
    setStep('code')
    /*
     * The NORMALISED number is what travels.
     *
     * The server normalises again — it must, because a hand-written payload never came through this
     * field — but sending the canonical form means the code goes to the number the customer will
     * recognise, and that `05…`, `9665…` and `+9665…` are one destination rather than three.
     */
    startCode.mutate({ via: 'sms', destination: e164 })
  }

  const identifier = path === 'email' ? email.trim() : (phoneFieldValue(phone, dialCode) ?? phone)

  return (
    <AuthShell portal="default">
      <h2 className="font-heading text-[26px] font-extrabold leading-tight tracking-tight text-text-primary sm:text-[30px]">
        {step === 'code' ? c.codeTitle : c.title}
      </h2>
      <p className="mt-1.5 text-[14.5px] leading-relaxed text-text-secondary">
        {step === 'code' ? (channel === 'sms' ? c.codeSentSms : c.codeSentEmail) : c.subtitle}
      </p>

      {/*
        The two ways in, as a segmented control.

        Only on the first step: once a code has been sent, offering the other method beside it would
        invite somebody to abandon a code that is already on its way to them.
      */}
      {step === 'credentials' && (
        <div
          data-testid="login-paths"
          role="tablist"
          aria-label={c.title}
          className="mt-6 grid grid-cols-2 gap-1.5 rounded-2xl border border-border bg-surface-secondary p-1.5"
        >
          {([
            { key: 'email' as const, label: c.tabEmail, Icon: Mail },
            { key: 'phone' as const, label: c.tabPhone, Icon: Smartphone },
          ]).map(({ key, label, Icon }) => {
            const on = path === key
            return (
              <button
                key={key}
                type="button"
                role="tab"
                aria-selected={on}
                data-testid={`login-path-${key}`}
                onClick={() => choosePath(key)}
                className={`flex h-11 items-center justify-center gap-2 rounded-xl text-[14px] font-bold transition-colors ${
                  on
                    ? 'bg-surface text-text-primary shadow-[var(--shadow-small)]'
                    : 'text-text-secondary hover:text-text-primary'
                }`}
              >
                <Icon size={16} className="shrink-0" /> {label}
              </button>
            )
          })}
        </div>
      )}

      {/* Path 1, step 1 — the address. No password yet: the server has not said there is one. */}
      {step === 'credentials' && path === 'email' && (
        <form
          data-testid="login-identify"
          className="mt-5 space-y-4"
          onSubmit={(e) => { e.preventDefault(); if (email.trim()) identify.mutate() }}
        >
          <EmailInput
            id="login-email"
            label={c.email}
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            autoComplete="username"
            required
            error={activeError?.errors?.identifier?.[0]}
          />
          <ErrorNote error={activeError} />
          <Button type="submit" loading={identify.isPending} className="w-full" size="lg">{c.continue}</Button>
        </form>
      )}

      {/* Path 2, step 1 — the number. Saudi Arabia is the default and needs no typing. */}
      {step === 'credentials' && path === 'phone' && (
        <form
          data-testid="login-phone"
          className="mt-5 space-y-4"
          onSubmit={(e) => { e.preventDefault(); submitPhone() }}
        >
          <PhoneField
            id="login-phone-number"
            label={c.phone}
            value={phone}
            onChange={(v) => { setPhone(v); setPhoneError(null) }}
            dialCode={dialCode}
            onDialCodeChange={setDialCode}
            ar={ar}
            required
            hint={c.phoneHint}
            error={phoneError ?? undefined}
          />
          <ErrorNote error={activeError} />
          <Button type="submit" loading={startCode.isPending} className="w-full" size="lg">
            <Smartphone size={16} /> {c.sendCode}
          </Button>
        </form>
      )}

      {/* Path 1, step 2 — the password, for the addresses the server said have one. */}
      {step === 'password' && (
        <form
          data-testid="login-password"
          className="mt-6 space-y-4"
          onSubmit={(e) => { e.preventDefault(); signIn.mutate() }}
        >
          <IdentifierRow value={identifier} label={c.back} onChange={startOver} />
          <PasswordInput
            id="login-password-field"
            label={t('password')} value={password} onChange={(e) => setPassword(e.target.value)}
            autoComplete="current-password" required autoFocus
            error={activeError?.errors?.password?.[0]}
            showLabel={t('show_password')} hideLabel={t('hide_password')}
          />

          <div className="flex items-center justify-between">
            <label className="flex cursor-pointer items-center gap-2 text-sm text-text-secondary">
              <input type="checkbox" checked={remember} onChange={(e) => setRemember(e.target.checked)} className="h-4 w-4 rounded border-border accent-brand-600" />
              {t('remember_me')}
            </label>
            <Link to="/forgot-password" className="text-sm font-semibold text-brand-600 hover:underline">{t('forgot_password')}</Link>
          </div>

          <ErrorNote error={activeError} />
          <Button type="submit" loading={signIn.isPending} className="w-full" size="lg">{t('sign_in')}</Button>
        </form>
      )}

      {/* Either path, last step — the code. */}
      {step === 'code' && (
        <form
          data-testid="login-code"
          className="mt-6 space-y-4"
          onSubmit={(e) => { e.preventDefault(); if (verificationId && code.trim()) verifyCode.mutate() }}
        >
          <IdentifierRow value={identifier} label={c.back} onChange={startOver} />
          <label className="block">
            <span className="mb-1.5 block text-sm font-semibold text-text-secondary">{c.code}</span>
            <input
              className="min-h-[56px] w-full rounded-[11px] border border-border bg-surface px-4 text-center font-mono text-lg tracking-[0.3em] text-text-primary outline-none transition-colors focus:border-brand-500 focus:ring-[3px] focus:ring-brand-500/15"
              dir="ltr" inputMode="numeric" autoComplete="one-time-code" autoFocus
              value={code} onChange={(e) => setCode(e.target.value)}
            />
          </label>

          <ErrorNote error={activeError} always />

          <Button type="submit" loading={verifyCode.isPending} disabled={!verificationId} className="w-full" size="lg">
            <KeyRound size={16} /> {c.verify}
          </Button>
          <button
            type="button"
            onClick={() => startCode.mutate({ via: channel, destination: identifier })}
            disabled={startCode.isPending}
            className="w-full text-center text-sm font-semibold text-brand-600 hover:underline disabled:opacity-60"
          >
            {c.resend}
          </button>
        </form>
      )}

      {/*
        Google and Apple, on the email path's first step only.

        Once the server has said this identifier signs in by code — or the visitor has asked for one —
        a password-provider button beside it would offer a third thing to try mid-journey. No portal
        travels with the request: like the password form, it states no preference.
      */}
      {step === 'credentials' && path === 'email' && (
        <SocialSignIn portalParam={null} redirect={params.get('redirect')} ar={ar} />
      )}

      <p className="mt-5 text-center text-sm text-text-secondary">
        {c.noAccount} <Link to="/register" className="font-semibold text-brand-600 hover:underline">{c.register}</Link>
      </p>
    </AuthShell>
  )
}

/** The page's one error surface, so a failure never renders twice or in two different shapes. */
function ErrorNote({ error, always = false }: { error: ReturnType<typeof toApiError> | null; always?: boolean }) {
  // Field-level messages are rendered BY the field. Repeating them here would say the same thing
  // twice — except on the code step, which has no field-level slot of its own.
  if (!error || (!always && error.errors)) return null

  return (
    <p data-testid="login-error" role="alert" className="rounded-xl bg-[var(--negative-background)] px-4 py-3 text-sm text-danger">
      {error.message}
    </p>
  )
}

/**
 * The identifier already given, shown back with a way to change it.
 *
 * A multi-step form that hides what you typed in step one is a small trap of its own: somebody who
 * mistyped a character sees a password failure and never learns the address was wrong. This states
 * which account is being signed into, and makes correcting it one click.
 */
function IdentifierRow({ value, label, onChange }: { value: string; label: string; onChange: () => void }) {
  return (
    <div className="flex min-h-[56px] items-center justify-between gap-3 rounded-[11px] border border-border bg-surface-secondary px-4 py-2.5">
      <span className="truncate font-mono text-sm text-text-secondary" dir="ltr">{value}</span>
      <button
        type="button"
        data-testid="login-change-identifier"
        onClick={onChange}
        className="inline-flex shrink-0 items-center gap-1 text-xs font-semibold text-brand-600 hover:underline"
      >
        <ArrowLeft size={13} /> {label}
      </button>
    </div>
  )
}

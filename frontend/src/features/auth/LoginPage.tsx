import { useCallback, useEffect, useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { useMutation, useQuery } from '@tanstack/react-query'
import { ArrowLeft, ArrowRight, Eye, EyeOff, Lock, Mail } from 'lucide-react'
import { fetchSignInOptions, login, phoneSignInStart, phoneSignInVerify, startOAuth } from './api'
import { resolvePostAuthOutcome } from './postAuthDestination'
import { safeRedirect } from './redirect'
import { AuthShell } from './AuthShell'
import { EmailCodeSignIn } from './EmailCodeSignIn'
import { OAuthLinkCard, type LinkedSignIn } from './OAuthLinkCard'
import { OtpField } from './OtpField'
import { HelpRequestModal } from './HelpRequestModal'
import { ErrorNote, IconField } from './loginFields'
import { Button } from '@/components/ui/Button'
import { PhoneField, phoneFieldValue, DEFAULT_DIAL_CODE } from '@/components/ui/PhoneField'
import { toApiError } from '@/lib/api/client'
import { useUi } from '@/stores/ui'
import { recallRegistration } from '@/features/signup/api'
import { useAuth } from '@/stores/auth'

/**
 * LOGIN-CARD-001 — one card, and the server decides where you land.
 *
 * The credentials sit in a single card: address, password, «تذكرني», «نسيت كلمة المرور؟», and one primary button. Below a hairline,
 * Google where the server reports it can work.
 *
 * ## LOGIN-SINGLE-DOOR-001 (Owner, 2026-11-03)
 *
 * This is the only sign-in page, for every kind of account. The email code is not on it unless the server says it is enabled
 * (`email_code`, off by default). A client contact with no password chooses one through «نسيت كلمة المرور؟»: the address they prove
 * by spending that link is what opens their account, and the server then sends them to the portal like anybody else.
 *
 * ## Nothing moves after the first paint
 *
 * Google's row is drawn from the first frame at its final size, as a placeholder until the server answers. A failed lookup keeps the
 * button, and a click on it reports what went wrong. When the server says Google cannot work, the row links home instead: its box keeps
 * its size, so nothing moves and no gap is left.
 *
 * **The URL grants nothing.** `portal: null` travels with every sign-in, so there is no preference for the server to honour and no
 * way for a link to request access it does not hold.
 *
 * `AuthShell`, the marketing panel, the header, the two-column split, the type and the palette are approved and untouched.
 */

const COPY = {
  ar: {
    title: 'مرحباً بعودتك',
    subtitle: 'سجّل الدخول إلى حسابك في كامبينز هب',
    email: 'البريد الإلكتروني',
    emailPlaceholder: 'name@company.com',
    password: 'كلمة المرور',
    passwordPlaceholder: 'أدخل كلمة المرور',
    showPassword: 'إظهار كلمة المرور',
    hidePassword: 'إخفاء كلمة المرور',
    remember: 'تذكرني',
    forgot: 'نسيت كلمة المرور؟',
    signIn: 'تسجيل الدخول',
    noAccount: 'ليس لديك حساب؟',
    register: 'إنشاء حساب',
    or: 'أو',
    home: 'العودة إلى الصفحة الرئيسية',
    emailCode: 'الدخول برمز عبر البريد بدلًا من كلمة المرور',
    codeTitle: 'تحقق من جوالك',
    codeSentTo: 'أرسلنا رمز التحقق إلى:',
    code: 'رمز التحقق',
    resend: 'إعادة إرسال الرمز',
    resendIn: 'إعادة الإرسال بعد :seconds ثانية',
    changeNumber: 'استخدام رقم آخر',
    confirmCode: 'تأكيد الرمز والمتابعة',
    codeExpired: 'انتهت صلاحية رمز التحقق. أعد إرسال الرمز للحصول على رمز جديد.',
    notDelivered: 'لم يُرسل الرمز: خدمة الرسائل غير مفعّلة بعد على هذه البيئة.',
    deliveryFailed: 'تعذّر إرسال الرمز إلى جوالك. جرّب إعادة الإرسال بعد قليل.',
    // DEV/E2E only — never rendered in a production build.
    phone: 'رقم الجوال',
    phoneHint: 'سنرسل رمز تحقق إلى هذا الرقم.',
    phoneInvalid: 'أدخل رقم جوال صحيحًا.',
    continue: 'متابعة',
    google: 'الدخول عبر Google',
  },
  en: {
    title: 'Welcome back',
    subtitle: 'Sign in to your CampaignsHub account',
    email: 'Email address',
    emailPlaceholder: 'name@company.com',
    password: 'Password',
    passwordPlaceholder: 'Enter your password',
    showPassword: 'Show password',
    hidePassword: 'Hide password',
    remember: 'Remember me',
    forgot: 'Forgot your password?',
    signIn: 'Sign in',
    noAccount: "Don't have an account?",
    register: 'Create an account',
    or: 'or',
    home: 'Back to the home page',
    emailCode: 'Sign in with an email code instead',
    codeTitle: 'Check your phone',
    codeSentTo: 'We sent your verification code to:',
    code: 'Verification code',
    resend: 'Send the code again',
    resendIn: 'You can ask again in :seconds seconds',
    changeNumber: 'Use a different number',
    confirmCode: 'Confirm code and continue',
    codeExpired: 'This verification code has expired. Send the code again to get a new one.',
    notDelivered: 'The code was not sent: messaging is not configured on this environment yet.',
    deliveryFailed: 'The code could not be delivered to your phone. Try sending it again in a moment.',
    phone: 'Mobile number',
    phoneHint: 'We will send a verification code to this number.',
    phoneInvalid: 'Enter a valid mobile number.',
    continue: 'Continue',
    google: 'Continue with Google',
  },
} as const

/**
 * The delivery states that mean a message is genuinely on its way to somebody.
 *
 * Everything else — `awaiting_credentials`, `awaiting_provider_credentials`, `sandbox`, `failed` —
 * is said out loud on the page. Written as an allow-list rather than a deny-list on purpose: a state
 * this browser has not been taught about is far more likely to be a new way of NOT arriving than a
 * new way of arriving, and the failure that matters is somebody waiting for a code that never left.
 *
 * `sandbox` is in the honest half deliberately: the transport accepted it and it reached nobody,
 * which is a developer's log file, not an inbox.
 */
const DELIVERED = new Set(['sent', 'queued', 'delivered'])

type Step = 'credentials' | 'code'

/**
 * Where Google sent them back with no session. The page used to say nothing at all: the visitor pressed Google and returned to an
 * empty form. `link_required` and `registration_required` name the way forward without saying whose address is whose.
 */
const OAUTH_NOTES: Record<'ar' | 'en', Record<string, string>> = {
  ar: {
    cancelled: 'أُلغي تسجيل الدخول عبر Google.',
    failed: 'تعذّر إكمال تسجيل الدخول عبر Google. حاول مرة أخرى.',
    unavailable: 'هذا الحساب غير متاح حاليًا.',
    link_required: 'لم يُربط حساب Google هذا بعد. سجّل الدخول بكلمة المرور ثم اربطه من إعدادات حسابك. إن لم تكن لديك كلمة مرور فاستخدم «نسيت كلمة المرور؟».',
    registration_required: 'لا يوجد حساب مرتبط بحساب Google هذا. إن كان بريدك مسجّلًا لدينا فاستخدم «نسيت كلمة المرور؟»، وإلا فأنشئ حسابًا.',
  },
  en: {
    cancelled: 'Google sign-in was cancelled.',
    failed: 'Google sign-in could not be completed. Please try again.',
    unavailable: 'This account is not available at the moment.',
    link_required: 'This Google account is not connected yet. Sign in with your password, then connect it from your account settings. If you have no password, use «Forgot your password?».',
    registration_required: 'No account is connected to this Google account. If we have your email on file, use «Forgot your password?»; otherwise create an account.',
  },
}

export function LoginPage() {
  const navigate = useNavigate()
  const [params, setParams] = useSearchParams()
  const setUser = useAuth((s) => s.setUser)
  const status = useAuth((s) => s.status)
  const { locale } = useUi()
  const c = COPY[locale]
  const oauthProviders = useQuery({ queryKey: ['oauth', 'sign-in-options'], queryFn: fetchSignInOptions })
  const google = oauthProviders.data?.providers.find((provider) => provider.provider === 'google')
  // Reserved until the server has answered; a failed lookup keeps the button, because nothing says Google cannot work.
  const googleRow = oauthProviders.isPending ? 'reserved' : oauthProviders.isError || google?.available === true ? 'button' : 'absent'
  const googleSignIn = useMutation({
    // The page they were interrupted on, for the server to accept or refuse when Google answers: the browser decides nothing here.
    mutationFn: () => startOAuth('google', { redirect: safeRedirect(params.get('redirect'), '') || null }),
    onSuccess: ({ authorize_url }) => window.location.assign(authorize_url),
  })
  const ar = locale === 'ar'

  /*
   * The DEV/E2E escape for the mobile path.
   *
   * `import.meta.env.DEV` is the outer gate, so it is eliminated from a production bundle rather
   * than merely hidden, and `?e2e=phone` is the inner one — so the DEFAULT `/login` is the approved
   * page in every environment. The visual baselines, the live review and the customer all see the
   * same thing, which is the only way a review of it means anything.
   */
  const phoneCompat = import.meta.env.DEV && params.get('e2e') === 'phone'

  // LOGIN-004 — the secret of a mailed link, taken once and then removed from the address bar.
  const [linkToken] = useState(() => (params.get('oauth') === 'link' ? params.get('confirm') : null))
  useEffect(() => {
    if (!params.has('confirm')) return
    const next = new URLSearchParams(params)
    next.delete('confirm')
    setParams(next, { replace: true })
  }, [params, setParams])

  const [registrationId] = useState(recallRegistration)
  const [step, setStep] = useState<Step>('credentials')
  const [emailCodeOpen, setEmailCodeOpen] = useState(false)

  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [showPassword, setShowPassword] = useState(false)
  const [remember, setRemember] = useState(false)

  const [phone, setPhone] = useState('')
  const [dialCode, setDialCode] = useState(DEFAULT_DIAL_CODE)
  const [phoneError, setPhoneError] = useState<string | null>(null)

  const [verificationId, setVerificationId] = useState<string | null>(null)
  const [code, setCode] = useState('')
  const [delivery, setDelivery] = useState<string | null>(null)
  const [cooldown, setCooldown] = useState(0)
  // When the code in hand stops working — stated on the page instead of letting it be typed in vain.
  const [expiresAt, setExpiresAt] = useState<number | null>(null)
  const [expired, setExpired] = useState(false)

  /*
   * Already signed in? Then this page is not for you.
   *
   * Reachable more often than it looks: Back after signing in, a bookmarked `/login`, a second tab,
   * or the brief guest state the session probe passes through on reload. The destination comes from
   * the server, exactly as it does after signing in — the browser never gets a second, different
   * rule for the same question.
   */
  useEffect(() => {
    if (status !== 'authenticated') return
    /*
     * WORKSPACE-SWITCH-001 — the switcher sent this person here because the server refused the session. If the boot probe
     * still answers 200, bouncing onward returns them to the switcher, which sends them back here, with no end to it.
     * Here the chain stops and the form waits for a person.
     */
    if (params.get('reason') === 'session') return

    let cancelled = false
    resolvePostAuthOutcome(params, null)
      .then(({ destination }) => { if (!cancelled) navigate(destination, { replace: true }) })
      // A failed probe is not a reason to strand somebody on a form they do not need.
      .catch(() => undefined)

    return () => { cancelled = true }
  }, [status, params, navigate])

  /** The resend countdown. One interval, cleared on unmount; nothing here outlives the page. */
  useEffect(() => {
    if (cooldown <= 0) return
    const id = window.setInterval(() => setCooldown((s) => (s <= 1 ? 0 : s - 1)), 1000)

    return () => window.clearInterval(id)
  }, [cooldown])

  /** One timer to the code's expiry; cleared when a new code arrives or the page goes. */
  useEffect(() => {
    if (expiresAt === null) return
    const left = expiresAt - Date.now()
    if (left <= 0) { setExpired(true); return }
    const id = window.setTimeout(() => setExpired(true), left)

    return () => window.clearTimeout(id)
  }, [expiresAt])

  const identifier = phoneFieldValue(phone, dialCode) ?? phone

  /** The DEV-only mobile path. */
  const startCode = useMutation({
    mutationFn: (destination: string) => phoneSignInStart(destination),
    onSuccess: (r) => {
      setVerificationId(r.verification_id)
      setDelivery(r.delivery_status ?? null)
      setCooldown('resend_after' in r && typeof r.resend_after === 'number' ? r.resend_after : 60)
      setExpired(false)
      setExpiresAt('expires_in_minutes' in r && typeof r.expires_in_minutes === 'number' ? Date.now() + r.expires_in_minutes * 60_000 : null)
      // Dev-only: the backend returns `dev_code` ONLY outside production (hard-gated server-side).
      if (r.dev_code) setCode(r.dev_code)
    },
    // AUTH-CODE-SWITCHES-001 — the platform turned this code off. Back to the card, where the password and the reset link are.
    onError: (e) => { if (toApiError(e).status === 403) setStep('credentials') },
  })

  /** The password path. `portal: null`, always — the browser states no preference. */
  const signIn = useMutation({
    mutationFn: () => login({ email: email.trim(), password, remember, portal: null }),
    onSuccess: async (user) => {
      setUser(user)
      const { destination } = await resolvePostAuthOutcome(params, null)
      navigate(destination, { replace: true })
    },
  })

  /** Check the code; the destination comes from memberships. */
  const verifyCode = useMutation({
    /*
     * The code travels as a VARIABLE, not out of the closure.
     *
     * The last digit both fills the field and submits, and the state holding it has not been
     * committed at the moment `onComplete` fires — reading `code` here would send five digits and
     * fail a perfectly good sign-in. What was typed is passed in.
     */
    mutationFn: async (typed: string) => {
      const user = await phoneSignInVerify(verificationId!, typed, remember)

      setUser(user)
      const { destination } = await resolvePostAuthOutcome(params, null)

      return destination
    },
    onSuccess: (destination) => navigate(destination, { replace: true }),
  })

  const activeError = signIn.isError ? toApiError(signIn.error)
    : googleSignIn.isError ? toApiError(googleSignIn.error)
      : startCode.isError ? toApiError(startCode.error)
        : verifyCode.isError ? toApiError(verifyCode.error)
          : null

  /** Back to the first step. Clears the secrets. */
  const startOver = () => {
    setStep('credentials')
    setCode('')
    setVerificationId(null)
    setDelivery(null)
    setCooldown(0)
    setExpiresAt(null)
    setExpired(false)
    signIn.reset(); startCode.reset(); verifyCode.reset()
  }

  const submitPhone = () => {
    const e164 = phoneFieldValue(phone, dialCode)
    if (e164 === null) {
      setPhoneError(c.phoneInvalid)
      return
    }
    setPhoneError(null)
    setStep('code')
    // The NORMALISED number travels, so `05…`, `9665…` and `+9665…` are one destination, not three.
    startCode.mutate(e164)
  }

  const submitCode = useCallback((typed?: string) => {
    const value = (typed ?? code).trim()
    if (verificationId && value.length === 6 && !verifyCode.isPending && !expired) verifyCode.mutate(value)
  }, [verificationId, code, verifyCode, expired])

  // LOGIN-SINGLE-DOOR-001 — only reachable when the server enabled it; the destination comes back from the server.
  if (emailCodeOpen && oauthProviders.data?.emailCode === true) {
    return (
      <AuthShell portal="default">
        <EmailCodeSignIn
          redirect={safeRedirect(params.get('redirect'), '') || null}
          onBack={() => setEmailCodeOpen(false)}
          onSignedIn={(destination) => navigate(destination, { replace: true })}
        />
      </AuthShell>
    )
  }

  const oauthReason = params.get('oauth')

  /*
   * LOGIN-004 — Google returned an address that matches an account here: prove it is yours, in this card, instead of a dead end.
   *
   * The secret in a mailed link is read once and taken out of the address bar at once; the rest (who Google is, which account it
   * matches) stays on the server and arrives through the card's own request.
   */
  if (oauthReason === 'link') {
    return (
      <AuthShell portal="default">
        <div data-testid="login-column" className="mx-auto w-full max-w-[440px]">
          <OAuthLinkCard
            locale={locale}
            confirmToken={linkToken}
            onSignedIn={({ user, destination }: LinkedSignIn) => {
              setUser(user)
              navigate(destination, { replace: true })
            }}
            onLeave={() => {
              const next = new URLSearchParams(params)
              next.delete('oauth')
              next.delete('confirm')
              setParams(next, { replace: true })
            }}
          />
        </div>
      </AuthShell>
    )
  }
  const oauthNote = oauthReason !== null ? (OAUTH_NOTES[locale][oauthReason] ?? OAUTH_NOTES[locale].failed) : null

  return (
    <AuthShell portal="default">
      {/* AUTH-LOGIN-UX-002 — the sign-in column is at most 440px wide, so every way in reads as one narrow, deliberate stack. */}
      <div data-testid="login-column" className="mx-auto w-full max-w-[440px]">
      {/* WORKSPACE-SWITCH-001 — sent here because the session ended, so say so; nothing they did was refused. */}
      {params.get('reason') === 'session' && (
        <p data-testid="login-session-ended" role="status" className="mb-4 rounded-xl border border-border bg-surface px-4 py-3 text-sm text-text-secondary">
          {locale === 'ar' ? 'انتهت جلستك. سجّل الدخول مرة أخرى وستعود إلى حيث كنت.' : 'Your session has ended. Sign in again and you will return to where you were.'}
        </p>
      )}
      {/* What Google's answer was. Fixed sentences only: nothing from the address is echoed onto the page. */}
      {oauthNote !== null && (
        <p data-testid="login-oauth-note" role="alert" className="mb-4 rounded-xl border border-border bg-surface px-4 py-3 text-sm text-text-secondary">{oauthNote}</p>
      )}
      {/*
        An applicant has no account until the workspace exists, so this form cannot admit them. This browser kept the
        id of their own application; the screen that can actually help them is one link away.
      */}
      {registrationId !== null && (
        <Link
          data-testid="login-registration-status"
          to={`/signup/status?request=${encodeURIComponent(registrationId)}`}
          className="mb-4 block rounded-xl border border-border bg-surface px-4 py-3 text-sm font-semibold text-brand-600 hover:underline"
        >
          {locale === 'ar' ? 'لديك طلب تسجيل قيد المتابعة — اعرض حالته' : 'You have a registration in progress — view its status'}
        </Link>
      )}
      <h2 className="font-heading text-[26px] font-extrabold leading-tight tracking-tight text-text-primary sm:text-[30px]">
        {step === 'code' ? c.codeTitle : c.title}
      </h2>
      <p className="mt-1.5 text-[14.5px] leading-relaxed text-text-secondary">
        {step === 'code' ? c.codeSentTo : c.subtitle}
      </p>

      {/* Who the code went to, stated plainly under the sentence that says one was sent. */}
      {step === 'code' && (
        <p data-testid="login-code-destination" dir="ltr" className="mt-1 truncate font-mono text-sm text-text-primary">
          {identifier}
        </p>
      )}

      <div className="mt-5 rounded-2xl border border-border bg-surface p-5 shadow-[var(--shadow-small)] sm:p-6">
        {/* The credentials, and beneath them Google where it can work. */}
        {step === 'credentials' && !phoneCompat && (
          <>
            <form
              data-testid="login-identify"
              className="space-y-4"
              onSubmit={(e) => { e.preventDefault(); signIn.mutate() }}
            >
              <IconField
                id="login-email"
                data-testid="login-email"
                ar={ar}
                label={c.email}
                icon={<Mail size={17} />}
                type="email"
                placeholder={c.emailPlaceholder}
                value={email}
                onChange={setEmail}
                autoComplete="username"
                required
                error={activeError?.errors?.email?.[0] ?? activeError?.errors?.identifier?.[0]}
              />

              <div data-testid="login-password">
                <IconField
                  id="login-password-field"
                  ar={ar}
                  label={c.password}
                  icon={<Lock size={17} />}
                  type={showPassword ? 'text' : 'password'}
                  placeholder={c.passwordPlaceholder}
                  value={password}
                  onChange={setPassword}
                  autoComplete="current-password"
                  required
                  error={activeError?.errors?.password?.[0]}
                  trailing={(
                    <button
                      type="button"
                      onClick={() => setShowPassword((s) => !s)}
                      aria-label={showPassword ? c.hidePassword : c.showPassword}
                      className="text-text-secondary transition-colors hover:text-text-primary"
                    >
                      {showPassword ? <EyeOff size={17} /> : <Eye size={17} />}
                    </button>
                  )}
                />
              </div>

              <div className="flex items-center justify-between gap-3">
                <label className="flex cursor-pointer items-center gap-2 text-sm text-text-secondary">
                  <input
                    type="checkbox"
                    checked={remember}
                    onChange={(e) => setRemember(e.target.checked)}
                    className="h-4 w-4 shrink-0 rounded border-border accent-brand-600"
                  />
                  {c.remember}
                </label>
                <Link to="/forgot-password" className="text-sm font-semibold text-brand-600 hover:underline">
                  {c.forgot}
                </Link>
              </div>

              <ErrorNote error={activeError} />

              <Button type="submit" loading={signIn.isPending} className="w-full" size="lg">
                {c.signIn} {ar ? <ArrowLeft size={17} /> : <ArrowRight size={17} />}
              </Button>
            </form>

            <div data-testid="login-methods">
              <div className="my-5 flex items-center gap-3">
                <span className="h-px flex-1 bg-border" />
                <span className="text-[13px] text-text-secondary">{c.or}</span>
                <span className="h-px flex-1 bg-border" />
              </div>
              {googleRow === 'absent' ? (
                <Button type="button" data-testid="login-home" variant="secondary" className="w-full" size="lg" onClick={() => navigate('/')}>
                  {c.home}
                </Button>
              ) : googleRow === 'reserved' ? (
                // The button's own box and nothing to read, so a Google that turns out unavailable never flashes a label.
                <Button type="button" data-testid="login-google-reserved" variant="secondary" className="w-full" size="lg" disabled aria-hidden tabIndex={-1}>
                  <span className="invisible">{c.google}</span>
                </Button>
                ) : (
                <Button
                  type="button"
                  data-testid="login-google"
                  variant="secondary"
                  className="w-full"
                  size="lg"
                  loading={googleSignIn.isPending}
                  onClick={() => googleSignIn.mutate()}
                >
                  <span aria-hidden="true" className="font-bold">G</span>
                  {c.google}
                </Button>
              )}
            </div>
          </>
        )}

        {/* DEV/E2E only — the mobile path, kept reachable for the suite that covers it. */}
        {step === 'credentials' && phoneCompat && (
          <form
            data-testid="login-phone"
            className="space-y-4"
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
            <Button type="submit" loading={startCode.isPending} className="w-full" size="lg">{c.continue}</Button>
          </form>
        )}

        {/* The code, in the same card the address was in. No new page, no new card. */}
        {step === 'code' && (
          <form
            data-testid="login-code"
            className="space-y-4"
            onSubmit={(e) => { e.preventDefault(); submitCode() }}
          >
            <OtpField
              label={c.code}
              value={code}
              onChange={setCode}
              onComplete={submitCode}
              autoFocus
              disabled={verifyCode.isPending}
            />

            {/*
              Honest about delivery.

              `awaiting_provider_credentials` means no mail provider is configured and NOTHING was
              sent. Saying «check your inbox» over that would be the product claiming a message it
              never made, and would leave somebody waiting for something that is not coming.
            */}
            {delivery !== null && !DELIVERED.has(delivery) && (
              <p data-testid="login-code-undelivered" role="status" className="rounded-xl bg-surface-secondary px-4 py-3 text-[13px] leading-relaxed text-text-secondary">
                {delivery === 'failed' ? c.deliveryFailed : c.notDelivered}
              </p>
            )}

            {expired && (
              <p data-testid="login-code-expired" role="alert" className="rounded-xl bg-[var(--warning-background)] px-4 py-3 text-[13px] leading-relaxed text-text-primary">
                {c.codeExpired}
              </p>
            )}

            <ErrorNote error={activeError} always />

            <Button
              type="submit"
              data-testid="login-confirm-code"
              loading={verifyCode.isPending}
              disabled={!verificationId || code.trim().length < 6 || expired}
              className="w-full"
              size="lg"
            >
              {c.confirmCode}
            </Button>

            <div className="flex items-center justify-between gap-3 text-[13px]">
              <button
                type="button"
                data-testid="login-resend"
                onClick={() => startCode.mutate(identifier)}
                disabled={startCode.isPending || cooldown > 0}
                className="font-semibold text-brand-600 hover:underline disabled:cursor-default disabled:text-text-secondary disabled:no-underline"
              >
                {/* Latin digits in both languages — the platform rule, and a countdown is a number. */}
                {cooldown > 0 ? c.resendIn.replace(':seconds', String(cooldown)) : c.resend}
              </button>
              <button
                type="button"
                data-testid="login-change-identifier"
                onClick={startOver}
                className="font-semibold text-text-secondary hover:text-text-primary hover:underline"
              >
                {c.changeNumber}
              </button>
            </div>
          </form>
        )}

        <p className="mt-5 text-center text-sm text-text-secondary">
          {c.noAccount} <Link to="/register" className="font-semibold text-brand-600 hover:underline">{c.register}</Link>
        </p>
      </div>

      {/*
        «تحتاج مساعدة في البدء؟», outside the card and only on the first step.

        Outside, because it is not a way in and must not sit among the controls that are. Not beside
        the code step, because somebody holding a code already on its way to them should not be
        offered a form to fill in instead.
      */}
      {step === 'credentials' && (
        <>
          <HelpRequestModal locale={locale} ar={ar} />
          {/*
            Reserved always, at its final height, so the page holds still whichever way the lookup answers (`login-stable.spec.ts`).
            Empty unless the server enabled the code: there is nothing here that appears and then goes.
          */}
          <p data-testid="login-email-code-row" className="mt-2 min-h-6 text-center text-[13px]">
            {oauthProviders.data?.emailCode === true && !phoneCompat && (
              <button type="button" data-testid="login-email-code" onClick={() => setEmailCodeOpen(true)} className="font-semibold text-text-secondary hover:text-text-primary hover:underline">
                {c.emailCode}
              </button>
            )}
          </p>
        </>
      )}
      </div>
    </AuthShell>
  )
}

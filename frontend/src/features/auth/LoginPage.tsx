import { useCallback, useEffect, useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { useMutation } from '@tanstack/react-query'
import { ArrowLeft, ArrowRight, Eye, EyeOff, Lock, Mail, ShieldCheck } from 'lucide-react'
import { emailCodeStart, emailCodeVerify, login, phoneSignInStart, phoneSignInVerify, signInMethod } from './api'
import { resolvePostAuthOutcome } from './postAuthDestination'
import { AuthShell } from './AuthShell'
import { OtpField } from './OtpField'
import { HelpRequestModal } from './HelpRequestModal'
import { Button } from '@/components/ui/Button'
import { PhoneField, phoneFieldValue, DEFAULT_DIAL_CODE } from '@/components/ui/PhoneField'
import { toApiError } from '@/lib/api/client'
import { useUi } from '@/stores/ui'
import { useAuth } from '@/stores/auth'
import { portalLoginStart, portalLoginVerify } from '@/features/requests/clientPortalApi'

/**
 * LOGIN-CARD-001 — one card, two ways in, and the server decides where you land.
 *
 * ## The shape, and how it got here
 *
 * The credentials sit in a single card: address, password, «تذكرني», «نسيت كلمة المرور؟», and one
 * primary button. Below a hairline, «أو الدخول بدون كلمة مرور» offers the second route — a code sent
 * to the address that was just typed — as an outlined button that reads as an alternative rather
 * than as a competitor to the green one.
 *
 * An earlier revision of this page (LOGIN-OTP-001) removed the password from the production UI
 * entirely and made the code the only door. The owner replaced that with the layout above. It is
 * also the more honest arrangement for this environment as it stands: no mail provider is configured
 * yet, so a code-only door would lock every existing account out until one is.
 *
 * ## Both routes end in a session the SERVER chose
 *
 * `POST /auth/method` decides which engine sends a code, before anything is sent. A client contact's
 * address opens a PORTAL session through the portal's own engine and lands in `/portal`; every other
 * address opens a platform session through `/auth/email-code/*` and lands wherever real memberships
 * say. The visitor sees one button either way — the difference is not theirs to know or to get right.
 *
 * **The URL grants nothing.** `portal: null` travels with every sign-in, so there is no preference
 * for the server to honour and no way for a link to request access it does not hold.
 *
 * ## The visual baseline
 *
 * `AuthShell`, the marketing panel, the header, the two-column split, the type and the palette are
 * approved and untouched. Everything below is inside the form column.
 */

const COPY = {
  ar: {
    title: 'مرحباً بعودتك',
    subtitle: 'سجّل الدخول إلى حسابك في CampaignsHub',
    email: 'البريد الإلكتروني',
    emailPlaceholder: 'name@company.com',
    password: 'كلمة المرور',
    passwordPlaceholder: 'أدخل كلمة المرور',
    showPassword: 'إظهار كلمة المرور',
    hidePassword: 'إخفاء كلمة المرور',
    remember: 'تذكرني',
    forgot: 'نسيت كلمة المرور؟',
    signIn: 'تسجيل الدخول',
    orPasswordless: 'أو الدخول بدون كلمة مرور',
    sendCode: 'إرسال رمز إلى البريد الإلكتروني',
    secureTitle: 'دخول آمن ومشفّر لحماية بياناتك',
    secureBody: 'نستخدم أفضل ممارسات الأمان لحماية حسابك',
    noAccount: 'ليس لديك حساب؟',
    register: 'إنشاء حساب',
    emailRequired: 'أدخل بريدك الإلكتروني أولاً لإرسال الرمز.',
    codeTitle: 'تحقق من بريدك الإلكتروني',
    codeSentTo: 'أرسلنا رمز التحقق إلى:',
    code: 'رمز التحقق',
    resend: 'إعادة إرسال الرمز',
    resendIn: 'إعادة الإرسال بعد :seconds ثانية',
    changeEmail: 'تغيير البريد الإلكتروني',
    notDelivered: 'لم يُرسل الرمز: خدمة البريد غير مفعّلة بعد على هذه البيئة.',
    deliveryFailed: 'تعذّر إرسال الرمز إلى بريدك. جرّب إعادة الإرسال بعد قليل.',
    // DEV/E2E only — never rendered in a production build.
    phone: 'رقم الجوال',
    phoneHint: 'سنرسل رمز تحقق إلى هذا الرقم.',
    phoneInvalid: 'أدخل رقم جوال صحيحًا.',
    continue: 'متابعة',
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
    orPasswordless: 'Or sign in without a password',
    sendCode: 'Email me a sign-in code',
    secureTitle: 'Secure, encrypted sign-in',
    secureBody: 'We follow current security practice to protect your account',
    noAccount: "Don't have an account?",
    register: 'Create an account',
    emailRequired: 'Enter your email address first so we know where to send the code.',
    codeTitle: 'Check your email',
    codeSentTo: 'We sent your verification code to:',
    code: 'Verification code',
    resend: 'Send the code again',
    resendIn: 'You can ask again in :seconds seconds',
    changeEmail: 'Use a different email address',
    notDelivered: 'The code was not sent: email delivery is not configured on this environment yet.',
    deliveryFailed: 'The code could not be delivered to your inbox. Try sending it again in a moment.',
    phone: 'Mobile number',
    phoneHint: 'We will send a verification code to this number.',
    phoneInvalid: 'Enter a valid mobile number.',
    continue: 'Continue',
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

/** Which engine minted the code being held — the three end in three different kinds of session. */
type Engine = 'platform' | 'portal' | 'sms'

type Step = 'credentials' | 'code'

export function LoginPage() {
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const setUser = useAuth((s) => s.setUser)
  const status = useAuth((s) => s.status)
  const { locale } = useUi()
  const c = COPY[locale]
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

  const [step, setStep] = useState<Step>('credentials')
  const [engine, setEngine] = useState<Engine>('platform')

  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [showPassword, setShowPassword] = useState(false)
  const [remember, setRemember] = useState(false)
  const [emailMissing, setEmailMissing] = useState(false)

  const [phone, setPhone] = useState('')
  const [dialCode, setDialCode] = useState(DEFAULT_DIAL_CODE)
  const [phoneError, setPhoneError] = useState<string | null>(null)

  const [verificationId, setVerificationId] = useState<string | null>(null)
  const [code, setCode] = useState('')
  const [delivery, setDelivery] = useState<string | null>(null)
  const [cooldown, setCooldown] = useState(0)

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

  const identifier = engine === 'sms' ? (phoneFieldValue(phone, dialCode) ?? phone) : email.trim()

  /**
   * Send a code, by whichever engine this identifier belongs to.
   *
   * Three of them, because they end in three different sessions. `emailCodeStart` opens a PLATFORM
   * session for a user; `portalLoginStart` opens a PORTAL session for a client contact;
   * `phoneSignInStart` is the DEV-only mobile path. Using the portal's for a platform user would
   * sign them into `/portal`, where they hold nothing, and the page would look like it had worked.
   */
  const startCode = useMutation({
    mutationFn: ({ via, destination }: { via: Engine; destination: string }) =>
      via === 'platform' ? emailCodeStart(destination)
        : via === 'sms' ? phoneSignInStart(destination)
          : portalLoginStart('email', destination),
    onSuccess: (r) => {
      setVerificationId(r.verification_id)
      setDelivery(r.delivery_status ?? null)
      setCooldown('resend_after' in r && typeof r.resend_after === 'number' ? r.resend_after : 60)
      // Dev-only: the backend returns `dev_code` ONLY outside production (hard-gated server-side).
      if (r.dev_code) setCode(r.dev_code)
    },
  })

  /**
   * «الدخول بدون كلمة مرور» — ask the server which engine owns this address, then send.
   *
   * The question is asked BEFORE anything is sent because the answer decides which endpoint sends
   * it, and an address belonging to a client contact must not be handed a platform code it could
   * verify into a workspace it holds nothing in. An unknown address answers `password`, which here
   * means «the platform engine» — so a stranger gets a code too, and learns nothing.
   */
  const requestCode = useMutation({
    mutationFn: () => signInMethod(email.trim()),
    onSuccess: (result) => {
      const via: Engine = result.method === 'code' && result.channel === 'email' ? 'portal' : 'platform'
      setEngine(via)
      setStep('code')
      startCode.mutate({ via, destination: email.trim() })
    },
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

  /**
   * Check the code, and go wherever that code's session belongs.
   *
   * A platform code signs in a USER, so the destination comes from memberships. A portal code signs
   * in a client CONTACT, whose session belongs to the request portal — a `redirect` inside that
   * space is honoured and anything outside it ignored, because an absolute or protocol-relative URL
   * there would make this an open redirect.
   */
  const verifyCode = useMutation({
    /*
     * The code travels as a VARIABLE, not out of the closure.
     *
     * The last digit both fills the field and submits, and the state holding it has not been
     * committed at the moment `onComplete` fires — reading `code` here would send five digits and
     * fail a perfectly good sign-in. What was typed is passed in.
     */
    mutationFn: async (typed: string) => {
      if (engine === 'portal') {
        await portalLoginVerify(verificationId!, typed)
        const wanted = params.get('redirect') ?? ''
        const safe = /^\/(portal|client)(\/|$)/.test(wanted) && !wanted.startsWith('//')

        return safe ? wanted : '/portal'
      }

      const user = engine === 'sms'
        ? await phoneSignInVerify(verificationId!, typed, remember)
        : await emailCodeVerify(verificationId!, typed, remember)

      setUser(user)
      const { destination } = await resolvePostAuthOutcome(params, null)

      return destination
    },
    onSuccess: (destination) => navigate(destination, { replace: true }),
  })

  const activeError = signIn.isError ? toApiError(signIn.error)
    : requestCode.isError ? toApiError(requestCode.error)
      : startCode.isError ? toApiError(startCode.error)
        : verifyCode.isError ? toApiError(verifyCode.error)
          : null

  /** Back to the card. Clears the secrets; keeps the address so it need not be retyped. */
  const startOver = () => {
    setStep('credentials')
    setCode('')
    setVerificationId(null)
    setDelivery(null)
    setCooldown(0)
    signIn.reset(); requestCode.reset(); startCode.reset(); verifyCode.reset()
  }

  /** A code has to go somewhere. Asked for with an empty field, this says so instead of failing. */
  const askForCode = () => {
    if (email.trim() === '') {
      setEmailMissing(true)
      return
    }
    setEmailMissing(false)
    requestCode.mutate()
  }

  const submitPhone = () => {
    const e164 = phoneFieldValue(phone, dialCode)
    if (e164 === null) {
      setPhoneError(c.phoneInvalid)
      return
    }
    setPhoneError(null)
    setEngine('sms')
    setStep('code')
    // The NORMALISED number travels, so `05…`, `9665…` and `+9665…` are one destination, not three.
    startCode.mutate({ via: 'sms', destination: e164 })
  }

  const submitCode = useCallback((typed?: string) => {
    const value = (typed ?? code).trim()
    if (verificationId && value.length === 6 && !verifyCode.isPending) verifyCode.mutate(value)
  }, [verificationId, code, verifyCode])

  return (
    <AuthShell portal="default">
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
        {/* The credentials, and beneath them the way in that needs none. */}
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
                onChange={(v) => { setEmail(v); setEmailMissing(false) }}
                autoComplete="username"
                required
                error={emailMissing ? c.emailRequired : activeError?.errors?.email?.[0] ?? activeError?.errors?.identifier?.[0]}
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
                    className="h-4 w-4 rounded border-border accent-brand-600"
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

            {/*
              The second route, separated rather than stacked.

              An outlined button under a labelled rule reads as «or», where a second filled button
              would read as «choose», and the overwhelming majority of people here already know their
              password and want the green one.
            */}
            <div className="my-5 flex items-center gap-3">
              <span className="h-px flex-1 bg-border" />
              <span className="text-[13px] text-text-secondary">{c.orPasswordless}</span>
              <span className="h-px flex-1 bg-border" />
            </div>

            <Button
              type="button"
              data-testid="login-request-code"
              variant="secondary"
              className="w-full"
              size="lg"
              loading={requestCode.isPending || startCode.isPending}
              onClick={askForCode}
            >
              <Mail size={17} /> {c.sendCode}
            </Button>

            {/*
              A statement of practice, not a claim about this request.

              It says what the product does — transport encryption, hashed credentials, session
              rotation — and deliberately does not say anything a page cannot know, such as that this
              particular connection is safe.
            */}
            <div className="mt-5 flex items-start gap-3 rounded-xl bg-[var(--positive-background)] px-4 py-3">
              <ShieldCheck size={18} className="mt-0.5 shrink-0 text-brand-600" />
              <div>
                <p className="text-[13.5px] font-bold text-brand-700">{c.secureTitle}</p>
                <p className="mt-0.5 text-[12.5px] leading-relaxed text-text-secondary">{c.secureBody}</p>
              </div>
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

            <ErrorNote error={activeError} always />

            <Button
              type="submit"
              loading={verifyCode.isPending}
              disabled={!verificationId || code.trim().length < 6}
              className="w-full"
              size="lg"
            >
              {c.signIn}
            </Button>

            <div className="flex items-center justify-between gap-3 text-[13px]">
              <button
                type="button"
                data-testid="login-resend"
                onClick={() => startCode.mutate({ via: engine, destination: identifier })}
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
                {c.changeEmail}
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
      {step === 'credentials' && <HelpRequestModal locale={locale} ar={ar} />}
    </AuthShell>
  )
}

/**
 * A labelled input with an icon inside it, laid out the way the page reads.
 *
 * ## A flex row, not an absolutely-positioned icon over a padded input
 *
 * The obvious build — pin the icon with `position: absolute` and reserve room for it with padding —
 * is what put the lock underneath «أدخل كلمة المرور». It only holds while the padding and the
 * icon's offset agree about the icon's width, and they stop agreeing the moment either the icon
 * size, the font or the direction changes. A row cannot overlap: the icon occupies its own track and
 * the field takes what is left.
 *
 * ## Everything sits on the same side as the language
 *
 * The row inherits the page's direction, so in Arabic the icon lands at the right beside its
 * right-aligned label and the reveal control at the left; in English every one of them mirrors.
 * Nothing is pinned to a physical side.
 *
 * ## …but the VALUE still reads left to right
 *
 * `dir="ltr"` on the input, with the alignment set explicitly. An address and a password are Latin
 * strings, and rendering `name@company.com` in a right-to-left context reorders its punctuation —
 * the «.com» ends up in the wrong place and the field looks corrupted. Fixing the direction keeps
 * the string intact; setting the alignment separately keeps it against the same edge as the label.
 * `text-align: start` cannot do this: it resolves against the input's own direction, which is
 * exactly the thing being overridden.
 */
function IconField({
  id, label, icon, trailing, error, ar, onChange, ...props
}: {
  id: string
  label: string
  icon: React.ReactNode
  trailing?: React.ReactNode
  error?: string
  ar: boolean
  onChange: (value: string) => void
} & Omit<React.InputHTMLAttributes<HTMLInputElement>, 'onChange' | 'id'>) {
  return (
    <div>
      <label htmlFor={id} className="mb-1.5 block text-sm font-semibold text-text-secondary">{label}</label>
      <div
        className={`flex min-h-[52px] items-center gap-3 rounded-xl border bg-surface px-4 transition-colors focus-within:ring-[3px] focus-within:ring-brand-500/15 ${
          error ? 'border-danger focus-within:border-danger' : 'border-border focus-within:border-brand-500'
        }`}
      >
        <span className="shrink-0 text-text-secondary">{icon}</span>
        <input
          id={id}
          dir="ltr"
          onChange={(e) => onChange(e.target.value)}
          style={{ textAlign: ar ? 'right' : 'left' }}
          /*
           * `self-stretch` and 16px, both load-bearing.
           *
           * The input is a flex CHILD now, so without `self-stretch` its own box is only as tall as
           * its line — the row looked right and the touch target had quietly shrunk to about 46px,
           * under the 50px this product holds itself to. And 16px is the threshold below which iOS
           * zooms the whole page on focus, which on a phone throws the layout somebody was reading.
           */
          className="min-w-0 flex-1 self-stretch border-0 bg-transparent text-[16px] text-text-primary outline-none placeholder:text-text-secondary/70"
          aria-invalid={error ? true : undefined}
          {...props}
        />
        {trailing && <span className="shrink-0">{trailing}</span>}
      </div>
      {error && <p className="mt-1.5 text-[13px] text-danger">{error}</p>}
    </div>
  )
}

/**
 * The page's one error surface, so a failure never renders twice or in two different shapes.
 *
 * On the code step it also has to carry what a FIELD would normally say. A refused resend comes back
 * as a validation error on `destination` — «الرجاء الانتظار 43 ثانية قبل طلب رمز جديد» — and there
 * is no destination field on that step to render it. Falling back to the envelope's own `message`
 * there would print Laravel's generic «the given data was invalid», which tells somebody waiting out
 * a cooldown nothing at all. The specific sentence wins whenever there is one.
 */
function ErrorNote({ error, always = false }: { error: ReturnType<typeof toApiError> | null; always?: boolean }) {
  // Field-level messages are rendered BY the field. Repeating them here would say the same thing
  // twice — except on the code step, which has no field-level slot of its own.
  if (!error || (!always && error.errors)) return null

  const specific = error.errors ? Object.values(error.errors).flat().find(Boolean) : undefined

  return (
    <p data-testid="login-error" role="alert" className="rounded-xl bg-[var(--negative-background)] px-4 py-3 text-sm text-danger">
      {specific ?? error.message}
    </p>
  )
}

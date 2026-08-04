import { useEffect, useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { useMutation } from '@tanstack/react-query'
import { ArrowLeft, KeyRound, Megaphone, Moon, Sun } from 'lucide-react'
import { login, signInMethod } from './api'
import { resolvePostAuthOutcome } from './postAuthDestination'
import { Button } from '@/components/ui/Button'
import { EmailInput, PasswordInput } from '@/components/ui/form'
import { toApiError } from '@/lib/api/client'
import { useT } from '@/lib/i18n'
import { SocialSignIn } from './SocialSignIn'
import { AuthPanel, AuthPanelMobile } from './AuthPanel'
import { portalLoginStart, portalLoginVerify } from '@/features/requests/clientPortalApi'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'

/**
 * LOGIN-UNIFIED-001 — one sign-in page, and the server decides everything else.
 *
 * ## What was removed, and why it had to be
 *
 * This page used to open with a row of portal tabs: «إدارة الحملات», «وكالة», «متابعة الطلبات».
 * It asked the visitor a question only the system can answer. A client who picked «إدارة الحملات»
 * was shown a password field their account has never had; an operator who picked «متابعة الطلبات»
 * was sent to a form that would mail them a code instead of signing them in. Neither could have got
 * it right by reading more carefully — the correct answer depends on the account, which is precisely
 * the thing they have not identified yet at the moment the question is asked.
 *
 * There were also five other doors — `/admin/login`, `/app/login`, `/agency/login`,
 * `/influencers/login`, `/portal/login` — each a separate implementation of the same event. Those
 * addresses still work; they redirect here (see `LegacyLoginRedirect`).
 *
 * ## The shape now: identify first, then the right step
 *
 * 1. The visitor types an email or a phone. Nothing else is asked.
 * 2. `POST /auth/method` says whether that identifier signs in by **password** or by **one-time
 *    code**. The rule lives on the server (`SignInMethodResolver`), where the accounts are, and it
 *    is deliberately uninformative about identifiers it does not recognise.
 * 3. The matching step renders. On success the destination comes from `resolvePostAuthOutcome`,
 *    i.e. from real memberships — `/admin`, `/app`, `/agency` or `/portal`.
 *
 * **The URL grants nothing.** No portal is claimed by the browser at any point: `login()` is called
 * with `portal: null`, so there is no preference for the server to check and no way for a link to
 * ask for access. That also removes the wrong-portal refusal from this page entirely — there is
 * nothing left to refuse, because nothing is being requested.
 */

const COPY = {
  ar: {
    app: 'CampaignsHub',
    formTitle: 'مرحباً بعودتك',
    formValue: 'أدخل بريدك الإلكتروني أو رقم جوالك للمتابعة.',
    identifier: 'البريد الإلكتروني أو رقم الجوال',
    continue: 'متابعة',
    back: 'استخدام حساب آخر',
    noAccount: 'ليس لديك حساب؟',
    register: 'تسجيل حساب',
    codeTitle: 'أدخل رمز التحقق',
    codeSentEmail: 'أرسلنا رمزًا إلى بريدك الإلكتروني.',
    codeSentSms: 'أرسلنا رمزًا إلى رقم جوالك.',
    code: 'رمز التحقق',
    verify: 'تأكيد الرمز',
    resend: 'إعادة الإرسال',
  },
  en: {
    app: 'CampaignsHub',
    formTitle: 'Welcome back',
    formValue: 'Enter your email address or mobile number to continue.',
    identifier: 'Email address or mobile number',
    continue: 'Continue',
    back: 'Use a different account',
    noAccount: "Don't have an account?",
    register: 'Create an account',
    codeTitle: 'Enter your verification code',
    codeSentEmail: 'We sent a code to your email address.',
    codeSentSms: 'We sent a code to your mobile number.',
    code: 'Verification code',
    verify: 'Verify code',
    resend: 'Resend',
  },
} as const

export function LoginPage() {
  const t = useT()
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const setUser = useAuth((s) => s.setUser)
  const status = useAuth((s) => s.status)
  const { theme, locale, toggleTheme, toggleLocale } = useUi()
  const c = COPY[locale]
  const ar = locale === 'ar'

  /**
   * `identify` → the visitor is saying who they are.
   * `password` / `code` → the SERVER has said which step applies to them.
   */
  const [step, setStep] = useState<'identify' | 'password' | 'code'>('identify')
  const [identifier, setIdentifier] = useState('')
  const [password, setPassword] = useState('')
  const [remember, setRemember] = useState(true)
  const [channel, setChannel] = useState<'email' | 'sms'>('email')
  const [verificationId, setVerificationId] = useState<string | null>(null)
  const [code, setCode] = useState('')

  /*
   * Already signed in? Then this page is not for you.
   *
   * Reachable more often than it looks: Back after signing in, a bookmarked `/login`, a second tab,
   * or the brief guest state the session probe passes through on reload. The destination comes from
   * the server, exactly as it does after signing in — the browser never gets a second, different
   * rule for the same question.
   *
   * `null` is passed as the requested portal because this page never requests one. That is also why
   * there is no refusal branch here any more: nothing is being asked for, so nothing can be refused.
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

  /** Step 2b — one-time code, the same engine the client portal has always used. */
  const startCode = useMutation({
    mutationFn: (via: 'email' | 'sms') => portalLoginStart(via, identifier.trim()),
    onSuccess: (r) => {
      setVerificationId(r.verification_id)
      // Dev-only: the backend returns `dev_code` ONLY outside production (hard-gated server-side).
      if (r.dev_code) setCode(r.dev_code)
    },
  })

  /** Step 1 — ask the server which form this identifier uses. */
  const identify = useMutation({
    mutationFn: () => signInMethod(identifier.trim()),
    onSuccess: (result) => {
      const via = result.channel === 'sms' ? 'sms' : 'email'
      setChannel(via)
      if (result.method === 'code') {
        setStep('code')
        startCode.mutate(via)
        return
      }
      setStep('password')
    },
  })

  /**
   * Step 2a — password.
   *
   * `portal: null`, always. The browser states no preference, so the server picks the destination
   * from memberships alone and no link can claim access it does not have.
   */
  const signIn = useMutation({
    mutationFn: () => login({ email: identifier.trim(), password, remember, portal: null }),
    onSuccess: async (user) => {
      setUser(user)
      const { destination } = await resolvePostAuthOutcome(params, null)
      navigate(destination, { replace: true })
    },
  })

  const verifyCode = useMutation({
    mutationFn: () => portalLoginVerify(verificationId!, code),
    /*
     * The code session belongs to the request portal, so `/portal` is where it lands — but a
     * `redirect` INSIDE that space is honoured, which is how somebody who followed a link to a
     * specific request gets taken there rather than to the portal home. Anything outside it is
     * ignored: an absolute or protocol-relative URL here would make this an open redirect.
     */
    onSuccess: () => {
      const wanted = params.get('redirect') ?? ''
      const safe = /^\/(portal|client)(\/|$)/.test(wanted) && !wanted.startsWith('//')
      navigate(safe ? wanted : '/portal', { replace: true })
    },
  })

  const activeError = identify.isError ? toApiError(identify.error)
    : signIn.isError ? toApiError(signIn.error)
      : startCode.isError ? toApiError(startCode.error)
        : verifyCode.isError ? toApiError(verifyCode.error)
          : null

  /** Back to step 1. Clears the secrets; keeps the identifier so it need not be retyped. */
  const startOver = () => {
    setStep('identify')
    setPassword('')
    setCode('')
    setVerificationId(null)
    identify.reset(); signIn.reset(); startCode.reset(); verifyCode.reset()
  }

  const Logo = ({ size = 20, className = '' }: { size?: number; className?: string }) => (
    <div className={`flex items-center justify-center rounded-xl bg-gradient-to-br from-brand-500 to-brand-700 text-white shadow-lg ${className}`}>
      <Megaphone size={size} />
    </div>
  )

  return (
    <div className="grid min-h-screen grid-cols-1 bg-background lg:grid-cols-[1.05fr_1fr]">
      {/*
        One panel, one message. The sign-in page speaks about the product, not about a portal —
        tailoring it would mean knowing which portal the visitor belongs to, which is the question
        this page exists to answer. Registration is where the copy adapts, because there the account
        type genuinely is being chosen (`AuthShell`).
      */}
      <AuthPanel locale={locale} portal="default" />

      <main className="flex flex-col px-5 py-4 sm:px-8">
        <div className="flex items-center justify-between">
          <Link to="/" className="flex items-center gap-2 lg:invisible">
            <Logo size={16} className="h-8 w-8 rounded-lg" />
            <span className="font-extrabold text-text-primary">{c.app}</span>
          </Link>
          <div className="flex items-center gap-1.5">
            <button onClick={toggleLocale} aria-label="Toggle language" className="flex h-9 min-w-9 items-center justify-center rounded-lg px-2 text-sm font-semibold text-text-secondary hover:bg-surface-hover">{ar ? 'EN' : 'ع'}</button>
            <button onClick={toggleTheme} aria-label="Toggle theme" className="flex h-9 w-9 items-center justify-center rounded-lg text-text-secondary hover:bg-surface-hover">{theme === 'light' ? <Moon size={18} /> : <Sun size={18} />}</button>
          </div>
        </div>

        {/*
          Centred by default. Only from `lg` — where the panel actually appears beside it — is the
          form pulled toward the divider, by fixing margin-inline-start and leaving margin-inline-end
          auto (correct in both LTR and RTL).
        */}
        <div data-testid="login-form" className="mx-auto flex w-full max-w-[468px] flex-1 flex-col justify-center py-6">
          {/*
            A card, not a bare column.

            The three steps are very different heights — an email field, an email field plus a
            password and a remember row, six digits — and unframed they made the page jump on every
            transition, which reads as a reload rather than as progress. A fixed frame with one
            padding and a floor under the step area keeps the box the same object throughout, and
            `min-h` is on the step region alone so the heading never drifts away from it.
          */}
          <div className="rounded-3xl border border-border bg-surface px-5 py-7 shadow-[var(--shadow-medium)] sm:px-8 sm:py-10">
            <h2 className="font-heading text-[26px] font-extrabold leading-tight tracking-tight text-text-primary sm:text-[30px]">
              {step === 'code' ? c.codeTitle : c.formTitle}
            </h2>
            <p className="mt-1.5 text-[14px] leading-relaxed text-text-secondary">
              {step === 'code' ? (channel === 'sms' ? c.codeSentSms : c.codeSentEmail) : c.formValue}
            </p>

            {/*
              One floor under all three steps, so the card does not resize as the visitor moves —
              from `sm` only. On a phone the card is already the whole screen and a floor just opens
              a gap between the button and what follows it.
            */}
            <div className="sm:min-h-[190px]">
            {/* Step 1 — who are you. No portal, no password yet. */}
            {step === 'identify' && (
              <form
                data-testid="login-identify"
                className="mt-5 space-y-4"
                onSubmit={(e) => { e.preventDefault(); if (identifier.trim()) identify.mutate() }}
              >
                <EmailInput
                  type="text"
                  inputMode="email"
                  autoComplete="username"
                  label={c.identifier}
                  value={identifier}
                  onChange={(e) => setIdentifier(e.target.value)}
                  required
                  error={activeError?.errors?.identifier?.[0]}
                />
                {activeError && !activeError.errors && (
                  <p data-testid="login-error" role="alert" className="rounded-xl bg-[var(--negative-background)] px-4 py-3 text-sm text-danger">{activeError.message}</p>
                )}
                <Button type="submit" loading={identify.isPending} className="w-full" size="lg">{c.continue}</Button>
              </form>
            )}

            {/* Step 2a — password, for the accounts the server said have one. */}
            {step === 'password' && (
              <form
                data-testid="login-password"
                className="mt-5 space-y-4"
                onSubmit={(e) => { e.preventDefault(); signIn.mutate() }}
              >
                <IdentifierRow value={identifier} label={c.back} onChange={startOver} />
                <PasswordInput
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

                {activeError && !activeError.errors && (
                  <p data-testid="login-error" role="alert" className="rounded-xl bg-[var(--negative-background)] px-4 py-3 text-sm text-danger">{activeError.message}</p>
                )}

                <Button type="submit" loading={signIn.isPending} className="w-full" size="lg">{t('sign_in')}</Button>
              </form>
            )}

            {/* Step 2b — one-time code, for the accounts that have never had a password. */}
            {step === 'code' && (
              <form
                data-testid="login-code"
                className="mt-5 space-y-4"
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

                {activeError && (
                  <p data-testid="login-error" role="alert" className="rounded-xl bg-[var(--negative-background)] px-4 py-3 text-sm text-danger">{activeError.message}</p>
                )}

                <Button type="submit" loading={verifyCode.isPending} disabled={!verificationId} className="w-full" size="lg">
                  <KeyRound size={16} /> {c.verify}
                </Button>
                <button
                  type="button"
                  onClick={() => startCode.mutate(channel)}
                  disabled={startCode.isPending}
                  className="w-full text-center text-sm font-semibold text-brand-600 hover:underline disabled:opacity-60"
                >
                  {c.resend}
                </button>
              </form>
            )}
            </div>
            {/*
              Google and Apple, on the identity step only.
              Once the server has said this identifier signs in by code, a password-provider button
              beside it would put back the choice this page removed.

              No portal travels with the request: like the password form, it states no preference.
            */}
            {step === 'identify' && <SocialSignIn portalParam={null} redirect={params.get('redirect')} ar={ar} />}
          </div>

          <p className="mt-5 text-center text-sm text-text-secondary">
            {c.noAccount} <Link to="/register" className="font-semibold text-brand-600 hover:underline">{c.register}</Link>
          </p>

          {/* On phones the value proposition lives here — under the form, collapsed by default. */}
          <AuthPanelMobile locale={locale} portal="default" />
        </div>
      </main>
    </div>
  )
}

/**
 * The identifier already given, shown back with a way to change it.
 *
 * A two-step form that hides what you typed in step one is a small trap of its own: somebody who
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

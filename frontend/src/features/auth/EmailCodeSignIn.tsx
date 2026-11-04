import { useCallback, useEffect, useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { Mail } from 'lucide-react'
import { ErrorNote, IconField } from './loginFields'
import { OtpField } from './OtpField'
import { Button } from '@/components/ui/Button'
import { toApiError } from '@/lib/api/client'
import { useUi } from '@/stores/ui'
import { portalLoginStart, portalLoginVerify } from '@/features/requests/clientPortalApi'

/**
 * LOGIN-SINGLE-DOOR-001 — the email code, as a step of `/login`, drawn only when the server says it is on.
 *
 * It was #943's separate `/portal/login` page; the engine and its protections are the portal's own and did not change: real mail,
 * single use, a disabled account refused, an audit trail. The server answers every address the same way and mails only one it knows.
 * Where the session lands comes back from the server, inside the client portal, because that is all a code opens.
 */

const COPY = {
  ar: {
    title: 'الدخول برمز عبر البريد',
    subtitle: 'أدخل بريدك الإلكتروني المسجّل، وسنرسل إليك رمز دخول.',
    email: 'البريد الإلكتروني',
    send: 'إرسال رمز الدخول',
    codeTitle: 'تحقق من بريدك الإلكتروني',
    codeSentTo: 'إذا كان هذا البريد مسجّلًا لدينا، فسيصل رمز التحقق إلى:',
    code: 'رمز التحقق',
    confirm: 'تأكيد الرمز والمتابعة',
    resend: 'إعادة إرسال الرمز',
    resendIn: 'إعادة الإرسال بعد :seconds ثانية',
    change: 'استخدام بريد إلكتروني آخر',
    expired: 'انتهت صلاحية رمز التحقق. أعد إرسال الرمز للحصول على رمز جديد.',
    notDelivered: 'لم يُرسل الرمز: خدمة البريد غير مفعّلة بعد على هذه البيئة.',
    deliveryFailed: 'تعذّر إرسال الرمز إلى بريدك. جرّب إعادة الإرسال بعد قليل.',
    back: 'تسجيل الدخول بكلمة المرور',
  },
  en: {
    title: 'Sign in with an email code',
    subtitle: 'Enter the email address we have on file and we will send you a sign-in code.',
    email: 'Email address',
    send: 'Send sign-in code',
    codeTitle: 'Check your email',
    codeSentTo: 'If we have this address on file, the verification code is on its way to:',
    code: 'Verification code',
    confirm: 'Confirm code and continue',
    resend: 'Send the code again',
    resendIn: 'You can ask again in :seconds seconds',
    change: 'Use a different email address',
    expired: 'This verification code has expired. Send the code again to get a new one.',
    notDelivered: 'The code was not sent: email delivery is not configured on this environment yet.',
    deliveryFailed: 'The code could not be delivered to your inbox. Try sending it again in a moment.',
    back: 'Sign in with a password',
  },
} as const

/** An allow-list: a state this browser does not know is more likely a new way of not arriving than a new way of arriving. */
const DELIVERED = new Set(['sent', 'queued', 'delivered'])
const COOLDOWN = 60

export function EmailCodeSignIn({ redirect, onBack, onSignedIn }: {
  /** The page the sign-in was interrupted on, for the server to accept or refuse. */
  redirect: string | null
  onBack: () => void
  onSignedIn: (destination: string) => void
}) {
  const { locale } = useUi()
  const c = COPY[locale]
  const ar = locale === 'ar'

  const [step, setStep] = useState<'email' | 'code'>('email')
  const [email, setEmail] = useState('')
  const [code, setCode] = useState('')
  const [verificationId, setVerificationId] = useState<string | null>(null)
  const [delivery, setDelivery] = useState<string | null>(null)
  const [cooldown, setCooldown] = useState(0)
  const [expiresAt, setExpiresAt] = useState<number | null>(null)
  const [expired, setExpired] = useState(false)

  useEffect(() => {
    if (cooldown <= 0) return
    const id = window.setInterval(() => setCooldown((s) => (s <= 1 ? 0 : s - 1)), 1000)

    return () => window.clearInterval(id)
  }, [cooldown])

  useEffect(() => {
    if (expiresAt === null) return
    const left = expiresAt - Date.now()
    if (left <= 0) { setExpired(true); return }
    const id = window.setTimeout(() => setExpired(true), left)

    return () => window.clearTimeout(id)
  }, [expiresAt])

  const send = useMutation({
    mutationFn: () => portalLoginStart('email', email.trim()),
    onSuccess: (r) => {
      setStep('code')
      setCooldown(r.resend_after ?? COOLDOWN)
      setVerificationId(r.verification_id)
      setDelivery(r.delivery_status)
      setExpired(false)
      setExpiresAt(r.expires_in_minutes ? Date.now() + r.expires_in_minutes * 60_000 : null)
      // Dev-only: the backend returns `dev_code` ONLY outside production (hard-gated server-side).
      if (r.dev_code) setCode(r.dev_code)
    },
  })

  const verify = useMutation({
    // The value travels as a variable: the last digit both fills the field and submits, before that state is committed.
    mutationFn: (typed: string) => portalLoginVerify(verificationId!, typed, redirect),
    onSuccess: (r) => onSignedIn(r.destination),
  })

  const submitCode = useCallback((typed?: string) => {
    const value = (typed ?? code).trim()
    if (verificationId && value.length === 6 && !verify.isPending && !expired) verify.mutate(value)
  }, [code, verify, expired, verificationId])

  const startOver = () => {
    setStep('email')
    setCode('')
    setVerificationId(null)
    setDelivery(null)
    setCooldown(0)
    setExpiresAt(null)
    setExpired(false)
    send.reset(); verify.reset()
  }

  const activeError = send.isError ? toApiError(send.error) : verify.isError ? toApiError(verify.error) : null

  return (
    <div data-testid="email-code" className="mx-auto w-full max-w-[440px]">
      <h2 className="font-heading text-[26px] font-extrabold leading-tight tracking-tight text-text-primary sm:text-[30px]">
        {step === 'code' ? c.codeTitle : c.title}
      </h2>
      <p className="mt-1.5 text-sm leading-relaxed text-text-secondary">{step === 'code' ? c.codeSentTo : c.subtitle}</p>
      {step === 'code' && (
        <p data-testid="email-code-destination" dir="ltr" className="mt-1 truncate font-mono text-sm text-text-primary">{email.trim()}</p>
      )}

      <div className="mt-5 rounded-2xl border border-border bg-surface p-5 shadow-[var(--shadow-small)] sm:p-6">
        {step === 'email' ? (
          <form data-testid="email-code-form" className="space-y-4" onSubmit={(e) => { e.preventDefault(); send.mutate() }}>
            <IconField
              id="email-code-address"
              data-testid="email-code-address"
              ar={ar}
              label={c.email}
              icon={<Mail size={17} />}
              type="email"
              placeholder="name@company.com"
              value={email}
              onChange={setEmail}
              /*
               * Off, because this form has one field and no credential to remember. Firefox on Linux offers past values in a popup, and a
               * click outside that popup closes it and goes no further: the button looked pressed and nothing was sent.
               */
              autoComplete="off"
              required
              error={activeError?.errors?.destination?.[0]}
            />
            {/* Every refusal is stated: one whose field is not on this step must not read as a button that did nothing. */}
            {!activeError?.errors?.destination && <ErrorNote error={activeError} always />}
            <Button type="submit" data-testid="email-code-send" loading={send.isPending} className="w-full" size="lg">{c.send}</Button>
          </form>
        ) : (
          <form data-testid="email-code-step" className="space-y-4" onSubmit={(e) => { e.preventDefault(); submitCode() }}>
            <OtpField label={c.code} value={code} onChange={setCode} onComplete={submitCode} autoFocus disabled={verify.isPending} />

            {delivery !== null && !DELIVERED.has(delivery) && (
              <p data-testid="email-code-undelivered" role="status" className="rounded-xl bg-surface-secondary px-4 py-3 text-[13px] leading-relaxed text-text-secondary">
                {delivery === 'failed' ? c.deliveryFailed : c.notDelivered}
              </p>
            )}
            {expired && (
              <p data-testid="email-code-expired" role="alert" className="rounded-xl bg-[var(--warning-background)] px-4 py-3 text-[13px] leading-relaxed text-text-primary">{c.expired}</p>
            )}
            <ErrorNote error={activeError} always />

            <Button type="submit" data-testid="email-code-confirm" loading={verify.isPending} disabled={code.trim().length < 6 || expired} className="w-full" size="lg">{c.confirm}</Button>

            <div className="flex items-center justify-between gap-3 text-[13px]">
              <button
                type="button"
                data-testid="email-code-resend"
                onClick={() => send.mutate()}
                disabled={send.isPending || cooldown > 0}
                className="font-semibold text-brand-600 hover:underline disabled:cursor-default disabled:text-text-secondary disabled:no-underline"
              >
                {cooldown > 0 ? c.resendIn.replace(':seconds', String(cooldown)) : c.resend}
              </button>
              <button type="button" data-testid="email-code-change" onClick={startOver} className="font-semibold text-text-secondary hover:text-text-primary hover:underline">{c.change}</button>
            </div>
          </form>
        )}
      </div>

      <p className="mt-3 text-center text-[13px]">
        <button type="button" data-testid="email-code-back" onClick={onBack} className="font-semibold text-text-secondary hover:text-text-primary hover:underline">{c.back}</button>
      </p>
    </div>
  )
}

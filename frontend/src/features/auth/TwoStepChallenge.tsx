import { useEffect, useRef, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { ChevronLeft, ChevronRight, KeyRound, Mail, MessageSquare, ShieldCheck, Smartphone, type LucideIcon } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { toApiError } from '@/lib/api/client'
import { countedAr, countedEn } from '@/lib/counted'
import type { AuthUser } from '@/lib/api/types'
import { OtpField } from './OtpField'
import { cancelTwoStep, fetchTwoStep, sendTwoStepCode, verifyTwoStep, type TwoStepInfo, type TwoStepMethod } from './api'

/**
 * TWO-STEP-001 — the second step at sign-in.
 *
 * Reached from every door: the password form, Google (`/login?two_step=1`), a phone code and linking in place. The server holds
 * the session at this step and opens it only when the answer is right; this screen asks for it and nothing else. One sentence
 * for every way of being wrong, because that is what the server says.
 *
 * TWO-STEP-UX-002 (Owner 2026-11-23): the screen used to say «التحقق بخطوتين · الرمز» and nothing else, so nobody could tell
 * which code it wanted. It now reads like the large providers' second step, in as few words as they use: the method's icon and a
 * one-line heading, one short line of where the code comes from (the app, or the masked address or number and its lifetime), the
 * code, «تحقق», and two small links — resend and «طريقة أخرى», which lists every way this account can answer.
 */
type Choice = TwoStepMethod | 'recovery'
type Sent = 'email' | 'sms'

const MINUTES_AR = { one: 'دقيقة', two: 'دقيقتين', few: 'دقائق', many: 'دقيقة' }

const COPY = {
  ar: {
    title: 'التحقق بخطوتين',
    name: { totp: 'تطبيق المصادقة', email: 'البريد الإلكتروني', sms: 'رسالة نصية', recovery: 'رمز احتياطي' },
    heading: { totp: 'أدخل رمز تطبيق المصادقة', email: 'أدخل الرمز المرسل لبريدك', sms: 'أدخل الرمز المرسل لجوالك', recovery: 'أدخل رمزًا احتياطيًا' },
    sub: {
      totp: 'من Google أو Microsoft Authenticator',
      email: (to: string | null) => to ?? 'بريدك الإلكتروني',
      sms: (to: string | null) => to ?? 'جوالك',
      recovery: 'بالشكل xxxxx-xxxxx · يُستخدم مرة واحدة',
    },
    valid: (m: number) => `صالح ${countedAr(m, MINUTES_AR)}`,
    code: 'رمز التحقق',
    send: 'أرسل الرمز',
    resend: 'إعادة الإرسال',
    wait: (s: number) => `إعادة الإرسال ${s} ث`,
    notSent: 'تعذّر الإرسال. أعد المحاولة.',
    verify: 'تحقق',
    other: 'طريقة أخرى',
    chooseTitle: 'اختر طريقة التحقق',
    current: 'الحالية',
    back: 'رجوع',
    expired: 'انتهت مهلة التحقق. سجّل الدخول مرة أخرى.',
    none: 'لا توجد طريقة متاحة الآن. استخدم رمزًا احتياطيًا.',
  },
  en: {
    title: 'Two-step verification',
    name: { totp: 'Authenticator app', email: 'Email', sms: 'Text message', recovery: 'Recovery code' },
    heading: { totp: 'Enter your authenticator code', email: 'Enter the code sent to your email', sms: 'Enter the code sent to your phone', recovery: 'Enter a recovery code' },
    sub: {
      totp: 'From Google or Microsoft Authenticator',
      email: (to: string | null) => to ?? 'your email',
      sms: (to: string | null) => to ?? 'your phone',
      recovery: 'Format xxxxx-xxxxx · works once',
    },
    valid: (m: number) => `valid ${countedEn(m, 'minute', 'minutes')}`,
    code: 'Verification code',
    send: 'Send code',
    resend: 'Resend',
    wait: (s: number) => `Resend in ${s}s`,
    notSent: 'Could not send. Try again.',
    verify: 'Verify',
    other: 'Another way',
    chooseTitle: 'Choose a method',
    current: 'Current',
    back: 'Back',
    expired: 'The verification step expired. Sign in again.',
    none: 'No method is available right now. Use a recovery code.',
  },
} as const

const ICONS: Record<Choice, LucideIcon> = { totp: Smartphone, email: Mail, sms: MessageSquare, recovery: KeyRound }
const DELIVERED = new Set(['sent', 'queued', 'delivered'])
const isSent = (m: Choice | null): m is Sent => m === 'email' || m === 'sms'

export function TwoStepChallenge({
  locale,
  initial,
  onSignedIn,
  onLeave,
}: {
  locale: 'ar' | 'en'
  /** What the sign-in answer already said; absent after a redirect (Google) or a reload, then it is asked for. */
  initial: TwoStepInfo | null
  onSignedIn: (result: { user: AuthUser; destination: string }) => void
  onLeave: (expired: boolean) => void
}) {
  const c = COPY[locale]
  const probe = useQuery({ queryKey: ['auth', 'two-step'], queryFn: fetchTwoStep, enabled: initial === null, retry: false })
  const info = initial ?? probe.data ?? null

  const [choice, setChoice] = useState<Choice | null>(null)
  const [choosing, setChoosing] = useState(false)
  const [code, setCode] = useState('')
  const [cooldown, setCooldown] = useState(0)
  const [delivery, setDelivery] = useState<string | null>(null)
  const autoSent = useRef(false)

  const method: Choice | null = choice ?? info?.default ?? (info?.methods.length ? info.methods[0] : info ? 'recovery' : null)
  const hintOf = (m: Choice | null) => (m === 'email' ? info?.email_hint ?? null : m === 'sms' ? info?.phone_hint ?? null : null)
  // Email and SMS codes live ten minutes (ContactVerificationService, `requests.verification.code_ttl_minutes`).
  const ttl = 10

  useEffect(() => {
    if (cooldown <= 0) return
    const id = window.setInterval(() => setCooldown((s) => (s <= 1 ? 0 : s - 1)), 1000)
    return () => window.clearInterval(id)
  }, [cooldown])

  const send = useMutation({ meta: { feedback: 'none' },
    mutationFn: (m: Sent) => sendTwoStepCode(m),
    onSuccess: (r) => {
      setDelivery(r.delivery_status)
      setCooldown(r.resend_after)
      // Non-production only: the server hard-gates `dev_code`.
      if (r.dev_code) setCode(r.dev_code)
    },
  })

  const verify = useMutation({ meta: { feedback: 'none' },
    mutationFn: (typed: string) => verifyTwoStep(method ?? 'totp', typed),
    onSuccess: onSignedIn,
    onError: (e) => { if (toApiError(e).status === 410) onLeave(true) },
  })

  // A code by email or SMS is sent once on arrival when it is the default, so the person is not asked to press twice.
  useEffect(() => {
    if (autoSent.current || choice !== null || !info) return
    if (isSent(info.default)) {
      autoSent.current = true
      send.mutate(info.default)
    }
  }, [info, choice, send])

  useEffect(() => {
    if (probe.isError && toApiError(probe.error).status === 410) onLeave(true)
  }, [probe.isError, probe.error, onLeave])

  const pick = (m: Choice) => {
    setChoosing(false)
    if (m === method) return
    setChoice(m)
    setCode('')
    setDelivery(null)
    setCooldown(0)
    verify.reset()
    send.reset()
  }

  const submit = (typed?: string) => {
    const value = (typed ?? code).trim()
    if (verify.isPending || value === '') return
    if (method !== 'recovery' && value.length !== 6) return
    verify.mutate(value)
  }

  const error = verify.isError && toApiError(verify.error).status !== 410 ? toApiError(verify.error).message
    : send.isError ? toApiError(send.error).message : null

  const choices: Choice[] = [...(info?.methods ?? []), ...(info?.recovery_available ? (['recovery'] as const) : [])]
  const Icon = method ? ICONS[method] : ShieldCheck
  const Chevron = locale === 'ar' ? ChevronLeft : ChevronRight
  const subOf = (m: Choice) => (m === 'totp' || m === 'recovery' ? c.sub[m] : c.sub[m](hintOf(m)))
  const delivered = delivery !== null && DELIVERED.has(delivery)

  return (
    <div data-testid="two-step" className="rounded-2xl border border-border bg-surface p-6 shadow-[var(--shadow-small)]">
      <p className="mb-4 flex items-center justify-center gap-1.5 text-xs font-semibold text-text-muted">
        <ShieldCheck size={14} aria-hidden /> {c.title}
      </p>

      {info === null && probe.isPending && <div className="h-24 animate-pulse rounded-xl bg-surface-secondary" />}

      {info !== null && choosing && (
        <div data-testid="two-step-choose">
          <h1 className="mb-3 text-center text-lg font-bold text-text-primary">{c.chooseTitle}</h1>
          <ul className="divide-y divide-border overflow-hidden rounded-xl border border-border">
            {choices.map((m) => {
              const MIcon = ICONS[m]
              return (
                <li key={m}>
                  <button
                    type="button"
                    data-testid={`two-step-method-${m}`}
                    aria-current={method === m ? 'true' : undefined}
                    onClick={() => pick(m)}
                    className="flex w-full items-center gap-3 px-4 py-3 text-start transition-colors hover:bg-surface-secondary"
                  >
                    <MIcon size={18} className="shrink-0 text-text-secondary" aria-hidden />
                    <span className="min-w-0 flex-1">
                      <span className="block text-sm font-semibold text-text-primary">{c.name[m]}</span>
                      <span className="block truncate text-xs text-text-muted" dir="auto">{subOf(m)}</span>
                    </span>
                    {method === m ? <span className="text-xs font-semibold text-brand-600">{c.current}</span> : <Chevron size={16} className="shrink-0 text-text-muted" aria-hidden />}
                  </button>
                </li>
              )
            })}
          </ul>
          <Button type="button" variant="ghost" className="mt-3 w-full" data-testid="two-step-choose-back" onClick={() => setChoosing(false)}>{c.back}</Button>
        </div>
      )}

      {info !== null && !choosing && method !== null && (
        <>
          <div className="mb-5 flex flex-col items-center gap-2 text-center" data-testid={`two-step-panel-${method}`}>
            <span className="flex size-12 items-center justify-center rounded-full bg-brand-500/10 text-brand-600"><Icon size={22} aria-hidden /></span>
            <h1 className="text-lg font-bold text-text-primary">{c.heading[method]}</h1>
            <p className="text-sm text-text-secondary" data-testid="two-step-explain" dir="auto">
              {subOf(method)}
              {isSent(method) && delivered && <span data-testid="two-step-sent"> · {c.valid(ttl)}</span>}
            </p>
          </div>

          {info.methods.length === 0 && method === 'recovery' && <p className="mb-3 text-center text-sm text-text-secondary">{c.none}</p>}

          <form onSubmit={(e) => { e.preventDefault(); submit() }} className="space-y-4">
            {method === 'recovery' ? (
              <Input
                data-testid="two-step-recovery"
                dir="ltr"
                autoComplete="one-time-code"
                aria-label={c.name.recovery}
                placeholder="xxxxx-xxxxx"
                value={code}
                onChange={(e) => setCode(e.target.value)}
                className="text-center font-mono tnum"
              />
            ) : (
              <OtpField value={code} onChange={setCode} onComplete={submit} label={c.code} autoFocus testId="two-step-otp" disabled={verify.isPending} />
            )}

            {error && <p role="alert" data-testid="two-step-error" className="text-center text-sm text-danger">{error}</p>}
            {isSent(method) && delivery !== null && !delivered && <p className="text-center text-sm text-warning">{c.notSent}</p>}

            <Button type="submit" size="lg" className="w-full" data-testid="two-step-verify" loading={verify.isPending}>{c.verify}</Button>
          </form>

          <div className="mt-4 flex items-center justify-between gap-2 text-sm">
            {isSent(method) ? (
              <button
                type="button"
                data-testid="two-step-send"
                disabled={cooldown > 0 || send.isPending}
                onClick={() => send.mutate(method)}
                className="font-semibold text-brand-600 hover:underline disabled:cursor-default disabled:font-normal disabled:text-text-muted disabled:no-underline"
              >
                <span className="tnum">{cooldown > 0 ? c.wait(cooldown) : delivery === null ? c.send : c.resend}</span>
              </button>
            ) : <span />}
            {choices.length > 1 && (
              <button type="button" data-testid="two-step-other" onClick={() => setChoosing(true)} className="font-semibold text-brand-600 hover:underline">{c.other}</button>
            )}
          </div>

          <Button
            type="button"
            variant="ghost"
            className="mt-3 w-full"
            data-testid="two-step-back"
            onClick={() => { void cancelTwoStep().catch(() => undefined); onLeave(false) }}
          >
            {c.back}
          </Button>
        </>
      )}
    </div>
  )
}

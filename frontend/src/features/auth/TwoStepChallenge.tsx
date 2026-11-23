import { useEffect, useRef, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { CheckCircle2, ChevronLeft, ChevronRight, KeyRound, Mail, MessageCircle, MessageSquare, ShieldCheck, Smartphone, type LucideIcon } from 'lucide-react'
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
 * which code it wanted. It now reads like the large providers' second step: the method in use is named with its icon, a sentence
 * says exactly where the code comes from (which app, which address or number, how long it lasts), and «جرّب طريقة أخرى» opens a
 * list of every way this account can answer, each with where it goes. WhatsApp is offered beside SMS when the server can send it.
 */
type Choice = TwoStepMethod | 'recovery'
type Sent = 'email' | 'sms' | 'whatsapp'

const MINUTES_AR = { one: 'دقيقة', two: 'دقيقتين', few: 'دقائق', many: 'دقيقة' }

const COPY = {
  ar: {
    title: 'التحقق بخطوتين',
    lead: 'خطوة أمان إضافية لحماية حسابك. أكّد أنه أنت للمتابعة.',
    name: { totp: 'تطبيق المصادقة', email: 'البريد الإلكتروني', sms: 'رسالة نصية (SMS)', whatsapp: 'واتساب', recovery: 'رمز احتياطي' },
    heading: {
      totp: 'أدخل الرمز من تطبيق المصادقة',
      email: 'أدخل الرمز المرسل إلى بريدك',
      sms: 'أدخل الرمز المرسل برسالة نصية',
      whatsapp: 'أدخل الرمز المرسل على واتساب',
      recovery: 'أدخل أحد رموزك الاحتياطية',
    },
    body: {
      totp: 'افتح تطبيق المصادقة في جوالك (مثل Google Authenticator أو Microsoft Authenticator أو Authy)، واكتب الرمز المكوّن من 6 أرقام الظاهر تحت حساب كامبينز هب.',
      email: (to: string) => `نرسل رمزًا من 6 أرقام إلى ${to}. إذا ما وصلك خلال دقيقة، تحقق من مجلد الرسائل غير المرغوب فيها.`,
      sms: (to: string) => `نرسل رسالة نصية فيها رمز من 6 أرقام إلى الرقم ${to}.`,
      whatsapp: (to: string) => `نرسل رمزًا من 6 أرقام في رسالة واتساب إلى الرقم ${to}.`,
      recovery: 'هي الرموز اللي حفظتها لما فعّلت التحقق بخطوتين، مثل xxxxx-xxxxx. كل رمز يُستخدم مرة وحدة فقط.',
    },
    option: {
      totp: 'رمز من تطبيق المصادقة في جوالك',
      email: (to: string | null) => (to ? `رمز إلى ${to}` : 'رمز إلى بريدك الإلكتروني'),
      sms: (to: string | null) => (to ? `رسالة نصية إلى ${to}` : 'رسالة نصية إلى جوالك'),
      whatsapp: (to: string | null) => (to ? `رسالة واتساب إلى ${to}` : 'رسالة واتساب إلى جوالك'),
      recovery: (left: number | undefined) => (left === undefined ? 'أحد الرموز اللي حفظتها' : `باقي لك ${countedAr(left, { one: 'رمز', two: 'رمزان', few: 'رموز', many: 'رمزًا' })}`),
    },
    totpHint: 'الرمز يتجدد كل 30 ثانية. إذا انتهى وأنت تكتبه، اكتب الرمز الجديد.',
    valid: (m: number) => `الرمز صالح لمدة ${countedAr(m, MINUTES_AR)}.`,
    code: 'رمز التحقق',
    send: (to: string) => `أرسل الرمز إلى ${to}`,
    sendPlain: 'أرسل الرمز',
    resend: 'إعادة الإرسال',
    wait: (s: number) => `إعادة الإرسال بعد ${s} ث`,
    sentTo: (to: string) => `تم الإرسال إلى ${to}`,
    notSent: 'ما قدرنا نرسل الرمز الآن. أعد المحاولة أو جرّب طريقة أخرى.',
    verify: 'تحقق وادخل',
    other: 'جرّب طريقة أخرى',
    chooseTitle: 'اختر طريقة التحقق',
    chooseLead: 'هذي الطرق المفعّلة على حسابك.',
    current: 'الحالية',
    back: 'رجوع لتسجيل الدخول',
    backToCode: 'رجوع',
    help: 'ما تقدر توصل لأي طريقة؟ تواصل مع مدير مساحة العمل لإعادة ضبط التحقق بخطوتين.',
    expired: 'انتهت مهلة التحقق. سجّل الدخول مرة أخرى.',
    none: 'لا توجد طريقة متاحة الآن. استخدم رمزًا احتياطيًا.',
  },
  en: {
    title: 'Two-step verification',
    lead: 'An extra security step protects your account. Confirm it is you to continue.',
    name: { totp: 'Authenticator app', email: 'Email', sms: 'Text message (SMS)', whatsapp: 'WhatsApp', recovery: 'Recovery code' },
    heading: {
      totp: 'Enter the code from your authenticator app',
      email: 'Enter the code sent to your email',
      sms: 'Enter the code sent by text message',
      whatsapp: 'Enter the code sent on WhatsApp',
      recovery: 'Enter one of your recovery codes',
    },
    body: {
      totp: 'Open the authenticator app on your phone (such as Google Authenticator, Microsoft Authenticator or Authy) and type the 6-digit code shown under CampaignsHub.',
      email: (to: string) => `We send a 6-digit code to ${to}. If it has not arrived within a minute, check your spam folder.`,
      sms: (to: string) => `We send a text message with a 6-digit code to ${to}.`,
      whatsapp: (to: string) => `We send a 6-digit code in a WhatsApp message to ${to}.`,
      recovery: 'These are the codes you saved when you turned on two-step verification, like xxxxx-xxxxx. Each one works once.',
    },
    option: {
      totp: 'A code from the authenticator app on your phone',
      email: (to: string | null) => (to ? `A code to ${to}` : 'A code to your email'),
      sms: (to: string | null) => (to ? `A text message to ${to}` : 'A text message to your phone'),
      whatsapp: (to: string | null) => (to ? `A WhatsApp message to ${to}` : 'A WhatsApp message to your phone'),
      recovery: (left: number | undefined) => (left === undefined ? 'One of the codes you saved' : `${countedEn(left, 'code', 'codes')} left`),
    },
    totpHint: 'The code changes every 30 seconds. If it runs out while you type, enter the new one.',
    valid: (m: number) => `The code is valid for ${countedEn(m, 'minute', 'minutes')}.`,
    code: 'Verification code',
    send: (to: string) => `Send the code to ${to}`,
    sendPlain: 'Send the code',
    resend: 'Resend',
    wait: (s: number) => `Resend in ${s}s`,
    sentTo: (to: string) => `Sent to ${to}`,
    notSent: 'We could not send the code right now. Try again or use another way.',
    verify: 'Verify and continue',
    other: 'Try another way',
    chooseTitle: 'Choose how to verify',
    chooseLead: 'These are the methods turned on for your account.',
    current: 'Current',
    back: 'Back to sign in',
    backToCode: 'Back',
    help: 'Cannot reach any of these? Ask your workspace admin to reset two-step verification.',
    expired: 'The verification step expired. Sign in again.',
    none: 'No method is available right now. Use a recovery code.',
  },
} as const

const ICONS: Record<Choice, LucideIcon> = { totp: Smartphone, email: Mail, sms: MessageSquare, whatsapp: MessageCircle, recovery: KeyRound }
const DELIVERED = new Set(['sent', 'queued', 'delivered'])
const isSent = (m: Choice | null): m is Sent => m === 'email' || m === 'sms' || m === 'whatsapp'

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
  const hintOf = (m: Choice | null) => (m === 'email' ? info?.email_hint ?? null : m === 'sms' || m === 'whatsapp' ? info?.phone_hint ?? null : null)
  const hint = hintOf(method)
  const ttl = info?.code_ttl_minutes ?? 10

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

  // A code by email, SMS or WhatsApp is sent once on arrival when it is the default, so the person is not asked to press twice.
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
  const optionText = (m: Choice) => m === 'totp' ? c.option.totp : m === 'recovery' ? c.option.recovery(info?.recovery_left) : c.option[m](hintOf(m))
  const bodyText = (m: Choice) => m === 'totp' ? c.body.totp : m === 'recovery' ? c.body.recovery : c.body[m](hintOf(m) ?? c.name[m])

  return (
    <div data-testid="two-step" className="rounded-2xl border border-border bg-surface p-6 shadow-[var(--shadow-small)]">
      <div className="mb-5 flex flex-col items-center gap-2 text-center">
        <span className="flex size-12 items-center justify-center rounded-full bg-brand-500/10 text-brand-600"><ShieldCheck size={24} aria-hidden /></span>
        <h1 className="text-xl font-bold text-text-primary">{c.title}</h1>
        <p className="text-sm text-text-secondary">{c.lead}</p>
      </div>

      {info === null && probe.isPending && <div className="h-24 animate-pulse rounded-xl bg-surface-secondary" />}

      {info !== null && choosing && (
        <div data-testid="two-step-choose">
          <h2 className="text-base font-bold text-text-primary">{c.chooseTitle}</h2>
          <p className="mb-3 text-sm text-text-secondary">{c.chooseLead}</p>
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
                    <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-surface-secondary text-text-secondary"><MIcon size={18} aria-hidden /></span>
                    <span className="min-w-0 flex-1">
                      <span className="flex items-center gap-2 text-sm font-semibold text-text-primary">
                        {c.name[m]}
                        {method === m && <span className="rounded-full bg-brand-500/10 px-2 py-0.5 text-xs font-semibold text-brand-600">{c.current}</span>}
                      </span>
                      <span className="block truncate text-xs text-text-secondary" dir="auto">{optionText(m)}</span>
                    </span>
                    <Chevron size={16} className="shrink-0 text-text-muted" aria-hidden />
                  </button>
                </li>
              )
            })}
          </ul>
          <p className="mt-3 text-xs text-text-muted">{c.help}</p>
          <Button type="button" variant="ghost" className="mt-3 w-full" data-testid="two-step-choose-back" onClick={() => setChoosing(false)}>{c.backToCode}</Button>
        </div>
      )}

      {info !== null && !choosing && method !== null && (
        <>
          <div className="mb-4 flex items-start gap-3 rounded-xl border border-border bg-surface-secondary/50 p-3" data-testid={`two-step-panel-${method}`}>
            <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-brand-500/10 text-brand-600"><Icon size={20} aria-hidden /></span>
            <div className="min-w-0 flex-1">
              <p className="text-xs font-semibold text-brand-600">{c.name[method]}</p>
              <h2 className="text-sm font-bold text-text-primary">{c.heading[method]}</h2>
              <p className="mt-1 text-sm leading-relaxed text-text-secondary" data-testid="two-step-explain">{bodyText(method)}</p>
              {method === 'totp' && <p className="mt-1 text-xs text-text-muted">{c.totpHint}</p>}
            </div>
          </div>

          {info.methods.length === 0 && method === 'recovery' && <p className="mb-3 text-sm text-text-secondary">{c.none}</p>}

          {isSent(method) && (
            <div className="mb-3 space-y-2">
              {delivery !== null && DELIVERED.has(delivery) && (
                <p data-testid="two-step-sent" className="flex items-center gap-1.5 text-sm text-success" dir="auto">
                  <CheckCircle2 size={16} aria-hidden /> {c.sentTo(hint ?? c.name[method])} · {c.valid(ttl)}
                </p>
              )}
              {delivery !== null && !DELIVERED.has(delivery) && <p className="text-sm text-warning">{c.notSent}</p>}
              <Button
                type="button"
                size="sm"
                variant="secondary"
                data-testid="two-step-send"
                className="w-full"
                disabled={cooldown > 0}
                loading={send.isPending}
                onClick={() => send.mutate(method)}
              >
                {cooldown > 0 ? c.wait(cooldown) : delivery === null ? (hint ? c.send(hint) : c.sendPlain) : c.resend}
              </Button>
            </div>
          )}

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

            {error && <p role="alert" data-testid="two-step-error" className="text-sm text-danger">{error}</p>}

            <Button type="submit" size="lg" className="w-full" data-testid="two-step-verify" loading={verify.isPending}>{c.verify}</Button>
          </form>

          <div className="mt-4 flex flex-col gap-1 border-t border-border pt-3">
            {choices.length > 1 && (
              <Button type="button" variant="ghost" className="w-full" data-testid="two-step-other" onClick={() => setChoosing(true)}>{c.other}</Button>
            )}
            <Button
              type="button"
              variant="ghost"
              className="w-full"
              data-testid="two-step-back"
              onClick={() => { void cancelTwoStep().catch(() => undefined); onLeave(false) }}
            >
              {c.back}
            </Button>
          </div>
        </>
      )}
    </div>
  )
}

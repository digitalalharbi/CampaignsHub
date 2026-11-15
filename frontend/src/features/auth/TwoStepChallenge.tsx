import { useEffect, useRef, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { KeyRound, Mail, MessageSquare, Smartphone } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { toApiError } from '@/lib/api/client'
import type { AuthUser } from '@/lib/api/types'
import { OtpField } from './OtpField'
import { cancelTwoStep, fetchTwoStep, sendTwoStepCode, verifyTwoStep, type TwoStepInfo, type TwoStepMethod } from './api'

/**
 * TWO-STEP-001 — the second step at sign-in.
 *
 * Reached from every door: the password form, Google (`/login?two_step=1`), a phone code and linking in place. The server holds
 * the session at this step and opens it only when the answer is right; this screen asks for it and nothing else. One sentence
 * for every way of being wrong, because that is what the server says.
 */
type Choice = TwoStepMethod | 'recovery'

const COPY = {
  ar: {
    title: 'التحقق بخطوتين',
    totp: 'تطبيق المصادقة',
    email: 'البريد',
    sms: 'رسالة نصية',
    recovery: 'رمز احتياطي',
    code: 'الرمز',
    send: 'أرسل الرمز',
    resend: 'إعادة الإرسال',
    wait: (s: number) => `إعادة الإرسال بعد ${s} ث`,
    sentTo: (to: string) => `أُرسل إلى ${to}`,
    notSent: 'لم يُرسل الرمز. جرّب طريقة أخرى.',
    verify: 'تحقق',
    back: 'رجوع',
    expired: 'انتهت مهلة التحقق. سجّل الدخول مرة أخرى.',
    none: 'لا توجد طريقة متاحة الآن. استخدم رمزًا احتياطيًا.',
  },
  en: {
    title: 'Two-step verification',
    totp: 'Authenticator app',
    email: 'Email',
    sms: 'Text message',
    recovery: 'Recovery code',
    code: 'Code',
    send: 'Send the code',
    resend: 'Resend',
    wait: (s: number) => `Resend in ${s}s`,
    sentTo: (to: string) => `Sent to ${to}`,
    notSent: 'The code was not sent. Try another method.',
    verify: 'Verify',
    back: 'Back',
    expired: 'The verification step expired. Sign in again.',
    none: 'No method is available right now. Use a recovery code.',
  },
} as const

const ICONS = { totp: Smartphone, email: Mail, sms: MessageSquare, recovery: KeyRound } as const
const DELIVERED = new Set(['sent', 'queued', 'delivered'])

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
  const [code, setCode] = useState('')
  const [cooldown, setCooldown] = useState(0)
  const [delivery, setDelivery] = useState<string | null>(null)
  const autoSent = useRef(false)

  const method: Choice | null = choice ?? info?.default ?? (info?.methods.length ? info.methods[0] : info ? 'recovery' : null)
  const hint = method === 'email' ? info?.email_hint : method === 'sms' ? info?.phone_hint : null

  useEffect(() => {
    if (cooldown <= 0) return
    const id = window.setInterval(() => setCooldown((s) => (s <= 1 ? 0 : s - 1)), 1000)
    return () => window.clearInterval(id)
  }, [cooldown])

  const send = useMutation({ meta: { feedback: 'none' },
    mutationFn: (m: 'email' | 'sms') => sendTwoStepCode(m),
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
    if (info.default === 'email' || info.default === 'sms') {
      autoSent.current = true
      send.mutate(info.default)
    }
  }, [info, choice, send])

  useEffect(() => {
    if (probe.isError && toApiError(probe.error).status === 410) onLeave(true)
  }, [probe.isError, probe.error, onLeave])

  const pick = (m: Choice) => {
    setChoice(m)
    setCode('')
    setDelivery(null)
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

  return (
    <div data-testid="two-step" className="rounded-2xl border border-border bg-surface p-6 shadow-[var(--shadow-small)]">
      <h1 className="mb-4 text-xl font-bold text-text-primary">{c.title}</h1>

      {info === null && probe.isPending && <div className="h-24 animate-pulse rounded-xl bg-surface-secondary" />}

      {info !== null && (
        <>
          {choices.length > 1 && (
            <div role="tablist" className="mb-4 flex flex-wrap gap-2">
              {choices.map((m) => {
                const Icon = ICONS[m]
                return (
                  <button
                    key={m}
                    type="button"
                    role="tab"
                    aria-selected={method === m}
                    data-testid={`two-step-method-${m}`}
                    onClick={() => pick(m)}
                    className={`inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-sm font-semibold transition-colors ${method === m ? 'border-brand-500 bg-brand-500/10 text-brand-600' : 'border-border text-text-secondary hover:bg-surface-secondary'}`}
                  >
                    <Icon size={14} /> {c[m]}
                  </button>
                )
              })}
            </div>
          )}

          {info.methods.length === 0 && method === 'recovery' && <p className="mb-3 text-sm text-text-secondary">{c.none}</p>}

          {(method === 'email' || method === 'sms') && (
            <div className="mb-3 flex flex-wrap items-center gap-2 text-sm text-text-secondary">
              {delivery !== null && DELIVERED.has(delivery) && hint && <span data-testid="two-step-sent">{c.sentTo(hint)}</span>}
              {delivery !== null && !DELIVERED.has(delivery) && <span className="text-warning">{c.notSent}</span>}
              <Button
                type="button"
                size="sm"
                variant="secondary"
                data-testid="two-step-send"
                className="ms-auto"
                disabled={cooldown > 0}
                loading={send.isPending}
                onClick={() => send.mutate(method)}
              >
                {cooldown > 0 ? c.wait(cooldown) : delivery === null ? c.send : c.resend}
              </Button>
            </div>
          )}

          <form onSubmit={(e) => { e.preventDefault(); submit() }} className="space-y-4">
            {method === 'recovery' ? (
              <Input
                data-testid="two-step-recovery"
                dir="ltr"
                autoComplete="one-time-code"
                aria-label={c.recovery}
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
            <Button
              type="button"
              variant="ghost"
              className="w-full"
              data-testid="two-step-back"
              onClick={() => { void cancelTwoStep().catch(() => undefined); onLeave(false) }}
            >
              {c.back}
            </Button>
          </form>
        </>
      )}
    </div>
  )
}

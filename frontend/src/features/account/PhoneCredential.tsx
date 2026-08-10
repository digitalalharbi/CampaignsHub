import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { MessageCircle, Phone, ShieldCheck } from 'lucide-react'
import {
  confirmPhone, getPhoneCredential, revokePhoneCredential, startPhoneConfirmation,
  type PhoneCredential as PhoneCredentialData,
} from './api'
import { OtpField } from '@/features/auth/OtpField'
import { PhoneField, phoneFieldValue, DEFAULT_DIAL_CODE } from '@/components/ui/PhoneField'
import { Button } from '@/components/ui/Button'
import { Badge } from '@/components/ui/Badge'
import { Alert } from '@/components/ui/Alert'
import { Skeleton } from '@/components/ui/States'
import { fmtDateTime } from '@/lib/datetime'
import { toApiError } from '@/lib/api/client'
import { useUi } from '@/stores/ui'

/**
 * AUTH-PHONE-001 — the mobile number, from Account security.
 *
 * ## What this screen is for
 *
 * A number reaches an account two ways: through registration, where the mobile gate proves it, and
 * through a profile edit, where nothing does. Because a proved number is a SIGN-IN credential, the
 * second route is not allowed to mint one — `MeController` withdraws the proof whenever the number
 * changes. This panel is how somebody puts it back, and the only place in the product that can.
 *
 * ## Why the channels are drawn separately, and honestly
 *
 * `GET /me/phone` reports `sms` and `whatsapp` independently because they are independently
 * configured, and what they say decides what this screen may OFFER. With `whatsapp: false` there is
 * no WhatsApp provider wired: a «sign in with WhatsApp» option over that would be a button that
 * cannot work, and a customer who chose it would sit waiting for a message nobody sent. So the
 * channel is drawn, named, and marked **awaiting credentials** — a fact read off the response rather
 * than a claim in a document — and it is not selectable.
 *
 * When neither channel is configured the code is minted and delivered to nobody. The screen says
 * exactly that instead of «check your phone», because the alternative is the product claiming a
 * message it never made.
 */

type Step = 'idle' | 'code'
type Channel = 'sms' | 'whatsapp'

/** Anything else means nothing reached a handset — see `ContactVerificationService`. */
const DELIVERED = new Set(['sent', 'queued', 'delivered'])

export function PhoneCredentialCard() {
  const ar = useUi((u) => u.locale) === 'ar'
  const qc = useQueryClient()
  const q = useQuery({ queryKey: ['me', 'phone'], queryFn: getPhoneCredential })
  const invalidate = () => { void qc.invalidateQueries({ queryKey: ['me', 'phone'] }) }

  const a = {
    start: useMutation({
      mutationFn: (b: { phone: string; channel: Channel }) => startPhoneConfirmation(b.phone, b.channel),
    }),
    confirm: useMutation({
      mutationFn: (b: { verification_id: string; code: string }) => confirmPhone(b.verification_id, b.code),
      onSuccess: invalidate,
    }),
    revoke: useMutation({ mutationFn: revokePhoneCredential, onSuccess: invalidate }),
  }

  const [step, setStep] = useState<Step>('idle')
  const [national, setNational] = useState('')
  const [dialCode, setDialCode] = useState(DEFAULT_DIAL_CODE)
  const [channel, setChannel] = useState<Channel>('sms')
  const [verificationId, setVerificationId] = useState<string | null>(null)
  const [delivery, setDelivery] = useState<string | null>(null)
  const [code, setCode] = useState('')
  const [cooldown, setCooldown] = useState(0)
  const [error, setError] = useState('')
  const [done, setDone] = useState('')

  const data = q.data
  const channels = data?.channels

  // WhatsApp cannot be pre-selected when there is no provider behind it — the state would be a
  // choice the customer never made and the send would go nowhere.
  useEffect(() => {
    if (channels && !channels.whatsapp && channel === 'whatsapp') setChannel('sms')
  }, [channels, channel])

  useEffect(() => {
    if (cooldown <= 0) return
    const t = setTimeout(() => setCooldown((c) => c - 1), 1000)
    return () => clearTimeout(t)
  }, [cooldown])

  const e164 = phoneFieldValue(national, dialCode)

  const send = async () => {
    setError('')
    setDone('')
    if (e164 === null) {
      setError(ar ? 'أدخل رقم جوال صحيحاً.' : 'Enter a valid mobile number.')
      return
    }
    try {
      const r = await a.start.mutateAsync({ phone: e164, channel })
      setVerificationId(r.verification_id)
      setDelivery(r.delivery_status)
      setCooldown(r.resend_after)
      // Dev-only: the server returns `dev_code` outside production ONLY, hard-gated server-side.
      setCode(r.dev_code ?? '')
      setStep('code')
    } catch (e) {
      setError(toApiError(e).message)
    }
  }

  const confirm = async (value: string) => {
    setError('')
    if (!verificationId || value.trim().length < 6) return
    try {
      await a.confirm.mutateAsync({ verification_id: verificationId, code: value.trim() })
      setStep('idle')
      setVerificationId(null)
      setCode('')
      setNational('')
      setDone(ar ? 'تم توثيق الرقم.' : 'The number is confirmed.')
    } catch (e) {
      setError(toApiError(e).message)
    }
  }

  const revoke = async () => {
    setError('')
    setDone('')
    try {
      await a.revoke.mutateAsync()
      setDone(ar ? 'لم يعد الرقم وسيلة لتسجيل الدخول.' : 'The number is no longer a sign-in method.')
    } catch (e) {
      setError(toApiError(e).message)
    }
  }

  return (
    <div data-testid="phone-credential" className="rounded-2xl border border-border bg-surface p-6 shadow-[var(--shadow-small)]">
      <h2 className="mb-1 flex items-center gap-2 text-lg font-bold text-text-primary">
        <Phone size={18} /> {ar ? 'رقم الجوال' : 'Your mobile number'}
      </h2>
      <p className="mb-4 text-sm text-text-secondary">
        {ar
          ? 'رقم موثّق يُستخدم لاستعادة الحساب، وتنبيهات الأمان، وتسجيل الدخول برمز — بعد إثبات ملكيته من الجهاز نفسه.'
          : 'A confirmed number is used for account recovery, security alerts and code sign-in — once you have proved it from the handset itself.'}
      </p>

      {q.isLoading ? (
        <Skeleton className="h-28" />
      ) : !data ? (
        <p className="text-sm text-text-muted">{ar ? 'تعذّر قراءة حالة الرقم.' : 'The number state could not be read.'}</p>
      ) : (
        <div className="space-y-4">
          <CurrentState data={data} ar={ar} />

          {done && <Alert severity="positive" title={ar ? 'تم' : 'Done'}>{done}</Alert>}

          {step === 'idle' ? (
            <div className="space-y-4">
              <PhoneField
                id="phone-credential-number"
                label={data.confirmed ? (ar ? 'رقم جديد' : 'A new number') : (ar ? 'رقم الجوال' : 'Mobile number')}
                value={national}
                onChange={setNational}
                dialCode={dialCode}
                onDialCodeChange={setDialCode}
                ar={ar}
                hint={
                  data.confirmed
                    ? ar
                      ? 'تغيير الرقم يلغي توثيق الرقم الحالي حتى يُثبت الجديد.'
                      : 'Changing the number withdraws the current proof until the new one is proved.'
                    : undefined
                }
              />

              <ChannelChoice channels={data.channels} value={channel} onChange={setChannel} ar={ar} />

              {/*
                Neither channel is configured, and the customer is told BEFORE pressing Send.
                Otherwise the only honest word about it arrives after the code step opens, by which
                point somebody is already watching a handset for a message nobody sent.
              */}
              {!data.channels.sms && !data.channels.whatsapp && (
                <Alert severity="warning" title={ar ? 'لا يوجد مزوّد رسائل مُهيّأ' : 'No messaging provider is configured'}>
                  {ar
                    ? 'لن يصل الرمز إلى أي جهاز على هذه البيئة. الإرسال متاح لأغراض التطوير والاختبار فقط حتى تُضاف بيانات اعتماد المزوّد.'
                    : 'The code will not reach any handset on this environment. Sending is available for development and testing only until the provider credentials are added.'}
                </Alert>
              )}

              {error && <Alert severity="danger" title={ar ? 'تعذّر الإرسال' : 'Could not send'}>{error}</Alert>}

              <div className="flex flex-wrap items-center gap-2">
                <Button data-testid="phone-send-code" onClick={send} loading={a.start.isPending} disabled={cooldown > 0}>
                  {cooldown > 0
                    ? ar ? `إعادة الإرسال بعد ${cooldown} ثانية` : `Resend in ${cooldown}s`
                    : ar ? 'إرسال رمز التحقق' : 'Send a verification code'}
                </Button>
                {data.confirmed && (
                  <Button data-testid="phone-revoke" variant="ghost" onClick={revoke} loading={a.revoke.isPending}>
                    {ar ? 'إيقاف استخدامه لتسجيل الدخول' : 'Stop using it to sign in'}
                  </Button>
                )}
              </div>
            </div>
          ) : (
            <form
              className="space-y-4"
              onSubmit={(e) => { e.preventDefault(); confirm(code) }}
            >
              <OtpField
                testId="phone-otp"
                label={ar ? 'رمز التحقق' : 'Verification code'}
                value={code}
                onChange={setCode}
                onComplete={confirm}
                autoFocus
                disabled={a.confirm.isPending}
              />

              {/*
                Honest about delivery. `awaiting_provider_credentials` means no provider is wired and
                NOTHING was sent — telling somebody to check their phone over that would leave them
                waiting for a message that is not coming.
              */}
              {delivery !== null && !DELIVERED.has(delivery) && (
                <p data-testid="phone-code-undelivered" role="status" className="rounded-xl bg-surface-secondary px-4 py-3 text-[13px] leading-relaxed text-text-secondary">
                  {ar
                    ? 'لم يُرسل الرمز فعلياً: لا يوجد مزوّد رسائل مُهيّأ بعد على هذه البيئة. أضف بيانات اعتماد المزوّد لتفعيل الإرسال.'
                    : 'The code was not actually sent: no messaging provider is configured on this environment yet. Add the provider credentials to enable delivery.'}
                </p>
              )}

              {error && <Alert severity="danger" title={ar ? 'رمز غير صحيح' : 'That code did not work'}>{error}</Alert>}

              <div className="flex flex-wrap items-center gap-2">
                <Button
                  type="submit"
                  data-testid="phone-confirm-code"
                  loading={a.confirm.isPending}
                  disabled={code.trim().length < 6}
                >
                  {ar ? 'تأكيد الرقم' : 'Confirm the number'}
                </Button>
                <Button
                  type="button"
                  variant="ghost"
                  onClick={send}
                  disabled={a.start.isPending || cooldown > 0}
                >
                  {cooldown > 0
                    ? ar ? `إعادة الإرسال بعد ${cooldown} ثانية` : `Resend in ${cooldown}s`
                    : ar ? 'إعادة إرسال الرمز' : 'Resend the code'}
                </Button>
                <Button
                  type="button"
                  variant="ghost"
                  onClick={() => { setStep('idle'); setError(''); setCode('') }}
                >
                  {ar ? 'رجوع' : 'Back'}
                </Button>
              </div>
            </form>
          )}
        </div>
      )}
    </div>
  )
}

function CurrentState({ data, ar }: { data: PhoneCredentialData; ar: boolean }) {
  return (
    <div className="rounded-xl border border-border bg-surface-secondary p-4">
      <div className="flex flex-wrap items-center gap-3">
        <span dir="ltr" data-testid="phone-current" className="text-base font-bold tnum text-text-primary">
          {data.phone ?? (ar ? 'لا يوجد رقم' : 'No number')}
        </span>
        {data.confirmed ? (
          <Badge tone="success" data-testid="phone-state">
            <ShieldCheck size={12} className="inline" /> {ar ? 'موثّق' : 'Confirmed'}
          </Badge>
        ) : (
          <Badge tone="warning" data-testid="phone-state">{ar ? 'غير موثّق' : 'Not confirmed'}</Badge>
        )}
        {data.confirmed && data.confirmed_at && (
          <span className="text-xs text-text-muted">{ar ? 'منذ' : 'since'} {fmtDateTime(data.confirmed_at)}</span>
        )}
      </div>

      <p className="mt-2 text-[13px] leading-relaxed text-text-secondary">
        {data.confirmed
          ? ar
            ? 'هذا الرقم يصلح لتسجيل الدخول برمز ولاستعادة الحساب.'
            : 'This number can be used for code sign-in and for account recovery.'
          : ar
            ? 'الرقم مُسجّل كوسيلة تواصل فقط، ولا يصلح لتسجيل الدخول حتى تُثبت ملكيته.'
            : 'The number is kept as a contact detail only. It cannot sign anybody in until it is proved.'}
      </p>

      <div className="mt-3 grid gap-2 sm:grid-cols-2">
        <ChannelState
          ar={ar}
          testId="phone-channel-sms"
          icon={<Phone size={14} />}
          name={ar ? 'الرسائل النصية (SMS)' : 'SMS'}
          configured={data.channels.sms}
        />
        <ChannelState
          ar={ar}
          testId="phone-channel-whatsapp"
          icon={<MessageCircle size={14} />}
          name={ar ? 'واتساب' : 'WhatsApp'}
          configured={data.channels.whatsapp}
        />
      </div>
    </div>
  )
}

function ChannelState({
  ar, name, icon, configured, testId,
}: { ar: boolean; name: string; icon: React.ReactNode; configured: boolean; testId: string }) {
  return (
    <div data-testid={testId} className="flex items-center justify-between gap-2 rounded-lg bg-surface px-3 py-2">
      <span className="flex items-center gap-2 text-[13px] font-semibold text-text-primary">{icon} {name}</span>
      {configured ? (
        <Badge tone="success">{ar ? 'مفعّلة' : 'Enabled'}</Badge>
      ) : (
        // READY_FOR_CREDENTIALS, stated as what it is: the channel exists and has no credentials.
        <Badge tone="neutral">{ar ? 'بانتظار بيانات الاعتماد' : 'Awaiting credentials'}</Badge>
      )}
    </div>
  )
}

function ChannelChoice({
  channels, value, onChange, ar,
}: { channels: { sms: boolean; whatsapp: boolean }; value: Channel; onChange: (c: Channel) => void; ar: boolean }) {
  const options: { key: Channel; label: string }[] = [
    { key: 'sms', label: ar ? 'رسالة نصية' : 'Text message' },
    { key: 'whatsapp', label: ar ? 'واتساب' : 'WhatsApp' },
  ]

  /*
   * An unconfigured channel is refused only while a working one exists — that is what «disabled»
   * means here: pick the other one. With NOTHING configured, disabling both would leave a radio
   * group where no option can be chosen, and would lock the development and E2E path behind
   * credentials that by definition are not there. The banner above the group carries the fact
   * instead, and every option stays labelled with its real state either way.
   */
  const anyConfigured = channels.sms || channels.whatsapp

  return (
    <fieldset>
      <legend className="mb-1.5 text-sm font-semibold text-text-secondary">
        {ar ? 'أرسل الرمز عبر' : 'Send the code through'}
      </legend>
      <div className="flex flex-wrap gap-2">
        {options.map((o) => {
          const configured = channels[o.key]
          const available = configured || !anyConfigured
          return (
            <label
              key={o.key}
              data-testid={`phone-channel-choice-${o.key}`}
              className={`flex items-center gap-2 rounded-xl border px-3 py-2 text-sm ${
                value === o.key ? 'border-brand-600 bg-brand-50 font-semibold text-brand-700' : 'border-border text-text-secondary'
              } ${available ? 'cursor-pointer' : 'cursor-not-allowed opacity-60'}`}
            >
              <input
                type="radio"
                name="phone-channel"
                value={o.key}
                checked={value === o.key}
                disabled={!available}
                onChange={() => onChange(o.key)}
                className="accent-brand-600"
              />
              {o.label}
              {!configured && (
                <span className="text-xs font-normal text-text-muted">
                  {ar ? '— غير مُهيّأ بعد' : '— not configured yet'}
                </span>
              )}
            </label>
          )
        })}
      </div>
      {!channels.whatsapp && (
        <p data-testid="phone-whatsapp-unavailable" className="mt-2 text-[13px] text-text-muted">
          {ar
            ? 'واتساب غير متاح كوسيلة تحقق أو تسجيل دخول على هذه البيئة حتى تُضاف بيانات اعتماد المزوّد.'
            : 'WhatsApp is not available as a verification or sign-in channel on this environment until the provider credentials are added.'}
        </p>
      )}
    </fieldset>
  )
}

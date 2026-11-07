import { useState } from 'react'
import { Switch } from '@/components/ui/Switch'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Checkbox } from '@/components/ui/Checkbox'
import { useTestChannel, useWhatsAppOptIn, type NotifPrefs, type TestChannel } from './api'

/**
 * The three channels, each with its master switch and its «اختبار» button — NOTIFICATION-CHANNELS-001.
 *
 * Owner: «كل شيء يكون إشعارات على الإيميل + النظام + لاحقًا على الواتساب». Short labels only; the
 * cards are a fixed height so a result line or an opt-in step never moves the page. WhatsApp is a
 * «قريبًا» chip until the server says a provider is configured, and then a number with a consent
 * checkbox and a code — never a switch for a channel that could not send.
 */
const RESULT_AR: Record<string, string> = {
  sent: 'تم الإرسال',
  sandbox: 'تجريبي، لم يصل لأحد',
  queued: 'في الانتظار',
  accepted: 'قبلها المزوّد',
  awaiting_credentials: 'البريد غير مربوط',
  suppressed_by_preference: 'القناة مغلقة لديك',
  address_not_proven: 'عنوانك غير مؤكد',
  whatsapp_not_available: 'واتساب غير متاح بعد',
  whatsapp_no_opt_in: 'لا موافقة على واتساب',
  failed: 'تعذّر الإرسال',
}
const RESULT_EN: Record<string, string> = {
  sent: 'Sent',
  sandbox: 'Test mode, reached nobody',
  queued: 'Queued',
  accepted: 'Accepted by the provider',
  awaiting_credentials: 'Email is not connected',
  suppressed_by_preference: 'This channel is off for you',
  address_not_proven: 'Your address is not verified',
  whatsapp_not_available: 'WhatsApp is not available yet',
  whatsapp_no_opt_in: 'No WhatsApp opt-in',
  failed: 'Could not be sent',
}

function codeOf(e: unknown): string {
  const body = (e as { response?: { data?: { errors?: { code?: string } } } })?.response?.data
  return body?.errors?.code ?? 'failed'
}

interface Props { p: NotifPrefs; setP: (next: NotifPrefs) => void; ar: boolean }

export function ChannelsPanel({ p, setP, ar }: Props) {
  const test = useTestChannel()
  const optIn = useWhatsAppOptIn()
  const [result, setResult] = useState<Partial<Record<TestChannel, string>>>({})
  const [phone, setPhone] = useState('')
  const [consent, setConsent] = useState(false)
  const [verification, setVerification] = useState<string | null>(null)
  const [code, setCode] = useState('')
  const wa = p.whatsapp ?? { provider_configured: false, opted_in: false, opted_out: false, number_hint: null }
  const words = ar ? RESULT_AR : RESULT_EN

  const run = async (channel: TestChannel) => {
    setResult((r) => ({ ...r, [channel]: '…' }))
    try {
      const res = await test.mutateAsync(channel)
      setResult((r) => ({ ...r, [channel]: words[res.state] ?? res.state }))
    } catch (e) {
      const c = codeOf(e)
      setResult((r) => ({ ...r, [channel]: words[c] ?? words.failed }))
    }
  }

  const card = 'flex min-h-[148px] flex-col justify-between gap-3 rounded-xl border border-border bg-surface-secondary p-4'
  const head = 'flex items-center justify-between gap-2 text-sm font-bold text-text-primary'

  const testButton = (channel: TestChannel, disabled = false) => (
    <div className="flex items-center gap-3">
      <Button
        type="button" size="sm" variant="secondary" disabled={disabled || test.isPending}
        data-testid={`test-${channel}`}
        aria-label={`${ar ? 'اختبار' : 'Test'} — ${channel === 'in_app' ? (ar ? 'داخل النظام' : 'in-app') : channel === 'email' ? (ar ? 'البريد' : 'email') : (ar ? 'واتساب' : 'WhatsApp')}`}
        onClick={() => void run(channel)}
      >
        {ar ? 'اختبار' : 'Test'}
      </Button>
      <span data-testid={`test-result-${channel}`} aria-live="polite" className="min-h-5 truncate text-xs text-text-secondary">{result[channel] ?? ''}</span>
    </div>
  )

  return (
    <div data-testid="channels-panel" className="mt-5 grid gap-3 border-t border-border pt-5 sm:grid-cols-3">
      <div className={card}>
        <div className={head}>{ar ? 'داخل النظام' : 'In-app'}</div>
        <Switch
          checked={p.channels.in_app}
          onCheckedChange={(v) => setP({ ...p, channels: { ...p.channels, in_app: v } })}
          label={ar ? 'إشعارات داخل النظام' : 'In-app notifications'}
        />
        {testButton('in_app')}
      </div>

      <div className={card}>
        <div className={head}>{ar ? 'البريد' : 'Email'}</div>
        <Switch
          checked={p.channels.email}
          onCheckedChange={(v) => setP({ ...p, channels: { ...p.channels, email: v } })}
          label={ar ? 'البريد الإلكتروني' : 'Email'}
        />
        {testButton('email')}
      </div>

      <div className={card} data-testid="whatsapp-card">
        <div className={head}>
          <span>{ar ? 'واتساب' : 'WhatsApp'}</span>
          {!wa.provider_configured && (
            <span data-testid="whatsapp-soon" className="inline-flex h-6 items-center rounded-[var(--radius-pill)] border border-dashed border-border px-2 text-xs font-bold text-text-muted">
              {ar ? 'قريبًا' : 'Soon'}
            </span>
          )}
        </div>

        {wa.provider_configured && wa.opted_in ? (
          <div className="flex items-center justify-between gap-2 text-sm text-text-primary">
            <span dir="ltr" className="font-mono text-xs" data-testid="whatsapp-number">{wa.number_hint}</span>
            <Button type="button" size="sm" variant="ghost" onClick={() => optIn.withdraw.mutate()} data-testid="whatsapp-withdraw">
              {ar ? 'إيقاف' : 'Turn off'}
            </Button>
          </div>
        ) : wa.provider_configured && verification === null ? (
          <div className="space-y-2">
            <Input dir="ltr" inputMode="tel" placeholder="05XXXXXXXX" value={phone} onChange={(e) => setPhone(e.target.value)} aria-label={ar ? 'رقم واتساب' : 'WhatsApp number'} />
            <Checkbox id="wa-consent" label={ar ? 'أوافق على الرسائل' : 'I agree to messages'} checked={consent} onChange={(e) => setConsent(e.target.checked)} />
            <Button
              type="button" size="sm" disabled={!consent || phone.trim() === ''} data-testid="whatsapp-start"
              onClick={async () => { const r = await optIn.start.mutateAsync({ phone, consent }); setVerification(r.verification_id) }}
            >
              {ar ? 'أرسل الرمز' : 'Send code'}
            </Button>
          </div>
        ) : wa.provider_configured ? (
          <div className="flex items-center gap-2">
            <Input dir="ltr" inputMode="numeric" maxLength={6} placeholder="000000" value={code} onChange={(e) => setCode(e.target.value)} aria-label={ar ? 'رمز التأكيد' : 'Confirmation code'} />
            <Button
              type="button" size="sm" disabled={code.length !== 6} data-testid="whatsapp-confirm"
              onClick={async () => { await optIn.confirm.mutateAsync({ verification_id: verification ?? '', code, consent }); setVerification(null); setCode('') }}
            >
              {ar ? 'تأكيد' : 'Confirm'}
            </Button>
          </div>
        ) : (
          <span className="text-xs text-text-muted">{ar ? 'بعد ربط المزوّد' : 'After the provider is linked'}</span>
        )}

        {testButton('whatsapp', !wa.provider_configured || !wa.opted_in)}
      </div>
    </div>
  )
}

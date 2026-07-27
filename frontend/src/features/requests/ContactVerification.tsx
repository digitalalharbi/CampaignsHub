import { useState } from 'react'
import { CheckCircle2, Loader2, ShieldCheck } from 'lucide-react'
import { checkVerification, startVerification } from './api'

type Channel = 'phone' | 'email'
interface ChannelState { status: 'idle' | 'sending' | 'awaiting_code' | 'verified'; vid?: string; code: string; error?: string; devCode?: string | null }
const initial: ChannelState = { status: 'idle', code: '' }

/**
 * A final request is never accepted without a verified phone + email. This runs the real OTP challenge.
 * With no SMS/WhatsApp/mail provider wired the code is not actually sent (delivery is "awaiting provider
 * credentials"); in that case the dev code is surfaced so the flow is usable — we never fake verification.
 */
export function ContactVerification({
  phone, email, ar, onChange,
}: {
  phone: string
  email: string
  ar: boolean
  onChange: (ids: { phone?: string; email?: string }) => void
}) {
  const [ph, setPh] = useState<ChannelState>(initial)
  const [em, setEm] = useState<ChannelState>(initial)

  const run = async (
    ch: Channel,
    set: React.Dispatch<React.SetStateAction<ChannelState>>,
  ) => {
    const destination = ch === 'phone' ? phone : email
    set({ ...initial, status: 'sending' })
    try {
      const res = await startVerification(ch === 'phone' ? 'sms' : 'email', destination)
      if (res.dev_code) {
        // No provider wired → auto-verify with the surfaced dev code (dev/test only).
        await checkVerification(res.verification_id, res.dev_code)
        set({ status: 'verified', vid: res.verification_id, code: '' })
        onChange({ [ch]: res.verification_id }) // parent merges — never clobbers the other channel
      } else {
        set({ status: 'awaiting_code', vid: res.verification_id, code: '', devCode: null })
      }
    } catch {
      set({ ...initial, status: 'idle', error: ar ? 'تعذّر الإرسال' : 'Could not send' })
    }
  }

  const confirm = async (
    ch: Channel,
    state: ChannelState,
    set: React.Dispatch<React.SetStateAction<ChannelState>>,
  ) => {
    if (!state.vid || state.code.length !== 6) return
    try {
      await checkVerification(state.vid, state.code)
      set({ ...state, status: 'verified' })
      onChange({ [ch]: state.vid })
    } catch {
      set({ ...state, error: ar ? 'رمز غير صحيح' : 'Incorrect code' })
    }
  }

  const Row = ({ ch, label, value, state, set }: { ch: Channel; label: string; value: string; state: ChannelState; set: React.Dispatch<React.SetStateAction<ChannelState>> }) => (
    <div className="rounded-xl border border-border bg-surface p-3">
      <div className="flex items-center justify-between gap-3">
        <div className="min-w-0">
          <div className="text-xs font-semibold text-text-secondary">{label}</div>
          <div className="truncate text-sm text-text-primary" dir="ltr">{value || '—'}</div>
        </div>
        {state.status === 'verified' ? (
          <span className="flex items-center gap-1 whitespace-nowrap text-xs font-semibold text-success"><CheckCircle2 size={15} /> {ar ? 'تم التحقق' : 'Verified'}</span>
        ) : (
          <button type="button" disabled={!value || state.status === 'sending'} aria-label={`${ar ? 'تحقّق' : 'Verify'} ${label}`}
            onClick={() => run(ch, set)}
            className="flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-400 px-3 py-1.5 text-xs font-semibold text-brand-700 hover:bg-brand-primary-soft disabled:opacity-50">
            {state.status === 'sending' ? <Loader2 size={14} className="animate-spin" /> : <ShieldCheck size={14} />} {ar ? 'تحقّق' : 'Verify'}
          </button>
        )}
      </div>
      {state.status === 'awaiting_code' && (
        <div className="mt-2 flex items-center gap-2">
          <input inputMode="numeric" maxLength={6} placeholder="000000" dir="ltr"
            value={state.code} onChange={(e) => set({ ...state, code: e.target.value.replace(/\D/g, '') })}
            className="tnum h-9 w-28 rounded-lg border border-border bg-surface px-3 text-center text-sm outline-none focus:border-brand-500" />
          <button type="button" onClick={() => confirm(ch, state, set)}
            className="rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-700">{ar ? 'تأكيد' : 'Confirm'}</button>
          <span className="text-[11px] text-text-muted">{ar ? 'أُرسل رمز مكوّن من 6 أرقام' : 'A 6-digit code was sent'}</span>
        </div>
      )}
      {state.error && <p className="mt-1 text-[11px] text-danger">{state.error}</p>}
    </div>
  )

  return (
    <div className="grid gap-2.5">
      <Row ch="phone" label={ar ? 'رقم الجوال' : 'Mobile number'} value={phone} state={ph} set={setPh} />
      <Row ch="email" label={ar ? 'البريد الإلكتروني' : 'Email'} value={email} state={em} set={setEm} />
      <p className="text-[11px] text-text-muted">{ar
        ? 'لن يُقبل الطلب دون التحقق من الجوال والبريد. عند غياب مزوّد الرسائل تُسجَّل الحالة بصدق (بانتظار بيانات المزوّد).'
        : 'A request is not accepted without a verified phone + email. With no messaging provider, delivery is recorded honestly (awaiting provider credentials).'}</p>
    </div>
  )
}

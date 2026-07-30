import { useState } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import { useMutation } from '@tanstack/react-query'
import { Loader2, LogIn, Mail, Phone } from 'lucide-react'
import { portalLoginStart, portalLoginVerify } from '../clientPortalApi'
import { PortalShell } from './PortalShell'
import { toApiError } from '@/lib/api/client'
import { useUi } from '@/stores/ui'

/** Client portal sign-in: OTP to phone or email → httpOnly-cookie session (no token in JS). */
export function ClientPortalLoginPage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const navigate = useNavigate()
  const [channel, setChannel] = useState<'email' | 'sms'>('email')
  const [destination, setDestination] = useState('')
  const [vid, setVid] = useState<string | null>(null)
  const [code, setCode] = useState('')
  const [error, setError] = useState<string | null>(null)
  // Dev-only: the backend returns dev_code ONLY in non-production (hard-gated server-side), so this affordance
  // can never appear in production. It lets a local reviewer copy the OTP without touching the API by hand.
  const [devCode, setDevCode] = useState<string | null>(null)

  /**
   * Where to land after signing in. A `redirect` is honoured ONLY when it is a portal path on this
   * site — an absolute or protocol-relative URL here would turn the login page into an open redirect,
   * and the server would not be the one to catch it.
   */
  const requested = new URLSearchParams(useLocation().search).get('redirect') ?? ''
  const afterLogin = /^\/(portal|client)(\/|$)/.test(requested) && !requested.startsWith('//')
    ? requested
    : '/portal'

  const start = useMutation({
    mutationFn: () => portalLoginStart(channel, destination.trim()),
    onSuccess: (r) => { setVid(r.verification_id); setError(null); if (r.dev_code) { setCode(r.dev_code); setDevCode(r.dev_code) } },
    onError: (e) => setError(toApiError(e).message),
  })
  const verify = useMutation({
    mutationFn: () => portalLoginVerify(vid!, code),
    onSuccess: () => navigate(afterLogin),
    onError: (e) => setError(toApiError(e).message),
  })

  const field = 'h-11 w-full rounded-xl border border-border bg-surface px-3.5 text-sm outline-none focus:border-brand-500'

  return (
    <PortalShell title={ar ? 'بوابة العميل' : 'Client portal'}>
      <div className="mx-auto max-w-md">
        <div className="rounded-2xl border border-border bg-surface p-6">
          <h1 className="font-heading text-xl font-extrabold text-text-primary">{ar ? 'تسجيل الدخول' : 'Sign in'}</h1>
          <p className="mt-1 text-sm text-text-secondary">{ar ? 'سنرسل رمز تحقق إلى بريدك أو جوالك لمتابعة طلباتك.' : 'We’ll send a verification code to your email or phone to track your requests.'}</p>

          {!vid ? (
            <form className="mt-5 grid gap-3" onSubmit={(e) => { e.preventDefault(); if (destination.trim()) start.mutate() }}>
              <div className="flex gap-2">
                <button type="button" onClick={() => setChannel('email')} className={`flex flex-1 items-center justify-center gap-1.5 rounded-xl border px-3 py-2 text-sm font-semibold ${channel === 'email' ? 'border-brand-500 bg-brand-primary-soft text-brand-700' : 'border-border text-text-secondary'}`}><Mail size={15} /> {ar ? 'البريد' : 'Email'}</button>
                <button type="button" onClick={() => setChannel('sms')} className={`flex flex-1 items-center justify-center gap-1.5 rounded-xl border px-3 py-2 text-sm font-semibold ${channel === 'sms' ? 'border-brand-500 bg-brand-primary-soft text-brand-700' : 'border-border text-text-secondary'}`}><Phone size={15} /> {ar ? 'الجوال' : 'Phone'}</button>
              </div>
              <input className={field} dir="ltr" inputMode={channel === 'email' ? 'email' : 'tel'}
                placeholder={channel === 'email' ? 'you@example.com' : '+9665XXXXXXXX'}
                value={destination} onChange={(e) => setDestination(e.target.value)} aria-label={ar ? 'وسيلة التواصل' : 'Contact'} />
              <button type="submit" disabled={!destination.trim() || start.isPending} className="flex items-center justify-center gap-2 rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-60">
                {start.isPending ? <Loader2 size={16} className="animate-spin" /> : <LogIn size={16} />} {ar ? 'إرسال الرمز' : 'Send code'}
              </button>
            </form>
          ) : (
            <form className="mt-5 grid gap-3" onSubmit={(e) => { e.preventDefault(); if (code.length === 6) verify.mutate() }}>
              <label className="text-xs font-semibold text-text-secondary">{ar ? 'رمز التحقق (6 أرقام)' : 'Verification code (6 digits)'}
                <input className={`${field} tnum text-center tracking-[0.5em]`} dir="ltr" inputMode="numeric" maxLength={6}
                  value={code} onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))} aria-label={ar ? 'الرمز' : 'Code'} />
              </label>
              {devCode && (
                <div className="flex items-center justify-between gap-2 rounded-xl border border-warning/40 bg-warning/10 px-3 py-2 text-xs" dir="ltr">
                  <span className="font-semibold text-warning">DEV OTP (local only): <span className="tnum tracking-widest">{devCode}</span></span>
                  <button type="button" onClick={() => { void navigator.clipboard?.writeText(devCode); setCode(devCode) }}
                    className="rounded-lg border border-warning/50 px-2 py-1 font-semibold text-warning hover:bg-warning/15">{ar ? 'نسخ' : 'Copy'}</button>
                </div>
              )}
              <button type="submit" disabled={code.length !== 6 || verify.isPending} className="flex items-center justify-center gap-2 rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-60">
                {verify.isPending ? <Loader2 size={16} className="animate-spin" /> : <LogIn size={16} />} {ar ? 'دخول' : 'Sign in'}
              </button>
              <button type="button" onClick={() => { setVid(null); setCode('') }} className="text-xs font-semibold text-text-secondary hover:text-text-primary">{ar ? 'تغيير وسيلة التواصل' : 'Change contact'}</button>
            </form>
          )}

          {error && <p className="mt-3 rounded-lg bg-[var(--negative-background)] px-3 py-2 text-sm text-danger">{error}</p>}
        </div>
      </div>
    </PortalShell>
  )
}

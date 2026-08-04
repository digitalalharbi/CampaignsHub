import { useEffect, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { useMutation } from '@tanstack/react-query'
import { MailCheck, Loader2, RefreshCw, ShieldCheck } from 'lucide-react'
import { resendVerification, verifyEmail } from './api'
import { OnboardingShell } from './OnboardingShell'
import { fetchCurrentUser } from '@/features/auth/api'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'
import { AccessRecovery } from '@/features/auth/AccessRecovery'

export function VerifyEmailPage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const setUser = useAuth((s) => s.setUser)
  const email = useAuth((s) => s.user?.email)
  const [devLink, setDevLink] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)

  const token = params.get('token')

  const verify = useMutation({
    mutationFn: (t: string) => verifyEmail(t),
    /*
     * Move on FIRST, refresh the profile after.
     *
     * This used to await `fetchCurrentUser()` before navigating, which made the redirect wait on a
     * round trip that has nothing to do with whether verification worked — and since that probe
     * gained a retry, a contended backend could hold the page on /verify-email for three requests
     * while the user stared at a spinner. Verification has already succeeded by this point; there is
     * nothing to wait for.
     *
     * `if (u)` matters as much as the ordering: a refresh that fails must not call `setUser(null)`
     * and sign out someone who just proved their email.
     */
    onSuccess: () => {
      navigate('/onboarding', { replace: true })
      void fetchCurrentUser().then((u) => { if (u) setUser(u) })
    },
    onError: () => setError(ar ? 'رابط التحقق غير صالح أو منتهٍ. أعد الإرسال.' : 'Invalid or expired link. Please resend.'),
  })

  const resend = useMutation({
    mutationFn: resendVerification,
    onSuccess: (r) => { setDevLink(r.email_verification?.dev_link ?? null); setError(null) },
  })

  // Auto-verify when the page is opened from a verification link.
  useEffect(() => { if (token) verify.mutate(token) /* eslint-disable-next-line */ }, [token])
  // No token → fetch a fresh verification link once so the (dev-only) verify button is available.
  useEffect(() => { if (!token && email) resend.mutate() /* eslint-disable-next-line */ }, [token, email])

  if (token) {
    return (
      <OnboardingShell title={ar ? 'تأكيد البريد' : 'Verify email'}>
        <div className="flex flex-col items-center gap-3 py-8 text-center">
          {verify.isError ? <p className="text-sm text-danger">{error}</p> : <><Loader2 className="animate-spin text-brand-600" /><p className="text-sm text-text-secondary">{ar ? 'جارٍ التحقق…' : 'Verifying…'}</p></>}
        </div>
      </OnboardingShell>
    )
  }

  return (
    <OnboardingShell title={ar ? 'تأكيد البريد' : 'Verify email'}>
      <div className="flex flex-col items-center gap-4 text-center">
        <span className="flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-primary-soft text-brand-600"><MailCheck size={26} /></span>
        <div>
          <h1 className="font-heading text-xl font-extrabold text-text-primary">{ar ? 'أكّد بريدك الإلكتروني' : 'Verify your email'}</h1>
          <p className="mt-1 text-sm text-text-secondary">{ar ? 'أرسلنا رابط تأكيد إلى' : 'We sent a verification link to'} <span className="font-semibold text-text-primary" dir="ltr">{email}</span>.</p>
          <p className="mt-1 text-xs text-text-muted">{ar ? 'لم يُفعَّل مزوّد البريد بعد (بانتظار البيانات) — استخدم زر التطوير أدناه مؤقتًا.' : 'No mail provider is configured yet (awaiting credentials) — use the dev button below for now.'}</p>
        </div>

        {devLink && (
          <a href={devLink} className="flex items-center gap-1.5 rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-700"><ShieldCheck size={16} /> {ar ? 'تأكيد الآن (تطوير)' : 'Verify now (dev)'}</a>
        )}

        <button onClick={() => resend.mutate()} disabled={resend.isPending} className="flex items-center gap-1.5 rounded-xl border border-border px-4 py-2.5 text-sm font-semibold text-text-secondary hover:text-text-primary disabled:opacity-60">
          {resend.isPending ? <Loader2 size={15} className="animate-spin" /> : <RefreshCw size={15} />} {ar ? 'إعادة إرسال الرابط' : 'Resend link'}
        </button>
        {error && <p className="text-xs text-danger">{error}</p>}

        {/*
          ACCESS-EXIT-001 — verification is a wall too, for the person who cannot pass it.
          Somebody who signed in with the wrong account, or who no longer has that inbox, had no way
          off this page: no sign-out, no home, and the session kept them here on every return visit.
        */}
        <AccessRecovery />
      </div>
    </OnboardingShell>
  )
}

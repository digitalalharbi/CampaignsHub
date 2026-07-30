import { useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { useMutation, useQuery } from '@tanstack/react-query'
import { Loader2, UserPlus } from 'lucide-react'
import { acceptInvite, previewInvite } from './inviteApi'
import { OnboardingShell } from './OnboardingShell'
import { toApiError } from '@/lib/api/client'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'
import { resolvePostAuthDestination } from '@/features/auth/postAuthDestination'

/** Accept a workspace invitation: set name + password → join the existing workspace → dashboard. */
export function InviteAcceptPage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const setUser = useAuth((s) => s.setUser)
  const token = params.get('token') ?? ''
  const [name, setName] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState<string | null>(null)

  const preview = useQuery({ queryKey: ['invite', token], queryFn: () => previewInvite(token), enabled: !!token, retry: false })

  const accept = useMutation({
    mutationFn: () => acceptInvite(token, name, password),
    // Use the user returned by accept directly — no second /auth/me round-trip to race the session cookie.
    // ADR 0002: an invitation grants a membership, so the destination follows from it — an invited
    // agency client must not land in the advertiser dashboard.
    onSuccess: async (user) => {
      setUser(user)
      navigate(await resolvePostAuthDestination(new URLSearchParams()), { replace: true })
    },
    onError: (e) => setError(toApiError(e).message),
  })

  const field = 'h-11 w-full rounded-xl border border-border bg-surface px-3.5 text-sm outline-none focus:border-brand-500'

  if (!token || preview.isError) {
    return <OnboardingShell title={ar ? 'دعوة' : 'Invitation'}><p className="text-center text-sm text-danger">{ar ? 'رابط الدعوة غير صالح أو منتهٍ.' : 'This invitation link is invalid or expired.'}</p></OnboardingShell>
  }

  return (
    <OnboardingShell title={ar ? 'قبول الدعوة' : 'Accept invitation'}>
      <div className="flex flex-col items-center gap-2 text-center">
        <span className="flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-primary-soft text-brand-600"><UserPlus size={22} /></span>
        <h1 className="font-heading text-xl font-extrabold text-text-primary">{ar ? 'انضمّ إلى' : 'Join'} {preview.data?.workspace_name ?? '…'}</h1>
        <p className="text-sm text-text-secondary">{ar ? 'دُعيت للانضمام كـ' : 'You were invited as'} <span className="font-semibold text-text-primary">{preview.data?.role_slug}</span> · <span dir="ltr">{preview.data?.email}</span></p>
      </div>
      <form className="mt-5 grid gap-3" onSubmit={(e) => { e.preventDefault(); accept.mutate() }}>
        <label className="text-xs font-semibold text-text-secondary">{ar ? 'الاسم الكامل' : 'Full name'}<input className={field} value={name} onChange={(e) => setName(e.target.value)} required /></label>
        <label className="text-xs font-semibold text-text-secondary">{ar ? 'كلمة المرور' : 'Password'}<input type="password" className={field} value={password} onChange={(e) => setPassword(e.target.value)} autoComplete="new-password" required /></label>
        <button type="submit" disabled={name.trim().length < 2 || password.length < 8 || accept.isPending} className="flex items-center justify-center gap-2 rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-60">
          {accept.isPending ? <Loader2 size={16} className="animate-spin" /> : <UserPlus size={16} />} {ar ? 'الانضمام' : 'Join workspace'}
        </button>
        {error && <p className="text-xs text-danger">{error}</p>}
      </form>
    </OnboardingShell>
  )
}

import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useMutation } from '@tanstack/react-query'
import { Eye, EyeOff } from 'lucide-react'
import { register } from './api'
import { AuthField, AuthShell, authInputClass } from './AuthShell'
import { Button } from '@/components/ui/Button'
import { toApiError } from '@/lib/api/client'
import { useT } from '@/lib/i18n'
import { useAuth } from '@/stores/auth'

/** Real sign-up — provisions a tenant + owner via POST /auth/register, then drops into onboarding. */
export function RegisterPage() {
  const t = useT()
  const navigate = useNavigate()
  const setUser = useAuth((s) => s.setUser)
  const [form, setForm] = useState({ tenant_name: '', name: '', email: '', password: '', password_confirmation: '' })
  const [show, setShow] = useState(false)
  const set = (k: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement>) => setForm({ ...form, [k]: e.target.value })

  const mutation = useMutation({
    mutationFn: register,
    // Onboarding (module/account-type selection) lands in Phase 2; go to the app for now.
    onSuccess: (user) => { setUser(user); navigate('/', { replace: true }) },
  })
  const error = mutation.isError ? toApiError(mutation.error) : null
  const err = (k: string) => error?.errors?.[k]?.[0]

  return (
    <AuthShell>
      <h2 className="font-[var(--font-heading)] text-2xl font-extrabold text-text-primary">{t('create_account_title')}</h2>
      <p className="mt-1 text-sm text-text-secondary">{t('create_account_subtitle')}</p>

      <form className="mt-6 space-y-4" onSubmit={(e) => { e.preventDefault(); mutation.mutate(form) }}>
        <AuthField label={t('org_name')} error={err('tenant_name')}>
          <input value={form.tenant_name} onChange={set('tenant_name')} required className={authInputClass} />
        </AuthField>
        <AuthField label={t('full_name')} error={err('name')}>
          <input value={form.name} onChange={set('name')} autoComplete="name" required className={authInputClass} />
        </AuthField>
        <AuthField label={t('email')} error={err('email')}>
          <input type="email" value={form.email} onChange={set('email')} autoComplete="username" required className={authInputClass} />
        </AuthField>
        <AuthField label={t('password')} error={err('password')}>
          <div className="relative">
            <input type={show ? 'text' : 'password'} value={form.password} onChange={set('password')} autoComplete="new-password" required className={`${authInputClass} pe-11`} />
            <button type="button" onClick={() => setShow((v) => !v)} aria-label={t(show ? 'hide_password' : 'show_password')} className="absolute inset-y-0 end-2 my-auto flex h-8 w-8 items-center justify-center rounded-lg text-text-muted hover:bg-surface-hover">
              {show ? <EyeOff size={17} /> : <Eye size={17} />}
            </button>
          </div>
        </AuthField>
        <AuthField label={t('confirm_password')}>
          <input type={show ? 'text' : 'password'} value={form.password_confirmation} onChange={set('password_confirmation')} autoComplete="new-password" required className={authInputClass} />
        </AuthField>

        {error && !error.errors && <p className="rounded-xl bg-[var(--negative-background)] px-3 py-2.5 text-sm text-danger">{error.message}</p>}

        <Button type="submit" loading={mutation.isPending} className="w-full" size="lg">{t('create_account')}</Button>
      </form>

      <p className="mt-6 text-center text-sm text-text-secondary">
        {t('have_account')} <Link to="/login" className="font-semibold text-brand-600 hover:underline">{t('sign_in')}</Link>
      </p>
    </AuthShell>
  )
}

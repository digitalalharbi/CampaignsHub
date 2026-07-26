import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useMutation } from '@tanstack/react-query'
import { Eye, EyeOff } from 'lucide-react'
import { login } from './api'
import { AuthField, AuthShell, authInputClass } from './AuthShell'
import { Button } from '@/components/ui/Button'
import { toApiError } from '@/lib/api/client'
import { useT } from '@/lib/i18n'
import { useAuth } from '@/stores/auth'

export function LoginPage() {
  const t = useT()
  const navigate = useNavigate()
  const setUser = useAuth((s) => s.setUser)

  // Never pre-fill credentials outside local/demo dev.
  const [email, setEmail] = useState(import.meta.env.DEV ? 'owner@demo-agency.local' : '')
  const [password, setPassword] = useState(import.meta.env.DEV ? 'password' : '')
  const [showPassword, setShowPassword] = useState(false)
  const [remember, setRemember] = useState(true)

  const mutation = useMutation({
    mutationFn: login,
    onSuccess: (user) => { setUser(user); navigate('/', { replace: true }) },
  })
  const error = mutation.isError ? toApiError(mutation.error) : null

  return (
    <AuthShell>
      <h2 className="font-[var(--font-heading)] text-2xl font-extrabold text-text-primary">{t('welcome_back')}</h2>
      <p className="mt-1 text-sm text-text-secondary">{t('sign_in_subtitle')}</p>

      <form className="mt-6 space-y-4" onSubmit={(e) => { e.preventDefault(); mutation.mutate({ email, password }) }}>
        <AuthField label={t('email')} error={error?.errors?.email?.[0]}>
          <input type="email" value={email} onChange={(e) => setEmail(e.target.value)} autoComplete="username" required className={authInputClass} />
        </AuthField>

        <AuthField label={t('password')} error={error?.errors?.password?.[0]}>
          <div className="relative">
            <input type={showPassword ? 'text' : 'password'} value={password} onChange={(e) => setPassword(e.target.value)} autoComplete="current-password" required className={`${authInputClass} pe-11`} />
            <button type="button" onClick={() => setShowPassword((v) => !v)} aria-label={t(showPassword ? 'hide_password' : 'show_password')} className="absolute inset-y-0 end-2 my-auto flex h-8 w-8 items-center justify-center rounded-lg text-text-muted hover:bg-surface-hover">
              {showPassword ? <EyeOff size={17} /> : <Eye size={17} />}
            </button>
          </div>
        </AuthField>

        <div className="flex items-center justify-between">
          <label className="flex cursor-pointer items-center gap-2 text-sm text-text-secondary">
            <input type="checkbox" checked={remember} onChange={(e) => setRemember(e.target.checked)} className="h-4 w-4 rounded border-border accent-brand-600" />
            {t('remember_me')}
          </label>
          <Link to="/forgot-password" className="text-sm font-semibold text-brand-600 hover:underline">{t('forgot_password')}</Link>
        </div>

        {error && !error.errors && <p className="rounded-xl bg-[var(--negative-background)] px-3 py-2.5 text-sm text-danger">{error.message}</p>}

        <Button type="submit" loading={mutation.isPending} className="w-full" size="lg">{t('sign_in')}</Button>
      </form>

      <p className="mt-6 text-center text-sm text-text-secondary">
        {t('no_account')} <Link to="/register" className="font-semibold text-brand-600 hover:underline">{t('create_account')}</Link>
      </p>
      {import.meta.env.DEV && <p className="mt-2 text-center text-[11px] text-text-muted" dir="auto">بيانات تجريبية معبّأة للتطوير فقط</p>}
    </AuthShell>
  )
}

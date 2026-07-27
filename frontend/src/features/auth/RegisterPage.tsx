import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useMutation } from '@tanstack/react-query'
import { register } from './api'
import { AuthShell } from './AuthShell'
import { Button } from '@/components/ui/Button'
import { EmailInput, PasswordInput, TextInput } from '@/components/ui/form'
import { toApiError } from '@/lib/api/client'
import { useT } from '@/lib/i18n'
import { useAuth } from '@/stores/auth'

/** Real sign-up — provisions a tenant + owner via POST /auth/register, then drops into the app. */
export function RegisterPage() {
  const t = useT()
  const navigate = useNavigate()
  const setUser = useAuth((s) => s.setUser)
  const [form, setForm] = useState({ tenant_name: '', name: '', email: '', password: '', password_confirmation: '' })
  const set = (k: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement>) => setForm({ ...form, [k]: e.target.value })

  const mutation = useMutation({
    // Onboarding (module/account-type selection) lands in Phase 2; go to the app for now.
    mutationFn: register,
    onSuccess: (user) => { setUser(user); navigate('/dashboard', { replace: true }) },
  })
  const error = mutation.isError ? toApiError(mutation.error) : null
  const err = (k: string) => error?.errors?.[k]?.[0]

  return (
    <AuthShell>
      <h2 className="font-[var(--font-heading)] text-2xl font-extrabold text-text-primary">{t('create_account_title')}</h2>
      <p className="mt-1 text-[15px] text-text-secondary">{t('create_account_subtitle')}</p>

      <form className="mt-6 space-y-[18px]" onSubmit={(e) => { e.preventDefault(); mutation.mutate(form) }}>
        <TextInput label={t('org_name')} value={form.tenant_name} onChange={set('tenant_name')} required error={err('tenant_name')} />
        <TextInput label={t('full_name')} value={form.name} onChange={set('name')} autoComplete="name" required error={err('name')} />
        <EmailInput label={t('email')} value={form.email} onChange={set('email')} required error={err('email')} />
        <PasswordInput label={t('password')} value={form.password} onChange={set('password')} autoComplete="new-password" required error={err('password')} showLabel={t('show_password')} hideLabel={t('hide_password')} />
        <PasswordInput label={t('confirm_password')} value={form.password_confirmation} onChange={set('password_confirmation')} autoComplete="new-password" required showLabel={t('show_password')} hideLabel={t('hide_password')} />

        {error && !error.errors && <p className="rounded-xl bg-[var(--negative-background)] px-4 py-3 text-sm text-danger">{error.message}</p>}

        <Button type="submit" loading={mutation.isPending} className="w-full" size="lg">{t('create_account')}</Button>
      </form>

      <p className="mt-6 text-center text-sm text-text-secondary">
        {t('have_account')} <Link to="/login" className="font-semibold text-brand-600 hover:underline">{t('sign_in')}</Link>
      </p>
    </AuthShell>
  )
}

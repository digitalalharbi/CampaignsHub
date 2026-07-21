import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation } from '@tanstack/react-query'
import { login } from './api'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { toApiError } from '@/lib/api/client'
import { useT } from '@/lib/i18n'
import { useAuth } from '@/stores/auth'

export function LoginPage() {
  const t = useT()
  const navigate = useNavigate()
  const setUser = useAuth((s) => s.setUser)
  const [email, setEmail] = useState('owner@demo-agency.local')
  const [password, setPassword] = useState('password')

  const mutation = useMutation({
    mutationFn: login,
    onSuccess: (user) => {
      setUser(user)
      navigate('/', { replace: true })
    },
  })

  const error = mutation.isError ? toApiError(mutation.error) : null

  return (
    <div className="flex min-h-screen items-center justify-center bg-background p-4">
      <Card className="w-full max-w-[420px]">
        <div className="mb-5">
          <h1 className="font-[var(--font-heading)] text-lg font-extrabold text-brand-600">
            {t('app_name')}
          </h1>
          <p className="mt-1 text-sm font-bold text-text-primary">{t('welcome_back')}</p>
          <p className="text-[13px] text-text-secondary">{t('sign_in_subtitle')}</p>
        </div>

        <form
          className="space-y-3"
          onSubmit={(e) => {
            e.preventDefault()
            mutation.mutate({ email, password })
          }}
        >
          <Field label={t('email')} error={error?.errors?.email?.[0]}>
            <input
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              autoComplete="username"
              className="w-full rounded-[9px] border border-border bg-surface-secondary px-3 py-2.5 text-[13px] outline-none focus:border-brand-500 focus:bg-surface focus:ring-[3px] focus:ring-brand-500/15"
            />
          </Field>

          <Field label={t('password')}>
            <input
              type="password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              autoComplete="current-password"
              className="w-full rounded-[9px] border border-border bg-surface-secondary px-3 py-2.5 text-[13px] outline-none focus:border-brand-500 focus:bg-surface focus:ring-[3px] focus:ring-brand-500/15"
            />
          </Field>

          {error && !error.errors && (
            <p className="rounded-[9px] bg-[var(--negative-background)] px-3 py-2 text-[12px] text-danger">
              {error.message}
            </p>
          )}

          <Button type="submit" loading={mutation.isPending} className="w-full">
            {t('sign_in')}
          </Button>
        </form>
      </Card>
    </div>
  )
}

function Field({
  label,
  error,
  children,
}: {
  label: string
  error?: string
  children: React.ReactNode
}) {
  return (
    <label className="block">
      <span className="mb-1 block text-[12px] font-semibold text-text-secondary">{label}</span>
      {children}
      {error && <span className="mt-1 block text-[11px] text-danger">{error}</span>}
    </label>
  )
}

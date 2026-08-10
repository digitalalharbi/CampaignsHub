import { useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { Monitor, ShieldCheck } from 'lucide-react'
import { getSessions, logoutOtherSessions } from './api'
import { PhoneCredentialCard } from './PhoneCredential'
import { Button } from '@/components/ui/Button'
import { PasswordInput } from '@/components/ui/form'
import { toApiError } from '@/lib/api/client'
import { useT } from '@/lib/i18n'

export function SecurityPage() {
  const t = useT()
  const [password, setPassword] = useState('')
  const sessions = useQuery({ queryKey: ['me', 'sessions'], queryFn: getSessions })

  const revoke = useMutation({
    mutationFn: () => logoutOtherSessions(password),
    onSuccess: () => setPassword(''),
  })
  const error = revoke.isError ? toApiError(revoke.error) : null

  return (
    <div>
      <header className="mb-6">
        <h1 className="font-heading text-2xl font-extrabold text-text-primary">{t('settings_security_title')}</h1>
        <p className="mt-1 text-sm text-text-secondary">{t('settings_security_subtitle')}</p>
      </header>

      <div className="space-y-6">
        {/* Current session. */}
        <section className="rounded-2xl border border-border bg-surface p-5">
          <h2 className="mb-3 text-sm font-bold text-text-primary">{t('current_session')}</h2>
          {sessions.isLoading ? (
            <div className="h-16 animate-pulse rounded-lg bg-surface-secondary" />
          ) : sessions.data ? (
            <div className="flex items-center gap-3">
              <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-brand-100 text-brand-700"><Monitor size={18} /></div>
              <div className="text-sm">
                <div className="font-semibold text-text-primary">
                  {sessions.data.current.browser} · {sessions.data.current.platform}
                </div>
                <div className="text-text-muted" dir="ltr">{sessions.data.current.ip ?? '—'}</div>
              </div>
              <span className="ms-auto rounded-full bg-success/15 px-2 py-0.5 text-[11px] font-semibold text-success">{t('account_status_active')}</span>
            </div>
          ) : null}

          {/* Revoke other sessions — real, password-confirmed. */}
          <div className="mt-5 border-t border-border pt-4">
            <PasswordInput
              label={t('field_current_password')} value={password} onChange={(e) => setPassword(e.target.value)}
              autoComplete="current-password" showLabel={t('show_password')} hideLabel={t('hide_password')}
              error={error?.errors?.current_password?.[0]}
            />
            <div className="mt-3 flex items-center gap-3">
              <Button variant="secondary" onClick={() => revoke.mutate()} loading={revoke.isPending} disabled={!password}>
                {t('revoke_other_sessions')}
              </Button>
              {revoke.isSuccess && <span className="text-sm font-semibold text-success">{t('saved_successfully')}</span>}
            </div>
          </div>
        </section>

        {/*
          The mobile number as a credential — AUTH-PHONE-001.

          Here rather than in workspace settings because it is personal: `/me/phone` is self-only,
          and the number decides how THIS person signs in and how their account is recovered.
        */}
        <PhoneCredentialCard />

        {/* Two-factor — honestly gated until the external verification/mailer is wired. */}
        <section className="rounded-2xl border border-border bg-surface p-5">
          <div className="flex items-center gap-3">
            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-surface-secondary text-text-muted"><ShieldCheck size={18} /></div>
            <div>
              <h2 className="text-sm font-bold text-text-primary">{t('two_factor_auth')}</h2>
              <p className="text-xs text-text-muted">{t('awaiting_external_dependency')}</p>
            </div>
            <span className="ms-auto rounded-full bg-warning/15 px-2.5 py-1 text-[11px] font-semibold text-warning">{t('awaiting_external_dependency')}</span>
          </div>
        </section>
      </div>
    </div>
  )
}

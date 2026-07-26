import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation } from '@tanstack/react-query'
import { ArrowRight, MailCheck } from 'lucide-react'
import { requestPasswordReset } from './api'
import { AuthField, AuthShell, authInputClass } from './AuthShell'
import { Button } from '@/components/ui/Button'
import { toApiError } from '@/lib/api/client'
import { useT } from '@/lib/i18n'

/**
 * Forgot-password request. Always shows the same generic success (no account enumeration). Actual
 * email delivery depends on mail credentials — until configured the endpoint records the request and
 * returns success (documented as Awaiting Credentials), so the flow is real end-to-end in the UI.
 */
export function ForgotPasswordPage() {
  const t = useT()
  const [email, setEmail] = useState('')
  const mutation = useMutation({ mutationFn: requestPasswordReset })
  const error = mutation.isError ? toApiError(mutation.error) : null

  return (
    <AuthShell>
      {mutation.isSuccess ? (
        <div className="text-center">
          <div className="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-success/15 text-success"><MailCheck size={26} /></div>
          <h2 className="font-[var(--font-heading)] text-2xl font-extrabold text-text-primary">{t('reset_sent_title')}</h2>
          <p className="mt-2 text-sm text-text-secondary">{t('reset_sent_body')}</p>
          <Link to="/login" className="mt-6 inline-flex items-center gap-1 text-sm font-semibold text-brand-600 hover:underline">
            <ArrowRight size={14} className="rtl:rotate-180" /> {t('back_to_login')}
          </Link>
        </div>
      ) : (
        <>
          <h2 className="font-[var(--font-heading)] text-2xl font-extrabold text-text-primary">{t('forgot_title')}</h2>
          <p className="mt-1 text-sm text-text-secondary">{t('forgot_subtitle')}</p>

          <form className="mt-6 space-y-4" onSubmit={(e) => { e.preventDefault(); mutation.mutate({ email }) }}>
            <AuthField label={t('email')} error={error?.errors?.email?.[0]}>
              <input type="email" value={email} onChange={(e) => setEmail(e.target.value)} autoComplete="username" required className={authInputClass} />
            </AuthField>
            {error && !error.errors && <p className="rounded-xl bg-[var(--negative-background)] px-3 py-2.5 text-sm text-danger">{error.message}</p>}
            <Button type="submit" loading={mutation.isPending} className="w-full" size="lg">{t('send_reset_link')}</Button>
          </form>

          <Link to="/login" className="mt-6 inline-flex items-center gap-1 text-sm font-semibold text-brand-600 hover:underline">
            <ArrowRight size={14} className="rtl:rotate-180" /> {t('back_to_login')}
          </Link>
        </>
      )}
    </AuthShell>
  )
}

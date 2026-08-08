import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useMutation } from '@tanstack/react-query'
import { ArrowRight, ShieldCheck } from 'lucide-react'
import { resetPassword } from './api'
import { AuthShell } from './AuthShell'
import { Button } from '@/components/ui/Button'
import { PasswordInput } from '@/components/ui/form'
import { toApiError } from '@/lib/api/client'
import { useT } from '@/lib/i18n'

/**
 * The other half of the reset — MAIL-009.
 *
 * `/forgot-password` has existed for months and led nowhere: no token was issued and no page consumed
 * one, so «check your email» pointed at an email that was never sent and a link that had nothing to
 * open. This is the page that link opens.
 *
 * ## The token stays in the URL and never in state we send anywhere else
 *
 * It arrives as a query parameter because that is the only way a link can carry it, and it is read
 * once, here, and posted straight back. It is deliberately not stored, not logged, and not put in the
 * page title — a reset token in a document title ends up in a screenshot or a browser history entry.
 *
 * ## The confirmation is checked in the browser AND on the server
 *
 * `confirmed` on the server is the rule; the check below is only so somebody who mistypes the second
 * field learns it without a round trip. It is not a substitute — a browser check is a courtesy, and
 * treating one as a control is how a validation rule quietly stops being enforced.
 */
export function ResetPasswordPage() {
  const t = useT()
  const [params] = useSearchParams()
  const token = params.get('token') ?? ''
  const email = params.get('email') ?? ''

  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [mismatch, setMismatch] = useState(false)

  const mutation = useMutation({ mutationFn: resetPassword })
  const error = mutation.isError ? toApiError(mutation.error) : null

  /*
   * A link missing either half cannot work, and saying so beats letting somebody type a password
   * into a form whose submit is already doomed. The server would refuse it either way; this refuses
   * it before the work.
   */
  const linkIncomplete = token === '' || email === ''

  return (
    <AuthShell>
      {mutation.isSuccess ? (
        <div className="text-center">
          <div className="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-success/15 text-success"><ShieldCheck size={26} /></div>
          <h2 className="font-[var(--font-heading)] text-2xl font-extrabold text-text-primary">{t('reset_done_title')}</h2>
          <p className="mt-2 text-[15px] text-text-secondary">{t('reset_done_body')}</p>
          <Link to="/login" className="mt-6 inline-flex items-center gap-1 text-sm font-semibold text-brand-600 hover:underline">
            <ArrowRight size={14} className="rtl:rotate-180" /> {t('back_to_login')}
          </Link>
        </div>
      ) : (
        <>
          <h2 className="font-[var(--font-heading)] text-2xl font-extrabold text-text-primary">{t('reset_title')}</h2>
          <p className="mt-1 text-[15px] text-text-secondary">{t('reset_subtitle')}</p>

          {linkIncomplete ? (
            <p className="mt-6 rounded-xl bg-[var(--negative-background)] px-4 py-3 text-sm text-danger">{t('reset_link_missing')}</p>
          ) : (
            <form
              className="mt-6 space-y-[18px]"
              onSubmit={(e) => {
                e.preventDefault()
                if (password !== confirmation) {
                  setMismatch(true)
                  return
                }
                setMismatch(false)
                mutation.mutate({ email, token, password, password_confirmation: confirmation })
              }}
            >
              <PasswordInput
                label={t('new_password')}
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                required
                minLength={8}
                autoComplete="new-password"
                error={error?.errors?.password?.[0]}
              />
              <PasswordInput
                label={t('confirm_password')}
                value={confirmation}
                onChange={(e) => setConfirmation(e.target.value)}
                required
                minLength={8}
                autoComplete="new-password"
                error={mismatch ? t('passwords_do_not_match') : undefined}
              />
              {/*
                The token error is shown as a page-level message rather than beside a field.

                It is not about anything the person typed — the link is stale or wrong — and putting
                it under the password box reads as «your password is invalid», which sends them to
                change what they chose instead of asking for a new link.
              */}
              {error?.errors?.token?.[0] && (
                <p className="rounded-xl bg-[var(--negative-background)] px-4 py-3 text-sm text-danger">{error.errors.token[0]}</p>
              )}
              {error && !error.errors && <p className="rounded-xl bg-[var(--negative-background)] px-4 py-3 text-sm text-danger">{error.message}</p>}
              <Button type="submit" loading={mutation.isPending} className="w-full" size="lg">{t('save_new_password')}</Button>
            </form>
          )}

          <Link to="/login" className="mt-6 inline-flex items-center gap-1 text-sm font-semibold text-brand-600 hover:underline">
            <ArrowRight size={14} className="rtl:rotate-180" /> {t('back_to_login')}
          </Link>
        </>
      )}
    </AuthShell>
  )
}

import { useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { useMutation } from '@tanstack/react-query'
import { Check, Copy } from 'lucide-react'
import { login } from './api'
import { safeRedirect } from './redirect'
import { AuthShell } from './AuthShell'
import { Button } from '@/components/ui/Button'
import { EmailInput, PasswordInput } from '@/components/ui/form'
import { toApiError } from '@/lib/api/client'
import { useT } from '@/lib/i18n'
import { useAuth } from '@/stores/auth'

const DEMO = { email: 'owner@demo-agency.local', password: 'password' }

/**
 * Dev-only demo credentials. Never auto-fills the fields and never renders in production — it just
 * offers copy buttons so a developer can paste them deliberately.
 */
function DemoCredentials() {
  const [copied, setCopied] = useState<'email' | 'password' | null>(null)
  const copy = (which: 'email' | 'password') => {
    void navigator.clipboard?.writeText(DEMO[which])
    setCopied(which)
    window.setTimeout(() => setCopied((c) => (c === which ? null : c)), 1500)
  }
  const Row = ({ which, value }: { which: 'email' | 'password'; value: string }) => (
    <div className="flex items-center justify-between gap-2 rounded-lg bg-surface px-3 py-2">
      <code className="truncate font-mono text-xs text-text-secondary" dir="ltr">{value}</code>
      <button
        type="button" onClick={() => copy(which)}
        className="flex shrink-0 items-center gap-1 rounded-md px-2 py-1 text-xs font-semibold text-brand-600 hover:bg-brand-primary-soft"
        aria-label={which === 'email' ? 'نسخ البريد' : 'نسخ كلمة المرور'}
      >
        {copied === which ? <Check size={13} /> : <Copy size={13} />}
        {copied === which ? 'تم النسخ' : 'نسخ'}
      </button>
    </div>
  )
  return (
    <div className="mt-5 rounded-xl border border-dashed border-border bg-surface-secondary p-3">
      <p className="mb-2 text-xs font-semibold text-text-muted">بيانات الحساب التجريبي · بيئة التطوير فقط</p>
      <div className="space-y-1.5">
        <Row which="email" value={DEMO.email} />
        <Row which="password" value={DEMO.password} />
      </div>
    </div>
  )
}

export function LoginPage() {
  const t = useT()
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const setUser = useAuth((s) => s.setUser)

  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [remember, setRemember] = useState(true)

  const mutation = useMutation({
    mutationFn: login,
    onSuccess: (user) => { setUser(user); navigate(safeRedirect(params.get('redirect')), { replace: true }) },
  })
  const error = mutation.isError ? toApiError(mutation.error) : null

  return (
    <AuthShell>
      <h2 className="font-[var(--font-heading)] text-3xl font-extrabold text-text-primary sm:text-[40px] sm:leading-tight">{t('welcome_back')}</h2>
      <p className="mt-2 text-[17px] text-text-secondary">{t('sign_in_subtitle')}</p>

      <form className="mt-8 space-y-5" onSubmit={(e) => { e.preventDefault(); mutation.mutate({ email, password }) }}>
        <EmailInput label={t('email')} value={email} onChange={(e) => setEmail(e.target.value)} required error={error?.errors?.email?.[0]} />
        <PasswordInput
          label={t('password')} value={password} onChange={(e) => setPassword(e.target.value)} autoComplete="current-password" required
          error={error?.errors?.password?.[0]} showLabel={t('show_password')} hideLabel={t('hide_password')}
        />

        <div className="flex items-center justify-between">
          <label className="flex cursor-pointer items-center gap-2 text-sm text-text-secondary">
            <input type="checkbox" checked={remember} onChange={(e) => setRemember(e.target.checked)} className="h-4 w-4 rounded border-border accent-brand-600" />
            {t('remember_me')}
          </label>
          <Link to="/forgot-password" className="text-sm font-semibold text-brand-600 hover:underline">{t('forgot_password')}</Link>
        </div>

        {error && !error.errors && <p className="rounded-xl bg-[var(--negative-background)] px-4 py-3 text-sm text-danger">{error.message}</p>}

        <Button type="submit" loading={mutation.isPending} className="w-full" size="lg">{t('sign_in')}</Button>
      </form>

      <p className="mt-6 text-center text-sm text-text-secondary">
        {t('no_account')} <Link to="/register" className="font-semibold text-brand-600 hover:underline">{t('create_account')}</Link>
      </p>

      {/* Demo credentials — dev only, BELOW the form, separate card with copy buttons, never auto-filled. */}
      {import.meta.env.DEV && <DemoCredentials />}
    </AuthShell>
  )
}

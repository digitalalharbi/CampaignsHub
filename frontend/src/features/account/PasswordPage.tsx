import { useMemo, useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { CheckCircle2 } from 'lucide-react'
import { updatePassword } from './api'
import { Button } from '@/components/ui/Button'
import { FormActions, FormSection, PasswordInput } from '@/components/ui/form'
import { toApiError } from '@/lib/api/client'
import { useT, type TranslationKey } from '@/lib/i18n'

/** 0–4 heuristic password strength (length + character classes). */
function scorePassword(pw: string): number {
  if (!pw) return 0
  let s = 0
  if (pw.length >= 8) s++
  if (pw.length >= 12) s++
  if (/[a-z]/.test(pw) && /[A-Z]/.test(pw)) s++
  if (/\d/.test(pw) && /[^A-Za-z0-9]/.test(pw)) s++
  return Math.min(s, 4)
}

export function PasswordPage() {
  const t = useT()
  const [form, setForm] = useState({ current_password: '', password: '', password_confirmation: '', logout_other_devices: false })
  const set = (k: keyof typeof form) => (v: string | boolean) => setForm((f) => ({ ...f, [k]: v }))

  const strength = useMemo(() => scorePassword(form.password), [form.password])
  const strengthLabel = (['strength_weak', 'strength_weak', 'strength_fair', 'strength_good', 'strength_strong'] as const)[strength] satisfies TranslationKey
  const strengthColor = ['bg-border', 'bg-danger', 'bg-warning', 'bg-brand-400', 'bg-success'][strength]

  const mutation = useMutation({
    mutationFn: updatePassword,
    onSuccess: () => setForm({ current_password: '', password: '', password_confirmation: '', logout_other_devices: false }),
  })
  const error = mutation.isError ? toApiError(mutation.error) : null
  const err = (k: string) => error?.errors?.[k]?.[0]

  return (
    <div>
      <header className="mb-6">
        <h1 className="font-heading text-2xl font-extrabold text-text-primary">{t('settings_password_title')}</h1>
        <p className="mt-1 text-sm text-text-secondary">{t('settings_password_subtitle')}</p>
      </header>

      <form className="max-w-lg space-y-8" onSubmit={(e) => { e.preventDefault(); mutation.mutate(form) }}>
        <FormSection>
          <PasswordInput
            label={t('field_current_password')} value={form.current_password} autoComplete="current-password"
            onChange={(e) => set('current_password')(e.target.value)} required error={err('current_password')}
            showLabel={t('show_password')} hideLabel={t('hide_password')}
          />
          <div>
            <PasswordInput
              label={t('field_new_password')} value={form.password} autoComplete="new-password"
              onChange={(e) => set('password')(e.target.value)} required error={err('password')}
              showLabel={t('show_password')} hideLabel={t('hide_password')}
            />
            {form.password && (
              <div className="mt-2 flex items-center gap-2">
                <div className="flex h-1.5 flex-1 gap-1">
                  {[1, 2, 3, 4].map((i) => (
                    <span key={i} className={`h-full flex-1 rounded-full ${i <= strength ? strengthColor : 'bg-border'}`} />
                  ))}
                </div>
                <span className="text-xs font-medium text-text-muted">{t('password_strength')}: {t(strengthLabel)}</span>
              </div>
            )}
          </div>
          <PasswordInput
            label={t('field_confirm_new_password')} value={form.password_confirmation} autoComplete="new-password"
            onChange={(e) => set('password_confirmation')(e.target.value)} required
            showLabel={t('show_password')} hideLabel={t('hide_password')}
          />
          <label className="flex cursor-pointer items-center gap-2 text-sm text-text-secondary">
            <input type="checkbox" checked={form.logout_other_devices} onChange={(e) => set('logout_other_devices')(e.target.checked)} className="h-4 w-4 rounded border-border accent-brand-600" />
            {t('logout_other_devices')}
          </label>
        </FormSection>

        {error && !error.errors && <p className="rounded-xl bg-[var(--negative-background)] px-4 py-3 text-sm text-danger">{error.message}</p>}

        <FormActions align="between">
          {mutation.isSuccess ? (
            <span className="flex items-center gap-1.5 text-sm font-semibold text-success"><CheckCircle2 size={16} /> {t('saved_successfully')}</span>
          ) : <span />}
          <Button type="submit" loading={mutation.isPending} size="lg">{t('save_changes')}</Button>
        </FormActions>
      </form>
    </div>
  )
}

import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { CheckCircle2 } from 'lucide-react'
import { updateProfile, type ProfileInput } from './api'
import { Button } from '@/components/ui/Button'
import { FormActions, FormSection, TextInput, TextareaField } from '@/components/ui/form'
import { FormField } from '@/components/ui/form'
import { controlClass } from '@/components/ui/Field'
import { toApiError } from '@/lib/api/client'
import { useT } from '@/lib/i18n'
import { useAuth } from '@/stores/auth'

const TIMEZONES = ['Asia/Riyadh', 'Asia/Dubai', 'Africa/Cairo', 'Europe/London', 'America/New_York', 'UTC']

export function ProfilePage() {
  const t = useT()
  const { user, setUser } = useAuth()
  const [form, setForm] = useState<ProfileInput>({
    name: user?.name ?? '',
    first_name: user?.first_name ?? '',
    last_name: user?.last_name ?? '',
    job_title: user?.job_title ?? '',
    phone: user?.phone ?? '',
    bio: user?.bio ?? '',
    locale: user?.locale ?? 'ar',
    timezone: user?.timezone ?? 'Asia/Riyadh',
    number_format: user?.number_format ?? 'latin',
  })
  const set = <K extends keyof ProfileInput>(k: K) => (v: ProfileInput[K]) => setForm((f) => ({ ...f, [k]: v }))

  const mutation = useMutation({
    mutationFn: updateProfile,
    // Reflect the new display name in the topbar, sidebar and user menu immediately.
    onSuccess: (updated) => setUser(updated),
  })
  const error = mutation.isError ? toApiError(mutation.error) : null
  const err = (k: string) => error?.errors?.[k]?.[0]

  return (
    <div>
      <header className="mb-6">
        <h1 className="font-heading text-2xl font-extrabold text-text-primary">{t('settings_profile_title')}</h1>
        <p className="mt-1 text-sm text-text-secondary">{t('settings_profile_subtitle')}</p>
      </header>

      <form
        className="space-y-8"
        onSubmit={(e) => { e.preventDefault(); mutation.mutate(form) }}
      >
        <FormSection>
          <TextInput label={t('field_display_name')} value={form.name} onChange={(e) => set('name')(e.target.value)} required error={err('name')} />
          <div className="grid gap-4 sm:grid-cols-2">
            <TextInput label={t('field_first_name')} value={form.first_name ?? ''} onChange={(e) => set('first_name')(e.target.value)} error={err('first_name')} />
            <TextInput label={t('field_last_name')} value={form.last_name ?? ''} onChange={(e) => set('last_name')(e.target.value)} error={err('last_name')} />
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <TextInput label={t('field_job_title')} value={form.job_title ?? ''} onChange={(e) => set('job_title')(e.target.value)} error={err('job_title')} />
            <TextInput label={t('field_phone')} value={form.phone ?? ''} onChange={(e) => set('phone')(e.target.value)} inputMode="tel" dir="ltr" error={err('phone')} />
          </div>
          <TextareaField label={t('field_bio')} value={form.bio ?? ''} onChange={(e) => set('bio')(e.target.value)} maxLength={500} error={err('bio')} />
        </FormSection>

        <FormSection>
          <div className="grid gap-4 sm:grid-cols-2">
            <FormField label={t('field_language')}>
              <select className={controlClass} value={form.locale} onChange={(e) => set('locale')(e.target.value as 'ar' | 'en')}>
                <option value="ar">العربية</option>
                <option value="en">English</option>
              </select>
            </FormField>
            <FormField label={t('field_timezone')}>
              <select className={controlClass} value={form.timezone} onChange={(e) => set('timezone')(e.target.value)}>
                {TIMEZONES.map((tz) => <option key={tz} value={tz}>{tz}</option>)}
              </select>
            </FormField>
          </div>
          <FormField label={t('field_number_format')}>
            <select className={controlClass} value={form.number_format} onChange={(e) => set('number_format')(e.target.value as 'latin' | 'arabic')}>
              <option value="latin">{t('number_format_latin')}</option>
              <option value="arabic">{t('number_format_arabic')}</option>
            </select>
          </FormField>
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

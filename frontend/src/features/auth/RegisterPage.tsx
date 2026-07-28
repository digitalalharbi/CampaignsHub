import { useMemo, useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { useMutation } from '@tanstack/react-query'
import { Building2, Check, LayoutDashboard, Users } from 'lucide-react'
import { register } from './api'
import { AuthShell } from './AuthShell'
import { Button } from '@/components/ui/Button'
import { EmailInput, FormField, PasswordInput, TextInput } from '@/components/ui/form'
import { controlClass } from '@/components/ui/Field'
import { toApiError } from '@/lib/api/client'
import { useT } from '@/lib/i18n'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'

/**
 * Journey handoff from the public homepage decision section. `?journey=self-managed|agency` presets the
 * onboarding so the visitor does NOT re-pick a path — the chosen path is shown here and stays editable.
 * The selection is carried forward via navigation state (and stays in the URL) so it reaches onboarding.
 */
type Journey = 'self-managed' | 'agency'
type SelfAccountType = 'freelancer' | 'brand' | 'in_house_team'

function parseJourney(raw: string | null): Journey | null {
  return raw === 'self-managed' || raw === 'agency' ? raw : null
}

/** Bilingual copy for the journey panel — kept local so the shared i18n dictionary is untouched. */
const JOURNEY_COPY = {
  ar: {
    heading: 'مسارك المختار',
    editable: 'يمكنك تعديله قبل المتابعة.',
    selfManaged: 'أدير حملاتي بنفسي',
    agency: 'أدير حملات عدة عملاء',
    accountTypeLabel: 'نوع الحساب',
    accountTypes: { freelancer: 'مستقل', brand: 'علامة تجارية', in_house_team: 'فريق تسويق داخلي' } as Record<SelfAccountType, string>,
    selfSummary: 'وحدة الإعلانات المدفوعة مفعّلة افتراضيًا.',
    agencyType: 'حساب وكالة',
    agencySummary: 'إدارة العملاء والطلبات مفعّلة لمساحة الوكالة.',
  },
  en: {
    heading: 'Your selected path',
    editable: 'You can adjust it before continuing.',
    selfManaged: 'I run my own campaigns',
    agency: "I manage several clients' campaigns",
    accountTypeLabel: 'Account type',
    accountTypes: { freelancer: 'Freelancer', brand: 'Brand', in_house_team: 'In-house team' } as Record<SelfAccountType, string>,
    selfSummary: 'Paid Advertising module enabled by default.',
    agencyType: 'Agency account',
    agencySummary: 'Clients and requests enabled for the agency workspace.',
  },
} as const

/** Real sign-up — provisions a tenant + owner via POST /auth/register, then drops into the app. */
export function RegisterPage() {
  const t = useT()
  const { locale } = useUi()
  const ar = locale === 'ar'
  const jc = JOURNEY_COPY[ar ? 'ar' : 'en']
  const navigate = useNavigate()
  const setUser = useAuth((s) => s.setUser)
  const [params, setParams] = useSearchParams()

  const journey = parseJourney(params.get('journey'))
  const [selfType, setSelfType] = useState<SelfAccountType>('freelancer')

  const [form, setForm] = useState({ tenant_name: '', name: '', email: '', password: '', password_confirmation: '' })
  const set = (k: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement>) => setForm({ ...form, [k]: e.target.value })

  // The onboarding intent presets carried into the app so the user never re-selects their path.
  const onboarding = useMemo(() => {
    if (journey === 'agency') return { journey, account_type: 'agency', modules: ['clients', 'requests'] }
    if (journey === 'self-managed') return { journey, account_type: selfType, modules: ['paid_media'] }
    return null
  }, [journey, selfType])

  // Switch path in place — persisted to the query string so a refresh (or the submit) keeps it.
  const pickJourney = (next: Journey) => {
    const p = new URLSearchParams(params)
    p.set('journey', next)
    setParams(p, { replace: true })
  }

  const mutation = useMutation({
    // Onboarding (module/account-type selection) lands in Phase 2; carry the preset via router state for now.
    mutationFn: register,
    onSuccess: (user) => { setUser(user); navigate('/verify-email', { replace: true, state: onboarding ? { onboarding } : undefined }) },
  })
  const error = mutation.isError ? toApiError(mutation.error) : null
  const err = (k: string) => error?.errors?.[k]?.[0]

  return (
    <AuthShell>
      <h2 className="font-[var(--font-heading)] text-2xl font-extrabold text-text-primary">{t('create_account_title')}</h2>
      <p className="mt-1 text-[15px] text-text-secondary">{t('create_account_subtitle')}</p>

      {/* Journey preset — only when arriving from a decision-section card. Editable, not a forced re-pick. */}
      {journey && (
        <section className="mt-5 rounded-2xl border border-border bg-surface-secondary p-4" aria-label={jc.heading}>
          <div className="flex items-center justify-between">
            <span className="text-sm font-bold text-text-primary">{jc.heading}</span>
            <span className="text-xs text-text-muted">{jc.editable}</span>
          </div>
          <div className="mt-3 grid gap-2 sm:grid-cols-2">
            {([
              { key: 'self-managed' as const, label: jc.selfManaged, Icon: LayoutDashboard },
              { key: 'agency' as const, label: jc.agency, Icon: Users },
            ]).map(({ key, label, Icon }) => {
              const on = journey === key
              return (
                <button
                  key={key} type="button" onClick={() => pickJourney(key)} aria-pressed={on}
                  className={`flex items-center gap-2.5 rounded-xl border px-3.5 py-3 text-start text-sm font-semibold transition-colors ${on ? 'border-brand-500 bg-brand-primary-soft text-brand-700' : 'border-border bg-surface text-text-secondary hover:border-brand-400'}`}
                >
                  <Icon size={17} className="shrink-0" /> <span className="min-w-0 flex-1">{label}</span>
                  {on && <Check size={16} className="shrink-0 text-brand-600" />}
                </button>
              )
            })}
          </div>

          {journey === 'self-managed' ? (
            <div className="mt-3">
              <FormField label={jc.accountTypeLabel} hint={jc.selfSummary}>
                <select className={controlClass} value={selfType} onChange={(e) => setSelfType(e.target.value as SelfAccountType)}>
                  {(['freelancer', 'brand', 'in_house_team'] as SelfAccountType[]).map((k) => (
                    <option key={k} value={k}>{jc.accountTypes[k]}</option>
                  ))}
                </select>
              </FormField>
            </div>
          ) : (
            <div className="mt-3 flex items-center gap-2 rounded-xl border border-border bg-surface px-3.5 py-3 text-sm">
              <Building2 size={17} className="shrink-0 text-brand-600" />
              <span className="font-semibold text-text-primary">{jc.agencyType}</span>
              <span className="text-text-muted">— {jc.agencySummary}</span>
            </div>
          )}
        </section>
      )}

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

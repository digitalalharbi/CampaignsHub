import { useMemo, useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { useMutation } from '@tanstack/react-query'
import { Building2, Check, LayoutDashboard, Users } from 'lucide-react'
import { AuthShell } from './AuthShell'
import { apply, rememberRegistration, type BillingInterval } from '@/features/signup/api'
import { PlanChooser } from '@/features/signup/PlanChooser'
import { Button } from '@/components/ui/Button'
import { EmailInput, PasswordInput, TextInput } from '@/components/ui/form'
import { controlClass } from '@/components/ui/Field'
import { ErrorSummary, useFormDraft, type FieldError } from '@/components/forms'
import { toApiError } from '@/lib/api/client'
import { useT } from '@/lib/i18n'
import { useUi } from '@/stores/ui'
import { features } from '@/lib/features'

/**
 * Journey handoff from the public homepage decision section. `?journey=self-service|multi-client` presets the
 * onboarding so the visitor does NOT re-pick a path — the chosen path is shown here and stays editable.
 * The selection is carried forward via navigation state (and stays in the URL) so it reaches onboarding.
 */
type Journey = 'self-service' | 'multi-client'
type SelfAccountType = 'freelancer' | 'brand' | 'in_house_team'

function parseJourney(raw: string | null): Journey | null {
  return raw === 'self-service' || raw === 'multi-client' ? raw : null
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
    agencyType: 'Agency account',
    agencySummary: 'Clients and requests enabled for the agency workspace.',
  },
} as const

/** Error-summary title + service read-out copy — kept local so the shared i18n dictionary is untouched. */
const REG_COPY = {
  ar: {
    errTitle: 'يرجى تصحيح الأخطاء التالية',
    moduleLabel: 'الخدمة المطلوبة',
    stepAccount: 'بيانات الحساب',
    stepPlan: 'الباقة',
    next: 'التالي',
    back: 'رجوع',
    planOptional: 'يمكنك المتابعة دون اختيار باقة الآن وتحديدها لاحقًا قبل التفعيل.',
  },
  en: {
    errTitle: 'Please fix the following errors',
    moduleLabel: 'Selected service',
    stepAccount: 'Your account',
    stepPlan: 'Plan',
    next: 'Continue',
    back: 'Back',
    planOptional: 'You can continue without choosing a plan and pick one later, before activation.',
  },
} as const

/**
 * The `module` handed over by the public site is a slug. Visitors read this page, so it is shown as the
 * service name they recognise — a raw `paid-media` on a public page is internal vocabulary leaking out.
 */
const SERVICE_LABELS: Record<string, { ar: string; en: string }> = {
  paid_media: { ar: 'إدارة الحملات الإعلانية المدفوعة', en: 'Paid advertising management' },
  influencer_marketing: { ar: 'حملات المؤثرين والمحتوى', en: 'Influencer & content campaigns' },
  combined: { ar: 'الخدمتان معًا', en: 'Both services' },
}

/**
 * Real sign-up — opens an APPLICATION via POST /auth/register (SIGNUP-002).
 *
 * It used to provision a tenant and an owner and drop straight into the app. It no longer can: what
 * comes back is a registration that may still owe verification, a review or a payment, so the form
 * hands over to the status page instead of to a workspace that might not exist.
 */
export function RegisterPage() {
  const t = useT()
  const { locale } = useUi()
  const ar = locale === 'ar'
  const jc = JOURNEY_COPY[ar ? 'ar' : 'en']
  const rc = REG_COPY[ar ? 'ar' : 'en']
  const navigate = useNavigate()
  const [params, setParams] = useSearchParams()

  const journey = parseJourney(params.get('journey'))
  // Carried through unchanged from the decision-section handoff; surfaced read-only, never a forced re-pick.
  const moduleParam = params.get('module')
  const [selfType, setSelfType] = useState<SelfAccountType>('freelancer')
  /*
   * Two steps, because one screen cannot hold both (PLAN-001e).
   *
   * The details and the plan are separate questions, and trying to ask them together is what broke
   * `e2e/auth-redesign.spec.ts` — this page must fit a 1366x768 desktop without scrolling and keep
   * its submit reachable at 1024x768, and any plan control large enough to read pushed it past both.
   * Shrinking the control until it fitted would have been answering a layout budget with an
   * unreadable choice; splitting the form answers it with room to spare on each step.
   *
   * The step lives in React state rather than the URL: a half-filled form is not a place worth
   * linking to, and putting it in history would make Back inside the form behave like Back out of it.
   */
  const [step, setStep] = useState<1 | 2>(1)
  const [planCode, setPlanCode] = useState<string | null>(null)
  const [interval, setInterval] = useState<BillingInterval>('monthly')

  // Non-secret fields autosave as a draft (survives refresh); passwords are kept in memory only, never persisted.
  const draft = useFormDraft('register', { tenant_name: '', name: '', email: '' })
  const [secret, setSecret] = useState({ password: '', password_confirmation: '' })
  const form = { ...draft.value, ...secret }
  const setDraft = (k: 'tenant_name' | 'name' | 'email') => (e: React.ChangeEvent<HTMLInputElement>) =>
    draft.setValue((f) => ({ ...f, [k]: e.target.value }))
  const setSecretField = (k: keyof typeof secret) => (e: React.ChangeEvent<HTMLInputElement>) =>
    setSecret((s) => ({ ...s, [k]: e.target.value }))

  /**
   * The chosen path, translated into the account fields the backend actually stores. It travels in the
   * registration request itself — router state would not survive a refresh, and the wizard would then ask
   * the visitor to pick the same path a second time.
   */
  const preset = useMemo(() => {
    if (journey === null) return null
    const account_type = journey === 'multi-client' ? 'agency' : selfType
    /*
     * A `module` the product is not offering does not carry (INFL-OFF-001).
     *
     * The influencer cards are gone from the public site, but a bookmarked
     * `/register?module=influencer-marketing` is still a live URL — and honouring it here would open
     * an application for a service that has no portal to serve it. The backend refuses the value
     * too; this stops the form reaching it with something it will reject.
     */
    const wantsInfluencer =
      moduleParam === 'influencer-marketing' || moduleParam === 'influencer_marketing'
    const service =
      wantsInfluencer && features.influencersUgc
        ? ('influencer_marketing' as const)
        : ('paid_media' as const)
    return { account_type, service }
  }, [journey, selfType, moduleParam])

  // Switch path in place — persisted to the query string so a refresh (or the submit) keeps it.
  const pickJourney = (next: Journey) => {
    const p = new URLSearchParams(params)
    p.set('journey', next)
    setParams(p, { replace: true })
  }

  const mutation = useMutation({
    mutationFn: apply,
    onSuccess: (envelope) => {
      draft.clear()
      // The id travels in the URL AND is kept locally, so closing the tab is recoverable.
      rememberRegistration(envelope.registration.id)
      /*
       * The whole envelope rides along in router state, because it carries the challenge that was
       * just issued — and the dev link inside it is, until a mail provider exists, the only way to
       * confirm the address. Router state rather than storage: a verification token is a credential,
       * and it has no business outliving the navigation it was minted for. After a refresh the
       * status page offers "resend", which is the honest recovery.
       */
      navigate(`/signup/status?request=${envelope.registration.id}`, { replace: true, state: { envelope } })
    },
  })
  const error = mutation.isError ? toApiError(mutation.error) : null
  const err = (k: string) => error?.errors?.[k]?.[0]
  // Field ids match the input ids below so the summary's click-to-focus lands on the right control.
  const summaryErrors: FieldError[] = error?.errors
    ? Object.entries(error.errors).flatMap(([field, msgs]) => (msgs?.length ? [{ field, message: msgs[0] }] : []))
    : []

  return (
    <AuthShell portal={journey === 'multi-client' ? 'agency' : 'default'}>
      <h2 className="font-[var(--font-heading)] text-[22px] font-extrabold text-text-primary">{t('create_account_title')}</h2>
      <p className="mt-1 text-[14px] text-text-secondary">{t('create_account_subtitle')}</p>

      {/* Journey preset — only when arriving from a decision-section card. Editable, not a forced re-pick. */}
      {journey && (
        <section data-testid="register-journey" className="mt-3 rounded-2xl border border-border bg-surface-secondary p-2.5" aria-label={jc.heading}>
          <div className="flex items-center justify-between">
            <span className="text-sm font-bold text-text-primary">{jc.heading}</span>
            <span className="text-xs text-text-muted">{jc.editable}</span>
          </div>
          <div className="mt-3 grid gap-2 sm:grid-cols-2">
            {([
              { key: 'self-service' as const, label: jc.selfManaged, Icon: LayoutDashboard },
              { key: 'multi-client' as const, label: jc.agency, Icon: Users },
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

          {journey === 'self-service' ? (
            /* Label beside the control rather than stacked above it: the same question in one row instead
               of three, which is what keeps this page inside a 768px-tall screen. */
            <div className="mt-3 flex flex-wrap items-center gap-2.5">
              <label htmlFor="self-account-type" className="text-sm font-semibold text-text-primary">{jc.accountTypeLabel}</label>
              <select
                id="self-account-type"
                className={`${controlClass} h-10 w-auto min-w-[9rem] flex-1 text-sm`}
                value={selfType}
                onChange={(e) => setSelfType(e.target.value as SelfAccountType)}
              >
                {(['freelancer', 'brand', 'in_house_team'] as SelfAccountType[]).map((k) => (
                  <option key={k} value={k}>{jc.accountTypes[k]}</option>
                ))}
              </select>
            </div>
          ) : (
            <div className="mt-3 flex items-center gap-2 rounded-xl border border-border bg-surface px-3.5 py-2.5 text-sm">
              <Building2 size={17} className="shrink-0 text-brand-600" />
              <span className="font-semibold text-text-primary">{jc.agencyType}</span>
              <span className="text-text-muted">— {jc.agencySummary}</span>
            </div>
          )}

          {preset && (
            <p className="mt-2 text-xs text-text-muted">
              {rc.moduleLabel}:{' '}
              <span className="font-semibold text-text-secondary">
                {SERVICE_LABELS[preset.service][ar ? 'ar' : 'en']}
              </span>
            </p>
          )}
        </section>
      )}

      {/* Where the visitor is, in two words. Two steps do not need a progress bar. */}
      <ol data-testid="register-steps" className="mt-2 flex items-center gap-2 text-xs font-semibold">
        {([1, 2] as const).map((n) => (
          <li key={n} data-testid={`register-step-${n}`} data-current={step === n} className={`flex items-center gap-1.5 ${step === n ? 'text-brand-600' : 'text-text-muted'}`}>
            <span className={`flex h-5 w-5 items-center justify-center rounded-full text-[11px] ${step === n ? 'bg-brand-600 text-white' : 'bg-surface-secondary'}`}>{n}</span>
            {n === 1 ? rc.stepAccount : rc.stepPlan}
            {n === 1 && <span className="text-text-muted">·</span>}
          </li>
        ))}
      </ol>

      {/*
        One <form>, two panels. The fields stay mounted across the step so a visitor who goes back to
        change an email does not find the page has forgotten their password — and so the browser's own
        `required` validation still guards step one.

        Two columns where the fields are short, so every field and the button fit a 768px-tall desktop
        screen without scrolling.
      */}
      <form className="mt-2 space-y-2" onSubmit={(e) => {
        e.preventDefault()
        if (step === 1) { setStep(2); return }
        mutation.mutate({
          ...form,
          ...(preset ?? {}),
          // Omitted entirely when nothing was chosen — an empty string is not a plan, and sending one
          // would be the form answering a question the visitor did not.
          ...(planCode ? { plan_code: planCode, billing_interval: interval } : {}),
        })
      }}>
        {summaryErrors.length > 0 && <ErrorSummary errors={summaryErrors} title={rc.errTitle} />}

        <div className={step === 1 ? 'space-y-3' : 'hidden'} data-testid="register-panel-account">
          <TextInput id="tenant_name" label={t('org_name')} value={form.tenant_name} onChange={setDraft('tenant_name')} required error={err('tenant_name')} />
          <div className="grid gap-3 sm:grid-cols-2">
            <TextInput id="name" label={t('full_name')} value={form.name} onChange={setDraft('name')} autoComplete="name" required error={err('name')} />
            <EmailInput id="email" label={t('email')} value={form.email} onChange={setDraft('email')} required error={err('email')} />
          </div>
          <div className="grid gap-3 sm:grid-cols-2">
            <PasswordInput id="password" label={t('password')} value={form.password} onChange={setSecretField('password')} autoComplete="new-password" required error={err('password')} showLabel={t('show_password')} hideLabel={t('hide_password')} />
            <PasswordInput id="password_confirmation" label={t('confirm_password')} value={form.password_confirmation} onChange={setSecretField('password_confirmation')} autoComplete="new-password" required showLabel={t('show_password')} hideLabel={t('hide_password')} />
          </div>
        </div>

        {step === 2 && (
          <div data-testid="register-panel-plan" className="space-y-3">
            <PlanChooser value={planCode} interval={interval} onChange={setPlanCode} onIntervalChange={setInterval} />
            <p className="text-xs text-text-muted">{rc.planOptional}</p>
          </div>
        )}

        {error && !error.errors && <p className="rounded-xl bg-[var(--negative-background)] px-4 py-3 text-sm text-danger">{error.message}</p>}

        <div className="flex gap-2">
          {step === 2 && (
            <button
              type="button"
              data-testid="register-back"
              onClick={() => setStep(1)}
              className="rounded-xl border border-border px-4 text-sm font-semibold text-text-secondary hover:text-text-primary"
            >
              {rc.back}
            </button>
          )}
          <Button type="submit" loading={mutation.isPending} className="flex-1" size="lg">
            {step === 1 ? rc.next : t('create_account')}
          </Button>
        </div>
      </form>

      <p className="mt-4 text-center text-sm text-text-secondary">
        {t('have_account')} <Link to="/login" className="font-semibold text-brand-600 hover:underline">{t('sign_in')}</Link>
      </p>
    </AuthShell>
  )
}

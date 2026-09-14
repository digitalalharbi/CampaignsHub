import { useEffect, useMemo, useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { useMutation } from '@tanstack/react-query'
import { Building2, Check, LayoutDashboard, Users } from 'lucide-react'
import { AuthShell } from './AuthShell'
import { apply, rememberRegistration, type BillingInterval } from '@/features/signup/api'
import { PlanChooser } from '@/features/signup/PlanChooser'
import { Button } from '@/components/ui/Button'
import { EmailInput, PasswordInput, TextInput } from '@/components/ui/form'
import { PhoneField, phoneFieldValue, DEFAULT_DIAL_CODE } from '@/components/ui/PhoneField'
import { controlClass } from '@/components/ui/Field'
import { ErrorSummary, useFormDraft, type FieldError } from '@/components/forms'
import { ACCOUNT_FIELDS, belongsToAccountStep, validateAccountStep, type AccountErrors } from './registerValidation'
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
    heading: 'كيف تريد البدء؟',
    editable: 'اختر ما يناسب احتياجك، وسنجهّز لك تجربة CampaignsHub المناسبة مباشرة.',
    selfManaged: 'أدير حملاتي بنفسي',
    selfManagedDesc: 'حساب واحد يجمع حملاتك ومنصاتك وميزانياتك وتقاريرك في مكان واحد.',
    agency: 'أدير حملات لعدة عملاء',
    agencyDesc: 'نظّم عملاءك ومشاريعك وحملاتك، وتابع أداء كل عميل بشكل مستقل.',
    accountTypeLabel: 'نوع الحساب',
    accountTypes: { freelancer: 'مستقل', brand: 'علامة تجارية', in_house_team: 'فريق تسويق داخلي' } as Record<SelfAccountType, string>,
    agencyType: 'حساب وكالة',
    agencySummary: 'إدارة العملاء والطلبات مفعّلة لمساحة الوكالة.',
  },
  en: {
    heading: 'How would you like to start?',
    editable: 'Pick what matches your work, and we will set CampaignsHub up to suit it.',
    selfManaged: 'I run my own campaigns',
    selfManagedDesc: 'One account holding your campaigns, platforms, budgets and reports together.',
    agency: 'I run campaigns for several clients',
    agencyDesc: 'Organise your clients, projects and campaigns, and follow each client separately.',
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
    planRequired: 'اختر باقة للمتابعة.',
    journeyRequired: 'اختر أولًا كيف ستستخدم CampaignsHub من الأعلى، لتظهر لك الباقات المناسبة.',
    planNote: 'يبدأ الاشتراك بعد تأكيد الدفع. لن تُفعّل مساحة العمل قبل ذلك.',
    fixAccount: 'يرجى تصحيح بيانات الحساب في الخطوة السابقة.',
    backToAccount: 'العودة إلى بيانات الحساب',
  },
  en: {
    errTitle: 'Please fix the following errors',
    moduleLabel: 'Selected service',
    stepAccount: 'Your account',
    stepPlan: 'Plan',
    next: 'Continue',
    back: 'Back',
    planRequired: 'Choose a plan to continue.',
    journeyRequired: 'Choose how you will use CampaignsHub above, and the plans that fit will appear here.',
    planNote: 'Your subscription starts once the payment is confirmed. Nothing is activated before then.',
    fixAccount: 'Please correct your account details on the previous step.',
    backToAccount: 'Back to account details',
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

  /*
   * Account-step errors, decided in the browser (SIGNUP-STEP-001).
   *
   * Kept apart from the server's errors rather than merged into one bag, because they are answers to
   * different questions and belong on different screens: these are "this field is not yet valid",
   * and they are the reason the visitor has not reached the packages step at all.
   */
  const [accountErrors, setAccountErrors] = useState<AccountErrors>({})
  const [planError, setPlanError] = useState<string | null>(null)

  /*
   * Non-secret fields autosave as a draft (survives refresh); passwords are kept in memory only.
   *
   * The mobile number and its dial code are drafted too: they are not secrets, and a number that has
   * to be retyped after a refresh is the field people abandon a form over.
   */
  const draft = useFormDraft('register', { tenant_name: '', name: '', email: '', phone: '', dial_code: DEFAULT_DIAL_CODE })
  const [secret, setSecret] = useState({ password: '', password_confirmation: '' })
  const form = { ...draft.value, ...secret }
  const clearError = (k: string) => setAccountErrors((e) => (k in e ? { ...e, [k]: undefined } : e))
  const setDraftValue = (k: 'phone' | 'dial_code') => (value: string) => {
    draft.setValue((f) => ({ ...f, [k]: value }))
    clearError('phone')
  }
  const setDraft = (k: 'tenant_name' | 'name' | 'email') => (e: React.ChangeEvent<HTMLInputElement>) => {
    draft.setValue((f) => ({ ...f, [k]: e.target.value }))
    clearError(k)
  }
  const setSecretField = (k: keyof typeof secret) => (e: React.ChangeEvent<HTMLInputElement>) => {
    setSecret((s) => ({ ...s, [k]: e.target.value }))
    clearError(k)
    // Correcting the password can only ever fix the confirmation, never break it further.
    if (k === 'password') clearError('password_confirmation')
  }

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
  const serverFields = error?.errors ? Object.keys(error.errors) : []

  /*
   * A server refusal about the ACCOUNT sends the visitor back to the account step.
   *
   * `unique:users` is the real case: the address is only known to be taken at the moment of submit,
   * which happens on the packages step. Rendering that message there would put an error about a
   * field on a screen the visitor has left — the exact failure this rework removes. So the form goes
   * back to where the field is, with the message beside it.
   */
  useEffect(() => {
    if (step === 2 && belongsToAccountStep(serverFields)) setStep(1)
  }, [step, serverFields.join(',')]) // eslint-disable-line react-hooks/exhaustive-deps

  /** A field's message — the browser's own if it has one, otherwise the server's. */
  const err = (k: keyof typeof accountErrors) => accountErrors[k] ?? error?.errors?.[k]?.[0]

  /*
   * The summary lists the step the visitor is ON, and nothing else.
   *
   * Field ids match the input ids below so the summary's click-to-focus lands on the right control.
   */
  const summaryErrors: FieldError[] = step === 1
    ? [
      ...Object.entries(accountErrors).flatMap(([field, message]) => (message ? [{ field, message }] : [])),
      ...Object.entries(error?.errors ?? {}).flatMap(([field, msgs]) =>
        msgs?.length && !(field in accountErrors && accountErrors[field as keyof typeof accountErrors])
          ? [{ field, message: msgs[0] }]
          : []),
    ].filter((e) => ACCOUNT_FIELDS.includes(e.field))
    : Object.entries(error?.errors ?? {}).flatMap(([field, msgs]) =>
      msgs?.length && !ACCOUNT_FIELDS.includes(field) ? [{ field, message: msgs[0] }] : [])

  /*
   * The panel follows the journey — PLAN-FIT-001.
   *
   * `default` and `agency` carried the SAME body («نظّم العملاء والمشاريع»), so the side of the page
   * said the same thing on both paths while the toggle above promised two different products. The
   * self-service path has its own now: my campaigns, my data, my reports — and no clients.
   */
  const panelPortal = journey === 'multi-client' ? 'agency' : journey === 'self-service' ? 'advertiser' : 'default'

  return (
    <AuthShell portal={panelPortal}>
      {/*
        Fluid rather than fixed — AUTH-FIT-001.

        The floor is the size a 1024px laptop gets, not a size chosen to make the form fit: the goal
        is a compact page, and a page whose text has been shrunk until it is hard to read is not
        compact, it is small.
      */}
      <h2 className="font-[var(--font-heading)] text-[clamp(1.1875rem,1.5vw,1.375rem)] font-extrabold text-text-primary">{t('create_account_title')}</h2>
      <p className="mt-1 text-[clamp(0.8125rem,0.95vw,0.875rem)] text-text-secondary">{t('create_account_subtitle')}</p>

      {/*
        Always asked — LAUNCH-PRICING-001.

        This used to appear only when the visitor arrived from a homepage decision card, so anybody
        landing on `/register` directly reached the plan step with no path at all and was shown the
        whole catalogue: Starter beside Agency, three plans for two different products. The path is
        not decoration on the way in, it is the question that decides which portal opens and which
        plans exist — so it is asked here, of everyone, and preset when we already know the answer.
      */}
      {(
        <section data-testid="register-journey" className="mt-[clamp(0.5rem,1.4vh,0.75rem)] rounded-2xl border border-border bg-surface-secondary p-[clamp(0.4375rem,0.9vh,0.625rem)]" aria-label={jc.heading}>
          {/*
            Stacked, not opposed — AUTH-FIT-001.

            These sat at either end of one row, which worked while the heading was two words and the
            note was four. Both are sentences now, so on a narrow column the two spans met in the
            middle and overlapped. A heading with its own subtitle beneath it is what this always
            was; the row was only ever hiding that.
          */}
          <div className="flex flex-col gap-0.5">
            <span className="text-sm font-bold text-text-primary">{jc.heading}</span>
            <span className="text-xs leading-snug text-text-muted">{jc.editable}</span>
          </div>
          {/*
            The two answers, each with the sentence that tells them apart.

            The labels alone («لحملاتي وأعمالي» / «لعملائي») are short enough to be misread as the
            same question asked twice; the line beneath each is what makes the choice a real one, and
            it is the difference the rest of the signup then follows — which portal opens, and which
            plans are offered.
          */}
          <div className="mt-[clamp(0.5rem,1.4vh,0.75rem)] grid gap-[clamp(0.375rem,0.9vh,0.5rem)] sm:grid-cols-2">
            {([
              { key: 'self-service' as const, label: jc.selfManaged, desc: jc.selfManagedDesc, Icon: LayoutDashboard },
              { key: 'multi-client' as const, label: jc.agency, desc: jc.agencyDesc, Icon: Users },
            ]).map(({ key, label, desc, Icon }) => {
              const on = journey === key
              return (
                <button
                  key={key} type="button" data-testid={`journey-${key}`} onClick={() => pickJourney(key)} aria-pressed={on}
                  className={`flex min-w-0 items-start gap-2.5 rounded-xl border px-3 py-[clamp(0.4375rem,0.95vh,0.6875rem)] text-start transition-colors ${on ? 'border-brand-500 bg-brand-primary-soft' : 'border-border bg-surface hover:border-brand-400'}`}
                >
                  <Icon size={17} className={`mt-0.5 shrink-0 ${on ? 'text-brand-700' : 'text-text-secondary'}`} />
                  <span className="min-w-0 flex-1">
                    <span className={`flex items-center gap-1.5 text-[13px] font-semibold leading-snug ${on ? 'text-brand-700' : 'text-text-primary'}`}>
                      {label}
                      {on && <Check size={15} className="shrink-0 text-brand-600" />}
                    </span>
                    <span className="mt-0.5 block text-[11px] leading-[1.35] text-text-muted">{desc}</span>
                  </span>
                </button>
              )
            })}
          </div>

          {/* Nothing chosen yet asks the follow-up of neither path — an account-type select above an
              unanswered question is a second question nobody was asked. */}
          {journey === null ? null : journey === 'self-service' ? (
            /* Label beside the control rather than stacked above it: the same question in one row instead
               of three, which is what keeps this page inside a 768px-tall screen. */
            <div className="mt-[clamp(0.375rem,1vh,0.625rem)] flex flex-wrap items-center gap-2.5">
              <label htmlFor="self-account-type" className="text-sm font-semibold text-text-primary">{jc.accountTypeLabel}</label>
              <select
                id="self-account-type"
                className={`${controlClass} h-[clamp(2.125rem,4.6vh,2.5rem)] w-auto min-w-[9rem] flex-1 text-sm`}
                value={selfType}
                onChange={(e) => setSelfType(e.target.value as SelfAccountType)}
              >
                {(['freelancer', 'brand', 'in_house_team'] as SelfAccountType[]).map((k) => (
                  <option key={k} value={k}>{jc.accountTypes[k]}</option>
                ))}
              </select>
            </div>
          ) : (
            <div className="mt-[clamp(0.375rem,1vh,0.625rem)] flex flex-wrap items-center gap-2 rounded-xl border border-border bg-surface px-3.5 py-2 text-sm">
              <Building2 size={17} className="shrink-0 text-brand-600" />
              <span className="font-semibold text-text-primary">{jc.agencyType}</span>
              <span className="text-text-muted">— {jc.agencySummary}</span>
            </div>
          )}

          {preset && (
            <p className="mt-1.5 text-xs leading-snug text-text-muted">
              {rc.moduleLabel}:{' '}
              <span className="font-semibold text-text-secondary">
                {SERVICE_LABELS[preset.service][ar ? 'ar' : 'en']}
              </span>
            </p>
          )}
        </section>
      )}

      {/* Where the visitor is, in two words. Two steps do not need a progress bar. */}
      <ol data-testid="register-steps" className="mt-[clamp(0.25rem,0.7vh,0.5rem)] flex items-center gap-2 text-xs font-semibold">
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
      {/*
        `noValidate` — one validation authority, not two (SIGNUP-STEP-001).
        The browser's own bubbles are English-only, appear one field at a time, vanish on the next
        click and cannot be placed beside the field the way the rest of this product places an error.
        Left on, they also PREEMPT the check below, so a malformed address never reached our rules at
        all. The fields keep `required` for assistive technology; `validateAccountStep` decides.
      */}
      <form noValidate className="mt-[clamp(0.25rem,0.7vh,0.5rem)] space-y-[clamp(0.3125rem,0.85vh,0.5rem)]" onSubmit={(e) => {
        e.preventDefault()

        /*
         * Step one is a gate, not a page turn.
         *
         * Nothing about the account travels to the packages step until every field on this one is
         * valid — which is what stops «كلمة المرور ضعيفة» being read for the first time beside a
         * price list, with no field on screen to correct.
         */
        if (step === 1) {
          const problems = validateAccountStep(form, ar)
          setAccountErrors(problems)

          const firstBad = Object.keys(problems)[0]
          if (firstBad) {
            document.getElementById(firstBad)?.focus()
            return
          }

          mutation.reset() // a stale server error must not follow a corrected form forward
          setStep(2)
          return
        }

        // A plan is not optional any more: there is no free tier to fall back to, and an application
        // naming no plan owes an amount nobody can compute.
        if (!planCode) {
          setPlanError(rc.planRequired)
          return
        }
        setPlanError(null)

        const { dial_code: _dialCode, ...fields } = form

        mutation.mutate({
          ...fields,
          /*
           * The canonical number, not the raw text.
           *
           * The server normalises again — it must, because a hand-written payload never came through
           * this control — but sending E.164 means the duplicate check, the OTP destination and what
           * the customer sees back are one value rather than three readings of one.
           */
          phone: phoneFieldValue(form.phone, form.dial_code) ?? form.phone,
          ...(preset ?? {}),
          plan_code: planCode,
          billing_interval: interval,
        })
      }}>
        {/*
          The summary belongs to the step it describes.
          On the packages step it is shown only for errors about the packages step — a server refusal
          about the email address sends the visitor back to the field instead (see `useEffect` above).
        */}
        {summaryErrors.length > 0 && <ErrorSummary errors={summaryErrors} title={rc.errTitle} />}

        <div className={step === 1 ? 'space-y-[clamp(0.5rem,1.4vh,0.75rem)]' : 'hidden'} data-testid="register-panel-account">
          {/*
            Three rows, not four.

            The mobile number is required now (PHONE-VERIFY-001), and giving it a row of its own
            pushed this page past the 768px-tall budget `auth-redesign.spec.ts` holds it to — the
            submit button went below the fold on every desktop size. Pairing it with the organisation
            name keeps the count where it was, and the pairing reads: the workspace, then the person,
            then their secrets.

            No hint under the field, for the same reason. What it said — that a code goes to this
            number before activation — is said on the status page, at the moment it happens, which is
            where somebody actually needs to read it.
          */}
          <div className="grid gap-[clamp(0.5rem,1.4vh,0.75rem)] sm:grid-cols-2">
            <TextInput id="tenant_name" label={t('org_name')} value={form.tenant_name} onChange={setDraft('tenant_name')} required error={err('tenant_name')} />
            <PhoneField
              id="phone"
              label={ar ? 'رقم الجوال' : 'Mobile number'}
              value={form.phone}
              onChange={setDraftValue('phone')}
              dialCode={form.dial_code}
              onDialCodeChange={setDraftValue('dial_code')}
              ar={ar}
              required
              error={err('phone')}
            />
          </div>
          <div className="grid gap-[clamp(0.5rem,1.4vh,0.75rem)] sm:grid-cols-2">
            <TextInput id="name" label={t('full_name')} value={form.name} onChange={setDraft('name')} autoComplete="name" required error={err('name')} />
            <EmailInput id="email" label={t('email')} value={form.email} onChange={setDraft('email')} required error={err('email')} />
          </div>

          <div className="grid gap-[clamp(0.5rem,1.4vh,0.75rem)] sm:grid-cols-2">
            <PasswordInput id="password" label={t('password')} value={form.password} onChange={setSecretField('password')} autoComplete="new-password" required error={err('password')} showLabel={t('show_password')} hideLabel={t('hide_password')} />
            <PasswordInput id="password_confirmation" label={t('confirm_password')} value={form.password_confirmation} onChange={setSecretField('password_confirmation')} autoComplete="new-password" required showLabel={t('show_password')} hideLabel={t('hide_password')} />
          </div>
        </div>

        {step === 2 && (
          <div data-testid="register-panel-plan" className="space-y-[clamp(0.5rem,1.4vh,0.75rem)]">
            {/*
              The plans follow the journey — PLAN-FIT-001.

              Both paths used to be shown all three plans with the same three sentences, so somebody
              who had just said «I run my own campaigns» was offered a plan whose summary talked
              about agencies. A price list that does not change when the question changes is not a
              choice.
            */}
            {journey === null ? (
              /*
                No path, no price list. The plans differ BY path now — «لعملائي» is sold Agency and
                nothing else — so a catalogue shown before the question is answered is three plans
                for two products, which is the incoherence this restructure exists to remove.
              */
              <p data-testid="register-journey-required" className="rounded-xl border border-border bg-surface-secondary p-3 text-sm text-text-secondary">
                {rc.journeyRequired}
              </p>
            ) : (
              <PlanChooser
                value={planCode}
                interval={interval}
                onChange={(code) => { setPlanCode(code); setPlanError(null) }}
                onIntervalChange={setInterval}
                journey={journey}
              />
            )}
            {planError && (
              <p data-testid="register-plan-error" role="alert" className="text-[13px] font-semibold text-danger">
                {planError}
              </p>
            )}
            {/* Said before the payment page, not after it: nothing is activated until the money is
                confirmed, and the visitor should know that before they choose a term. */}
            <p className="text-xs leading-snug text-text-muted">{rc.planNote}</p>
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

      <p className="mt-[clamp(0.5rem,1.6vh,1rem)] text-center text-sm text-text-secondary">
        {t('have_account')} <Link to="/login" className="font-semibold text-brand-600 hover:underline">{t('sign_in')}</Link>
      </p>
    </AuthShell>
  )
}

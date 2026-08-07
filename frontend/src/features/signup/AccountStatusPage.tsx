import { useEffect, useRef, useState } from 'react'
import { Link, useLocation, useNavigate, useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  BadgeCheck, Clock, CreditCard, Loader2, MailCheck, RefreshCw, ShieldAlert, ShieldCheck, Smartphone,
} from 'lucide-react'
import {
  fetchPaymentProviders, fetchRegistration, forgetRegistration, recallRegistration, rememberRegistration,
  resendRegistrationChallenge, startCheckout, verifyRegistrationEmail, verifyRegistrationMobile,
  type CheckoutResult, type RegistrationEnvelope, type RegistrationState, type VerificationIssued,
} from './api'
import { AuthShell } from '@/features/auth/AuthShell'
import { Button } from '@/components/ui/Button'
import { TextInput } from '@/components/ui/form'
import { toApiError } from '@/lib/api/client'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'

/**
 * Where an applicant stands, at every point before they have an account (SIGNUP-002).
 *
 * The failure this page exists to prevent is "I signed up and nothing happened". Registration can
 * now stop at verification, at a review queue or at a payment, and each of those is a real place to
 * be — so each has a screen that says what it is and, crucially, whether the applicant is expected
 * to do anything. When the answer is no, this page says so plainly instead of inventing a button.
 *
 * It is reachable without a session, because an applicant has none. The registration id in the URL
 * is what identifies the application; it is also kept locally so closing the tab is recoverable.
 */

const COPY = {
  ar: {
    title: 'حالة طلب التسجيل',
    checking: 'جارٍ التحقق…',
    verifying: 'جارٍ تأكيد بريدك…',
    notFound: 'لم نعثر على طلب تسجيل. ابدأ من صفحة إنشاء الحساب.',
    lookupFailed: 'تعذّر قراءة حالة الطلب. لم يتغيّر شيء في طلبك؛ المشكلة في قراءته الآن.',
    startOver: 'إنشاء حساب',
    awaitingProvider: 'لم يُفعَّل مزوّد الإرسال بعد (بانتظار بيانات الاعتماد)، لذلك لم تُرسل الرسالة فعليًا. استخدم زر التطوير أدناه مؤقتًا.',
    devVerify: 'تأكيد الآن (تطوير)',
    devCode: 'رمز التطوير',
    resendEmail: 'إعادة إرسال رابط التأكيد',
    resendCode: 'إرسال رمز جديد',
    codeLabel: 'رمز التحقق المرسل إلى جوالك',
    submitCode: 'تأكيد الرمز',
    waitingOnUs: 'لا يوجد إجراء مطلوب منك الآن. سنبلغك فور اكتمال المراجعة.',
    payNow: 'إتمام الدفع',
    paymentSoon: 'بوابة الدفع غير مهيأة بعد (بانتظار بيانات الاعتماد). لن يُفعَّل الحساب إلا بعد تأكيد الدفع من المزوّد.',
    paymentRule: 'لن يُفعَّل الحساب بمجرد رجوعك من صفحة الدفع، بل بعد تأكيد المزوّد للعملية.',
    sandboxNote: 'وضع تجريبي (Sandbox): لا تتحرك أي أموال حقيقية. المسار كامل — يُوقَّع الحدث ويُتحقق منه ويُفعَّل الحساب — لكن هذه ليست عملية دفع فعلية.',
    payAmount: 'المبلغ المستحق الآن',
    trialRefused: 'لا يمكن بدء تجربة جديدة: سبق استخدام تجربة بهذه البيانات.',
    provider: 'مزوّد الدفع',
    goToWorkspace: 'الانتقال إلى مساحة العمل',
    signIn: 'تسجيل الدخول',
    checkAgain: 'تحديث الحالة',
    steps: 'مراحل التفعيل',
    stepEmail: 'تأكيد البريد',
    stepMobile: 'تأكيد الجوال',
    stepApproval: 'مراجعة الطلب',
    stepPayment: 'الدفع',
    stepActive: 'تفعيل الحساب',
  },
  en: {
    title: 'Registration status',
    checking: 'Checking…',
    verifying: 'Confirming your email…',
    notFound: 'We could not find a registration. Start from the sign-up page.',
    lookupFailed: 'We could not read the status of your application. Nothing about it has changed — reading it just now is what failed.',
    startOver: 'Create an account',
    awaitingProvider: 'No delivery provider is configured yet (awaiting credentials), so the message was not actually sent. Use the dev button below for now.',
    devVerify: 'Verify now (dev)',
    devCode: 'Dev code',
    resendEmail: 'Resend the confirmation link',
    resendCode: 'Send a new code',
    codeLabel: 'The code we sent to your mobile',
    submitCode: 'Confirm code',
    waitingOnUs: 'There is nothing for you to do right now. We will let you know as soon as the review is complete.',
    payNow: 'Complete payment',
    paymentSoon: 'No payment gateway is configured yet (awaiting credentials). The account is activated only once the provider confirms the payment.',
    paymentRule: 'Returning from the payment page does not activate the account — the provider confirming the charge does.',
    sandboxNote: 'Sandbox mode: no real money moves. The whole path runs — the event is signed, verified and the account activated — but this is not a real payment.',
    payAmount: 'Due now',
    trialRefused: 'A new trial cannot be started: one has already been used with these details.',
    provider: 'Payment provider',
    goToWorkspace: 'Go to your workspace',
    signIn: 'Sign in',
    checkAgain: 'Refresh status',
    steps: 'Activation steps',
    stepEmail: 'Email confirmed',
    stepMobile: 'Mobile confirmed',
    stepApproval: 'Application reviewed',
    stepPayment: 'Payment',
    stepActive: 'Account active',
  },
} as const

/** The icon and tone for each state — a rejected application must not look like a pending one. */
const TONE: Record<RegistrationState, { Icon: typeof MailCheck; tone: 'wait' | 'act' | 'good' | 'bad' }> = {
  draft: { Icon: Clock, tone: 'wait' },
  email_verification_required: { Icon: MailCheck, tone: 'act' },
  mobile_verification_required: { Icon: Smartphone, tone: 'act' },
  pending_approval: { Icon: Clock, tone: 'wait' },
  approved_awaiting_payment: { Icon: CreditCard, tone: 'act' },
  payment_pending: { Icon: Clock, tone: 'wait' },
  active: { Icon: BadgeCheck, tone: 'good' },
  past_due: { Icon: CreditCard, tone: 'act' },
  suspended: { Icon: ShieldAlert, tone: 'bad' },
  rejected: { Icon: ShieldAlert, tone: 'bad' },
  cancelled: { Icon: ShieldAlert, tone: 'bad' },
  expired: { Icon: ShieldAlert, tone: 'bad' },
}

const TONE_CLASS = {
  wait: 'bg-surface-secondary text-text-secondary',
  act: 'bg-brand-primary-soft text-brand-600',
  good: 'bg-[var(--positive-background)] text-[var(--positive-foreground)]',
  bad: 'bg-[var(--negative-background)] text-danger',
} as const

export function AccountStatusPage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const c = COPY[ar ? 'ar' : 'en']
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const setUser = useAuth((s) => s.setUser)
  const [params] = useSearchParams()

  const token = params.get('token')
  const id = params.get('request') ?? recallRegistration()

  /*
   * What the sign-up form was handed a moment ago, carried through the navigation rather than
   * re-fetched — the status endpoint answers with the application, not with the challenge that was
   * issued alongside it, and asking again would not bring the challenge back.
   */
  const handedOver = (useLocation().state as { envelope?: RegistrationEnvelope } | null)?.envelope

  // The freshest challenge we hold, so the dev link/code survives a resend without a reload.
  const [issued, setIssued] = useState<VerificationIssued | null>(handedOver?.verification ?? null)
  const [code, setCode] = useState('')
  const [error, setError] = useState<string | null>(null)

  useEffect(() => { if (id) rememberRegistration(id) }, [id])

  const status = useQuery({
    queryKey: ['registration', id],
    queryFn: () => fetchRegistration(id as string),
    enabled: Boolean(id) && !token,
    initialData: handedOver?.registration.id === id ? handedOver : undefined,
  })

  /**
   * Adopt whatever a mutation just told us, rather than re-fetching to find out.
   *
   * Every one of these endpoints answers with the application's new state, so a follow-up request
   * would only be a second chance to disagree with it.
   */
  const adopt = (envelope: RegistrationEnvelope) => {
    queryClient.setQueryData(['registration', envelope.registration.id], envelope)
    if (envelope.verification) setIssued(envelope.verification)
    setError(null)

    if (envelope.registration.provisioned) {
      forgetRegistration()
      if (envelope.user) {
        // The application became a workspace and the server opened a session with it. Straight in.
        setUser(envelope.user)
        navigate('/onboarding', { replace: true })
      }
    }
  }

  const failed = (e: unknown) => setError(toApiError(e).message)

  const verify = useMutation({ mutationFn: verifyRegistrationEmail, onSuccess: adopt, onError: failed })
  const resend = useMutation({
    mutationFn: (channel: 'email' | 'mobile') => resendRegistrationChallenge(id as string, channel),
    onSuccess: adopt,
    onError: failed,
  })
  const confirmCode = useMutation({
    mutationFn: () => verifyRegistrationMobile(id as string, code),
    onSuccess: adopt,
    onError: failed,
  })

  // Auto-verify when the page is opened from a confirmation link. Guarded against a second run:
  // the token is single-use, so firing it twice turns a success into "already used".
  const consumed = useRef(false)
  useEffect(() => {
    if (token && !consumed.current) { consumed.current = true; verify.mutate(token) }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [token])

  if (!id && !token) {
    return (
      <AuthShell>
        <div data-testid="registration-missing" className="flex flex-col items-center gap-4 text-center">
          <p className="text-sm text-text-secondary">{c.notFound}</p>
          <Link to="/register" className="font-semibold text-brand-600 hover:underline">{c.startOver}</Link>
        </div>
      </AuthShell>
    )
  }

  const envelope = (queryClient.getQueryData(['registration', id]) as RegistrationEnvelope | undefined) ?? status.data
  const reg = envelope?.registration
  const policy = envelope?.policy

  /*
   * A LOOKUP that failed is not a lookup still running.
   *
   * `error` above is set by the mutations only, so until this branch existed a failed QUERY left the
   * spinner turning with nothing beside it — for as long as the tab stayed open. That is the worst
   * available answer for this particular page, whose entire reason to exist is that «I signed up and
   * nothing happened» must never be what somebody is left with. An applicant sent here by a payment
   * gateway with a registration id we cannot read gets told so, and is offered the two doors that
   * actually lead somewhere: ask again, or start over.
   *
   * Deliberately NOT an automatic retry. The failure this branch first exposed was an address
   * pointing at the wrong installation entirely (see `e2e/env.ts`) — retrying would have re-asked
   * the wrong server, more often, and reported nothing.
   */
  if (!reg && status.isError) {
    return (
      <AuthShell>
        <div data-testid="registration-unavailable" className="flex flex-col items-center gap-4 py-10 text-center">
          <span className="flex h-14 w-14 items-center justify-center rounded-2xl bg-[var(--negative-background)] text-danger">
            <ShieldAlert size={26} />
          </span>
          <p data-testid="registration-error" className="text-sm text-danger">
            {toApiError(status.error).message}
          </p>
          <p className="text-sm text-text-secondary">{c.lookupFailed}</p>
          <div className="flex flex-wrap items-center justify-center gap-3">
            <Button data-testid="registration-refresh" onClick={() => void status.refetch()} variant="secondary">
              <RefreshCw size={15} /> {c.checkAgain}
            </Button>
            <Link to="/register" className="text-sm font-semibold text-brand-600 hover:underline">{c.startOver}</Link>
          </div>
        </div>
      </AuthShell>
    )
  }

  if (!reg) {
    return (
      <AuthShell>
        <div className="flex flex-col items-center gap-3 py-10 text-center">
          <Loader2 className="animate-spin text-brand-600" />
          <p className="text-sm text-text-secondary">{token ? c.verifying : c.checking}</p>
          {error && <p data-testid="registration-error" className="text-sm text-danger">{error}</p>}
        </div>
      </AuthShell>
    )
  }

  const { Icon, tone } = TONE[reg.state]
  const devLink = issued?.dev_link ?? null
  const devCode = issued?.dev_code ?? null

  return (
    <AuthShell portal={reg.requested_portal === 'agency' ? 'agency' : 'default'}>
      <div data-testid="registration-status" data-state={reg.state} className="flex flex-col gap-5">
        <div className="flex flex-col items-center gap-3 text-center">
          <span className={`flex h-14 w-14 items-center justify-center rounded-2xl ${TONE_CLASS[tone]}`}>
            <Icon size={26} />
          </span>
          <div>
            <h1 className="font-heading text-xl font-extrabold text-text-primary">{c.title}</h1>
            {/* The state in words, translated by the server — the browser does not own this vocabulary. */}
            <p data-testid="registration-label" className="mt-1 text-sm font-semibold text-text-primary">{reg.label}</p>
            <p className="mt-0.5 text-sm text-text-secondary" dir="ltr">{reg.email}</p>
          </div>
        </div>

        {/* The single thing to do next — rendered only when there IS one. */}
        {reg.next_step && (
          <p data-testid="registration-next-step" className="rounded-xl border border-border bg-surface-secondary px-4 py-3 text-center text-sm text-text-primary">
            {reg.next_step}
          </p>
        )}

        {/* …and its opposite, said out loud rather than left as an empty screen. */}
        {!reg.next_step && !reg.provisioned && (reg.state === 'pending_approval' || reg.state === 'payment_pending') && (
          <p data-testid="registration-waiting" className="rounded-xl border border-border bg-surface-secondary px-4 py-3 text-center text-sm text-text-secondary">
            {c.waitingOnUs}
          </p>
        )}

        {reg.reason && (
          <p data-testid="registration-reason" className="rounded-xl bg-[var(--negative-background)] px-4 py-3 text-sm text-danger">{reg.reason}</p>
        )}

        <ActivationSteps reg={reg} policy={policy} copy={c} />

        {reg.state === 'email_verification_required' && (
          <div className="flex flex-col items-center gap-3">
            <p className="text-xs text-text-muted">{c.awaitingProvider}</p>
            {devLink && (
              <a data-testid="registration-dev-verify" href={devLink} className="flex items-center gap-1.5 rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-700">
                <ShieldCheck size={16} /> {c.devVerify}
              </a>
            )}
            <button
              data-testid="registration-resend-email"
              onClick={() => resend.mutate('email')}
              disabled={resend.isPending}
              className="flex items-center gap-1.5 rounded-xl border border-border px-4 py-2.5 text-sm font-semibold text-text-secondary hover:text-text-primary disabled:opacity-60"
            >
              {resend.isPending ? <Loader2 size={15} className="animate-spin" /> : <RefreshCw size={15} />} {c.resendEmail}
            </button>
          </div>
        )}

        {reg.state === 'mobile_verification_required' && (
          <form
            className="flex flex-col gap-3"
            onSubmit={(e) => { e.preventDefault(); confirmCode.mutate() }}
          >
            <p className="text-xs text-text-muted">{c.awaitingProvider}</p>
            {devCode && (
              <p data-testid="registration-dev-code" className="text-center text-sm text-text-secondary">
                {c.devCode}: <span className="font-mono font-bold text-text-primary" dir="ltr">{devCode}</span>
              </p>
            )}
            <TextInput
              id="otp"
              label={c.codeLabel}
              value={code}
              onChange={(e) => setCode(e.target.value)}
              inputMode="numeric"
              autoComplete="one-time-code"
              required
            />
            <Button type="submit" loading={confirmCode.isPending} size="lg">{c.submitCode}</Button>
            <button
              type="button"
              data-testid="registration-resend-code"
              onClick={() => resend.mutate('mobile')}
              disabled={resend.isPending}
              className="text-sm font-semibold text-brand-600 hover:underline disabled:opacity-60"
            >
              {c.resendCode}
            </button>
          </form>
        )}

        {reg.state === 'approved_awaiting_payment' && (
          <PaymentStep id={reg.id} copy={c} onOpened={(r) => { if (r.checkout_url) window.location.href = r.checkout_url }} />
        )}

        {reg.provisioned && (
          <Link to="/login" className="text-center text-sm font-semibold text-brand-600 hover:underline">{c.signIn}</Link>
        )}

        {error && <p data-testid="registration-error" className="text-center text-sm text-danger">{error}</p>}

        {!reg.provisioned && (
          <button
            data-testid="registration-refresh"
            onClick={() => void status.refetch()}
            className="text-center text-xs font-semibold text-text-muted hover:text-text-secondary"
          >
            {c.checkAgain}
          </button>
        )}
      </div>
    </AuthShell>
  )
}

/**
 * Paying the fee this application owes (PAY-002).
 *
 * The button appears only when a gateway can actually take money. With none configured it says so and
 * offers nothing, because a pay button that cannot pay is the definition of a dead control — and the
 * rule that matters is stated either way: returning from a payment page is not what activates an
 * account.
 */
function PaymentStep({
  id, copy, onOpened,
}: {
  id: string
  copy: typeof COPY['en'] | typeof COPY['ar']
  onOpened: (result: CheckoutResult) => void
}) {
  const [result, setResult] = useState<CheckoutResult | null>(null)
  const providers = useQuery({ queryKey: ['payment-providers'], queryFn: fetchPaymentProviders })

  const checkout = useMutation({
    mutationFn: () => startCheckout(id),
    onSuccess: (r) => { setResult(r); onOpened(r) },
  })

  const live = providers.data?.providers.find((p) => p.available)
  /*
   * Sandbox is said out loud, on the page where somebody is about to press Pay.
   *
   * The provider endpoint reports three states rather than two for exactly this moment: the button
   * below genuinely works and genuinely activates the account, and letting it sit under the same
   * wording a live gateway gets would be the clearest possible version of claiming a payment that
   * did not happen.
   */
  const sandbox = live?.status === 'sandbox'

  return (
    <div data-testid="registration-payment" className="flex flex-col items-center gap-3">
      {/* Said whether or not a gateway exists: it is the rule, not a consolation for a missing one. */}
      <p data-testid="registration-payment-rule" className="text-center text-xs text-text-muted">{copy.paymentRule}</p>

      {live ? (
        <>
          <Button
            data-testid="registration-pay"
            onClick={() => checkout.mutate()}
            loading={checkout.isPending}
            size="lg"
          >
            <CreditCard size={16} /> {copy.payNow}
          </Button>
          <p className="text-xs text-text-muted">{copy.provider}: {live.provider}</p>
          {sandbox && (
            <p data-testid="registration-payment-sandbox" className="rounded-xl border border-warning/40 bg-warning/10 px-4 py-3 text-center text-sm text-text-primary">
              {copy.sandboxNote}
            </p>
          )}
        </>
      ) : (
        <p data-testid="registration-payment-note" className="rounded-xl border border-border bg-surface-secondary px-4 py-3 text-center text-sm text-text-secondary">
          {copy.paymentSoon}
        </p>
      )}

      {result?.status === 'refused' && (
        <p data-testid="registration-trial-refused" className="rounded-xl bg-[var(--negative-background)] px-4 py-3 text-center text-sm text-danger">
          {copy.trialRefused}
        </p>
      )}

      {result && result.status !== 'refused' && (
        <p data-testid="registration-payment-amount" className="text-sm text-text-secondary" dir="ltr">
          {copy.payAmount}: {result.payment.amount} {result.payment.currency}
        </p>
      )}
    </div>
  )
}

/**
 * The whole journey, with the steps this particular plan actually requires.
 *
 * Showing a payment step to someone on a free plan would be as misleading as hiding one from someone
 * who owes money, so the list is built from the policy rather than being the same for everyone.
 */
function ActivationSteps({
  reg, policy, copy,
}: {
  reg: { email_verified: boolean; mobile_verified: boolean; state: RegistrationState; provisioned: boolean }
  policy?: { requires_mobile: boolean; requires_approval: boolean; requires_payment: boolean }
  copy: typeof COPY['en'] | typeof COPY['ar']
}) {
  const reached = (done: boolean) => (done ? 'text-[var(--positive-foreground)]' : 'text-text-muted')

  const steps: Array<{ key: string; label: string; done: boolean }> = [
    { key: 'email', label: copy.stepEmail, done: reg.email_verified },
  ]
  if (policy?.requires_mobile) steps.push({ key: 'mobile', label: copy.stepMobile, done: reg.mobile_verified })
  if (policy?.requires_approval) {
    steps.push({
      key: 'approval',
      label: copy.stepApproval,
      done: reg.provisioned || reg.state === 'approved_awaiting_payment' || reg.state === 'payment_pending',
    })
  }
  if (policy?.requires_payment) steps.push({ key: 'payment', label: copy.stepPayment, done: reg.provisioned })
  steps.push({ key: 'active', label: copy.stepActive, done: reg.provisioned })

  return (
    <ol data-testid="registration-steps" aria-label={copy.steps} className="flex flex-col gap-2 rounded-2xl border border-border bg-surface p-4">
      {steps.map((s) => (
        <li key={s.key} data-testid={`registration-step-${s.key}`} data-done={s.done} className="flex items-center gap-2 text-sm">
          <BadgeCheck size={16} className={`shrink-0 ${reached(s.done)}`} />
          <span className={s.done ? 'font-semibold text-text-primary' : 'text-text-secondary'}>{s.label}</span>
        </li>
      ))}
    </ol>
  )
}

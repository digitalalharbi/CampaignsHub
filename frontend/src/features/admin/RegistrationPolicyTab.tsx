import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, Loader2 } from 'lucide-react'
import {
  fetchRegistrationVerificationPolicy, saveRegistrationVerificationPolicy,
  type RegistrationVerificationChannel, type RegistrationVerificationPolicy,
} from './api'
import { Button } from '@/components/ui/Button'
import { Num } from '@/components/ui/Num'
import { toApiError } from '@/lib/api/client'
import { useUi } from '@/stores/ui'

/**
 * The platform's signup-verification switches (SIGNUP-VERIFY-POLICY-001).
 *
 * Two independent switches, and they decide one thing: what a NEW application is asked to prove. The page says what they
 * do not do, because that is where somebody could assume otherwise: nobody is marked verified, no payment or approval
 * moves, and an application already in the queue keeps the policy it was opened under.
 */

const COPY = {
  ar: {
    title: 'سياسة التسجيل والتحقق',
    intro: 'تحدد هذه الإعدادات ما يُطلب إثباته من الطلبات الجديدة فقط. الطلبات القائمة تبقى على سياستها، ويمكن إعادة تقييم أي طلب منفردًا من صفحة طلبات التسجيل. الدفع والاعتماد لا يتأثران بها، ولا تُعطَّل رسائل الإيصالات والتنبيهات الأمنية واستعادة الحساب.',
    email: 'تأكيد ملكية البريد الإلكتروني',
    emailOn: 'يُرسل رابط تأكيد، ولا يتقدم الطلب قبل تأكيده.',
    emailOff: 'لا يُرسل رابط. يبقى البريد غير مؤكد، ولا يُستخدم لاستعادة الحساب أو الدخول بلا كلمة مرور.',
    mobile: 'رمز التحقق عبر الجوال (SMS)',
    mobileOn: 'يُرسل رمز، ولا يتقدم الطلب قبل إدخاله. رقم الجوال مطلوب في النموذج.',
    mobileOff: 'لا يُرسل رمز، ولا يُطلب الرقم لأجله. يبقى أي رقم غير مؤكد ولا يصلح لتسجيل الدخول.',
    enabled: 'مفعّل', disabled: 'معطّل',
    riskOne: 'تعطيل هذا التحقق يسمح بفتح طلبات بعنوان أو رقم لم يثبت أحد ملكيته.',
    riskBoth: 'تعطيل التحققين معًا يجعل فتح الطلبات مجانيًا لأي برنامج آلي. يبقى الدفع والاعتماد، وتُطبَّق حدود التسجيل أدناه.',
    confirm: 'أفهم المخاطر وأتحمل هذا القرار.',
    gate: 'البوابة', provider: 'مزوّد الإرسال', configured: 'مهيأ', notConfigured: 'غير مهيأ (لا يُرسل شيء فعليًا)',
    last24: 'آخر 24 ساعة', attempted: 'محاولات', delivered: 'أُرسلت', blocked: 'رُفضت بالحد',
    delivered30: 'أُرسلت خلال 30 يومًا',
    cost: 'التكلفة التقديرية خلال 30 يومًا', unknownCost: 'غير معروفة: لم يُدخل سعر الوحدة',
    costNote: 'تقدير: عدد الرسائل المرسلة × السعر الذي تدخله من فاتورة المزوّد.',
    cap: 'الحد اليومي', noCap: 'بلا حد',
    alert: { ok: 'ضمن الحد', near_cap: 'قريب من الحد', at_cap: 'بلغ الحد: لا يُرسل شيء', no_cap: 'لا يوجد حد' },
    limits: 'الحدود والتكلفة',
    emailCap: 'الحد اليومي للبريد', smsCap: 'الحد اليومي للرسائل النصية', perApp: 'الحد اليومي لكل طلب',
    emailCost: 'سعر رسالة البريد', smsCost: 'سعر الرسالة النصية', currency: 'العملة (مثل USD)',
    alertPct: 'التنبيه عند نسبة % من الحد', ceiling: 'سقف الطلبات غير المؤكدة يوميًا (يُطبق عند تعطيل البريد فقط)',
    emptyHint: 'اترك الحقل فارغًا إن لم يوجد حد أو سعر.',
    reason: 'سبب التغيير (يُحفظ في السجل)',
    save: 'حفظ السياسة',
    held: 'طلبات بانتظار التحقق',
    atEmail: 'عند البريد', atMobile: 'عند الجوال',
    unverified: 'طلبات بلا بريد مؤكد خلال 24 ساعة',
    since: 'يبدأ العد من',
    noCount: 'لم تُسجَّل أي محاولة إرسال بعد.',
    audit: 'سجل التغييرات',
    noAudit: 'لم تتغير السياسة بعد: القيم الافتراضية الآمنة سارية.',
    changed: (who: number | null) => `المسؤول رقم ${who ?? '—'}`,
  },
  en: {
    title: 'Registration & verification policy',
    intro: 'These settings decide what NEW applications are asked to prove. Existing applications keep their policy, and one can be re-evaluated from Registration requests. Payment and approval are not affected, and receipts, security alerts and account recovery messages are not turned off.',
    email: 'Email ownership verification',
    emailOn: 'A confirmation link is sent, and the application waits for it.',
    emailOff: 'No link is sent. The address stays unverified, and cannot recover the account or sign in without a password.',
    mobile: 'Phone / SMS OTP verification',
    mobileOn: 'A code is sent, and the application waits for it. The form requires a mobile number.',
    mobileOff: 'No code is sent, and no number is required for it. Any number stays unverified and cannot sign in.',
    enabled: 'On', disabled: 'Off',
    riskOne: 'Turning this off lets applications open with an address or number nobody proved they hold.',
    riskBoth: 'With both off, opening applications costs a script nothing. Payment and approval still apply, and the sign-up limits below take over.',
    confirm: 'I understand the risk and take this decision.',
    gate: 'Gate', provider: 'Delivery provider', configured: 'Configured', notConfigured: 'Not configured (nothing is really sent)',
    last24: 'Last 24 hours', attempted: 'attempted', delivered: 'delivered', blocked: 'refused by a limit',
    delivered30: 'Delivered in 30 days',
    cost: 'Estimated cost, 30 days', unknownCost: 'Unknown: no unit price entered',
    costNote: 'An estimate: messages delivered × the price you enter from the provider invoice.',
    cap: 'Daily cap', noCap: 'No cap',
    alert: { ok: 'Within the cap', near_cap: 'Near the cap', at_cap: 'At the cap: nothing is sent', no_cap: 'No cap set' },
    limits: 'Limits and cost',
    emailCap: 'Email daily cap', smsCap: 'SMS daily cap', perApp: 'Daily cap per application',
    emailCost: 'Price per email', smsCost: 'Price per SMS', currency: 'Currency (such as USD)',
    alertPct: 'Alert at % of the cap', ceiling: 'Unverified applications per day (applies only with email off)',
    emptyHint: 'Leave a field empty when there is no cap or price.',
    reason: 'Why (kept in the record)',
    save: 'Save policy',
    held: 'Applications waiting on verification',
    atEmail: 'At email', atMobile: 'At mobile',
    unverified: 'Applications with no proved address, last 24 hours',
    since: 'Counted since',
    noCount: 'No send attempt has been recorded yet.',
    audit: 'Change history',
    noAudit: 'The policy has not been changed: the secure defaults are in force.',
    changed: (who: number | null) => `Administrator #${who ?? '—'}`,
  },
} as const

type Copy = typeof COPY['en'] | typeof COPY['ar']

type Draft = Record<
  | 'email_daily_cap' | 'sms_daily_cap' | 'per_application_daily_cap' | 'email_unit_cost' | 'sms_unit_cost'
  | 'cost_currency' | 'budget_alert_percent' | 'unverified_signups_daily_cap',
  string
>

const toDraft = (p: RegistrationVerificationPolicy): Draft => ({
  email_daily_cap: p.email_daily_cap?.toString() ?? '',
  sms_daily_cap: p.sms_daily_cap?.toString() ?? '',
  per_application_daily_cap: p.per_application_daily_cap?.toString() ?? '',
  email_unit_cost: p.email_unit_cost ?? '',
  sms_unit_cost: p.sms_unit_cost ?? '',
  cost_currency: p.cost_currency ?? '',
  budget_alert_percent: String(p.budget_alert_percent),
  unverified_signups_daily_cap: String(p.unverified_signups_daily_cap),
})

const intOrNull = (v: string) => (v.trim() === '' ? null : Number(v))
const textOrNull = (v: string) => (v.trim() === '' ? null : v.trim())

export function RegistrationPolicyTab() {
  const ar = useUi((s) => s.locale) === 'ar'
  const c = COPY[ar ? 'ar' : 'en']
  const queryClient = useQueryClient()

  const overview = useQuery({ queryKey: ['admin', 'registration-verification-policy'], queryFn: fetchRegistrationVerificationPolicy })

  const [email, setEmail] = useState(true)
  const [mobile, setMobile] = useState(true)
  const [draft, setDraft] = useState<Draft | null>(null)
  const [reason, setReason] = useState('')
  const [confirmed, setConfirmed] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const policy = overview.data?.policy

  useEffect(() => {
    if (!policy) return
    setEmail(policy.email_enabled)
    setMobile(policy.mobile_enabled)
    setDraft(toDraft(policy))
    setConfirmed(false)
    setReason('')
  }, [policy])

  const save = useMutation({
    mutationFn: () => saveRegistrationVerificationPolicy({
      email_enabled: email,
      mobile_enabled: mobile,
      email_daily_cap: intOrNull(draft!.email_daily_cap),
      sms_daily_cap: intOrNull(draft!.sms_daily_cap),
      per_application_daily_cap: intOrNull(draft!.per_application_daily_cap),
      email_unit_cost: textOrNull(draft!.email_unit_cost),
      sms_unit_cost: textOrNull(draft!.sms_unit_cost),
      cost_currency: textOrNull(draft!.cost_currency),
      budget_alert_percent: Number(draft!.budget_alert_percent),
      unverified_signups_daily_cap: Number(draft!.unverified_signups_daily_cap),
      reason,
      expected_version: policy!.version,
      confirm_risk: confirmed,
    }),
    onSuccess: (next) => {
      queryClient.setQueryData(['admin', 'registration-verification-policy'], next)
      setError(null)
    },
    onError: (e: unknown) => {
      setError(toApiError(e).message)
      // A refused save may be somebody else's newer decision; show it rather than the stale one.
      void queryClient.invalidateQueries({ queryKey: ['admin', 'registration-verification-policy'] })
    },
  })

  if (overview.isError) {
    return <p data-testid="verification-policy-error" className="rounded-xl bg-[var(--negative-background)] px-4 py-3 text-sm text-danger">{toApiError(overview.error).message}</p>
  }
  if (!overview.data || !policy || !draft) {
    return <div className="flex justify-center p-8"><Loader2 className="animate-spin text-brand-600" /></div>
  }

  const turningOff = (policy.email_enabled && !email) || (policy.mobile_enabled && !mobile)
  const bothOff = !email && !mobile
  const blocked = reason.trim().length < 5 || (turningOff && !confirmed)

  const field = (key: keyof Draft, label: string, inputMode: 'numeric' | 'decimal' | 'text' = 'numeric') => (
    <label className="flex min-w-0 flex-col gap-1 text-xs font-semibold text-text-secondary">
      {label}
      <input
        data-testid={`policy-${key}`}
        dir="ltr"
        inputMode={inputMode}
        value={draft[key]}
        onChange={(e) => setDraft({ ...draft, [key]: e.target.value })}
        className="h-10 w-full min-w-0 rounded-xl border border-border bg-surface px-3 text-sm text-text-primary"
      />
    </label>
  )

  return (
    <div data-testid="verification-policy" className="flex flex-col gap-5">
      <header>
        <h2 className="font-heading text-lg font-extrabold text-text-primary">{c.title}</h2>
        <p className="mt-1 max-w-3xl text-sm text-text-secondary">{c.intro}</p>
      </header>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <ChannelCard
          testId="email" title={c.email} on={email} onChange={setEmail}
          hint={email ? c.emailOn : c.emailOff} channel={overview.data.channels.email} copy={c} ar={ar}
        />
        <ChannelCard
          testId="mobile" title={c.mobile} on={mobile} onChange={setMobile}
          hint={mobile ? c.mobileOn : c.mobileOff} channel={overview.data.channels.mobile} copy={c} ar={ar}
        />
      </div>

      <div className="rounded-2xl border border-border bg-surface p-4 text-sm text-text-secondary">
        <p data-testid="policy-held">
          {c.held}: {c.atEmail} <span dir="ltr">{overview.data.open_applications.at_email_gate}</span> · {c.atMobile}{' '}
          <span dir="ltr">{overview.data.open_applications.at_mobile_gate}</span>
        </p>
        <p className="mt-1">{c.unverified}: <span dir="ltr">{overview.data.unverified_signups_last_24h}</span></p>
        <p className="mt-1 text-xs text-text-muted">
          {overview.data.counted_since ? <>{c.since} <span dir="ltr">{overview.data.counted_since.slice(0, 10)}</span></> : c.noCount}
        </p>
      </div>

      <section className="rounded-2xl border border-border bg-surface p-4">
        <h3 className="font-semibold text-text-primary">{c.limits}</h3>
        <p className="mt-0.5 text-xs text-text-muted">{c.emptyHint} {c.costNote}</p>
        <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
          {field('email_daily_cap', c.emailCap)}
          {field('sms_daily_cap', c.smsCap)}
          {field('per_application_daily_cap', c.perApp)}
          {field('budget_alert_percent', c.alertPct)}
          {field('email_unit_cost', c.emailCost, 'decimal')}
          {field('sms_unit_cost', c.smsCost, 'decimal')}
          {field('cost_currency', c.currency, 'text')}
          {field('unverified_signups_daily_cap', c.ceiling)}
        </div>
      </section>

      {turningOff && (
        <div data-testid="policy-risk" className="flex flex-col gap-2 rounded-2xl border border-warning/40 bg-warning/10 p-4 text-sm text-text-primary">
          <p className="flex items-start gap-2"><AlertTriangle size={16} className="mt-0.5 shrink-0" />{bothOff ? c.riskBoth : c.riskOne}</p>
          <label className="flex items-center gap-2 font-semibold">
            <input type="checkbox" data-testid="policy-confirm" checked={confirmed} onChange={(e) => setConfirmed(e.target.checked)} />
            {c.confirm}
          </label>
        </div>
      )}

      <div className="flex flex-col gap-2">
        <label className="flex flex-col gap-1 text-xs font-semibold text-text-secondary">
          {c.reason}
          <input
            data-testid="policy-reason"
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            className="h-10 rounded-xl border border-border bg-surface px-3 text-sm text-text-primary"
          />
        </label>
        <div>
          <Button data-testid="policy-save" onClick={() => save.mutate()} loading={save.isPending} disabled={blocked} size="sm">{c.save}</Button>
        </div>
        {error && <p data-testid="policy-save-error" className="rounded-xl bg-[var(--negative-background)] px-3.5 py-2.5 text-sm text-danger">{error}</p>}
      </div>

      <section>
        <h3 className="mb-2 font-semibold text-text-primary">{c.audit}</h3>
        {overview.data.changes.length === 0
          ? <p data-testid="policy-no-audit" className="text-sm text-text-secondary">{c.noAudit}</p>
          : (
            <ol data-testid="policy-audit" className="flex flex-col gap-1.5 text-xs text-text-secondary">
              {overview.data.changes.map((entry, i) => (
                <li key={`${entry.at}-${i}`} className="flex flex-wrap gap-1.5">
                  <span dir="ltr">{entry.at?.slice(0, 16).replace('T', ' ')}</span>
                  <span className="font-semibold text-text-primary">{c.changed(entry.user_id)}</span>
                  <span dir="ltr">
                    email {String(entry.before?.email_enabled)} → {String(entry.after?.email_enabled)}, sms {String(entry.before?.mobile_enabled)} → {String(entry.after?.mobile_enabled)}
                  </span>
                  {entry.reason && <span className="text-text-muted">— {entry.reason}</span>}
                </li>
              ))}
            </ol>
          )}
      </section>
    </div>
  )
}

function ChannelCard({
  testId, title, on, onChange, hint, channel, copy, ar,
}: {
  testId: string
  title: string
  on: boolean
  onChange: (next: boolean) => void
  hint: string
  channel: RegistrationVerificationChannel
  copy: Copy
  ar: boolean
}) {
  const number = (n: number) => new Intl.NumberFormat(ar ? 'ar-u-nu-latn' : 'en-US').format(n)

  return (
    <section data-testid={`policy-channel-${testId}`} className="flex min-w-0 flex-col gap-3 rounded-2xl border border-border bg-surface p-4">
      <label className="flex items-center justify-between gap-3">
        <span className="min-w-0 font-semibold text-text-primary">{title}</span>
        <span className="flex shrink-0 items-center gap-2 text-sm text-text-secondary">
          {on ? copy.enabled : copy.disabled}
          <input
            type="checkbox"
            role="switch"
            data-testid={`policy-switch-${testId}`}
            checked={on}
            onChange={(e) => onChange(e.target.checked)}
          />
        </span>
      </label>
      <p className="text-sm text-text-secondary">{hint}</p>
      <dl className="grid grid-cols-1 gap-1 text-xs text-text-secondary">
        <div data-testid={`policy-provider-${testId}`} data-provider={channel.provider} className="flex flex-wrap justify-between gap-2">
          <dt>{copy.provider}</dt>
          <dd className={channel.provider === 'configured' ? '' : 'font-semibold text-danger'}>
            {channel.provider === 'configured' ? copy.configured : copy.notConfigured}
          </dd>
        </div>
        <div className="flex flex-wrap justify-between gap-2">
          <dt>{copy.last24}</dt>
          <dd>
            <Num>{number(channel.last_24h.attempted)}</Num> {copy.attempted} · <Num>{number(channel.last_24h.delivered)}</Num> {copy.delivered} · <Num>{number(channel.last_24h.blocked)}</Num> {copy.blocked}
          </dd>
        </div>
        <div className="flex flex-wrap justify-between gap-2"><dt>{copy.delivered30}</dt><dd><Num>{number(channel.delivered_30d)}</Num></dd></div>
        <div className="flex flex-wrap justify-between gap-2">
          <dt>{copy.cap}</dt>
          <dd data-testid={`policy-alert-${testId}`} data-alert={channel.alert} className={channel.alert === 'at_cap' || channel.alert === 'near_cap' ? 'font-semibold text-danger' : ''}>
            {channel.cap === null ? copy.noCap : <><span dir="ltr">{number(channel.cap)}</span> · {copy.alert[channel.alert]}</>}
          </dd>
        </div>
        <div className="flex flex-wrap justify-between gap-2">
          <dt>{copy.cost}</dt>
          <dd data-testid={`policy-cost-${testId}`}>
            {channel.cost_known ? <span dir="ltr">{channel.estimated_cost_30d} {channel.currency}</span> : copy.unknownCost}
          </dd>
        </div>
      </dl>
    </section>
  )
}

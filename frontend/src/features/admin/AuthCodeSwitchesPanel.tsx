import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, Loader2 } from 'lucide-react'
import {
  fetchAuthCodeSwitches, saveAuthCodeSwitches,
  type AuthCodeChannel, type AuthCodeSwitchKey,
} from './api'
import { Button } from '@/components/ui/Button'
import { Num } from '@/components/ui/Num'
import { toApiError } from '@/lib/api/client'
import { useUi } from '@/stores/ui'

/**
 * One-time codes at sign-in and recovery (AUTH-CODE-SWITCHES-001, Owner §14).
 *
 * Signup has its own switches on the tab above. These cover what is left, grouped by who is signing in, because a client contact holds
 * no password and a platform account does. The page states what is not here: password, Google and the reset link stay on whatever these
 * say. The server refuses a combination that would strand somebody, and this page shows its reason in its words.
 */

const COPY = {
  ar: {
    title: 'رموز التحقق عند الدخول والاستعادة',
    intro: 'تعطيل قناة هنا يوقف إنشاء الرمز واستدعاء مزوّد الإرسال فعليًا، ويبطل أي رمز صادر لم يُستخدم. الدخول بالبريد وكلمة المرور وGoogle ورابط استعادة كلمة المرور لا تتأثر بها.',
    groups: { accounts: 'حسابات المنصة', contacts: 'جهات اتصال العملاء (بلا كلمة مرور)', recovery: 'استعادة الوصول' },
    switches: {
      sign_in_email_code: ['رمز عبر البريد', 'يدخل صاحب الحساب برمز يصل إلى بريده.', 'لا يُرسل رمز. يبقى الدخول بكلمة المرور وGoogle.'],
      sign_in_sms_code: ['رمز SMS', 'يدخل صاحب الرقم الموثّق برمز SMS.', 'لا يُرسل رمز. يبقى الدخول بكلمة المرور وGoogle.'],
      portal_email_code: ['رمز عبر البريد', 'تدخل جهة الاتصال برمز يصل إلى بريدها.', 'لا يُرسل رمز لجهات الاتصال.'],
      portal_sms_code: ['رمز SMS', 'تدخل جهة الاتصال برمز يصل إلى جوالها.', 'لا يُرسل رمز لجهات الاتصال.'],
      recovery_sms_code: ['إثبات الجوال برمز SMS', 'يثبت المستخدم رقمه من إعدادات الحساب ليصبح وسيلة عودة.', 'لا يُرسل رمز. تبقى الأرقام الموثقة كما هي.'],
    },
    link: 'رابط استعادة كلمة المرور',
    linkFixed: 'يعمل دائمًا ولا يُعطَّل من هنا. يبقى رابطًا إلى أن يحسم المالك: رابط أم رمز.',
    enabled: 'مفعّل', disabled: 'معطّل',
    provider: 'مزوّد الإرسال',
    providerState: { configured: 'مهيأ', not_configured: 'غير مهيأ (لا يُرسل شيء فعليًا)', failing: 'يفشل حاليًا' },
    last24: 'آخر 24 ساعة', issued: 'رموز', sent: 'أُرسلت',
    sent30: 'أُرسلت خلال 30 يومًا',
    cost: 'التكلفة التقديرية خلال 30 يومًا', unknownCost: 'غير معروفة: لم يُدخل سعر الوحدة',
    stranded: (n: number) => `${n} من جهات الاتصال لا تملك الآن أي طريقة دخول. أعد تفعيل القناة أو أضف لها وسيلة أخرى.`,
    limits: 'الضوابط السارية',
    ttl: (n: number) => `صلاحية الرمز ${n} دقيقة`,
    attempts: (n: number) => `${n} محاولات لكل رمز`,
    resend: (n: number) => `إعادة الإرسال بعد ${n} ثانية`,
    risk: 'تعطيل هذا الرمز يحجب طريقة دخول عن من يستخدمها. يرفض النظام أي تركيبة تترك أحدًا بلا طريقة دخول أو استعادة.',
    confirm: 'أفهم المخاطر وأتحمل هذا القرار.',
    reason: 'سبب التغيير (يُحفظ في السجل)',
    save: 'حفظ',
    audit: 'سجل التغييرات',
    noAudit: 'لم تتغير هذه الإعدادات بعد: كل القنوات مفعّلة كما هي اليوم.',
    who: (id: number | null) => `المسؤول رقم ${id ?? '—'}`,
  },
  en: {
    title: 'Codes at sign-in and recovery',
    intro: 'Turning a channel off here stops the code being created and the provider being called, and voids any code not yet used. Email and password, Google and the password reset link are not affected.',
    groups: { accounts: 'Platform accounts', contacts: 'Client contacts (no password)', recovery: 'Account recovery' },
    switches: {
      sign_in_email_code: ['Email code', 'The account holder signs in with a code sent to their email.', 'No code is sent. Password and Google remain.'],
      sign_in_sms_code: ['SMS code', 'The holder of a confirmed number signs in with an SMS code.', 'No code is sent. Password and Google remain.'],
      portal_email_code: ['Email code', 'A contact signs in with a code sent to their email.', 'No code is sent to contacts.'],
      portal_sms_code: ['SMS code', 'A contact signs in with a code sent to their phone.', 'No code is sent to contacts.'],
      recovery_sms_code: ['Prove a number by SMS', 'A user proves their number from account settings, making it a way back.', 'No code is sent. Numbers already confirmed stay confirmed.'],
    },
    link: 'Password reset link',
    linkFixed: 'Always works and cannot be turned off here. It stays a link until the Owner decides between a link and a code.',
    enabled: 'On', disabled: 'Off',
    provider: 'Delivery provider',
    providerState: { configured: 'Configured', not_configured: 'Not configured (nothing is really sent)', failing: 'Failing now' },
    last24: 'Last 24 hours', issued: 'codes', sent: 'sent',
    sent30: 'Sent in 30 days',
    cost: 'Estimated cost, 30 days', unknownCost: 'Unknown: no unit price entered',
    stranded: (n: number) => `${n} contact(s) have no way in right now. Turn the channel back on or give them another one.`,
    limits: 'Limits in force',
    ttl: (n: number) => `A code lasts ${n} minutes`,
    attempts: (n: number) => `${n} attempts per code`,
    resend: (n: number) => `Resend after ${n} seconds`,
    risk: 'Turning this code off takes a way in from whoever uses it. The system refuses any combination that leaves somebody with no way in or no way back.',
    confirm: 'I understand the risk and take this decision.',
    reason: 'Why (kept in the record)',
    save: 'Save',
    audit: 'Change history',
    noAudit: 'These settings have not been changed: every channel is on, as it is today.',
    who: (id: number | null) => `Administrator #${id ?? '—'}`,
  },
} as const

const GROUPS: { key: 'accounts' | 'contacts' | 'recovery'; switches: AuthCodeSwitchKey[] }[] = [
  { key: 'accounts', switches: ['sign_in_email_code', 'sign_in_sms_code'] },
  { key: 'contacts', switches: ['portal_email_code', 'portal_sms_code'] },
  { key: 'recovery', switches: ['recovery_sms_code'] },
]

const ALL_SWITCHES = GROUPS.flatMap((group) => group.switches)

type Draft = Record<AuthCodeSwitchKey, boolean>

export function AuthCodeSwitchesPanel() {
  const ar = useUi((s) => s.locale) === 'ar'
  const c = COPY[ar ? 'ar' : 'en']
  const queryClient = useQueryClient()
  const key = ['admin', 'auth-code-switches']

  const overview = useQuery({ queryKey: key, queryFn: fetchAuthCodeSwitches })
  const saved = overview.data?.switches

  const [draft, setDraft] = useState<Draft | null>(null)
  const [reason, setReason] = useState('')
  const [confirmed, setConfirmed] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (!saved) return
    setDraft(Object.fromEntries(ALL_SWITCHES.map((name) => [name, saved[name]])) as Draft)
    setReason('')
    setConfirmed(false)
  }, [saved])

  const save = useMutation({
    mutationFn: () => saveAuthCodeSwitches({ ...draft!, reason, expected_version: saved!.version, confirm_risk: confirmed }),
    onSuccess: (next) => {
      queryClient.setQueryData(key, next)
      setError(null)
    },
    onError: (e: unknown) => {
      const failure = toApiError(e)
      // A refusal names who would be stranded, and that sentence is the point of it.
      setError(Object.values(failure.errors ?? {}).flat()[0] ?? failure.message)
      void queryClient.invalidateQueries({ queryKey: key })
    },
  })

  if (overview.isError) {
    return <p data-testid="code-switches-error" className="rounded-xl bg-[var(--negative-background)] px-4 py-3 text-sm text-danger">{toApiError(overview.error).message}</p>
  }
  if (!overview.data || !saved || !draft) {
    return <div className="flex justify-center p-8"><Loader2 className="animate-spin text-brand-600" /></div>
  }

  const { channels, limits, lockout, changes } = overview.data
  const turningOff = ALL_SWITCHES.some((name) => saved[name] && !draft[name])
  const changed = ALL_SWITCHES.some((name) => saved[name] !== draft[name])
  const blocked = !changed || reason.trim().length < 5 || (turningOff && !confirmed)

  return (
    <div data-testid="code-switches" className="mt-8 flex flex-col gap-5 border-t border-border pt-6">
      <header>
        <h2 className="font-heading text-lg font-extrabold text-text-primary">{c.title}</h2>
        <p className="mt-1 max-w-3xl text-sm text-text-secondary">{c.intro}</p>
      </header>

      {lockout.stranded_contacts > 0 && (
        <p data-testid="code-switches-stranded" className="flex items-start gap-2 rounded-2xl border border-warning/40 bg-warning/10 p-4 text-sm text-text-primary">
          <AlertTriangle size={16} className="mt-0.5 shrink-0" />{c.stranded(lockout.stranded_contacts)}
        </p>
      )}

      {GROUPS.map((group) => (
        <section key={group.key} data-testid={`code-group-${group.key}`} className="flex flex-col gap-3">
          <h3 className="font-semibold text-text-primary">{c.groups[group.key]}</h3>
          <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
            {group.switches.map((name) => (
              <SwitchCard
                key={name}
                name={name}
                on={draft[name]}
                onChange={(next) => setDraft({ ...draft, [name]: next })}
                channel={channels[name]}
                ar={ar}
              />
            ))}
            {group.key === 'recovery' && (
              <div data-testid="code-recovery-link" className="flex min-w-0 flex-col gap-2 rounded-2xl border border-border bg-surface p-4">
                <div className="flex items-center justify-between gap-3">
                  <span className="min-w-0 font-semibold text-text-primary">{c.link}</span>
                  <span className="shrink-0 text-sm text-text-secondary">{c.enabled}</span>
                </div>
                <p className="text-sm text-text-secondary">{c.linkFixed}</p>
              </div>
            )}
          </div>
        </section>
      ))}

      <p data-testid="code-limits" className="rounded-2xl border border-border bg-surface p-4 text-sm text-text-secondary">
        <span className="font-semibold text-text-primary">{c.limits}: </span>
        {c.ttl(limits.code_ttl_minutes)} · {c.attempts(limits.max_attempts)} · {c.resend(limits.resend_cooldown_seconds)}
      </p>

      {turningOff && (
        <div data-testid="code-switches-risk" className="flex flex-col gap-2 rounded-2xl border border-warning/40 bg-warning/10 p-4 text-sm text-text-primary">
          <p className="flex items-start gap-2"><AlertTriangle size={16} className="mt-0.5 shrink-0" />{c.risk}</p>
          <label className="flex items-center gap-2 font-semibold">
            <input type="checkbox" data-testid="code-switches-confirm" checked={confirmed} onChange={(e) => setConfirmed(e.target.checked)} />
            {c.confirm}
          </label>
        </div>
      )}

      <div className="flex flex-col gap-2">
        <label className="flex flex-col gap-1 text-xs font-semibold text-text-secondary">
          {c.reason}
          <input
            data-testid="code-switches-reason"
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            className="h-10 rounded-xl border border-border bg-surface px-3 text-sm text-text-primary"
          />
        </label>
        <div>
          <Button data-testid="code-switches-save" onClick={() => save.mutate()} loading={save.isPending} disabled={blocked} size="sm">{c.save}</Button>
        </div>
        {error && <p data-testid="code-switches-save-error" className="rounded-xl bg-[var(--negative-background)] px-3.5 py-2.5 text-sm text-danger">{error}</p>}
      </div>

      <section>
        <h3 className="mb-2 font-semibold text-text-primary">{c.audit}</h3>
        {changes.length === 0
          ? <p data-testid="code-switches-no-audit" className="text-sm text-text-secondary">{c.noAudit}</p>
          : (
            <ol data-testid="code-switches-audit" className="flex flex-col gap-1.5 text-xs text-text-secondary">
              {changes.map((entry, i) => (
                <li key={`${entry.at}-${i}`} className="flex flex-wrap gap-1.5">
                  <span dir="ltr">{entry.at?.slice(0, 16).replace('T', ' ')}</span>
                  <span className="font-semibold text-text-primary">{c.who(entry.user_id)}</span>
                  <span dir="ltr">
                    {ALL_SWITCHES.filter((name) => entry.before?.[name] !== entry.after?.[name])
                      .map((name) => `${name} ${String(entry.before?.[name])} → ${String(entry.after?.[name])}`).join(', ')}
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

function SwitchCard({ name, on, onChange, channel, ar }: {
  name: AuthCodeSwitchKey
  on: boolean
  onChange: (next: boolean) => void
  channel: AuthCodeChannel
  ar: boolean
}) {
  const c = COPY[ar ? 'ar' : 'en']
  const [title, onHint, offHint] = c.switches[name]
  const number = (n: number) => new Intl.NumberFormat(ar ? 'ar-u-nu-latn' : 'en-US').format(n)

  return (
    <section data-testid={`code-channel-${name}`} className="flex min-w-0 flex-col gap-3 rounded-2xl border border-border bg-surface p-4">
      <label className="flex items-center justify-between gap-3">
        <span className="min-w-0 font-semibold text-text-primary">{title}</span>
        <span className="flex shrink-0 items-center gap-2 text-sm text-text-secondary">
          {on ? c.enabled : c.disabled}
          <input type="checkbox" role="switch" data-testid={`code-switch-${name}`} checked={on} onChange={(e) => onChange(e.target.checked)} />
        </span>
      </label>
      <p className="text-sm text-text-secondary">{on ? onHint : offHint}</p>
      <dl className="grid grid-cols-1 gap-1 text-xs text-text-secondary">
        <div data-testid={`code-provider-${name}`} data-provider={channel.provider} className="flex flex-wrap justify-between gap-2">
          <dt>{c.provider}</dt>
          <dd className={channel.provider === 'configured' ? '' : 'font-semibold text-danger'}>{c.providerState[channel.provider]}</dd>
        </div>
        <div className="flex flex-wrap justify-between gap-2">
          <dt>{c.last24}</dt>
          <dd><Num>{number(channel.last_24h.issued)}</Num> {c.issued} · <Num>{number(channel.last_24h.sent)}</Num> {c.sent}</dd>
        </div>
        <div className="flex flex-wrap justify-between gap-2"><dt>{c.sent30}</dt><dd><Num>{number(channel.sent_30d)}</Num></dd></div>
        <div className="flex flex-wrap justify-between gap-2">
          <dt>{c.cost}</dt>
          <dd data-testid={`code-cost-${name}`}>
            {channel.cost_known ? <span dir="ltr">{channel.estimated_cost_30d} {channel.currency}</span> : c.unknownCost}
          </dd>
        </div>
      </dl>
    </section>
  )
}

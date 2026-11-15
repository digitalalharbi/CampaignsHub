import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Mail, MessageSquare, ShieldCheck, Smartphone } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { deleteData, getData, postData, toApiError } from '@/lib/api/client'
import { OtpField } from '@/features/auth/OtpField'
import { qrModules } from '@/features/shortLinks/ShortLinkQr'
import { useUi } from '@/stores/ui'

/**
 * TWO-STEP-001 — the second step, one screen: three method cards with a status chip each.
 *
 * What the switch used to be was one TOTP toggle whose flag nothing at sign-in read. Each card here is a method the sign-in
 * really asks for. Turning the first one on shows ten recovery codes once; turning methods off asks for the password again.
 */
type Method = 'totp' | 'email' | 'sms'

interface Status {
  enabled: boolean
  default: Method | null
  recovery_codes_left: number
  methods: {
    totp: { active: boolean; available: boolean }
    email: { active: boolean; available: boolean; hint: string }
    sms: { active: boolean; available: boolean; provider_ready: boolean; phone_confirmed: boolean; hint: string | null }
  }
  recovery_codes?: string[] | null
}

type Confirm = { kind: 'remove'; method: Method } | { kind: 'disable' } | { kind: 'recovery' }

const COPY = {
  ar: {
    title: 'التحقق بخطوتين',
    on: 'مفعّل',
    off: 'غير مفعّل',
    totp: 'تطبيق المصادقة',
    email: 'البريد الإلكتروني',
    sms: 'الرسائل النصية',
    enable: 'تفعيل',
    remove: 'إيقاف',
    makeDefault: 'اجعلها الافتراضية',
    isDefault: 'الافتراضية',
    awaitingSms: 'بانتظار ربط مزوّد الرسائل',
    confirmEmail: 'أكّد بريدك أولًا',
    confirmPhone: 'أكّد رقم جوالك أولًا',
    scan: 'امسح الرمز أو أدخل المفتاح',
    code: 'الرمز',
    confirm: 'تأكيد',
    cancel: 'إلغاء',
    send: 'أرسل الرمز',
    recoveryTitle: 'رموز احتياطية',
    recoveryOnce: 'احفظها الآن. لن تظهر مرة أخرى.',
    recoveryLeft: (n: number) => `${n} متبقية`,
    recoveryNew: 'رموز جديدة',
    copy: 'نسخ',
    copied: 'تم النسخ',
    disableAll: 'إيقاف التحقق بخطوتين',
    password: 'كلمة المرور',
  },
  en: {
    title: 'Two-step verification',
    on: 'On',
    off: 'Off',
    totp: 'Authenticator app',
    email: 'Email',
    sms: 'Text messages',
    enable: 'Turn on',
    remove: 'Turn off',
    makeDefault: 'Make default',
    isDefault: 'Default',
    awaitingSms: 'Awaiting an SMS provider',
    confirmEmail: 'Confirm your email first',
    confirmPhone: 'Confirm your mobile first',
    scan: 'Scan the code or enter the key',
    code: 'Code',
    confirm: 'Confirm',
    cancel: 'Cancel',
    send: 'Send the code',
    recoveryTitle: 'Recovery codes',
    recoveryOnce: 'Save them now. They will not be shown again.',
    recoveryLeft: (n: number) => `${n} left`,
    recoveryNew: 'New codes',
    copy: 'Copy',
    copied: 'Copied',
    disableAll: 'Turn off two-step verification',
    password: 'Password',
  },
} as const

const ICONS = { totp: Smartphone, email: Mail, sms: MessageSquare } as const
const KEY = ['me', 'two-factor'] as const

function Chip({ tone, children, testId }: { tone: 'on' | 'off' | 'wait'; children: React.ReactNode; testId?: string }) {
  const cls = tone === 'on' ? 'bg-success/15 text-success' : tone === 'wait' ? 'bg-warning/15 text-warning' : 'bg-surface-secondary text-text-muted'
  return <span data-testid={testId} className={`inline-flex shrink-0 items-center rounded-full px-2.5 py-1 text-[11px] font-semibold ${cls}`}>{children}</span>
}

function Qr({ text }: { text: string }) {
  const modules = useMemo(() => qrModules(text), [text])
  const n = modules.length
  return (
    <svg viewBox={`-2 -2 ${n + 4} ${n + 4}`} className="h-36 w-36 rounded-lg bg-white" shapeRendering="crispEdges" aria-hidden="true">
      {modules.flatMap((row, r) => row.map((dark, c) => (dark ? <rect key={`${r}-${c}`} x={c} y={r} width={1} height={1} fill="#000" /> : null)))}
    </svg>
  )
}

export function TwoStepCard() {
  const ar = useUi((u) => u.locale) === 'ar'
  const c = COPY[ar ? 'ar' : 'en']
  const qc = useQueryClient()
  const status = useQuery({ queryKey: KEY, queryFn: () => getData<Status>('/me/two-factor') })

  const [enrolling, setEnrolling] = useState<Method | null>(null)
  const [setup, setSetup] = useState<{ secret: string; otpauth_uri: string } | null>(null)
  const [code, setCode] = useState('')
  const [codes, setCodes] = useState<string[] | null>(null)
  const [copied, setCopied] = useState(false)
  const [confirm, setConfirm] = useState<Confirm | null>(null)
  const [password, setPassword] = useState('')

  const settle = (next: Status) => {
    qc.setQueryData(KEY, next)
    if (next.recovery_codes) setCodes(next.recovery_codes)
    setEnrolling(null)
    setSetup(null)
    setCode('')
  }

  const start = useMutation({ meta: { feedback: 'none' },
    mutationFn: async (m: Method) => {
      if (m === 'totp') return { totp: await postData<{ secret: string; otpauth_uri: string }>('/me/two-factor/totp/setup') }
      return { channel: await postData<{ dev_code: string | null }>(`/me/two-factor/${m}/start`) }
    },
    onSuccess: (r, m) => {
      setEnrolling(m)
      setCode('')
      if ('totp' in r && r.totp) setSetup(r.totp)
      // Non-production only: the server hard-gates `dev_code`.
      if ('channel' in r && r.channel?.dev_code) setCode(r.channel.dev_code)
    },
  })

  const confirmEnrol = useMutation({ meta: { feedback: 'none' },
    mutationFn: (typed: string) => postData<Status>(`/me/two-factor/${enrolling === 'totp' ? 'totp' : enrolling}/confirm`, { code: typed }),
    onSuccess: settle,
  })

  const makeDefault = useMutation({ meta: { feedback: 'none' },
    mutationFn: (m: Method) => postData<Status>('/me/two-factor/default', { method: m }),
    onSuccess: (next) => qc.setQueryData(KEY, next),
  })

  const act = useMutation({ meta: { feedback: 'none' },
    mutationFn: async (what: Confirm) => {
      if (what.kind === 'remove') return { status: await deleteData<Status>(`/me/two-factor/methods/${what.method}`, { password }) }
      if (what.kind === 'disable') return { status: await postData<Status>('/me/two-factor/disable', { password }) }
      return { codes: (await postData<{ recovery_codes: string[] }>('/me/two-factor/recovery-codes', { password })).recovery_codes }
    },
    onSuccess: (r) => {
      if (r.status) qc.setQueryData(KEY, r.status)
      if (r.codes) setCodes(r.codes)
      setConfirm(null)
      setPassword('')
    },
  })

  const s = status.data
  const error = start.isError ? toApiError(start.error).message
    : confirmEnrol.isError ? toApiError(confirmEnrol.error).message
      : makeDefault.isError ? toApiError(makeDefault.error).message : null

  const submitCode = (typed?: string) => {
    const value = (typed ?? code).trim()
    if (value.length === 6 && !confirmEnrol.isPending) confirmEnrol.mutate(value)
  }

  return (
    <section data-testid="two-step-card" className="rounded-2xl border border-border bg-surface p-5">
      <div className="mb-4 flex items-center gap-3">
        <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-surface-secondary text-text-primary"><ShieldCheck size={18} /></div>
        <h2 className="text-sm font-bold text-text-primary">{c.title}</h2>
        {s && <span className="ms-auto"><Chip tone={s.enabled ? 'on' : 'off'} testId="two-step-status">{s.enabled ? c.on : c.off}</Chip></span>}
      </div>

      {status.isLoading && <div className="h-28 animate-pulse rounded-xl bg-surface-secondary" />}

      {s && (
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
          {(['totp', 'email', 'sms'] as const).map((m) => {
            const Icon = ICONS[m]
            const info = s.methods[m]
            const waiting = m === 'sms' && !s.methods.sms.provider_ready
            const blocked = !info.active && !info.available
            const blockedLabel = waiting ? c.awaitingSms : m === 'sms' ? c.confirmPhone : c.confirmEmail
            const hint = m === 'email' ? s.methods.email.hint : m === 'sms' ? s.methods.sms.hint : null

            return (
              <div key={m} data-testid={`two-step-card-${m}`} className={`flex min-w-0 flex-col gap-3 rounded-xl border border-border p-4 ${waiting ? 'opacity-70' : ''}`}>
                <div className="flex items-center gap-2">
                  <Icon size={16} className="shrink-0 text-text-secondary" />
                  <span className="min-w-0 truncate text-sm font-semibold text-text-primary">{c[m]}</span>
                </div>
                <div className="flex flex-wrap items-center gap-1.5">
                  {blocked
                    ? <Chip tone="wait" testId={`two-step-chip-${m}`}>{blockedLabel}</Chip>
                    : <Chip tone={info.active ? 'on' : 'off'} testId={`two-step-chip-${m}`}>{info.active ? c.on : c.off}</Chip>}
                  {s.default === m && info.active && <Chip tone="on">{c.isDefault}</Chip>}
                </div>
                {hint && <span className="truncate text-xs text-text-muted" dir="ltr">{hint}</span>}
                <div className="mt-auto flex flex-wrap gap-2">
                  {!info.active && (
                    <Button size="sm" data-testid={`two-step-enable-${m}`} disabled={blocked} loading={start.isPending && start.variables === m} onClick={() => start.mutate(m)}>
                      {c.enable}
                    </Button>
                  )}
                  {info.active && s.default !== m && (
                    <Button size="sm" variant="secondary" onClick={() => makeDefault.mutate(m)}>{c.makeDefault}</Button>
                  )}
                  {info.active && (
                    <Button size="sm" variant="ghost" data-testid={`two-step-remove-${m}`} onClick={() => { setConfirm({ kind: 'remove', method: m }); setPassword('') }}>{c.remove}</Button>
                  )}
                </div>
              </div>
            )
          })}
        </div>
      )}

      {enrolling !== null && (
        <div data-testid="two-step-enrol" className="mt-4 rounded-xl border border-border bg-surface-secondary p-4">
          {enrolling === 'totp' && setup && (
            <div className="mb-3 flex flex-col items-center gap-2 sm:flex-row sm:items-start">
              <Qr text={setup.otpauth_uri} />
              <div className="min-w-0 text-sm text-text-secondary">
                <p className="mb-1">{c.scan}</p>
                <code data-testid="two-step-secret" dir="ltr" className="block break-all rounded bg-surface px-2 py-1 font-mono text-sm tnum">{setup.secret}</code>
              </div>
            </div>
          )}
          <OtpField value={code} onChange={setCode} onComplete={submitCode} label={c.code} autoFocus testId="two-step-enrol-otp" disabled={confirmEnrol.isPending} />
          <div className="mt-3 flex justify-end gap-2">
            <Button variant="ghost" size="sm" onClick={() => { setEnrolling(null); setSetup(null); setCode(''); confirmEnrol.reset() }}>{c.cancel}</Button>
            <Button size="sm" data-testid="two-step-enrol-confirm" loading={confirmEnrol.isPending} onClick={() => submitCode()}>{c.confirm}</Button>
          </div>
        </div>
      )}

      {error && <p role="alert" className="mt-3 text-sm text-danger">{error}</p>}

      {codes && (
        <div data-testid="two-step-recovery-codes" className="mt-4 rounded-xl border border-warning/40 bg-warning/5 p-4">
          <div className="mb-2 flex items-center gap-2">
            <span className="text-sm font-bold text-text-primary">{c.recoveryTitle}</span>
            <span className="text-xs text-text-secondary">{c.recoveryOnce}</span>
          </div>
          <ul className="grid grid-cols-2 gap-1 font-mono text-sm tnum sm:grid-cols-5">
            {codes.map((rc) => <li key={rc}><bdi>{rc}</bdi></li>)}
          </ul>
          <div className="mt-3 flex justify-end gap-2">
            <Button size="sm" variant="secondary" onClick={() => { void navigator.clipboard?.writeText(codes.join('\n')).then(() => setCopied(true)).catch(() => undefined) }}>
              {copied ? c.copied : c.copy}
            </Button>
            <Button size="sm" variant="ghost" onClick={() => { setCodes(null); setCopied(false) }}>{c.cancel}</Button>
          </div>
        </div>
      )}

      {s?.enabled && (
        <div className="mt-4 flex flex-wrap items-center gap-2 border-t border-border pt-4">
          <span className="text-sm text-text-secondary">{c.recoveryTitle}</span>
          <Chip tone={s.recovery_codes_left > 0 ? 'on' : 'wait'}>{c.recoveryLeft(s.recovery_codes_left)}</Chip>
          <Button size="sm" variant="secondary" className="ms-auto" onClick={() => { setConfirm({ kind: 'recovery' }); setPassword('') }}>{c.recoveryNew}</Button>
          <Button size="sm" variant="danger" data-testid="two-step-disable" onClick={() => { setConfirm({ kind: 'disable' }); setPassword('') }}>{c.disableAll}</Button>
        </div>
      )}

      {confirm && (
        <form
          data-testid="two-step-reconfirm"
          className="mt-3 flex flex-wrap items-end gap-2"
          onSubmit={(e) => { e.preventDefault(); if (password) act.mutate(confirm) }}
        >
          <Input type="password" aria-label={c.password} placeholder={c.password} autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} className="max-w-[240px]" />
          <Button type="submit" size="sm" variant={confirm.kind === 'recovery' ? 'primary' : 'danger'} loading={act.isPending} disabled={!password}>{c.confirm}</Button>
          <Button type="button" size="sm" variant="ghost" onClick={() => { setConfirm(null); act.reset() }}>{c.cancel}</Button>
          {act.isError && <span role="alert" className="text-xs text-danger">{toApiError(act.error).message}</span>}
        </form>
      )}
    </section>
  )
}

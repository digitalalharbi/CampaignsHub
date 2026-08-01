import { useEffect, useState } from 'react'
import { KeyRound, ShieldCheck, Smartphone } from 'lucide-react'
import { useSecurityActions, useSecurityActivity, useSecurityPolicy, type SecurityPolicy } from '../api'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Button } from '@/components/ui/Button'
import { fmtDateTime } from '@/lib/datetime'
import { Switch } from '@/components/ui/Switch'
import { Alert } from '@/components/ui/Alert'
import { Skeleton } from '@/components/ui/States'
import { toApiError } from '@/lib/api/client'
import { useUi } from '@/stores/ui'

export function SecurityTab() {
  const ar = useUi((u) => u.locale) === 'ar'
  const activity = useSecurityActivity()
  const policyQ = useSecurityPolicy()
  const a = useSecurityActions()
  const [pw, setPw] = useState({ current_password: '', password: '', password_confirmation: '' })
  const [pwMsg, setPwMsg] = useState<{ ok: boolean; text: string } | null>(null)
  const [mfa, setMfa] = useState<{ secret: string; otpauth_uri: string } | null>(null)
  const [code, setCode] = useState('')
  const [mfaMsg, setMfaMsg] = useState('')
  const [policy, setPolicy] = useState<SecurityPolicy | null>(null)

  useEffect(() => { if (policyQ.data) setPolicy(policyQ.data.policy) }, [policyQ.data])

  const changePw = async (e: React.FormEvent) => {
    e.preventDefault()
    setPwMsg(null)
    try { await a.changePassword.mutateAsync(pw); setPwMsg({ ok: true, text: ar ? 'تم تحديث كلمة المرور.' : 'Your password has been updated.' }); setPw({ current_password: '', password: '', password_confirmation: '' }) }
    catch (e2) { setPwMsg({ ok: false, text: toApiError(e2).message }) }
  }
  const startMfa = async () => { setMfaMsg(''); setMfa(await a.mfaSetup.mutateAsync()) }
  const confirmMfa = async () => {
    setMfaMsg('')
    try { await a.mfaConfirm.mutateAsync(code); setMfa(null); setCode(''); activity.refetch() }
    catch (e2) { setMfaMsg(toApiError(e2).message) }
  }

  const enabled = activity.data?.two_factor_enabled

  return (
    <div className="space-y-5">
      {/* Password */}
      <form onSubmit={changePw} className="rounded-2xl border border-border bg-surface p-6 shadow-[var(--shadow-small)]">
        <h2 className="mb-3 flex items-center gap-2 text-lg font-bold text-text-primary"><KeyRound size={18} /> {ar ? 'تغيير كلمة المرور' : 'Change your password'}</h2>
        {pwMsg && <div className="mb-3"><Alert severity={pwMsg.ok ? 'positive' : 'danger'} title={pwMsg.ok ? (ar ? 'تم' : 'Done') : (ar ? 'خطأ' : 'Error')}>{pwMsg.text}</Alert></div>}
        <div className="grid gap-3 sm:grid-cols-3">
          <Field label={ar ? 'كلمة المرور الحالية' : 'Current password'} htmlFor="cpw"><Input id="cpw" type="password" value={pw.current_password} onChange={(e) => setPw({ ...pw, current_password: e.target.value })} required /></Field>
          <Field label={ar ? 'الجديدة' : 'New password'} htmlFor="npw"><Input id="npw" type="password" value={pw.password} onChange={(e) => setPw({ ...pw, password: e.target.value })} required /></Field>
          <Field label={ar ? 'تأكيد الجديدة' : 'Confirm the new password'} htmlFor="npw2"><Input id="npw2" type="password" value={pw.password_confirmation} onChange={(e) => setPw({ ...pw, password_confirmation: e.target.value })} required /></Field>
        </div>
        <div className="mt-4 flex justify-end"><Button type="submit" disabled={a.changePassword.isPending}>{ar ? 'تحديث' : 'Update'}</Button></div>
      </form>

      {/* MFA */}
      <div className="rounded-2xl border border-border bg-surface p-6 shadow-[var(--shadow-small)]">
        <h2 className="mb-1 flex items-center gap-2 text-lg font-bold text-text-primary"><Smartphone size={18} /> {ar ? 'المصادقة الثنائية (2FA)' : 'Two-factor authentication (2FA)'}</h2>
        <p className="mb-3 text-sm text-text-secondary">{ar ? 'حماية إضافية عبر تطبيق مصادقة (TOTP). ' : 'Extra protection through an authenticator app (TOTP). '}{enabled ? <span className="font-semibold text-success"><ShieldCheck size={14} className="inline" /> {ar ? 'مُفعّلة' : 'Enabled'}</span> : <span className="text-text-muted">{ar ? 'غير مُفعّلة' : 'Not enabled'}</span>}</p>
        {!enabled && !mfa && <Button variant="secondary" onClick={startMfa} disabled={a.mfaSetup.isPending}>{ar ? 'بدء التفعيل' : 'Start setup'}</Button>}
        {!enabled && mfa && (
          <div className="rounded-xl border border-border bg-surface-secondary p-4">
            <p className="text-sm text-text-secondary">{ar ? 'أضف هذا المفتاح في تطبيق المصادقة ثم أدخل الرمز:' : 'Add this key to your authenticator app, then enter the code:'}</p>
            <code className="my-2 block break-all rounded bg-surface px-2 py-1 text-sm tnum">{mfa.secret}</code>
            {mfaMsg && <p className="mb-2 text-xs text-danger">{mfaMsg}</p>}
            <div className="flex gap-2"><Input value={code} onChange={(e) => setCode(e.target.value)} placeholder="000000" maxLength={6} className="max-w-[140px] tnum" /><Button onClick={confirmMfa} disabled={a.mfaConfirm.isPending}>{ar ? 'تأكيد' : 'Confirm'}</Button></div>
          </div>
        )}
        {enabled && <MfaDisable onDone={() => activity.refetch()} />}
      </div>

      {/* Org policy */}
      {policy && (
        <div className="rounded-2xl border border-border bg-surface p-6 shadow-[var(--shadow-small)]">
          <h2 className="mb-3 text-lg font-bold text-text-primary">{ar ? 'سياسة الأمان للمؤسسة' : 'Organisation security policy'}</h2>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label={ar ? 'مهلة الجلسة (دقائق)' : 'Session timeout (minutes)'} htmlFor="sto"><Input id="sto" type="number" min={5} value={policy.session_timeout_minutes} onChange={(e) => setPolicy({ ...policy, session_timeout_minutes: Number(e.target.value) })} /></Field>
            <div className="flex flex-col justify-end gap-2">
              <Switch checked={policy.alert_new_device} onCheckedChange={(v) => setPolicy({ ...policy, alert_new_device: v })} label={ar ? 'تنبيه عند جهاز جديد' : 'Alert on a new device'} />
              <Switch checked={policy.alert_failed_logins} onCheckedChange={(v) => setPolicy({ ...policy, alert_failed_logins: v })} label={ar ? 'تنبيه محاولات الدخول الفاشلة' : 'Alert on failed sign-ins'} />
            </div>
          </div>
          <div className="mt-4 flex justify-end"><Button onClick={() => a.savePolicy.mutateAsync(policy)} disabled={a.savePolicy.isPending}>{ar ? 'حفظ السياسة' : 'Save the policy'}</Button></div>
        </div>
      )}

      {/* Activity */}
      <div className="rounded-2xl border border-border bg-surface p-6 shadow-[var(--shadow-small)]">
        <h2 className="mb-3 text-lg font-bold text-text-primary">{ar ? 'نشاط تسجيل الدخول' : 'Sign-in activity'}</h2>
        {activity.isLoading ? <Skeleton className="h-32" /> : (activity.data?.history.length ?? 0) === 0 ? <p className="text-sm text-text-muted">{ar ? 'لا نشاط مُسجّل.' : 'No activity recorded.'}</p> : (
          <div className="overflow-x-auto"><table className="w-full min-w-[420px] text-sm">
            <thead><tr className="border-b border-border text-text-muted"><th className="p-2 text-start">{ar ? 'الحدث' : 'Event'}</th><th className="p-2 text-start">IP</th><th className="p-2 text-start">{ar ? 'الجهاز' : 'Device'}</th><th className="p-2 text-start">{ar ? 'الوقت' : 'Time'}</th></tr></thead>
            <tbody>{activity.data!.history.slice(0, 12).map((h, i) => (
              <tr key={i} className="border-b border-border last:border-0">
                <td className="p-2">{h.action === 'user.login' ? (ar ? 'دخول' : 'Sign in') : (ar ? 'خروج' : 'Sign out')}</td>
                <td className="p-2 tnum text-xs">{h.ip_address ?? '—'}</td>
                <td className="p-2 max-w-[220px] truncate text-xs text-text-muted" title={h.user_agent ?? ''}>{h.user_agent ?? '—'}</td>
                <td className="p-2 text-xs">{h.at ? fmtDateTime(h.at) : '—'}</td>
              </tr>
            ))}</tbody>
          </table></div>
        )}
      </div>
    </div>
  )
}

function MfaDisable({ onDone }: { onDone: () => void }) {
  const ar = useUi((u) => u.locale) === 'ar'
  const { mfaDisable } = useSecurityActions()
  const [pw, setPw] = useState('')
  const [msg, setMsg] = useState('')
  return (
    <div className="flex flex-wrap items-end gap-2">
      <Field label={ar ? 'كلمة المرور لإيقاف 2FA' : 'Password, to turn 2FA off'} htmlFor="dmfa"><Input id="dmfa" type="password" value={pw} onChange={(e) => setPw(e.target.value)} className="max-w-[220px]" /></Field>
      <Button variant="danger" onClick={async () => { try { await mfaDisable.mutateAsync(pw); onDone() } catch (e) { setMsg(toApiError(e).message) } }} disabled={mfaDisable.isPending}>{ar ? 'إيقاف 2FA' : 'Turn 2FA off'}</Button>
      {msg && <span className="text-xs text-danger">{msg}</span>}
    </div>
  )
}

import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { Check, Copy } from 'lucide-react'
import { actOnUserAccess, type TenantPerson, type UserAccessAction } from './api'
import { Button } from '@/components/ui/Button'
import { Modal } from '@/components/ui/Modal'
import { toApiError } from '@/lib/api/client'

/**
 * SUPERADMIN-ACCESS-001 — restore one person's access, from the tenant drawer.
 *
 * End every session, issue a one-time set-password link, or turn two-factor off after a lost device. Nobody sees or chooses the
 * person's password. Every action needs a reason; the two that hand back a way in ask for the operator's own password again.
 */

const ACTIONS: { key: UserAccessAction; ar: string; en: string; hintAr: string; hintEn: string; needsPassword: boolean }[] = [
  { key: 'revoke_sessions', ar: 'إنهاء كل الجلسات', en: 'End every session', hintAr: 'يُسجَّل خروجه من كل الأجهزة فورًا.', hintEn: 'Signed out of every device now.', needsPassword: false },
  { key: 'password_link', ar: 'رابط تعيين كلمة مرور', en: 'Set-password link', hintAr: 'رابط لاستخدام واحد يُرسل بالبريد ويظهر لك مرة واحدة. كلمة المرور الحالية تبقى حتى يُستخدم.', hintEn: 'Single-use link, emailed and shown to you once. The current password stays until it is used.', needsPassword: true },
  { key: 'reset_mfa', ar: 'إعادة ضبط التحقق الثنائي', en: 'Reset two-factor', hintAr: 'يُعطَّل التحقق الثنائي وتنتهي الجلسات، ليعيد التسجيل بعد فقدان الجهاز.', hintEn: 'Two-factor is turned off and sessions end, so they can enrol again after losing the device.', needsPassword: true },
]

export function UserAccessDialog({ person, ar, onClose }: { person: TenantPerson; ar: boolean; onClose: () => void }) {
  const [action, setAction] = useState<UserAccessAction>('revoke_sessions')
  const [reason, setReason] = useState('')
  const [password, setPassword] = useState('')
  const [copied, setCopied] = useState(false)
  const chosen = ACTIONS.find((a) => a.key === action)!

  const act = useMutation({
    mutationFn: () => actOnUserAccess(person.user_uuid ?? '', {
      action, reason: reason.trim(), current_password: chosen.needsPassword ? password : undefined,
    }),
    onSuccess: () => setPassword(''),
  })

  const copy = async (link: string) => {
    try {
      await navigator.clipboard.writeText(link)
      setCopied(true)
      window.setTimeout(() => setCopied(false), 1500)
    } catch {
      setCopied(false)
    }
  }

  return (
    <Modal open onClose={onClose} title={ar ? `إدارة وصول ${person.name ?? person.email ?? ''}` : `Access for ${person.name ?? person.email ?? ''}`}>
      <form data-testid="user-access-form" className="flex flex-col gap-3" onSubmit={(e) => { e.preventDefault(); act.mutate() }}>
        <p className="text-xs text-text-muted" dir="ltr">{person.email}{person.mfa_enabled ? ' · 2FA' : ''}</p>
        <fieldset className="flex flex-col gap-2">
          <legend className="mb-1 text-xs font-semibold text-text-secondary">{ar ? 'الإجراء' : 'Action'}</legend>
          {ACTIONS.map((a) => (
            <label key={a.key} className="flex items-start gap-2 text-sm text-text-primary">
              <input data-testid={`access-action-${a.key}`} type="radio" name="access-action" className="mt-1" checked={action === a.key}
                onChange={() => { setAction(a.key); act.reset() }} disabled={a.key === 'reset_mfa' && !person.mfa_enabled} />
              <span>
                <span className="font-semibold">{ar ? a.ar : a.en}</span>
                <span className="block text-xs text-text-muted">{ar ? a.hintAr : a.hintEn}</span>
              </span>
            </label>
          ))}
        </fieldset>
        <label className="flex flex-col gap-1 text-xs font-semibold text-text-secondary">
          {ar ? 'السبب (يُسجَّل في سجل التدقيق)' : 'Reason (recorded in the audit trail)'}
          <input data-testid="access-reason" required minLength={5} value={reason} onChange={(e) => setReason(e.target.value)}
            className="h-10 min-w-0 rounded-lg border border-border bg-surface px-3 text-sm font-normal text-text-primary outline-none focus-visible:ring-2 focus-visible:ring-brand-600" />
        </label>
        {chosen.needsPassword && (
          <label className="flex flex-col gap-1 text-xs font-semibold text-text-secondary">
            {ar ? 'كلمة مرورك للتأكيد' : 'Your password, to confirm'}
            <input data-testid="access-password" type="password" required autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)}
              className="h-10 min-w-0 rounded-lg border border-border bg-surface px-3 text-sm font-normal text-text-primary outline-none focus-visible:ring-2 focus-visible:ring-brand-600" />
          </label>
        )}
        {act.isError && <p data-testid="access-error" className="text-xs font-semibold text-danger">{toApiError(act.error).message}</p>}
        {act.data && (
          <div data-testid="access-result" className="flex min-w-0 flex-col gap-2 rounded-xl bg-surface-secondary p-3 text-xs">
            <p className="font-semibold text-[var(--positive-foreground)]">{ar ? 'تم.' : 'Done.'}</p>
            {act.data.link && (
              <>
                <p className="text-text-secondary">
                  {act.data.delivery === 'sent'
                    ? (ar ? 'أُرسل الرابط بالبريد أيضًا.' : 'The link was also emailed.')
                    : (ar ? 'لم يُؤكَّد إرسال البريد. سلّم الرابط بنفسك بعد التحقق من هوية الشخص.' : 'Mail delivery was not confirmed. Hand the link over yourself after verifying who they are.')}
                </p>
                <div className="flex items-start gap-2">
                  <p data-testid="access-link" className="min-w-0 flex-1 break-all font-mono text-text-primary" dir="ltr">{act.data.link}</p>
                  <button type="button" onClick={() => void copy(act.data!.link!)} className="flex shrink-0 items-center gap-1 rounded-lg border border-border px-2 py-1 font-semibold text-text-secondary hover:text-text-primary">
                    {copied ? <Check size={12} /> : <Copy size={12} />} {copied ? (ar ? 'نُسخ' : 'Copied') : (ar ? 'نسخ' : 'Copy')}
                  </button>
                </div>
              </>
            )}
          </div>
        )}
        <div className="flex justify-end gap-2">
          <Button type="button" size="sm" variant="secondary" onClick={onClose}>{ar ? 'إغلاق' : 'Close'}</Button>
          <Button data-testid="access-submit" type="submit" size="sm" disabled={act.isPending || !person.user_uuid}>{ar ? 'تنفيذ' : 'Apply'}</Button>
        </div>
      </form>
    </Modal>
  )
}

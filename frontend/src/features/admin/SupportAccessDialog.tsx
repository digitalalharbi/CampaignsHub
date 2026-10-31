import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { startSupportSession, type PlatformTenant } from './api'
import { Button } from '@/components/ui/Button'
import { Modal } from '@/components/ui/Modal'
import { toApiError } from '@/lib/api/client'

/**
 * SUPERADMIN-SUPPORT-ACCESS-001 — «الدخول للدعم» for one tenant.
 *
 * Asks why and for the operator's OWN password (never the tenant's), then enters the workspace for 30 minutes, read-only. A full
 * page load follows, so the workspace opens through the same session checks a member's does and no console data is carried in.
 */
export function SupportAccessDialog({ tenant, ar, onClose }: { tenant: PlatformTenant; ar: boolean; onClose: () => void }) {
  const [reason, setReason] = useState('')
  const [password, setPassword] = useState('')
  const start = useMutation({
    mutationFn: () => startSupportSession(tenant.id, reason.trim(), password),
    onSuccess: (r) => window.location.assign(r.landing_path),
  })
  const field = 'h-10 min-w-0 rounded-lg border border-border bg-surface px-3 text-sm font-normal text-text-primary outline-none focus-visible:ring-2 focus-visible:ring-brand-600'

  return (
    <Modal open onClose={onClose} title={ar ? `الدخول للدعم — ${tenant.name}` : `Support access — ${tenant.name}`}>
      <form data-testid="support-access-form" className="flex flex-col gap-3" onSubmit={(e) => { e.preventDefault(); start.mutate() }}>
        <p className="text-xs text-text-secondary">
          {ar
            ? 'جلسة قراءة فقط لمدة 30 دقيقة. لا يمكن خلالها التعديل أو الحذف أو الوصول إلى الأسرار والمدفوعات والتصدير. تُسجَّل الجلسة وكل صفحة تُفتح في سجل تدقيق المساحة.'
            : 'A 30-minute, read-only visit. No edits, deletions, secrets, payments or exports. The visit and every page opened are recorded in the workspace’s audit trail.'}
        </p>
        <label className="flex flex-col gap-1 text-xs font-semibold text-text-secondary">
          {ar ? 'سبب الدخول' : 'Reason'}
          <input data-testid="support-reason" required minLength={5} value={reason} onChange={(e) => setReason(e.target.value)} className={field} />
        </label>
        <label className="flex flex-col gap-1 text-xs font-semibold text-text-secondary">
          {ar ? 'كلمة مرورك للتأكيد' : 'Your password, to confirm'}
          <input data-testid="support-password" type="password" required autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} className={field} />
        </label>
        {start.isError && <p data-testid="support-error" className="text-xs font-semibold text-danger">{toApiError(start.error).message}</p>}
        <div className="flex justify-end gap-2">
          <Button type="button" size="sm" variant="secondary" onClick={onClose}>{ar ? 'إلغاء' : 'Cancel'}</Button>
          <Button data-testid="support-start" type="submit" size="sm" disabled={start.isPending}>{ar ? 'الدخول للدعم' : 'Enter for support'}</Button>
        </div>
      </form>
    </Modal>
  )
}

import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { actOnMembership, type MembershipAction, type TenantPerson } from './api'
import { Button } from '@/components/ui/Button'
import { Modal } from '@/components/ui/Modal'
import { toApiError } from '@/lib/api/client'

/**
 * SUPERADMIN-MEMBERS-001 — one person's place in one workspace: their role here, a suspension here, or leaving here.
 *
 * Nothing done here reaches the person's other workspaces or deletes their account. The server keeps every workspace with one
 * active owner and records the reason in that workspace's audit trail.
 */
export function MembershipDialog({ tenantId, person, roles, ar, onClose }: {
  tenantId: string
  person: TenantPerson
  roles: Array<{ slug: string; name: string }>
  ar: boolean
  onClose: () => void
}) {
  const client = useQueryClient()
  const suspended = person.status === 'suspended'
  const [action, setAction] = useState<MembershipAction>('change_role')
  const [role, setRole] = useState(person.roles?.[0] ?? roles[0]?.slug ?? '')
  const [reason, setReason] = useState('')

  const act = useMutation({
    mutationFn: () => actOnMembership(tenantId, person.user_uuid ?? '', {
      action, role: action === 'change_role' ? role : undefined, reason: reason.trim(),
    }),
    onSuccess: () => void client.invalidateQueries({ queryKey: ['admin', 'tenant', tenantId] }),
  })

  const choices: { key: MembershipAction; ar: string; en: string; hintAr: string; hintEn: string }[] = [
    { key: 'change_role', ar: 'تغيير الدور', en: 'Change role', hintAr: 'يستبدل دوره في هذه المساحة فقط.', hintEn: 'Replaces their role in this workspace only.' },
    suspended
      ? { key: 'reactivate', ar: 'إعادة التفعيل', en: 'Reactivate', hintAr: 'يعود وصوله إلى هذه المساحة.', hintEn: 'Their access to this workspace returns.' }
      : { key: 'suspend', ar: 'إيقاف العضوية', en: 'Suspend membership', hintAr: 'يتوقف وصوله إلى هذه المساحة؛ حسابه ومساحاته الأخرى كما هي.', hintEn: 'Access to this workspace stops; their account and other workspaces are untouched.' },
    { key: 'remove', ar: 'إزالة من المساحة', en: 'Remove from workspace', hintAr: 'يغادر هذه المساحة. لا يُحذف حسابه.', hintEn: 'Leaves this workspace. The account is not deleted.' },
  ]

  return (
    <Modal open onClose={onClose} title={ar ? `عضوية ${person.name ?? person.email ?? ''}` : `Membership of ${person.name ?? person.email ?? ''}`}>
      <form data-testid="membership-form" className="flex flex-col gap-3" onSubmit={(e) => { e.preventDefault(); act.mutate() }}>
        <p className="text-xs text-text-muted" dir="ltr">{person.email} · {(person.roles ?? []).join(', ') || '—'} · {person.status}</p>
        <fieldset className="flex flex-col gap-2">
          <legend className="mb-1 text-xs font-semibold text-text-secondary">{ar ? 'الإجراء' : 'Action'}</legend>
          {choices.map((c) => (
            <label key={c.key} className="flex items-start gap-2 text-sm text-text-primary">
              <input data-testid={`membership-action-${c.key}`} type="radio" name="membership-action" className="mt-1"
                checked={action === c.key} onChange={() => { setAction(c.key); act.reset() }} />
              <span>
                <span className="font-semibold">{ar ? c.ar : c.en}</span>
                <span className="block text-xs text-text-muted">{ar ? c.hintAr : c.hintEn}</span>
              </span>
            </label>
          ))}
        </fieldset>
        {action === 'change_role' && (
          <label className="flex flex-col gap-1 text-xs font-semibold text-text-secondary">
            {ar ? 'الدور في هذه المساحة' : 'Role in this workspace'}
            <select data-testid="membership-role" value={role} onChange={(e) => setRole(e.target.value)}
              className="h-10 min-w-0 rounded-lg border border-border bg-surface px-3 text-sm font-normal text-text-primary">
              {roles.map((r) => <option key={r.slug} value={r.slug}>{r.name}</option>)}
            </select>
          </label>
        )}
        <label className="flex flex-col gap-1 text-xs font-semibold text-text-secondary">
          {ar ? 'السبب (يُسجَّل في سجل تدقيق المساحة)' : 'Reason (recorded in the workspace’s audit trail)'}
          <input data-testid="membership-reason" required minLength={5} value={reason} onChange={(e) => setReason(e.target.value)}
            className="h-10 min-w-0 rounded-lg border border-border bg-surface px-3 text-sm font-normal text-text-primary outline-none focus-visible:ring-2 focus-visible:ring-brand-600" />
        </label>
        {act.isError && <p data-testid="membership-error" className="text-xs font-semibold text-danger">{toApiError(act.error).message}</p>}
        {act.data && (
          <p data-testid="membership-result" className="rounded-xl bg-surface-secondary p-3 text-xs font-semibold text-[var(--positive-foreground)]">
            {act.data.member.member
              ? (ar ? `تم. الأدوار: ${act.data.member.roles.join(', ') || '—'} · ${act.data.member.status}` : `Done. Roles: ${act.data.member.roles.join(', ') || '—'} · ${act.data.member.status}`)
              : (ar ? 'تم. لم يعد عضوًا في هذه المساحة.' : 'Done. No longer a member of this workspace.')}
          </p>
        )}
        <div className="flex justify-end gap-2">
          <Button type="button" size="sm" variant="secondary" onClick={onClose}>{ar ? 'إغلاق' : 'Close'}</Button>
          <Button data-testid="membership-submit" type="submit" size="sm" variant={action === 'remove' ? 'danger' : 'primary'}
            disabled={act.isPending || !person.user_uuid || reason.trim().length < 5 || (action === 'change_role' && role === '')}>
            {ar ? 'تنفيذ' : 'Apply'}
          </Button>
        </div>
      </form>
    </Modal>
  )
}

import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { addTenantMember, reissueTenantInvitation, type PendingInvitation } from './api'
import { InvitationLink } from './CreateWorkspaceDialog'
import { Button } from '@/components/ui/Button'
import { Num } from '@/components/ui/Num'
import { toApiError } from '@/lib/api/client'

/**
 * ADMIN-FULL-CONTROL-001 — a person into this workspace with a role, and a fresh link for an invitation that went nowhere.
 *
 * An address that already has an account joins now; one that does not is invited and chooses their own password when they accept.
 * Nobody sees or sets a password here. Owners are not made here, and platform roles are not offered: an owner comes from a
 * confirmed role change on someone already in the workspace.
 */
const NEVER_OFFERED = ['tenant-owner', 'platform-admin', 'super-admin']

const inputClass = 'h-9 min-w-0 rounded-lg border border-border bg-surface px-3 text-sm text-text-primary outline-none focus-visible:ring-2 focus-visible:ring-brand-600'

export function AddTenantMember({ tenantId, roles, ar }: { tenantId: string; roles: Array<{ slug: string; name: string }>; ar: boolean }) {
  const client = useQueryClient()
  const offered = roles.filter((r) => !NEVER_OFFERED.includes(r.slug))
  const [email, setEmail] = useState('')
  const [role, setRole] = useState('')
  const [reason, setReason] = useState('')

  const add = useMutation({
    mutationFn: () => addTenantMember(tenantId, { email: email.trim(), role, reason: reason.trim() }),
    onSuccess: () => {
      setEmail(''); setRole(''); setReason('')
      void client.invalidateQueries({ queryKey: ['admin', 'tenant', tenantId] })
    },
  })

  return (
    <section className="mt-5">
      <h3 className="text-[12px] font-semibold uppercase tracking-wide text-text-muted">{ar ? 'إضافة شخص' : 'Add a person'}</h3>
      <form data-testid="add-member-form" className="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2" onSubmit={(e) => { e.preventDefault(); add.mutate() }}>
        <input data-testid="add-member-email" type="email" required dir="ltr" value={email} onChange={(e) => setEmail(e.target.value)}
          placeholder={ar ? 'البريد الإلكتروني' : 'Email'} aria-label={ar ? 'البريد الإلكتروني' : 'Email'} className={inputClass} />
        <select data-testid="add-member-role" required value={role} onChange={(e) => setRole(e.target.value)} aria-label={ar ? 'الدور' : 'Role'} className={inputClass}>
          <option value="">{ar ? 'اختر الدور' : 'Choose a role'}</option>
          {offered.map((r) => <option key={r.slug} value={r.slug}>{r.name}</option>)}
        </select>
        <input data-testid="add-member-reason" required minLength={5} value={reason} onChange={(e) => setReason(e.target.value)}
          placeholder={ar ? 'السبب (يُسجَّل في السجل)' : 'Reason (kept in the record)'} aria-label={ar ? 'السبب' : 'Reason'} className={`${inputClass} sm:col-span-2`} />
        <div className="sm:col-span-2">
          <Button data-testid="add-member-submit" type="submit" size="sm" variant="secondary" disabled={add.isPending || email.trim() === '' || role === '' || reason.trim().length < 5}>
            {ar ? 'إضافة' : 'Add'}
          </Button>
        </div>
      </form>
      {add.isError && <p data-testid="add-member-error" className="mt-2 text-xs font-semibold text-danger">{toApiError(add.error).message}</p>}
      {add.data?.added && <p data-testid="add-member-added" className="mt-2 text-xs font-semibold text-[var(--positive-foreground)]">{ar ? 'تمت الإضافة إلى هذه المساحة.' : 'Added to this workspace.'}</p>}
      {add.data?.invitation && (
        <div data-testid="add-member-invited" className="mt-2">
          <p className="mb-1 text-xs text-text-secondary">{ar ? 'لا يوجد حساب بهذا البريد، فأُرسلت دعوة يختار فيها كلمة مروره بنفسه.' : 'No account uses this address, so an invitation was issued and they choose their own password.'}</p>
          <InvitationLink invitation={add.data.invitation} ar={ar} />
        </div>
      )}
    </section>
  )
}

export function PendingInvitations({ tenantId, invitations, ar }: { tenantId: string; invitations: PendingInvitation[]; ar: boolean }) {
  if (invitations.length === 0) return null

  return (
    <section data-testid="pending-invitations" className="mt-5">
      <h3 className="text-[12px] font-semibold uppercase tracking-wide text-text-muted">{ar ? 'دعوات لم تُقبل بعد' : 'Invitations not yet accepted'}</h3>
      <ul className="mt-2 grid gap-1.5">
        {invitations.map((i) => <InvitationRow key={i.id} tenantId={tenantId} invitation={i} ar={ar} />)}
      </ul>
    </section>
  )
}

function InvitationRow({ tenantId, invitation, ar }: { tenantId: string; invitation: PendingInvitation; ar: boolean }) {
  const client = useQueryClient()
  const [open, setOpen] = useState(false)
  const [reason, setReason] = useState('')
  const reissue = useMutation({
    mutationFn: () => reissueTenantInvitation(tenantId, invitation.id, reason.trim()),
    onSuccess: () => { setReason(''); setOpen(false); void client.invalidateQueries({ queryKey: ['admin', 'tenant', tenantId] }) },
  })
  const expired = invitation.expires_at !== null && new Date(invitation.expires_at).getTime() < Date.now()

  return (
    <li data-testid={`invitation-${invitation.id}`} className="flex min-w-0 flex-col gap-2 rounded-lg bg-surface-secondary px-3 py-2 text-[13px]">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <span className="min-w-0 break-all text-text-primary" dir="ltr"><Num>{invitation.email}</Num></span>
        <span className="flex flex-wrap items-center gap-2 text-[12px] text-text-secondary">
          <span>{invitation.role}</span>
          {expired && <span className="rounded-full bg-warning/15 px-2 py-0.5 font-semibold text-warning">{ar ? 'منتهية' : 'Expired'}</span>}
          <button type="button" data-testid={`invitation-reissue-${invitation.id}`} onClick={() => setOpen(!open)}
            className="rounded-lg border border-border px-2 py-0.5 font-semibold text-text-secondary hover:text-text-primary">
            {ar ? 'إعادة إصدار الرابط' : 'Reissue link'}
          </button>
        </span>
      </div>
      {open && (
        <form className="flex flex-wrap items-center gap-2" onSubmit={(e) => { e.preventDefault(); reissue.mutate() }}>
          <input data-testid="invitation-reason" required minLength={5} value={reason} onChange={(e) => setReason(e.target.value)}
            placeholder={ar ? 'السبب (يُسجَّل في السجل)' : 'Reason (kept in the record)'} aria-label={ar ? 'السبب' : 'Reason'} className={`${inputClass} flex-1`} />
          <Button data-testid="invitation-reissue-submit" type="submit" size="sm" variant="secondary" disabled={reissue.isPending || reason.trim().length < 5}>{ar ? 'تنفيذ' : 'Apply'}</Button>
        </form>
      )}
      {reissue.isError && <p className="text-xs font-semibold text-danger">{toApiError(reissue.error).message}</p>}
      {reissue.data?.invitation && <InvitationLink invitation={reissue.data.invitation} ar={ar} />}
    </li>
  )
}

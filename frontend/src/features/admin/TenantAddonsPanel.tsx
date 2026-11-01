import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { decideWorkspaceAddon, fetchAddons, fetchWorkspaceAddons, grantWorkspaceAddon } from './api'
import { Button } from '@/components/ui/Button'
import { toApiError } from '@/lib/api/client'

/**
 * PRICING-ADDONS-001 — one workspace's add-ons, in its tenant drawer: approve or decline a request, cancel an active add-on, or
 * grant one outright. Every act needs a reason (asked once, here) and lands in the workspace's audit trail. Nothing is charged.
 */
export function TenantAddonsPanel({ tenantId, ar }: { tenantId: string; ar: boolean }) {
  const qc = useQueryClient()
  const key = ['admin', 'tenant-addons', tenantId]
  const held = useQuery({ queryKey: key, queryFn: () => fetchWorkspaceAddons(tenantId) })
  const catalogue = useQuery({ queryKey: ['admin', 'addons'], queryFn: fetchAddons })
  const [reason, setReason] = useState('')
  const [code, setCode] = useState('')
  const done = { onSuccess: (next: Awaited<ReturnType<typeof fetchWorkspaceAddons>>) => { qc.setQueryData(key, next); setReason('') } }
  const decide = useMutation({ mutationFn: ({ id, d }: { id: string; d: 'approve' | 'decline' | 'cancel' }) => decideWorkspaceAddon(id, d, reason.trim()), ...done })
  const grant = useMutation({ mutationFn: () => grantWorkspaceAddon(tenantId, code, 1, reason.trim()), ...done })
  const ok = reason.trim().length >= 5
  const error = decide.error ?? grant.error

  return (
    <section data-testid="tenant-addons" className="mt-5">
      <h3 className="text-[12px] font-semibold uppercase tracking-wide text-text-muted">{ar ? 'الإضافات' : 'Add-ons'}</h3>
      <input data-testid="tenant-addon-reason" value={reason} onChange={(e) => setReason(e.target.value)}
        placeholder={ar ? 'السبب (مطلوب لأي إجراء)' : 'Reason (needed for any action)'}
        className="mt-2 h-9 w-full min-w-0 rounded-lg border border-border bg-surface px-2 text-sm" />
      {error && <p className="mt-1 text-xs font-semibold text-danger">{toApiError(error).message}</p>}
      <ul className="mt-2 grid gap-1.5">
        {(held.data?.addons ?? []).filter((t) => t.status === 'requested' || t.status === 'active').map((t) => (
          <li key={t.id} data-testid={`tenant-addon-${t.id}`} className="flex flex-wrap items-center gap-2 rounded-lg bg-surface-secondary px-3 py-2 text-[13px]">
            <span className="font-semibold text-text-primary">{(ar ? t.name_ar : t.name) ?? t.code} ×{t.quantity}</span>
            <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${t.status === 'active' ? 'bg-[var(--positive-background)] text-[var(--positive-foreground)]' : 'bg-info/15 text-info'}`}>
              {t.status === 'active' ? (ar ? 'مفعّلة' : 'Active') : (ar ? 'طلب بانتظار' : 'Requested')}
            </span>
            {t.unit_price && <span className="tnum text-[12px] text-text-muted" dir="ltr">{t.unit_price} {t.currency}</span>}
            <span className="ms-auto flex gap-1">
              {t.status === 'requested' && <>
                <Button data-testid={`tenant-addon-approve-${t.id}`} size="sm" disabled={!ok || decide.isPending} onClick={() => decide.mutate({ id: t.id, d: 'approve' })}>{ar ? 'اعتماد' : 'Approve'}</Button>
                <Button size="sm" variant="secondary" disabled={!ok || decide.isPending} onClick={() => decide.mutate({ id: t.id, d: 'decline' })}>{ar ? 'رفض' : 'Decline'}</Button>
              </>}
              {t.status === 'active' && <Button size="sm" variant="secondary" disabled={!ok || decide.isPending} onClick={() => decide.mutate({ id: t.id, d: 'cancel' })}>{ar ? 'إلغاء' : 'Cancel'}</Button>}
            </span>
          </li>
        ))}
      </ul>
      <div className="mt-2 flex flex-wrap items-center gap-2">
        <select data-testid="tenant-addon-grant-code" value={code} onChange={(e) => setCode(e.target.value)} className="h-9 rounded-lg border border-border bg-surface px-2 text-sm">
          <option value="">{ar ? 'منح إضافة…' : 'Grant an add-on…'}</option>
          {(catalogue.data?.addons ?? []).filter((a) => a.readiness === 'available').map((a) => <option key={a.code} value={a.code}>{ar ? a.name_ar : a.name}</option>)}
        </select>
        <Button data-testid="tenant-addon-grant" size="sm" variant="secondary" disabled={!ok || code === '' || grant.isPending} onClick={() => grant.mutate()}>{ar ? 'منح' : 'Grant'}</Button>
      </div>
    </section>
  )
}

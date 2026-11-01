import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { fetchTenantLimits, setTenantLimit, type TenantLimit } from './api'
import { Button } from '@/components/ui/Button'
import { DateField } from '@/components/ui/DateField'
import { toApiError } from '@/lib/api/client'

/**
 * SUPERADMIN-LIMITS-001 — this workspace's plan limits, in its tenant drawer.
 *
 * Each metric: what the plan allows, what is used, and what applies. A cap can be raised or lowered, lifted, or returned to the
 * plan, optionally until a date. The server enforces it on the same path as the plan's own cap; the plan and its price are not
 * changed. A reason is always required and goes to this workspace's audit trail.
 */
const LABELS: Record<string, { ar: string; en: string }> = {
  projects: { ar: 'المشاريع', en: 'Projects' },
  clients: { ar: 'العملاء', en: 'Clients' },
  team_members: { ar: 'أعضاء الفريق', en: 'Team members' },
  ad_accounts: { ar: 'الحسابات الإعلانية', en: 'Ad accounts' },
  connections: { ar: 'الاتصالات', en: 'Connections' },
  reports_per_month: { ar: 'التقارير شهريًا', en: 'Reports per month' },
}

const show = (n: number | null, ar: boolean) => (n === null ? (ar ? 'غير محدود' : 'Unlimited') : n.toLocaleString('en-US'))

export function TenantLimitsPanel({ tenantId, ar }: { tenantId: string; ar: boolean }) {
  const query = useQuery({ queryKey: ['admin', 'tenant-limits', tenantId], queryFn: () => fetchTenantLimits(tenantId) })
  const [editing, setEditing] = useState<string | null>(null)

  return (
    <section data-testid="tenant-limits" className="mt-5">
      <h3 className="text-[12px] font-semibold uppercase tracking-wide text-text-muted">{ar ? 'حدود الخطة' : 'Plan limits'}</h3>
      {query.isError && <p className="mt-2 text-xs text-danger">{toApiError(query.error).message}</p>}
      {query.data && (
        <ul className="mt-2 grid gap-1.5">
          {query.data.limits.map((l) => (
            <li key={l.metric} data-testid={`limit-${l.metric}`} className="rounded-lg bg-surface-secondary px-3 py-2 text-[13px]">
              <div className="flex flex-wrap items-center gap-2">
                <span className="font-semibold text-text-primary">{ar ? LABELS[l.metric]?.ar ?? l.metric : LABELS[l.metric]?.en ?? l.metric}</span>
                <span className="tnum text-text-secondary" dir="ltr">{l.usage.toLocaleString('en-US')} / {show(l.effective_limit, ar)}</span>
                {l.override
                  ? <span className="rounded-full bg-info/15 px-2 py-0.5 text-[11px] font-semibold text-info">
                      {ar ? `مخصص · الخطة ${show(l.plan_limit, ar)}` : `Override · plan ${show(l.plan_limit, ar)}`}
                      {l.override.expires_at && ` · ${ar ? 'حتى' : 'until'} ${l.override.expires_at.slice(0, 10)}`}
                    </span>
                  : <span className="text-[11px] text-text-muted">{ar ? 'حسب الخطة' : 'From the plan'}</span>}
                {l.lapsed && <span className="text-[11px] text-warning">{ar ? 'انتهى التخصيص' : 'Override lapsed'}</span>}
                <button type="button" data-testid={`limit-edit-${l.metric}`} onClick={() => setEditing(editing === l.metric ? null : l.metric)}
                  className="ms-auto rounded-lg border border-border px-2 py-0.5 text-[12px] font-semibold text-text-secondary hover:text-text-primary">
                  {ar ? 'تعديل' : 'Change'}
                </button>
              </div>
              {editing === l.metric && <LimitForm tenantId={tenantId} limit={l} ar={ar} onDone={() => setEditing(null)} />}
            </li>
          ))}
        </ul>
      )}
    </section>
  )
}

function LimitForm({ tenantId, limit, ar, onDone }: { tenantId: string; limit: TenantLimit; ar: boolean; onDone: () => void }) {
  const qc = useQueryClient()
  const [mode, setMode] = useState<'cap' | 'unlimited' | 'inherit'>(limit.override ? (limit.override.limit === null ? 'unlimited' : 'cap') : 'cap')
  const [value, setValue] = useState(String(limit.override?.limit ?? limit.plan_limit ?? ''))
  const [until, setUntil] = useState('')
  const [reason, setReason] = useState('')
  const save = useMutation({
    mutationFn: () => setTenantLimit(tenantId, limit.metric, {
      mode, limit: mode === 'cap' ? Number(value) : undefined, expires_at: mode !== 'inherit' && until ? until : null, reason: reason.trim(),
    }),
    onSuccess: (next) => { qc.setQueryData(['admin', 'tenant-limits', tenantId], next); onDone() },
  })
  const valid = reason.trim().length >= 5 && (mode !== 'cap' || /^\d+$/.test(value))

  return (
    <form data-testid={`limit-form-${limit.metric}`} className="mt-2 flex flex-col gap-2" onSubmit={(e) => { e.preventDefault(); if (valid) save.mutate() }}>
      <div className="flex flex-wrap gap-3 text-[12px]">
        {(['cap', 'unlimited', 'inherit'] as const).map((m) => (
          <label key={m} className="flex items-center gap-1">
            <input type="radio" name={`mode-${limit.metric}`} data-testid={`limit-mode-${m}`} checked={mode === m} onChange={() => setMode(m)} />
            {m === 'cap' ? (ar ? 'حد محدد' : 'A number') : m === 'unlimited' ? (ar ? 'غير محدود' : 'Unlimited') : (ar ? 'العودة للخطة' : 'Back to the plan')}
          </label>
        ))}
      </div>
      {mode === 'cap' && (
        <input data-testid="limit-value" inputMode="numeric" dir="ltr" value={value} onChange={(e) => setValue(e.target.value.replace(/\D/g, ''))}
          aria-label={ar ? 'الحد' : 'Limit'} className="h-9 w-32 rounded-lg border border-border bg-surface px-2 text-sm" />
      )}
      {mode !== 'inherit' && (
        <label className="flex flex-col gap-1 text-[12px] text-text-secondary">
          {ar ? 'حتى تاريخ (اختياري)' : 'Until (optional)'}
          <DateField value={until} onChange={setUntil} />
        </label>
      )}
      <input data-testid="limit-reason" value={reason} onChange={(e) => setReason(e.target.value)} placeholder={ar ? 'السبب (يُسجَّل في سجل التدقيق)' : 'Reason (recorded in the audit trail)'}
        className="h-9 min-w-0 rounded-lg border border-border bg-surface px-2 text-sm" />
      {save.isError && <p className="text-xs font-semibold text-danger">{toApiError(save.error).message}</p>}
      <div className="flex justify-end gap-2">
        <Button type="button" size="sm" variant="secondary" onClick={onDone}>{ar ? 'إلغاء' : 'Cancel'}</Button>
        <Button data-testid="limit-save" type="submit" size="sm" disabled={!valid || save.isPending}>{ar ? 'حفظ' : 'Save'}</Button>
      </div>
    </form>
  )
}

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { fetchTenantCapabilities, setTenantCapability } from './api'
import { toApiError } from '@/lib/api/client'

/**
 * SUPERADMIN-FEATURES-001 — one workspace's own switch for each capability: inherit its plan, force on, or force off.
 * The same switch the workspace's settings write, enforced by the server; every change needs a reason.
 */
export function TenantCapabilitiesPanel({ tenantId, ar }: { tenantId: string; ar: boolean }) {
  const qc = useQueryClient()
  const list = useQuery({ queryKey: ['admin', 'tenant-capabilities', tenantId], queryFn: () => fetchTenantCapabilities(tenantId) })
  const change = useMutation({
    mutationFn: ({ module, enabled, reason }: { module: string; enabled: boolean | null; reason: string }) => setTenantCapability(tenantId, module, enabled, reason),
    onSuccess: () => void qc.invalidateQueries({ queryKey: ['admin', 'tenant-capabilities', tenantId] }),
  })

  const set = (module: string, value: string) => {
    const enabled = value === 'on' ? true : value === 'off' ? false : null
    const reason = window.prompt(ar ? 'سبب التغيير (يُسجَّل في سجل التدقيق):' : 'Reason (recorded in the audit trail):')
    if (reason && reason.trim().length >= 5) change.mutate({ module, enabled, reason: reason.trim() })
  }

  return (
    <section className="mt-5">
      <h3 className="text-[12px] font-semibold uppercase tracking-wide text-text-muted">{ar ? 'الخصائص' : 'Features'}</h3>
      {change.isError && <p className="mt-1 text-xs font-semibold text-danger">{toApiError(change.error).message}</p>}
      <ul className="mt-2 grid gap-1.5">
        {(list.data?.capabilities ?? []).map((c) => (
          <li key={c.module} data-testid={`tenant-feature-${c.module}`} data-enabled={c.enabled}
            className="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-surface-secondary px-3 py-1.5 text-[13px]">
            <span className="min-w-0">
              <span className="font-mono text-text-primary" dir="ltr">{c.module}</span>
              <span className={`ms-2 text-[11px] ${c.enabled ? 'text-[var(--positive-foreground)]' : 'text-text-muted'}`}>
                {c.enabled ? (ar ? 'مفعّلة' : 'on') : (ar ? 'غير مفعّلة' : 'off')}{c.reason ? ` · ${c.reason}` : ''}
              </span>
            </span>
            <select aria-label={c.module} data-testid={`tenant-feature-switch-${c.module}`} disabled={change.isPending}
              value={c.tenant_switch === null ? 'inherit' : c.tenant_switch ? 'on' : 'off'}
              onChange={(e) => set(c.module, e.target.value)}
              className="h-8 rounded-lg border border-border bg-surface px-2 text-xs text-text-primary">
              <option value="inherit">{ar ? 'حسب الخطة' : 'Inherit'}</option>
              <option value="on" disabled={!c.entitled}>{ar ? 'تشغيل' : 'On'}</option>
              <option value="off">{ar ? 'إيقاف' : 'Off'}</option>
            </select>
          </li>
        ))}
      </ul>
    </section>
  )
}

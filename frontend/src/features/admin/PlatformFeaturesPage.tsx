import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { fetchPlanCapabilities, fetchPlatformCapabilities, setPlanCapability, setPlatformCapability } from './api'
import { Button } from '@/components/ui/Button'
import { ErrorState, Skeleton } from '@/components/ui/States'
import { toApiError } from '@/lib/api/client'
import { useUi } from '@/stores/ui'

/**
 * `/admin/features` — SUPERADMIN-FEATURES-001: a capability on or off for EVERY workspace.
 *
 * The server refuses a switched-off module everywhere (the `module:` checks), so this is a real off, not a hidden menu. Below it,
 * which modules each plan carries — its active and trialing subscribers' entitlement; price and currency are not touched. One
 * workspace's own switch is in its tenant drawer. A module the environment switched off cannot be turned
 * back on here, and the page says so.
 */
export function PlatformFeaturesPage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const qc = useQueryClient()
  const list = useQuery({ queryKey: ['admin', 'capabilities'], queryFn: fetchPlatformCapabilities })
  const change = useMutation({
    mutationFn: ({ module, disabled, reason }: { module: string; disabled: boolean; reason: string }) => setPlatformCapability(module, disabled, reason),
    onSuccess: () => void qc.invalidateQueries({ queryKey: ['admin', 'capabilities'] }),
  })

  const toggle = (module: string, disabled: boolean) => {
    const reason = window.prompt(ar ? 'سبب التغيير (يُسجَّل في سجل التدقيق):' : 'Reason (recorded in the audit trail):')
    if (reason && reason.trim().length >= 5) change.mutate({ module, disabled, reason: reason.trim() })
  }

  return (
    <div data-testid="admin-features" className="w-full">
      <header className="mb-5">
        <h1 className="font-heading text-3xl font-extrabold tracking-tight text-text-primary">{ar ? 'الخصائص' : 'Features'}</h1>
        <p className="mt-1 max-w-2xl text-sm text-text-secondary">
          {ar
            ? 'تشغيل أو إيقاف كل خاصية لكل مساحات العمل. الإيقاف يُفرض في الخادم وليس إخفاءً في الواجهة. وفي الأسفل: ما تشمله كل خطة. وخاصية مساحة واحدة من صفحة المستأجر.'
            : 'Turn a capability on or off for every workspace. Off is enforced by the server, not hidden in the interface. Below: which modules each plan carries. One workspace’s switch is in its tenant drawer.'}
        </p>
      </header>
      {list.isPending && <Skeleton className="h-40" />}
      {list.isError && <ErrorState error={list.error} ar={ar} title={ar ? 'تعذّر تحميل الخصائص.' : 'Features could not be loaded.'} onRetry={() => void list.refetch()} />}
      {change.isError && <p className="mb-3 text-xs font-semibold text-danger">{toApiError(change.error).message}</p>}
      {list.data && (
        <ul className="grid gap-2">
          {list.data.capabilities.map((c) => (
            <li key={c.module} data-testid={`feature-${c.module}`} data-disabled={c.platform_disabled}
              className="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-border bg-surface px-4 py-3">
              <span className="min-w-0">
                <span className="font-mono text-sm text-text-primary" dir="ltr">{c.module}</span>
                <span className={`ms-2 rounded-full px-2 py-0.5 text-[11px] font-semibold ${c.platform_disabled ? 'bg-danger/10 text-danger' : 'bg-[var(--positive-background)] text-[var(--positive-foreground)]'}`}>
                  {c.platform_disabled ? (ar ? 'موقوفة للجميع' : 'Off for everyone') : (ar ? 'متاحة حسب الخطة' : 'Available by plan')}
                </span>
                {c.disabled_by_environment && <span className="ms-2 text-[11px] text-text-muted">{ar ? 'موقوفة من بيئة التشغيل' : 'Switched off by the environment'}</span>}
              </span>
              <Button data-testid={`feature-toggle-${c.module}`} size="sm" variant="secondary" disabled={change.isPending || c.disabled_by_environment}
                onClick={() => toggle(c.module, !c.platform_disabled)}>
                {c.platform_disabled ? (ar ? 'تشغيل' : 'Turn on') : (ar ? 'إيقاف للجميع' : 'Turn off for everyone')}
              </Button>
            </li>
          ))}
        </ul>
      )}
      <PlanModules ar={ar} />
    </div>
  )
}

/**
 * Plan level: the modules a plan carries. Turning one on entitles every active or trialing subscriber of that plan; turning it off
 * withdraws only what the plan gave (a grant or the workspace's own modules still count). A module every workspace already has is
 * shown as such rather than offered as a switch that would change nothing.
 */
function PlanModules({ ar }: { ar: boolean }) {
  const qc = useQueryClient()
  const data = useQuery({ queryKey: ['admin', 'plan-capabilities'], queryFn: fetchPlanCapabilities })
  const change = useMutation({
    mutationFn: ({ plan, module, enabled, reason }: { plan: string; module: string; enabled: boolean; reason: string }) => setPlanCapability(plan, module, enabled, reason),
    onSuccess: (next) => qc.setQueryData(['admin', 'plan-capabilities'], next),
  })

  const toggle = (plan: { id: string; name: string; workspaces: number }, module: string, enabled: boolean) => {
    const reason = window.prompt(ar
      ? `يؤثر على ${plan.workspaces} مساحة عمل على خطة ${plan.name}. سبب التغيير (يُسجَّل في سجل التدقيق):`
      : `Affects ${plan.workspaces} workspaces on ${plan.name}. Reason (recorded in the audit trail):`)
    if (reason && reason.trim().length >= 5) change.mutate({ plan: plan.id, module, enabled, reason: reason.trim() })
  }

  return (
    <section data-testid="plan-modules" className="mt-8">
      <h2 className="font-heading text-lg font-bold text-text-primary">{ar ? 'خصائص الخطط' : 'Plan features'}</h2>
      <p className="mt-1 max-w-2xl text-sm text-text-secondary">
        {ar ? 'ما تشمله كل خطة لمشتركيها النشطين والتجريبيين. لا يغيّر السعر أو العملة.' : 'What each plan includes for its active and trialing subscribers. Price and currency are not changed.'}
      </p>
      {data.isPending && <Skeleton className="mt-3 h-40" />}
      {data.isError && <ErrorState error={data.error} ar={ar} title={ar ? 'تعذّر تحميل خصائص الخطط.' : 'Plan features could not be loaded.'} onRetry={() => void data.refetch()} />}
      {change.isError && <p className="mt-2 text-xs font-semibold text-danger">{toApiError(change.error).message}</p>}
      {data.data && (
        data.data.plans.length === 0
          ? <p className="mt-3 text-sm text-text-muted">{ar ? 'لا خطط بعد.' : 'No plans yet.'}</p>
          : (
            <div className="mt-3 overflow-x-auto rounded-xl border border-border">
              <table className="w-full min-w-[560px] text-sm">
                <thead className="bg-surface-secondary text-[12px] text-text-muted">
                  <tr>
                    <th className="px-3 py-2 text-start font-semibold">{ar ? 'الخاصية' : 'Module'}</th>
                    {data.data.plans.map((p) => (
                      <th key={p.id} className="px-3 py-2 text-start font-semibold">
                        {(ar ? p.name_ar : null) ?? p.name}
                        <span className="tnum block font-normal">{p.workspaces} {ar ? 'مساحة' : 'workspaces'}</span>
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {data.data.modules.map((m) => (
                    <tr key={m.module} className="border-t border-border">
                      <td className="px-3 py-2 font-mono text-[13px] text-text-primary"><span dir="ltr">{m.module}</span></td>
                      {data.data!.plans.map((p) => {
                        const carried = p.modules[m.module] === true
                        return (
                          <td key={p.id} className="px-3 py-2">
                            {m.default_entitled
                              ? <span className="text-[12px] text-text-muted">{ar ? 'متاحة للجميع' : 'Everyone has it'}</span>
                              : (
                                <button type="button" data-testid={`plan-module-${p.code}-${m.module}`} data-carried={carried}
                                  disabled={change.isPending} onClick={() => toggle(p, m.module, !carried)}
                                  className={`rounded-full px-2.5 py-0.5 text-[12px] font-semibold ${carried ? 'bg-[var(--positive-background)] text-[var(--positive-foreground)]' : 'bg-surface-secondary text-text-muted'}`}>
                                  {carried ? (ar ? 'مشمولة' : 'Included') : (ar ? 'غير مشمولة' : 'Not included')}
                                </button>
                              )}
                          </td>
                        )
                      })}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )
      )}
    </section>
  )
}

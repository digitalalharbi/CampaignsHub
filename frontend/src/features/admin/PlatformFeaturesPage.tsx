import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { fetchPlatformCapabilities, setPlatformCapability } from './api'
import { Button } from '@/components/ui/Button'
import { ErrorState, Skeleton } from '@/components/ui/States'
import { toApiError } from '@/lib/api/client'
import { useUi } from '@/stores/ui'

/**
 * `/admin/features` — SUPERADMIN-FEATURES-001: a capability on or off for EVERY workspace.
 *
 * The server refuses a switched-off module everywhere (the `module:` checks), so this is a real off, not a hidden menu. Plans'
 * features stay on the plans page and one workspace's switch in its drawer. A module the environment switched off cannot be turned
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
            ? 'تشغيل أو إيقاف كل خاصية لكل مساحات العمل. الإيقاف يُفرض في الخادم وليس إخفاءً في الواجهة. خصائص الخطط في صفحة الخطط، وخاصية مساحة واحدة من صفحة المستأجر.'
            : 'Turn a capability on or off for every workspace. Off is enforced by the server, not hidden in the interface. Plan features live on the plans page; one workspace’s switch is in its tenant drawer.'}
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
    </div>
  )
}

import { Badge } from '@/components/ui/Badge'
import { Switch } from '@/components/ui/Switch'
import { Alert } from '@/components/ui/Alert'
import { Skeleton } from '@/components/ui/States'
import { useUi } from '@/stores/ui'
import { useCapabilities, useSetCapability, type Capability, type CapabilityReason } from '../capabilitiesApi'

/** What a module's state means, in words a person can act on — never the bare reason code. */
export function reasonText(reason: CapabilityReason | null, ar: boolean): string {
  switch (reason) {
    case 'not_entitled': return ar ? 'غير مضمّنة في باقتك' : 'Not included in your plan'
    case 'not_activated': return ar ? 'متاحة — فعّلها عند الحاجة' : 'Available — switch it on when you need it'
    case 'platform_disabled': return ar ? 'معطّلة مؤقتًا من المنصة' : 'Temporarily disabled by the platform'
    case 'tenant_off': return ar ? 'متوقفة لهذه المنظمة' : 'Switched off for this organisation'
    case 'client_off': return ar ? 'متوقفة لهذا العميل' : 'Switched off for this client'
    case 'project_off': return ar ? 'متوقفة لهذا المشروع' : 'Switched off for this project'
    default: return ar ? 'مفعّلة' : 'On'
  }
}

function Row({ cap, ar, busy, onToggle }: { cap: Capability; ar: boolean; busy: boolean; onToggle: (next: boolean) => void }) {
  const tone = cap.enabled ? 'success' : cap.entitled ? 'warning' : 'neutral'
  /* A module the platform has disabled, or that the workspace does not have, offers no switch to press. */
  const locked = !cap.entitled || cap.reason === 'platform_disabled'
  return (
    <li data-testid={`module-${cap.module}`} className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border bg-surface p-4">
      <div className="min-w-0">
        <p className="font-bold text-text-primary">{cap.label}</p>
        <p className="mt-0.5 text-xs text-text-secondary">{reasonText(cap.reason, ar)}</p>
      </div>
      <div className="flex items-center gap-3">
        <Badge tone={tone} data-testid={`module-state-${cap.module}`}>
          {cap.enabled ? (ar ? 'مفعّلة' : 'On') : cap.entitled ? (ar ? 'متوقفة' : 'Off') : (ar ? 'غير متاحة' : 'Unavailable')}
        </Badge>
        <Switch
          checked={cap.enabled}
          disabled={locked || busy}
          onCheckedChange={onToggle}
          label={cap.label}
          testId={`module-switch-${cap.module}`}
        />
      </div>
    </li>
  )
}

/** CAP-REGISTRY-001 — which modules this organisation has, and the switch for each one it can turn on or off. */
export function ModulesTab() {
  const ar = useUi((u) => u.locale) === 'ar'
  const { data, isLoading, isError } = useCapabilities()
  const set = useSetCapability()

  if (isLoading) return <div className="space-y-3"><Skeleton className="h-16" /><Skeleton className="h-16" /><Skeleton className="h-16" /></div>
  if (isError || !data) return <Alert severity="danger" title={ar ? 'تعذّر تحميل الوحدات' : 'Could not load the modules'} />

  return (
    <div className="space-y-4">
      <div>
        <h2 className="text-xl font-bold text-text-primary">{ar ? 'الوحدات' : 'Modules'}</h2>
        <p className="mt-1 text-sm text-text-secondary">
          {ar
            ? 'تفعيل وحدة أو إيقافها يخفيها ويمنع الوصول إليها فقط؛ لا يغيّر بياناتك ولا أرقامك.'
            : 'Switching a module on or off shows or hides it and nothing else — it never changes your data or your figures.'}
        </p>
      </div>
      {set.isError && <Alert severity="danger" title={ar ? 'تعذّر حفظ التغيير' : 'The change was not saved'} />}
      <ul className="space-y-2">
        {data.map((cap) => (
          <Row key={cap.module} cap={cap} ar={ar} busy={set.isPending} onToggle={(next) => set.mutate({ module: cap.module, enabled: next })} />
        ))}
      </ul>
    </div>
  )
}

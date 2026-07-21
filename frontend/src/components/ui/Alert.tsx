import { AlertTriangle, CheckCircle2, Info, XCircle } from 'lucide-react'
import type { ReactNode } from 'react'

type Severity = 'positive' | 'warning' | 'danger' | 'info'

const config: Record<Severity, { bg: string; fg: string; icon: typeof Info }> = {
  positive: { bg: 'var(--positive-background)', fg: 'var(--success)', icon: CheckCircle2 },
  warning: { bg: 'var(--warning-background)', fg: 'var(--warning)', icon: AlertTriangle },
  danger: { bg: 'var(--negative-background)', fg: 'var(--danger)', icon: XCircle },
  info: { bg: 'var(--info-background)', fg: 'var(--info)', icon: Info },
}

/** Status is conveyed by icon + text, never color alone (WCAG). */
export function Alert({
  severity = 'info',
  title,
  children,
}: {
  severity?: Severity
  title: string
  children?: ReactNode
}) {
  const c = config[severity]
  const Icon = c.icon
  return (
    <div
      role="alert"
      className="flex gap-3 rounded-[12px] border border-border p-3.5"
      style={{ background: c.bg }}
    >
      <Icon size={18} style={{ color: c.fg }} className="mt-0.5 shrink-0" aria-hidden />
      <div>
        <p className="text-[13px] font-bold text-text-primary">{title}</p>
        {children && <div className="mt-0.5 text-[12px] text-text-secondary">{children}</div>}
      </div>
    </div>
  )
}

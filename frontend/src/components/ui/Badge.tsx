import type { ReactNode } from 'react'

type Tone = 'success' | 'warning' | 'danger' | 'info' | 'neutral'

const tones: Record<Tone, string> = {
  success: 'bg-[var(--positive-background)] text-success',
  warning: 'bg-[var(--warning-background)] text-warning',
  danger: 'bg-[var(--negative-background)] text-danger',
  info: 'bg-[var(--info-background)] text-info',
  neutral: 'bg-surface-secondary text-text-secondary',
}

/** Status is never conveyed by color alone — always pair with a label/icon (dot below). */
export function Badge({
  tone = 'neutral', children, 'data-testid': testId,
}: { tone?: Tone; children: ReactNode; 'data-testid'?: string }) {
  return (
    <span
      data-testid={testId}
      className={`inline-flex items-center gap-1.5 rounded-[var(--radius-pill)] px-2.5 py-0.5 text-xs font-bold ${tones[tone]}`}
    >
      <span className="h-1.5 w-1.5 rounded-full bg-current" aria-hidden />
      {children}
    </span>
  )
}

import type { ReactNode } from 'react'

export function Card({ children, className = '' }: { children: ReactNode; className?: string }) {
  return (
    <div
      className={`rounded-[14px] border border-border bg-surface p-4 shadow-[var(--shadow-small)] ${className}`}
    >
      {children}
    </div>
  )
}

export function CardTitle({ children }: { children: ReactNode }) {
  return <h3 className="text-sm font-bold text-text-primary">{children}</h3>
}

export function CardDescription({ children }: { children: ReactNode }) {
  return <p className="mt-1 text-[13px] text-text-secondary">{children}</p>
}

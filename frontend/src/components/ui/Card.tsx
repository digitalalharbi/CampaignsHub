import type { ReactNode } from 'react'

export function Card({
  children,
  className = '',
  interactive = false,
  ...rest
}: {
  children: ReactNode
  className?: string
  interactive?: boolean
  /*
   * Pass-through for the identifying attributes a test needs (`data-testid`, `data-*`).
   *
   * Without it a caller that wants to name its cards has to wrap them in another element, which is
   * how `integrations.spec.ts` ended up selecting `main li` and reading the account rows as though
   * they were connector cards.
   */
} & React.HTMLAttributes<HTMLDivElement>) {
  return (
    <div
      {...rest}
      className={`rounded-2xl border border-border bg-surface p-5 shadow-[var(--shadow-small)] ${
        interactive ? 'transition-all duration-150 hover:-translate-y-0.5 hover:border-border-strong hover:shadow-[var(--shadow-medium)]' : ''
      } ${className}`}
    >
      {children}
    </div>
  )
}

export function CardTitle({ children }: { children: ReactNode }) {
  return <h3 className="text-base font-bold tracking-tight text-text-primary">{children}</h3>
}

export function CardDescription({ children }: { children: ReactNode }) {
  return <p className="mt-1 text-sm leading-relaxed text-text-secondary">{children}</p>
}

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
      /*
       * `min-w-0`, so a card can never widen the column it was given.
       *
       * A grid or flex item's `min-width` is `auto`, which resolves to its content's minimum — so a
       * card holding something with an intrinsic width pushes its track wider than the viewport and
       * the whole PAGE scrolls sideways, not the card. On `/agency/portfolio` at 390px the platform
       * matrix (`min-w-[22rem]` inside its own `overflow-x-auto`) did exactly that, and because a
       * one-column grid sizes every item to the widest track, the health card beside it was dragged
       * out by 20px too — two cards reported, one cause.
       *
       * Fixed here rather than at that call site: no card anywhere should be able to do this, and
       * the content that genuinely needs width already carries its own scroll container.
       */
      className={`min-w-0 rounded-2xl border border-border bg-surface p-5 shadow-[var(--shadow-small)] ${
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
